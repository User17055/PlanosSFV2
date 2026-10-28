const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync('public/assets/signing.js', 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function element(extra = {}) {
  return Object.assign({ dataset: {}, hidden: true, disabled: false, textContent: '', handlers: {},
    classList: { add() {}, remove() {} }, setAttribute() {}, removeAttribute() {}, scrollIntoView() {},
    addEventListener(name, callback) { this.handlers[name] = callback; } }, extra);
}
function run({ review = false, recovery = false, fetch }) {
  const frame = element(), status = element(), retry = element(), button = element(), form = element({action:'/recover'});
  const reader = element({ dataset: { pdfUrl:'/review' }, querySelector(selector) {
    return ({'[data-contract-frame]':frame,'[data-contract-status]':status,'[data-contract-retry]':retry})[selector];
  }});
  form.querySelector = selector => selector === '[data-recovery-status]' ? status : button;
  const timers = new Map(); let timer = 0, reloads = 0;
  vm.runInNewContext(source, {
    document: { querySelector: selector => selector === '[data-contract-review]' ? (review ? reader : null) : (recovery ? form : null),
      querySelectorAll: selector => selector === '[data-requires-review]' ? [button] : selector === '[data-contract-signing-form]' ? [form] : [] },
    fetch, AbortController, Blob, FormData: class {}, URL: {createObjectURL:()=> 'blob:pdf', revokeObjectURL() {}},
    window: {addEventListener() {}}, location: {reload:()=>reloads++},
    setTimeout: callback => { timers.set(++timer, callback); return timer; }, clearTimeout: id => timers.delete(id),
  });
  return { frame, status, button, form, retry, timers, reloads:()=>reloads, async nextTimer() {
    const [id,callback] = timers.entries().next().value; timers.delete(id); await callback(); await flush();
  }};
}
test('PDF loading enables confirmation only after receiving a PDF', async () => {
  let resolve;
  const page = run({review:true,fetch:()=>new Promise(r=>resolve=r)});
  assert.equal(page.button.disabled,true);
  resolve({ok:true,headers:{get:()=> 'application/pdf'},blob:async()=>new Blob(['%PDF-test'])});
  await flush();
  assert.equal(page.button.disabled,false);
  assert.equal(page.frame.hidden,false);
  assert.equal(page.frame.src,'blob:pdf');
  let blocked=false; page.form.handlers.submit({preventDefault:()=>blocked=true}); assert.equal(blocked,false);
});
test('failed PDF keeps confirmation blocked and allows another attempt', async () => {
  const page=run({review:true,fetch:async()=>({ok:false,json:async()=>({message:'Conversor ocupado'})})});
  await flush();
  assert.equal(page.button.disabled,true);
  assert.equal(page.frame.hidden,true);
  assert.equal(page.status.textContent,'Conversor ocupado');
  let blocked=false; page.form.handlers.submit({preventDefault:()=>blocked=true}); assert.equal(blocked,true);
  assert.equal(typeof page.retry.handlers.click,'function');
});
test('automatic recovery retries conversion and reloads only after final document exists', async () => {
  let calls=0;
  const page=run({recovery:true,fetch:async()=>({ok:++calls>1,status:calls>1?200:503,json:async()=>({ready:calls>1})})});
  await page.nextTimer(); assert.equal(calls,1); assert.equal(page.reloads(),0);
  await page.nextTimer(); assert.equal(calls,2); assert.equal(page.reloads(),1);
});
test('recovery stops after three failures while preserving manual retry', async () => {
  let calls=0;
  const page=run({recovery:true,fetch:async()=>{calls++;return {ok:false,status:503,json:async()=>({ready:false})};}});
  await page.nextTimer(); await page.nextTimer(); await page.nextTimer();
  assert.equal(calls,3); assert.equal(page.timers.size,0); assert.equal(page.button.disabled,false);
  let prevented=false; page.form.handlers.submit({preventDefault:()=>prevented=true}); await flush();
  assert.equal(prevented,true); assert.equal(calls,4);
});
