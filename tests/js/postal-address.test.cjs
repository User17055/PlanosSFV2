const { test }=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const {readFileSync}=require('node:fs');
const source=readFileSync('public/assets/postal-address.js','utf8');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function setup(fetch) {
 const status={textContent:''}, values={};
 for(const name of ['street','number','complement','neighborhood','city','state'])values[name]={value:'',dispatchEvent(){},addEventListener(){},closest:()=>({classList:{add(){},remove(){}}})};
 const events={};
 const input={value:'19045-060',dataset:{postalLookup:'/enderecos/cep'},setAttribute(){},removeAttribute(){},
   form:{elements:{namedItem:name=>values[name]},dispatchEvent(){}},closest:()=>({querySelector:()=>status}),addEventListener:(name,fn)=>events[name]=fn};
 vm.runInNewContext(source,{document:{querySelectorAll:()=>[input]},fetch,AbortController,Event:class{},CustomEvent:class{},setTimeout:()=>1,clearTimeout(){}});
 return {input,values,events,status};
}
test('CEP fills street and location while preserving the customer house number',async()=>{
 const page=setup(async()=>({ok:true,json:async()=>({street:'Rua Nova',city:'Presidente Prudente',state:'SP',neighborhood:'Centro'})}));
 page.values.number.value='321';page.values.complement.value='Casa 2';
 await page.events.blur();
 assert.equal(page.values.street.value,'Rua Nova');assert.equal(page.values.city.value,'Presidente Prudente');
 assert.equal(page.values.number.value,'321');assert.equal(page.values.complement.value,'Casa 2');
});
test('changing CEP refreshes previous location and does not overwrite edits made during lookup',async()=>{
 let resolve;
 const page=setup(()=>new Promise(r=>resolve=r));page.values.street.value='Rua Anterior';
 const pending=page.events.blur();page.values.street.value='Rua Corrigida';
 resolve({ok:true,json:async()=>({street:'Rua da Consulta',city:'Nova Cidade',state:'SP'})});await pending;
 assert.equal(page.values.street.value,'Rua Corrigida');assert.equal(page.values.city.value,'Nova Cidade');
});
test('a stale CEP response cannot fill the new address',async()=>{
 let resolve;
 const page=setup(()=>new Promise(r=>resolve=r));const pending=page.events.blur();
 page.input.value='19010-000';page.events.input();
 resolve({ok:true,json:async()=>({street:'Rua do CEP antigo',city:'Cidade antiga'})});await pending;await flush();
 assert.equal(page.values.street.value,'');assert.equal(page.values.city.value,'');
});
test('lookup failure keeps manual entry available with a clear status',async()=>{
 const page=setup(async()=>({ok:false}));await page.events.blur();
 assert.match(page.status.textContent,/manualmente/);assert.equal(page.values.number.value,'');
});
