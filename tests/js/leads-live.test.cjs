const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const {readFileSync}=require('node:fs');
const source=readFileSync('public/assets/leads-live.js','utf8');

// A resposta é só o id do último lead; 'login' simula a sessão encerrada, sem a área ao vivo.
function setup(responses,firstLead=1) {
 const swaps=[],handlers={},status={hidden:true,className:'',lastElementChild:{textContent:''}},toast={textContent:'',classList:{add(){},remove(){}}};
 const lastLead=id=>sel=>sel==='[data-last-lead]'?{dataset:{lastLead:String(id)}}:null;
 const root={querySelector:lastLead(firstLead),querySelectorAll:()=>[],contains:()=>false,replaceChildren:()=>swaps.push(1)};
 const clock={now:0,tick:null,cleared:false,fetches:0};
 class FakeDate extends Date{static now(){return clock.now;}}
 class DOMParser{parseFromString(html){return {querySelector:sel=>sel==='[data-live]'&&html!=='login'?{innerHTML:html,childNodes:[],querySelector:lastLead(Number(html))}:null};}}
 const document={title:'Leads',hidden:false,activeElement:null,querySelector:sel=>({'[data-live]':root,'[data-live-status]':status})[sel]??null,getElementById:()=>toast,addEventListener:(name,fn)=>(handlers[name]??=[]).push(fn)};
 vm.runInNewContext(source,{document,DOMParser,Date:FakeDate,URL,window:{},location:{href:'http://x/leads',origin:'http://x',pathname:'/leads'},history:{pushState(){}},Number,Math,String,Error,getSelection:()=>'',addEventListener(){},
  fetch:async()=>{clock.fetches++;return {ok:true,text:async()=>responses.shift()};},setInterval:fn=>{clock.tick=fn;return 7;},clearInterval:()=>{clock.cleared=true;},setTimeout(){},clearTimeout(){}});
 return {root,clock,status,toast,swaps,handlers,document,settle:()=>new Promise(done=>setImmediate(done))};
}

test('lead novo troca a lista e avisa; o título só muda com a aba escondida',async()=>{
 const page=setup(['2','3']);
 page.clock.tick();await page.settle();
 assert.equal(page.swaps.length,1);
 assert.equal(page.toast.textContent,'Chegou lead novo.');
 assert.equal(page.document.title,'Leads');
 page.document.hidden=true;
 page.clock.tick();await page.settle();
 assert.equal(page.document.title,'(1) Leads');
});

test('sem novidade não troca nada de novo nem repete o aviso',async()=>{
 const page=setup(['1','1']);
 page.clock.tick();await page.settle();
 page.clock.tick();await page.settle();
 assert.equal(page.swaps.length,1);
 assert.equal(page.toast.textContent,'');
});

test('sessão encerrada para a atualização e avisa',async()=>{
 const page=setup(['login']);
 page.clock.tick();await page.settle();
 assert.equal(page.clock.cleared,true);
 assert.match(page.status.lastElementChild.textContent,/Sessão encerrada/);
});

test('parada a tela por 1 hora, para de renovar a sessão; mexer na tela retoma',async()=>{
 const page=setup(['1']);
 page.clock.now=60*60*1000+1;
 page.clock.tick();await page.settle();
 assert.equal(page.clock.fetches,0);
 assert.match(page.status.lastElementChild.textContent,/pausada/);
 page.handlers.pointerdown[0]();await page.settle();
 assert.equal(page.clock.fetches,1);
 assert.match(page.status.lastElementChild.textContent,/Ao vivo/);
});

test('dados abertos de um lead continuam abertos quando a lista se atualiza; clicar de novo fecha',async()=>{
 const page=setup(['2']);
 const more={hidden:true},row={dataset:{leadId:'5'},classList:{toggle(){}},querySelector:()=>null};
 page.root.querySelector=sel=>({'[data-lead-more="5"]':more,'[data-lead-id="5"]':row})[sel]??null;
 const click=()=>page.handlers.click.forEach(fn=>fn({target:{closest:sel=>sel==='[data-live] [data-lead-id]'?row:null}}));
 click();
 assert.equal(more.hidden,false);
 more.hidden=true; // as linhas novas chegam fechadas
 page.clock.tick();await page.settle();
 assert.equal(page.swaps.length,1);
 assert.equal(more.hidden,false);
 click();
 assert.equal(more.hidden,true);
});

test('clicar em link ou botão da linha não abre os dados',()=>{
 const page=setup([]);
 const more={hidden:true},row={dataset:{leadId:'5'},classList:{toggle(){}},querySelector:()=>null};
 page.root.querySelector=sel=>sel==='[data-lead-more="5"]'?more:null;
 page.handlers.click.forEach(fn=>fn({target:{closest:sel=>sel==='[data-live] [data-lead-id]'?row:(sel==='a,form'?{}:null)}}));
 assert.equal(more.hidden,true);
});
