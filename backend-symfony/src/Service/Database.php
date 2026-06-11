<?php

namespace App\Service;

use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class Database
{
    private PDO $pdo;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        // Connexion à la base SQLite locale du projet.
        $this->pdo = new PDO('sqlite:'.$projectDir.'/database.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->init();
    }

    private function init(): void
    {
        // Table des tickets réellement validés et conservés dans l'historique local.
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pc_id TEXT,
                email TEXT,
                type_panne TEXT,
                custom_panne TEXT,
                description TEXT,
                image_paths TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Table des couples email + appareil déjà validés.
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS verified_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                device_fingerprint TEXT,
                verified_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(email, device_fingerprint)
            )
        ");

        // Table des tickets en attente de validation email.
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS pending_tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT UNIQUE,
                pc_id TEXT,
                email TEXT,
                type_panne TEXT,
                custom_panne TEXT,
                description TEXT,
                image_paths TEXT,
                device_fingerprint TEXT,
                expires_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Ajoute la colonne des images si la base existait avant cette fonctionnalité.
        $this->addColumnIfMissing('tickets', 'image_paths TEXT');
        $this->addColumnIfMissing('pending_tickets', 'image_paths TEXT');
    }

    public function deleteExpiredPendingTickets(): void
    {
        // Supprime seulement les validations email expirées, pas les tickets validés.
        $this->pdo->exec("DELETE FROM pending_tickets WHERE expires_at < datetime('now')");
    }

    public function findVerifiedUser(string $email, string $deviceFingerprint): ?array
    {
        // Vérifie si cet email a déjà validé cet appareil.
        $statement = $this->pdo->prepare("
            SELECT *
            FROM verified_users
            WHERE email = ?
            AND device_fingerprint = ?
        ");
        $statement->execute([$email, $deviceFingerprint]);

        return $statement->fetch() ?: null;
    }

    public function addVerifiedUser(string $email, string $deviceFingerprint): void
    {
        // Enregistre un couple email + appareil après validation.
        $statement = $this->pdo->prepare("
            INSERT OR IGNORE INTO verified_users (
                email,
                device_fingerprint
            )
            VALUES (?, ?)
        ");
        $statement->execute([$email, $deviceFingerprint]);
    }

    public function addPendingTicket(string $token, array $ticket, string $deviceFingerprint): void
    {
        // Stocke temporairement le ticket jusqu'au clic sur le lien reçu par email.
        $statement = $this->pdo->prepare("
            INSERT INTO pending_tickets (
                token,
                pc_id,
                email,
                type_panne,
                custom_panne,
                description,
                image_paths,
                device_fingerprint,
                expires_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+30 minutes'))
        ");
        $statement->execute([
            $token,
            $ticket['pc_id'],
            $ticket['email'],
            $ticket['type_panne'],
            $ticket['custom_panne'],
            $ticket['description'],
            $ticket['image_paths'] ?? '[]',
            $deviceFingerprint,
        ]);
    }

    public function findPendingTicket(string $token): ?array
    {
        // Récupère un ticket en attente si le lien de validation est encore valide.
        $statement = $this->pdo->prepare("
            SELECT *
            FROM pending_tickets
            WHERE token = ?
            AND expires_at >= datetime('now')
        ");
        $statement->execute([$token]);

        return $statement->fetch() ?: null;
    }

    public function deletePendingTicket(string $token): void
    {
        // Supprime le ticket en attente après validation ou expiration.
        $statement = $this->pdo->prepare("DELETE FROM pending_tickets WHERE token = ?");
        $statement->execute([$token]);
    }

    public function findDuplicateTicket(string $pcId): ?array
    {
        // Un poste ne peut avoir qu'un seul signalement par jour, peu importe la panne.
        $statement = $this->pdo->prepare("
            SELECT *
            FROM tickets
            WHERE pc_id = ?
              AND date(created_at, 'localtime') = date('now', 'localtime')
            LIMIT 1
        ");
        $statement->execute([$pcId]);

        return $statement->fetch() ?: null;
    }

    public function findDuplicateTicketByPcInput(string $pcInput): ?array
    {
        return $this->findDuplicateTicket($pcInput);
    }

    public function countTicketsTodayByEmail(string $email): int
    {
        // Compte les tickets créés aujourd'hui par une même adresse email.
        $statement = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM tickets
            WHERE lower(email) = lower(?)
              AND date(created_at, 'localtime') = date('now', 'localtime')
        ");
        $statement->execute([$email]);

        return (int) $statement->fetchColumn();
    }

    public function addTicket(array $ticket): void
    {
        // Enregistre le ticket validé dans l'historique local.
        $statement = $this->pdo->prepare("
            INSERT INTO tickets (
                pc_id,
                email,
                type_panne,
                custom_panne,
                description,
                image_paths
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $statement->execute([
            $ticket['pc_id'],
            $ticket['email'],
            $ticket['type_panne'],
            $ticket['custom_panne'],
            $ticket['description'],
            $ticket['image_paths'] ?? '[]',
        ]);
    }

    public function getTickets(): array
    {
        // Récupère tous les tickets pour l'espace admin.
        return $this->pdo
            ->query("SELECT * FROM tickets ORDER BY created_at DESC")
            ->fetchAll();
    }

    public function getTicketsByEmail(string $email): array
    {
        // Récupère les tickets visibles dans la page "Mes signalements".
        $statement = $this->pdo->prepare("
            SELECT *
            FROM tickets
            WHERE lower(email) = lower(?)
            ORDER BY created_at DESC
        ");
        $statement->execute([$email]);

        return $statement->fetchAll();
    }

    private function addColumnIfMissing(string $table, string $definition): void
    {
        // Petite migration automatique pour garder une ancienne base compatible.
        try {
            $this->pdo->exec("ALTER TABLE $table ADD COLUMN $definition");
        } catch (\Throwable) {
        }
    }
}
