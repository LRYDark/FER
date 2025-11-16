function updateNavGap() {
  const nav = document.querySelector(".nav-flottante");
  if (!nav) return;

  // Mobile : petit espacement fixe
  if (window.innerWidth < 767) {
    nav.style.gap = "2rem";
  } else {
    // Desktop : on laisse le CSS gérer un gap fixe défini dans le CSS
    nav.style.gap = "";
  }
}

window.addEventListener("DOMContentLoaded", updateNavGap);
window.addEventListener("resize", updateNavGap);

document.addEventListener("DOMContentLoaded", function () {
  const btn = document.querySelector(".burger-toggle");
  const menu = document.querySelector(".menu-deroulant");

  if (btn && menu) {
    btn.innerHTML = '<span></span>'; // ligne centrale (si besoin visuel)

    btn.addEventListener("click", function () {
      menu.classList.toggle("open");
      btn.classList.toggle("open");
    });

    // Ferme le menu quand on clique sur un lien
    menu.querySelectorAll("a").forEach(link => {
      link.addEventListener("click", (event) => {
        if (
          link.classList.contains("partenaires-toggle-mobile") ||
          link.classList.contains("photos-toggle-mobile")
        ) {
          event.preventDefault();
          return;
        }
        menu.classList.remove("open");
        btn.classList.remove("open");
      });
    });
  }
});

document.addEventListener("DOMContentLoaded", function () {
  const currentUrl = window.location.href;
  const links = document.querySelectorAll(".nav-flottante a, .menu-deroulant a");

  links.forEach(link => {
    const href = link.getAttribute("href");
    if (!href || href === "#") return;

    const linkUrl = new URL(href, window.location.origin);

    // Active si l'URL actuelle contient le chemin du lien
    if (currentUrl.includes(linkUrl.pathname)) {
      link.classList.add("active");
    }
  });

  // Cas spécial : activer manuellement le lien Partenaires si on est sur partenaires.php
  if (currentUrl.includes("partenaires.php")) {
    const partenairesToggle = document.querySelector(".partenaires-toggle");
    if (partenairesToggle) {
      partenairesToggle.classList.add("active");
    }
  }

  // Même chose pour photos.php
  if (currentUrl.includes("photos.php")) {
    const photosToggle = document.querySelector(".photos-toggle");
    if (photosToggle) {
      photosToggle.classList.add("active");
    }
  }
});

function openDropdown(id) {
  // Desktop uniquement : sur mobile on laisse le comportement existant
  if (window.innerWidth < 767) return;
  const current = document.getElementById(id);
  if (!current) return;
  current.style.display = "block";
}

function closeDropdown(id) {
  if (window.innerWidth < 767) return;
  const current = document.getElementById(id);
  if (!current) return;
  current.style.display = "none";
}

function toggleMobileDropdown(event) {
  event.preventDefault();
  event.stopPropagation();

  const current = document.getElementById("dropdownMobilePartenaires");
  const other = document.getElementById("dropdownMobilePhotos");

  if (other && other.style.display === "flex") {
    other.style.display = "none";
  }

  current.style.display = current.style.display === "flex" ? "none" : "flex";
}

function toggleMobilePhotosDropdown(event) {
  event.preventDefault();
  event.stopPropagation();

  const current = document.getElementById("dropdownMobilePhotos");
  const other = document.getElementById("dropdownMobilePartenaires");

  if (other && other.style.display === "flex") {
    other.style.display = "none";
  }

  current.style.display = current.style.display === "flex" ? "none" : "flex";
}

document.addEventListener("click", function (event) {
  const partenairesDropdown = document.getElementById("dropdownPartenaires");
  const partenairesToggle = document.querySelector(".partenaires-toggle");

  const photosDropdown = document.getElementById("dropdownPhotos");
  const photosToggle = document.querySelector(".photos-toggle");

  // Fermer le menu Partenaires si on clique ailleurs
  if (
    partenairesDropdown &&
    !partenairesDropdown.contains(event.target) &&
    partenairesToggle &&
    !partenairesToggle.contains(event.target)
  ) {
    partenairesDropdown.style.display = "none";
  }

  // Fermer le menu Photos si on clique ailleurs
  if (
    photosDropdown &&
    !photosDropdown.contains(event.target) &&
    photosToggle &&
    !photosToggle.contains(event.target)
  ) {
    photosDropdown.style.display = "none";
  }
});
