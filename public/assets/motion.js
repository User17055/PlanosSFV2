'use strict';
/* São Francisco · movimento do site público e da área autenticada (GSAP + ScrollTrigger + SplitText).
   O conteúdo começa oculto apenas enquanto html.motion-pending existir; o layout remove a classe
   sozinho após 3 s se este arquivo não rodar, então nada fica invisível sem JavaScript.
   O "tremido" ao trocar de página vinha das fontes baixadas de novo a cada página (servidor sem cache),
   não das animações: ver server.php e docs/ops/nginx.conf. */
(() => {
  const root = document.documentElement;
  const reveal = () => root.classList.remove('motion-pending');
  const publicPage = document.body.classList.contains('public-body');
  const { gsap, ScrollTrigger, SplitText } = window;
  if (!gsap) { reveal(); return; }
  gsap.registerPlugin(...[ScrollTrigger, SplitText].filter(Boolean));
  // Em aparelho lento, as animações terminam no tempo real em vez de "esperar" o processador:
  // podem perder fluidez, mas nenhum conteúdo fica invisível por mais tempo que o previsto.
  gsap.ticker.lagSmoothing(0);

  const $ = (selector, parent = document) => (parent ? parent.querySelector(selector) : null);
  const $$ = (selector, parent = document) => (parent ? [...parent.querySelectorAll(selector)] : []);
  const pick = (...targets) => targets.flat().filter(Boolean);
  const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const finePointer = matchMedia('(hover: hover) and (pointer: fine)').matches;
  const entrance = root.classList.contains('motion-pending') && !reduceMotion;
  const CLEAR = 'transform,opacity,visibility';

  // Ícones que vêm depois do texto (setas) deslizam no hover; ícones iniciais (+) ficam parados.
  $$('.button .icon, .text-link .icon').forEach(icon => {
    let previous = icon.previousSibling;
    while (previous && previous.nodeType === Node.TEXT_NODE && !previous.textContent.trim()) previous = previous.previousSibling;
    if (previous) icon.classList.add('is-trailing');
  });

  // Cabeçalho público compacto ao rolar.
  const publicHeader = $('.public-header');
  if (publicHeader && ScrollTrigger) ScrollTrigger.create({ start: 12, end: 'max', toggleClass: { targets: publicHeader, className: 'is-scrolled' } });

  if (reduceMotion) { reveal(); return; }

  // Guide the next action without moving the page or resizing the controls.
  document.addEventListener('address:filled', event => {
    const number = event.target.elements?.namedItem('number');
    if(!number || number.value.trim())return;
    const field = number.closest('.field');
    if(field)gsap.fromTo(field,{opacity:.55},{opacity:1,duration:.45,repeat:1,yoyo:true,onComplete:()=>gsap.set(field,{clearProps:'opacity'})});
  });
  document.addEventListener('input', event => { if(event.target.name==='number' && event.target.value.trim())event.target.closest('.field')?.classList.remove('is-next-field'); });

  /* ---------- utilidades ---------- */
  const floatLoop = (element, { y = 8, rotation = 0, duration = 3.2, delay = 0 } = {}) => element && gsap.to(element, {
    y: `-=${y}`, rotation: `+=${rotation}`, duration, delay, ease: 'sine.inOut', yoyo: true, repeat: -1,
  });

  // Tudo o que espera para aparecer fica registrado aqui. Se a pessoa pular para o fim da página,
  // usar uma âncora, navegar pelo teclado ou imprimir, o conteúdo é mostrado na hora.
  const waiting = new Set();
  const showNow = (targets, { stagger = 0, delay = 0, duration = .5 } = {}) => {
    targets = targets.filter(target => waiting.delete(target));
    if (targets.length) gsap.to(targets, { autoAlpha: 1, y: 0, scale: 1, stagger, delay, duration, ease: 'power3.out', overwrite: true, clearProps: CLEAR });
  };
  const showAll = () => showNow([...waiting]);

  const revealOnScroll = (targets, { y = 28, scale = 1, stagger = .08, delay = 0 } = {}) => {
    targets = targets.filter(Boolean);
    if (!targets.length) return;
    gsap.set(targets, { autoAlpha: 0, y, scale });
    targets.forEach(target => waiting.add(target));
    if (!ScrollTrigger) { showNow(targets, { stagger, delay, duration: .8 }); return; }
    let first = true;
    ScrollTrigger.batch(targets, {
      start: 'top 96%', once: true,
      onEnter: batch => { showNow(batch, { stagger, delay: first ? delay : 0, duration: .85 }); first = false; },
    });
  };

  const countUp = element => {
    const original = element.textContent.trim();
    const match = original.match(/\d[\d.]*(?:,\d+)?/);
    if (!match) return;
    const decimals = (match[0].split(',')[1] || '').length;
    const value = parseFloat(match[0].replace(/\./g, '').replace(',', '.'));
    if (!Number.isFinite(value) || value === 0) return;
    const prefix = original.slice(0, match.index), suffix = original.slice(match.index + match[0].length);
    const format = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    const state = { value: 0 };
    element.textContent = prefix + format.format(0) + suffix;
    gsap.to(state, {
      value, duration: 1.6, delay: .2, ease: 'power3.out',
      onUpdate: () => { element.textContent = prefix + format.format(state.value) + suffix; },
      onComplete: () => { element.textContent = original; },
    });
  };

  const onVisible = (element, callback) => {
    if (!ScrollTrigger) { callback(); return; }
    ScrollTrigger.create({ trigger: element, start: 'top 95%', once: true, onEnter: callback });
  };

  const splitWords = element => {
    if (!element) return [];
    if (!SplitText) return [element];
    return SplitText.create(element, { type: 'words' }).words;
  };

  /* ---------- microinterações globais ---------- */
  document.addEventListener('pointerdown', event => {
    const button = event.target.closest('.button');
    if (!button || button.disabled) return;
    const bounds = button.getBoundingClientRect(), size = Math.max(bounds.width, bounds.height) * 2.2;
    const ripple = document.createElement('span');
    ripple.className = 'button-ripple';
    ripple.setAttribute('aria-hidden', 'true');
    Object.assign(ripple.style, { width: `${size}px`, height: `${size}px`, left: `${event.clientX - bounds.left - size / 2}px`, top: `${event.clientY - bounds.top - size / 2}px` });
    button.append(ripple);
    gsap.fromTo(ripple, { scale: 0, opacity: .22 }, { scale: 1, opacity: 0, duration: .75, ease: 'power2.out', onComplete: () => ripple.remove() });
  });

  document.addEventListener('toggle', event => {
    const details = event.target;
    if (details.tagName !== 'DETAILS' || !details.open) return;
    gsap.from([...details.children].filter(child => child.tagName !== 'SUMMARY'), { autoAlpha: 0, y: -8, duration: .45, ease: 'power2.out', clearProps: CLEAR });
  }, true);

  // Assistente de contratação: cada etapa entra deslizando, e o marcador da etapa "salta".
  $$('[data-step]').forEach(panel => new MutationObserver(() => {
    if (panel.hidden) return;
    gsap.fromTo([...panel.children], { autoAlpha: 0, x: 26 }, { autoAlpha: 1, x: 0, duration: .55, stagger: .05, ease: 'power3.out', clearProps: CLEAR });
  }).observe(panel, { attributes: true, attributeFilter: ['hidden'] }));
  $$('[data-step-indicator]').forEach(step => new MutationObserver(() => {
    if (step.classList.contains('active')) gsap.fromTo(step.firstElementChild, { opacity: .45 }, { opacity: 1, duration: .35, clearProps: 'opacity' });
  }).observe(step, { attributes: true, attributeFilter: ['class'] }));

  // Menu lateral no celular: itens entram em cascata ao abrir.
  const sidebar = $('#sidebar');
  if (sidebar) new MutationObserver(() => {
    if (sidebar.classList.contains('is-open')) gsap.fromTo($$('.nav-link', sidebar), { autoAlpha: 0, x: -14 }, { autoAlpha: 1, x: 0, duration: .4, stagger: .03, ease: 'power2.out', clearProps: CLEAR });
  }).observe(sidebar, { attributes: true, attributeFilter: ['class'] });

  // Cartões de produto ficam parados; quem reage é o botão: a seta dá a volta ao passar pelo cartão
  // e o botão acompanha levemente o cursor. O brilho que atravessa o botão é feito no CSS.
  const arrowLoop = arrow => {
    if (!arrow || gsap.isTweening(arrow)) return;
    gsap.timeline()
      .to(arrow, { x: 16, autoAlpha: 0, duration: .2, ease: 'power2.in' })
      .set(arrow, { x: -14 })
      .to(arrow, { x: 0, autoAlpha: 1, duration: .38, ease: 'power3.out', clearProps: CLEAR });
  };
  $$('.product-card').forEach(card => {
    const button = $('.button', card);
    if (button) card.addEventListener('pointerenter', () => arrowLoop($('.icon.is-trailing', button)));
  });
  if (finePointer) $$('.product-card .button, .sales-hero .button').forEach(button => {
    const [pullX, pullY] = button.closest('.product-card') ? [.06, .22] : [.22, .3];
    const moveX = gsap.quickTo(button, 'x', { duration: .45, ease: 'power3.out' });
    const moveY = gsap.quickTo(button, 'y', { duration: .45, ease: 'power3.out' });
    button.addEventListener('pointermove', event => {
      const bounds = button.getBoundingClientRect();
      moveX((event.clientX - bounds.left - bounds.width / 2) * pullX);
      moveY(-2 + (event.clientY - bounds.top - bounds.height / 2) * pullY);
    });
    button.addEventListener('pointerleave', () => { moveX(0); moveY(0); });
  });

  /* ---------- área autenticada: entrada curta e suave, pensada para quem navega o dia todo ---------- */
  const main = $('.main-content');
  if (main) {
    if (entrance) {
      const heading = $('.page-heading', main);
      const intro = [$('.back-link', main), ...(heading ? [...(heading.firstElementChild?.children ?? []), ...[...heading.children].slice(1)] : [])].filter(Boolean);
      gsap.set(intro, { autoAlpha: 0, y: 12 });

      const expand = ['.reports-scope', '.workspace-stack'];
      const grids = '.metrics-grid,.catalog-grid,.integration-grid,.portal-summary,.portal-pets,.dashboard-columns,.form-layout,.profile-grid,.report-stats,.report-financial,.finance-records';
      const blocks = [];
      const collect = element => {
        if (element.matches('script,style,template,dialog,.page-heading,.back-link,.page-footer,.workspace-nav,[hidden],[data-customer-profile]')) return;
        if (expand.some(selector => element.matches(selector))) { [...element.children].forEach(collect); return; }
        if (element.matches(grids)) { blocks.push(...element.children); return; }
        blocks.push(element);
      };
      [...main.children].forEach(collect);

      reveal();
      gsap.to(intro, { autoAlpha: 1, y: 0, duration: .55, stagger: .05, ease: 'power3.out', clearProps: CLEAR });
      revealOnScroll(blocks, { y: 16, stagger: .05, delay: .12 });

      $$('tbody', main).forEach(body => {
        if (body.closest('dialog, details, [hidden], [data-customer-profile]')) return;
        const rows = [...body.rows].filter(row => !row.querySelector('.empty-state')).slice(0, 30);
        if (rows.length < 2) return;
        gsap.set(rows, { autoAlpha: 0 });
        rows.forEach(row => waiting.add(row));
        onVisible(body, () => showNow(rows, { stagger: .035, delay: .2, duration: .35 }));
      });
      $$('.metric-value, .report-money .money', main).forEach(value => onVisible(value, () => countUp(value)));
    } else reveal();

    // Painel de boas-vindas: cartões dos pets surgem e flutuam.
    const banner = $('.welcome-banner', main);
    if (banner) {
      const cards = $$('.pet-card', banner);
      gsap.timeline({ delay: entrance ? .35 : 0 })
        .from(cards, { y: 40, autoAlpha: 0, rotation: '-=10', duration: 1, stagger: .14, ease: 'back.out(1.5)', immediateRender: true })
        .from($$('.art-spark, .art-caption', banner), { autoAlpha: 0, scale: .4, duration: .6, stagger: .1, ease: 'back.out(2)' }, '-=.55')
        .add(() => {
          floatLoop($('.pet-card-front', banner), { y: 9, rotation: 2.5, duration: 3.2 });
          floatLoop($('.pet-card-back', banner), { y: 11, rotation: -2, duration: 3.9, delay: .3 });
          $$('.art-spark', banner).forEach((spark, index) => {
            gsap.to(spark, { rotation: '+=360', duration: 12 + index * 6, repeat: -1, ease: 'none' });
            gsap.to(spark, { scale: 1.25, duration: 1.8 + index * .4, yoyo: true, repeat: -1, ease: 'sine.inOut' });
          });
        });
      $$('.art-orbit', banner).forEach((orbit, index) => gsap.to(orbit, { rotation: index ? '-=360' : '+=360', duration: 50 + index * 15, repeat: -1, ease: 'none' }));
    }
  }

  /* ---------- site público ---------- */
  if (publicPage && ScrollTrigger && document.documentElement.scrollHeight > innerHeight * 1.6) {
    const progress = document.createElement('div');
    progress.className = 'scroll-progress';
    progress.setAttribute('aria-hidden', 'true');
    document.body.append(progress);
    gsap.to(progress, { scaleX: 1, ease: 'none', scrollTrigger: { start: 0, end: 'max', scrub: .3 } });
  }

  const hero = $('.sales-hero');
  if (hero) {
    const copy = hero.firstElementChild, title = $('h1', copy), highlight = $('em', title);
    const art = $('.sales-art', hero);
    const circle = $('.sales-art-circle', art), dog = $('.sales-art-circle svg', art), badge = $('.sales-floating-badge', art);
    const spark = $('.sales-spark', art), orbs = $$('.sales-orb', art), caption = $('.sales-art-word', art);
    const startLoops = () => {
      floatLoop(dog, { y: 10, duration: 3 });
      floatLoop(badge, { y: 9, rotation: 1.5, duration: 3.6, delay: .2 });
      orbs.forEach((orb, index) => floatLoop(orb, { y: 12, rotation: index ? -6 : 6, duration: 3.2 + index * .7, delay: index * .3 }));
      if (spark) {
        gsap.to(spark, { rotation: '+=360', duration: 16, repeat: -1, ease: 'none' });
        gsap.to(spark, { scale: 1.2, duration: 1.8, yoyo: true, repeat: -1, ease: 'sine.inOut' });
      }
    };
    if (entrance) {
      const words = splitWords(title);
      const others = [...copy.children].filter(child => child !== title);
      const timeline = gsap.timeline({ defaults: { ease: 'power3.out' }, onComplete: startLoops });
      gsap.set(others, { autoAlpha: 0, y: 22 });
      gsap.set(words, { autoAlpha: 0, yPercent: 65, rotation: 3 });
      if (highlight) gsap.set(highlight, { '--underline': 0 });
      timeline
        .to(pick(others[0]), { autoAlpha: 1, y: 0, duration: .6 }, .05)
        .to(words, { autoAlpha: 1, yPercent: 0, rotation: 0, duration: 1, stagger: .065, ease: 'power4.out' }, .15)
        .to(pick(highlight), { '--underline': 1, duration: .9, ease: 'power2.inOut' }, .85)
        .to(others.slice(1), { autoAlpha: 1, y: 0, duration: .75, stagger: .1, clearProps: CLEAR }, .6)
        .from(pick(circle), { autoAlpha: 0, scale: .8, duration: 1.3, ease: 'expo.out' }, .1)
        .from(pick(dog), { autoAlpha: 0, y: 70, duration: 1.1, ease: 'back.out(1.4)' }, .4)
        .from(pick(badge), { autoAlpha: 0, y: 30, scale: .9, duration: .9, ease: 'back.out(1.8)' }, .8)
        .from(orbs, { autoAlpha: 0, scale: 0, duration: .8, stagger: .12, ease: 'back.out(2.2)' }, .9)
        .from(pick(spark), { autoAlpha: 0, scale: 0, rotation: -140, duration: 1, ease: 'back.out(2)' }, 1)
        .from(pick(caption), { autoAlpha: 0, x: 20, duration: .8 }, 1.1);
    } else startLoops();
    if (ScrollTrigger && circle) gsap.to(circle, { yPercent: -9, ease: 'none', scrollTrigger: { trigger: hero, start: 'top top', end: 'bottom top', scrub: true } });
  }

  const auth = $('.auth-layout');
  if (auth) reveal(); // login sem animação de entrada

  if (publicPage && entrance && !hero && !auth) {
    const page = $('.public-main');
    const pieces = $$('.registration-intro > *', page);
    revealOnScroll([...(pieces.length ? pieces : []), ...[...page.children].filter(child => !child.matches('.registration-intro'))], { y: 24, stagger: .09 });
  }

  if (entrance && (hero || $('.sales-values'))) {
    revealOnScroll($$('.sales-values > div'), { y: 30, stagger: .1 });
    revealOnScroll($$('.sales-section-heading > *, .sales-catalog > .alert'), { y: 22, stagger: .08 });
    revealOnScroll($$('.sales-catalog .catalog-grid > *'), { y: 46, scale: .97, stagger: .1 });
    const how = $('.sales-how');
    if (how) {
      revealOnScroll([how], { y: 50, scale: .98 });
      revealOnScroll([...$$(':scope > div:first-child > *', how), ...$$('.sales-how-steps > div', how)], { y: 26, stagger: .1, delay: .25 });
      const steps = $('.sales-how-steps', how);
      if (steps && ScrollTrigger) gsap.fromTo(steps, { '--progress': 0 }, { '--progress': 1, ease: 'none', scrollTrigger: { trigger: steps, start: 'top 85%', end: 'top 40%', scrub: .6 } });
    }
  }

  if (entrance && publicPage && !auth) reveal();
  if (ScrollTrigger) addEventListener('load', () => ScrollTrigger.refresh());

  // Garantias: nada fica escondido à espera de uma rolagem que não vai acontecer.
  if (location.hash) showAll();
  addEventListener('hashchange', showAll);
  addEventListener('beforeprint', showAll);
  document.addEventListener('focusin', event => showNow([...waiting].filter(target => target.contains(event.target))));
  addEventListener('scroll', () => {
    if (waiting.size && innerHeight + scrollY >= document.documentElement.scrollHeight - 80) showAll();
  }, { passive: true });
})();
