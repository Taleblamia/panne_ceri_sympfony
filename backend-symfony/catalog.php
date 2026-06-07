<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class PcCatalog
{
    private ?array $pcs = null;
    private ?array $pannes = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getPannes(): array
    {
        if ($this->pannes === null) {
            $this->pannes = $this->readJson('/data/pannes.json');
        }

        return $this->pannes;
    }

    public function getPcs(): array
    {
        if ($this->pcs === null) {
            $this->pcs = $this->readJson('/data/pcs.json');
        }

        return $this->pcs;
    }

    public function findPcByIdOrToken(string $value): ?array
    {
        foreach ($this->getPcs() as $pc) {
            if (($pc['id'] ?? null) === $value || ($pc['token'] ?? null) === $value) {
                return $pc;
            }
        }

        return null;
    }

    public function getSalles(): array
    {
        $salles = array_values(array_unique(array_map(
            static fn (array $pc): string => (string) ($pc['salle'] ?? ''),
            $this->getPcs()
        )));
        sort($salles);

        return $salles;
    }

    private function readJson(string $relativePath): array
    {
        $content = file_get_contents($this->projectDir.$relativePath);

        if ($content === false) {
            return [];
        }

        return json_decode($content, true, flags: JSON_THROW_ON_ERROR);
    }
}
