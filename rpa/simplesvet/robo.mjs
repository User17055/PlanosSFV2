// Robô do SimplesVet para os planos: lê uma tarefa em JSON (stdin) e devolve o resultado em JSON (última linha do stdout).
// Plano ativo: acha o tutor pelo CPF (ou cadastra), põe a marca "CLIENTE PLANO", acha o pet (ou cadastra) e põe a marca
// do plano com a data de início. Plano encerrado: tira a marca do pet e, se o tutor não tiver outro plano, a dele.
// Com "dry_run", percorre e preenche tudo, tira prints, mas nunca salva.
import fs from 'node:fs';
import process from 'node:process';
import { chromium } from 'playwright';

const BASE = 'https://app.simples.vet';
const UNIT = process.env.SIMPLESVET_UNIT_ID || '38026';
const SHOTS = process.env.SV_SHOTS_DIR || '';
const UF = { AC: 'Acre', AL: 'Alagoas', AP: 'Amapá', AM: 'Amazonas', BA: 'Bahia', CE: 'Ceará', DF: 'Distrito Federal', ES: 'Espírito Santo',
  GO: 'Goiás', MA: 'Maranhão', MT: 'Mato Grosso', MS: 'Mato Grosso do Sul', MG: 'Minas Gerais', PA: 'Pará', PB: 'Paraíba', PR: 'Paraná',
  PE: 'Pernambuco', PI: 'Piauí', RJ: 'Rio de Janeiro', RN: 'Rio Grande do Norte', RS: 'Rio Grande do Sul', RO: 'Rondônia', RR: 'Roraima',
  SC: 'Santa Catarina', SP: 'São Paulo', SE: 'Sergipe', TO: 'Tocantins' };
const TUTOR = { edit: '#v__btn_editar_geral', tab: 'a[href="#tabExtras"]', box: '.tagsinput', input: '.tagsinput input', save: '#f__btn_salvar', ficha: '#pes_var_chave' };
const ANIMAL = { edit: '#v__btn_editar_ani', tab: 'a[href="#tabExtrasAni"]', box: '.tagsinput', input: '.tagsinput input', save: '#btn_salvar_ani', cancel: '#btn_cancelar_ani', ficha: '#ani_var_chave' };

class Manual extends Error {}
const actions = [];
const say = (text) => { actions.push(text); console.error('·', text); };
const norm = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9]+/g, ' ').trim();
const digits = (s) => String(s ?? '').replace(/\D/g, '');
let shotNo = 0;
// "#id" acha só o primeiro elemento com aquele id; o SimplesVet repete ids em formulários escondidos. [id="..."] acha todos.
const byId = (selector) => selector.replace(/(^|[\s,>+~])#([\w-]+)/g, '$1[id="$2"]');
const vis = (page, selector) => page.locator(`${byId(selector)}:visible`).first();
const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: `${SHOTS}/${String(++shotNo).padStart(2, '0')}-${name}.png`, fullPage: true }).catch(() => {}); };

const task = JSON.parse(fs.readFileSync(0, 'utf8'));
const dry = Boolean(task.dry_run);

