(() => {
  const body = document.body;
  const root = document.documentElement;
  const sidebar = document.getElementById('client-navigation');
  const button = document.getElementById('client-sidebar-toggle');
  if (!sidebar || !button) return;

  const mobile = window.matchMedia('(max-width:760px)');
  const widthKey = 'animalClinicClientSidebarWidth';
  const collapsedKey = 'animalClinicClientSidebarCollapsed';
  const minWidth = 150;
  const maxWidth = 360;

  function setCollapsed(collapsed) {
    body.classList.toggle('client-sidebar-collapsed', collapsed);
    root.classList.toggle('client-sidebar-collapsed', collapsed);
  }

  function applySavedWidth() {
    if (mobile.matches) return;
    const stored = parseInt(localStorage.getItem(widthKey) || '224', 10);
    const width = Number.isFinite(stored) && stored >= minWidth
      ? Math.min(maxWidth, stored)
      : 224;
    root.style.setProperty('--admin-sidebar-width', `${width}px`);
  }

  function syncState() {
    const collapsed = body.classList.contains('client-sidebar-collapsed');
    const open = body.classList.contains('client-sidebar-open');
    button.setAttribute('aria-expanded', mobile.matches ? String(open) : String(!collapsed));
  }

  applySavedWidth();
  setCollapsed(!mobile.matches && localStorage.getItem(collapsedKey) === '1');
  syncState();

  button.addEventListener('click', () => {
    if (mobile.matches) {
      body.classList.toggle('client-sidebar-open');
    } else {
      setCollapsed(!body.classList.contains('client-sidebar-collapsed'));
      localStorage.setItem(collapsedKey, body.classList.contains('client-sidebar-collapsed') ? '1' : '0');
    }
    syncState();
  });

  mobile.addEventListener('change', () => {
    body.classList.remove('client-sidebar-open');
    applySavedWidth();
    setCollapsed(!mobile.matches && localStorage.getItem(collapsedKey) === '1');
    syncState();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && mobile.matches) {
      body.classList.remove('client-sidebar-open');
      syncState();
    }
  });

  sidebar.addEventListener('click', (event) => {
    if (event.target.closest('.nav-item') && mobile.matches) {
      body.classList.remove('client-sidebar-open');
      syncState();
    }
  });

  document.addEventListener('mousedown', (event) => {
    if (mobile.matches && body.classList.contains('client-sidebar-open') &&
        !sidebar.contains(event.target) && !button.contains(event.target)) {
      body.classList.remove('client-sidebar-open');
      syncState();
    }
  });

  const resizer = document.createElement('div');
  resizer.className = 'sidebar-resizer';
  resizer.setAttribute('role', 'separator');
  resizer.setAttribute('aria-label', 'Resize navigation');
  resizer.setAttribute('aria-orientation', 'vertical');
  sidebar.appendChild(resizer);

  resizer.addEventListener('pointerdown', (event) => {
    if (mobile.matches || body.classList.contains('client-sidebar-collapsed')) return;
    event.preventDefault();
    resizer.setPointerCapture(event.pointerId);
    body.classList.add('client-resizing');
  });

  window.addEventListener('pointermove', (event) => {
    if (!body.classList.contains('client-resizing')) return;
    const width = Math.min(maxWidth, Math.max(minWidth, event.clientX));
    root.style.setProperty('--admin-sidebar-width', `${width}px`);
  });

  const stopResize = () => {
    if (!body.classList.contains('client-resizing')) return;
    body.classList.remove('client-resizing');
    const width = parseInt(getComputedStyle(root).getPropertyValue('--admin-sidebar-width'), 10);
    if (Number.isFinite(width)) localStorage.setItem(widthKey, String(width));
  };
  window.addEventListener('pointerup', stopResize);
  window.addEventListener('pointercancel', stopResize);
})();
