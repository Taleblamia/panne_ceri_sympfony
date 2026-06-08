<?php

namespace App;

class Aladin
{
    public function __construct(private Catalog $catalog)
    {
    }

    public function buildUrl(array $ticket): string
    {
        $baseUrl = $_ENV['ALADIN_URL'] ?? $_SERVER['ALADIN_URL'] ?? '';
        $pc = $this->catalog->findPcByIdOrToken((string) $ticket['pc_id']);
        $panne = $this->clean($ticket['type_panne'] === 'Autre' ? ($ticket['custom_panne'] ?? '') : ($ticket['type_panne'] ?? ''));

        $title = $this->clean('Panne PC ' . $ticket['pc_id'] . ' - ' . $panne);
        $description =
            'Salle ' . $this->clean($pc['salle'] ?? 'Non renseignee') . '                                                                                                        ' .
            'Poste ' . $this->clean($pc['poste'] ?? 'Non renseigne') . '                                                                                                         ' .
            'Panne : ' . $panne . '                                                                ' .
            'Description : ' . $this->clean($ticket['description'] ?: 'Aucune description');

        $params = http_build_query([
            'inst' => '1',
            'nom' => $this->clean($ticket['nom'] ?? ''),
            'prenom' => $this->clean($ticket['prenom'] ?? ''),
            'mel' => $ticket['email'] ?? '',
            'tit' => $title,
            'pb' => $description,
        ]);

        return rtrim($baseUrl, '?') . '?' . $params;
    }

    private function clean(?string $text): string
    {
        $text = $text ?? '';
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $converted = $converted === false ? $text : $converted;

        return str_replace(['’', '“', '”'], ["'", '"', '"'], $converted);
    }
}