async function login(page) {
  // Sessão guardada da execução anterior: se ainda vale, não precisa entrar de novo (menos captcha).
  await page.goto(`${BASE}/principal/cliente/cliente.php`, { waitUntil: 'commit', timeout: 60000 }).catch(() => {});
  await page.locator('#p__pes_var_nome, #l_usu_var_email').first().waitFor({ state: 'attached', timeout: 45000 }).catch(() => {});
  if (!page.url().includes('/login/') && await page.locator('#p__pes_var_nome').count()) return;
  for (let attempt = 1; ; attempt++) {
    try {
      await page.goto(`${BASE}/login/login.php`, { waitUntil: 'commit', timeout: 30000 });
      await page.waitForURL(() => true, { timeout: 5000 }).catch(() => {});
      await page.locator('#l_usu_var_email, #menu, .page-sidebar, a[href*="sair"]').first().waitFor({ state: 'attached', timeout: 30000 });
      if (!page.url().includes('/login/')) return; // já estava logado: o SimplesVet levou ao painel
      await page.locator('#l_usu_var_email').waitFor({ state: 'visible', timeout: 15000 });
      break;
    } catch (error) { if (attempt >= 4) throw new Error('SimplesVet não abriu a tela de login'); }
  }
  await page.locator('#l_usu_var_email').fill(process.env.SIMPLESVET_USER || '');
  await page.locator('#l_usu_var_senha').fill(process.env.SIMPLESVET_PASSWORD || '');
  // Captcha da Cloudflare na tela de login: espera a verificação liberar antes de entrar.
  await page.waitForFunction(() => {
    const field = document.querySelector('[name="cf-turnstile-response"]');
    return !field || field.value.length > 0;
  }, null, { timeout: 60000 }).catch(() => {
    // O robô não tenta passar por captcha. Entrar com a conta do robô em outro computador não renova a sessão dele no servidor.
    throw new Manual('O SimplesVet pediu captcha no login do robô, e ele não passa por captcha. Cadastre este tutor e pet à mão no SimplesVet, com as marcas do plano, e avise o suporte para renovar o acesso do robô.');
  });
  await page.locator('#btn_login').click();
  await page.waitForTimeout(4000);
  const unit = page.locator(`.celx[data-id="${UNIT}"]`).first();
  if (page.url().includes('/login/') && await unit.count()) {
    await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login/'), { timeout: 30000 }), unit.click()]);
  }
  if (page.url().includes('/login/')) throw new Error('Login no SimplesVet não concluído (usuário, senha ou unidade)');
}

// Pesquisa o responsável pelo CPF e devolve quantos vieram. A lista abre com 100 clientes: espera ela trocar.
async function searchTutor(page, cpf) {
  await page.goto(`${BASE}/principal/cliente/cliente.php`, { waitUntil: 'commit', timeout: 60000 });
  await page.locator('#p__pes_var_nome').waitFor({ state: 'visible', timeout: 45000 });
  const rows = page.locator('tr.linhaRegistro');
  await rows.first().waitFor({ state: 'visible', timeout: 30000 }).catch(() => {});
  const before = await rows.count();
  await page.locator('#p__pes_var_nome').fill(cpf);
  await page.locator('#p__btn_filtrar').click();
  await page.waitForFunction((n) => document.querySelectorAll('tr.linhaRegistro').length !== n, before, { timeout: 30000 })
    .catch(() => { throw new Error('A pesquisa por CPF não respondeu'); });
  await page.waitForTimeout(1500);
  return rows.count();
}

async function openFirstTutor(page) {
  await page.locator('tr.linhaRegistro').first().locator('td').first().click();
  await page.locator(TUTOR.edit).first().waitFor({ state: 'visible', timeout: 30000 });
}

