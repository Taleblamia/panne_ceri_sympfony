<?php

namespace App;

use PDO;

class Database
{
    private PDO $pdo;

    public function __construct()
    {
        // Base SQLite locale utilisee par l'application Symfony.
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
        // On supprime seulement les demandes de validation expirees.
        $this->pdo->exec("DELETE FROM pending_tickets WHERE expires_at < datetime('now')");
    }

    public function findDuplicateTicket(string $pcId, string $typePanne, ?string $customPanne): ?array
    {
        // Evite de declarer la meme panne sur le meme poste pendant 24h.
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

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function hasTicketTodayByEmailAndPc(string $email, string $pcId): bool
    {
        // Un utilisateur ne peut declarer qu'une panne par jour sur le meme poste.
        $statement = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM tickets
            WHERE lower(email) = lower(?)
              AND pc_id = ?
              AND date(created_at, 'localtime') = date('now', 'localtime')
        ");
        $statement->execute([$email, $pcId]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function countTicketsTodayByEmail(string $email): int
    {
        // Limite globale : maximum deux signalements par utilisateur et par jour.
        $statement = $this->pdo->prepare("
            SELECT COUNT(*)
            FROM tickets
            WHERE lower(email) = lower(?)
              AND date(created_at, 'localtime') = date('now', 'localtime')
        ");
        $statement->execute([$email]);

        return (int) $statement->fetchColumn();
    }

    public function findTicketsByEmail(string $email): array
    {
        // Liste les signalements locaux d'un utilisateur, du plus recent au plus ancien.
        $statement = $this->pdo->prepare("
            SELECT *
            FROM tickets
            WHERE lower(email) = lower(?)
            ORDER BY created_at DESC
        ");
        $statement->execute([$email]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findVerifiedUser(string $email, string $fingerprint): ?array
    {
        // La validation est liee au couple email + appareil.
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
        // Ticket temporaire en attente de validation email.
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
        // Une fois valide, ce couple email + appareil ne redemande plus de validation.
        $statement = $this->pdo->prepare("
            INSERT OR IGNORE INTO verified_users (email, device_fingerprint)
            VALUES (?, ?)
        ");
        $statement->execute([$email, $fingerprint]);
    }

    public function addTicket(array $ticket): void
    {
        // Historique local des signalements envoyes vers ALADIN.
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
        // Creation automatique des tables si la base n'existe pas encore.
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
