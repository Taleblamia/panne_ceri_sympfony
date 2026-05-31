// useState recuperer les valeurs qui changent
// useEffct sert à exécuter du code au chargement de la page
import { useEffect, useState } from "react";

//useOarals recupere l'id dans l'URL
import { Routes, Route, useParams, useNavigate } from "react-router-dom";
import jsPDF from "jspdf"; // génerer le pdf du ticket 
import { QRCodeCanvas } from "qrcode.react"; // afficher le qr code

import { FaDesktop } from "react-icons/fa"; // icone pour le poste informatique
import { QrCode } from "lucide-react"; // icone pour le qr code
import "./App.css"; //import du fichier css pour le style de l'application

import { Html5Qrcode } from "html5-qrcode"; // librairie pour scanner les QR codes

function ReportPage() {
  const { id } = useParams();

  const [pannes, setPannes] = useState([]);
  const [pc, setPc] = useState(null);
  const [pcError, setPcError] = useState("");

  // ces variables stockent ce que l'utilisateur écrit dans le formulaire
  const [email, setEmail] = useState("");
  const [typePanne, setTypePanne] = useState("");
  const [customPanne, setCustomPanne] = useState("");
  const [description, setDescription] = useState("");

  // sert soit à afficher le formulaire ou previsualiser le ticket
  const [preview, setPreview] = useState(false);

  useEffect(() => {
    fetch("http://10.120.2.212:3001/api/pannes")
      .then((res) => res.json())
      .then((data) => setPannes(data));

    fetch(`http://10.120.2.212:3001/api/pcs/${id}`)
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

  //validation du formulaire
  const handleValidate = (e) => {
    e.preventDefault();

    const emailRegex =
      /^[a-zA-Z0-9._%+-]+@(univ-avignon\.fr|alumni\.univ-avignon\.fr)$/;

    if (!emailRegex.test(email)) {
      alert(
        "Veuillez utiliser une adresse email universitaire"
      );
      return;
  }

  //previsualisation du ticket
  setPreview(true);
  };


  //Envoi du ticket
  const handleSend = async () => {
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
    alert(data.message);

    //vider le formulaire apres l'envoie
    setEmail("");
    setTypePanne("");
    setCustomPanne("");
    setDescription("");
    setPreview(false);
  };

  //telecharger le ticket sous forme pdf
  const handleDownloadPDF = () => {
    const doc = new jsPDF();

    doc.setFontSize(18);
    doc.text("Ticket de panne - CERI", 20, 20);

    doc.setFontSize(12);
    doc.text(`Salle : ${pc?.salle}`, 20, 40);
    doc.text(`Poste : ${pc?.numero}`, 20, 50);
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

  //affichage du formulaire
  return (
    <div className="report-page">
      <div className="report-card">
        <h1>Déclaration de panne</h1>
        {pcError && (
          <p style={{ color: "red", fontWeight: "bold" }}>
            {pcError}
          </p>
        )}

        {pc && ( // si les infos du pc sont chargées, on les affiche en haut du formulaire
          <div className="pc-info-card">
            <p>Poste détecté</p>
            <strong>Salle :</strong> {pc.salle}
            <br />
            <strong>Poste :</strong> {pc.poste}
          </div>
        )}

      {pc ? (
        !preview ? (
          <form onSubmit={handleValidate}>
            <label>
              Votre email universitaire <span>*</span>
            </label>

            <input
              type="email"
              placeholder="Votre email universitaire"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              pattern="^[a-zA-Z0-9._%+-]+@(univ-avignon\.fr|alumni\.univ-avignon\.fr)$"
              required
            />

            <small>Seuls les emails universitaires sont acceptés.</small>

            <label>
              Type de panne <span>*</span>
            </label>

            <select
              value={typePanne}
              onChange={(e) => setTypePanne(e.target.value)}
              required>

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

            <br /><br />

            <label>Description (optionnelle)</label>

            <textarea
              placeholder="Décrivez le problème rencontré..."
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            ></textarea>


            <button type="submit" className="primary-btn">
              Continuer
            </button>
          </form>
        ) : (
          <div className="preview-card">
            <h2>Prévisualisation du ticket</h2>

            <p><strong>Salle :</strong> {pc?.salle}</p>
            <p><strong>Poste :</strong> {pc?.numero}</p>
            <p><strong>Service Tag :</strong> {pc?.service_tag}</p>
            <p><strong>Email :</strong> {email}</p>
            <p><strong>Type de panne :</strong> {typePanne}</p>

            {typePanne === "Autre" && (
              <p>
                <strong>Panne précisée :</strong> {customPanne}
              </p>
            )}

            <p>
              <strong>Description :</strong> {description || "Aucune description"}
            </p>

            <p>
              <strong>Date :</strong> {new Date().toLocaleString()}
            </p>

            <button className="primary-btn" onClick={handleSend}>
              Envoyer le ticket
            </button>

            <button className="secondary-btn" onClick={() => setPreview(false)}>
              Modifier
            </button>

            <button className="secondary-btn" onClick={handleDownloadPDF}>
              Télécharger PDF
            </button>
          </div>
        )
      ) : null}
    </div>
  </div>
  );
}

function HomePage() {
  const [pcCode, setPcCode] = useState(""); //stocke le code poste que l'utilisateur entre dans le champ de saisie

  const navigate = useNavigate();
  const [showScanner, setShowScanner] = useState(false); //sert à afficher ou cacher le scanner de QR code

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

  useEffect(() => { // code pour scanner le QR code et rediriger vers la page de déclaration de panne du poste scanné
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

        <button
          className="scan-btn"
          onClick={() => setShowScanner(true)}
        >
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
          Vous pouvez aussi saisir le code du poste si le QR code ne fonctionne pas.
        </div>

        <div className="manual-box">
          <input
            type="text"
            placeholder="Exemple : mc128-24"
            value={pcCode}
            onChange={(e) => setPcCode(e.target.value)}
          />

          <button onClick={handleRedirect}>
            Continuer
          </button>
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