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
  LogOut,
} from "lucide-react";
import "./App.css";
import { Html5Qrcode } from "html5-qrcode";

import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  ArcElement,
  Tooltip,
  Legend,
} from "chart.js";

import { Bar, Doughnut } from "react-chartjs-2";

ChartJS.register(
  CategoryScale,
  LinearScale,
  BarElement,
  ArcElement,
  Tooltip,
  Legend
);

const API_URL = import.meta.env.VITE_API_URL;
const FRONT_URL = import.meta.env.VITE_FRONTEND_URL;

function getCookie(name) {
  const cookies = document.cookie.split(";");

  for (let cookie of cookies) {
    const [key, value] = cookie.trim().split("=");

    if (key === name) {
      return decodeURIComponent(value);
    }
  }

  return null;
}

function setCookie(name, value) {
  document.cookie = `${name}=${encodeURIComponent(value)}; max-age=31536000; path=/`;
}

function ReportPage({ language }) {
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
  const [waitingValidation, setWaitingValidation] = useState(false);

  const text = {
    fr: {
      title: "Déclaration de panne",
      pcError: "PC introuvable. Veuillez vérifier le code du poste.",
      detected: "Poste détecté",
      room: "Salle",
      computer: "Poste",
      userType: "Type d'utilisateur",
      student: "Étudiant",
      teacher: "Professeur",
      email: "Adresse email universitaire",
      emailAlert: "Veuillez utiliser une adresse email universitaire",
      problemType: "Type de panne",
      selectProblem: "Sélectionnez le type de panne",
      defineProblem: "Définir la panne",
      customPlaceholder: "Exemple : Problème de casque",
      description: "Description (optionnelle)",
      descriptionPlaceholder: "Décrivez le problème rencontré...",
      continue: "Continuer",
      previewTitle: "Aperçu du ticket",
      previewInfo: "Veuillez vérifier les informations avant d’envoyer votre déclaration.",
      sendTicket: "Envoyer le ticket",
      edit: "Modifier",
      emailSentTitle: "Email envoyé !",
      emailSentText: "Un email de validation a été envoyé à :",
      validateText: "Veuillez valider votre adresse email pour finaliser votre demande.",
      delay: "Lien valable 30 minutes.",
      ticketSentTitle: "Ticket envoyé !",
      ticketSentText: "Merci, votre déclaration a bien été enregistrée.",
      downloadPdf: "Télécharger le PDF",
      backHome: "Retour à l'accueil",
      noDescription: "Aucune description",
      pdfTitle: "Ticket de panne - CERI",
      customProblem: "Panne précisée",
      date: "Date",
    },
    en: {
      title: "Report a problem",
      pcError: "Computer not found. Please check the workstation code.",
      detected: "Detected workstation",
      room: "Room",
      computer: "Workstation",
      userType: "User type",
      student: "Student",
      teacher: "Teacher",
      email: "University email address",
      emailAlert: "Please use a university email address",
      problemType: "Problem type",
      selectProblem: "Select the problem type",
      defineProblem: "Specify the problem",
      customPlaceholder: "Example: Headset issue",
      description: "Description (optional)",
      descriptionPlaceholder: "Describe the problem...",
      continue: "Continue",
      previewTitle: "Ticket preview",
      previewInfo: "Please check the information before submitting your report.",
      sendTicket: "Submit ticket",
      edit: "Edit",
      emailSentTitle: "Email sent!",
      emailSentText: "A validation email has been sent to:",
      validateText: "Please validate your email address to complete your request.",
      delay: "Link valid for 30 minutes.",
      ticketSentTitle: "Ticket sent!",
      ticketSentText: "Thank you, your report has been saved.",
      downloadPdf: "Download PDF",
      backHome: "Back to home",
      noDescription: "No description",
      pdfTitle: "CERI failure ticket",
      customProblem: "Specified problem",
      date: "Date",
    },
  };

  const t = text[language] || text.fr;

  useEffect(() => {
    const savedEmailPrefix = getCookie("emailPrefix");
    const savedUserType = getCookie("userType");

    if (savedEmailPrefix) {
      setEmailPrefix(savedEmailPrefix);
    }

    if (savedUserType) {
      setUserType(savedUserType);
    }

    fetch(`${API_URL}/pannes`)
      .then((res) => res.json())
      .then((data) => setPannes(data));

    fetch(`${API_URL}/pcs/${id}`)
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
        setPcError(t.pcError);
      });
  }, [id, t.pcError]);

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
      alert(t.emailAlert);
      return;
    }

    setPreview(true);
  };

  const handleSend = async () => {
    const email = buildEmail();
    setCookie("emailPrefix", emailPrefix.toLowerCase());
    setCookie("userType", userType);

    const ticket = {
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

    if (response.status === 202) {
      setWaitingValidation(true);
      return;
    }

    if (response.status === 201) {
      setConfirmed(true);
      return;
    }

    alert(data.message);
  };

  const handleDownloadPDF = () => {
    const email = buildEmail();
    const doc = new jsPDF();

    doc.setFontSize(18);
    doc.text(t.pdfTitle, 20, 20);

    doc.setFontSize(12);
    doc.text(`${t.room} : ${pc?.salle}`, 20, 40);
    doc.text(`${t.computer} : ${pc?.poste}`, 20, 50);
    doc.text(`${t.email} : ${email}`, 20, 60);
    doc.text(`${t.problemType} : ${typePanne}`, 20, 70);

    if (typePanne === "Autre") {
      doc.text(`${t.customProblem} : ${customPanne}`, 20, 80);
      doc.text(`${t.description} : ${description || t.noDescription}`, 20, 90);
      doc.text(`${t.date} : ${new Date().toLocaleString()}`, 20, 100);
    } else {
      doc.text(`${t.description} : ${description || t.noDescription}`, 20, 80);
      doc.text(`${t.date} : ${new Date().toLocaleString()}`, 20, 90);
    }

    doc.save("ticket-panne.pdf");
  };

  const isFormValid =
    emailPrefix.trim() &&
    typePanne &&
    (typePanne !== "Autre" || customPanne.trim());

  return (
    <div className="report-page">
      <div className="report-card">
        <h1>{t.title}</h1>

        {pcError && (
          <p style={{ color: "red", fontWeight: "bold" }}>{pcError}</p>
        )}

        {pc && !confirmed && !waitingValidation && (
          <div className="pc-info-card">
            <p>{t.detected}</p>
            <strong>{t.room} :</strong> {pc.salle}
            <br />
            <strong>{t.computer} :</strong> {pc.poste}
          </div>
        )}

        {pc ? (
          waitingValidation ? (
            <div className="confirmation-card">
              <div className="mail-icon">📧</div>

              <h2>{t.emailSentTitle}</h2>

              <p className="confirmation-text">{t.emailSentText}</p>

              <div className="success-box">
                <strong>{buildEmail()}</strong>
              </div>

              <p className="confirmation-text">{t.validateText}</p>

              <p className="delay-text">{t.delay}</p>
            </div>
          ) : confirmed ? (
            <div className="confirmation-card">
              <div className="success-icon">✓</div>

              <h2>{t.ticketSentTitle}</h2>

              <p>{t.ticketSentText}</p>

              <button className="primary-btn" onClick={handleDownloadPDF}>
                {t.downloadPdf}
              </button>

              <button
                className="secondary-btn full-btn"
                onClick={() => navigate("/")}
              >
                {t.backHome}
              </button>
            </div>
          ) : !preview ? (
            <form onSubmit={handleValidate}>
              <label>
                {t.userType} <span>*</span>
              </label>

              <div className="user-type-box">
                <label>
                  <input
                    type="radio"
                    value="etudiant"
                    checked={userType === "etudiant"}
                    onChange={(e) => setUserType(e.target.value)}
                  />
                  {t.student}
                </label>

                <label>
                  <input
                    type="radio"
                    value="Professeur"
                    checked={userType === "Professeur"}
                    onChange={(e) => setUserType(e.target.value)}
                  />
                  {t.teacher}
                </label>
              </div>

              <label>
                {t.email} <span>*</span>
              </label>

              <div className="email-box">
                <input
                  type="text"
                  placeholder="prenom.nom"
                  value={emailPrefix}
                  onChange={(e) => setEmailPrefix(e.target.value.toLowerCase())}
                  required
                />

                <span>
                  {userType === "etudiant"
                    ? "@alumni.univ-avignon.fr"
                    : "@univ-avignon.fr"}
                </span>
              </div>

              <label>
                {t.problemType} <span>*</span>
              </label>

              <select
                value={typePanne}
                onChange={(e) => setTypePanne(e.target.value)}
                required
              >
                <option value="">{t.selectProblem}</option>

                {pannes.map((panne, index) => (
                  <option key={index} value={panne}>
                    {panne}
                  </option>
                ))}
              </select>

              {typePanne === "Autre" && (
                <>
                  <label>
                    {t.defineProblem} <span>*</span>
                  </label>

                  <input
                    type="text"
                    placeholder={t.customPlaceholder}
                    value={customPanne}
                    onChange={(e) => setCustomPanne(e.target.value)}
                    required
                  />
                </>
              )}

              <br />
              <br />

              <label>{t.description}</label>

              <textarea
                placeholder={t.descriptionPlaceholder}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
              ></textarea>

              <button
                type="submit"
                className="primary-btn"
                disabled={!isFormValid}
              >
                {t.continue}
              </button>
            </form>
          ) : (
            <div className="preview-card">
              <h2>{t.previewTitle}</h2>

              <div className="preview-info-box">
                <Info size={22} />
                <span>{t.previewInfo}</span>
              </div>

              <div className="ticket-details-card">
                <div className="ticket-row">
                  <MapPin size={22} />
                  <div>
                    <strong>{t.room}</strong>
                    <p>{pc?.salle}</p>
                  </div>
                </div>

                <div className="ticket-row">
                  <Monitor size={22} />
                  <div>
                    <strong>{t.computer}</strong>
                    <p>{pc?.poste}</p>
                  </div>
                </div>

                <div className="ticket-row">
                  <Mail size={22} />
                  <div>
                    <strong>{t.email}</strong>
                    <p>{buildEmail()}</p>
                  </div>
                </div>

                <div className="ticket-row">
                  <CircleAlert size={22} />
                  <div>
                    <strong>{t.problemType}</strong>
                    <p>{typePanne === "Autre" ? customPanne : typePanne}</p>
                  </div>
                </div>

                <div className="ticket-row">
                  <FilePenLine size={22} />
                  <div>
                    <strong>{t.description}</strong>
                    <p>{description || t.noDescription}</p>
                  </div>
                </div>
              </div>

              <button className="primary-btn" onClick={handleSend}>
                {t.sendTicket}
              </button>

              <button
                className="secondary-btn full-btn"
                onClick={() => setPreview(false)}
              >
                {t.edit}
              </button>
            </div>
          )
        ) : null}
      </div>
    </div>
  );
}

