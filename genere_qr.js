const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const QRCode = require("qrcode");
const sharp = require("sharp");

const pcsPath = path.join(__dirname, "backend-symfony/data/pcs.json");
let pcs = require(pcsPath);

const FRONT_URL = process.env.FRONT_URL || "http://10.188.74.8:8000";
const outputDir = path.join(__dirname, "qr_codes");

if (!fs.existsSync(outputDir)) {
  fs.mkdirSync(outputDir);
}

// ajouter un token à chaque PC s'il n'existe pas encore
pcs = pcs.map((pc) => ({
  ...pc,
  token: pc.token || crypto.randomBytes(32).toString("hex"),
}));

// sauvegarder pcs.json avec les tokens
fs.writeFileSync(pcsPath, JSON.stringify(pcs, null, 2));

pcs.forEach(async (pc) => {
  const url = `${FRONT_URL}/report/${pc.token}`;
  const filePath = path.join(outputDir, `${pc.id}.png`);

  const qrBuffer = await QRCode.toBuffer(url, {
    width: 500,
    margin: 2,
  });

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
