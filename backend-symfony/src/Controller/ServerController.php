<?php

namespace App\Controller;

use App\Service\Database; // Service de gestion de la base de données SQLite
use App\Service\GlpiTicketService; // Service de communication avec l'API GLPI pour créer les tickets
use App\Service\PcCatalog; // Service pour gérer le catalogue des PC et leurs pannes associées
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController; // Contrôleur de base de Symfony pour gérer les requêtes HTTP
use Symfony\Component\DependencyInjection\Attribute\Autowire; // Attribut pour injecter des paramètres de configuration dans le constructeur
use Symfony\Component\HttpFoundation\JsonResponse; // Classe pour retourner des réponses JSON aux requêtes HTTP
use Symfony\Component\HttpFoundation\Request; // Classe pour accéder aux données des requêtes HTTP
use Symfony\Component\HttpFoundation\Response; // Classe pour retourner des réponses HTTP génériques
use Symfony\Component\Mailer\MailerInterface; // Interface pour envoyer des emails via le composant Mailer de Symfony
use Symfony\Component\Mime\Address; // Classe pour représenter une adresse email dans les messages envoyés avec le composant Mailer de Symfony
use Symfony\Component\Mime\Email; // Classe pour créer des messages email avec le composant Mailer de Symfony
use Symfony\Component\Routing\Attribute\Route; // Attribut pour définir les routes des méthodes du contrôleur

