<?php

namespace App\Controller;

use App\Service\Database;
use App\Service\GlpiTicketService;
use App\Service\PcCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;


class FrontController extends AbstractController
{
    // Injection des services utilisés par les pages Twig :
    // base SQLite, catalogue des postes, service GLPI et variables de configuration.
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

    #[Route('/', methods: ['GET'])]
    public function home(): Response
    {
        // Affiche la page d'accueil avec le scan QR code et la saisie manuelle.
        return $this->render('src/pages/home.html.twig');
    }

    #[Route('/report/{id}', methods: ['GET'])]
    public function reportForm(string $id): Response
    {
        // Recherche le poste à partir du token QR code ou du code poste saisi manuellement.
        $pc = $this->pcCatalog->findPcByIdOrToken($id);

        if (!$pc) {
            return $this->render('src/pages/message.html.twig', [
                'title' => 'PC introuvable',
                'message' => 'Veuillez vérifier le code du poste.',
            ], new Response('', Response::HTTP_NOT_FOUND));
        }

        // Si l'utilisateur arrive avec le code poste, on le redirige vers l'URL avec le token.
        // Cela évite d'afficher directement l'identifiant du poste dans l'URL.
        if (($pc['token'] ?? null) && $id !== $pc['token']) {
            return $this->redirect('/report/'.$pc['token']);
        }

        // Affiche le formulaire de déclaration avec les informations du poste déjà remplies.
        return $this->render('src/pages/report.html.twig', [
            'pc' => $pc,
            'pannes' => $this->pcCatalog->getPannes(),
        ]);
    }


    #[Route('/report/{id}', methods: ['POST'])]
    public function submitReport(string $id, Request $request, MailerInterface $mailer): Response
    {
        // Vérifie une nouvelle fois que le poste existe avant de traiter le formulaire.
        $pc = $this->pcCatalog->findPcByIdOrToken($id);

        if (!$pc) {
            return $this->render('src/pages/message.html.twig', [
                'title' => 'PC introuvable',
                'message' => 'Veuillez vérifier le code du poste.',
            ], new Response('', Response::HTTP_NOT_FOUND));
        }

        // Regroupe les données envoyées par le formulaire dans un tableau commun.
        $payload = [
            'pc_id' => $pc['id'],
            'email' => (string) $request->request->get('email', ''),
            'type_panne' => (string) $request->request->get('type_panne', ''),
            'custom_panne' => $request->request->get('custom_panne') ?: null,
            'description' => $request->request->get('description') ?: null,
        ];

        // Identifie l'appareil utilisé afin de valider le couple email + appareil.
        $deviceFingerprint = $this->getDeviceFingerprint($request);

        // Nettoie les demandes de validation email expirées.
        $this->db->deleteExpiredPendingTickets();

        // Limite anti-abus : maximum 2 signalements par email et par jour.
        if ($limitMessage = $this->dailyLimitMessage($payload['email'])) {
            return $this->render('src/pages/message.html.twig', [
                'title' => 'Limite atteinte',
                'message' => $limitMessage,
            ], new Response('', Response::HTTP_CONFLICT));
        }

        // Un poste déjà signalé aujourd'hui ne peut pas recevoir un deuxième ticket.
        if ($this->db->findDuplicateTicket($payload['pc_id'])) {
            return $this->render('src/pages/message.html.twig', [
                'title' => 'Poste déjà signalé',
                'message' => 'Un signalement a déjà été créé pour ce poste aujourd’hui.',
            ], new Response('', Response::HTTP_CONFLICT));
        }

        // Si le couple email + appareil n'est pas encore validé,
        // le ticket est mis en attente et un email de validation est envoyé.
        if (!$this->db->findVerifiedUser($payload['email'], $deviceFingerprint)) {
            $token = bin2hex(random_bytes(32));
            $this->db->addPendingTicket($token, $payload, $deviceFingerprint);

            try {
                $this->sendValidationEmail($mailer, $payload['email'], $token);
            } catch (\Throwable) {
                return $this->render('src/pages/message.html.twig', [
                    'title' => 'Erreur email',
                    'message' => "Erreur lors de l'envoi de l'email de validation.",
                ], new Response('', Response::HTTP_INTERNAL_SERVER_ERROR));
            }

            return $this->render('src/pages/message.html.twig', [
                'title' => 'Email envoyé',
                'message' => 'Un email de validation a été envoyé à '.$payload['email'].'.',
                'email' => $payload['email'],
            ], new Response('', Response::HTTP_ACCEPTED));
        }

        try {
            // Crée le ticket dans GLPI puis l'enregistre dans la base locale.
            $this->createTicketInGlpiAndSave($payload);

            return $this->render('src/pages/message.html.twig', [
                'title' => 'Ticket envoyé',
                'message' => 'Votre déclaration a bien été enregistrée.',
                'ticket' => [
                    ...$payload,
                    'salle' => $pc['salle'],
                    'poste' => $pc['poste'],
                ],
            ], new Response('', Response::HTTP_CREATED));
        } catch (\Throwable) {
            return $this->render('src/pages/message.html.twig', [
                'title' => 'Erreur GLPI',
                'message' => "Erreur lors de l'envoi du ticket vers GLPI.",
            ], new Response('', Response::HTTP_INTERNAL_SERVER_ERROR));
        }
    }

