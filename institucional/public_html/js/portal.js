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

// Cadastro em etapas: pets primeiro, depois tutor e, havendo plano, a tela de benefícios. Sem JS, todas as etapas aparecem.
(() => {
  'use strict';
  const form = document.querySelector('[data-wizard]');
  if (!form) return;
  const steps = [...form.querySelectorAll('[data-step]')], progress = [...form.querySelectorAll('.wiz-progress li')];
  const petsBox = form.querySelector('[data-pets]'), template = document.getElementById('wiz-pet-template');
  const addButton = form.querySelector('[data-add-pet]'), MAX_PETS = 5;
  let current = 0, nextIndex = petsBox.querySelectorAll('[data-pet]').length;
  const money = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
  form.classList.add('wiz-on');
  form.querySelectorAll('[data-next],[data-back],[data-add-pet]').forEach(button => { button.hidden = false; });

  const pets = () => [...petsBox.querySelectorAll('[data-pet]')];
  const refreshPets = () => {
    const list = pets();
    list.forEach((pet, i) => {
      const n = pet.querySelector('[data-pet-n]'), remove = pet.querySelector('[data-remove-pet]');
      if (n) n.textContent = i + 1;
      if (remove) remove.hidden = list.length < 2;
    });
    if (addButton) addButton.hidden = list.length >= MAX_PETS;
  };
  const show = (index, scroll = true) => {
    current = Math.max(0, Math.min(steps.length - 1, index));
    steps.forEach((step, i) => step.classList.toggle('is-active', i === current));
    progress.forEach((item, i) => { item.classList.toggle('is-active', i === current); item.classList.toggle('is-done', i < current); });
    form.querySelectorAll('[data-back]').forEach(button => { button.hidden = current === 0; });
    if (steps[current].querySelector('[data-pet-names]')) summary();
    if (typeof saveDraft === 'function' && KEY) saveDraft();
    if (scroll) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };
  const valid = () => {
    for (const field of steps[current].querySelectorAll('input,select,textarea')) {
      if (!field.checkValidity()) { field.closest('details')?.setAttribute('open', ''); field.reportValidity(); return false; }
    }
    const password = steps[current].querySelector('[name=password]'), confirmation = steps[current].querySelector('[name=password_confirmation]');
    if (password && confirmation && password.value !== confirmation.value) {
      confirmation.setCustomValidity('As senhas precisam ser iguais.'); confirmation.reportValidity();
      confirmation.addEventListener('input', () => confirmation.setCustomValidity(''), { once: true });
      return false;
    }
    return true;
  };
  const names = () => {
    const list = pets().map(pet => pet.querySelector('[name$="[name]"]').value.trim()).filter(Boolean);
    return list.length > 1 ? list.slice(0, -1).join(', ') + ' e ' + list.at(-1) : (list[0] || 'seu pet');
  };
  function summary() {
    const count = pets().length, price = Number(form.dataset.price) || 0;
    form.querySelector('[data-pet-names]').textContent = names();
    form.querySelector('[data-pet-count]').textContent = count === 1 ? '1 pet' : count + ' pets';
    const total = form.querySelector('[data-total]'), span = document.createElement('span');
    span.textContent = form.dataset.cycle || '';
    total.replaceChildren(document.createTextNode(money.format(price * count / 100)), span);
  }

  addButton?.addEventListener('click', () => {
    if (pets().length >= MAX_PETS) return;
    const holder = document.createElement('div');
    holder.innerHTML = template.innerHTML.replaceAll('__i__', nextIndex++);
    const pet = holder.firstElementChild;
    petsBox.append(pet); refreshPets(); pet.querySelector('input').focus();
  });
  petsBox.addEventListener('click', event => {
    const remove = event.target.closest('[data-remove-pet]');
    if (remove) { remove.closest('[data-pet]').remove(); refreshPets(); }
  });
  // Na etapa de dados: se o CPF ou e-mail já têm acesso, leva direto ao login em vez de repetir o cadastro.
  const checkAccount = async () => {
    const step = steps[current], cpf = step.querySelector('[name=document]'), email = step.querySelector('[name=email]');
    if (!cpf || !email || !form.dataset.checkUrl) return true;
    const notice = step.querySelector('[data-check-notice]') || cpf.closest('label').appendChild(Object.assign(document.createElement('small'), { role: 'alert' }));
    notice.setAttribute('data-check-notice', ''); notice.textContent = '';
    try {
      const body = new FormData(); body.set('_token', form.querySelector('[name=_token]').value); body.set('document', cpf.value); body.set('email', email.value);
      const response = await fetch(form.dataset.checkUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (data.redirect) { window.location.assign(data.redirect); return false; }
      if (data.message) { notice.textContent = data.message; cpf.focus(); return false; }
    } catch { /* sem resposta: segue, o envio final confere de novo */ }
    return true;
  };
  form.addEventListener('click', async event => {
    const next = event.target.closest('[data-next]');
    if (next) {
      if (!valid()) return;
      next.disabled = true;
      const ok = await checkAccount();
      next.disabled = false;
      if (ok) show(current + 1);
    } else if (event.target.closest('[data-back]')) show(current - 1);
  });
  // Enter dentro de uma etapa avança em vez de enviar o formulário incompleto.
  form.addEventListener('keydown', event => {
    if (event.key !== 'Enter' || current === steps.length - 1 || event.target.matches('button,summary,textarea')) return;
    event.preventDefault();
    form.querySelector('.wiz-step.is-active [data-next]')?.click();
  });
  // Se o navegador acusar campo inválido em etapa oculta, volta à etapa certa.
  form.addEventListener('invalid', event => {
    const index = steps.findIndex(step => step.contains(event.target));
    if (index >= 0 && index !== current) show(index, false);
  }, true);
  // Rascunho: guarda o que foi digitado neste aparelho (sem senhas nem aceite) para não perder tudo ao recarregar a tela.
  const KEY = 'sf-cadastro-rascunho', TTL = 24 * 60 * 60 * 1000;
  const draftFields = () => [...form.querySelectorAll('input,select,textarea')].filter(f => f.name && f.type !== 'hidden' && f.type !== 'password' && f.type !== 'checkbox');
  const saveDraft = () => {
    try {
      const values = {}; draftFields().forEach(f => { if (f.value) values[f.name] = f.value; });
      localStorage.setItem(KEY, JSON.stringify({ t: Date.now(), step: current, values }));
    } catch { /* armazenamento bloqueado: segue sem rascunho */ }
  };
  const restoreDraft = () => {
    try {
      const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (!saved || Date.now() - saved.t > TTL) { localStorage.removeItem(KEY); return 0; }
      draftFields().forEach(f => {
        if (!f.value && saved.values[f.name] !== undefined) { f.value = saved.values[f.name]; f.dispatchEvent(new Event('input', { bubbles: true })); }
      });
      // As senhas não são guardadas: quem já passou da etapa de dados volta a ela para digitá-las de novo.
      const password = form.querySelector('[name=password]');
      return password && !password.value && saved.step > 1 ? 1 : Math.min(saved.step || 0, steps.length - 1);
    } catch { return 0; }
  };
  let timer;
  form.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(saveDraft, 300); });
  form.addEventListener('change', saveDraft);
  form.addEventListener('submit', () => { try { localStorage.removeItem(KEY); } catch {} });
  refreshPets();
  const resume = restoreDraft();
  show(resume, false);
  if (resume > 0) saveDraft();
})();

// CPF com máscara 000.000.000-00 enquanto digita; o servidor continua recebendo só o que for útil e valida os dígitos.
document.querySelectorAll('input[name=document]').forEach(input => {
  const mask = value => {
    const d = value.replace(/\D/g, '').slice(0, 11);
    return d.replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1-$2');
  };
  input.setAttribute('inputmode', 'numeric'); input.setAttribute('placeholder', '000.000.000-00'); input.setAttribute('maxlength', '14');
  input.addEventListener('input', () => { input.value = mask(input.value); });
  input.value = mask(input.value);
});