// Marcações (tags) do tutor ou do animal: abre a edição, ajusta e salva; confere reabrindo. Devolve a ficha.
async function setTags(page, cfg, wanted, label) {
  await vis(page, cfg.edit).click();
  await vis(page, cfg.tab).click();
  await vis(page, cfg.box).waitFor({ state: 'visible', timeout: 15000 });
  const ficha = (await vis(page, cfg.ficha).inputValue().catch(() => '')).trim() || null;
  const read = async () => (await page.locator(`${byId(cfg.box)}:visible .tag span`).allInnerTexts()).map((t) => t.trim()).filter(Boolean);
  const current = await read();
  const target = wanted(current);
  const remove = current.filter((t) => !target.some((w) => norm(w) === norm(t)));
  const add = target.filter((w) => !current.some((t) => norm(t) === norm(w)));
  if (!remove.length && !add.length) {
    say(`${label}: marcações já corretas`);
    await leaveEdit(page, cfg);
    return ficha;
  }
  for (const text of remove) {
    await page.locator(`${byId(cfg.box)}:visible .tag`).filter({ has: page.locator('span', { hasText: new RegExp(`^\\s*${text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`) }) }).first().locator('a').click();
  }
  for (const text of add) {
    await vis(page, cfg.input).fill(text);
    await vis(page, cfg.input).press('Enter');
  }
  const after = await read();
  if (target.some((w) => !after.some((t) => norm(t) === norm(w))) || after.some((t) => remove.some((r) => norm(r) === norm(t)))) {
    throw new Error(`${label}: o SimplesVet não aceitou a marcação`);
  }
  say(`${label}: ${add.length ? 'põe ' + add.join(', ') : ''}${add.length && remove.length ? '; ' : ''}${remove.length ? 'tira ' + remove.join(', ') : ''}`);
  await shot(page, label.toLowerCase().replace(/[^a-z]+/g, '-'));
  if (cfg === TUTOR) await answerWhatsapp(page);
  if (dry) { await leaveEdit(page, cfg); return ficha; }
  await saveAndWait(page, cfg.save, cfg.edit);
  // Confere o que ficou gravado.
  await vis(page, cfg.edit).click();
  await vis(page, cfg.tab).click();
  await vis(page, cfg.box).waitFor({ state: 'visible', timeout: 15000 });
  const saved = await read();
  if (target.some((w) => !saved.some((t) => norm(t) === norm(w))) || saved.some((t) => remove.some((r) => norm(r) === norm(t)))) {
    throw new Error(`${label}: a marcação não ficou gravada`);
  }
  await leaveEdit(page, cfg);
  return ficha;
}

// Salva e espera a ficha voltar. Se o SimplesVet responder com um aviso, nada foi gravado: vai para a recepção com a mensagem.
async function saveAndWait(page, save, done) {
  await vis(page, save).click();
  const outcome = await Promise.race([
    vis(page, done).waitFor({ state: 'visible', timeout: 45000 }).then(() => 'ok'),
    page.locator('.modal:visible, .bootbox:visible').first().waitFor({ state: 'visible', timeout: 45000 }).then(() => 'aviso'),
  ]).catch(() => 'demora');
  if (outcome === 'ok') return;
  const text = outcome === 'aviso' ? (await page.locator('.modal:visible, .bootbox:visible').first().innerText()).replace(/\s+/g, ' ').trim() : '';
  await shot(page, 'nao-salvou');
  if (outcome === 'aviso') throw new Manual(`O SimplesVet não salvou: ${text.replace(/^Erro!?\s*/i, '').replace(/\s*Ok$/i, '').slice(0, 200)}`);
  throw new Error('O SimplesVet não confirmou o salvamento a tempo');
}

// Ficha antiga com celular sem "é WhatsApp?": o SimplesVet não salva nada sem isso. O número que é o daqui é o WhatsApp do cliente.
async function answerWhatsapp(page) {
  const mine = digits(task.tutor?.phone).replace(/^55(?=\d{10,11}$)/, '');
  if (!mine) return;
  await page.locator('a[href="#tabGeral"]:visible').first().click().catch(() => {});
  const changed = await page.evaluate((mine) => {
    let n = 0;
    for (const select of document.querySelectorAll('select[id^="con_cha_whatsapp"]')) {
      if (!select.offsetParent || select.value) continue;
      // Sobe até o bloco da linha do contato, que tem o número e o "é WhatsApp?".
      let row = select.parentElement;
      while (row && !row.querySelector('input[type="tel"]')) row = row.parentElement;
      const phone = row?.querySelector('input[type="tel"]')?.value.replace(/\D/g, '') || '';
      if (phone && phone === mine && select.options.length > 1) {
        select.selectedIndex = 1; // "É WhatsApp"
        select.dispatchEvent(new Event('change', { bubbles: true }));
        n++;
      }
    }
    return n;
  }, mine);
  if (changed) say('tutor: marca o celular daqui como WhatsApp (a ficha antiga não dizia)');
}

