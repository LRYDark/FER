/* ================================================
   FER MODERN JS - Forbach en Rose
   Modern navbar and interactions for the entire site
   ================================================ */

// ===== Mega menu style Engine - centered and simple =====
(function(){
  const overlay = document.getElementById('megaOverlay');
  const items = Array.from(document.querySelectorAll('.item[data-menu]'));
  const isMobile = () => window.matchMedia('(max-width: 980px)').matches;

  let currentItem = null;
  let enterTimer = null;
  let leaveTimer = null;

  function positionMega(item){
    const trigger = item.querySelector('.trigger');
    const mega = item.querySelector('.mega');
    if(!trigger || !mega) return;

    const triggerRect = trigger.getBoundingClientRect();
    const megaRect = mega.getBoundingClientRect();
    const megaWidth = megaRect.width || mega.offsetWidth || 0;
    const center = triggerRect.left + (triggerRect.width / 2);
    const edgeGap = 24;

    let left = center;
    if(megaWidth){
      const minLeft = edgeGap + (megaWidth / 2);
      const maxLeft = window.innerWidth - edgeGap - (megaWidth / 2);
      left = Math.min(Math.max(center, minLeft), maxLeft);
    }

    mega.style.setProperty('--mega-left', `${left}px`);
  }

  function switchToMenu(newItem){
    if(currentItem === newItem) return;

    if(currentItem){
      currentItem.dataset.open = 'false';
    }

    currentItem = newItem;
    newItem.dataset.open = 'true';
    positionMega(newItem);

    if(overlay && !overlay.classList.contains('active')){
      overlay.classList.add('active');
    }
  }

  function closeAllMenus(){
    if(!currentItem) return;

    currentItem.dataset.open = 'false';
    currentItem = null;

    if(overlay) overlay.classList.remove('active');
  }

  items.forEach(item => {
    const trigger = item.querySelector('.trigger');
    const mega = item.querySelector('.mega');
    if(!trigger || !mega) return;

    item.addEventListener('mouseenter', () => {
      if(isMobile()) return;

      clearTimeout(leaveTimer);
      clearTimeout(enterTimer);

      if(currentItem){
        switchToMenu(item);
      } else {
        enterTimer = setTimeout(() => {
          switchToMenu(item);
        }, 100);
      }
    });

    item.addEventListener('mouseleave', () => {
      if(isMobile()) return;

      clearTimeout(enterTimer);
      clearTimeout(leaveTimer);
      leaveTimer = setTimeout(closeAllMenus, 200);
    });

    mega.addEventListener('mouseenter', () => {
      if(isMobile()) return;
      clearTimeout(leaveTimer);
    });

    mega.addEventListener('mouseleave', () => {
      if(isMobile()) return;
      clearTimeout(leaveTimer);
      leaveTimer = setTimeout(closeAllMenus, 200);
    });

    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      if(isMobile()) return;

      if(currentItem === item){
        closeAllMenus();
      } else {
        switchToMenu(item);
      }
    });
  });

  if(overlay){
    overlay.addEventListener('click', closeAllMenus);
  }

  document.addEventListener('click', (e) => {
    if(isMobile()) return;

    let inside = false;
    items.forEach(item => {
      if(item.contains(e.target)) inside = true;
      const mega = item.querySelector('.mega');
      if(mega && mega.contains(e.target)) inside = true;
    });

    if(!inside) closeAllMenus();
  });

  document.addEventListener('keydown', (e) => {
    if(e.key === 'Escape') closeAllMenus();
  });

  window.addEventListener('resize', closeAllMenus);
})();

