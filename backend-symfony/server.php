<?php

namespace App\Controller;

use App\Service\Database;
use App\Service\GlpiTicketService;
use App\Service\PcCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

class ServerController extends AbstractController
{
    public function __construct(
        private readonly Database $db,
        private readonly PcCatalog $pcCatalog,
        private readonly GlpiTicketService $glpiTicketService,
        #[Autowire('%app.backend_url%')]
        private readonly string $backendUrl,
        #[Autowire('%app.admin_password%')]
        private readonly string $adminPassword,
        #[Autowire('%app.mail_from%')]
        private readonly string $mailFrom,
    ) {
    }

    #[Route('/api/pannes', methods: ['GET'])]
    public function pannes(): JsonResponse
    {
        return $this->json($this->pcCatalog->getPannes());
    }

    #[Route('/api/pcs/{id}', methods: ['GET'])]
    public function pc(string $id): JsonResponse
    {
        $pc = $this->pcCatalog->findPcByIdOrToken($id);

        if (!$pc) {
            return $this->json(['message' => 'PC introuvable'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($pc);
    }

    #[Route('/api/tickets', methods: ['POST'])]
    public function createTicket(
        Request $request,
        MailerInterface $mailer,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true) ?: [];
        $pc = $this->pcCatalog->findPcByIdOrToken((string) ($payload['pc_id'] ?? ''));

        if (!$pc) {
            return $this->json(['message' => 'PC introuvable'], Response::HTTP_NOT_FOUND);
        }

        $payload = [
            'pc_id' => $pc['id'],
            'email' => (string) ($payload['email'] ?? ''),
            'type_panne' => (string) ($payload['type_panne'] ?? ''),
            'custom_panne' => $payload['custom_panne'] ?? null,
            'description' => $payload['description'] ?? null,
        ];
        $deviceFingerprint = $this->getDeviceFingerprint($request);

        $this->db->deleteExpiredPendingTickets();

        $verifiedUser = $this->db->findVerifiedUser($payload['email'], $deviceFingerprint);

        if (!$verifiedUser) {
            $token = bin2hex(random_bytes(32));
            $this->db->addPendingTicket($token, $payload, $deviceFingerprint);

            try {
                $this->sendValidationEmail($mailer, $payload['email'], $token);
            } catch (\Throwable) {
                return $this->json([
                    'message' => "Erreur lors de l'envoi de l'email de validation. Vérifiez MAILER_DSN et MAIL_FROM dans backend-symfony/.env.local.",
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return $this->json([
                'message' => 'Un email de validation vous a été envoyé. Veuillez valider cet appareil dans les 30 minutes.',
            ], Response::HTTP_ACCEPTED);
        }

        $this->db->deleteOldTickets();

        if ($this->db->findDuplicateTicket($payload['pc_id'], $payload['type_panne'], $payload['custom_panne'])) {
            return $this->json([
                'message' => 'Cette panne a déjà été signalée sur ce poste.',
            ], Response::HTTP_CONFLICT);
        }

        try {
            $glpiData = $this->createTicketInGlpiAndSave($payload);

            return $this->json([
                'message' => 'Ticket créé avec succès dans GLPI',
                'glpi' => $glpiData,
            ], Response::HTTP_CREATED);
        } catch (\Throwable) {
            return $this->json([
                'message' => "Erreur lors de l'envoi du ticket vers GLPI.",
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/api/tickets/check', methods: ['POST'])]
    public function checkTicket(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];
        $pc = $this->pcCatalog->findPcByIdOrToken((string) ($payload['pc_id'] ?? ''));

        if (!$pc) {
            return $this->json(['message' => 'PC introuvable'], Response::HTTP_NOT_FOUND);
        }

        $this->db->deleteOldTickets();

        if ($this->db->findDuplicateTicket($pc['id'], (string) ($payload['type_panne'] ?? ''), $payload['custom_panne'] ?? null)) {
            return $this->json([
                'message' => 'Cette panne a déjà été signalée sur ce poste.',
            ], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => 'OK']);
    }

    #[Route('/api/verify-email/{token}', methods: ['GET'])]
    public function verifyEmail(string $token): Response
    {
        $pendingTicket = $this->db->findPendingTicket($token);

        if (!$pendingTicket) {
            return new Response('Lien invalide ou expiré.');
        }

        $this->db->addVerifiedUser($pendingTicket['email'], $pendingTicket['device_fingerprint']);

        try {
            $this->createTicketInGlpiAndSave([
                'pc_id' => $pendingTicket['pc_id'],
                'email' => $pendingTicket['email'],
                'type_panne' => $pendingTicket['type_panne'],
                'custom_panne' => $pendingTicket['custom_panne'],
                'description' => $pendingTicket['description'],
            ]);

            $this->db->deletePendingTicket($token);

            return new Response('Email et appareil validés avec succès. Votre ticket a été créé dans GLPI.');
        } catch (\Throwable) {
            return new Response('Email validé, mais une erreur est survenue lors de la création du ticket dans GLPI.');
        }
    }

    #[Route('/api/admin/salles', methods: ['GET'])]
    public function adminSalles(Request $request): JsonResponse
    {
        if (!$this->isAdminRequest($request)) {
            return $this->json(['message' => 'Accès refusé'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->pcCatalog->getSalles());
    }

    #[Route('/api/admin/tickets', methods: ['GET'])]
    public function adminTickets(Request $request): JsonResponse
    {
        if (!$this->isAdminRequest($request)) {
            return $this->json(['message' => 'Accès refusé'], Response::HTTP_UNAUTHORIZED);
        }

        $data = array_map(function (array $ticket): array {
            $pc = $this->pcCatalog->findPcByIdOrToken($ticket['pc_id']) ?? [];

            return [
                ...$ticket,
                'salle' => $pc['salle'] ?? 'Inconnue',
                'poste' => $pc['poste'] ?? 'Inconnu',
            ];
        }, $this->db->getTickets());

        return $this->json($data);
    }

    private function createTicketInGlpiAndSave(array $payload): array
    {
        $glpiData = $this->glpiTicketService->createTicket($payload);
        $this->db->addTicket($payload);

        return $glpiData;
    }

    private function sendValidationEmail(MailerInterface $mailer, string $email, string $token): void
    {
        $validationLink = $this->backendUrl.'/api/verify-email/'.$token;

        $message = (new Email())
            ->from(new Address($this->mailFrom, 'Ticket Panne CERI'))
            ->to($email)
            ->subject('Validation de votre appareil')
            ->html(sprintf(
                '<h2>Validation de votre appareil</h2><p>Veuillez cliquer sur le lien ci-dessous pour valider votre adresse email et cet appareil.</p><p>Ce lien est valable pendant 30 minutes.</p><a href="%s">Valider mon appareil</a>',
                htmlspecialchars($validationLink, ENT_QUOTES)
            ));

        $mailer->send($message);
    }

    private function getDeviceFingerprint(Request $request): string
    {
        return hash('sha256', $request->headers->get('user-agent', ''));
    }

    private function isAdminRequest(Request $request): bool
    {
        return $request->headers->get('x-admin-password') === $this->adminPassword;
    }

}
