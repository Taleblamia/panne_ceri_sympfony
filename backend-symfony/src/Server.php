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
        // Liste des types de panne affiches dans le formulaire.
        return $this->json($this->catalog->pannes());
    }

    #[Route('/api/pcs/{id}', methods: ['GET'])]
    public function pc(string $id): JsonResponse
    {
        // Recherche d'un poste depuis son code lisible ou son token QR code.
        $pc = $this->catalog->findPcByIdOrToken($id);

        if (!$pc) {
            return $this->json(['message' => 'PC introuvable'], 404);
        }

        return $this->json($pc);
    }

    #[Route('/api/tickets', methods: ['POST'])]
    public function createTicket(Request $request, MailerInterface $mailer): JsonResponse
    {
        // Point d'entree appele par le formulaire Twig en JavaScript.
        $data = json_decode((string) $request->getContent(), true) ?: [];
        $result = $this->prepareTicket($data, $request, $mailer);

        if ($result['status'] === 'not_found') {
            return $this->json(['message' => 'PC introuvable'], 404);
        }

        if ($result['status'] === 'duplicate') {
            return $this->json(['message' => 'Cette panne a déjà été signalée sur ce poste.'], 409);
        }

        if ($result['status'] === 'limit') {
            return $this->json(['message' => $result['message']], 409);
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
        // Validation du lien envoye par mail avant la redirection vers ALADIN.
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

        $limitMessage = $this->dailyLimitMessage($ticket['email'], $ticket['pc_id']);

        if ($limitMessage) {
            $this->database->deletePendingTicket($token);

            return new Response($limitMessage);
        }

        if ($this->database->findDuplicateTicket($ticket['pc_id'], $ticket['type_panne'], $ticket['custom_panne'])) {
            $this->database->deletePendingTicket($token);

            return new Response('Cette panne a déjà été signalée sur ce poste.');
        }

        $this->database->addVerifiedUser($pendingTicket['email'], $pendingTicket['device_fingerprint']);
        $this->database->addTicket($ticket);
        $this->database->deletePendingTicket($token);

        return new RedirectResponse($this->aladin->buildUrl($ticket));
    }

    public function prepareTicket(array $data, Request $request, MailerInterface $mailer): array
    {
        // Nettoie les validations expirees avant de traiter une nouvelle demande.
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

        $limitMessage = $this->dailyLimitMessage($ticket['email'], $ticket['pc_id']);

        if ($limitMessage) {
            return [
                'status' => 'limit',
                'message' => $limitMessage,
            ];
        }

        $fingerprint = $this->deviceFingerprint($request);
        $verifiedUser = $this->database->findVerifiedUser($ticket['email'], $fingerprint);

        if (!$verifiedUser) {
            // Premier signalement avec cet email sur cet appareil : validation obligatoire.
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

    private function dailyLimitMessage(string $email, string $pcId): ?string
    {
        if ($this->database->hasTicketTodayByEmailAndPc($email, $pcId)) {
            return 'Vous avez déjà déclaré une panne sur ce poste aujourd’hui.';
        }

        if ($this->database->countTicketsTodayByEmail($email) >= 2) {
            return 'Vous avez atteint la limite de 2 signalements par jour.';
        }

        return null;
    }

    private function sendValidationEmail(MailerInterface $mailer, string $emailAddress, string $token): void
    {
        // Mail envoye pour verifier le couple email + appareil.
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
        // L'appareil est reconnu grace a un identifiant stocke dans le navigateur.
        $deviceId = trim((string) $request->headers->get('X-Device-Id', ''));

        if ($deviceId !== '') {
            return hash('sha256', $deviceId);
        }

        return hash('sha256', $request->headers->get('User-Agent', ''));
    }
}
