const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const {readFileSync}=require('node:fs');
const source=readFileSync('public/assets/shell.js','utf8');
function element(initial=[]) {
 const classes=new Set(initial),attributes={},events={};
 return {attributes,events,classList:{contains:key=>classes.has(key),remove:key=>classes.delete(key),toggle(key,force){const next=force??!classes.has(key);if(next)classes.add(key);else classes.delete(key);return next;}},
   addEventListener:(name,fn)=>events[name]=fn,setAttribute:(key,value)=>attributes[key]=value,removeAttribute:key=>delete attributes[key],
   querySelector:()=>({textContent:'Propostas e contratos'})};
}
function setup(isDesktop) {
 const root=element(),sidebar=element(),overlay=element(),button=element(),link=element(['active']),media={matches:isDesktop,addEventListener:(name,fn)=>media.change=fn};
 const handlers={};
 vm.runInNewContext(source,{document:{documentElement:root,querySelector:selector=>({'#sidebar':sidebar,'.sidebar-overlay':overlay})[selector]??null,
   querySelectorAll:selector=>selector.includes('nav-link')?[link]:selector.includes('sidebar-toggle')?[button]:[],
   addEventListener:(name,fn)=>(handlers[name]??=[]).push(fn)},matchMedia:()=>media,localStorage:{setItem(){}},addEventListener(){}});
 const click=()=>handlers.click[0]({target:{closest:selector=>selector==='[data-sidebar-toggle]'?button:null}});
 return {root,sidebar,overlay,button,link,media,click,handlers};
}
test('one desktop hamburger click changes one sidebar state and exposes the active destination',()=>{
 const page=setup(true);page.click();assert.equal(page.root.classList.contains('sidebar-collapsed'),true);
 assert.equal(page.sidebar.classList.contains('is-open'),false);assert.equal(page.button.attributes['aria-expanded'],'false');
 assert.match(page.link.title,/Propostas e contratos/);assert.equal(page.link.attributes['aria-current'],'page');
 page.click();assert.equal(page.root.classList.contains('sidebar-collapsed'),false);assert.equal(page.button.attributes['aria-expanded'],'true');
});
test('mobile toggle opens a drawer and closes cleanly when returning to desktop',()=>{
 const page=setup(false);page.click();assert.equal(page.sidebar.classList.contains('is-open'),true);assert.equal(page.overlay.classList.contains('is-open'),true);
 assert.equal(page.root.classList.contains('sidebar-collapsed'),false);page.media.matches=true;page.media.change();
 assert.equal(page.sidebar.classList.contains('is-open'),false);assert.equal(page.overlay.classList.contains('is-open'),false);
});
