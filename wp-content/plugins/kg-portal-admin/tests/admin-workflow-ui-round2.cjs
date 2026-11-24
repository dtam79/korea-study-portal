'use strict';
/** UI-only contract test for A02, A05, A07; run with node, no WP or DB writes. */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.join(__dirname,'..');
const read=x=>fs.readFileSync(path.join(root,'assets',x),'utf8');
function stub(page){
  const nodes={},handlers={},calls=[];
  const container={innerHTML:'',addEventListener(k,cb){handlers[k]=cb;},contains(){return true;},setAttribute(){},removeAttribute(){}};
  const common={window:{KGP_ADMIN:{page,nonce:'TEST',apiRoot:'https://localhost/wp-json/kgp/v1/',operatorRoot:'https://localhost/wp-json/kgp-operator/v1/',adminBase:'https://localhost/wp-admin/admin.php?page=',permissions:{edit:true,review:true,assign:true,source_review:true,issuers:true},coreReady:true, userId:9},confirm:()=>true},
    document:{getElementById(id){return id==='kgp-operator-root'?container:(nodes[id]||null);}},location:{search:''},
    URL,URLSearchParams,JSON,Set,Map,Number,String,Math,Date,Error,FormData,Symbol,Object,Array,Promise,console,
    fetch:async(url,options)=>{calls.push({url,options});return {ok:true,status:200,json:async()=>({items:[],total:0,page:1,per_page:20,db_complete:true})}}};
  return {common,nodes,container,handlers,calls};
}
(async()=>{
  const a=stub('entities');
  const aSource=read('admin.js');
  const aInjected=aSource.replace('  start().catch(error=>{', '  globalThis.__testA02={assignSelectedUniversities,entityBulkResults,bulkActionPanel,state};\n  start().catch(error=>{');
  assert.notEqual(aSource,aInjected,'Cannot inject A02 test bridge');
  vm.runInNewContext(aInjected,a.common,{filename:'admin.js'});
  const core=a.common.__testA02;
  assert(core&&typeof core.assignSelectedUniversities==='function');
  const calls=[];
  const res=await core.assignSelectedUniversities([101,102,103],7,async(route,method,payload)=>{
    calls.push({route,method,payload});
    if(payload.university_id===102)throw Error('권한이 없는 대학');
    return {id:30};
  });
  assert.deepEqual(Array.from(res,x=>x.ok),[true,false,true]);
  assert.equal(res[1].error,'권한이 없는 대학');
  assert(calls.every(c=>c.route==='admin/assignments'&&c.method==='POST'&&c.payload.user_id===7),'must reuse existing server-authorized endpoint');
  assert(calls.every(c=>Object.keys(c.payload).sort().join(',')==='university_id,user_id'));
  core.state.type='university';core.state.bulkResults=res;core.state.selectedUniversities=new Set([102]);
  const out=core.entityBulkResults();
  assert(out.includes('2건 / 실패 1건'));
  assert(!out.includes('승인·게시'));
  assert(core.bulkActionPanel().includes('kgpa-bulk-assign-form'));
  core.state.type='program';assert(!core.bulkActionPanel().includes('kgpa-bulk-assign-form'));

  const w=stub('history');
  const wSource=read('workflows.js');
  const wInjected=wSource.replace('  ({translations:translationPage,imports:csvPage,history:historyPage})[conf.page]()',
    '  globalThis.__testWork={translationRows,translationEditor,collectTranslationDraft,translationComparison,diffRows,diffTable,state};\n  ({translations:translationPage,imports:csvPage,history:historyPage})[conf.page]()');
  assert.notEqual(wSource,wInjected,'Cannot inject workflow test bridge');
  vm.runInNewContext(wInjected,w.common,{filename:'workflows.js'});
  const workflow=w.common.__testWork;
  assert(workflow);
  const source={name:'Korean Name',amount:200000,currency:'KRW',application_url:'https://uni.ac.kr/en',date:'2027-03-01',
    condition:'Officially accepted',scholarships:[{description:'Original text',amount:50000,year:2027}]};
  const translated={...source,name:'English Name',scholarships:[{description:'Translated text',amount:50000,year:2027}]};
  const leaves=workflow.translationRows(source,translated,'repeat:scholarship');
  assert(leaves.some(x=>x.label==='name'&&x.writable));
  assert(leaves.some(x=>x.label==='scholarships[1].description'&&x.writable));
  for(const name of ['amount','currency','application_url','date'])assert(leaves.some(x=>x.label===name&&!x.writable),'immutable '+name);
  assert(!leaves.some(x=>x.label==='scholarships[1].amount'&&x.writable));
  const html=workflow.translationEditor({source_version_id:8,source_value:source,source_value_type:'repeat:scholarship',
    draft:{revision:1,status:'draft',translated_json:JSON.stringify(translated)},can_submit:true});
  assert(html.includes('항목별 입력')&&html.includes('고급 JSON 입력'));
  assert(!html.includes('data-i18n-index="1"'),'immutable amount must not be editable');
  const inputs=workflow.state.translationRows;
  inputs.forEach((x,i)=>{if(x.writable)w.nodes['kgpaw-text-'+i]={value:'Human verified: '+i};});
  const saved=workflow.collectTranslationDraft('structured');
  assert.equal(saved.amount,200000);assert.equal(saved.currency,'KRW');assert.equal(saved.date,'2027-03-01');
  assert.equal(saved.application_url,'https://uni.ac.kr/en');assert.equal(saved.scholarships[0].amount,50000);
  assert.equal(saved.scholarships[0].year,2027);
  assert(saved.name.startsWith('Human verified:'));
  assert(saved.scholarships[0].description.startsWith('Human verified:'));
  assert.throws(()=>workflow.translationRows({name:'x'},{wrong:'y'},'official_guidance'),/구조/);
  const safe=workflow.translationComparison({description:'<img src=x onerror=alert(1)>'},{description:'<script>alert(1)</script>'},'official_guidance',true);
  assert(!safe.includes('<img')&&!safe.includes('<script>'),'escaped source/draft data');
  assert(safe.includes('&lt;img')&&safe.includes('&lt;script&gt;'));
  const diff=workflow.diffRows({a:[{amount:100,description:'old'}],source:{id:1}},
    {a:[{amount:100,description:'new'},{amount:200}],source:{id:2}});
  assert(diff.some(r=>r.path==='a[1].description'&&r.changed));
  assert(diff.some(r=>r.path==='a[2]'&&r.changed));
  assert(diff.some(r=>r.path==='source.id'&&r.changed));
  assert(diff.some(r=>r.path==='a[1].amount'&&!r.changed));
  const difHTML=workflow.diffTable({notes:'<svg onload=alert(1)>'},{notes:'after'},'차이');
  assert(!difHTML.includes('<svg onload'));
  assert(difHTML.includes('&lt;svg'));
  assert(difHTML.includes('kgpaw-diff-changed'));
  const css=read('admin.css');
  assert(css.includes('.kgpa-bulk-panel')&&css.includes('.kgpaw-compare-table')&&css.includes('.kgpaw-diff-table'));
  const routes=fs.readFileSync(path.join(root,'inc','workflow','class-routes.php'),'utf8');
  assert(routes.includes("'/translations/version/(?P<id>\\d+)/save'")&&routes.includes("'/history/entry/(?P<id>\\d+)/compare'"));
  console.log('PASS: A02 existing assignment endpoint/partial failures; A05 immutable shared values/escaped structured edit; A07 nested/source diffs; no new publishing routes');
})().catch(e=>{console.error(e);process.exit(1)});
