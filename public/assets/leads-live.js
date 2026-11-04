'use strict';
/* Lista e painel de leads ao vivo: busca a própria página de tempos em tempos e troca só o que mudou.
   Filtros, paginação e botões também trocam sem recarregar. Sem JavaScript, eles continuam sendo link e formulário comuns; só os dados do lead não abrem. */
(() => {
  // shortcut: refaz a página inteira a cada 20 s; se muitas abas abertas pesarem no servidor, trocar por um endpoint só com o último id.
  const EVERY = 20000;
  // Cada busca renova a sessão. Parada a tela por 1 h, a atualização para e o tempo de sessão volta a contar, como em qualquer outra tela.
  const IDLE_LIMIT = 60 * 60 * 1000;

  const root = document.querySelector('[data-live]');
  if (!root) return;
  const status = document.querySelector('[data-live-status]');
  const search = document.querySelector('[data-live-search]');
  const pageTitle = document.title;
  const lastLeadIn = parent => Number(parent.querySelector('[data-last-lead]')?.dataset.lastLead || 0);

  let lastLead = lastLeadIn(root);
  let lastHtml = null;
  let unseen = 0;
  let ticket = 0;
  let touchedAt = Date.now();
  let state = 'live';
  let timer = 0;

  const toast = message => {
    const box = document.getElementById('toast');
    if (!box) return;
    box.textContent = message;
    box.classList.add('show');
    clearTimeout(window.careToast);
    window.careToast = setTimeout(() => box.classList.remove('show'), 5000);
  };

  const setState = next => {
    state = next;
    if (!status) return;
    status.hidden = false;
    status.className = `lead-live is-${next}`;
    const time = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    status.lastElementChild.textContent = {
      live: `Ao vivo · atualizado às ${time}`,
      offline: 'Sem conexão. Tentando de novo...',
      paused: 'Atualização pausada. Mexa na tela para retomar.',
      ended: 'Sessão encerrada. Recarregue a página.',
    }[next];
  };

  // Dados do lead: abrem ao clicar na linha e continuam abertos quando a lista se atualiza.
  const opened = new Set();
  const show = (id, open) => {
    const more = root.querySelector(`[data-lead-more="${id}"]`);
    if (!more) return;
    more.hidden = !open;
    const row = root.querySelector(`[data-lead-id="${id}"]`);
    row?.classList.toggle('is-open', open);
    row?.querySelector('[data-lead-toggle]')?.setAttribute('aria-expanded', String(open));
  };

  // Os botões só com ícone se identificam pelo aria-label.
  const nameOf = item => item.getAttribute('aria-label') || item.textContent.trim();

  // Troca o conteúdo mantendo o foco no mesmo botão da mesma linha, para quem navega pelo teclado não voltar ao início.
  const swap = fresh => {
    const focused = document.activeElement;
    const row = focused?.closest?.('[data-lead-id]');
    const label = focused && nameOf(focused);
    root.replaceChildren(...fresh.childNodes);
    opened.forEach(id => show(id, true));
    if (!row) return;
    const again = root.querySelector(`[data-lead-id="${row.dataset.leadId}"]`);
    [...(again?.querySelectorAll('a,button') ?? [])].find(item => nameOf(item) === label)?.focus({ preventScroll: true });
  };

  // Devolve false quando a resposta não é esta tela (a sessão acabou e veio o login).
  const apply = doc => {
    const fresh = doc.querySelector('[data-live]');
    if (!fresh) return false;
    const newest = lastLeadIn(fresh);
    const arrived = newest > lastLead;
    const html = fresh.innerHTML;
    const previous = lastLead;
    if (html !== lastHtml) {
      lastHtml = html;
      const nav = doc.querySelector('.nav-shortcuts');
      if (nav) document.querySelector('.nav-shortcuts')?.replaceWith(nav);
      swap(fresh);
    }
    if (arrived) {
      if (previous) root.querySelectorAll('[data-lead-id]').forEach(row => { if (Number(row.dataset.leadId) > previous) row.classList.add('lead-row-fresh'); });
      const name = root.querySelector(`[data-lead-id="${newest}"] strong`)?.textContent;
      toast(name ? `Chegou lead novo: ${name}` : 'Chegou lead novo.');
      if (document.hidden) document.title = `(${++unseen}) ${pageTitle}`;
    }
    lastLead = Math.max(lastLead, newest);
    return true;
  };

  const parse = html => new DOMParser().parseFromString(html, 'text/html');

  const load = async (url = location.href) => {
    const mine = ++ticket;
    try {
      const response = await fetch(url, { cache: 'no-store' });
      const html = await response.text();
      if (mine !== ticket) return false;
      if (!response.ok) throw new Error(String(response.status));
      if (!apply(parse(html))) { clearInterval(timer); setState('ended'); return false; }
      setState('live');
      return true;
    } catch {
      if (mine === ticket) setState('offline');
      return false;
    }
  };

  const navigate = url => { history.pushState(null, '', url); return load(url); };

  const touch = () => {
    touchedAt = Date.now();
    if (state === 'paused') load();
  };
  const seen = () => { unseen = 0; document.title = pageTitle; };

  // Botões da tela (Já chamei, Virou cliente...): salvam sem recarregar nem perder a posição na lista.
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!root.contains(form) || form.method !== 'post') return;
    event.preventDefault();
    ticket++;
    const buttons = [...form.querySelectorAll('button')];
    buttons.forEach(button => { button.disabled = true; });
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
      if (!response.ok) throw new Error(String(response.status));
      const doc = parse(await response.text());
      const message = doc.querySelector('.alert-success div')?.textContent;
      if (apply(doc) || await load()) toast(message ?? 'Lead atualizado.');
      setState(state === 'ended' ? 'ended' : 'live');
    } catch {
      buttons.forEach(button => { button.disabled = false; });
      toast('Não foi possível salvar. Recarregue a página e tente de novo.');
    }
  });

  document.addEventListener('click', event => {
    const row = event.target.closest?.('[data-live] [data-lead-id]');
    // Links e botões da linha fazem o trabalho deles, e selecionar um texto para copiar não abre nem fecha.
    if (!row || event.target.closest('a,form') || String(getSelection())) return;
    const id = row.dataset.leadId;
    const open = !opened.has(id);
    if (open) opened.add(id); else opened.delete(id);
    show(id, open);
  });

  // Filtros, paginação e período dentro da mesma tela; link para outra tela segue normal.
  document.addEventListener('click', event => {
    const link = event.target.closest?.('[data-live] a[href]');
    if (!link || link.target || event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || url.pathname !== location.pathname) return;
    event.preventDefault();
    navigate(url.href);
  });
  addEventListener('popstate', () => load());

  if (search) {
    const results = () => {
      const url = new URL(location.href);
      const q = search.elements.q.value.trim();
      if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
      // A busca olha todos os leads: procurar com uma aba aberta escondia quem estava nas outras.
      url.searchParams.delete('situacao');
      url.searchParams.delete('page');
      return url.href;
    };
    let typing = 0;
    search.addEventListener('input', () => { clearTimeout(typing); typing = setTimeout(() => navigate(results()), 300); });
    search.addEventListener('submit', event => { event.preventDefault(); clearTimeout(typing); navigate(results()); });
  }

  ['pointerdown', 'keydown'].forEach(name => document.addEventListener(name, touch, { passive: true }));
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    seen();
    touch();
    load();
  });

  setState('live');
  timer = setInterval(() => {
    if (state === 'ended') return;
    if (Date.now() - touchedAt > IDLE_LIMIT) { setState('paused'); return; }
    load();
  }, EVERY);
})();
