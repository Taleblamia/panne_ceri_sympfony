<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

class Server extends AbstractController
{
    public function __construct(
        private Catalog $catalog,
        private Database $database,
        private Aladin $aladin,
    ) {
    }

    #[Route('/api/pannes', methods: ['GET'])]
    public function pannes(): JsonResponse
    {
        return $this->json($this->catalog->pannes());
    }

    #[Route('/api/pcs/{id}', methods: ['GET'])]
    public function pc(string $id): JsonResponse
    {
        $pc = $this->catalog->findPcByIdOrToken($id);

        if (!$pc) {
            return $this->json(['message' => 'PC introuvable'], 404);
        }

        return $this->json($pc);
    }

    #[Route('/api/tickets', methods: ['POST'])]
    public function createTicket(Request $request, MailerInterface $mailer): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true) ?: [];
        $result = $this->prepareTicket($data, $request, $mailer);

        if ($result['status'] === 'not_found') {
            return $this->json(['message' => 'PC introuvable'], 404);
        }

        if ($result['status'] === 'duplicate') {
            return $this->json(['message' => 'Cette panne a déjà été signalée sur ce poste.'], 409);
        }

        if ($result['status'] === 'validation_sent') {
            return $this->json([
                'message' => 'Un email de validation vous a été envoyé. Veuillez valider cet appareil dans les 30 minutes.',
            ], 202);
        }

        return $this->json([
            'message' => 'Signalement validé. Redirection vers le formulaire ALADIN.',
            'redirectUrl' => $result['redirectUrl'],
        ], 201);
    }

    #[Route('/api/verify-email/{token}', methods: ['GET'])]
    public function verifyEmail(string $token): Response
    {
        $pendingTicket = $this->database->findPendingTicket($token);

        if (!$pendingTicket) {
            return new Response('Lien invalide ou expiré.');
        }

        $ticket = [
            'nom' => $pendingTicket['nom'] ?? '',
            'prenom' => $pendingTicket['prenom'] ?? '',
            'pc_id' => $pendingTicket['pc_id'],
            'email' => $pendingTicket['email'],
            'type_panne' => $pendingTicket['type_panne'],
            'custom_panne' => $pendingTicket['custom_panne'],
            'description' => $pendingTicket['description'],
        ];

        $this->database->addVerifiedUser($pendingTicket['email'], $pendingTicket['device_fingerprint']);
        $this->database->addTicket($ticket);
        $this->database->deletePendingTicket($token);

        return new RedirectResponse($this->aladin->buildUrl($ticket));
    }

    public function prepareTicket(array $data, Request $request, MailerInterface $mailer): array
    {
        $this->database->cleanup();

        $pc = $this->catalog->findPcByIdOrToken((string) ($data['pc_id'] ?? ''));

        if (!$pc) {
            return ['status' => 'not_found'];
        }

        $ticket = [
            'nom' => trim((string) ($data['nom'] ?? '')),
            'prenom' => trim((string) ($data['prenom'] ?? '')),
            'pc_id' => $pc['id'],
            'email' => strtolower(trim((string) ($data['email'] ?? ''))),
            'type_panne' => trim((string) ($data['type_panne'] ?? '')),
            'custom_panne' => trim((string) ($data['custom_panne'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
        ];

        if ($this->database->findDuplicateTicket($ticket['pc_id'], $ticket['type_panne'], $ticket['custom_panne'])) {
            return ['status' => 'duplicate'];
        }

        $fingerprint = $this->deviceFingerprint($request);
        $verifiedUser = $this->database->findVerifiedUser($ticket['email'], $fingerprint);

        if (!$verifiedUser) {
            $token = bin2hex(random_bytes(32));
            $this->database->addPendingTicket($token, $ticket, $fingerprint);
            $this->sendValidationEmail($mailer, $ticket['email'], $token);

            return ['status' => 'validation_sent'];
        }

        $this->database->addTicket($ticket);

        return [
            'status' => 'created',
            'redirectUrl' => $this->aladin->buildUrl($ticket),
        ];
    }

    private function sendValidationEmail(MailerInterface $mailer, string $emailAddress, string $token): void
    {
        $backendUrl = rtrim($_ENV['BACKEND_URL'] ?? $_SERVER['BACKEND_URL'] ?? 'http://localhost:8000', '/');
        $from = $_ENV['MAIL_FROM'] ?? $_SERVER['MAIL_FROM'] ?? 'noreply@example.com';
        $validationLink = $backendUrl . '/api/verify-email/' . $token;

        $email = (new Email())
            ->from(new Address($from, 'Ticket Panne CERI'))
            ->to($emailAddress)
            ->subject('Validation de votre appareil')
            ->html('
                <h2>Validation de votre appareil</h2>
                <p>Veuillez cliquer sur le lien ci-dessous pour valider votre adresse email et cet appareil.</p>
                <p>Ce lien est valable pendant 30 minutes.</p>
                <p><a href="' . htmlspecialchars($validationLink, ENT_QUOTES) . '">Valider mon appareil</a></p>
            ');

        $mailer->send($email);
    }

    private function deviceFingerprint(Request $request): string
    {
        $deviceId = trim((string) $request->headers->get('X-Device-Id', ''));

        if ($deviceId !== '') {
            return hash('sha256', $deviceId);
        }

        return hash('sha256', $request->headers->get('User-Agent', ''));
    }
}
