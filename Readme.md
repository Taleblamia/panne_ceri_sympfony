# Ticket Panne CERI - version GLPI

Application web de signalement de pannes pour les salles informatiques du CERI.

Cette branche `main` correspond à la version **GLPI** : l'application permet de scanner le QR code d'un poste, de déclarer une panne rapidement, puis de créer automatiquement un ticket dans GLPI avec l'API REST.

---

## Description du problème

Au CERI, les pannes informatiques peuvent déjà être signalées, mais le parcours classique demande plusieurs étapes : accéder au bon service, s'identifier, retrouver les informations du poste et remplir le formulaire.

L'objectif de cette application est de simplifier ce signalement :

- l'utilisateur scanne le QR code du poste ;
- le poste est reconnu automatiquement ;
- l'utilisateur remplit un formulaire court ;
- l'application vérifie l'email et l'appareil ;
- un ticket est créé automatiquement dans GLPI.

---

## Technologies utilisées

### Backend

- PHP
- Symfony
- SQLite
- Symfony Mailer
- Symfony HttpClient
- API REST GLPI

### Frontend

- Twig
- HTML / CSS / JavaScript
- Lucide Icons
- jsPDF pour les exports PDF
- PWA simple avec `manifest.json` et `sw.js`

### GLPI

- GLPI
- API REST GLPI
- Docker + MariaDB pour l'environnement de test local

---

## Fonctionnalités

- Scan d'un QR code.
- Saisie manuelle du code poste si le QR code ne fonctionne pas.
- Formulaire de déclaration de panne.
- Email universitaire en minuscule avec domaine automatique.
- Validation du couple email + appareil.
- Création automatique d'un ticket dans GLPI.
- Ajout de plusieurs images jointes, maximum 3.
- Visualisation des images dans l'espace admin et dans `Mes signalements`.
- Limite de signalements :
  - maximum 2 signalements par utilisateur et par jour ;
  - maximum 1 signalement par poste et par jour, quelle que soit la panne.
- Page `Mes signalements`.
- Espace administration protégé par mot de passe.
- Recherche admin par email, poste, salle ou type de panne.
- Filtre admin par période.
- Statistiques admin.
- Export CSV et PDF.
- Mode sombre.
- Changement de langue français / anglais.
- Application installable en PWA selon les conditions du navigateur.

---

## Fonctionnement avec GLPI

La création d'un ticket GLPI se fait avec l'API REST de GLPI.

Le service `backend-symfony/src/Service/GlpiTicketService.php` :

1. ouvre une session GLPI avec `initSession` ;
2. récupère un `Session-Token` ;
3. envoie une requête `POST` vers `/Ticket` ;
4. transmet le titre et le contenu du ticket ;
5. ferme la session avec `killSession`.

Les informations nécessaires sont configurées dans `.env.local` :

- `GLPI_URL`
- `GLPI_USER_TOKEN`
- `GLPI_APP_TOKEN`

Les images jointes sont conservées dans l'application locale. Le ticket GLPI indique qu'il y a des images jointes dans l'application.

---

## Données locales

Le projet utilise une base SQLite locale :

```text
backend-symfony/database.db
```

Elle sert à stocker :

- les tickets créés localement ;
- les validations email + appareil ;
- les tickets en attente de validation ;
- les chemins des images jointes.

Les fichiers JSON suivants servent de catalogue :

- `backend-symfony/data/pcs.json` : liste des postes et tokens QR code ;
- `backend-symfony/data/pannes.json` : liste des types de panne.

---

## Fichiers importants

- `backend-symfony/src/Controller/ServerController.php` : routes API, validation email, création des tickets.
- `backend-symfony/src/Controller/FrontController.php` : routes des pages Twig.
- `backend-symfony/src/Service/GlpiTicketService.php` : communication avec l'API REST GLPI.
- `backend-symfony/src/Service/Database.php` : gestion de la base SQLite.
- `backend-symfony/src/Service/PcCatalog.php` : lecture des postes, salles et types de panne.
- `frontend-twig/src/App.html.twig` : base HTML des pages.
- `frontend-twig/src/pages/home.html.twig` : page d'accueil.
- `frontend-twig/src/pages/report.html.twig` : formulaire et aperçu du ticket.
- `frontend-twig/src/pages/message.html.twig` : pages de confirmation ou d'erreur.
- `frontend-twig/src/pages/my_reports.html.twig` : page Mes signalements.
- `frontend-twig/src/pages/admin.html.twig` : espace administration.
- `backend-symfony/public/app.css` : style de l'application.
- `backend-symfony/public/manifest.json` : configuration PWA.
- `backend-symfony/public/sw.js` : service worker PWA.
- `genere_qr.js` : génération des QR codes.

---

## Configuration

Créer ou modifier le fichier :

```bash
backend-symfony/.env.local
```

Exemple de configuration :

