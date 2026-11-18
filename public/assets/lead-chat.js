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
  const sendButton = form?.querySelector('button[type="submit"]');
  const fileInput = form?.querySelector('[data-chat-file]');
  const attachBox = form?.querySelector('[data-chat-attachment]');
  const attachName = form?.querySelector('[data-chat-attachment-name]');
  const recordButton = form?.querySelector('[data-chat-record]');
  const recordingBox = form?.querySelector('[data-chat-recording]');
  const recordingTime = form?.querySelector('[data-chat-recording-time]');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const messages = new Map();
  const nodes = new Map();
  const MAX_FILE = 5 * 1024 * 1024;
  const mediaLabel = { image: 'Imagem', audio: 'Áudio', ptt: 'Áudio', video: 'Vídeo', document: 'Documento', sticker: 'Figurinha' };
  let page = 1, loading = false, stopped = false, timer, queue = Promise.resolve();
  let lastSendError = '', idleAt = Date.now();
  // Como no WhatsApp: relógio enquanto sai, ✓ quando saiu, ! quando falhou.
  const TICKS = {
    sending: '<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="1.4"/><path d="M8 4.8V8l2.1 1.3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>',
    sent: '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path d="M3 8.6l3 3 7-7.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    failed: '<svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="currentColor"/><path d="M8 4.5v4.2M8 11.2v.1" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/></svg>',
  };
  const kindOf = file => /^(image|audio|video)\//.exec(file.type)?.[1] || 'document';
  let attachment = null, recorder = null, recordTimer = null, avatarUrl = '', atBottom = true;
  const paused = () => document.documentElement.dataset.chatPaused === 'true';

  function showError(message) {
    error.textContent = message;
    error.hidden = !message;
  }

  function clearHistory() {
    messages.clear();
    nodes.clear();
    list.replaceChildren();
    empty.hidden = true;
    older.hidden = true;
  }

  function freeze() {
    stopped = true;
    clearTimeout(timer);
    clearHistory();
    stopRecording(false);
    for (const control of [textarea, sendButton, fileInput, recordButton]) if (control) control.disabled = true;
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
    if (response.status === 413) throw new Error('O arquivo é grande demais para enviar. Envie um arquivo menor.');
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
  const clock = seconds => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

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

  // Foto do contato no Digisac, no cabeçalho e na lista. Sem foto, ou se ela não carregar, ficam as iniciais.
  function showAvatar(url) {
    if (!url || avatarUrl) return;
    avatarUrl = url;
    // Foto já guardada no sistema vem pronta na página; aqui só entra a primeira vez.
    for (const holder of document.querySelectorAll('.chat-contact .chat-avatar:not(.has-photo), .chat-lead.is-selected .chat-avatar:not(.has-photo)')) {
      const image = new Image();
      image.alt = '';
      image.addEventListener('load', () => { holder.replaceChildren(image); holder.classList.add('has-photo'); });
      image.addEventListener('error', () => { avatarUrl = ''; });
      image.src = url;
    }
  }

  // Foto abre por cima da conversa, sem baixar. Ctrl+clique continua abrindo em outra aba.
  const viewer = document.createElement('dialog');
  viewer.className = 'chat-viewer';
  viewer.setAttribute('aria-label', 'Imagem da conversa');
  viewer.innerHTML = '<button type="button" class="chat-viewer-close" aria-label="Fechar imagem">×</button><img alt="">';
  document.body.append(viewer);
  const viewerImage = viewer.querySelector('img');
  viewer.addEventListener('click', event => { if (event.target !== viewerImage) viewer.close(); });
  // Esc fecha só a foto, não a janela do chat inteira.
  viewer.addEventListener('keydown', event => { if (event.key === 'Escape') { event.preventDefault(); viewer.close(); } });
  viewer.addEventListener('close', () => viewerImage.removeAttribute('src'));

  // O link do Digisac é temporário: se vencer, o balão é refeito com o link novo na próxima atualização.
  function media(message, bubble) {
    const url = message.file?.url;
    const stale = () => { bubble.dataset.stale = '1'; };
    if (!url) {
      const note = document.createElement('p');
      note.className = 'chat-media';
      note.textContent = (mediaLabel[message.type] || 'Anexo') + ' · consulte no Digisac';
      return note;
    }
    if (message.type === 'image' || message.type === 'sticker') {
      const link = document.createElement('a');
      link.className = `chat-photo${message.type === 'sticker' ? ' is-sticker' : ''}`;
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.setAttribute('aria-label', 'Abrir imagem em tamanho real');
      const image = document.createElement('img');
      image.alt = mediaLabel[message.type];
      image.loading = 'lazy';
      image.decoding = 'async';
      image.addEventListener('error', stale);
      // A foto carrega depois do balão: quem estava no fim da conversa continua no fim.
      image.addEventListener('load', () => { if (atBottom) history.scrollTop = history.scrollHeight; });
      image.src = url;
      link.append(image);
      link.addEventListener('click', event => {
        if (event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        viewerImage.src = url;
        viewerImage.alt = image.alt;
        viewer.showModal();
      });
      return link;
    }
    if (['audio', 'ptt', 'video'].includes(message.type)) {
      const player = document.createElement(message.type === 'video' ? 'video' : 'audio');
      player.controls = true;
      player.preload = message.type === 'video' ? 'metadata' : 'none';
      player.setAttribute('aria-label', mediaLabel[message.type]);
      player.addEventListener('error', stale);
      player.src = url;
      return player;
    }
    const file = document.createElement('a');
    file.className = 'chat-file';
    file.href = url;
    file.target = '_blank';
    file.rel = 'noopener';
    file.textContent = message.file.name || 'Documento';
    return file;
  }

  function bubbleFor(message, date) {
    const bubble = document.createElement('article');
    bubble.className = `chat-message${message.outgoing ? ' is-outgoing' : ''}${message.type !== 'chat' ? ' has-media' : ''}`;
    bubble.setAttribute('aria-label', message.outgoing ? 'Mensagem enviada' : 'Mensagem recebida');
    if (message.sender) {
      const sender = document.createElement('strong');
      sender.className = 'chat-sender';
      sender.textContent = message.sender;
      bubble.append(sender);
    }
    if (message.type !== 'chat') bubble.append(media(message, bubble));
    // No documento, o Digisac repete o nome do arquivo como texto.
    if (message.text && !(message.type === 'document' && message.text === message.file?.name)) {
      const text = document.createElement('p');
      text.textContent = message.text;
      bubble.append(text);
    }
    const time = document.createElement('small');
    time.textContent = date?.toLocaleTimeString('pt-BR', { timeZone: 'America/Sao_Paulo', hour: '2-digit', minute: '2-digit' }) || '';
    if (message.outgoing) {
      const state = message.local || (message.sent ? 'sent' : 'sending');
      const tick = document.createElement('span');
      tick.className = `chat-tick is-${state}`;
      tick.innerHTML = TICKS[state];
      time.title = { sending: 'Enviando', sent: 'Enviada', failed: 'Não enviada' }[state];
      time.append(tick);
    }
    bubble.append(time);
    if (message.local === 'failed') {
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'chat-retry';
      retry.dataset.chatRetry = message.id;
      retry.textContent = 'Não enviada · Tentar de novo';
      bubble.append(retry);
    }
    return bubble;
  }

  function render() {
    const nearBottom = history.scrollHeight - history.scrollTop - history.clientHeight < 100;
    const scrollHeight = history.scrollHeight;
    const scrollTop = history.scrollTop;
    const items = [...messages.values()].sort((a, b) => (dateOf(a.at)?.getTime() || 0) - (dateOf(b.at)?.getTime() || 0));
    const fragment = document.createDocumentFragment();
    const used = new Map();
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
      // Reaproveita o balão que não mudou: a foto não pisca e o áudio tocando não para a cada atualização.
      const signature = [message.type, message.text, message.outgoing, message.sent, message.local || '', message.at, message.file ? message.file.name : '-', message.sender || ''].join('|');
      let bubble = nodes.get(message.id);
      if (!bubble || bubble.dataset.signature !== signature || bubble.dataset.stale) {
        bubble = bubbleFor(message, date);
        bubble.dataset.signature = signature;
      }
      used.set(message.id, bubble);
      fragment.append(bubble);
    }
    nodes.clear();
    used.forEach((bubble, id) => nodes.set(id, bubble));
    list.replaceChildren(fragment);
    empty.hidden = items.length > 0;
    if (loading === 'older') history.scrollTop = scrollTop + history.scrollHeight - scrollHeight;
    else if (nearBottom) history.scrollTop = history.scrollHeight;
  }

  function schedule() {
    clearTimeout(timer);
    if (!stopped && !paused()) timer = setTimeout(() => load(), 4000);
  }

  // A mensagem que saiu daqui dá lugar à do Digisac assim que ela aparece (mesmo texto, ou o mesmo tipo de arquivo).
  function settle(arrived) {
    for (const [id, local] of messages) {
      if (local.local !== 'sent') continue;
      const twin = arrived.some(message => message.outgoing && (dateOf(message.at)?.getTime() || 0) >= local.at - 120000
        && (local.type === 'chat' ? message.text.trim() === local.text : message.type === local.type || (local.type === 'audio' && message.type === 'ptt')));
      if (twin || Date.now() - local.sentAt > 120000) {
        if (local.file?.url) URL.revokeObjectURL(local.file.url);
        messages.delete(id);
      }
    }
  }

  async function deliver(local) {
    if (stopped) return;
    local.local = 'sending';
    render();
    try {
      const body = { text: local.text, request_id: local.requestId };
      if (local.blob) body.file = { name: local.blob.name, data: await base64(local.blob) };
      await api(root.dataset.sendUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
      local.local = 'sent';
      local.sentAt = Date.now();
      lastSendError = '';
      root.querySelector('[data-chat-claim-note]')?.remove();
    } catch (failure) {
      local.local = 'failed';
      lastSendError = failure.message;
      showError(failure.message);
    }
    render();
    if (local.local === 'sent' && !stopped) load(false, true);
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
      if (!previous && !manual) url.searchParams.set('poll', '1');
      const data = await api(url);
      if (stopped || paused()) return;
      for (const message of data.messages) if (message.id) messages.set(message.id, message);
      if (!previous) settle(data.messages);
      if (previous) page++;
      if (previous || page === 1) older.hidden = !data.has_more;
      render();
      showAvatar(data.avatar);
      status.textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });
      if (!lastSendError) showError('');
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

  function setAttachment(file, label = '') {
    attachment = file;
    if (!attachBox) return;
    attachBox.hidden = !file;
    attachName.textContent = file ? label || file.name : '';
    textarea.required = !file;
    if (!file && fileInput) fileInput.value = '';
  }

  // Foto grande do celular é reduzida antes do envio (até 1920 px, em JPEG) para caber no limite de 5 MB.
  async function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size <= 1.5 * 1024 * 1024 || !window.createImageBitmap) return file;
    try {
      const bitmap = await createImageBitmap(file);
      const scale = Math.min(1, 1920 / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(bitmap.width * scale);
      canvas.height = Math.round(bitmap.height * scale);
      const context = canvas.getContext('2d');
      context.fillStyle = '#fff';
      context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
      return blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
    } catch (_) {
      return file;
    }
  }

  const base64 = blob => new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.addEventListener('load', () => resolve(String(reader.result).split(',')[1] || ''));
    reader.addEventListener('error', () => reject(new Error('Não foi possível ler o arquivo. Anexe de novo.')));
    reader.readAsDataURL(blob);
  });

  // Chrome, Edge e Safari gravam em AAC (MP4), que o WhatsApp toca; o Firefox grava em Ogg.
  const recordType = ['audio/mp4;codecs=mp4a.40.2', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm;codecs=opus']
    .find(type => window.MediaRecorder?.isTypeSupported?.(type));
  if (recordButton && (!recordType || !navigator.mediaDevices?.getUserMedia)) recordButton.hidden = true;

  function stopRecording(keep) {
    return new Promise(resolve => {
      if (!recorder) return resolve();
      const current = recorder;
      recorder = null;
      clearInterval(recordTimer);
      current.addEventListener('stop', () => {
        current.stream.getTracks().forEach(track => track.stop());
        recordingBox.hidden = true;
        recordButton.classList.remove('is-recording');
        recordButton.setAttribute('aria-pressed', 'false');
        recordButton.setAttribute('aria-label', 'Gravar áudio');
        if (keep && current.chunks.length) {
          const type = current.mimeType.split(';')[0];
          const extension = { 'audio/mp4': 'm4a', 'audio/ogg': 'ogg' }[type] || 'weba';
          const file = new File(current.chunks, `audio.${extension}`, { type });
          if (file.size > MAX_FILE) showError('O áudio passa de 5 MB. Grave um áudio mais curto.');
          else setAttachment(file, `Áudio gravado · ${clock(Math.round((Date.now() - current.startedAt) / 1000))}`);
        }
        resolve();
      }, { once: true });
      current.stop();
    });
  }

  recordButton?.addEventListener('click', async () => {
    if (recorder) { await stopRecording(true); textarea.focus(); return; }
    if (stopped) return;
    let stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (_) {
      showError('Libere o microfone no navegador para gravar áudio.');
      return;
    }
    setAttachment(null);
    showError('');
    const current = new MediaRecorder(stream, { mimeType: recordType });
    current.chunks = [];
    current.startedAt = Date.now();
    current.addEventListener('dataavailable', event => { if (event.data.size) current.chunks.push(event.data); });
    current.start();
    recorder = current;
    recordButton.classList.add('is-recording');
    recordButton.setAttribute('aria-pressed', 'true');
    recordButton.setAttribute('aria-label', 'Parar gravação');
    recordingBox.hidden = false;
    recordingTime.textContent = '0:00';
    recordTimer = setInterval(() => {
      const seconds = Math.round((Date.now() - current.startedAt) / 1000);
      recordingTime.textContent = clock(seconds);
      if (seconds >= 300) stopRecording(true);
    }, 500);
  });
  form?.querySelector('[data-chat-record-cancel]')?.addEventListener('click', () => stopRecording(false));

  fileInput?.addEventListener('change', async () => {
    const file = fileInput.files[0];
    if (!file) return;
    await stopRecording(false);
    const ready = await shrink(file);
    if (ready.size > MAX_FILE) {
      setAttachment(null);
      showError('O arquivo passa de 5 MB. Envie um arquivo menor.');
      return;
    }
    showError('');
    setAttachment(ready);
    textarea.focus();
  });
  form?.querySelector('[data-chat-attachment-remove]')?.addEventListener('click', () => { setAttachment(null); textarea.focus(); });

  history.addEventListener('scroll', () => { atBottom = history.scrollHeight - history.scrollTop - history.clientHeight < 100; }, { passive: true });
  refresh.addEventListener('click', () => { idleAt = Date.now(); load(false, true); });
  older.addEventListener('click', () => load(true, true));
  list.addEventListener('click', event => {
    const local = messages.get(event.target.closest('[data-chat-retry]')?.dataset.chatRetry);
    if (local) { showError(''); queue = queue.then(() => deliver(local)); }
  });
  root.addEventListener('pointerdown', () => { idleAt = Date.now(); });
  root.addEventListener('keydown', () => { idleAt = Date.now(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { idleAt = Date.now(); load(); } });
  document.addEventListener('lead-chat:visibility', () => {
    if (paused()) {
      clearTimeout(timer);
      clearHistory();
      stopRecording(false);
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
      if (!stopped) form.requestSubmit();
    }
  });
  form?.addEventListener('submit', async event => {
    event.preventDefault();
    if (recorder) await stopRecording(true);
    const text = textarea.value.trim();
    const file = attachment;
    if (stopped || (!text && !file)) return;
    // Como no WhatsApp: a mensagem aparece na hora e o campo fica livre para a próxima; os envios seguem em fila, um de cada vez.
    textarea.value = '';
    setAttachment(null);
    fitTextarea();
    showError('');
    lastSendError = '';
    const local = { id: 'local-' + crypto.randomUUID(), requestId: crypto.randomUUID(), local: 'sending', outgoing: true, at: Date.now(), text,
      type: file ? kindOf(file) : 'chat', blob: file, file: file ? { url: URL.createObjectURL(file), name: file.name } : null };
    messages.set(local.id, local);
    atBottom = true;
    render();
    history.scrollTop = history.scrollHeight;
    textarea.focus();
    queue = queue.then(() => deliver(local));
  });
  window.addEventListener('pagehide', () => { freeze(); });
  window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
  load(false, true);
})();

// A lista de conversas acompanha as mensagens que chegam (o Digisac é lido a cada minuto), sem recarregar a página.
(() => {
  const list = document.querySelector('.chat-lead-list');
  if (!list) return;
  setInterval(async () => {
    if (document.hidden || document.documentElement.dataset.chatPaused === 'true') return;
    try {
      const response = await fetch(location.href, { cache: 'no-store', credentials: 'same-origin' });
      if (!response.ok || response.redirected) return;
      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
      const count = doc.querySelector('[data-queue-count]');
      if (count) document.querySelector('[data-queue-count]')?.replaceWith(count);
      const fresh = doc.querySelector('.chat-lead-list');
      if (!fresh || fresh.innerHTML === list.innerHTML) return;
      const top = list.scrollTop;
      list.replaceChildren(...fresh.childNodes);
      list.scrollTop = top;
    } catch (_) { /* Sem conexão: tenta de novo na próxima volta. */ }
  }, 20000);
})();
