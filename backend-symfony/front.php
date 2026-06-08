<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;

class Front extends AbstractController
{
    public function __construct(
        private Catalog $catalog,
        private Server $server,
    ) {
    }

    #[Route('/', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('src/pages/home.html.twig');
    }

    #[Route('/report/{id}', methods: ['GET', 'POST'])]
    public function report(string $id, Request $request, MailerInterface $mailer): Response
    {
        $pc = $this->catalog->findPcByIdOrToken($id);
        $pannes = $this->catalog->pannes();
        $error = null;
        $waitingEmail = null;

        if ($request->isMethod('POST') && $pc) {
            $email = strtolower(trim($request->request->get('email_prefix', '')));
            $userType = $request->request->get('user_type', 'etudiant');
            $domain = $userType === 'Professeur' ? '@univ-avignon.fr' : '@alumni.univ-avignon.fr';
            $email = $email . $domain;
            $identity = $this->identityFromEmail($email);

            $result = $this->server->prepareTicket([
                'nom' => $identity['nom'],
                'prenom' => $identity['prenom'],
                'pc_id' => $pc['id'],
                'email' => $email,
                'type_panne' => $request->request->get('type_panne', ''),
                'custom_panne' => $request->request->get('custom_panne', ''),
                'description' => $request->request->get('description', ''),
            ], $request, $mailer);

            if ($result['status'] === 'duplicate') {
                $error = 'Cette panne a déjà été signalée sur ce poste.';
            } elseif ($result['status'] === 'validation_sent') {
                $waitingEmail = $email;
            } elseif ($result['status'] === 'created') {
                return $this->redirect($result['redirectUrl']);
            }
        }

        return $this->render('src/pages/report.html.twig', [
            'pc' => $pc,
            'pc_error' => $pc ? null : 'PC introuvable. Veuillez vérifier le code du poste.',
            'pannes' => $pannes,
            'error' => $error,
            'waiting_email' => $waitingEmail,
        ]);
    }

    #[Route('/qr/{id}', methods: ['GET'])]
    public function qr(string $id): Response
    {
        return $this->render('src/pages/qr.html.twig', [
            'id' => $id,
            'url' => rtrim($_ENV['FRONTEND_URL'] ?? $_SERVER['FRONTEND_URL'] ?? 'http://localhost:8000', '/') . '/report/' . $id,
        ]);
    }

    private function identityFromEmail(string $email): array
    {
        $username = explode('@', $email)[0] ?? '';
        $parts = explode('.', $username);

        return [
            'prenom' => $parts[0] ?? '',
            'nom' => implode(' ', array_slice($parts, 1)),
        ];
    }
}
