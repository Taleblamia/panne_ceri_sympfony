# Backend Symfony - Ticket Panne CERI

Ce dossier remplace le backend Node.js/Express par une version Symfony avec pages Twig.

La structure sépare maintenant le backend Symfony et le frontend Twig :

- `server.php` contient le backend Symfony/API
- `front.php` contient les routes du frontend Twig
- `../frontend-twig/src/App.html.twig` est le fichier principal du frontend Twig
- `../frontend-twig/src/pages/` contient les pages Twig
- `db.php` remplace `backend/db.js`
- `database.db` remplace `backend/database.db`

## Installation

```bash
cd backend-symfony
composer install
cp .env.example .env.local
```

Renseigner ensuite `.env.local` avec les valeurs GLPI, mail, admin et base de données.

## Base de données

La base SQLite est créée automatiquement dans `backend-symfony/database.db` au démarrage de l'API.

## Lancement

```bash
symfony server:start --port=8000
```

ou :

```bash
php -S 0.0.0.0:8000 -t public
```

## Pages Twig disponibles

- `GET /`
- `GET /report/{id}`
- `POST /report/{id}`
- `GET|POST /admin`

## Routes API disponibles

- `GET /api/pannes`
- `GET /api/pcs/{id}`
- `POST /api/tickets`
- `GET /api/verify-email/{token}`
- `GET /api/admin/salles`
- `GET /api/admin/tickets`

Les routes API restent disponibles pour test ou compatibilité avec l'ancien frontend React.
