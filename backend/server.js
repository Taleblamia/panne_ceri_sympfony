const db = require("./db");
const express = require("express");
const cors = require("cors");
const pannes = require("./data/pannes.json");
const pcs = require("./data/pcs.json");
const nodemailer = require("nodemailer");
const crypto = require("crypto");

require("dotenv").config();

const app = express();

const GLPI_URL = process.env.GLPI_URL;
const GLPI_USER_TOKEN = process.env.GLPI_USER_TOKEN;
const GLPI_APP_TOKEN = process.env.GLPI_APP_TOKEN;
const BACKEND_URL = process.env.BACKEND_URL;


const transporter = nodemailer.createTransport({
  service: "gmail",
  auth: {
    user: process.env.MAIL_USER,
    pass: process.env.MAIL_PASS,
  },
});

function getDeviceFingerprint(req) {
  const userAgent = req.headers["user-agent"] || "";
  return crypto.createHash("sha256").update(userAgent).digest("hex");
}

async function sendValidationEmail(email, token) {
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

app.use(cors());
app.use(express.json());

async function initGlpiSession() {
  const response = await fetch(`${GLPI_URL}/initSession`, {
    method: "GET",
    headers: {
      Authorization: `user_token ${GLPI_USER_TOKEN}`,
      "App-Token": GLPI_APP_TOKEN,
    },
  });

  const data = await response.json();

  if (!data.session_token) {
    throw new Error("Impossible de créer une session GLPI");
  }

  return data.session_token;
}

function findPcByIdOrToken(value) {
  return pcs.find((p) => p.id === value || p.token === value);
}

async function createTicketInGlpiAndSave(ticketData) {
  const { pc_id, email, type_panne, custom_panne, description } = ticketData;

  const pc = pcs.find((p) => p.id === pc_id);

  if (!pc) {
    throw new Error("PC introuvable");
  }

  const panneFinale = type_panne === "Autre" ? custom_panne : type_panne;

  const glpiTicket = {
    input: {
      name: `Panne PC ${pc_id} - ${panneFinale}`,
      content: `
Email utilisateur : ${email}
PC ID : ${pc_id}
Salle : ${pc.salle}
Poste : ${pc.poste}
Type de panne : ${type_panne}
${type_panne === "Autre" ? `Panne précisée : ${custom_panne}` : ""}
Description : ${description || "Aucune description"}
      `,
      type: 1,
    },
  };

  const sessionToken = await initGlpiSession();

  const response = await fetch(`${GLPI_URL}/Ticket`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "Session-Token": sessionToken,
      "App-Token": GLPI_APP_TOKEN,
    },
    body: JSON.stringify(glpiTicket),
  });

  const data = await response.json();

  if (!response.ok) {
    throw new Error(JSON.stringify(data));
  }

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

  await fetch(`${GLPI_URL}/killSession`, {
    method: "GET",
    headers: {
      "Session-Token": sessionToken,
      "App-Token": GLPI_APP_TOKEN,
    },
  });

  return data;
}

app.get("/", (req, res) => {
  res.send("API Ticket Panne CERI fonctionne");
});

app.get("/api/pannes", (req, res) => {
  res.json(pannes);
});

app.get("/api/pcs/:id", (req, res) => {
  const pc = findPcByIdOrToken(req.params.id);

  if (!pc) {
    return res.status(404).json({
      message: "PC introuvable",
    });
  }

  res.json(pc);
});

app.post("/api/tickets", async (req, res) => {
  const { pc_id, email, type_panne, custom_panne, description } = req.body;
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

  const verifiedUser = db.prepare(`
    SELECT *
    FROM verified_users
    WHERE email = ?
    AND device_fingerprint = ?
  `).get(email, deviceFingerprint);

  if (!verifiedUser) {
    const token = crypto.randomBytes(32).toString("hex");

    db.prepare(`
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
    `).run(
      token,
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

  db.prepare(`
    DELETE FROM tickets
    WHERE created_at < datetime('now', '-24 hours')
  `).run();

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

  try {
    const data = await createTicketInGlpiAndSave({
      pc_id: realPcId,
      email,
      type_panne,
      custom_panne,
      description,
    });

    res.status(201).json({
      message: "Ticket créé avec succès dans GLPI",
      glpi: data,
    });
  } catch (error) {
    console.error("Erreur création ticket GLPI :", error);

    res.status(500).json({
      message: "Erreur lors de l'envoi du ticket vers GLPI.",
    });
  }
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

  try {
    await createTicketInGlpiAndSave({
      pc_id: pendingTicket.pc_id,
      email: pendingTicket.email,
      type_panne: pendingTicket.type_panne,
      custom_panne: pendingTicket.custom_panne,
      description: pendingTicket.description,
    });

    db.prepare(`
      DELETE FROM pending_tickets
      WHERE token = ?
    `).run(token);

    res.send("Email et appareil validés avec succès. Votre ticket a été créé dans GLPI.");
  } catch (error) {
    console.error("Erreur après validation email :", error);

    res.send(
      "Email validé, mais une erreur est survenue lors de la création du ticket dans GLPI."
    );
  }
});

app.listen(3001, "0.0.0.0", () => {
  console.log("Serveur lancé sur http://0.0.0.0:3001");
});