// useState recuperer les valeurs qui changent
// useEffect sert à exécuter du code au chargement de la page
import { useEffect, useState } from "react";

// useParams recupere l'id dans l'URL
import { Routes, Route, useParams, useNavigate } from "react-router-dom";
import { QRCodeCanvas } from "qrcode.react";

import { FaDesktop } from "react-icons/fa";
import { QrCode } from "lucide-react";
import "./App.css";

import { Html5Qrcode } from "html5-qrcode";

const API_URL = import.meta.env.VITE_API_URL;
const FRONT_URL = import.meta.env.VITE_FRONTEND_URL;

function ReportPage() {
  const { id } = useParams();

  const [pannes, setPannes] = useState([]);
  const [pc, setPc] = useState(null);
  const [pcError, setPcError] = useState("");

  const [userType, setUserType] = useState("etudiant");
  const [emailPrefix, setEmailPrefix] = useState("");
  const [typePanne, setTypePanne] = useState("");
  const [customPanne, setCustomPanne] = useState("");
  const [description, setDescription] = useState("");


  useEffect(() => {
    fetch(`${API_URL}/pannes`)
      .then((res) => res.json())
      .then((data) => setPannes(data));

    fetch(`${API_URL}/pcs/${id}`)
      .then((res) => {
        if (!res.ok) {
          throw new Error("PC introuvable");
        }
        return res.json();
      })
      .then((data) => {
        setPc(data);
        setPcError("");
      })
      .catch(() => {
        setPc(null);
        setPcError("PC introuvable. Veuillez vérifier le code du poste.");
      });
  }, [id]);

  const handleValidate = (e) => {
    e.preventDefault();
    const email =
      emailPrefix +
      (userType === "etudiant"
        ? "@alumni.univ-avignon.fr"
        : "@univ-avignon.fr");
    handleSend();
  };

  function getIdentityFromEmail(email) {
    const username = email.split("@")[0]; // Récupère la partie avant le @
    const parts = username.split("."); // Sépare le prénom et le nom en utilisant le point comme séparateur

    const prenom = parts[0] || ""; // Le prénom est la première partie de l'email
    const nom = parts.slice(1).join(" ") || ""; // Le nom  est le reste de l'email après le prénom

    return {
      prenom,
      nom,
    };
  }

  const handleSend = async () => {
    const email =
      emailPrefix +
      (userType === "etudiant"
        ? "@alumni.univ-avignon.fr"
        : "@univ-avignon.fr");
    const { nom, prenom } = getIdentityFromEmail(email);

    const ticket = {
      nom,
      prenom,
      pc_id: pc.id,
      email,
      type_panne: typePanne,
      custom_panne: customPanne,
      description,
    };

    const response = await fetch(`${API_URL}/tickets`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(ticket),
    });

    const data = await response.json();
    alert(data.message);

    if (data.redirectUrl) {
      window.location.href = data.redirectUrl;
      return;
    }

    setEmailPrefix("");
    setTypePanne("");
    setCustomPanne("");
    setDescription("");
  };

  const isFormValid =
    emailPrefix.trim() &&
    typePanne &&
    (typePanne !== "Autre" || customPanne.trim())
  ;

  return (
    <div className="report-page">
      <div className="report-card">
        <h1>Déclaration de panne</h1>

        {pcError && (
          <p style={{ color: "red", fontWeight: "bold" }}>{pcError}</p>
        )}

        {pc && (
          <div className="pc-info-card">
            <p>Poste détecté</p>
            <strong>Salle :</strong> {pc.salle}
            <br />
            <strong>Poste :</strong> {pc.poste}
          </div>
        )}

        {pc ? (
          
            <form onSubmit={handleValidate}>
              <label>
                Type d'utilisateur <span>*</span>
              </label>

              <div className="user-type-box">
              <label>
                <input
                  type="radio"
                  value="etudiant"
                  checked={userType === "etudiant"}
                  onChange={(e) => setUserType(e.target.value)}
                />
                Étudiant
              </label>

              <label>
                <input
                  type="radio"
                  value="Professeur"
                  checked={userType === "Professeur"}
                  onChange={(e) => setUserType(e.target.value)}
                />
                Professeur
              </label>
            </div>

              <label>
                Adresse email universitaire <span>*</span>
              </label>

              <div className="email-box">
                <input
                  type="text"
                  placeholder="prenom.nom"
                  value={emailPrefix}
                  onChange={(e) => setEmailPrefix(e.target.value)}
                  required
                />

                <span>
                  {userType === "etudiant"
                    ? "@alumni.univ-avignon.fr"
                    : "@univ-avignon.fr"}
                </span>
              </div>

              <label>
                Type de panne <span>*</span>
              </label>

              <select
                value={typePanne}
                onChange={(e) => setTypePanne(e.target.value)}
                required
              >
                <option value="">Sélectionnez le type de panne</option>

                {pannes.map((panne, index) => (
                  <option key={index} value={panne}>
                    {panne}
                  </option>
                ))}
              </select>

              {typePanne === "Autre" && (
                <>
                  <label>
                    Définir la panne <span>*</span>
                  </label>

                  <input
                    type="text"
                    placeholder="Exemple : Problème de casque"
                    value={customPanne}
                    onChange={(e) => setCustomPanne(e.target.value)}
                    required
                  />
                </>
              )}

              <br />
              <br />

              <label>Description (optionnelle)</label>

              <textarea
                placeholder="Décrivez le problème rencontré..."
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              ></textarea>

              <button type="submit"
                className="primary-btn"
                disabled={!isFormValid}
              >
                Continuer
              </button>
            </form>
          
        ) : null}
      </div>
    </div>
  );
}

