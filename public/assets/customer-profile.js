(() => {
  const root = document.querySelector('[data-customer-profile]');
  if (!root) return;
  const panels = [...root.querySelectorAll('[data-profile-panel]')];
  const tabs = [...root.querySelectorAll('[data-profile-tab]')];
  const nav = root.querySelector('[data-profile-tabs]');
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let active = null, lastHash = null;
  const revealParents = target => {
    for (let node = target; node && node !== root; node = node.parentElement) if (node.tagName === 'DETAILS') node.open = true;
  };
  function showPanel(panel, animate = true) {
    if (!panel) return;
    const changed = active !== panel;
    active = panel;
    panels.forEach(item => { item.hidden = item !== panel; });
    tabs.forEach(tab => {
      const selected = tab.dataset.profileTab === panel.dataset.profilePanel;
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
    });
    if (changed && window.gsap && !reduced && animate) {
      window.gsap.killTweensOf(panel);
      window.gsap.fromTo(panel, { opacity: .6, y: 6 }, { opacity: 1, y: 0, duration: .22, ease: 'power2.out', clearProps: 'opacity,transform' });
    }
  }
  function navigate(hash, { update = false, scroll = true, focus = false, animate = true } = {}) {
    let id;
    try { id = decodeURIComponent((hash || '').replace(/^#/, '')); } catch { id = ''; }
    const target = id ? document.getElementById(id) : null;
    const local = target && root.contains(target) ? target : null;
    const panel = local?.closest('[data-profile-panel]') || panels.find(item => item.dataset.profilePanel === root.dataset.profileInitial) || panels[0];
    showPanel(panel, animate);
    if (local) revealParents(local);
    if (update && hash !== location.hash) history.pushState(null, '', hash);
    lastHash = location.hash;
    if (scroll && local) requestAnimationFrame(() => {
      local.scrollIntoView({ block: 'start', behavior: 'auto' });
      if (focus) {
        const control = local.tagName === 'DETAILS' ? local.querySelector('summary') : (local.matches('[data-profile-panel]') ? tabs.find(tab => tab.dataset.profileTab === panel.dataset.profilePanel) : local);
        if(control===local)control.tabIndex=-1;
        control?.focus({ preventScroll: true });
      }
    });
  }
  nav?.setAttribute('role', 'tablist');
  panels.forEach(panel => {
    panel.setAttribute('role', 'tabpanel');
    panel.setAttribute('aria-labelledby', `profile-tab-${panel.dataset.profilePanel}`);
  });
  tabs.forEach(tab => {
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-controls', tab.dataset.profileTab);
    tab.addEventListener('keydown', event => {
      const index = tabs.indexOf(tab);
      const next = event.key === 'ArrowRight' ? (index + 1) % tabs.length : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : null;
      if (next === null) return;
      event.preventDefault();
      navigate(tabs[next].getAttribute('href'), { update: true, focus: true });
    });
  });
  root.addEventListener('click', event => {
    const link = event.target.closest('a[href^="#"]');
    if (!link || !root.contains(link) || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || (event.button !== undefined && event.button !== 0)) return;
    const hash = link.getAttribute('href');
    let target;
    try { target = document.getElementById(decodeURIComponent(hash.slice(1))); } catch { return; }
    if (!target || !root.contains(target)) return;
    event.preventDefault();
    navigate(hash, { update: true, focus: true });
  });
  const syncLocation = () => { if (lastHash !== location.hash) navigate(location.hash); };
  window.addEventListener('hashchange', syncLocation);
  window.addEventListener('popstate', syncLocation);
  const error = root.dataset.profileErrors === 'true';
  navigate(error ? `#${root.dataset.profileInitial}` : location.hash, { scroll: !error && !!location.hash, animate: false });
  const reopen = root.querySelector('[data-profile-reopen]');
  if (reopen) { showPanel(reopen.closest('[data-profile-panel]'), false); reopen.showModal(); }
  else if (error) requestAnimationFrame(() => {
    const alert = active?.querySelector('[role="alert"]');
    if (alert) { revealParents(alert); alert.tabIndex = -1; alert.scrollIntoView({ block: 'center' }); alert.focus({ preventScroll: true }); }
  });
  window.addEventListener('beforeprint', () => panels.forEach(panel => { panel.hidden = false; }));
  window.addEventListener('afterprint', () => showPanel(active, false));
})();
