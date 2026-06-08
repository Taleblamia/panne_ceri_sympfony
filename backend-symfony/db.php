<?php

namespace App;

use PDO;

class Database
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite:' . __DIR__ . '/database.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function cleanup(): void
    {
        $this->pdo->exec("DELETE FROM pending_tickets WHERE expires_at < datetime('now')");
        $this->pdo->exec("DELETE FROM tickets WHERE created_at < datetime('now', '-24 hours')");
    }

    public function findDuplicateTicket(string $pcId, string $typePanne, ?string $customPanne): ?array
    {
        $statement = $this->pdo->prepare("
            SELECT *
            FROM tickets
            WHERE pc_id = ?
              AND type_panne = ?
              AND IFNULL(custom_panne, '') = IFNULL(?, '')
            LIMIT 1
        ");
        $statement->execute([$pcId, $typePanne, $customPanne]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findVerifiedUser(string $email, string $fingerprint): ?array
    {
        $statement = $this->pdo->prepare("
            SELECT *
            FROM verified_users
            WHERE email = ?
              AND device_fingerprint = ?
            LIMIT 1
        ");
        $statement->execute([$email, $fingerprint]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function addPendingTicket(string $token, array $ticket, string $fingerprint): void
    {
        $statement = $this->pdo->prepare("
            INSERT INTO pending_tickets (
                token, nom, prenom, pc_id, email, type_panne, custom_panne, description, device_fingerprint, expires_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+30 minutes'))
        ");
        $statement->execute([
            $token,
            $ticket['nom'] ?? '',
            $ticket['prenom'] ?? '',
            $ticket['pc_id'],
            $ticket['email'],
            $ticket['type_panne'],
            $ticket['custom_panne'] ?? null,
            $ticket['description'] ?? '',
            $fingerprint,
        ]);
    }

    public function findPendingTicket(string $token): ?array
    {
        $statement = $this->pdo->prepare("
            SELECT *
            FROM pending_tickets
            WHERE token = ?
              AND expires_at >= datetime('now')
            LIMIT 1
        ");
        $statement->execute([$token]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deletePendingTicket(string $token): void
    {
        $statement = $this->pdo->prepare('DELETE FROM pending_tickets WHERE token = ?');
        $statement->execute([$token]);
    }

    public function addVerifiedUser(string $email, string $fingerprint): void
    {
        $statement = $this->pdo->prepare("
            INSERT OR IGNORE INTO verified_users (email, device_fingerprint)
            VALUES (?, ?)
        ");
        $statement->execute([$email, $fingerprint]);
    }

    public function addTicket(array $ticket): void
    {
        $statement = $this->pdo->prepare("
            INSERT INTO tickets (nom, prenom, pc_id, email, type_panne, custom_panne, description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $statement->execute([
            $ticket['nom'] ?? '',
            $ticket['prenom'] ?? '',
            $ticket['pc_id'],
            $ticket['email'],
            $ticket['type_panne'],
            $ticket['custom_panne'] ?? null,
            $ticket['description'] ?? '',
        ]);
    }

    private function migrate(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nom TEXT,
                prenom TEXT,
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
                nom TEXT,
                prenom TEXT,
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

        $this->addColumnIfMissing('tickets', 'nom TEXT');
        $this->addColumnIfMissing('tickets', 'prenom TEXT');
        $this->addColumnIfMissing('pending_tickets', 'nom TEXT');
        $this->addColumnIfMissing('pending_tickets', 'prenom TEXT');
        $this->addColumnIfMissing('pending_tickets', 'device_fingerprint TEXT');
        $this->addColumnIfMissing('verified_users', 'device_fingerprint TEXT');
    }

    private function addColumnIfMissing(string $table, string $definition): void
    {
        try {
            $this->pdo->exec("ALTER TABLE $table ADD COLUMN $definition");
        } catch (\Throwable) {
        }
    }
}
