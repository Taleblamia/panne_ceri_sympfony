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
        $this->pdo = new PDO('sqlite:'.$projectDir.'/database.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->init();
    }

    private function init(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pc_id TEXT,
                email TEXT,
                type_panne TEXT,
                custom_panne TEXT,
                description TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS verified_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                device_fingerprint TEXT,
                verified_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(email, device_fingerprint)
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS pending_tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT UNIQUE,
                pc_id TEXT,
                email TEXT,
                type_panne TEXT,
                custom_panne TEXT,
                description TEXT,
                device_fingerprint TEXT,
                expires_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function deleteExpiredPendingTickets(): void
    {
        $this->pdo->exec("DELETE FROM pending_tickets WHERE expires_at < datetime('now')");
    }

    public function deleteOldTickets(): void
    {
        $this->pdo->exec("DELETE FROM tickets WHERE created_at < datetime('now', '-24 hours')");
    }

    public function findVerifiedUser(string $email, string $deviceFingerprint): ?array
    {
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
        $statement = $this->pdo->prepare("
            INSERT INTO pending_tickets (
                token,
                pc_id,
                email,
                type_panne,
                custom_panne,
                description,
                device_fingerprint,
                expires_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now', '+30 minutes'))
        ");
        $statement->execute([
            $token,
            $ticket['pc_id'],
            $ticket['email'],
            $ticket['type_panne'],
            $ticket['custom_panne'],
            $ticket['description'],
            $deviceFingerprint,
        ]);
    }

    public function findPendingTicket(string $token): ?array
    {
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
        $statement = $this->pdo->prepare("DELETE FROM pending_tickets WHERE token = ?");
        $statement->execute([$token]);
    }

    public function findDuplicateTicket(string $pcId, string $typePanne, ?string $customPanne): ?array
    {
        $statement = $this->pdo->prepare("
            SELECT *
            FROM tickets
            WHERE pc_id = ?
              AND type_panne = ?
              AND IFNULL(custom_panne, '') = IFNULL(?, '')
              AND created_at >= datetime('now', '-24 hours')
            LIMIT 1
        ");
        $statement->execute([$pcId, $typePanne, $customPanne]);

        return $statement->fetch() ?: null;
    }

    public function findDuplicateTicketByPcInput(string $pcInput, string $typePanne, ?string $customPanne): ?array
    {
        return $this->findDuplicateTicket($pcInput, $typePanne, $customPanne);
    }

    public function addTicket(array $ticket): void
    {
        $statement = $this->pdo->prepare("
            INSERT INTO tickets (
                pc_id,
                email,
                type_panne,
                custom_panne,
                description
            )
            VALUES (?, ?, ?, ?, ?)
        ");
        $statement->execute([
            $ticket['pc_id'],
            $ticket['email'],
            $ticket['type_panne'],
            $ticket['custom_panne'],
            $ticket['description'],
        ]);
    }

    public function getTickets(): array
    {
        return $this->pdo
            ->query("SELECT * FROM tickets ORDER BY created_at DESC")
            ->fetchAll();
    }
}