    #[Route('/admin', methods: ['GET', 'POST'])]
    public function adminPage(Request $request): Response
    {
        // L'espace admin est protégé par un mot de passe simple défini dans .env.local.
        $password = (string) $request->request->get('password', '');

        if ($request->isMethod('POST') && $password === $this->adminPassword) {
            // Récupère les tickets locaux et complète chaque ticket avec sa salle et son poste.
            $tickets = array_map(function (array $ticket): array {
                $pc = $this->pcCatalog->findPcByIdOrToken($ticket['pc_id']) ?? [];

                return [
                    ...$ticket,
                    'salle' => $pc['salle'] ?? 'Inconnue',
                    'poste' => $pc['poste'] ?? 'Inconnu',
                ];
            }, $this->db->getTickets());

            return $this->render('src/pages/admin.html.twig', [
                'tickets' => $tickets,
                'error' => null,
            ]);
        }

        return $this->render('src/pages/admin.html.twig', [
            'tickets' => null,
            'error' => $request->isMethod('POST') ? 'Mot de passe incorrect.' : null,
        ]);
    }

    #[Route('/mes-signalements', methods: ['GET'])]
    public function myReports(Request $request): Response
    {
        // Récupère l'email depuis l'URL ou, si absent, depuis les cookies du navigateur.
        $email = strtolower(trim((string) $request->query->get('email', '')));
        $tickets = [];

        if ($email === '') {
            $emailPrefix = strtolower(trim((string) $request->cookies->get('emailPrefix', '')));
            $userType = (string) $request->cookies->get('userType', 'etudiant');

            if ($emailPrefix !== '') {
                $email = $emailPrefix.($userType === 'Professeur' ? '@univ-avignon.fr' : '@alumni.univ-avignon.fr');
            }
        }

        if ($email !== '') {
            // Affiche uniquement les tickets locaux associés à l'adresse email de l'utilisateur.
            $tickets = array_map(function (array $ticket): array {
                $pc = $this->pcCatalog->findPcByIdOrToken($ticket['pc_id']) ?? [];

                return [
                    ...$ticket,
                    'salle' => $pc['salle'] ?? 'Inconnue',
                    'poste' => $pc['poste'] ?? 'Inconnu',
                ];
            }, $this->db->getTicketsByEmail($email));
        }

        return $this->render('src/pages/my_reports.html.twig', [
            'email' => $email,
            'tickets' => $tickets,
            'searched' => $email !== '',
        ]);
    }

    private function createTicketInGlpiAndSave(array $payload): array
    {
        // Envoie le ticket vers GLPI puis conserve une copie locale pour l'admin et le suivi.
        $glpiData = $this->glpiTicketService->createTicket($payload);
        $this->db->addTicket($payload);

        return $glpiData;
    }

    private function dailyLimitMessage(string $email): ?string
    {
        // Un utilisateur ne peut pas dépasser deux signalements par jour.
        if ($this->db->countTicketsTodayByEmail($email) >= 2) {
            return 'Vous avez atteint la limite de 2 signalements par jour.';
        }

        return null;
    }

    private function sendValidationEmail(MailerInterface $mailer, string $email, string $token): void
    {
        // Construit le lien de validation envoyé par email.
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
        // L'identifiant d'appareil vient du navigateur. Il est hashé avant stockage.
        $deviceId = trim((string) $request->headers->get('X-Device-Id', ''));

        if ($deviceId !== '') {
            return hash('sha256', $deviceId);
        }

        // Fallback si l'identifiant d'appareil n'est pas disponible.
        return hash('sha256', $request->headers->get('user-agent', ''));
    }
}