async function leaveEdit(page, cfg) {
  const cancel = cfg.cancel ? page.locator(`${cfg.cancel}:visible`) : page.getByRole('button', { name: /cancelar/i });
  await cancel.first().click().catch(() => {});
  await vis(page, cfg.edit).waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
}

// Animais vivos da ficha do tutor (links entre "Animais vivos" e "Compras"/"Animais mortos").
async function livingAnimals(page) {
  return page.evaluate(() => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    let start = null; let end = null;
    while (walker.nextNode()) {
      const text = walker.currentNode.textContent.trim();
      if (!start && text === 'Animais vivos') start = walker.currentNode;
      else if (start && !end && (text === 'Compras' || text === 'Animais mortos')) end = walker.currentNode;
    }
    if (!start) return [];
    return [...document.querySelectorAll('a')].filter((a) => a.offsetParent
      && (start.compareDocumentPosition(a) & Node.DOCUMENT_POSITION_FOLLOWING)
      && (!end || (a.compareDocumentPosition(end) & Node.DOCUMENT_POSITION_FOLLOWING))
      && a.textContent.trim() && !/adicionar animal/i.test(a.textContent))
      .map((a, index) => { a.setAttribute('data-svrobo', String(index)); return { index, name: a.textContent.trim() }; });
  });
}

// Mesmo pet: nome igual; se não houver, um nome contido no outro (LUA / MARIA LUA). Mais de um parecido: conferência manual.
function matchAnimal(animals, name) {
  const target = norm(name);
  let found = animals.filter((a) => norm(a.name) === target);
  if (!found.length) found = animals.filter((a) => { const n = norm(a.name); return n && (` ${target} `.includes(` ${n} `) || ` ${n} `.includes(` ${target} `)); });
  if (found.length > 1) throw new Manual(`Mais de um animal parecido com "${name}" no SimplesVet: ${found.map((a) => a.name).join(', ')}`);
  return found[0] || null;
}

async function openAnimal(page, animal, pet) {
  await page.locator(`[data-svrobo="${animal.index}"]`).click();
  await vis(page, ANIMAL.edit).waitFor({ state: 'visible', timeout: 30000 });
  // Confere a espécie antes de mexer.
  await vis(page, ANIMAL.edit).click();
  await page.waitForTimeout(1500);
  const species = norm(await page.evaluate(() => {
    const select = [...document.querySelectorAll('select#esp_int_codigo')].find((s) => s.parentElement?.offsetParent);
    return select ? select.options[select.selectedIndex]?.text || '' : '';
  }));
  await leaveEdit(page, ANIMAL);
  const expected = pet.species === 'cat' ? 'FELINA' : 'CANINA';
  if (species && species !== expected) throw new Manual(`"${animal.name}" no SimplesVet é ${species}, aqui é ${expected}: conferir se é o mesmo pet`);
}

async function chooseOption(page, selector, pick) {
  const options = await page.evaluate((s) => {
    const select = [...document.querySelectorAll(s)].find((x) => x.parentElement?.offsetParent);
    return select ? [...select.options].map((o) => o.text.trim()) : [];
  }, selector);
  const label = pick(options);
  if (!label) return null;
  const done = await page.evaluate(({ s, label }) => {
    const select = [...document.querySelectorAll(s)].find((x) => x.parentElement?.offsetParent);
    const option = select && [...select.options].find((o) => o.text.trim() === label);
    if (!option) return false;
    select.value = option.value;
    if (window.jQuery) window.jQuery(select).trigger('change'); else select.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }, { s: selector, label });
  return done ? label : null;
}