// Contrôleur principal de l'application qui gère les différentes routes pour la création de tickets, la validation des emails, et les endpoints d'administration
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
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }
    
    // Route pour récupérer la liste des types de pannes disponibles
    #[Route('/api/pannes', methods: ['GET'])]
    public function pannes(): JsonResponse
    {
        return $this->json($this->pcCatalog->getPannes());
    }

    // Route pour récupérer les détails d'un PC à partir de son ID ou de son token
    #[Route('/api/pcs/{id}', methods: ['GET'])]
    public function pc(string $id): JsonResponse
    {
        $pc = $this->pcCatalog->findPcByIdOrToken($id);

        if (!$pc) {
            return $this->json(['message' => 'PC introuvable'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($pc);
    }

    // Route pour créer un ticket de panne à partir des données envoyées dans la requête
    #[Route('/api/tickets', methods: ['POST'])]
    public function createTicket(
        Request $request,
        MailerInterface $mailer,
    ): JsonResponse {
        $payload = $this->ticketPayload($request);
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
            'image_paths' => json_encode($this->saveImages($request), JSON_UNESCAPED_SLASHES),
        ];
        $deviceFingerprint = $this->getDeviceFingerprint($request);

        $this->db->deleteExpiredPendingTickets();

        if ($limitMessage = $this->dailyLimitMessage($payload['email'], $payload['pc_id'])) {
            return $this->json([
                'message' => $limitMessage,
            ], Response::HTTP_CONFLICT);
        }

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

    // Route pour vérifier si un ticket peut être créé pour un PC donné sans créer de ticket (utilisé pour la validation côté client avant d'envoyer les données complètes)
    #[Route('/api/tickets/check', methods: ['POST'])]
    public function checkTicket(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?: [];
        $pc = $this->pcCatalog->findPcByIdOrToken((string) ($payload['pc_id'] ?? ''));

        if (!$pc) {
            return $this->json(['message' => 'PC introuvable'], Response::HTTP_NOT_FOUND);
        }

        $email = (string) ($payload['email'] ?? '');

        if ($limitMessage = $this->dailyLimitMessage($email, $pc['id'])) {
            return $this->json([
                'message' => $limitMessage,
            ], Response::HTTP_CONFLICT);
        }

        if ($this->db->findDuplicateTicket($pc['id'], (string) ($payload['type_panne'] ?? ''), $payload['custom_panne'] ?? null)) {
            return $this->json([
                'message' => 'Cette panne a déjà été signalée sur ce poste.',
            ], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => 'OK']);
    }

    // Route pour vérifier l'email et l'appareil à partir du token envoyé dans le lien de validation, puis créer le ticket dans GLPI si tout est valide
    #[Route('/api/verify-email/{token}', methods: ['GET'])]
    public function verifyEmail(string $token): Response
    {
        $pendingTicket = $this->db->findPendingTicket($token);

        if (!$pendingTicket) {
            return new Response('Lien invalide ou expiré.');
        }

        $this->db->addVerifiedUser($pendingTicket['email'], $pendingTicket['device_fingerprint']);

        if ($limitMessage = $this->dailyLimitMessage($pendingTicket['email'], $pendingTicket['pc_id'])) {
            $this->db->deletePendingTicket($token);

            return new Response($limitMessage);
        }

        if ($this->db->findDuplicateTicket($pendingTicket['pc_id'], $pendingTicket['type_panne'], $pendingTicket['custom_panne'])) {
            $this->db->deletePendingTicket($token);

            return new Response('Cette panne a déjà été signalée sur ce poste.');
        }

        try {
            $this->createTicketInGlpiAndSave([
                'pc_id' => $pendingTicket['pc_id'],
                'email' => $pendingTicket['email'],
                'type_panne' => $pendingTicket['type_panne'],
                'custom_panne' => $pendingTicket['custom_panne'],
                'description' => $pendingTicket['description'],
                'image_paths' => $pendingTicket['image_paths'] ?? '[]',
            ]);

            $this->db->deletePendingTicket($token);

            return new Response('Email et appareil validés avec succès. Votre ticket a été créé dans GLPI.');
        } catch (\Throwable) {
            return new Response('Email validé, mais une erreur est survenue lors de la création du ticket dans GLPI.');
        }
    }

    // Route pour récupérer la liste des salles (endpoint d'administration protégé par mot de passe)
    #[Route('/api/admin/salles', methods: ['GET'])]
    public function adminSalles(Request $request): JsonResponse
    {
        if (!$this->isAdminRequest($request)) {
            return $this->json(['message' => 'Accès refusé'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->pcCatalog->getSalles());
    }

    // Route pour récupérer la liste des tickets (endpoint d'administration protégé par mot de passe)
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

    // Méthode privée pour créer un ticket dans GLPI et le sauvegarder dans la base de données locale
    private function createTicketInGlpiAndSave(array $payload): array
    {
        $glpiData = $this->glpiTicketService->createTicket($payload);
        $this->db->addTicket($payload);

        return $glpiData;
    }

    // Méthode privée pour vérifier les limites de signalement par jour pour un email et un PC donnés, et retourner un message d'erreur approprié si la limite est atteinte
    private function dailyLimitMessage(string $email, string $pcId): ?string
    {
        if ($this->db->hasTicketTodayByEmailAndPc($email, $pcId)) {
            return 'Vous avez déjà déclaré une panne sur ce poste aujourd’hui.';
        }

        if ($this->db->countTicketsTodayByEmail($email) >= 2) {
            return 'Vous avez atteint la limite de 2 signalements par jour.';
        }

        return null;
    }

    // Méthode privée pour extraire les données du ticket de la requête, en prenant en charge à la fois les requêtes JSON et les requêtes multipart/form-data (pour les images)
    private function ticketPayload(Request $request): array
    {
        if (str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/form-data')) {
            return [
                'pc_id' => $request->request->get('pc_id'),
                'email' => $request->request->get('email'),
                'type_panne' => $request->request->get('type_panne'),
                'custom_panne' => $request->request->get('custom_panne'),
                'description' => $request->request->get('description'),
            ];
        }

        return json_decode($request->getContent(), true) ?: [];
    }

    // Méthode privée pour sauvegarder les images envoyées dans la requête, en les stockant dans le dossier public/uploads/tickets et en retournant les chemins d'accès relatifs à ces images
    private function saveImages(Request $request): array
    {
        $images = $request->files->get('images', []);

        if (!$images) {
            return [];
        }

        if (!is_array($images)) {
            $images = [$images];
        }

        $uploadDir = $this->projectDir.'/public/uploads/tickets';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $paths = [];

        foreach (array_slice($images, 0, 3) as $image) {
            if (!$image || !str_starts_with((string) $image->getMimeType(), 'image/')) {
                continue;
            }

            $extension = $image->guessExtension() ?: 'png';
            $filename = bin2hex(random_bytes(16)).'.'.$extension;
            $image->move($uploadDir, $filename);
            $paths[] = '/uploads/tickets/'.$filename;
        }

        return $paths;
    }

    // Méthode privée pour envoyer un email de validation à l'utilisateur avec un lien contenant un token unique pour valider l'email et l'appareil avant de créer le ticket dans GLPI
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

    // Méthode privée pour générer une empreinte de l'appareil à partir d'un identifiant unique envoyé dans les headers (X-Device-Id) ou, à défaut, à partir du user-agent, afin de limiter la création de tickets à partir du même appareil même si l'email est différent
    private function getDeviceFingerprint(Request $request): string
    {
        $deviceId = trim((string) $request->headers->get('X-Device-Id', ''));

        if ($deviceId !== '') {
            return hash('sha256', $deviceId);
        }

        return hash('sha256', $request->headers->get('user-agent', ''));
    }

    // Méthode privée pour vérifier si la requête d'administration contient le mot de passe correct dans les headers (X-Admin-Password) pour autoriser l'accès aux endpoints d'administration
    private function isAdminRequest(Request $request): bool
    {
        return $request->headers->get('x-admin-password') === $this->adminPassword;
    }

}
