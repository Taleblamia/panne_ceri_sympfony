const db = require("./db");
const express = require("express");
const cors = require("cors");
const pannes = require("./data/pannes.json");
const pcs = require("./data/pcs.json");
const nodemailer = require("nodemailer");
const crypto = require("crypto");

require("dotenv").config();

const app = express();

const BACKEND_URL = process.env.BACKEND_URL;
const ALADIN_URL = process.env.ALADIN_URL;

const transporter = nodemailer.createTransport({
  service: "gmail",
  auth: {
    user: process.env.MAIL_USER,
    pass: process.env.MAIL_PASS,
  },
});

app.use(cors());
app.use(express.json());

function getDeviceFingerprint(req) {
  const userAgent = req.headers["user-agent"] || "";
  return crypto.createHash("sha256").update(userAgent).digest("hex");
}

function findPcByIdOrToken(value) {
  return pcs.find((p) => p.id === value || p.token === value);
}

function getPanneFinale(type_panne, custom_panne) {
  return type_panne === "Autre" ? custom_panne : type_panne;
}

// Fonction pour nettoyer les textes en supprimant les accents et les caractères spéciaux
function cleanText(text) {
  return (text || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[’]/g, "'")
    .replace(/[“”]/g, '"');
}


function buildAladinUrl(ticketData) {
  // Nettoyer les données pour éviter les problèmes d'encodage dans l'URL
  const { nom, prenom, email, pc_id, type_panne, custom_panne, description } =
    ticketData;

  const pc = pcs.find((p) => p.id === pc_id); // Récupérer les infos du PC pour la description

  const panneFinale = cleanText( // Nettoyer le type de panne pour le titre et la description
    type_panne === "Autre" ? custom_panne : type_panne
  );

  const titre = cleanText(`Panne PC ${pc_id} - ${panneFinale}`); // Titre du ticket pour ALADIN

  const descriptionAladin =
    `Salle ${pc?.salle || "Non renseignee"}                                                                                                        ` +
    `Poste ${pc?.poste || "Non renseigne"}                                                                                                         ` +
    `Panne : ${panneFinale}                                                                ` +
    `Description : ${cleanText(description) || "Aucune description"}`
  ;

  const params = new URLSearchParams(); // Construire les paramètres de l'URL pour ALADIN
  params.set("inst", "1");
  params.set("nom", cleanText(nom));
  params.set("prenom", cleanText(prenom));
  params.set("mel", email);
  params.set("tit", titre);
  params.set("pb", descriptionAladin);

  return `${ALADIN_URL}?${params.toString()}`; // URL finale pour rediriger vers ALADIN avec les données du ticket
}

function saveTicketLocal(ticketData) { // Enregistrer le ticket dans la base de données locale
  const { pc_id, email, type_panne, custom_panne, description } = ticketData;

  db.prepare(`
    INSERT INTO tickets (
      pc_id,
      email,
      type_panne,
      custom_panne,
      description
    )
    VALUES (?, ?, ?, ?, ?)
  `).run(pc_id, email, type_panne, custom_panne, description);
}

async function sendValidationEmail(email, token) { // Envoyer un email de validation à l'utilisateur avec un lien contenant le token
  const validationLink = `${BACKEND_URL}/api/verify-email/${token}`;

  await transporter.sendMail({
    from: `"Ticket Panne CERI" <${process.env.MAIL_USER}>`,
    to: email,
    subject: "Validation de votre appareil",
    html: `
      <h2>Validation de votre appareil</h2>
      <p>Veuillez cliquer sur le lien ci-dessous pour valider votre adresse email et cet appareil.</p>
      <p>Ce lien est valable pendant 30 minutes.</p>
      <a href="${validationLink}">Valider mon appareil</a>
    `,
  });
}
// Routes API
app.get("/", (req, res) => {
  res.send("API Ticket Panne CERI fonctionne");
});

// Route pour récupérer la liste des types de pannes
app.get("/api/pannes", (req, res) => {
  res.json(pannes);
});