async function createAnimal(page, pet, tag) {
  if (!['male', 'female'].includes(pet.sex) || pet.neutered === null || pet.neutered === undefined) {
    throw new Manual(`Para cadastrar "${pet.name}" no SimplesVet faltam o sexo ou a castração na saúde`);
  }
  await page.getByText('Adicionar animal', { exact: false }).first().click();
  await vis(page, '#ani_var_nome').waitFor({ state: 'visible', timeout: 30000 });
  await vis(page, '#ani_var_nome').fill(pet.name.toUpperCase());
  // Ordem que o SimplesVet respeita: espécie (recarrega raças e limpa a esterilização), raça, sexo, esterilização, pelagem e a data por último.
  const speciesLabel = pet.species === 'cat' ? 'FELINA' : 'CANINA';
  await chooseOption(page, '#esp_int_codigo', (o) => o.find((x) => norm(x) === speciesLabel));
  await page.waitForTimeout(2000);
  const breed = norm(pet.breed);
  const noBreed = !breed || /^(SRD|SEM RACA.*|VIRA LATA|MESTICO|NAO SEI)$/.test(breed);
  // A raça daqui, se existir lá (inclusive MESTIÇO); "sem raça" sem correspondência vira SRD ou MESTIÇO; outra raça desconhecida fica em branco.
  const breedLabel = await chooseOption(page, '#rac_int_codigo', (o) => o.find((x) => norm(x) === breed)
    || (noBreed ? o.find((x) => norm(x) === 'SRD') || o.find((x) => norm(x) === 'MESTICO') : null) || null);
  const sexLabel = pet.sex === 'male' ? 'Macho' : 'Fêmea';
  const neuterLabel = pet.neutered ? 'Castrado' : 'Fértil';
  await vis(page, '#ani_cha_sexo').selectOption({ label: sexLabel });
  await vis(page, '#ani_cha_sexo').dispatchEvent('change');
  const color = norm(pet.color);
  const coatLabel = await chooseOption(page, '#pel_int_codigo', (o) => o.find((x) => norm(x) === color)
    || o.find((x) => norm(x) === color.replace(/O$/, 'A')) || o.find((x) => norm(x) === 'INDEFINIDA'));
  if (pet.birth_date) {
    const [y, m, d] = pet.birth_date.split('-');
    await vis(page, '#ani_dat_nascimento').click();
    await vis(page, '#ani_dat_nascimento').pressSequentially(`${d}${m}${y}`, { delay: 30 });
    await vis(page, '#ani_dat_nascimento').press('Escape');
    await vis(page, '#ani_var_nome').click(); // fecha o calendário
  }
  // A lista de esterilização é recarregada depois do sexo e apaga o que foi marcado antes: marca por último e confere.
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.waitForFunction(() => [...document.querySelectorAll('[id="ani_cha_esterilizacao"]')].some((x) => x.offsetParent && x.options.length > 1), null, { timeout: 15000 });
    await page.waitForTimeout(800);
    await vis(page, '#ani_cha_esterilizacao').selectOption({ label: neuterLabel });
    await page.waitForTimeout(800);
    const now = await vis(page, '#ani_cha_esterilizacao').evaluate((s) => s.options[s.selectedIndex]?.text || '');
    if (norm(now) === norm(neuterLabel)) break;
  }
  // Confere o formulário antes de seguir: campo fora do lugar para aqui, sem salvar.
  const shown = await page.evaluate(() => {
    const pick = (id) => [...document.querySelectorAll(`[id="${id}"]`)].find((x) => x.offsetParent || x.parentElement?.offsetParent);
    const text = (id) => { const s = pick(id); return s ? (s.tagName === 'SELECT' ? s.options[s.selectedIndex]?.text : s.value) || '' : ''; };
    return { sex: text('ani_cha_sexo'), neutered: text('ani_cha_esterilizacao'), species: text('esp_int_codigo'), coat: text('pel_int_codigo'), name: text('ani_var_nome') };
  });
  const wrong = Object.entries({ sex: sexLabel, neutered: neuterLabel, species: speciesLabel, name: pet.name.toUpperCase(), ...(coatLabel ? { coat: coatLabel } : {}) })
    .filter(([key, value]) => norm(shown[key]) !== norm(value)).map(([key]) => key);
  await shot(page, 'novo-animal-geral');
  if (wrong.length) throw new Error(`O formulário do animal não ficou certo (${wrong.join(', ')})`);
  await page.locator('a[href="#tabExtrasAni"]:visible').first().click();
  await vis(page, ANIMAL.input).fill(tag);
  await vis(page, ANIMAL.input).press('Enter');
  say(`animal: cadastra "${pet.name.toUpperCase()}" (${pet.species === 'cat' ? 'FELINA' : 'CANINA'}, ${pet.sex === 'male' ? 'Macho' : 'Fêmea'}, ${pet.neutered ? 'Castrado' : 'Fértil'}, raça ${breedLabel || '-'}, pelagem ${coatLabel || '-'}) com ${tag}`);
  await shot(page, 'novo-animal');
  if (dry) return null;
  await saveAndWait(page, ANIMAL.save, ANIMAL.edit);
  return setTags(page, ANIMAL, (current) => current, 'animal (conferência)');
}

