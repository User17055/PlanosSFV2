'use strict';
/* Casca do tema claro (mint.css): menu lateral recolhível no desktop, topo que escurece ao rolar e menu do usuário.
   No celular, o mesmo botão continua abrindo o menu pelo app.js. */
(() => {
  const root = document.documentElement;
  const desktop = matchMedia('(min-width: 1025px)');
  const storageKey = 'sf-sidebar-collapsed';
  const navLinks = [...document.querySelectorAll('.sidebar .nav-link')];
  const toggles = [...document.querySelectorAll('[data-sidebar-toggle]')];
  const sidebar = document.querySelector('#sidebar'), overlay = document.querySelector('.sidebar-overlay');
  const closeSidebar = () => { sidebar?.classList.remove('is-open'); overlay?.classList.remove('is-open'); syncSidebar(); };

  // Menu recolhido mostra só os ícones; o nome volta como dica ao passar o mouse.
  const syncSidebar = () => {
    const mini = desktop.matches && root.classList.contains('sidebar-collapsed');
    navLinks.forEach(link => {
      const label = link.querySelector('span')?.textContent.trim();
      if (mini && label) link.title = label + (link.classList.contains('active') ? ' — você está aqui' : ''); else link.removeAttribute('title');
      if(link.classList.contains('active'))link.setAttribute('aria-current','page');
    });
    toggles.forEach(button => {
      button.setAttribute('aria-label', desktop.matches ? (mini ? 'Expandir menu' : 'Recolher menu') : (sidebar?.classList.contains('is-open') ? 'Fechar menu' : 'Abrir menu'));
      button.setAttribute('aria-expanded', String(desktop.matches ? !mini : !!sidebar?.classList.contains('is-open')));
    });
  };

  // Captura no document: no desktop o clique não chega ao listener de celular do app.js.
  document.addEventListener('click', event => {
    if(event.target.closest('[data-sidebar-close]')) { closeSidebar(); return; }
    if (!event.target.closest('[data-sidebar-toggle]')) return;
    if(desktop.matches) {
      const collapsed = root.classList.toggle('sidebar-collapsed');
      try { localStorage.setItem(storageKey, collapsed ? '1' : '0'); } catch {}
    } else {
      const open = sidebar?.classList.toggle('is-open'); overlay?.classList.toggle('is-open',!!open);
    }
    syncSidebar();
  }, true);
  desktop.addEventListener('change', closeSidebar);
  sidebar?.addEventListener('click', event => { if(!desktop.matches && event.target.closest('.nav-link'))closeSidebar(); });
  syncSidebar();

  const topbar = document.querySelector('.topbar');
  if (topbar) {
    const onScroll = () => topbar.classList.toggle('is-scrolled', scrollY > 8);
    addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  const menus = [...document.querySelectorAll('details.user-menu')];
  document.addEventListener('click', event => menus.forEach(menu => { if (menu.open && !menu.contains(event.target)) menu.open = false; }));
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    closeSidebar();
    menus.forEach(menu => { if (menu.open) { menu.open = false; menu.querySelector('summary')?.focus(); } });
  });
  // "/" leva direto para a busca, como no campo do topo mostra.
  document.addEventListener('keydown', event => {
    const el = document.activeElement;
    if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || /^(INPUT|TEXTAREA|SELECT)$/.test(el?.tagName) || el?.isContentEditable) return;
    const search = document.querySelector('.topbar-search input');
    if (search) { event.preventDefault(); search.focus(); }
  });
})();
