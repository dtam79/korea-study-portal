'use strict';
// Offline interaction smoke for the exact production filter adapter (no WordPress or browser required).
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const code=fs.readFileSync(require('node:path').join(__dirname,'../assets/filter-controls.js'),'utf8');
class Cls {
  constructor(){this.values=new Set();}
  add(v){this.values.add(v);}
  contains(v){return this.values.has(v);}
  toggle(v,state){if(state)this.values.add(v);else this.values.delete(v);}
}
class El {
  constructor(tag,text=''){
    this.tagName=tag.toUpperCase();this.textContent=text;this.children=[];
    this.parentElement=null;this.dataset={};this.classList=new Cls();this.handlers={};this.attrs={};
    this.options=[];this.value='';this.disabled=false;this.multiple=false;this.name='';this.type='';this.id='';
  }
  set className(s){this.classList.values=new Set(String(s).split(/\s+/).filter(Boolean));}
  get className(){return [...this.classList.values].join(' ');}
  append(...children){for(const child of children){child.parentElement=this;this.children.push(child);}}
  replaceChildren(...children){this.children=[];this.append(...children);}
  contains(el){return this===el||this.children.some(child=>child.contains?.(el));}
  get childNodes(){return [];}
  get nextElementSibling(){if(!this.parentElement)return null;const siblings=this.parentElement.children;return siblings[siblings.indexOf(this)+1]||null;}
  setAttribute(k,v){this.attrs[k]=String(v);}getAttribute(k){return this.attrs[k]??null;}
  addEventListener(name,fn){(this.handlers[name]??=[]).push(fn);}
  dispatchEvent(event){for(const fn of this.handlers[event.type]||[])fn(event);if(event.bubbles && this.parentElement)this.parentElement.dispatchEvent(event);}
  click(){this.dispatchEvent({type:'click',preventDefault(){},stopImmediatePropagation(){}});}
  closest(tag){let el=this;while(el){if(el.tagName.toLowerCase()===tag.toLowerCase())return el;el=el.parentElement;}return null;}
  insertAdjacentElement(position,el){assert.equal(position,'afterend');this.parentElement.append(el);}
  querySelector(tag){if(tag==='.kgds-filter-status')return this.children.find(c=>c.classList.contains('kgds-filter-status'))||null;
    if(tag==='select')return this.children.find(c=>c.tagName==='SELECT')||null;
    if(tag==='summary')return this.children.find(c=>c.tagName==='SUMMARY')||null;
    if(tag==='form')return this.children.find(c=>c.tagName==='FORM')||null;return null;}
  querySelectorAll(q){if(q==='label')return this.children.filter(c=>c.tagName==='LABEL');
    if(q==='select,input,textarea')return this.children.filter(c=>['SELECT','INPUT','TEXTAREA'].includes(c.tagName));
    if(q==='button,input[type="reset"]')return this.children.filter(c=>c.tagName==='BUTTON'||c.type==='reset');
    if(q==='select:not(.kgds-bool-native-select)')return this.children.filter(c=>c.tagName==='SELECT'&&!c.classList.contains('kgds-bool-native-select'));
    return [];
  }
}
const labels=['인증대학 여부','100% 영어 학위 과정'];
const form=new El('form');const selects=[];
for(const l of labels){
  const label=new El('label',l);const select=new El('select');
  select.options=[{textContent:'전체',value:''},{textContent:'예',value:'1'},{textContent:'아니요',value:'0'}];
  select.value='';select.form=form;select.name=l.includes('인증')?'certified':'english_only';
  form.append(label,select);selects.push(select);
}
const regionLabel=new El('label','지역');const region=new El('select');region.name='region';region.id='region';regionLabel.htmlFor='region';
region.options=[{textContent:'전체 지역',value:''},{textContent:'서울',value:'seoul'}];region.value='seoul';
form.append(regionLabel,region);
const priceLabel=new El('label','등록금 최대값 (원)');const price=new El('input');price.type='number';price.name='max_tuition';price.id='max_tuition';priceLabel.htmlFor='max_tuition';price.value='3500000';form.append(priceLabel,price);
const keywordField=new El('input');keywordField.name='q';keywordField.type='search';keywordField.value='KAIST';
const details=new El('details');details.append(new El('summary','조건별 필터'),form);
const main={querySelectorAll(q){if(q.includes('input[name=\"q\"]')||q.includes('input[type=\"search\"]'))return [keywordField];if(q==='label')return form.querySelectorAll('label');return q.startsWith('details,')?[details]:[];}};
const handlers={};
const sandbox={location:{pathname:'/ko/universities/'},document:{readyState:'complete',querySelector:q=>q==='.kgp-shell main'?main:null,
    createElement:tag=>new El(tag),getElementById:()=>null},
  window:{MutationObserver:null,addEventListener:(n,f)=>(handlers[n]??=[]).push(f),location:{assign:url=>{sandbox.lastNavigation=url;}}},
  Event:class {constructor(type,init){this.type=type;this.bubbles=init?.bubbles;}},
  Set, Object, console, URL, location:{pathname:'/ko/universities/',origin:'https://portal.example',href:'https://portal.example/ko/universities/?q=KAIST&certified=1'}, setTimeout, requestAnimationFrame:f=>f()};
