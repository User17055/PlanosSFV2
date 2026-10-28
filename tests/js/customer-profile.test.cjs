const {test}=require('node:test');
const assert=require('node:assert/strict');
const {readFileSync}=require('node:fs');
const vm=require('node:vm');
const source=readFileSync('public/assets/customer-profile.js','utf8');
function node(id,tag='SECTION',dataset={}) {
 return {id,tagName:tag,dataset,children:[],attrs:{},events:{},hidden:false,open:false,focused:false,
  append(child){child.parentElement=this;this.children.push(child);return child;},
  all(){return this.children.flatMap(child=>[child,...child.all()]);},
  contains(child){return this===child||this.all().includes(child);},
  matches(selector){return selector==='[data-profile-panel]'?'profilePanel' in this.dataset:selector==='[data-profile-tabs]'?'profileTabs' in this.dataset:selector==='[data-profile-reopen]'?'profileReopen' in this.dataset:selector==='[role="alert"]'?this.attrs.role==='alert':selector==='summary'?this.tagName==='SUMMARY':selector==='a[href^="#"]'?this.tagName==='A'&&this.attrs.href?.startsWith('#'):false;},
  closest(selector){for(let parent=this;parent;parent=parent.parentElement)if(parent.matches(selector))return parent;return null;},
  querySelector(selector){return this.all().find(child=>child.matches(selector))||null;},
  setAttribute(key,value){this.attrs[key]=value;},getAttribute(key){return this.attrs[key];},
  addEventListener(key,callback){this.events[key]=callback;},scrollIntoView(){this.scrolled=true;},focus(){this.focused=true;},showModal(){this.open=true;}
 };
}
function setup({hash='',initial='resumo',error=false,reopen=false}={}) {
 const root=node('root','DIV',{profileInitial:initial,profileErrors:String(error)}),nav=root.append(node('nav','NAV',{profileTabs:''}));
 const panels={},tabs={};for(const name of ['resumo','pets','contratos','tutor']){
  tabs[name]=nav.append(node('profile-tab-'+name,'A',{profileTab:name}));tabs[name].attrs.href='#'+name;
  panels[name]=root.append(node(name,'SECTION',{profilePanel:name}));
 }
 const add=panels.pets.append(node('novo-pet','DETAILS'));add.append(node('add-summary','SUMMARY'));
 const historyPanel=panels.contratos.append(node('historico-contratos','DETAILS'));
 const contract=historyPanel.append(node('assinatura-2','ARTICLE'));
 const input=panels.tutor.append(node('draft','INPUT'));input.value='Dado ainda não salvo';
 const alert=panels.tutor.append(node('error','DIV'));alert.attrs.role='alert';
 const dialog=panels.contratos.append(node('cancel-2','DIALOG',reopen?{profileReopen:''}:{}));
 const cta=panels.resumo.append(node('cta','A'));cta.attrs.href='#novo-pet';
 root.querySelectorAll=selector=>selector==='[data-profile-panel]'?Object.values(panels):Object.values(tabs);
 const location={hash},events={};
 vm.runInNewContext(source,{document:{querySelector:()=>root,getElementById:id=>[root,...root.all()].find(item=>item.id===id)},
  window:{matchMedia:()=>({matches:true}),addEventListener:(key,callback)=>events[key]=callback},location,
  history:{pushState:(state,title,next)=>location.hash=next},requestAnimationFrame:callback=>callback()});
 const click=target=>{let prevented=false;root.events.click({target,button:0,preventDefault:()=>prevented=true});return prevented;};
 return {root,panels,tabs,add,contract,historyPanel,input,alert,dialog,cta,events,location,click};
}
test('profile opens one summary and tab changes keep unsaved form data',()=>{
 const page=setup();assert.equal(page.panels.resumo.hidden,false);assert.equal(page.panels.tutor.hidden,true);
 assert.equal(page.tabs.resumo.attrs['aria-selected'],'true');page.click(page.tabs.tutor);
 assert.equal(page.panels.tutor.hidden,false);assert.equal(page.panels.resumo.hidden,true);
 assert.equal(page.input.value,'Dado ainda não salvo');assert.equal(page.tabs.tutor.tabIndex,0);
});
test('existing contract deep links reveal the contracts tab and closed history',()=>{
 const page=setup({hash:'#assinatura-2'});assert.equal(page.panels.contratos.hidden,false);
 assert.equal(page.historyPanel.open,true);assert.equal(page.contract.scrolled,true);
});
test('add-pet shortcut opens its form in the pets tab and moves keyboard focus',()=>{
 const page=setup();assert.equal(page.click(page.cta),true);
 assert.equal(page.panels.pets.hidden,false);assert.equal(page.add.open,true);
 assert.equal(page.add.children[0].focused,true);assert.equal(page.location.hash,'#novo-pet');
});
test('validation errors take priority over an unrelated hash and reveal the right form',()=>{
 const page=setup({hash:'#pets',initial:'tutor',error:true});
 assert.equal(page.panels.tutor.hidden,false);assert.equal(page.alert.focused,true);assert.equal(page.alert.scrolled,true);
});
test('keyboard navigation and browser back update active tab and print restores its state',()=>{
 const page=setup();page.tabs.resumo.events.keydown({key:'End',preventDefault(){}});
 assert.equal(page.panels.tutor.hidden,false);assert.equal(page.tabs.tutor.focused,true);
 page.location.hash='#pets';page.events.popstate();assert.equal(page.panels.pets.hidden,false);
 page.events.beforeprint();assert.equal(Object.values(page.panels).every(panel=>!panel.hidden),true);
 page.events.afterprint();assert.equal(page.panels.resumo.hidden,true);assert.equal(page.panels.pets.hidden,false);
});
test('only an explicit server validation marker reopens the cancellation dialog',()=>{
 assert.equal(setup().dialog.open,false);
 const page=setup({initial:'contratos',error:true,reopen:true});assert.equal(page.dialog.open,true);assert.equal(page.panels.contratos.hidden,false);
});
