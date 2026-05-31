# Ticket Panne CERI

Application web de gestion des tickets de panne pour les salles informatiques du CERI.

---

# Technologies utilisées

## Frontend
- React
- Vite
- React Router DOM
## Lancer le Frontend

```bash
cd frontend
npm.cmd run dev


## Backend
- Node.js
- Express.js
- CORS
## Lancer le backend
```bash
cd backend
node server.js

## Base de données
- SQLite
- better-sqlite3

---

# Structure du projet

```txt
ticket-panne-ceri/
│
├── backend/
│   ├── data/
│   │   ├── pannes.json
│   │   └── pcs.json
│   ├── database.db
│   ├── db.js
│   ├── server.js
│   ├── package.json
│   └── package-lock.json
│
└── frontend/
    ├── src/
    │   ├── App.jsx
    │   └── main.jsx
    ├── package.json
    └── package-lock.json