vm.runInNewContext(code,sandbox,{filename:'filter-controls.js'});
const boxes=form.children.filter(c=>c.classList?.contains('kgds-bool-segmented'));
const status=details.children[0].querySelector('.kgds-filter-status');
assert.ok(status,'compact disclosure summary exists');
assert.equal(boxes.length,2,'two visual filter groups');
const getButtons=box=>box.children[1].children;
for(const box of boxes)assert.equal(getButtons(box).length,2,'only two visible choices');
assert.deepEqual(getButtons(boxes[0]).map(x=>x.textContent),['인증','전체 보기']);
assert.deepEqual(getButtons(boxes[1]).map(x=>x.textContent),['100% 영어 학위','전체 보기']);
let changes=0;selects[0].addEventListener('change',()=>changes++);
getButtons(boxes[0])[0].click();
assert.equal(selects[0].value,'1','certified sends positive native value');
assert.equal(changes,1,'native change event is dispatched');
assert.equal(getButtons(boxes[0])[0].getAttribute('aria-pressed'),'true');
getButtons(boxes[0])[1].click();
assert.equal(selects[0].value,'','all includes both certified and not certified');
assert.equal(changes,2,'native change event on all');
assert.equal(getButtons(boxes[0])[1].getAttribute('aria-pressed'),'true');
getButtons(boxes[1])[0].click();assert.equal(selects[1].value,'1');
getButtons(boxes[1])[1].click();assert.equal(selects[1].value,'');
selects[0].value='0';handlers.pageshow.forEach(fn=>fn());
assert.equal(selects[0].value,'','legacy negative state normalizes to all');
assert.equal(getButtons(boxes[0])[1].getAttribute('aria-pressed'),'true','negative-only button does not exist');
// Active filters must be legible in a collapsed panel and visibly selected in the form.
getButtons(boxes[0])[0].click();
getButtons(boxes[1])[0].click();
form.dispatchEvent({type:'change'});
assert.ok(details.classList.contains('kgds-has-active-filters'),'filter disclosure highlights active criteria');
assert.ok(status.children.some(x=>x.classList.contains('kgds-filter-status-count')&&x.textContent==='4개 적용'),'count includes two positive switches, region and max tuition');
assert.ok(regionLabel.classList.contains('kgds-filter-active-label'),'selected region label highlighted');
assert.ok(priceLabel.classList.contains('kgds-filter-active-label'),'selected numeric label highlighted');
// A single reset preserves only the typed search keyword, never the native filters.
const reset=form.children.find(c=>c.classList.contains('kgds-filter-clear'));
assert.ok(reset,'visible reset button is added to native filter form');
assert.equal(reset.textContent,'필터 초기화');
reset.click();
const navigated=new URL(sandbox.lastNavigation);
assert.equal(navigated.pathname,'/ko/universities/');
assert.equal(navigated.searchParams.get('q'),'KAIST');
assert.equal([...navigated.searchParams.keys()].join(','),'q','all filter parameters removed');
assert.equal(region.value,'','region resets to all');
assert.equal(price.value,'','tuition resets to no bounds');
assert.equal(selects[0].value,'','certified resets to all');
assert.equal(selects[1].value,'','English-only resets to all');
assert.equal(keywordField.value,'KAIST','keyword input survives');
assert.ok(!details.classList.contains('kgds-has-active-filters'),'active filter highlight removed');
console.log('PASS: two choices, 4 active criteria with summary/label highlights, keyword-only reset removes ALL conditions and preserves keyword');