```env
APP_ENV=dev
APP_SECRET=change_me

BACKEND_URL=http://10.120.2.212:8001
FRONTEND_URL=http://10.120.2.212:8001
ADMIN_PASSWORD=admin123

MAILER_DSN=smtp://user:password@smtp.gmail.com:587
MAIL_FROM=adresse@gmail.com

GLPI_URL=http://localhost:8080/apirest.php
GLPI_USER_TOKEN=change_me
GLPI_APP_TOKEN=change_me
```

`BACKEND_URL` et `FRONTEND_URL` doivent utiliser l'adresse IP du PC si l'application est testée depuis un téléphone sur le même réseau.

---

## Installation

Depuis le dossier Symfony :

```bash
cd backend-symfony
composer install
```

---

## Exécution de l'application

Lancer le serveur Symfony :

```bash
cd backend-symfony
php -S 0.0.0.0:8001 -t public
```

Puis ouvrir :

```text
http://localhost:8001
```

ou depuis un autre appareil sur le même réseau :

```text
http://ADRESSE_IP_DU_PC:8001
```

Exemple :

```text
http://10.120.2.212:8001
```

### Accès administrateur

Le bouton administrateur n'est pas affiché dans l'application destinée aux utilisateurs.
L'accès se fait uniquement avec l'URL directe :

```text
http://localhost:8001/admin
```

ou depuis un autre appareil sur le même réseau :

```text
http://ADRESSE_IP_DU_PC:8001/admin
```

Exemple :

```text
http://10.120.2.212:8001/admin
```

---

## Lancer GLPI en local

Depuis le dossier GLPI :

```bash
cd glpi_docker
docker compose up -d
```

GLPI sera disponible sur :

```text
http://localhost:8080
```

---

## Proposition de déploiement avec Docker

L'application Symfony peut être lancée dans un conteneur Docker afin d'éviter d'installer PHP, Composer et les extensions PHP directement sur le serveur.

Avant le lancement, vérifier que le fichier de configuration existe :

```bash
backend-symfony/.env.local
```

Sur un serveur, les variables `BACKEND_URL` et `FRONTEND_URL` doivent utiliser l'adresse du serveur ou le nom de domaine :

```env
BACKEND_URL=http://ADRESSE_DU_SERVEUR:8001
FRONTEND_URL=http://ADRESSE_DU_SERVEUR:8001
```

Si GLPI est installé sur la même machine que Docker, il ne faut pas forcément utiliser `localhost` depuis le conteneur. Il est possible d'utiliser :

```env
GLPI_URL=http://host.docker.internal:8080/apirest.php
```

Créer les fichiers persistants utilisés par l'application :

```bash
touch backend-symfony/database.db
mkdir -p backend-symfony/public/uploads/tickets
```

Lancer l'application avec Docker :

```bash
cd backend-symfony
docker compose up -d --build
```

Si la commande `docker compose` n'est pas disponible, utiliser l'ancienne forme :

```bash
docker-compose up -d --build
```

L'application sera disponible sur :

```text
http://localhost:8001
```

ou depuis le réseau :

```text
http://ADRESSE_DU_SERVEUR:8001
```

Commandes utiles :

```bash
docker compose logs -f
docker compose down
docker compose restart
```

Avec l'ancienne commande :

```bash
docker-compose logs -f
docker-compose down
docker-compose restart
```

Les données importantes sont conservées en dehors du conteneur :

- `backend-symfony/database.db` pour la base SQLite ;
- `backend-symfony/public/uploads/` pour les images jointes.

---

## Routes principales

Pages Twig :

- `GET /`
- `GET /report/{id}`
- `POST /report/{id}`
- `GET /mes-signalements`
- `GET|POST /admin`

Routes API :

- `GET /api/pannes`
- `GET /api/pcs/{id}`
- `POST /api/tickets`
- `POST /api/tickets/check`
- `GET /api/verify-email/{token}`
- `GET /api/admin/salles`
- `GET /api/admin/tickets`

---

## Génération des QR codes

Depuis la racine du projet :

```bash
node genere_qr.js
```

Les QR codes sont générés dans :

```text
qr_codes/
```

---

## Structure du projet

```text
ticket-panne-ceri/
├── backend/              Ancienne version Node.js conservée
├── backend-symfony/      Backend Symfony et fichiers publics
│   ├── src/
│   │   ├── Controller/   Routes API et pages Twig
│   │   └── Service/      Base SQLite, GLPI et catalogue
│   └── public/
├── frontend-twig/        Pages Twig
│   └── src/
│       ├── App.html.twig
│       └── pages/
├── frontend/             Ancienne version React conservée
├── glpi_docker/          GLPI + MariaDB pour test local
├── qr_codes/             QR codes générés
└── genere_qr.js          Script de génération QR code
```

---

## Commandes Git utiles

Passer sur la branche principale :

```bash
git checkout main
```

Récupérer les changements :

```bash
git pull origin main
```

Pousser le travail :

```bash
git add .
git commit -m "Description des modifications"
git push
```