function HomePage() {
  const [pcCode, setPcCode] = useState("");
  const navigate = useNavigate();
  const [showScanner, setShowScanner] = useState(false);

  const handleRedirect = async () => {
    if (!pcCode) {
      alert("Veuillez entrer un code poste");
      return;
    }

    const code = pcCode.toLowerCase();

    const response = await fetch(`${API_URL}/pcs/${code}`);

    if (!response.ok) {
      alert("PC introuvable. Veuillez vérifier le code du poste.");
      return;
    }

    navigate(`/report/${code}`);
  };

  useEffect(() => {
    if (showScanner) {
      const html5QrCode = new Html5Qrcode("reader");

      Html5Qrcode.getCameras()
        .then((devices) => {
          if (devices && devices.length) {
            const cameraId = devices[0].id;

            html5QrCode.start(
              cameraId,
              {
                fps: 10,
                qrbox: 250,
              },
              (decodedText) => {
                window.location.href = decodedText;
              },
              (errorMessage) => {
                console.log(errorMessage);
              }
            );
          }
        })
        .catch((err) => {
          console.log(err);
        });

      return () => {
        html5QrCode.stop().catch(() => {});
      };
    }
  }, [showScanner]);

  return (
    <div className="home-page">
      <div className="home-card">
        <div className="home-header">
          <FaDesktop className="pc-logo" />
        </div>

        <div className="home-content">
          <h1>Ticket Panne CERI</h1>

          <p>
            Signalez facilement une panne sur un poste informatique en scannant
            le QR code du poste concerné.
          </p>

          <button className="scan-btn" onClick={() => setShowScanner(true)}>
            <QrCode size={22} />
            Scanner un QR code
          </button>

          {showScanner && (
            <div
              id="reader"
              style={{
                marginTop: "20px",
              }}
            ></div>
          )}

          <div className="info-box">
            Vous pouvez aussi saisir le code du poste si le QR code ne fonctionne
            pas.
          </div>

          <div className="manual-box">
            <input
              type="text"
              placeholder="Exemple : mc128-24"
              value={pcCode}
              onChange={(e) => setPcCode(e.target.value)}
            />

            <button onClick={handleRedirect}>Continuer</button>
          </div>
        </div>
      </div>
    </div>
  );
}

function QRPage() {
  const { id } = useParams();

  const url = `${FRONT_URL}/report/${id}`;

  return (
    <div style={{ padding: "40px", textAlign: "center" }}>
      <h1>QR Code PC {id}</h1>

      <QRCodeCanvas value={url} size={250} />

      <p style={{ marginTop: "20px" }}>{url}</p>
    </div>
  );
}

function App() {
  return (
    <Routes>
      <Route path="/" element={<HomePage />} />
      <Route path="/report/:id" element={<ReportPage />} />
      <Route path="/qr/:id" element={<QRPage />} />
    </Routes>
  );
}

export default App;