// Aviso do SimplesVet durante o cadastro: telefone/e-mail de outra ficha ou dado recusado. Não cadastra; a recepção confere.
async function stopOnWarning(page) {
  await page.waitForTimeout(2000);
  const modal = page.locator('.modal:visible, .bootbox:visible').first();
  if (!await modal.count()) return;
  const text = (await modal.innerText()).replace(/\s+/g, ' ').trim();
  await shot(page, 'aviso-simplesvet');
  if (/Cliente já cadastrado/i.test(text)) {
    const found = (text.match(/Nome:\s*(.+?)\s*CPF:/) || [])[1] || 'outra ficha';
    throw new Manual(`O SimplesVet já tem um cliente com o mesmo telefone ou e-mail (${found}), com outro CPF ou sem CPF: conferir antes de cadastrar`);
  }
  throw new Manual(`O SimplesVet recusou um dado do tutor: ${text.replace(/^Erro!?\s*/i, '').replace(/\s*Ok$/i, '').slice(0, 200)}`);
}

async function createTutor(page, tutor, tag) {
  const a = tutor.address || {};
  if (!['male', 'female'].includes(tutor.sex)) throw new Manual('Para cadastrar o tutor no SimplesVet falta o sexo do titular na saúde');
  if (![a.zip, a.street, a.number, a.neighborhood, a.city, a.state].every(Boolean) || !UF[String(a.state).toUpperCase()]) {
    throw new Manual('Para cadastrar o tutor no SimplesVet falta endereço completo na saúde (CEP, rua, número, bairro, cidade e UF)');
  }
  await page.locator('#p__btn_adicionar').click();
  await vis(page, '#pes_var_nome').waitFor({ state: 'visible', timeout: 30000 });
  await vis(page, '#pes_var_nome').fill(tutor.name.toUpperCase());
  await vis(page, '#pes_cha_sexo').selectOption({ label: tutor.sex === 'male' ? 'Masculino' : 'Feminino' });
  await vis(page, '#pes_var_cpf').click();
  await vis(page, '#pes_var_cpf').pressSequentially(digits(tutor.cpf), { delay: 20 });
  await vis(page, '#ope_int_codigo').selectOption({ label: tutor.origin || 'Google' });
  await chooseOption(page, '#prf_int_codigo', (o) => o.find((x) => norm(x) === 'OUTRA'));
  if (tutor.email) {
    await page.locator('input[type="email"][placeholder="E-mail"]:visible').first().fill(tutor.email);
    await page.locator('input[type="email"][placeholder="E-mail"]:visible').first().press('Tab');
    await stopOnWarning(page);
  }
  if (tutor.phone) {
    const phone = page.locator('input[type="tel"][placeholder="Celular"]:visible').first();
    await phone.click();
    await phone.pressSequentially(digits(tutor.phone).replace(/^55(?=\d{10,11}$)/, ''), { delay: 20 });
    const whatsapp = page.locator('select[id^="con_cha_whatsapp"]:visible').first();
    if (await whatsapp.count()) await whatsapp.selectOption({ index: 1 }); // "É WhatsApp"
  }
  await stopOnWarning(page);
  await shot(page, 'novo-tutor-geral');
  await page.locator('a[href="#tabEndereco"]:visible').first().click();
  await vis(page, '#end_var_cep').click();
  await vis(page, '#end_var_cep').pressSequentially(digits(a.zip), { delay: 30 });
  await vis(page, '#end_var_cep').press('Tab');
  await page.waitForTimeout(3000); // a busca do CEP preenche a rua; os dados daqui prevalecem
  await vis(page, '#end_var_endereco').fill(a.street);
  await vis(page, '#end_var_numero').fill(String(a.number));
  if (a.complement) await vis(page, '#end_var_complemento').fill(a.complement);
  await vis(page, '#end_var_bairro').fill(a.neighborhood);
  await vis(page, '#end_var_municipio').fill(a.city);
  await vis(page, '#end_var_uf').selectOption({ label: UF[String(a.state).toUpperCase()] });
  await shot(page, 'novo-tutor-endereco');
  await page.locator('a[href="#tabExtras"]:visible').first().click();
  await vis(page, TUTOR.input).fill(tag);
  await vis(page, TUTOR.input).press('Enter');
  say(`tutor: cadastra ${tutor.name.toUpperCase()} (${tutor.sex === 'male' ? 'Masculino' : 'Feminino'}, origem ${tutor.origin || 'Google'}, profissão Outra) com ${tag}`);
  await shot(page, 'novo-tutor');
  if (dry) return null;
  await saveAndWait(page, TUTOR.save, TUTOR.edit);
  return setTags(page, TUTOR, (current) => current, 'tutor (conferência)');
}

