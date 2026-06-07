<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class GlpiTicketService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PcCatalog $pcCatalog,
        private readonly string $glpiUrl,
        private readonly string $userToken,
        private readonly string $appToken,
    ) {
    }

    public function createTicket(array $ticketData): array
    {
        $pc = $this->pcCatalog->findPcByIdOrToken($ticketData['pc_id']);

        if (!$pc) {
            throw new \RuntimeException('PC introuvable');
        }

        $panneFinale = $ticketData['type_panne'] === 'Autre'
            ? ($ticketData['custom_panne'] ?? 'Autre')
            : $ticketData['type_panne'];

        $sessionToken = $this->initSession();

        try {
            $response = $this->httpClient->request('POST', $this->glpiUrl.'/Ticket', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Session-Token' => $sessionToken,
                    'App-Token' => $this->appToken,
                ],
                'json' => [
                    'input' => [
                        'name' => sprintf('Panne PC %s - %s', $ticketData['pc_id'], $panneFinale),
                        'content' => $this->buildContent($ticketData, $pc),
                        'type' => 1,
                    ],
                ],
            ]);

            $data = $response->toArray(false);

            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException(json_encode($data));
            }

            return $data;
        } finally {
            $this->httpClient->request('GET', $this->glpiUrl.'/killSession', [
                'headers' => [
                    'Session-Token' => $sessionToken,
                    'App-Token' => $this->appToken,
                ],
            ]);
        }
    }

    private function initSession(): string
    {
        $response = $this->httpClient->request('GET', $this->glpiUrl.'/initSession', [
            'headers' => [
                'Authorization' => 'user_token '.$this->userToken,
                'App-Token' => $this->appToken,
            ],
        ]);

        $data = $response->toArray(false);

        if (empty($data['session_token'])) {
            throw new \RuntimeException('Impossible de créer une session GLPI');
        }

        return $data['session_token'];
    }

    private function buildContent(array $ticketData, array $pc): string
    {
        $customLine = $ticketData['type_panne'] === 'Autre'
            ? sprintf("Panne précisée : %s\n", $ticketData['custom_panne'] ?? '')
            : '';

        return sprintf(
            "Email utilisateur : %s\nPC ID : %s\nSalle : %s\nPoste : %s\nType de panne : %s\n%sDescription : %s",
            $ticketData['email'],
            $ticketData['pc_id'],
            $pc['salle'] ?? '',
            $pc['poste'] ?? '',
            $ticketData['type_panne'],
            $customLine,
            $ticketData['description'] ?: 'Aucune description'
        );
    }
}
