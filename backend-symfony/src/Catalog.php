<?php

namespace App;

class Catalog
{
    private string $dataDir;

    public function __construct()
    {
        // Les listes de postes et de pannes sont stockees dans backend-symfony/data.
        $this->dataDir = dirname(__DIR__) . '/data';
    }

    public function pannes(): array
    {
        return $this->readJson('pannes.json');
    }

    public function pcs(): array
    {
        return $this->readJson('pcs.json');
    }

    public function findPcByIdOrToken(string $value): ?array
    {
        // Accepte soit le code du poste, soit le token cache dans le QR code.
        $value = strtolower(trim($value));

        foreach ($this->pcs() as $pc) {
            if (strtolower((string) $pc['id']) === $value || strtolower((string) $pc['token']) === $value) {
                return $pc;
            }
        }

        return null;
    }

    private function readJson(string $file): array
    {
        // Lecture simple d'un fichier JSON du catalogue.
        $path = $this->dataDir . '/' . $file;

        if (!is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }
}