// Marca deste plano no pet, em qualquer formato: "PLANO X - 10/11/2026", o antigo "PLANO X DESDE 10/11/2026" ou só "PLANO X".
function isPlanTag(tag) {
  const plan = norm(task.pet_tag_prefix);
  const text = norm(tag);
  return text === plan || (text.startsWith(`${plan} `) && /^(DESDE )?\d/.test(text.slice(plan.length + 1)));
}

async function activate(page) {
  const { tutor, pet } = task;
  const count = await searchTutor(page, digits(tutor.cpf));
  if (count > 1) throw new Manual(`O CPF tem ${count} cadastros no SimplesVet: conferir qual é o certo`);
  let tutorId;
  if (count === 0) {
    tutorId = await createTutor(page, tutor, task.tutor_tag);
    if (dry) { say('animal: seria cadastrado depois do tutor'); return { tutor_id: null, pet_id: null }; }
  } else {
    await openFirstTutor(page);
    say('tutor: encontrado pelo CPF');
    tutorId = await setTags(page, TUTOR, (current) => [...current, task.tutor_tag], 'tutor');
  }
  if (tutor.simplesvet_id && tutorId && tutor.simplesvet_id !== tutorId) throw new Manual(`O CPF agora aponta para outra ficha no SimplesVet (${tutorId}, antes ${tutor.simplesvet_id})`);
  await page.locator(TUTOR.edit).first().waitFor({ state: 'visible', timeout: 30000 });
  const animals = await livingAnimals(page);
  const animal = matchAnimal(animals, pet.name);
  let petId;
  if (!animal) {
    petId = await createAnimal(page, pet, task.pet_tag);
  } else {
    say(`animal: "${pet.name}" é "${animal.name}" no SimplesVet`);
    await openAnimal(page, animal, pet);
    petId = await setTags(page, ANIMAL, (current) => [...current.filter((t) => !isPlanTag(t)), task.pet_tag], 'animal');
  }
  if (pet.simplesvet_id && petId && pet.simplesvet_id !== petId) throw new Manual(`O pet agora aponta para outra ficha no SimplesVet (${petId}, antes ${pet.simplesvet_id})`);
  return { tutor_id: tutorId, pet_id: petId };
}

