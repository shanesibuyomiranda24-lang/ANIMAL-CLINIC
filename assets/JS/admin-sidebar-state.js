(() => {
  const root = document.documentElement;
  const currentScript = document.currentScript;
  const requestedRole = currentScript && currentScript.getAttribute('data-role');
  const role = requestedRole === 'client' || requestedRole === 'vet'
    ? requestedRole
    : 'admin';
  const mobile = window.matchMedia('(max-width:760px)');
  const roleName = role.charAt(0).toUpperCase() + role.slice(1);
  const widthKey = `animalClinic${roleName}SidebarWidth`;
  const collapsedKey = `animalClinic${roleName}SidebarCollapsed`;
  const collapsedClass = `${role}-sidebar-collapsed`;
  const storedWidth = parseInt(localStorage.getItem(widthKey) || '224', 10);
  const width = Number.isFinite(storedWidth) && storedWidth >= 150
    ? Math.min(360, storedWidth)
    : 224;

  root.style.setProperty('--admin-sidebar-width', `${width}px`);
  if (!mobile.matches && localStorage.getItem(collapsedKey) === '1') {
    root.classList.add(collapsedClass);
  }
})();
