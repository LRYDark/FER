<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Accueil - Meeting Forbach</title>
  <link rel="stylesheet" href="../css/forbach-style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <style>
    :root {
      --rose-600: #e83e8c;
      --rose-500: #f672a6;
    }

    /* NAVIGATION intégrée sous la barre .hero */
    .nav-flottante {
      background: white;
      border-radius: 3rem;
      padding: 0.8rem 3rem;
      margin: -1.8rem auto 2rem;
      width: calc(100% - 100px);
      max-width: 1000px;
      display: flex;
      justify-content: center;
      gap: 8rem;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
      z-index: 10;
      position: relative;
    }

    .nav-flottante a {
      color: var(--rose-600);
      font-weight: 700;
      text-decoration: none;
      font-size: 1.15rem;
    }

    .nav-flottante a:hover {
      text-decoration: underline;
    }

    @media (max-width: 575.98px) {
      .nav-flottante {
        flex-wrap: wrap;
        gap: 1.5rem;
        padding: 0.8rem 1.2rem;
        width: calc(100% - 40px);
        max-width: 100%;
        margin: -1.5rem auto 1rem;
      }

      .nav-flottante a {
        font-size: 1rem;
      }
    }

    /* Section image + compte à rebours avec superposition */
    .hero-accueil {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 5rem 2rem 2rem;
      position: relative;
      flex-wrap: wrap;
    }

    .image-container {
      position: relative;
      display: inline-block;
      margin: 2rem;
    }

    .image-container img {
      max-width: 100%;
      border-radius: 1rem;
    }

    /* Nouveau style compteur en blocs */
    .countdown-group {
      position: absolute;
      top: 50%;
      left: 100%;
      transform: translate(-20%, -50%);
      display: flex;
      gap: 1rem;
      z-index: 5;
      flex-wrap: wrap;
      justify-content: center;
    }

    .bloc {
      background-color: #1d2344;
      color: white;
      border-radius: 2rem;
      padding: 1rem 1.2rem;
      text-align: center;
      min-width: 80px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    .valeur {
      font-size: 1.8rem;
      font-weight: bold;
    }

    .label {
      font-size: 0.85rem;
      margin-top: 0.3rem;
    }

    /* Responsive pour mobile */
    @media (max-width: 768px) {
      .countdown-group {
        position: relative;
        transform: none;
        left: 0;
        top: 1rem;
        margin-top: 1rem;
      }

      .bloc {
        min-width: 65px;
        padding: 0.8rem;
        font-size: 1rem;
      }

      .valeur {
        font-size: 1.3rem;
      }
    }

    /* Compteur d'inscrits */
    .inscrits {
      text-align: center;
      padding: 2rem;
      font-size: 1.5rem;
      font-weight: 600;
      color: #222;
    }

    /* Section bouton vers l’inscription */
    .section-inscription {
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 3rem 1rem;
      text-align: center;
    }

    .section-inscription .btn-inscription {
      background: var(--rose-600);
      color: white;
      padding: 0.8rem 2rem;
      font-size: 1.2rem;
      border-radius: 2rem;
      border: none;
    }

    .section-inscription .btn-inscription:hover {
      background: #c13778;
    }

    /* Partenaires */
    .partenaires {
      text-align: center;
      padding: 3rem 1rem;
    }

    .partenaires img {
      max-width: 100%;
      border-radius: 1rem;
      box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }

    /* Footer */
    footer {
      background: var(--rose-600);
      color: white;
      text-align: center;
      padding: 1rem;
      margin-top: 3rem;
    }

    footer a {
      color: white;
      margin: 0 0.5rem;
      text-decoration: underline;
    }
  </style>
</head>
<body>

  <!-- Barre HERO en haut -->
  <section class="hero">
    <img src="logo.png" alt="Logo" class="logo-top">
    <div class="hero-inner">
      <h1>Forbach en rose</h1>
      <span class="badge-donation">Édition 2026</span>
    </div>
  </section>

  <!-- Navigation -->
  <nav class="nav-flottante">
    <a href="accueil.php">Accueil</a>
    <a href="#parcours">Parcours</a>
    <a href="#partenaire">Partenaire</a>
    <a href="#photos">Photos</a>
  </nav>

  <!-- Section image + compte à rebours -->
  <section class="hero-accueil">
    <div class="image-container">
      <img src="../files/_pictures/1200x680_vignette-forbach.jpg" alt="Forbach en rose">
      <div class="countdown-group" id="countdown"></div>
    </div>
  </section>

  <!-- Compteur d'inscrits -->
  <div class="inscrits">
    Déjà <strong><span id="nb-inscrits">245</span></strong> inscrits !
  </div>

  <!-- Lien vers la page d'inscription -->
  <section class="section-inscription">
    <div>
      <h2>Rejoignez-nous !</h2>
      <p>Inscrivez-vous dès maintenant pour participer à l'événement</p>
      <a href="register.php" class="btn btn-inscription">Je m'inscris</a>
    </div>
  </section>

  <!-- Section partenaires -->
  <section class="partenaires" id="partenaire">
    <h2>Nos Partenaires</h2>
    <p>Merci à tous nos sponsors et soutiens</p>
    <img src="partenaires.jpg" alt="Logos des partenaires">
  </section>

  <!-- Footer -->
  <footer>
    Suivez-nous sur :
    <a href="https://facebook.com" target="_blank">Facebook</a> |
    <a href="https://instagram.com" target="_blank">Instagram</a>
  </footer>

  <!-- Script compte à rebours -->
  <script>
    const countdown = document.getElementById('countdown');
    const targetDate = new Date("2026-07-05T00:00:00").getTime();

    function updateCountdown() {
      const now = new Date().getTime();
      const distance = targetDate - now;

      if (distance <= 0) {
        countdown.innerHTML = `<div class="bloc"><div class="valeur">C'est</div><div class="label">le jour J !</div></div>`;
        return;
      }

      const days = Math.floor(distance / (1000 * 60 * 60 * 24));
      const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
      const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
      const seconds = Math.floor((distance % (1000 * 60)) / 1000);

      countdown.innerHTML = `
        <div class="bloc"><div class="valeur">${days}</div><div class="label">Jours</div></div>
        <div class="bloc"><div class="valeur">${hours}</div><div class="label">Heures</div></div>
        <div class="bloc"><div class="valeur">${minutes}</div><div class="label">Minutes</div></div>
        <div class="bloc"><div class="valeur">${seconds}</div><div class="label">Secondes</div></div>
      `;
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);
  </script>

</body>
</html>
