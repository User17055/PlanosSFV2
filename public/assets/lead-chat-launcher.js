'use strict';
(() => {
  const launcher = document.querySelector('[data-chat-launcher]');
  const dialog = document.querySelector('[data-chat-window]');
  const frame = dialog?.querySelector('[data-chat-frame]');
  if (!launcher || !dialog || !frame || typeof dialog.showModal !== 'function') return;
  const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
  // Fecha com a animação de volta ao botão. Sem animação (ou se ela não terminar), fecha na hora.
  const hide = () => {
    if (!dialog.open || dialog.classList.contains('is-closing')) return;
    if (reduceMotion?.matches) { dialog.close(); return; }
    const done = () => {
      if (!dialog.classList.contains('is-closing')) return;
      dialog.classList.remove('is-closing');
      dialog.close();
    };
    dialog.classList.add('is-closing');
    dialog.addEventListener('animationend', event => { if (event.target === dialog) done(); });
    setTimeout(done, 400);
  };
  const notify = () => frame.contentWindow?.postMessage({ type: 'sf:lead-chat:visibility', visible: dialog.open && !document.hidden }, location.origin);
  launcher.addEventListener('click', event => {
    if (event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (!frame.hasAttribute('src')) frame.src = launcher.href;
    if (!dialog.open) dialog.showModal();
    document.documentElement.classList.add('lead-chat-open');
    launcher.setAttribute('aria-expanded', 'true');
    notify();
  });
  dialog.querySelector('[data-chat-minimize]').addEventListener('click', hide);
  // Esc na página também fecha com animação, em vez do fechamento seco do navegador.
  dialog.addEventListener('cancel', event => { event.preventDefault(); hide(); });
  dialog.addEventListener('close', () => {
    document.documentElement.classList.remove('lead-chat-open');
    launcher.setAttribute('aria-expanded', 'false');
    notify();
    launcher.focus();
  });
  frame.addEventListener('load', notify);
  document.addEventListener('visibilitychange', notify);
  window.addEventListener('message', event => {
    if (event.origin === location.origin && event.source === frame.contentWindow && event.data?.type === 'sf:lead-chat:close') hide();
  });
})();
