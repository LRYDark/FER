    document.addEventListener("DOMContentLoaded", function () {
      const nav = document.querySelector(".nav-flottante");

      // Fonction utilitaire : vérifier si un élément est visible (display ≠ none)
      function isVisible(el) {
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
      }

      // Ne compte que les <a> visibles
      const links = Array.from(nav.querySelectorAll("a")).filter(isVisible);
      const nbElements = links.length;

      const gapValue = 11 - nbElements;
      nav.style.gap = `${gapValue}rem`;
    });

    function updateNavGap() {
      const nav = document.querySelector(".nav-flottante");

      // Désactiver si mobile
      if (window.innerWidth < 767) {
        nav.style.gap = "2rem";
        return;
      }

      // Liens visibles
      const links = Array.from(nav.querySelectorAll('a')).filter(el => {
        const style = window.getComputedStyle(el);
        return style.display !== "none" && style.visibility !== "hidden";
      });

      // Largeur totale disponible
      const containerWidth = nav.clientWidth;

      // Largeur totale utilisée par les liens
      const totalLinksWidth = links.reduce((sum, link) => {
        const rect = link.getBoundingClientRect();
        return sum + rect.width;
      }, 0);

      const nbGaps = links.length - 1;
      const maxAllowedGap = (containerWidth - totalLinksWidth) / (nbGaps || 1);

      // Fixe entre 1rem et 7rem max pour éviter les excès
      const gapRem = Math.min(Math.max(1, maxAllowedGap / 16), 7);
      nav.style.gap = `${gapRem.toFixed(2)}rem`;
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
      const path = window.location.pathname.split("/").pop(); // ex: accueil.php
      const links = document.querySelectorAll(".nav-flottante a, .menu-deroulant a");

      links.forEach(link => {
        if (link.getAttribute("href") === path) {
          link.classList.add("active");
        }
      });
    });












function toggleDropdown(event) {
  event.preventDefault();

  const current = document.getElementById('dropdownPartenaires');
  const other = document.getElementById('dropdownPhotos');

  // Ferme l'autre menu si ouvert
  if (other && other.style.display === 'block') {
    other.style.display = 'none';
  }

  // Bascule le menu actuel
  current.style.display = (current.style.display === 'block') ? 'none' : 'block';
}

function togglePhotosDropdown(event) {
  event.preventDefault();

  const current = document.getElementById('dropdownPhotos');
  const other = document.getElementById('dropdownPartenaires');

  if (other && other.style.display === 'block') {
    other.style.display = 'none';
  }

  current.style.display = (current.style.display === 'block') ? 'none' : 'block';
}

function toggleMobileDropdown(event) {
  event.preventDefault();
  event.stopPropagation();

  const current = document.getElementById('dropdownMobilePartenaires');
  const other = document.getElementById('dropdownMobilePhotos');

  if (other && other.style.display === 'flex') {
    other.style.display = 'none';
  }

  current.style.display = (current.style.display === 'flex') ? 'none' : 'flex';
}

function toggleMobilePhotosDropdown(event) {
  event.preventDefault();
  event.stopPropagation();

  const current = document.getElementById('dropdownMobilePhotos');
  const other = document.getElementById('dropdownMobilePartenaires');

  if (other && other.style.display === 'flex') {
    other.style.display = 'none';
  }

  current.style.display = (current.style.display === 'flex') ? 'none' : 'flex';
}

document.addEventListener('click', function(event) {
  const partenairesDropdown = document.getElementById('dropdownPartenaires');
  const partenairesToggle = document.querySelector('.partenaires-toggle');

  const photosDropdown = document.getElementById('dropdownPhotos');
  const photosToggle = document.querySelector('.photos-toggle');

  // Fermer le menu Partenaires si on clique ailleurs
  if (partenairesDropdown && !partenairesDropdown.contains(event.target) && !partenairesToggle.contains(event.target)) {
    partenairesDropdown.style.display = 'none';
  }

  // Fermer le menu Photos si on clique ailleurs
  if (photosDropdown && !photosDropdown.contains(event.target) && !photosToggle.contains(event.target)) {
    photosDropdown.style.display = 'none';
  }
});
