(() => {
  'use strict';
  document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', () => {
      if (form.matches('[data-contract-generation]')) return;
      const button = form.querySelector('button[data-busy]');
      if (button) { button.disabled = true; button.textContent = button.dataset.busy; }
    });
  });
  // Long lists render complete and only collapse once JS runs, so nothing is hidden without it.
  document.querySelectorAll('[data-ver-mais]').forEach(button => {
    const target = document.getElementById(button.getAttribute('aria-controls'));
    if (!target) return;
    const toggle = open => {
      target.classList.toggle('is-recolhida', !open);
      button.setAttribute('aria-expanded', String(open));
      button.textContent = open ? button.dataset.verMenos : button.dataset.verMais;
    };
    toggle(false);
    button.hidden = false;
    button.addEventListener('click', () => toggle(button.getAttribute('aria-expanded') !== 'true'));
  });
  window.addEventListener('pageshow', event => {
    if (event.persisted) window.location.reload();
  });
  const postal = document.querySelector('[data-postal-url]');
  if (postal) {
    const input = postal.elements.namedItem('zip'), status = postal.querySelector('[data-postal-status]');
    let controller;
    input.addEventListener('blur', async () => {
      const zip = input.value.replace(/\D/g, '');
      if (zip.length !== 8) return;
      controller?.abort(); controller = new AbortController();
      const typed = Object.fromEntries(['street', 'neighborhood', 'city', 'state'].map(name => [name, postal.elements.namedItem(name).value]));
      status.textContent = 'Buscando o endereço...';
      try {
        const response = await fetch(postal.dataset.postalUrl.replace('00000000', zip), { headers: { Accept: 'application/json' }, signal: controller.signal });
        if (!response.ok) throw new Error('Postal lookup unavailable');
        const data = await response.json();
        if (input.value.replace(/\D/g, '') !== zip) return;
        ['street', 'neighborhood', 'city', 'state'].forEach(name => {
          const field = postal.elements.namedItem(name);
          if (!typed[name] && field.value === typed[name] && data[name]) field.value = data[name];
        });
        status.textContent = 'Confira o endereço e preencha o número.';
      } catch (error) {
        if (error.name !== 'AbortError') status.textContent = 'Preencha o endereço manualmente para continuar.';
      }
    });
  }
  document.querySelectorAll('[data-device-info]').forEach(input => {
    const fill = () => {
      const connection = navigator.connection || {}, hints = navigator.userAgentData;
      let timezone = ''; try { timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch {}
      input.value = JSON.stringify({ screen: `${screen.width}x${screen.height}`, viewport: `${window.innerWidth}x${window.innerHeight}`, pixel_ratio: window.devicePixelRatio || 1,
        platform: hints?.platform || navigator.platform || '', mobile: hints ? hints.mobile : /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent),
        language: navigator.language || '', languages: (navigator.languages || []).join(', '), timezone, timezone_offset: -new Date().getTimezoneOffset(),
        touch_points: navigator.maxTouchPoints || 0, cores: navigator.hardwareConcurrency || null, memory_gb: navigator.deviceMemory || null,
        connection: connection.effectiveType || '', downlink_mbps: connection.downlink ?? null, captured_at: new Date().toISOString() });
    };
    fill(); input.form?.addEventListener('submit', fill);
  });
  document.querySelectorAll('[data-signature-form]').forEach(form => {
    const canvas = form.querySelector('[data-signature-canvas]'), hidden = form.querySelector('[data-signature-value]'), error = form.querySelector('[data-signature-error]');
    const context = canvas?.getContext('2d'); if (!context) return;
    let strokes = [], current = null, distance = 0, size = null;
    const point = event => { const bounds = canvas.getBoundingClientRect(); return { x: (event.clientX - bounds.left) / bounds.width, y: (event.clientY - bounds.top) / bounds.height }; };
    const paint = () => {
      if (!size) return;
      context.clearRect(0, 0, size.width, size.height);
      strokes.filter(stroke => stroke.length).forEach(stroke => { context.beginPath(); context.moveTo(stroke[0].x * size.width, stroke[0].y * size.height); stroke.slice(1).forEach(p => context.lineTo(p.x * size.width, p.y * size.height)); context.stroke(); });
    };
    const resize = () => {
      const bounds = canvas.getBoundingClientRect(); if (!bounds.width || !bounds.height || (size && size.width === bounds.width && size.height === bounds.height)) return;
      size = { width: bounds.width, height: bounds.height };
      const ratio = Math.min(window.devicePixelRatio || 1, 2);
      canvas.width = Math.round(Math.min(2000, bounds.width * ratio)); canvas.height = Math.round(Math.min(1000, bounds.height * ratio));
      context.setTransform(canvas.width / bounds.width, 0, 0, canvas.height / bounds.height, 0, 0);
      context.strokeStyle = '#0b5988'; context.lineWidth = 2.2; context.lineCap = 'round'; context.lineJoin = 'round'; paint();
    };
    const stop = () => { current = null; hidden.value = distance >= 12 ? canvas.toDataURL('image/png') : ''; if (hidden.value && error) error.hidden = true; };
    canvas.addEventListener('pointerdown', event => { if (event.pointerType === 'mouse' && event.button !== 0) return; event.preventDefault(); canvas.setPointerCapture(event.pointerId); current = [point(event)]; strokes.push(current); });
    canvas.addEventListener('pointermove', event => { if (!current || !size) return; event.preventDefault(); const next = point(event), previous = current[current.length - 1]; distance += Math.hypot((next.x - previous.x) * size.width, (next.y - previous.y) * size.height); current.push(next); paint(); });
    canvas.addEventListener('pointerup', stop); canvas.addEventListener('pointercancel', stop);
    form.querySelector('[data-clear-signature]')?.addEventListener('click', () => { strokes = []; current = null; distance = 0; paint(); hidden.value = ''; });
    // Capture phase runs before the busy-button handler, so a blocked submit keeps the button usable.
    form.addEventListener('submit', event => {
      stop();
      if (hidden.value) return;
      event.preventDefault(); event.stopImmediatePropagation();
      if (error) error.hidden = false;
      canvas.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }, true);
    resize(); window.addEventListener('resize', resize);
    if (window.ResizeObserver) new ResizeObserver(resize).observe(canvas);
  });
  document.querySelectorAll('form[data-auto-submit]').forEach(form => setTimeout(() => form.requestSubmit ? form.requestSubmit() : form.submit(), 1500));
  const generation = document.querySelector('[data-contract-generation]');
  if (generation) {
    const button = generation.querySelector('button'), status = generation.querySelector('[data-generation-status]');
    let busy = false, attempts = 0;
    async function generate() {
      if (busy) return;
      busy = true; button.disabled = true; attempts++;
      status.textContent = 'Preparando seu PDF. Sua assinatura já está registrada...';
      const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 135000);
      try {
        const response = await fetch(generation.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(generation), signal: controller.signal, credentials: 'same-origin' });
        const result = await response.json();
        if (response.ok && result.ready) { window.location.reload(); return; }
        status.textContent = result.message || 'O PDF ainda está em preparação. Tente novamente em instantes.';
        if (response.status === 503 && attempts < 3) setTimeout(generate, attempts * 5000);
      } catch {
        status.textContent = 'Sua assinatura permanece salva. Tente preparar o PDF novamente em instantes.';
      } finally { clearTimeout(timeout); busy = false; button.disabled = false; }
    }
    generation.addEventListener('submit', event => { event.preventDefault(); attempts = 0; generate(); });
    setTimeout(generate, 1000);
  }
})();
