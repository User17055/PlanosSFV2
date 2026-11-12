(() => {
  'use strict';
  const source = document.getElementById('dashboard-data');
  if (!source) return;
  const data = JSON.parse(source.textContent);
  const ns = 'http://www.w3.org/2000/svg';
  const make = (name, attrs = {}, text = '') => {
    const node = document.createElementNS(ns, name);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
    if (text) node.textContent = text;
    return node;
  };
  const money = value => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }).format(value / 100);
  const norm = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim().replace(/\s+/g, ' ').toLowerCase();
  const form = document.getElementById('dashboard-filters');
  // Section links also reveal the tables that now start collapsed.
  function revealSection() {
    const target = document.getElementById(location.hash.slice(1));
    if (!target || !target.closest('.dashboard-workspace')) return;
    for (let node = target; node; node = node.parentElement) {
      if (node.tagName === 'DETAILS') node.open = true;
    }
    requestAnimationFrame(() => target.scrollIntoView({ block: 'start' }));
  }
  document.querySelectorAll('.dashboard-section-nav a').forEach(link => link.addEventListener('click', () => {
    requestAnimationFrame(revealSection);
  }));
  window.addEventListener('hashchange', revealSection);
  if (location.hash) revealSection();
  function filter(state, city = '', neighborhood = '') {
    form.elements.state.value = state;
    form.elements.city.value = city;
    form.elements.neighborhood.value = neighborhood;
    form.requestSubmit();
  }
  form.elements.state.addEventListener('change', () => { form.elements.city.value = ''; form.elements.neighborhood.value = ''; });
  form.elements.city.addEventListener('change', () => {
    form.elements.neighborhood.value = '';
    form.elements.state.value = form.elements.city.selectedOptions?.[0]?.dataset.state || '';
  });
  function chart() {
    const host = document.getElementById('dashboard-revenue-chart');
    const series = data.series;
    const w = 760, h = 285, left = 82, top = 24, bottom = 40, right = 15;
    const max = Math.max(10000, ...series.flatMap(s => [s.sold_cents, s.received_cents, s.commission_cents])) * 1.15;
    const plotH = h - top - bottom, plotW = w - left - right;
    const svg = make('svg', { viewBox: `0 0 ${w} ${h}`, 'aria-hidden': 'true' });
    const hatch = make('pattern', { id: 'chart-hatch', width: 6, height: 6, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' });
    hatch.append(make('line', { x1: 0, y1: 0, x2: 0, y2: 6, class: 'chart-hatch-line', 'stroke-width': 2 }));
    const defs = make('defs');
    defs.append(hatch);
    svg.append(defs);
    for (let i = 0; i <= 4; i++) {
      const y = top + plotH * i / 4;
      svg.append(make('line', { x1: left, x2: w - right, y1: y, y2: y, class: 'chart-grid', 'stroke-dasharray': '4 5' }));
      svg.append(make('text', { x: left - 12, y: y + 4, 'text-anchor': 'end', class: 'chart-label', 'font-size': 10 }, money(max * (1 - i / 4))));
    }
    const slot = plotW / Math.max(1, series.length), bw = Math.min(28, slot / 5);
    series.forEach((s, index) => {
      const center = left + slot * (index + .5);
      ['sold_cents', 'received_cents', 'commission_cents'].forEach((key, i) => {
        const barH = Math.max(s[key] ? 2 : 0, plotH * s[key] / max);
        const bar = make('rect', { x: center + (i - 1.5) * (bw + 4), y: top + plotH - barH, width: bw, height: barH, rx: 3, class: `chart-bar chart-bar-${i}` });
        bar.append(make('title', {}, `${s.month}: ${money(s[key])}`)); svg.append(bar);
      });
      svg.append(make('text', { x: center, y: h - 14, 'text-anchor': 'middle', class: 'chart-label', 'font-size': 11 }, s.month));
    });
    host.replaceChildren(svg);
  }
  if (document.getElementById('dashboard-revenue-chart')) chart();

  // Gráficos do painel (crescimento, entradas e saídas, recebido, cancelamentos): SVG na largura real da tela, com dica ao
  // passar o mouse ou tocar. A tabela "Ver mês a mês" traz os mesmos números.
  const whole = (value, cents) => cents ? new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value / 100) : new Intl.NumberFormat('pt-BR').format(value);
  const short = (value, cents) => new Intl.NumberFormat('pt-BR', { notation: 'compact', maximumFractionDigits: 1, ...(cents ? { style: 'currency', currency: 'BRL' } : {}) }).format(cents ? value / 100 : value);
  const niceMax = value => { if (value <= 0) return 1; const p = 10 ** Math.floor(Math.log10(value)); return [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10].find(n => n * p >= value) * p; };
  // Cantos de 4px só na ponta do dado; a base fica reta, encostada na linha do zero.
  const bar = (x, y, w, h, up) => {
    if (h <= 0.5) return '';
    const r = Math.min(4, w / 2, h);
    return up ? `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`
      : `M${x},${y}V${y + h - r}Q${x},${y + h} ${x + r},${y + h}H${x + w - r}Q${x + w},${y + h} ${x + w},${y + h - r}V${y}Z`;
  };
  const percent = value => value === null || value === undefined ? '—' : `${String(value).replace('.', ',')}%`;
  const monthCharts = {
    growth: { height: 260, area: true, series: [{ key: 'active', color: '--c-main' }],
      tip: m => [['Na carteira', whole(m.active), '--c-main'], ['Receita da carteira', whole(m.mrr_cents, true), '--c-navy'], ['Entraram', whole(m.entered), '--c-in'], ['Saíram', whole(m.left), '--c-out']] },
    flow: { height: 170, series: [{ key: 'entered', color: '--c-in' }, { key: 'left', color: '--c-out', down: true }],
      tip: m => [['Entraram', whole(m.entered), '--c-in'], ['Saíram', whole(m.left), '--c-out']] },
    received: { height: 230, label: true, series: [{ key: 'received_cents', color: '--c-main2', cents: true }],
      tip: m => [['Recebido', whole(m.received_cents, true), '--c-main2']] },
    cancel: { height: 200, label: true, series: [{ key: 'left', color: '--c-out' }],
      tip: m => [['Saíram', whole(m.left), '--c-out'], ['Taxa de cancelamento', percent(m.churn), '--c-gray'], ['Receita perdida', `${whole(m.lost_cents, true)}/mês`, '--c-out'], ...(m.replaced ? [['Continuaram com outra assinatura', whole(m.replaced), '--c-muted']] : [])] },
  };
  function monthChart(host, config) {
    const months = data.months || [];
    if (!months.length) return;
    if (months.every(m => config.series.every(s => !m[s.key]))) {
      const empty = document.createElement('p'); empty.className = 'analytics-empty'; empty.textContent = `Nada para mostrar de ${months[0].month} a ${months.at(-1).month}.`;
      host.classList.add('is-empty'); host.replaceChildren(empty); return;
    }
    const color = name => getComputedStyle(host).getPropertyValue(name).trim();
    let drawnWidth = 0, hideTip = () => {};
    const draw = () => {
      const W = Math.round(host.clientWidth);
      if (!W || W === drawnWidth) return;
      drawnWidth = W;
      const H = config.height, left = 48, right = 16, top = 22, bottom = 28, plotH = H - top - bottom;
      const up = config.series.filter(s => !s.down), down = config.series.filter(s => s.down);
      const cents = Boolean(up[0].cents);
      const maxUp = niceMax(Math.max(0, ...months.flatMap(m => up.map(s => m[s.key]))));
      const maxDown = down.length ? niceMax(Math.max(0, ...months.flatMap(m => down.map(s => m[s.key])))) : 0;
      const zero = top + plotH * maxUp / (maxUp + maxDown);
      const yUp = v => zero - v * (zero - top) / maxUp;
      const yDown = v => zero + v * (top + plotH - zero) / (maxDown || 1);
      const band = (W - left - right) / months.length;
      const cx = i => left + band * (i + 0.5);
      const svg = make('svg', { viewBox: `0 0 ${W} ${H}`, width: W, height: H, 'aria-hidden': 'true', focusable: 'false' });
      // Três linhas finas de grade, com o valor à esquerda; a do zero é a base.
      [[maxUp, yUp(maxUp)], [maxUp / 2, yUp(maxUp / 2)], [0, zero], ...(down.length ? [[-maxDown, yDown(maxDown)]] : [])].forEach(([value, y]) => {
        svg.append(make('line', { x1: left, x2: W - right, y1: y, y2: y, class: value === 0 ? 'dash-axis' : 'dash-grid' }));
        svg.append(make('text', { x: left - 8, y: y + 4, 'text-anchor': 'end', class: 'dash-tick' }, value === 0 ? '0' : `${value < 0 ? '−' : ''}${short(Math.abs(value), cents)}`));
      });
      // Meses: no celular, só alguns, sempre com o último.
      const every = W < 560 ? Math.ceil(months.length / 5) : 1;
      months.forEach((m, i) => { if ((months.length - 1 - i) % every === 0) svg.append(make('text', { x: cx(i), y: H - 8, 'text-anchor': 'middle', class: 'dash-tick' }, m.month)); });
      const marks = [];
      if (config.area) {
        const s = up[0], points = months.map((m, i) => [cx(i), yUp(m[s.key])]);
        const id = `area-${Math.random().toString(36).slice(2)}`;
        const gradient = make('linearGradient', { id, x1: 0, y1: 0, x2: 0, y2: 1 });
        gradient.append(make('stop', { offset: '0', 'stop-color': color(s.color), 'stop-opacity': '0.30' }), make('stop', { offset: '1', 'stop-color': color(s.color), 'stop-opacity': '0.02' }));
        const defs = make('defs'); defs.append(gradient); svg.append(defs);
        const line = points.map((p, i) => `${i ? 'L' : 'M'}${p[0].toFixed(1)},${p[1].toFixed(1)}`).join('');
        svg.append(make('path', { d: `${line}L${points.at(-1)[0].toFixed(1)},${zero}L${points[0][0].toFixed(1)},${zero}Z`, fill: `url(#${id})` }));
        svg.append(make('path', { d: line, fill: 'none', stroke: color(s.color), 'stroke-width': 2.5, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }));
        const last = points.at(-1);
        svg.append(make('circle', { cx: last[0], cy: last[1], r: 5, fill: color(s.color), stroke: '#fff', 'stroke-width': 2 }));
        svg.append(make('text', { x: last[0] - 10, y: last[1] - 12, 'text-anchor': 'end', class: 'dash-value' }, whole(months.at(-1)[s.key], s.cents)));
      } else {
        const w = Math.max(4, Math.min(30, band * 0.62));
        months.forEach((m, i) => {
          const x = cx(i) - w / 2;
          up.forEach(s => { const y = yUp(m[s.key]); marks.push(make('path', { d: bar(x, y, w, zero - y, true), fill: color(s.color), class: 'dash-bar', 'data-i': i })); });
          down.forEach(s => { marks.push(make('path', { d: bar(x, zero, w, yDown(m[s.key]) - zero, false), fill: color(s.color), class: 'dash-bar', 'data-i': i })); });
        });
        svg.append(...marks);
        // Rótulo direto só no maior mês; o resto fica na dica e na tabela.
        if (config.label) {
          const s = up[0], values = months.map(m => m[s.key]), top3 = values.indexOf(Math.max(...values));
          if (values[top3] > 0) svg.append(make('text', { x: top3 === months.length - 1 ? cx(top3) + w / 2 : cx(top3), y: yUp(values[top3]) - 7, 'text-anchor': top3 === months.length - 1 ? 'end' : 'middle', class: 'dash-value' }, short(values[top3], s.cents)));
        }
      }
      const cross = make('line', { y1: top, y2: top + plotH, class: 'dash-cross', visibility: 'hidden' });
      const dot = make('circle', { r: 5, fill: color(up[0].color), stroke: '#fff', 'stroke-width': 2, visibility: 'hidden' });
      svg.append(cross, dot);
      const tip = document.createElement('div'); tip.className = 'dash-tip'; tip.hidden = true;
      const hide = () => { tip.hidden = true; cross.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); marks.forEach(mark => mark.classList.remove('is-dim')); };
      months.forEach((m, i) => {
        const hit = make('rect', { x: left + band * i, y: 0, width: band, height: H, fill: 'transparent' });
        const show = () => {
          cross.setAttribute('x1', cx(i)); cross.setAttribute('x2', cx(i)); cross.setAttribute('visibility', 'visible');
          if (config.area) { dot.setAttribute('cx', cx(i)); dot.setAttribute('cy', yUp(m[up[0].key])); dot.setAttribute('visibility', 'visible'); }
          marks.forEach(mark => mark.classList.toggle('is-dim', mark.dataset.i !== String(i)));
          const title = document.createElement('strong'); title.textContent = m.month;
          tip.replaceChildren(title, ...config.tip(m).map(([label, value, swatch]) => {
            const row = document.createElement('div'), mark = document.createElement('i'), name = document.createElement('span'), number = document.createElement('b');
            mark.style.background = color(swatch); name.textContent = label; number.textContent = value; row.append(mark, name, number); return row;
          }));
          tip.hidden = false;
          const x = cx(i) + 14 + tip.offsetWidth > W ? cx(i) - 14 - tip.offsetWidth : cx(i) + 14;
          tip.style.left = `${Math.max(0, x)}px`; tip.style.top = `${top}px`;
        };
        hit.addEventListener('pointerenter', show); hit.addEventListener('pointerdown', show);
        svg.append(hit);
      });
      // No toque, a dica fica até tocar fora do gráfico.
      svg.addEventListener('pointerleave', event => { if (event.pointerType !== 'touch') hide(); });
      hideTip = hide;
      host.replaceChildren(svg, tip);
    };
    draw();
    document.addEventListener('pointerdown', event => { if (!host.contains(event.target)) hideTip(); });
    if (window.ResizeObserver) new ResizeObserver(draw).observe(host); else window.addEventListener('resize', draw);
  }
  document.querySelectorAll('[data-chart]').forEach(host => { if (monthCharts[host.dataset.chart]) monthChart(host, monthCharts[host.dataset.chart]); });
  const mapPalette = ['#b9daee', '#76b4da', '#358ac0', '#08649a', '#14264b'];
  function mapScale(max) {
    const steps = Math.min(5, Math.max(0, max));
    return Array.from({ length: steps }, (_, i) => ({
      min: i ? Math.ceil(i * max / steps) + 1 : 1,
      max: Math.ceil((i + 1) * max / steps),
      color: mapPalette[steps === 1 ? 0 : Math.round(i * 4 / (steps - 1))],
    }));
  }
  function mapLegend(host, ranges) {
    const legend = host.parentElement.querySelector('[data-map-legend]');
    if (!legend) return;
    const heading = document.createElement('strong'); heading.textContent = 'Planos ativos';
    const items = [{ color: '#e3e8eb', label: '0' }, ...ranges.map(range => ({
      color: range.color, label: range.min === range.max ? String(range.min) : `${range.min}\u2013${range.max}`,
    }))].map(item => {
      const entry = document.createElement('span');
      const swatch = document.createElement('i'); swatch.style.backgroundColor = item.color;
      entry.append(swatch, document.createTextNode(item.label)); return entry;
    });
    legend.replaceChildren(heading, ...items);
  }
  function rings(geometry) {
    if (geometry.type === 'Polygon') return geometry.coordinates;
    if (geometry.type === 'MultiPolygon') return geometry.coordinates.flat();
    return [];
  }
  const pointsOf = geometry => geometry.type === 'Point' ? [geometry.coordinates] : rings(geometry).flat();
  const extent = coords => coords.reduce((box, p) => [Math.min(box[0], p[0]), Math.min(box[1], p[1]), Math.max(box[2], p[0]), Math.max(box[3], p[1])], [Infinity, Infinity, -Infinity, -Infinity]);
  function bounds(features) {
    const coords = features.flatMap(f => pointsOf(f.geometry));
    return extent(coords);
  }
  const mercator = ([lon, lat]) => [lon * Math.PI / 180, -Math.log(Math.tan(Math.PI / 4 + lat * Math.PI / 360))];
  function renderMap(host, features, info, selected, onSelect, labels = false) {
    const coords = features.flatMap(f => pointsOf(f.geometry)).map(mercator);
    if (!coords.length) throw new Error('A malha não contém geometrias.');
    const [minX, minY, maxX, maxY] = extent(coords);
    const w = 640, pad = 18;
    // A altura segue o formato do mapa: ele ocupa a largura no celular sem sobrar faixa vazia.
    const h = Math.round(Math.min(520, Math.max(300, (w - pad * 2) * (maxY - minY) / Math.max(.00001, maxX - minX) + pad * 2)));
    const scale = Math.min((w - pad * 2) / Math.max(.00001, maxX - minX), (h - pad * 2) / Math.max(.00001, maxY - minY));
    const ox = (w - (maxX - minX) * scale) / 2, oy = (h - (maxY - minY) * scale) / 2;
    const project = point => { const [x, y] = mercator(point); return [(x - minX) * scale + ox, (y - minY) * scale + oy]; };
    const svg = make('svg', { viewBox: `0 0 ${w} ${h}`, role: 'group' });
    const tooltip = document.createElement('div'); tooltip.className = 'analytics-map-tooltip'; tooltip.hidden = true;
    const max = Math.max(0, ...features.map(f => info(f).plans));
    const ranges = mapScale(max);
    mapLegend(host, ranges);
    const names = []; // nomes por último, para nenhum bairro desenhado depois cobrir o texto
    let dragged = false; // arrastar o mapa não conta como clique no bairro
    features.forEach(feature => {
      const item = info(feature);
      const d = rings(feature.geometry).map(ring => ring.map((p, i) => `${i ? 'L' : 'M'}${project(p).map(n => n.toFixed(2)).join(',')}`).join(' ') + ' Z').join(' ');
      const title = `${item.name}: ${item.customers} tutor(es), ${item.plans} plano(s) ativo(s)`;
      let attrs = { d, fill: ranges.find(range => item.plans >= range.min && item.plans <= range.max)?.color || '#e3e8eb', 'fill-rule': 'evenodd', tabindex: 0, role: 'button', 'aria-label': title, 'aria-pressed': String(selected(item)), class: selected(item) ? 'is-selected' : '' };
      const isPoint = feature.geometry.type === 'Point';
      if (isPoint) { const [cx, cy] = project(feature.geometry.coordinates); attrs = { ...attrs, cx, cy, r: item.plans ? 6 + 14 * Math.sqrt(item.plans / Math.max(1, max)) : 4, stroke: '#fff', 'stroke-width': 1.5 }; delete attrs.d; }
      const path = make(isPoint ? 'circle' : 'path', attrs);
      path.append(make('title', {}, title));
      const show = () => { tooltip.textContent = title; tooltip.hidden = false; };
      path.addEventListener('mouseenter', show); path.addEventListener('focus', show);
      path.addEventListener('mouseleave', () => { tooltip.hidden = true; }); path.addEventListener('blur', () => { tooltip.hidden = true; });
      path.addEventListener('click', () => { if (!dragged) onSelect(item); });
      path.addEventListener('keydown', e => { if (['Enter', ' '].includes(e.key)) { e.preventDefault(); onSelect(item); } });
      svg.append(path);
      if (labels === 'plans' ? item.label && item.plans > 0 : labels && item.label && (!isPoint || item.customers)) {
        const box = bounds([feature]); const pos = project([(box[0] + box[2]) / 2, (box[1] + box[3]) / 2]);
        names.push(make('text', { x: pos[0], y: pos[1], 'text-anchor': 'middle', 'font-size': 10, 'data-plans': item.plans }, item.plans ? `${item.label} (${item.plans})` : item.label));
      }
    });
    svg.append(...names);
    // Zoom (roda do mouse, pinça ou + e −) e arrasto mexem só no viewBox.
    const controls = document.createElement('div'); controls.className = 'analytics-map-zoom';
    const button = (action, label, text) => { const el = document.createElement('button'); el.type = 'button'; el.dataset.mapZoom = action; el.title = label; el.setAttribute('aria-label', label); el.textContent = text; controls.append(el); return el; };
    const zoomIn = button('in', 'Aproximar', '+'), zoomOut = button('out', 'Afastar', '−'), reset = button('reset', 'Ver o mapa todo', 'Ver tudo');
    host.replaceChildren(svg, tooltip, controls);
    const minWidth = w / 12;
    let view = [0, 0, w, h];
    // Nomes com cerca de 12px na tela em qualquer zoom. Só aparecem os que cabem sem encostar em outro, do lugar com mais planos
    // para o com menos: no mapa inteiro ficam os principais, e aproximar revela os demais.
    const order = names.sort((a, b) => b.dataset.plans - a.dataset.plans);
    const layout = () => {
      if (!svg.getBoundingClientRect().width) return; // aba escondida: mede quando ela abrir
      const px =1 / (svg.getScreenCTM()?.a || 1); // unidades do desenho por pixel da tela
      order.forEach(name => { name.style.fontSize = `${(13 * px).toFixed(3)}px`; name.style.strokeWidth = (3.5 * px).toFixed(3); name.style.display = ''; });
      // Os botões de zoom contam como um nome já colocado, e a borda guarda uma margem: nenhum nome fica cortado ou escondido.
      const c = controls.getBoundingClientRect(), [cx, cy] = toMap(c.left, c.top);
      const gap = 4 * px, edge = 6 * px, placed = [{ x: cx, y: cy, width: c.width * px, height: c.height * px }];
      order.map(name => [name, name.getBBox()]).forEach(([name, b]) => {
        const inside = b.x >= view[0] + edge && b.y >= view[1] + edge && b.x + b.width <= view[0] + view[2] - edge && b.y + b.height <= view[1] + view[3] - edge;
        const free = !placed.some(p => b.x < p.x + p.width + gap && p.x < b.x + b.width + gap && b.y < p.y + p.height + gap && p.y < b.y + b.height + gap);
        if (inside && free) placed.push(b); else name.style.display = 'none';
      });
    };
    let queued = false;
    const relayout = () => { if (!queued) { queued = true; requestAnimationFrame(() => { queued = false; layout(); }); } };
    const setView = (x, y, vw) => {
      vw = Math.min(w, Math.max(minWidth, vw)); const vh = vw * h / w;
      view = [Math.min(Math.max(x, 0), w - vw), Math.min(Math.max(y, 0), h - vh), vw, vh];
      svg.setAttribute('viewBox', view.map(n => n.toFixed(2)).join(' '));
      const zoomed = vw < w;
      svg.classList.toggle('is-zoomed', zoomed);
      svg.style.touchAction = zoomed ? 'none' : 'pan-y'; // sem zoom, o dedo continua rolando a página
      zoomOut.disabled = !zoomed; reset.hidden = !zoomed; zoomIn.disabled = vw <= minWidth;
      relayout();
    };
    const zoomAt = (factor, cx, cy) => { const vw = Math.min(w, Math.max(minWidth, view[2] / factor)), k = vw / view[2]; setView(cx - (cx - view[0]) * k, cy - (cy - view[1]) * k, vw); };
    const toMap = (clientX, clientY) => { const p = new DOMPoint(clientX, clientY).matrixTransform(svg.getScreenCTM().inverse()); return [p.x, p.y]; };
    const center = () => [view[0] + view[2] / 2, view[1] + view[3] / 2];
    zoomIn.addEventListener('click', () => zoomAt(1.6, ...center()));
    zoomOut.addEventListener('click', () => zoomAt(1 / 1.6, ...center()));
    reset.addEventListener('click', () => setView(0, 0, w));
    svg.addEventListener('wheel', event => {
      if (event.deltaY > 0 && view[2] >= w) return; // mapa inteiro: rolar para baixo continua descendo a página
      event.preventDefault();
      zoomAt(Math.exp(-Math.max(-100, Math.min(100, event.deltaY)) * 0.003), ...toMap(event.clientX, event.clientY));
    }, { passive: false });
    const pointers = new Map();
    svg.addEventListener('pointerdown', event => {
      if (event.pointerType === 'mouse' && event.button !== 0) return;
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY, x0: event.clientX, y0: event.clientY });
      dragged = false;
    });
    window.addEventListener('pointermove', event => {
      const last = pointers.get(event.pointerId);
      if (!last) return;
      const other = [...pointers.entries()].find(([id]) => id !== event.pointerId)?.[1];
      if (other) { // pinça: aproxima ou afasta em volta do meio dos dois dedos
        const before = Math.hypot(last.x - other.x, last.y - other.y), after = Math.hypot(event.clientX - other.x, event.clientY - other.y);
        if (before > 0) zoomAt(after / before, ...toMap((event.clientX + other.x) / 2, (event.clientY + other.y) / 2));
      } else if (view[2] < w) {
        const k = 1 / svg.getScreenCTM().a;
        setView(view[0] - (event.clientX - last.x) * k, view[1] - (event.clientY - last.y) * k, view[2]);
      }
      if (other || Math.hypot(event.clientX - last.x0, event.clientY - last.y0) > 4) dragged = true;
      last.x = event.clientX; last.y = event.clientY;
    });
    const release = event => pointers.delete(event.pointerId);
    window.addEventListener('pointerup', release); window.addEventListener('pointercancel', release);
    // Mede de novo quando o mapa ganha o tamanho final (aba aberta, tela girada): a primeira medida pode sair antes disso.
    if (window.ResizeObserver) new ResizeObserver(relayout).observe(svg); else window.addEventListener('resize', relayout);
    setView(0, 0, w);
    layout();
    document.fonts?.ready.then(relayout); // a fonte do site deixa os nomes mais largos: mede de novo quando ela chega
  }
  async function json(url, cache = 'default') { const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache }); if (!response.ok) throw new Error('Mapa temporariamente indisponível. Consulte os números ao lado.'); return response.json(); }
  function fail(host, error) { const p = document.createElement('p'); p.className = 'analytics-loading'; p.textContent = error.message; host.replaceChildren(p); }
  const region = document.getElementById('dashboard-region-map');
  let regionalLoaded = false;
  let neighborhoodsLoaded = false;
  async function loadNeighborhoods() {
    const host = document.getElementById('dashboard-neighborhood-map');
    // Sem cidade no filtro, abre nos bairros da cidade com mais planos (Presidente Prudente).
    const top = [...data.geo.cities].sort((a, b) => b.plans - a.plans)[0];
    const city = data.city || top?.city || '';
    const state = data.city ? data.state : (top?.state || data.state);
    if (!city) { fail(host, new Error('Ainda não há planos ativos com cidade para mostrar os bairros.')); return; }
    if (neighborhoodsLoaded) return;
    neighborhoodsLoaded = true;
    try {
      const catalog = await json(`${data.assets}/municipios.json`);
      const code = Object.keys(catalog).find(key => data.states[catalog[key].state] === state && norm(catalog[key].name) === norm(city));
      if (!code) throw new Error('Cidade não localizada na malha. Confira a grafia no cadastro; o ranking de bairros permanece disponível.');
      // O servidor manda guardar por 1 dia; "no-cache" faz o navegador conferir antes, para um mapa novo de bairros aparecer na hora.
      const geo = await json(`${data.neighborhoodUrl}/${code}`, 'no-cache');
      if (!geo.features.length) throw new Error(geo.message || 'Ainda sem base cartográfica de bairros para esta cidade. Consulte o ranking abaixo.');
      // Bairro digitado sem o tipo ("Humberto Salvador") cai no do mapa com o tipo ("Jardim Humberto Salvador"), se só um servir;
      // tipos diferentes ("Vila" x "Parque Furquim") não se misturam. A mesma regra está em DashboardService::sameNeighborhood.
      const full = v => norm(v).replace(/^jd\.?\s+/, 'jardim ').replace(/^vl\.?\s+/, 'vila ').replace(/^pq\.?\s+/, 'parque ').replace(/^res\.?\s+/, 'residencial ').replace(/^(cj|conj)\.?\s+/, 'conjunto ');
      const bare = v => full(v).replace(/^(jardim|vila|parque|residencial|conjunto habitacional|conjunto|nucleo habitacional|nucleo|chacaras?|loteamento|condominio|bairro)\s+/, '');
      const featureOf = name => {
        const exact = geo.features.find(f => full(f.properties.name) === full(name));
        if (exact) return exact.properties.name;
        const found = geo.features.filter(f => bare(f.properties.name) === bare(name) && (bare(name) === full(name) || bare(f.properties.name) === full(f.properties.name)));
        return found.length === 1 ? found[0].properties.name : null;
      };
      const counts = new Map();
      const local = data.geo.neighborhoods.filter(n => n.state === state && norm(n.city) === norm(city));
      local.forEach(n => {
        const feature = featureOf(n.name);
        if (!feature) return;
        const sum = counts.get(feature) || { customers: 0, plans: 0 };
        counts.set(feature, { customers: sum.customers + n.customers, plans: sum.plans + n.plans });
      });
      renderMap(host, geo.features, f => ({ ...(counts.get(f.properties.name) || { customers: 0, plans: 0 }), name: f.properties.name, label: f.properties.name }),
        item => Boolean(data.neighborhood) && featureOf(data.neighborhood) === item.name, item => filter(state, city, item.name), geo.features.length < 30 ? true : 'plans');
      const uncovered = local.filter(n => !featureOf(n.name)).reduce((sum, n) => sum + n.plans, 0);
      const note = document.createElement('p'); note.className = 'analytics-note';
      note.textContent = `${geo.approximate ? 'Divisão aproximada dos bairros (área urbana do IBGE e bairros do OpenStreetMap).' : geo.features[0].geometry.type === 'Point' ? 'Pontos de referência dos bairros.' : ''} ${uncovered ? `${uncovered} plano(s) fora da cobertura do mapa.` : ''}`.trim();
      if (note.textContent) host.after(note);
    } catch (error) { fail(host, error); neighborhoodsLoaded = false; }
  }
  async function loadRegional() {
    if (regionalLoaded) return;
    regionalLoaded = true;
    try {
      // As 28 cidades da região imediata de Prudente (IBGE) entram sempre, em cinza quando sem plano: Prudente sozinha deixava o quadro vazio.
      const nearby = await json(`${data.assets}/municipios-prudente.json`).catch(() => ({}));
      const cities = [...data.geo.map_cities];
      Object.entries(nearby).forEach(([code, city]) => { if (!cities.some(c => c.code === code)) cities.push({ code, name: city.name, state: data.states[city.state] }); });
      const allowed = new Set(cities.map(city => city.code));
      const states = [...new Set(cities.map(city => city.state))];
      const results = await Promise.allSettled(states.map(state => json(`${data.mapUrl}/${encodeURIComponent(state)}`)));
      const features = results.filter(result => result.status === 'fulfilled').flatMap(result => result.value.features).filter(feature => allowed.has(String(feature.properties.codarea || feature.id)));
      if (!features.length) throw new Error('Mapa temporariamente indisponível. Consulte os números ao lado.');
      renderMap(region, features, f => {
        const entry = cities.find(city => city.code === String(f.properties.codarea || f.id));
        const count = data.geo.cities.find(c => c.state === entry.state && norm(c.city) === norm(entry.name)) || { customers: 0, plans: 0 };
        return { ...count, name: entry.name, state: entry.state, label: entry.name };
      }, item => norm(item.name) === norm(data.city) && item.state === data.state, item => filter(item.state, item.name), features.length < 14 ? true : 'plans');
      if (results.some(result => result.status === 'rejected')) {
        const note = document.createElement('p'); note.className = 'analytics-note'; note.textContent = 'Algumas cidades estão com a cartografia indisponível no momento. Seus totais permanecem nos rankings.'; region.after(note);
      }
    } catch (error) { fail(region, error); regionalLoaded = false; }
  }
  function selectTab(name) {
    document.querySelectorAll('[data-map-tab]').forEach(button => {
      button.setAttribute('aria-selected', String(button.dataset.mapTab === name));
      button.setAttribute('tabindex', button.dataset.mapTab === name ? '0' : '-1');
    });
    document.getElementById('map-regional-panel').hidden = name !== 'regional';
    document.getElementById('map-bairros-panel').hidden = name !== 'bairros';
    if (name === 'regional') loadRegional();
    if (name === 'bairros') loadNeighborhoods();
  }
  document.querySelectorAll('[data-map-tab]').forEach(button => {
    button.addEventListener('click', () => selectTab(button.dataset.mapTab));
    button.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault(); const buttons = [...document.querySelectorAll('[data-map-tab]')];
      const index = buttons.indexOf(button);
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
      buttons[next].focus(); selectTab(buttons[next].dataset.mapTab);
    });
  });
  if (data.city) selectTab('bairros');
  else selectTab('regional');
})();
