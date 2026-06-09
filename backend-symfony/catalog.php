<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PcCatalog
{
    // Les données sont gardées en mémoire après la première lecture.
    private ?array $pcs = null;
    private ?array $pannes = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getPannes(): array
    {
        // Lit la liste des types de pannes proposés dans le formulaire.
        if ($this->pannes === null) {
            $this->pannes = $this->readJson('/data/pannes.json');
        }

        return $this->pannes;
    }

    public function getPcs(): array
    {
        // Lit la liste des postes et leurs tokens QR code.
        if ($this->pcs === null) {
            $this->pcs = $this->readJson('/data/pcs.json');
        }

        return $this->pcs;
    }

    public function findPcByIdOrToken(string $value): ?array
    {
        // Recherche un poste soit par son id, soit par son token QR code.
        foreach ($this->getPcs() as $pc) {
            if (($pc['id'] ?? null) === $value || ($pc['token'] ?? null) === $value) {
                return $pc;
            }
        }

        return null;
    }

    public function getSalles(): array
    {
        // Récupère la liste des salles distinctes pour l'administration.
        $salles = array_values(array_unique(array_map(
            static fn (array $pc): string => (string) ($pc['salle'] ?? ''),
            $this->getPcs()
        )));
        sort($salles);

        return $salles;
    }

    private function readJson(string $relativePath): array
    {
        // Lit un fichier JSON du dossier data.
        $content = file_get_contents($this->projectDir.$relativePath);

        if ($content === false) {
            return [];
        }

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
