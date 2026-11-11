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
    const w = 640, h = 420, pad = 18;
    const scale = Math.min((w - pad * 2) / Math.max(.00001, maxX - minX), (h - pad * 2) / Math.max(.00001, maxY - minY));
    const ox = (w - (maxX - minX) * scale) / 2, oy = (h - (maxY - minY) * scale) / 2;
    const project = point => { const [x, y] = mercator(point); return [(x - minX) * scale + ox, (y - minY) * scale + oy]; };
    const svg = make('svg', { viewBox: `0 0 ${w} ${h}`, role: 'group' });
    const tooltip = document.createElement('div'); tooltip.className = 'analytics-map-tooltip'; tooltip.hidden = true;
    const max = Math.max(0, ...features.map(f => info(f).plans));
    const ranges = mapScale(max);
    mapLegend(host, ranges);
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
      path.addEventListener('click', () => onSelect(item));
      path.addEventListener('keydown', e => { if (['Enter', ' '].includes(e.key)) { e.preventDefault(); onSelect(item); } });
      svg.append(path);
      if (labels && item.label && (!isPoint || item.customers)) {
        const box = bounds([feature]); const pos = project([(box[0] + box[2]) / 2, (box[1] + box[3]) / 2]);
        svg.append(make('text', { x: pos[0], y: pos[1], 'text-anchor': 'middle', 'font-size': 10 }, item.plans ? `${item.label} (${item.plans})` : item.label));
      }
    });
    host.replaceChildren(svg, tooltip);
  }
  async function json(url) { const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }); if (!response.ok) throw new Error('Mapa temporariamente indisponível. Consulte os números ao lado.'); return response.json(); }
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
      const geo = await json(`${data.neighborhoodUrl}/${code}`);
      if (!geo.features.length) throw new Error(geo.message || 'Ainda sem base cartográfica de bairros para esta cidade. Consulte o ranking abaixo.');
      renderMap(host, geo.features, f => {
        const name = f.properties.name;
        const count = data.geo.neighborhoods.find(n => n.state === state && norm(n.city) === norm(city) && norm(n.name) === norm(name)) || { customers: 0, plans: 0 };
        return { ...count, name, label: name };
      }, item => norm(item.name) === norm(data.neighborhood), item => filter(state, city, item.name), geo.features.length < 30);
      const matched = new Set(geo.features.map(f => norm(f.properties.name)));
      const uncovered = data.geo.neighborhoods.filter(n => norm(n.city) === norm(city) && n.state === state && !matched.has(norm(n.name))).reduce((sum, n) => sum + n.plans, 0);
      const note = document.createElement('p'); note.className = 'analytics-note';
      note.textContent = `${geo.features[0].geometry.type === 'Point' ? 'Pontos de referência dos bairros.' : ''} ${uncovered ? `${uncovered} plano(s) fora da cobertura do mapa.` : ''}`.trim();
      if (note.textContent) host.after(note);
    } catch (error) { fail(host, error); neighborhoodsLoaded = false; }
  }
  async function loadRegional() {
    if (regionalLoaded) return;
    regionalLoaded = true;
    try {
      const cities = data.geo.map_cities;
      const allowed = new Set(cities.map(city => city.code));
      const states = [...new Set(cities.map(city => city.state))];
      const results = await Promise.allSettled(states.map(state => json(`${data.mapUrl}/${encodeURIComponent(state)}`)));
      const features = results.filter(result => result.status === 'fulfilled').flatMap(result => result.value.features).filter(feature => allowed.has(String(feature.properties.codarea || feature.id)));
      if (!features.length) throw new Error('Mapa temporariamente indisponível. Consulte os números ao lado.');
      renderMap(region, features, f => {
        const entry = cities.find(city => city.code === String(f.properties.codarea || f.id));
        const count = data.geo.cities.find(c => c.state === entry.state && norm(c.city) === norm(entry.name)) || { customers: 0, plans: 0 };
        return { ...count, name: entry.name, state: entry.state, label: features.length < 14 ? entry.name : '' };
      }, item => norm(item.name) === norm(data.city) && item.state === data.state, item => filter(item.state, item.name), features.length < 14);
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
