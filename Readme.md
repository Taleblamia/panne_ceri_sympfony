# Ticket Panne CERI - version ALADIN

Application web de signalement de pannes pour les salles informatiques du CERI.

Cette branche correspond à la version **ALADIN** : l'application permet de scanner le QR code d'un poste, de remplir un formulaire court, puis de rediriger l'utilisateur vers le formulaire ALADIN de l'université déjà prérempli.

---

## Description du problème

Au CERI, les utilisateurs peuvent déjà déclarer une panne via les services de l'université, mais le parcours est assez long : il faut accéder au bon site, s'identifier et remplir plusieurs informations manuellement.

L'objectif de cette application est donc de simplifier ce parcours :

- l'utilisateur scanne le QR code du poste ;
- le poste est reconnu automatiquement ;
- l'utilisateur indique son email universitaire et le type de panne ;
- l'application vérifie l'email et l'appareil ;
- l'utilisateur est redirigé vers ALADIN avec un formulaire déjà prérempli.

---

## Technologies utilisées

### Backend

- PHP
- Symfony
- SQLite
- Symfony Mailer

### Frontend

- Twig
- HTML / CSS / JavaScript
- Lucide Icons
- PWA simple avec `manifest.json` et `sw.js`

### Données locales

- `backend-symfony/data/pcs.json` : liste des postes et tokens QR code
- `backend-symfony/data/pannes.json` : liste des types de panne
- `backend-symfony/database.db` : base SQLite locale

---

## Fonctionnalités

- Scan d'un QR code.
- Saisie manuelle du code poste si le QR code ne fonctionne pas.
- Formulaire de déclaration de panne.
- Email universitaire en minuscule avec domaine automatique.
- Validation du couple email + appareil.
- Redirection vers ALADIN avec les champs préremplis.
- Blocage des doublons : même panne sur le même poste pendant 24h.
- Limite de signalements :
  - maximum 2 signalements par utilisateur et par jour ;
  - maximum 1 signalement par utilisateur sur le même poste et par jour.
- Page `Mes signalements`.
- Mode sombre.
- Changement de langue français / anglais.
- Application installable en PWA selon les conditions du navigateur.

---

## Fichiers importants

- `backend-symfony/server.php` : routes API, validation email, création des signalements.
- `backend-symfony/front.php` : routes des pages Twig.
- `backend-symfony/db.php` : gestion de la base SQLite.
- `backend-symfony/aladin.php` : construction de l'URL ALADIN préremplie.
- `backend-symfony/catalog.php` : lecture des postes et types de panne.
- `frontend-twig/src/App.html.twig` : base HTML des pages.
- `frontend-twig/src/pages/home.html.twig` : page d'accueil.
- `frontend-twig/src/pages/report.html.twig` : formulaire de déclaration.
- `frontend-twig/src/pages/my_reports.html.twig` : page Mes signalements.
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

Exemple de variables :

```env
APP_ENV=dev
APP_SECRET=change_me

BACKEND_URL=http://10.120.2.212:8001
FRONTEND_URL=http://10.120.2.212:8001

ADMIN_PASSWORD=admin123
ALADIN_URL=https://www.appigs.univ-avignon.fr/extranet/interv_ext.html

MAILER_DSN=smtp://user:password@smtp.gmail.com:587
MAIL_FROM=adresse@gmail.com
```

`BACKEND_URL` et `FRONTEND_URL` doivent utiliser l'adresse IP du PC si l'application est testée depuis un téléphone sur le même réseau.

---

## Installation

Depuis le dossier Symfony :

```bash
cd backend-symfony
composer install
composer dump-autoload
```

---

## Exécution

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

---

## Génération des QR codes

Depuis la racine du projet :

```bash
node genere_qr.js
```

Les QR codes sont générés dans le dossier :

```text
qr_codes/
```

---

## Commandes Git utiles

Passer sur la branche ALADIN :

```bash
git checkout aladin
```

Récupérer les changements :

```bash
git pull origin aladin
```

Pousser le travail :

```bash
git add .
git commit -m "Description des modifications"
git push
```

Si la branche n'a pas encore de branche distante associée :

```bash
git push --set-upstream origin aladin
```
