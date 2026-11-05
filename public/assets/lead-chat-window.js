'use strict';
(() => {
  if (window.self !== window.top) {
    window.addEventListener('message', event => {
      if (event.origin !== location.origin || event.source !== window.parent || event.data?.type !== 'sf:lead-chat:visibility') return;
      document.documentElement.dataset.chatPaused = event.data.visible === true ? 'false' : 'true';
      document.dispatchEvent(new Event('lead-chat:visibility'));
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !event.defaultPrevented) {
        event.preventDefault();
        window.parent.postMessage({ type: 'sf:lead-chat:close' }, location.origin);
      }
    });
  }
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-confirm]');
    if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
  });
})();