function HomePage({ language, changeLanguage, darkMode, setDarkMode }) {
  const [pcCode, setPcCode] = useState("");
  const navigate = useNavigate();
  const [showScanner, setShowScanner] = useState(false);
  const [showMenu, setShowMenu] = useState(false);
  useEffect(() => {
    const handleClickOutside = () => {
      setShowMenu(false);
    };

    document.addEventListener("click", handleClickOutside);

    return () => {
      document.removeEventListener("click", handleClickOutside);
    };
  }, []);

  const text = {
    fr: {
      title: "Ticket Panne CERI",
      description:
        "Signalez facilement une panne sur un poste informatique en scannant le QR code du poste concerné.",
      scan: "Scanner un QR code",
      info: "Vous pouvez aussi saisir le code du poste si le QR code ne fonctionne pas.",
      placeholder: "Exemple : mc128-24",
      continue: "Continuer",
      language: "Passer en anglais",
      features: "Fonctionnalités",
      admin: "Espace admin",
      enterCode: "Veuillez entrer un code poste",
      pcNotFound: "PC introuvable. Veuillez vérifier le code du poste.",
      noCamera: "Aucune caméra détectée.",
      cameraAccess: "Impossible d'accéder à la caméra.",
    },
    en: {
      title: "CERI Failure Ticket",
      description:
        "Easily report a problem on a computer by scanning the QR code of the workstation.",
      scan: "Scan a QR code",
      info: "You can also enter the workstation code if the QR code does not work.",
      placeholder: "Example: mc128-24",
      continue: "Continue",
      language: "Switch to French",
      features: "Features",
      admin: "Admin area",
      enterCode: "Please enter a workstation code",
      pcNotFound: "Computer not found. Please check the workstation code.",
      noCamera: "No camera detected.",
      cameraAccess: "Unable to access the camera.",
    },
  };

  const t = text[language] || text.fr;

  const handleRedirect = async () => {
    if (!pcCode) {
      alert(t.enterCode);
      return;
    }

    const code = pcCode.toLowerCase();

    const response = await fetch(`${API_URL}/pcs/${code}`);

    if (!response.ok) {
      alert(t.pcNotFound);
      return;
    }

    const pc = await response.json();
    navigate(`/report/${pc.token}`);
  };

  useEffect(() => {
    let html5QrCode;
    let isStarted = false;

    if (showScanner) {
      html5QrCode = new Html5Qrcode("reader");

      Html5Qrcode.getCameras()
        .then((devices) => {
          if (!devices || devices.length === 0) {
            alert(t.noCamera);
            return;
          }

          const cameraId = devices[0].id;

          html5QrCode
            .start(
              cameraId,
              {
                fps: 10,
                qrbox: 250,
              },
              (decodedText) => {
                if (isStarted) {
                  html5QrCode
                    .stop()
                    .then(() => {
                      window.location.href = decodedText;
                    })
                    .catch(() => {
                      window.location.href = decodedText;
                    });
                } else {
                  window.location.href = decodedText;
                }
              },
              () => {}
            )
            .then(() => {
              isStarted = true;
            })
            .catch((err) => {
              console.error("Erreur démarrage scanner :", err);
              alert(t.cameraAccess);
            });
        })
        .catch((err) => {
          console.error("Erreur caméra :", err);
          alert(t.cameraAccess);
        });
    }

    return () => {
      if (html5QrCode && isStarted) {
        html5QrCode.stop().catch(() => {});
      }
    };
  }, [showScanner, t.noCamera, t.cameraAccess]);

  return (
    <div className="home-page">
      <div className="home-card">
        <div className="home-header">
          <FaDesktop className="pc-logo" />
        </div>
        <button
          className="admin-menu-btn"
          onClick={(e) => {
            e.stopPropagation();
            setShowMenu(!showMenu);
          }}
        >
          ☰
        </button>

        {showMenu && (
          <div
            className="home-menu"
            onClick={(e) => e.stopPropagation()}
          >
            <button onClick={changeLanguage}>
              🌐 {t.language}
            </button>
            <button onClick={() => setDarkMode(!darkMode)}>
              {darkMode ? "☀️ Mode clair" : "🌙 Mode sombre"}
            </button>
            <button onClick={() => navigate("/admin/login")}>
              ↪ {t.admin}
            </button>
          </div>
        )}

        <div className="home-content">
          <h1>{t.title}</h1>

          <p>{t.description}</p>

          <button className="scan-btn" onClick={() => setShowScanner(true)}>
            <QrCode size={22} />
            {t.scan}
          </button>

          {showScanner && (
            <div
              id="reader"
              style={{
                marginTop: "20px",
              }}
            ></div>
          )}

          <div className="info-box">{t.info}</div>

          <div className="manual-box">
            <input
              type="text"
              placeholder={t.placeholder}
              value={pcCode}
              onChange={(e) => setPcCode(e.target.value)}
            />

            <button onClick={handleRedirect}>{t.continue}</button>
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

function AdminLoginPage({ language }) {
  const [password, setPassword] = useState("");
  const navigate = useNavigate();

  const text = {
    fr: {
      title: "Espace admin",
      password: "Mot de passe",
      placeholder: "Votre mot de passe",
      enter: "Entrer",
      back: "Retour",
      incorrect: "Mot de passe incorrect",
    },
    en: {
      title: "Admin area",
      password: "Password",
      placeholder: "Your password",
      enter: "Log in",
      back: "Back",
      incorrect: "Incorrect password",
    },
  };

  const t = text[language] || text.fr;

  const handleLogin = async (e) => {
    e.preventDefault();

    const response = await fetch(`${API_URL}/admin/tickets`, {
      headers: {
        "x-admin-password": password,
      },
    });

    if (!response.ok) {
      alert(t.incorrect);
      return;
    }

    sessionStorage.setItem("adminPassword", password);
    navigate("/admin");
  };

  return (
    <div className="report-page">
      <div className="report-card">
        <h1>{t.title}</h1>

        <form onSubmit={handleLogin}>
          <label>{t.password}</label>

          <input
            type="password"
            placeholder={t.placeholder}
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />

          <button className="primary-btn" type="submit">
            {t.enter}
          </button>

          <button
            type="button"
            className="secondary-btn full-btn"
            onClick={() => navigate("/")}
          >
            {t.back}
          </button>
        </form>
      </div>
    </div>
  );
}

function AdminPage({ language }) {
  const navigate = useNavigate();
  const [tickets, setTickets] = useState([]);
  const [salleFilter, setSalleFilter] = useState("");
  const [section, setSection] = useState("liste");

  const text = {
    fr: {
      title: "Administration",
      logout: "Déconnexion",
      list: "Liste des pannes",
      stats: "Statistiques",
      about: "À propos",
      total: "Total des pannes déclarées",
      filterRoom: "Filtrer par salle",
      placeholder: "Exemple : 128",
      pc: "PC",
      room: "Salle",
      computer: "Poste",
      email: "Email",
      problem: "Panne",
      description: "Description",
      noDescription: "Aucune",
      details: "Voir détails",
      totalProblems: "Nombre total de pannes",
      concernedRooms: "Nombre de salles concernées",
      problemsByRoom: "Pannes par salle",
      problemsByType: "Pannes par type",
      problemCount: "Nombre de pannes",
      typeCount: "Types de pannes",
      aboutText:
        "Cette interface admin permet de consulter les pannes déclarées, filtrer par salle et suivre les signalements.",
    },
    en: {
      title: "Administration",
      logout: "Log out",
      list: "Ticket list",
      stats: "Statistics",
      about: "About",
      total: "Total reported problems",
      filterRoom: "Filter by room",
      placeholder: "Example: 128",
      pc: "PC",
      room: "Room",
      computer: "Workstation",
      email: "Email",
      problem: "Problem",
      description: "Description",
      noDescription: "None",
      details: "View details",
      totalProblems: "Total number of problems",
      concernedRooms: "Number of concerned rooms",
      problemsByRoom: "Problems by room",
      problemsByType: "Problems by type",
      problemCount: "Number of problems",
      typeCount: "Problem types",
      aboutText:
        "This admin interface lets you view reported problems, filter by room and track reports.",
    },
  };

  const t = text[language] || text.fr;

  useEffect(() => {
    const adminPassword = sessionStorage.getItem("adminPassword");

    if (!adminPassword) {
      navigate("/admin/login");
      return;
    }

    fetch(`${API_URL}/admin/tickets`, {
      headers: {
        "x-admin-password": adminPassword,
      },
    })
      .then((res) => {
        if (!res.ok) {
          sessionStorage.removeItem("adminPassword");
          navigate("/admin/login");
          return [];
        }

        return res.json();
      })
      .then((data) => setTickets(data));
  }, [navigate]);

  const filteredTickets = salleFilter
    ? tickets.filter((ticket) => String(ticket.salle) === salleFilter)
    : tickets;

  const pannesParSalle = Object.values(
    tickets.reduce((acc, ticket) => {
      const salle = ticket.salle || "Inconnue";

      if (!acc[salle]) {
        acc[salle] = { salle, nombre: 0 };
      }

      acc[salle].nombre += 1;
      return acc;
    }, {})
  );

  const pannesParType = Object.values(
    tickets.reduce((acc, ticket) => {
      const type =
        ticket.type_panne === "Autre"
          ? ticket.custom_panne || "Autre"
          : ticket.type_panne;

      if (!acc[type]) {
        acc[type] = { type, nombre: 0 };
      }

      acc[type].nombre += 1;
      return acc;
    }, {})
  );

  const chartSalleData = {
    labels: pannesParSalle.map((item) => `${t.room} ${item.salle}`),
    datasets: [
      {
        label: t.problemCount,
        data: pannesParSalle.map((item) => item.nombre),
        backgroundColor: "#003bba",
        borderRadius: 8,
      },
    ],
  };

  const chartTypeData = {
    labels: pannesParType.map((item) => item.type),
    datasets: [
      {
        label: t.typeCount,
        data: pannesParType.map((item) => item.nombre),
        backgroundColor: [
          "#003bba",
          "#f97316",
          "#22c55e",
          "#ef4444",
          "#a855f7",
          "#14b8a6",
        ],
      },
    ],
  };

  return (
    <div className="report-page">
      <div className="report-card admin-card">
        <button
          className="logout-icon"
          onClick={() => {
            sessionStorage.removeItem("adminPassword");
            navigate("/");
          }}
          title={t.logout}
        >
          <LogOut size={22} />
        </button>

        <h1>{t.title}</h1>

        <div className="admin-layout">
          <div className="admin-topbar">
            <button
              onClick={() => setSection("liste")}
              className={section === "liste" ? "active" : ""}
              title={t.list}
            >
              📋
            </button>

            <button
              onClick={() => setSection("stats")}
              className={section === "stats" ? "active" : ""}
              title={t.stats}
            >
              📊
            </button>

            <button
              onClick={() => setSection("about")}
              className={section === "about" ? "active" : ""}
              title={t.about}
            >
              ℹ️
            </button>
          </div>

          <div className="admin-content">
            {section === "liste" && (
              <>
                <div className="admin-summary">
                  {t.total} : {tickets.length}
                </div>

                <label>{t.filterRoom}</label>
                <input
                  type="text"
                  placeholder={t.placeholder}
                  value={salleFilter}
                  onChange={(e) => setSalleFilter(e.target.value)}
                />

                {filteredTickets.map((ticket) => (
                  <div className="pc-info-card" key={ticket.id}>
                    <strong>{t.pc} :</strong> {ticket.pc_id}
                    <br />
                    <strong>{t.room} :</strong> {ticket.salle}
                    <br />
                    <strong>{t.computer} :</strong> {ticket.poste}
                    <br />
                    <strong>{t.email} :</strong> {ticket.email}
                    <br />
                    <strong>{t.problem} :</strong>{" "}
                    {ticket.type_panne === "Autre"
                      ? ticket.custom_panne
                      : ticket.type_panne}
                    <br />
                    <strong>{t.description} :</strong>{" "}
                    {ticket.description || t.noDescription}

                    <button
                      className="primary-btn"
                      onClick={() => navigate(`/admin/tickets/${ticket.id}`)}
                    >
                      {t.details}
                    </button>
                  </div>
                ))}
              </>
            )}

            {section === "stats" && (
              <>
                <h2>{t.stats}</h2>

                <div className="stats-card">
                  <strong>{t.totalProblems} :</strong> {tickets.length}
                  <br />
                  <strong>{t.concernedRooms} :</strong>{" "}
                  {new Set(tickets.map((ticket) => ticket.salle)).size}
                </div>

                <h3>{t.problemsByRoom}</h3>

                <div className="chart-card">
                  <Bar data={chartSalleData} />
                </div>

                <h3>{t.problemsByType}</h3>

                <div className="chart-card">
                  <Doughnut data={chartTypeData} />
                </div>
              </>
            )}

            {section === "about" && (
              <>
                <h2>{t.about}</h2>
                <div className="pc-info-card">{t.aboutText}</div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function TicketDetailPage({ language }) {
  const { ticketId } = useParams();
  const navigate = useNavigate();
  const [ticket, setTicket] = useState(null);

  const text = {
    fr: {
      loading: "Chargement...",
      back: "Retour",
      title: "Détail de la panne",
      pc: "PC",
      room: "Salle",
      computer: "Poste",
      email: "Email",
      problem: "Panne",
      description: "Description",
      noDescription: "Aucune",
      date: "Date",
    },
    en: {
      loading: "Loading...",
      back: "Back",
      title: "Problem details",
      pc: "PC",
      room: "Room",
      computer: "Workstation",
      email: "Email",
      problem: "Problem",
      description: "Description",
      noDescription: "None",
      date: "Date",
    },
  };

  const t = text[language] || text.fr;

  useEffect(() => {
    const adminPassword = sessionStorage.getItem("adminPassword");

    if (!adminPassword) {
      navigate("/admin/login");
      return;
    }

    fetch(`${API_URL}/admin/tickets`, {
      headers: {
        "x-admin-password": adminPassword,
      },
    })
      .then((res) => {
        if (!res.ok) {
          sessionStorage.removeItem("adminPassword");
          navigate("/admin/login");
          return [];
        }

        return res.json();
      })
      .then((data) => {
        const selectedTicket = data.find(
          (item) => String(item.id) === String(ticketId)
        );

        setTicket(selectedTicket);
      });
  }, [navigate, ticketId]);

  if (!ticket) {
    return <p>{t.loading}</p>;
  }

  return (
    <div className="report-page">
      <div className="report-card">
        <button
          className="secondary-btn full-btn"
          onClick={() => navigate("/admin")}
        >
          {t.back}
        </button>

        <h1>{t.title}</h1>

        <div className="pc-info-card">
          <strong>{t.pc} :</strong> {ticket.pc_id}
          <br />
          <strong>{t.room} :</strong> {ticket.salle}
          <br />
          <strong>{t.computer} :</strong> {ticket.poste}
          <br />
          <strong>{t.email} :</strong> {ticket.email}
          <br />
          <strong>{t.problem} :</strong>{" "}
          {ticket.type_panne === "Autre" ? ticket.custom_panne : ticket.type_panne}
          <br />
          <strong>{t.description} :</strong> {ticket.description || t.noDescription}
          <br />
          <strong>{t.date} :</strong> {ticket.created_at}
        </div>
      </div>
    </div>
  );
}

function App() {
  const [language, setLanguage] = useState(
    localStorage.getItem("language") || "fr"
  );

  const [darkMode, setDarkMode] = useState(
    localStorage.getItem("darkMode") === "true"
  );

  useEffect(() => {
    document.body.className = darkMode ? "dark" : "light";
    localStorage.setItem("darkMode", darkMode);
  }, [darkMode]);

  const changeLanguage = () => {
    const newLang = language === "fr" ? "en" : "fr";
    setLanguage(newLang);
    localStorage.setItem("language", newLang);
  };

  return (
    <Routes>
      <Route
        path="/"
        element={
          <HomePage
            language={language}
            changeLanguage={changeLanguage}
            darkMode={darkMode}
            setDarkMode={setDarkMode}
          />
        }
      />

      <Route path="/report/:id" element={<ReportPage language={language} />} />
      <Route path="/qr/:id" element={<QRPage />} />
      <Route path="/admin" element={<AdminPage language={language} />} />
      <Route path="/admin/login" element={<AdminLoginPage language={language} />} />
      <Route path="/admin/tickets/:ticketId" element={<TicketDetailPage language={language} />} />
    </Routes>
  );
}

export default App;