(() => {
  const body = document.body;
  const isAdminPage = body.classList.contains('admin-dashboard') ||
    (body.classList.contains('role-app') && body.classList.contains('admin'));
  const isVetPage = body.classList.contains('role-app') && body.classList.contains('vet');
  if (!isAdminPage && !isVetPage) return;
  const role = isVetPage ? 'vet' : 'admin';
  const roleName = role.charAt(0).toUpperCase() + role.slice(1);
  const collapsedClass = `${role}-sidebar-collapsed`;
  const openClass = `${role}-sidebar-open`;
  const resizingClass = `${role}-resizing`;

  const sidebar = document.querySelector('.sidebar');
  const topbar = document.querySelector('.topbar');
  if (!sidebar || !topbar) return;

  const mobile = window.matchMedia('(max-width:760px)');
  const widthKey = `animalClinic${roleName}SidebarWidth`;
  const collapsedKey = `animalClinic${roleName}SidebarCollapsed`;
  const minWidth = 150;
  const maxWidth = 360;

  const existingToggle = document.getElementById('sidebar-toggle');
  let button = existingToggle;
  if (!button) {
    button = document.createElement('button');
    button.type = 'button';
    button.className = 'admin-hamburger';
    button.id = 'admin-hamburger';
    button.setAttribute('aria-label', 'Toggle navigation');
    button.setAttribute('aria-expanded', 'true');
    button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';
  } else {
    button.classList.add('admin-hamburger');
    button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';
  }

  let leading = topbar.querySelector('.topbar-leading');
  if (!leading) {
    leading = document.createElement('div');
    leading.className = 'topbar-leading';
    const title = topbar.querySelector('.topbar-title');
    leading.appendChild(button);
    if (title) leading.appendChild(title);
    topbar.insertBefore(leading, topbar.firstChild);
  } else if (!leading.contains(button)) {
    leading.insertBefore(button, leading.firstChild);
  }

  if (!sidebar.querySelector('.sidebar-resizer')) {
    const resizer = document.createElement('div');
    resizer.className = 'sidebar-resizer';
    resizer.setAttribute('role', 'separator');
    resizer.setAttribute('aria-label', 'Resize navigation');
    resizer.setAttribute('aria-orientation', 'vertical');
    sidebar.appendChild(resizer);
  }

  function applySavedWidth() {
    if (mobile.matches) return;
    const stored = parseInt(localStorage.getItem(widthKey) || '224', 10);
    const width = Number.isFinite(stored) && stored >= 150
      ? Math.min(maxWidth, stored)
      : 224;
    document.documentElement.style.setProperty('--admin-sidebar-width', `${width}px`);
  }

  function setCollapsed(collapsed) {
    body.classList.toggle(collapsedClass, collapsed);
    document.documentElement.classList.toggle(collapsedClass, collapsed);
  }

  function syncState() {
    const collapsed = body.classList.contains(collapsedClass);
    const open = body.classList.contains(openClass);
    button.setAttribute('aria-expanded', mobile.matches ? String(open) : String(!collapsed));
  }

  applySavedWidth();
  if (!mobile.matches && localStorage.getItem(collapsedKey) === '1') {
    setCollapsed(true);
  }
  syncState();

  button.addEventListener('click', () => {
    if (mobile.matches) {
      body.classList.toggle(openClass);
    } else {
      setCollapsed(!body.classList.contains(collapsedClass));
      localStorage.setItem(collapsedKey, body.classList.contains(collapsedClass) ? '1' : '0');
    }
    syncState();
  });

  mobile.addEventListener('change', () => {
    body.classList.remove(openClass);
    if (mobile.matches) {
      setCollapsed(false);
      document.documentElement.style.removeProperty('--admin-sidebar-width');
    } else {
      applySavedWidth();
      setCollapsed(localStorage.getItem(collapsedKey) === '1');
    }
    syncState();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && mobile.matches) {
      body.classList.remove(openClass);
      syncState();
    }
  });

  sidebar.addEventListener('click', (event) => {
    if (!event.target.closest('.nav-item')) return;
    if (mobile.matches) {
      body.classList.remove(openClass);
      syncState();
    }
  });

  document.addEventListener('mousedown', (event) => {
    if (mobile.matches && body.classList.contains(openClass) &&
        !sidebar.contains(event.target) && !button.contains(event.target)) {
      body.classList.remove(openClass);
      syncState();
    }
  });

  const resizer = sidebar.querySelector('.sidebar-resizer');
  if (resizer) {
    resizer.addEventListener('pointerdown', (event) => {
      if (mobile.matches || body.classList.contains(collapsedClass)) return;
      event.preventDefault();
      resizer.setPointerCapture(event.pointerId);
      body.classList.add(resizingClass);
    });

    window.addEventListener('pointermove', (event) => {
      if (!body.classList.contains(resizingClass)) return;
      const width = Math.min(maxWidth, Math.max(minWidth, event.clientX));
      document.documentElement.style.setProperty('--admin-sidebar-width', `${width}px`);
    });

    const stopResize = (event) => {
      if (!body.classList.contains(resizingClass)) return;
      body.classList.remove(resizingClass);
      try { resizer.releasePointerCapture(event.pointerId); } catch (_) {}
      const width = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--admin-sidebar-width'), 10);
      if (Number.isFinite(width) && !body.classList.contains(collapsedClass)) {
        localStorage.setItem(widthKey, String(width));
      } else {
        applySavedWidth();
      }
      localStorage.setItem(collapsedKey, body.classList.contains(collapsedClass) ? '1' : '0');
    };
    window.addEventListener('pointerup', stopResize);
    window.addEventListener('pointercancel', stopResize);
  }

  document.querySelectorAll('input[type="search"]').forEach((input) => {
    if (input.closest('.admin-search-wrap')) return;
    let label = input.closest('label');
    if (!label) label = input.parentElement;
    if (!label) return;
    const wrapper = document.createElement('div');
    wrapper.className = 'admin-search-wrap';
    label.insertBefore(wrapper, input);
    wrapper.appendChild(input);
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'admin-search-clear';
    clear.setAttribute('aria-label', 'Clear search');
    clear.innerHTML = '<span aria-hidden="true">×</span>';
    wrapper.appendChild(clear);

    const update = () => wrapper.classList.toggle('has-value', input.value !== '');
    input.addEventListener('input', () => {
      wrapper.classList.add('is-searching');
      update();
      window.clearTimeout(input._adminSearchTimer);
      input._adminSearchTimer = window.setTimeout(() => {
        wrapper.classList.remove('is-searching');
      }, 180);
    });
    clear.addEventListener('click', () => {
      input.value = '';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.focus();
      update();
    });
    update();
  });
})();
