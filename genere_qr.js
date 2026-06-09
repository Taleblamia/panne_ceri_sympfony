const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const QRCode = require("qrcode");
const sharp = require("sharp");

// Fichier contenant la liste des postes et leurs tokens QR code.
const pcsPath = path.join(__dirname, "backend-symfony/data/pcs.json");
let pcs = require(pcsPath);

// Adresse de l'application utilisée dans les QR codes.
// Elle peut être changée avec FRONT_URL=... node genere_qr.js
const FRONT_URL = process.env.FRONT_URL || "http://10.120.2.212:8001";

// Dossier où les images QR codes sont générées.
const outputDir = path.join(__dirname, "qr_codes");

if (!fs.existsSync(outputDir)) {
  fs.mkdirSync(outputDir);
}

// Ajoute un token unique à chaque PC s'il n'existe pas encore.
pcs = pcs.map((pc) => ({
  ...pc,
  token: pc.token || crypto.randomBytes(32).toString("hex"),
}));

// Sauvegarde pcs.json avec les tokens générés.
fs.writeFileSync(pcsPath, JSON.stringify(pcs, null, 2));

pcs.forEach(async (pc) => {
  // Le QR code pointe vers le formulaire du poste avec son token.
  const url = `${FRONT_URL}/report/${pc.token}`;
  const filePath = path.join(outputDir, `${pc.id}.png`);

  // Génère l'image du QR code.
  const qrBuffer = await QRCode.toBuffer(url, {
    width: 500,
    margin: 2,
  });

  // Ajoute le nom du poste et la salle sous le QR code.
  const svgText = `
    <svg width="500" height="120">
      <rect width="500" height="120" fill="white"/>
      <text x="250" y="45" font-size="32" text-anchor="middle" font-family="Arial" font-weight="bold">
        ${pc.id}
      </text>
      <text x="250" y="85" font-size="22" text-anchor="middle" font-family="Arial">
        Salle ${pc.salle} - Poste ${pc.poste}
      </text>
    </svg>
  `;

  const textBuffer = Buffer.from(svgText);

  // Assemble QR code + texte dans une seule image PNG.
  await sharp({
    create: {
      width: 500,
      height: 620,
      channels: 3,
      background: "white",
    },
  })
    .composite([
      { input: qrBuffer, top: 0, left: 0 },
      { input: textBuffer, top: 500, left: 0 },
    ])
    .png()
    .toFile(filePath);

  console.log(`QR créé : ${pc.id} -> ${pc.token}`);
});
