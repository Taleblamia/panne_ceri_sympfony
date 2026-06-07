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
        return $this->render('src/pages/home.html.twig');
    }

    #[Route('/report/{id}', methods: ['GET'])]
    public function reportForm(string $id): Response
    {
        $pc = $this->pcCatalog->findPcByIdOrToken($id);

        if (!$pc) {
            return $this->render('src/pages/message.html.twig', [
                'title' => 'PC introuvable',
                'message' => 'Veuillez vérifier le code du poste.',
            ], new Response('', Response::HTTP_NOT_FOUND));
        }

        if (($pc['token'] ?? null) && $id !== $pc['token']) {
            return $this->redirect('/report/'.$pc['token']);
        }

        return $this->render('src/pages/report.html.twig', [
            'pc' => $pc,
            'pannes' => $this->pcCatalog->getPannes(),
        ]);
    }

    #[Route('/report/{id}', methods: ['POST'])]
    public function submitReport(string $id, Request $request, MailerInterface $mailer): Response
    {
        $pc = $this->pcCatalog->findPcByIdOrToken($id);

        if (!$pc) {
            return $this->render('src/pages/message.html.twig', [
                'title' => 'PC introuvable',
                'message' => 'Veuillez vérifier le code du poste.',
            ], new Response('', Response::HTTP_NOT_FOUND));
        }

        $payload = [
            'pc_id' => $pc['id'],
            'email' => (string) $request->request->get('email', ''),
            'type_panne' => (string) $request->request->get('type_panne', ''),
            'custom_panne' => $request->request->get('custom_panne') ?: null,
            'description' => $request->request->get('description') ?: null,
        ];
        $deviceFingerprint = $this->getDeviceFingerprint($request);

        $this->db->deleteExpiredPendingTickets();

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

        $this->db->deleteOldTickets();

        if ($this->db->findDuplicateTicket($payload['pc_id'], $payload['type_panne'], $payload['custom_panne'])) {
            return $this->render('src/pages/message.html.twig', [
                'title' => 'Panne déjà signalée',
                'message' => 'Cette panne a déjà été signalée sur ce poste.',
            ], new Response('', Response::HTTP_CONFLICT));
        }

        try {
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
        $password = (string) $request->request->get('password', '');

        if ($request->isMethod('POST') && $password === $this->adminPassword) {
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
}