async function deactivate(page) {
  const { tutor, pet } = task;
  const count = await searchTutor(page, digits(tutor.cpf));
  if (count === 0) { say('tutor: não está no SimplesVet; nada a tirar'); return { tutor_id: null, pet_id: null }; }
  if (count > 1) throw new Manual(`O CPF tem ${count} cadastros no SimplesVet: conferir qual é o certo`);
  await openFirstTutor(page);
  let petId = null;
  const animal = matchAnimal(await livingAnimals(page), pet.name);
  if (animal) {
    await openAnimal(page, animal, pet);
    petId = await setTags(page, ANIMAL, (current) => current.filter((t) => !isPlanTag(t)), 'animal');
    await searchTutor(page, digits(tutor.cpf));
    await openFirstTutor(page);
  } else {
    say(`animal: "${pet.name}" não está no SimplesVet; nada a tirar`);
  }
  const tutorId = await setTags(page, TUTOR, (current) => (task.keep_tutor_tag ? current : current.filter((t) => norm(t) !== norm(task.tutor_tag))), 'tutor');
  return { tutor_id: tutorId, pet_id: petId };
}

let browser;
let result;
try {
  browser = await chromium.launch({ headless: process.env.SIMPLESVET_HEADLESS !== '0', slowMo: Number(process.env.SV_SLOWMO || 0) });
  const stateFile = process.env.SV_STATE_FILE || '';
  const context = await browser.newContext({ locale: 'pt-BR', viewport: { width: 1440, height: 1000 },
    ...(stateFile && fs.existsSync(stateFile) ? { storageState: stateFile } : {}),
    // Vídeo da rodada, para acompanhar o que o robô fez na tela.
    ...(process.env.SV_VIDEO_DIR ? { recordVideo: { dir: process.env.SV_VIDEO_DIR, size: { width: 1280, height: 890 } } } : {}) });
  // O "assistente" do SimplesVet (aviso por cima da tela) bloqueia os cliques: é escondido sempre que aparece.
  await context.addInitScript(() => {
    const hide = () => document.querySelectorAll('#assistentes').forEach((el) => el.style.setProperty('display', 'none', 'important'));
    new MutationObserver(hide).observe(document, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
  });
  // Rastreadores (Google Analytics, Facebook...) às vezes travam o carregamento: ficam de fora. O captcha da Cloudflare passa.
  await context.route('**/*', (route) => {
    const host = new URL(route.request().url()).hostname;
    return /google-analytics\.com$|googletagmanager\.com$|analytics\.google\.com$|doubleclick\.net$|facebook\.(net|com)$|hotjar\.com$|clarity\.ms$/.test(host)
      ? route.abort() : route.continue();
  });
  if (dry) {
    // Ensaio: nenhum clique de salvar, excluir ou confirmar chega ao SimplesVet.
    await context.addInitScript(() => document.addEventListener('click', (ev) => {
      const el = ev.target.closest('button,a,input');
      const text = el ? `${el.id} ${el.innerText || el.value || ''}`.toLowerCase() : '';
      if (/salvar|gravar|excluir|confirmar/.test(text)) { ev.preventDefault(); ev.stopImmediatePropagation(); }
    }, true));
  }
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  try {
    await login(page);
    if (stateFile) await context.storageState({ path: stateFile }).then(() => fs.chmodSync(stateFile, 0o600)).catch(() => {});
    result = { ok: true, dry_run: dry, ...(task.action === 'deactivate' ? await deactivate(page) : await activate(page)), actions };
  } catch (error) {
    await shot(page, 'erro');
    throw error;
  }
} catch (error) {
  // Primeira linha da mensagem e, se houver, o campo que o robô esperava (sem dados de cliente).
  const message = String(error?.message || error);
  const waiting = (message.match(/waiting for (locator\([^\n]*)/) || [])[1] || '';
  result = { ok: false, manual: error instanceof Manual, error: (message.split('\n')[0] + (waiting ? ` — ${waiting}` : '')).slice(0, 500), actions };
} finally {
  if (browser) { await Promise.all(browser.contexts().map((c) => c.close().catch(() => {}))); await browser.close().catch(() => {}); }
}
process.stdout.write(`${JSON.stringify(result)}\n`);
