/* P08 DOM-lite regression: obsolete async university responses never repopulate the selector. */
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
class El {
  constructor(value=''){this.value=value;this.disabled=false;this.hidden=true;this.textContent='Load programs';this.options=[];this.handlers={};this.dataset={endpoint:'/wp-json/kgpa/v1/offerings'};this.aria={};}
  addEventListener(name,fn){this.handlers[name]=fn;}
  replaceChildren(...items){this.options=items;}
  add(option){this.options.push(option);}
  setAttribute(key,val){this.aria[key]=val;}
  fire(name,arg={}){return this.handlers[name]?.(arg);}
}
const fields={'kgpa-inquiry-form':new El(),'kgpa-preferred-uni':new El(''),
  'kgpa-load-university':new El('Load programs'),'kgpa-offering':new El(),
  'kgpa-offerings-status':new El(), 'kgpa-send':new El()};
const jobs=[];
const window={};
const context={document:{documentElement:{lang:'en'},getElementById:id=>fields[id]||null},location:{origin:'https://example.test'},URL,
  Option:class {constructor(text,value){this.text=text;this.value=value;}},AbortController,
  fetch:(url,opts)=>new Promise((res,rej)=>jobs.push({url,opts,res,rej})),
  alert:()=>{throw Error('Intrusive alert should not be used.');}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/contact.js'),'utf8'),context);
const uni=fields['kgpa-preferred-uni'];
const load=fields['kgpa-load-university'];
const offering=fields['kgpa-offering'];
const status=fields['kgpa-offerings-status'];
const flush=()=>new Promise(r=>setImmediate(r));
(async()=>{
  uni.value='UNI-A';uni.fire('change');
  assert.equal(jobs.length,1);
  assert.equal(offering.options.length,1);
  assert.equal(load.disabled,true);
  uni.value='UNI-B';uni.fire('change');
  assert.equal(jobs.length,2,'Second university fetch must not be blocked by the previous loading flag.');
  assert.equal(jobs[0].opts.signal.aborted,true);
  jobs[1].res({ok:true,json:async()=>({items:[{id:'B-1',label:'Program B'}]})});
  await flush();await flush();
  assert.deepEqual(offering.options.map(o=>o.value),['','B-1']);
  assert.equal(load.disabled,false);
  jobs[0].res({ok:true,json:async()=>({items:[{id:'A-1',label:'STALE'}]})});
  await flush();await flush();
  assert.deepEqual(offering.options.map(o=>o.value),['','B-1'],'Stale response must not be shown.');
  uni.value='UNI-A';uni.fire('change');
  assert.deepEqual(offering.options.map(o=>o.value),[''],'Changing university clears a previous offering immediately.');
  jobs[2].res({ok:false});await flush();await flush();
  assert.match(status.textContent,/Could not load/);
  assert.equal(load.disabled,false);
  assert.equal(status.hidden,false);
  uni.value='';uni.fire('change');await flush();
  assert.deepEqual(offering.options.map(o=>o.value),['']);
  assert.equal(status.hidden,true);
  console.log('PASS: W3-1 P08 offering dropdown ignores stale responses, clears mismatched selection and announces failures.');
})().catch(err=>{console.error(err);process.exitCode=1;});