// Route pour récupérer les infos d'un PC à partir de son ID ou token
app.get("/api/pcs/:id", (req, res) => {
  const pc = findPcByIdOrToken(req.params.id);

  if (!pc) {
    return res.status(404).json({
      message: "PC introuvable",
    });
  }

  res.json(pc);
});

// Route pour créer un ticket de panne
app.post("/api/tickets", async (req, res) => {
  const {
    nom,
    prenom,
    pc_id,
    email,
    type_panne,
    custom_panne,
    description,
  } = req.body;

  const deviceFingerprint = getDeviceFingerprint(req);
  const pc = findPcByIdOrToken(pc_id);

  if (!pc) {
    return res.status(404).json({
      message: "PC introuvable",
    });
  }

  const realPcId = pc.id;

  db.prepare(`
    DELETE FROM pending_tickets
    WHERE expires_at < datetime('now')
  `).run();

  db.prepare(`
    DELETE FROM tickets
    WHERE created_at < datetime('now', '-24 hours')
  `).run();

  // Vérifier s'il existe déjà un ticket similaire pour ce PC
  const existingTicket = db.prepare(`
    SELECT *
    FROM tickets
    WHERE pc_id = ?
      AND type_panne = ?
      AND IFNULL(custom_panne, '') = IFNULL(?, '')
    LIMIT 1
  `).get(realPcId, type_panne, custom_panne);

  if (existingTicket) {
    return res.status(409).json({
      message: "Cette panne a déjà été signalée sur ce poste.",
    });
  }

  const verifiedUser = db.prepare(`
    SELECT *
    FROM verified_users
    WHERE email = ?
    AND device_fingerprint = ?
  `).get(email, deviceFingerprint);

  const ticketData = {
    nom,
    prenom,
    pc_id: realPcId,
    email,
    type_panne,
    custom_panne,
    description,
  };

  if (!verifiedUser) {
    const token = crypto.randomBytes(32).toString("hex");

    db.prepare(`
      INSERT INTO pending_tickets (
        token,
        nom,
        prenom,
        pc_id,
        email,
        type_panne,
        custom_panne,
        description,
        device_fingerprint,
        expires_at
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now', '+30 minutes'))
    `).run(
      token,
      nom,
      prenom,
      realPcId,
      email,
      type_panne,
      custom_panne,
      description,
      deviceFingerprint
    );

    await sendValidationEmail(email, token);

    return res.status(202).json({
      message:
        "Un email de validation vous a été envoyé. Veuillez valider cet appareil dans les 30 minutes.",
    });
  }

  saveTicketLocal(ticketData);

  const aladinUrl = buildAladinUrl(ticketData);

  res.status(201).json({
    message: "Signalement validé. Redirection vers le formulaire ALADIN.",
    redirectUrl: aladinUrl,
  });
});

app.get("/api/verify-email/:token", async (req, res) => {
  const { token } = req.params;

  const pendingTicket = db.prepare(`
    SELECT *
    FROM pending_tickets
    WHERE token = ?
      AND expires_at >= datetime('now')
  `).get(token);

  if (!pendingTicket) {
    return res.send("Lien invalide ou expiré.");
  }

  db.prepare(`
    INSERT OR IGNORE INTO verified_users (
      email,
      device_fingerprint
    )
    VALUES (?, ?)
  `).run(pendingTicket.email, pendingTicket.device_fingerprint);

  const ticketData = {
    nom: pendingTicket.nom,
    prenom: pendingTicket.prenom,
    pc_id: pendingTicket.pc_id,
    email: pendingTicket.email,
    type_panne: pendingTicket.type_panne,
    custom_panne: pendingTicket.custom_panne,
    description: pendingTicket.description,
  };

  saveTicketLocal(ticketData);

  db.prepare(`
    DELETE FROM pending_tickets
    WHERE token = ?
  `).run(token);

  const aladinUrl = buildAladinUrl(ticketData);

  res.redirect(aladinUrl);
});

app.listen(3001, "0.0.0.0", () => {
  console.log("Serveur lancé sur http://0.0.0.0:3001");
});