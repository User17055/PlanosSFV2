'use strict';
(() => {
  const root = document.querySelector('[data-lead-chat]');
  if (!root) return;
  const list = root.querySelector('[data-chat-messages]');
  const history = root.querySelector('[data-chat-history]');
  const status = root.querySelector('[data-chat-status]');
  const error = root.querySelector('[data-chat-error]');
  const empty = root.querySelector('[data-chat-empty]');
  const older = root.querySelector('[data-chat-older]');
  const refresh = root.querySelector('[data-chat-refresh]');
  const form = root.querySelector('[data-chat-compose]');
  const textarea = form?.querySelector('textarea');
  const sendButton = form?.querySelector('button');
  const sendLabel = form?.querySelector('[data-send-label]');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const messages = new Map();
  let page = 1, loading = false, sending = false, stopped = false, timer;
  let requestId = null, requestText = null, lastSendError = '', idleAt = Date.now();
  const paused = () => document.documentElement.dataset.chatPaused === 'true';

  function showError(message) {
    error.textContent = message;
    error.hidden = !message;
  }

  function freeze() {
    stopped = true;
    clearTimeout(timer);
    messages.clear();
    list.replaceChildren();
    empty.hidden = true;
    older.hidden = true;
    if (textarea) textarea.disabled = true;
    if (sendButton) sendButton.disabled = true;
    status.textContent = 'Atendimento indisponível';
  }

  async function api(url, options = {}) {
    let response;
    try {
      response = await fetch(url, { ...options, credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...options.headers } });
    } catch (_) {
      throw new Error(options.method === 'POST'
        ? 'A conexão caiu durante o envio. Confira o histórico antes de enviar novamente.'
        : 'Sem conexão com o sistema. Tentaremos atualizar novamente.');
    }
    if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
      freeze();
      throw new Error('Sua sessão ou permissão mudou. Recarregue a página para continuar.');
    }
    const data = await response.json();
    if (!response.ok) {
      if ([401, 403, 404, 419].includes(response.status)) freeze();
      throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Não foi possível concluir. Tente novamente.');
    }
    return data;
  }

  function dateOf(value) {
    if (value === null || value === undefined) return null;
    const timestamp = typeof value === 'number' || /^\d+$/.test(String(value)) ? Number(value) : value;
    const date = new Date(typeof timestamp === 'number' && timestamp < 1e12 ? timestamp * 1000 : timestamp);
    return Number.isNaN(date.getTime()) ? null : date;
  }

  const dayKey = date => date.toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' });

  function dayLabel(date, day) {
    if (day === dayKey(new Date())) return 'Hoje';
    return day === dayKey(new Date(Date.now() - 86400000)) ? 'Ontem' : day;
  }

  // O campo cresce com o texto até o limite do CSS e volta ao tamanho normal depois do envio.
  function fitTextarea() {
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 2 + 'px';
  }

  function render() {
    const nearBottom = history.scrollHeight - history.scrollTop - history.clientHeight < 100;
    const scrollHeight = history.scrollHeight;
    const scrollTop = history.scrollTop;
    const items = [...messages.values()].sort((a, b) => (dateOf(a.at)?.getTime() || 0) - (dateOf(b.at)?.getTime() || 0));
    const fragment = document.createDocumentFragment();
    let previousDate = '';
    for (const message of items) {
      const date = dateOf(message.at);
      const day = date ? dayKey(date) : '';
      if (day && day !== previousDate) {
        const divider = document.createElement('div');
        divider.className = 'chat-day';
        divider.textContent = dayLabel(date, day);
        fragment.append(divider);
        previousDate = day;
      }
      const bubble = document.createElement('article');
      bubble.className = `chat-message${message.outgoing ? ' is-outgoing' : ''}`;
      bubble.setAttribute('aria-label', message.outgoing ? 'Mensagem enviada' : 'Mensagem recebida');
      if (message.text) {
        const text = document.createElement('p');
        text.textContent = message.text;
        bubble.append(text);
      }
      if (message.type !== 'chat') {
        const media = document.createElement('p');
        media.className = 'chat-media';
        media.textContent = ({ image: 'Imagem', audio: 'Áudio', ptt: 'Áudio', video: 'Vídeo', document: 'Documento', sticker: 'Figurinha' }[message.type] || 'Anexo') + ' · consulte no Digisac';
        bubble.append(media);
      }
      const time = document.createElement('small');
      time.textContent = (date?.toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit' }) || '')
        + (message.outgoing ? (message.sent ? ' ✓' : ' · Aguardando confirmação') : '');
      if (message.outgoing) time.title = message.sent ? 'Enviada' : 'Aguardando confirmação';
      bubble.append(time);
      fragment.append(bubble);
    }
    list.replaceChildren(fragment);
    empty.hidden = items.length > 0;
    if (loading === 'older') history.scrollTop = scrollTop + history.scrollHeight - scrollHeight;
    else if (nearBottom) history.scrollTop = history.scrollHeight;
  }

  function schedule() {
    clearTimeout(timer);
    if (!stopped && !paused()) timer = setTimeout(() => load(), 10000);
  }

  async function load(previous = false, manual = false) {
    if (loading || stopped || paused()) return;
    if (!manual && (document.hidden || Date.now() - idleAt > 3600000)) {
      status.textContent = 'Atualização pausada';
      schedule();
      return;
    }
    loading = previous ? 'older' : 'latest';
    refresh.disabled = true;
    older.disabled = true;
    try {
      const url = new URL(root.dataset.messagesUrl, location.href);
      url.searchParams.set('page', previous ? page + 1 : 1);
      const data = await api(url);
      if (stopped || paused()) return;
      for (const message of data.messages) if (message.id) messages.set(message.id, message);
      if (previous) page++;
      if (previous || page === 1) older.hidden = !data.has_more;
      render();
      status.textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });
      if (!sending && !lastSendError) showError('');
    } catch (failure) {
      showError(failure.message);
      if (!stopped) status.textContent = 'Falha na atualização';
    } finally {
      loading = false;
      refresh.disabled = stopped;
      older.disabled = stopped;
      schedule();
    }
  }

  refresh.addEventListener('click', () => { idleAt = Date.now(); load(false, true); });
  older.addEventListener('click', () => load(true, true));
  root.addEventListener('pointerdown', () => { idleAt = Date.now(); });
  root.addEventListener('keydown', () => { idleAt = Date.now(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { idleAt = Date.now(); load(); } });
  document.addEventListener('lead-chat:visibility', () => {
    if (paused()) {
      clearTimeout(timer);
      messages.clear();
      list.replaceChildren();
      empty.hidden = true;
      older.hidden = true;
      page = 1;
      status.textContent = 'Atualização pausada';
    } else {
      idleAt = Date.now();
      load(false, true);
    }
  });
  textarea?.addEventListener('input', fitTextarea);
  textarea?.addEventListener('keydown', event => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      if (!sending && !stopped) form.requestSubmit();
    }
  });
  form?.addEventListener('submit', async event => {
    event.preventDefault();
    const text = textarea.value.trim();
    if (sending || stopped || !text) return;
    sending = true;
    sendButton.disabled = true;
    textarea.readOnly = true;
    sendLabel.textContent = 'Enviando…';
    lastSendError = '';
    showError('');
    // Mantém a chave após falhas de rede: clicar novamente não duplica o mesmo envio.
    if (!requestId || requestText !== text) { requestId = crypto.randomUUID(); requestText = text; }
    try {
      await api(root.dataset.sendUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text, request_id: requestId }) });
      textarea.value = '';
      fitTextarea();
      requestId = requestText = null;
      status.textContent = 'Mensagem enviada. Atualizando conversa…';
    } catch (failure) {
      lastSendError = failure.message;
      showError(failure.message);
    } finally {
      sending = false;
      sendButton.disabled = stopped;
      textarea.readOnly = false;
      sendLabel.textContent = 'Enviar';
      if (!stopped) textarea.focus();
    }
    if (!textarea.value && !stopped) await load(false, true);
  });
  window.addEventListener('pagehide', () => { freeze(); });
  window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
  load();
})();
