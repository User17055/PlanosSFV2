(() => {
  const recovery = document.querySelector('[data-contract-recovery]');
  if (recovery) {
    const status = recovery.querySelector('[data-recovery-status]'), button = recovery.querySelector('[data-recovery-button]');
    let busy = false, attempts = 0, timer;
    async function recover() {
      if (busy) return;
      clearTimeout(timer); busy = true; button.disabled = true; attempts++;
      status.textContent = 'Sua assinatura está registrada. Preparando o PDF final…';
      const controller = new AbortController(), requestTimer = setTimeout(() => controller.abort(), 125000);
      try {
        const response = await fetch(recovery.action, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new FormData(recovery), signal: controller.signal });
        const result = await response.json();
        if (response.ok && result.ready) { status.textContent = 'Assinatura concluída. Abrindo seu contrato…'; location.reload(); return; }
        if (response.status === 503 && attempts < 3) {
          status.textContent = 'O PDF ainda está em preparação. Vamos tentar novamente automaticamente.';
          timer = setTimeout(recover, attempts * 5000);
        } else {
          status.textContent = 'Sua assinatura está salva. Tente gerar o PDF novamente em instantes ou solicite apoio à unidade.';
        }
      } catch {
        status.textContent = 'Não foi possível concluir a conexão. Sua assinatura permanece salva; tente gerar o PDF novamente.';
      } finally { clearTimeout(requestTimer); busy = false; button.disabled = false; }
    }
    recovery.addEventListener('submit', event => { event.preventDefault(); attempts = 0; recover(); });
    timer = setTimeout(recover, 1500);
  }
  const root = document.querySelector('[data-contract-review]');
  if (!root) return;
  const frame = root.querySelector('[data-contract-frame]'), status = root.querySelector('[data-contract-status]');
  let ready = false, pending = false, objectUrl = null;
  const setReady = value => {
    ready = value;
    document.querySelectorAll('[data-requires-review]').forEach(button => { if (!button.dataset.submitting) button.disabled = !value; });
    document.querySelectorAll('[data-signing-review-hint]').forEach(hint => { hint.textContent = value ? 'PDF carregado. Confira todas as páginas antes de confirmar.' : 'Carregue o contrato em PDF antes de confirmar sua assinatura.'; });
  };
  async function load() {
    if (pending) return;
    pending = true; setReady(false);
    root.setAttribute('aria-busy', 'true');
    status.textContent = 'Preparando o PDF para conferência. Isso pode levar alguns instantes…';
    status.classList.remove('is-error');
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 110000);
    try {
      const response = await fetch(root.dataset.pdfUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal, cache: 'no-store' });
      if (!response.ok) {
        let message = 'Não foi possível carregar o PDF. Tente novamente ou atualize a página.';
        try { const error = await response.json(); message = error.message || message; } catch {}
        throw new Error(message);
      }
      if (!response.headers.get('content-type')?.includes('application/pdf')) throw new Error('Seu acesso precisa ser confirmado novamente. Atualize a página e entre com o acesso do titular.');
      const blob = await response.blob();
      if ((await blob.slice(0, 5).text()) !== '%PDF-') throw new Error('O documento não ficou pronto. Use “Carregar novamente”.');
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = URL.createObjectURL(blob); frame.src = objectUrl; frame.hidden = false;
      status.textContent = 'Contrato pronto para conferência. Se não aparecer neste dispositivo, abra o PDF em nova aba.';
      setReady(true);
    } catch (error) {
      frame.hidden = true;
      status.textContent = error.name === 'AbortError' ? 'O PDF demorou para responder. Tente carregar novamente; o aceite não foi enviado.' : error.message;
      status.classList.add('is-error');
    } finally { clearTimeout(timer); pending = false; root.removeAttribute('aria-busy'); }
  }
  root.querySelector('[data-contract-retry]')?.addEventListener('click', load);
  document.querySelectorAll('[data-contract-signing-form]').forEach(form => form.addEventListener('submit', event => {
    if (!ready) { event.preventDefault(); status.textContent = 'Carregue e confira o contrato antes de confirmar a assinatura.'; root.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
  }));
  window.addEventListener('pageshow', event => { if (event.persisted) load(); });
  load();
})();
