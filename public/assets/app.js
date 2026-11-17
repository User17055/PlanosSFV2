'use strict';
(() => {
  const $ = (selector, parent = document) => parent.querySelector(selector);
  const $$ = (selector, parent = document) => [...parent.querySelectorAll(selector)];
  const toast = (message) => { const box = $('#toast'); if (!box) return; box.textContent = message; box.classList.add('show'); clearTimeout(window.careToast); window.careToast = setTimeout(() => box.classList.remove('show'), 3200); };
  $$('[data-dismiss]').forEach(button => button.addEventListener('click', () => button.closest('.alert')?.remove()));
  $$('[data-dialog-open]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.dialogOpen)?.showModal()));
  $$('[data-dialog-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
  $$('dialog').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog) { const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dialog.close(); } }));
  document.addEventListener('click', event => { const button = event.target.closest('[data-confirm]'); if (button && !window.confirm(button.dataset.confirm)) event.preventDefault(); });
  $$('[data-copy-target]').forEach(button => button.addEventListener('click', async () => { const input = document.getElementById(button.dataset.copyTarget); if (!input) return; try { await navigator.clipboard.writeText(input.value); toast('Link copiado. Compartilhe com o tutor.'); } catch { input.select(); toast('Selecione e copie o link exibido.'); } }));
  $$('[data-toggle-password]').forEach(button => button.addEventListener('click', () => { const input = document.getElementById(button.dataset.togglePassword); if (!input) return; const show = input.type === 'password'; input.type = show ? 'text' : 'password'; button.textContent = show ? 'Ocultar' : 'Mostrar'; button.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha'); }));
  $$('[data-mask="cpf"]').forEach(input => input.addEventListener('input', () => { const v = input.value.replace(/\D/g, '').slice(0, 11); input.value = v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'); }));
  const dependentSelect = (parentSelector, childSelector, dataKey) => {
    const parent = $(parentSelector), child = $(childSelector); if (!parent || !child) return;
    const update = () => { let selectionValid = false; $$('option', child).forEach(option => { if (!option.value) return; const enabled = option.dataset[dataKey] === parent.value; option.hidden = !enabled; option.disabled = !enabled; if (enabled && option.selected) selectionValid = true; }); if (!selectionValid) child.value = ''; if (child.options[0]) child.options[0].textContent = parent.value ? 'Selecione uma opção' : 'Selecione primeiro o cadastro acima'; };
    parent.addEventListener('change', update); update();
  };
  // Busca do tutor: a lista aparece enquanto digita e o clique escolhe; o select escondido guarda a escolha para o formulário.
  const customerSearch = $('[data-customer-search]'), customerSelect = $('[data-customer-select]'), customerResults = $('[data-customer-results]');
  if (customerSearch && customerSelect && customerResults) {
    const normalize = value => value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const status = $('[data-customer-search-status]'), petSelect = $('[data-pet-select]');
    const say = text => { if (status) status.textContent = text; };
    const options = $$('option', customerSelect).filter(option => option.value);
    const choose = option => {
      customerSelect.value = option ? option.value : '';
      customerSelect.dispatchEvent(new Event('change', { bubbles: true }));
      customerSearch.setCustomValidity(option ? '' : 'Busque e clique no tutor na lista.');
      if (!option) return;
      customerSearch.value = option.dataset.name;
      customerResults.hidden = true; customerResults.replaceChildren();
      const pets = petSelect ? $$('option', petSelect).filter(pet => pet.value && !pet.disabled) : [];
      if (pets.length === 1) petSelect.value = pets[0].value;
      say(`Tutor escolhido: ${option.textContent}.` + (pets.length ? '' : ' Ele ainda não tem pet: cadastre o pet na ficha do tutor.'));
    };
    customerSearch.addEventListener('input', () => {
      if (customerSelect.value) choose(null);
      const terms = normalize(customerSearch.value).split(/\s+/).filter(Boolean);
      customerResults.replaceChildren();
      if (!terms.length) { customerResults.hidden = true; say('Digite para buscar e clique no tutor.'); return; }
      const matches = options.filter(option => {
        const text = normalize(`${option.textContent} ${option.dataset.search || ''}`), digits = text.replace(/\D/g, '');
        return terms.every(term => text.includes(term) || (/^[\d.\-()/]+$/.test(term) && term.replace(/\D/g, '') !== '' && digits.includes(term.replace(/\D/g, ''))));
      });
      for (const option of matches.slice(0, 8)) {
        const button = document.createElement('button'), name = document.createElement('strong'), detail = document.createElement('small');
        button.type = 'button'; button.className = 'customer-result';
        name.textContent = option.dataset.name; detail.textContent = option.dataset.detail || '';
        button.append(name, detail); button.addEventListener('click', () => choose(option));
        customerResults.append(button);
      }
      customerResults.hidden = !matches.length;
      say(!matches.length ? 'Nenhum tutor encontrado. Confira a busca, escolha "Tutor novo" ou cadastre o tutor.'
        : matches.length > 8 ? `${matches.length} tutores encontrados. Continue digitando para achar o certo.`
        : matches.length === 1 ? 'Clique no tutor para escolher.' : `${matches.length} tutores encontrados. Clique no certo.`);
    });
    // Enter na busca escolhe o único resultado, em vez de avançar a etapa.
    customerSearch.addEventListener('keydown', event => { if (event.key !== 'Enter') return; event.preventDefault(); if (customerResults.children.length === 1) customerResults.firstChild.click(); });
    const preset = options.find(option => option.value === customerSelect.value);
    if (preset) { customerSearch.value = preset.dataset.name; say(`Tutor escolhido: ${preset.textContent}.`); } else customerSearch.setCustomValidity('Busque e clique no tutor na lista.');
  }
  dependentSelect('[data-customer-select]', '[data-pet-select]', 'customer');
  dependentSelect('[data-subscription-select]', '[data-benefit-select]', 'subscription');
  const wizard = $('[data-wizard]');
  if (wizard) {
    let current = 0;
    const panels = $$('[data-step]', wizard), indicators = $$('[data-step-indicator]');
    const selfRegistration = () => wizard.dataset.staff === '1' && ($('[name="channel"]:checked', wizard)?.value === 'site' || ($('[name="channel"]:checked', wizard)?.value === 'link' && $('[name="prefill_by"]:checked', wizard)?.value === 'customer'));
    const updateReview = () => {
      $$('[data-review]', wizard).forEach(target => { const input = $(`[name="${target.dataset.review}"]`, wizard); target.textContent = input?.selectedOptions?.[0]?.textContent || '—'; });
      if (selfRegistration()) {
        $('[data-review="customer_id"]', wizard).textContent = 'O tutor preencherá pelo link';
        $('[data-review="pet_id"]', wizard).textContent = 'O tutor informará o pet';
      }
      const channel = $('[name="channel"]:checked', wizard)?.value;
      if ($('[data-review-channel]')) $('[data-review-channel]').textContent = ({ site: 'Site institucional', link: 'Link individual', presential: 'Presencial' })[channel] || '—';
      const product = $('[name="product_version_id"]:checked', wizard);
      if ($('[data-review-product]')) $('[data-review-product]').textContent = product?.dataset.productName || '—';
      if ($('[data-review-price]')) $('[data-review-price]').textContent = product ? `${product.dataset.productPrice} · ${product.dataset.productCycle}` : '—';
    };
    const show = index => { current = Math.max(0, Math.min(panels.length - 1, index)); panels.forEach((panel, i) => panel.hidden = i !== current); indicators.forEach((indicator, i) => { indicator.classList.toggle('active', i === current); indicator.classList.toggle('complete', i < current); }); updateReview(); };
    const validate = () => { const fields = $$('input, select, textarea', panels[current]).filter(field => !field.disabled && field.type !== 'hidden' && !field.closest('[hidden]')); for (const field of fields) { if (!field.checkValidity()) { field.reportValidity(); field.focus(); return false; } } return true; };
    $$('[data-wizard-next]', wizard).forEach(button => button.addEventListener('click', () => { if (validate()) { show(current + 1); $('.wizard-steps')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); } }));
    $$('[data-wizard-back]', wizard).forEach(button => button.addEventListener('click', () => { show(current - 1); $('.wizard-steps')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }));
    const updateChannels = () => { const value = $('[name="channel"]:checked', wizard)?.value; $$('[data-show-channel]', wizard).forEach(box => { box.hidden = box.dataset.showChannel !== value; }); const signature = $('[name="signature_method"]:checked', wizard); if (value !== 'presential') $('[name="signature_method"][value="digital"]', wizard).checked = true; else if (!signature || signature.value === 'digital') $('[name="signature_method"][value="tablet"]', wizard).checked = true; };
    const updateRegistration = () => {
      const automatic = selfRegistration();
      $$('[data-known-customer]', wizard).forEach(box => { box.hidden = automatic; $$('select, input', box).forEach(input => { input.disabled = automatic; }); });
      const explanation = $('[data-registration-explanation]', wizard); if (explanation) explanation.hidden = !automatic;
      const label = $('[data-proposal-submit-label]', wizard); if (label && wizard.dataset.staff === '1') label.textContent = automatic ? 'Gerar link para preenchimento' : 'Criar proposta';
      updateReview();
    };
    $$('[name="channel"]', wizard).forEach(input => input.addEventListener('change', () => { updateChannels(); updateRegistration(); }));
    $$('[name="prefill_by"]', wizard).forEach(input => input.addEventListener('change', updateRegistration));
    updateChannels(); updateRegistration(); show(0);
    wizard.addEventListener('submit', event => { if (current !== panels.length - 1) { event.preventDefault(); if (validate()) show(current + 1); } });
  }
  $$('[data-signature-form]').forEach(form => {
    const canvas = $('[data-signature-canvas]', form), hidden = $('[data-signature-value]', form), context = canvas?.getContext('2d'); if (!canvas || !context) return;
    let strokes = [], current = null, distance = 0, size = null;
    const point = event => { const bounds = canvas.getBoundingClientRect(); return { x: (event.clientX - bounds.left) / bounds.width, y: (event.clientY - bounds.top) / bounds.height }; };
    const paint = () => {
      if (!size) return;
      context.clearRect(0, 0, size.width, size.height);
      for (const stroke of strokes) {
        if (!stroke.length) continue;
        context.beginPath(); context.moveTo(stroke[0].x * size.width, stroke[0].y * size.height);
        stroke.slice(1).forEach(p => context.lineTo(p.x * size.width, p.y * size.height)); context.stroke();
      }
    };
    const resize = () => {
      const bounds = canvas.getBoundingClientRect(); if (!bounds.width || !bounds.height) return;
      if (size && size.width === bounds.width && size.height === bounds.height) return;
      size = { width: bounds.width, height: bounds.height };
      const ratio = Math.min(window.devicePixelRatio || 1, 2);
      canvas.width = Math.round(Math.min(2000, bounds.width * ratio)); canvas.height = Math.round(Math.min(1000, bounds.height * ratio));
      context.setTransform(canvas.width / bounds.width, 0, 0, canvas.height / bounds.height, 0, 0);
      context.strokeStyle = '#0b5988'; context.lineWidth = 2.2; context.lineCap = 'round'; context.lineJoin = 'round'; paint();
    };
    canvas.addEventListener('pointerdown', event => { if (event.pointerType === 'mouse' && event.button !== 0) return; event.preventDefault(); canvas.setPointerCapture(event.pointerId); current = [point(event)]; strokes.push(current); });
    canvas.addEventListener('pointermove', event => { if (!current || !size) return; event.preventDefault(); const next = point(event), previous = current[current.length - 1]; distance += Math.hypot((next.x - previous.x) * size.width, (next.y - previous.y) * size.height); current.push(next); paint(); });
    const stop = () => { current = null; hidden.value = distance >= 12 ? canvas.toDataURL('image/png') : ''; };
    canvas.addEventListener('pointerup', stop); canvas.addEventListener('pointercancel', stop);
    $('[data-clear-signature]', form)?.addEventListener('click', () => { strokes = []; current = null; distance = 0; paint(); hidden.value = ''; });
    form.addEventListener('submit', event => { stop(); if (!hidden.value || strokes.reduce((n, stroke) => n + stroke.length, 0) < 3) { event.preventDefault(); toast('Desenhe sua assinatura antes de confirmar.'); canvas.scrollIntoView({ block: 'center', behavior: 'smooth' }); } });
    resize(); window.addEventListener('resize', resize);
    if (window.ResizeObserver) new ResizeObserver(resize).observe(canvas);
  });
  // Device and network context recorded with every signature as evidence of the acceptance.
  const deviceInfo = () => {
    const connection = navigator.connection || {}, hints = navigator.userAgentData;
    let timezone = ''; try { timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch {}
    return JSON.stringify({ screen: `${screen.width}x${screen.height}`, viewport: `${window.innerWidth}x${window.innerHeight}`, pixel_ratio: window.devicePixelRatio || 1,
      platform: hints?.platform || navigator.platform || '', mobile: hints ? hints.mobile : /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent),
      language: navigator.language || '', languages: (navigator.languages || []).join(', '), timezone, timezone_offset: -new Date().getTimezoneOffset(),
      touch_points: navigator.maxTouchPoints || 0, cores: navigator.hardwareConcurrency || null, memory_gb: navigator.deviceMemory || null,
      connection: connection.effectiveType || '', downlink_mbps: connection.downlink ?? null, captured_at: new Date().toISOString() });
  };
  $$('[data-device-info]').forEach(input => { const fill = () => { input.value = deviceInfo(); }; fill(); input.form?.addEventListener('submit', fill); });
  $$('[data-selfie]').forEach(box => {
    const input = $('[data-selfie-input]', box), hidden = $('[data-selfie-value]', box), preview = $('[data-selfie-preview]', box), label = $('[data-selfie-label]', box);
    const load = file => new Promise((resolve, reject) => { const url = URL.createObjectURL(file), image = new Image(); image.onload = () => { URL.revokeObjectURL(url); resolve(image); }; image.onerror = () => { URL.revokeObjectURL(url); reject(new Error('unreadable')); }; image.src = url; });
    input.addEventListener('change', async () => {
      const file = input.files?.[0]; if (!file) return;
      try {
        const image = await load(file), scale = Math.min(1, 960 / Math.max(image.naturalWidth, image.naturalHeight)), canvas = document.createElement('canvas');
        canvas.width = Math.round(image.naturalWidth * scale); canvas.height = Math.round(image.naturalHeight * scale);
        canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
        hidden.value = canvas.toDataURL('image/jpeg', 0.82); preview.src = hidden.value; preview.hidden = false; if (label) label.textContent = 'Tirar outra foto';
      } catch { hidden.value = ''; preview.hidden = true; toast('Não foi possível ler a foto. Tire a foto novamente.'); }
      input.value = '';
    });
    box.closest('form')?.addEventListener('submit', event => { if (!hidden.value) { event.preventDefault(); toast('Tire a foto do rosto do titular antes de confirmar.'); box.scrollIntoView({ block: 'center', behavior: 'smooth' }); } });
  });
  $$('form[data-auto-submit]').forEach(form => setTimeout(() => { if (form.dataset.submitting !== 'true') form.requestSubmit ? form.requestSubmit() : form.submit(); }, 1500));
  const benefitRows = $('[data-benefit-rows]'), benefitTemplate = $('#benefit-row-template');
  if (benefitRows && benefitTemplate) { let index = $$('[data-benefit-row]', benefitRows).length; $('[data-add-benefit]')?.addEventListener('click', () => { const wrapper = document.createElement('div'); wrapper.innerHTML = benefitTemplate.innerHTML.replaceAll('__INDEX__', String(index++)); benefitRows.append(wrapper.firstElementChild); }); benefitRows.addEventListener('click', event => { const button = event.target.closest('[data-remove-benefit]'); if (button) button.closest('[data-benefit-row]')?.remove(); }); }
  const submittedButtons = new WeakMap();
  $$('form').forEach(form => form.addEventListener('submit', event => {
    if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
    queueMicrotask(() => {
      if (event.defaultPrevented) return;
      const submitter = event.submitter; if (!submitter?.dataset.submitLabel) return;
      form.dataset.submitting = 'true'; submittedButtons.set(submitter, submitter.innerHTML); submitter.dataset.submitting = 'true'; submitter.disabled = true; submitter.textContent = submitter.dataset.submitLabel;
    });
  }));
  window.addEventListener('pageshow', () => {
    $$('[data-submitting="true"]').forEach(element => {
      delete element.dataset.submitting;
      if (submittedButtons.has(element)) { element.innerHTML = submittedButtons.get(element); element.disabled = false; submittedButtons.delete(element); }
    });
  });
  $$('[data-commission-type]').forEach(select => {
    const suffix = select.closest('tr')?.querySelector('[data-commission-suffix]');
    const updateSuffix = () => { if (suffix) suffix.textContent = select.value === 'fixed' ? 'R$' : '%'; };
    select.addEventListener('change', updateSuffix); updateSuffix();
  });
  // No celular, tabelas viram cartões: cada célula recebe o título da sua coluna (estilos em theme.css).
  // As tabelas de leads são trocadas ao vivo, depois deste passo: o formato delas no celular fica todo no CSS.
  $$('table:not([data-no-stack])').forEach(table => {
    const headers = $$('thead th', table).map(th => th.textContent.trim());
    if (!headers.length) return;
    table.classList.add('is-stackable');
    if (headers.length >= 6) table.classList.add('is-wide');
    $$('tbody tr', table).forEach(row => {
      let column = 0;
      [...row.cells].forEach(cell => {
        const label = headers[column] || '';
        if (cell.colSpan > 1) cell.classList.add('cell-full');
        else if (label) cell.dataset.label = label;
        else { cell.classList.add('cell-action'); if (!cell.textContent.trim() && cell.querySelector('.table-arrow')) cell.classList.add('cell-arrow'); }
        column += cell.colSpan || 1;
      });
    });
  });
})();

// Campanhas: o fluxo das mensalidades acompanha o que se digita (o mesmo desenho de campaigns/_flow).
document.querySelectorAll('[data-campaign-flow]').forEach(flow => {
  const form = flow.closest('form');
  const value = (name, max) => Math.max(0, Math.min(max, parseInt(form.elements[name]?.value, 10) || 0));
  const step = (number, text, tone = '') => `<li${tone ? ` class="${tone}"` : ''}><b>${number}</b>${text}</li>`;
  form.addEventListener('input', () => {
    const free = value('free_months', 12), percent = value('discount_percent', 100), off = percent ? value('discount_months', 12) : 0;
    let html = step('1ª', 'Entrada cheia');
    for (let n = 2; n < 2 + free; n++) html += step(`${n}ª`, 'Grátis', 'is-free');
    for (let n = 2 + free; n < 2 + free + off; n++) html += step(`${n}ª`, `${percent}% de desconto`, 'is-discount');
    flow.innerHTML = html + step(`${2 + free + off}ª em diante`, 'Preço normal');
  });
});
