'use strict';
/** Executes production site.js with no filter form, simulating P03 navigation. */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const code=fs.readFileSync(path.join(__dirname,'../assets/js/site.js'),'utf8');
let expanded=0,focused=0,clickHandler=null,hashHandler=null;
const summary={focus(options){assert.equal(options.preventScroll,true);focused++;}};
const target={tagName:'DETAILS',classList:{contains:x=>x==='kgp-detail-section'},open:false,querySelector:key=>key==='summary'?summary:null};
const document={
  getElementById(id){return id==='kgp-det-DET-03'?target:null;},
  addEventListener(name,handler){if(name==='click')clickHandler=handler;},
};
const win={location:{hash:'#kgp-det-DET-03'},addEventListener(name,handler){if(name==='hashchange')hashHandler=handler;}};
vm.runInNewContext(code,{document,window:win,requestAnimationFrame:fn=>fn(),console});
assert.equal(target.open,true,'direct hash opens a published detail section');
assert.equal(typeof clickHandler,'function');
assert.equal(typeof hashHandler,'function');
target.open=false;
const link={getAttribute:key=>key==='href'?'#kgp-det-DET-03':null};
clickHandler({target:{closest:sel=>sel.includes('.kgp-detail-toc')?link:null}});
assert.equal(target.open,true,'TOC click expands target');
assert.equal(focused,1,'TOC click moves keyboard focus to summary');
target.open=false;
win.location.hash='#kgp-det-DET-03';hashHandler();
assert.equal(target.open,true,'browser hash navigation expands target');
target.open=false;
win.location.hash='#kgp-det-DET-99';hashHandler();
assert.equal(target.open,false,'unknown DET link has no effect');
clickHandler({target:{closest:()=>null}});
assert.equal(focused,1,'unrelated clicks do not move focus');
console.log('SUCCESS: W2-1 P03 TOC opens approved sections for direct hash, click and history navigation.');
