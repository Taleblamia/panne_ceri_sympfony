# Ticket Panne CERI

Application web de gestion des tickets de panne pour les salles informatiques du CERI.

## Description du problème

Beaucoup d'étudiants ne prennent pas le temps de signaler les pannes sur les postes informatiques, car la déclaration classique demande plusieurs étapes et plusieurs informations.

L'objectif du projet est de simplifier le signalement : l'utilisateur scanne un QR Code, remplit un formulaire court, puis un ticket est créé avec les informations du poste concerné.

## Architecture choisie

Le projet utilise maintenant l'option **Symfony + Twig** :

- **Symfony** pour le backend
- **Twig** pour les pages HTML
- **SQLite** pour la base de données locale
- **GLPI** pour la création des tickets
- **Docker + MariaDB** pour l'environnement GLPI

L'ancien backend Node.js/Express est conservé dans `backend/` pour comparaison, mais la version à utiliser est dans `backend-symfony/`.
Le dossier `frontend/` React est conservé, mais la version Symfony/Twig est celle à présenter si Twig est demandé.

## Technologies utilisées

### Frontend Twig

- Twig
- HTML/CSS
- Formulaires Symfony

### Backend Symfony

- PHP
- Symfony
- Symfony Mailer
- Symfony HttpClient
- SQLite

### GLPI

- Docker Compose
- GLPI
- MariaDB

## Lancer le backend Symfony

```bash
cd backend-symfony
composer install
cp .env.example .env.local
```

Modifier ensuite `.env.local` avec les valeurs de GLPI, du mail et du mot de passe admin.

La base SQLite est créée automatiquement dans `backend-symfony/database.db`.

Lancer l'API :

```bash
symfony server:start --port=8000
```

ou :

```bash
php -S 0.0.0.0:8000 -t public
```

Pages Twig principales :

- `GET /`
- `GET /report/{id}`
- `POST /report/{id}`
- `GET|POST /admin`

## Routes API Symfony

- `GET /api/pannes`
- `GET /api/pcs/{id}`
- `POST /api/tickets`
- `GET /api/verify-email/{token}`
- `GET /api/admin/salles`
- `GET /api/admin/tickets`

## Lancer GLPI avec Docker

```bash
cd glpi_docker
docker compose up -d
```

GLPI sera disponible sur :

```txt
http://localhost:8080
```

## Structure du projet

```txt
ticket-panne-ceri/
├── backend/              Ancien backend Node.js/Express
├── backend-symfony/      Version Symfony + Twig
│   ├── server.php        Backend API
│   ├── front.php         Routes frontend Twig
├── frontend-twig/        Frontend Twig
│   └── src/
│       ├── App.html.twig
│       └── pages/
├── frontend/             Ancien frontend React/Vite conservé
├── glpi_docker/          GLPI + MariaDB avec Docker
├── qr_codes/             QR codes générés
└── genere_qr.js          Script de génération QR code
```

## Commandes Git utiles

```bash
git checkout aladin
git pull origin aladin

git add .
git commit -m "Migration backend Symfony"
git push
```