// ===== MOBILE MENU SYSTEM (Vimeo slide navigation) =====
(function(){
  const mobileHeader = document.getElementById('mobileHeader');
  const mobileBottomBar = document.getElementById('mobileBottomBar');
  const mobileMenuBtn = document.getElementById('mobileMenuBtn');
  const mobileMenuBackdrop = document.getElementById('mobileMenuBackdrop');
  const mobileMenuPanel = document.getElementById('mobileMenuPanel');
  const mobileMenuClose = document.getElementById('mobileMenuClose');
  const mobileMenuBack = document.getElementById('mobileMenuBack');
  const mobileMenuTitleText = document.getElementById('mobileMenuTitleText');
  const slideMain = document.getElementById('slideMain');

  if(!mobileHeader || !mobileBottomBar) return;

  function isMobile(){ return window.matchMedia('(max-width: 980px)').matches; }

  let lastScrollY = 0;
  const scrollThreshold = 80;
  let currentSubId = null;

  function handleMobileScroll(){
    if(!isMobile()) return;
    const currentScrollY = window.scrollY;
    if(currentScrollY > scrollThreshold){
      mobileHeader.classList.add('hidden');
      mobileBottomBar.classList.add('header-hidden');
    } else {
      mobileHeader.classList.remove('hidden');
      mobileBottomBar.classList.remove('header-hidden');
    }
    lastScrollY = currentScrollY;
  }

  let ticking = false;
  window.addEventListener('scroll', () => {
    if(!ticking){
      window.requestAnimationFrame(() => {
        handleMobileScroll();
        ticking = false;
      });
      ticking = true;
    }
  });
  handleMobileScroll();

  // Go to a sub-view
  function goToSub(subId){
    const subSlide = document.getElementById('slideSub-' + subId);
    if(!subSlide) return;

    currentSubId = subId;
    slideMain.classList.add('pushed');
    subSlide.classList.add('active');

    // Update header
    mobileMenuTitleText.textContent = subSlide.dataset.title || 'Menu';
    mobileMenuBack.classList.add('visible');
  }

  // Go back to main view
  function goToMain(){
    if(!currentSubId) return;
    const subSlide = document.getElementById('slideSub-' + currentSubId);
    if(subSlide) subSlide.classList.remove('active');

    slideMain.classList.remove('pushed');
    mobileMenuTitleText.textContent = 'Menu';
    mobileMenuBack.classList.remove('visible');
    currentSubId = null;
  }

  // Open menu
  function openMobileMenu(){
    mobileBottomBar.classList.add('menu-open');
    mobileMenuBackdrop.classList.add('open');
    document.documentElement.style.overflow = 'hidden';
    document.body.style.overflow = 'hidden';
  }

  // Close menu — two-phase: 1) collapse menu, 2) then separate bar+CTA
  function closeMobileMenu(){
    // Phase 1: add "closing" (keeps CTA hidden), remove "menu-open" (collapses menu)
    mobileBottomBar.classList.add('menu-closing');
    mobileBottomBar.classList.remove('menu-open');
    mobileMenuBackdrop.classList.remove('open');
    document.documentElement.style.overflow = '';
    document.body.style.overflow = '';

    // Phase 2: after menu is fully collapsed, remove "closing" to reveal separated bar+CTA
    setTimeout(() => {
      mobileBottomBar.classList.remove('menu-closing');
      goToMain();
    }, 480);
  }

  // Menu button toggle
  if(mobileMenuBtn){
    mobileMenuBtn.addEventListener('click', () => {
      if(mobileBottomBar.classList.contains('menu-open')){
        closeMobileMenu();
      } else {
        openMobileMenu();
      }
    });
  }

  if(mobileMenuClose){
    mobileMenuClose.addEventListener('click', closeMobileMenu);
  }

  if(mobileMenuBack){
    mobileMenuBack.addEventListener('click', goToMain);
  }

  if(mobileMenuBackdrop){
    mobileMenuBackdrop.addEventListener('click', closeMobileMenu);
  }

  document.addEventListener('keydown', (e) => {
    if(e.key === 'Escape' && mobileBottomBar.classList.contains('menu-open')){
      closeMobileMenu();
    }
  });

  window.addEventListener('resize', () => {
    if(!isMobile()){
      closeMobileMenu();
      mobileHeader.classList.remove('hidden');
      mobileBottomBar.classList.remove('header-hidden');
    }
    handleMobileScroll();
  });

  // Click on menu items with sub-views
  document.querySelectorAll('.mobile-menu-item[data-sub]').forEach(item => {
    const trigger = item.querySelector('.mobile-menu-trigger');
    if(trigger){
      trigger.addEventListener('click', () => {
        goToSub(item.dataset.sub);
      });
    }
  });

  // Close menu when clicking a real link
  if(mobileMenuPanel){
    mobileMenuPanel.querySelectorAll('a[href]').forEach(link => {
      link.addEventListener('click', () => {
        setTimeout(closeMobileMenu, 100);
      });
    });
  }
})();

// ===== THEME TOGGLE (dark/light) =====
(function(){
  const STORAGE_KEY = 'fer-theme';

  function applyTheme(theme){
    if(theme === 'dark'){
      document.body.classList.add('dark-theme');
    } else {
      document.body.classList.remove('dark-theme');
    }
  }

  // Apply saved theme immediately
  const saved = localStorage.getItem(STORAGE_KEY) || 'light';
  applyTheme(saved);

  function toggleTheme(){
    const isDark = document.body.classList.contains('dark-theme');
    const newTheme = isDark ? 'light' : 'dark';
    localStorage.setItem(STORAGE_KEY, newTheme);
    applyTheme(newTheme);
  }

  // Bind toggle button directly for iOS compatibility
  function bindToggle(){
    var btn = document.getElementById('themeToggle');
    if(btn && !btn._ferBound){
      btn._ferBound = true;
      btn.addEventListener('click', function(e){ e.stopPropagation(); e.preventDefault(); toggleTheme(); });
    }
  }

  // Try immediately + on DOMContentLoaded + delegation fallback
  bindToggle();
  document.addEventListener('DOMContentLoaded', bindToggle);
  document.addEventListener('click', function(e){
    if(!e.target.closest) return;
    var btn = e.target.closest('#themeToggle');
    if(!btn) return;
    toggleTheme();
  });
})();

// ===== Gestion nav fixe -> flottante au scroll =====
(function(){
  let lastScroll = 0;
  const scrollThreshold = 50; // Pixel de scroll avant transition
  const isMobile = () => window.matchMedia('(max-width: 980px)').matches;

  function handleNavScroll() {
    if (isMobile()) {
      document.body.classList.remove('nav-scrolled');
      return;
    }
    const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
    if (currentScroll > scrollThreshold) {
      document.body.classList.add('nav-scrolled');
    } else {
      document.body.classList.remove('nav-scrolled');
    }

    lastScroll = currentScroll;
  }

  // Écouter le scroll avec throttle pour performance
  let ticking = false;
  window.addEventListener('scroll', function() {
    if (!ticking) {
      window.requestAnimationFrame(function() {
        handleNavScroll();
        ticking = false;
      });
      ticking = true;
    }
  });

  // Vérifier au chargement
  handleNavScroll();

  window.addEventListener('resize', handleNavScroll);
})();
