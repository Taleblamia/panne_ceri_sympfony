import { useEffect, useState } from "react";
import { Routes, Route, useParams, useNavigate } from "react-router-dom";
import jsPDF from "jspdf";
import { QRCodeCanvas } from "qrcode.react";
import { FaDesktop } from "react-icons/fa";
import {
  QrCode,
  Info,
  MapPin,
  Monitor,
  Mail,
  CircleAlert,
  FilePenLine,
} from "lucide-react";
import "./App.css";
import { Html5Qrcode } from "html5-qrcode";

function ReportPage() {
  const { id } = useParams();
  const navigate = useNavigate();

  const [pannes, setPannes] = useState([]);
  const [pc, setPc] = useState(null);
  const [pcError, setPcError] = useState("");

  const [userType, setUserType] = useState("etudiant");
  const [emailPrefix, setEmailPrefix] = useState("");
  const [typePanne, setTypePanne] = useState("");
  const [customPanne, setCustomPanne] = useState("");
  const [description, setDescription] = useState("");

  const [preview, setPreview] = useState(false);
  const [confirmed, setConfirmed] = useState(false);

  useEffect(() => {
    fetch("http://10.120.2.212:3001/api/pannes")
      .then((res) => res.json())
      .then((data) => setPannes(data));

    fetch(`http://10.120.2.212:3001/api/pcs/${id}`)
      .then((res) => {
        if (!res.ok) throw new Error("PC introuvable");
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

  function buildEmail() {
    return (
      emailPrefix +
      (userType === "etudiant"
        ? "@alumni.univ-avignon.fr"
        : "@univ-avignon.fr")
    );
  }

  const handleValidate = (e) => {
    e.preventDefault();

    const email = buildEmail();

    const emailRegex =
      /^[a-zA-Z0-9._%+-]+@(univ-avignon\.fr|alumni\.univ-avignon\.fr)$/;

    if (!emailRegex.test(email)) {
      alert("Veuillez utiliser une adresse email universitaire");
      return;
    }

    setPreview(true);
  };

  const handleSend = async () => {
    const email = buildEmail();

    const ticket = {
      pc_id: pc.id,
      email,
      type_panne: typePanne,
      custom_panne: customPanne,
      description,
    };

    const response = await fetch("http://10.120.2.212:3001/api/tickets", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(ticket),
    });

    const data = await response.json();

    if (response.ok) {
      setConfirmed(true);
    }
  };

  const handleDownloadPDF = () => {
    const email = buildEmail();
    const doc = new jsPDF();

    doc.setFontSize(18);
    doc.text("Ticket de panne - CERI", 20, 20);

    doc.setFontSize(12);
    doc.text(`Salle : ${pc?.salle}`, 20, 40);
    doc.text(`Poste : ${pc?.poste}`, 20, 50);
    doc.text(`Email : ${email}`, 20, 60);
    doc.text(`Type de panne : ${typePanne}`, 20, 70);

    if (typePanne === "Autre") {
      doc.text(`Panne précisée : ${customPanne}`, 20, 80);
      doc.text(`Description : ${description || "Aucune description"}`, 20, 90);
      doc.text(`Date : ${new Date().toLocaleString()}`, 20, 100);
    } else {
      doc.text(`Description : ${description || "Aucune description"}`, 20, 80);
      doc.text(`Date : ${new Date().toLocaleString()}`, 20, 90);
    }

    doc.save("ticket-panne.pdf");
  };

  const resetForm = () => {
    setConfirmed(false);
    setPreview(false);
    setEmailPrefix("");
    setTypePanne("");
    setCustomPanne("");
    setDescription("");
  };

  const isFormValid =
    emailPrefix.trim() &&
    typePanne &&
    (typePanne !== "Autre" || customPanne.trim());

  return (
    <div className="report-page">
      <div className="report-card">
        <h1>Déclaration de panne</h1>

        {pcError && (
          <p style={{ color: "red", fontWeight: "bold" }}>{pcError}</p>
        )}

        {pc && !confirmed && (
          <div className="pc-info-card">
            <p>Poste détecté</p>
            <strong>Salle :</strong> {pc.salle}
            <br />
            <strong>Poste :</strong> {pc.poste}
          </div>
        )}

        {pc ? (
          confirmed ? (
            <div className="confirmation-card">
              <div className="success-icon">✓</div>

              <h2>Ticket envoyé !</h2>

              <p>Merci, votre déclaration a bien été enregistrée.</p>


              <button className="primary-btn" onClick={handleDownloadPDF}>
                Télécharger le PDF
              </button>

              <button className="secondary-btn full-btn" onClick={() => navigate("/")}>
                Retour à l'accueil
              </button>
            </div>
          ) : !preview ? (
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

              <button
                type="submit"
                className="primary-btn"
                disabled={!isFormValid}
              >
                Continuer
              </button>
            </form>
          ) : (
            <div className="preview-card">
              <h2>Aperçu du ticket</h2>

              <div className="preview-info-box">
                <Info size={22} />
                <span>
                  Veuillez vérifier les informations avant d’envoyer votre déclaration.
                </span>
              </div>

              <div className="ticket-details-card">
                <div className="ticket-row">
                  <MapPin size={22} />
                  <div>
                    <strong>Salle</strong>
                    <p>{pc?.salle}</p>
                  </div>
                </div>

                <div className="ticket-row">
                  <Monitor size={22} />
                  <div>
                    <strong>Poste</strong>
                    <p>{pc?.poste}</p>
                  </div>
                </div>

                <div className="ticket-row">
                  <Mail size={22} />
                  <div>
                    <strong>Email</strong>
                    <p>{buildEmail()}</p>
                  </div>
                </div>

                <div className="ticket-row">
                  <CircleAlert size={22} />
                  <div>
                    <strong>Type de panne</strong>
                    <p>{typePanne === "Autre" ? customPanne : typePanne}</p>
                  </div>
                </div>

                <div className="ticket-row">
                  <FilePenLine size={22} />
                  <div>
                    <strong>Description</strong>
                    <p>{description || "Aucune description"}</p>
                  </div>
                </div>
              </div>

              <button className="primary-btn" onClick={handleSend}>
                Envoyer le ticket
              </button>

              <button
                className="secondary-btn full-btn"
                onClick={() => setPreview(false)}
              >
                Modifier
              </button>
            </div>
          )
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

    const response = await fetch(`http://10.120.2.212:3001/api/pcs/${code}`);

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

  const url = `http://10.120.2.212:5173/report/${id}`;

  return (
    <div style={{ padding: "40px", textAlign: "center" }}>
      <h1>QR Code PC {id}</h1>

      <QRCodeCanvas
        value={url}
        size={250}
      />

      <p style={{ marginTop: "20px" }}>
        {url}
      </p>
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