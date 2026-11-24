'use strict';
const fs=require('fs');
const vm=require('vm');
const path=require('path');
const assert=require('assert');
const script=fs.readFileSync(path.join(__dirname,'../assets/admin.js'),'utf8');
const integration=script.replace('  start().catch(error=>{', '  globalThis.__kgpa_qa={composeStructuredValue,structuredHelper};\n  start().catch(error=>{');
assert.notStrictEqual(integration,script,'test bridge could not access pure value composer');
function launch(page, responses){
  const handlers={};const calls=[];const nodes={};
  const root={innerHTML:'',addEventListener(event,cb){handlers[event]=cb},setAttribute(){},removeAttribute(){},contains(){return true}};
  const env={
    window:{KGP_ADMIN:{page,apiRoot:'https://example.test/wp-json/kgp/v1/',operatorRoot:'https://example.test/wp-json/kgp-operator/v1/',nonce:'TEST',adminBase:'https://example.test/wp-admin/admin.php?page=',coreReady:true,permissions:{edit:true,review:true,source_review:true,issuers:true,assign:true,inquiries:true}}},
    document:{getElementById(id){return id==='kgp-operator-root'?root:(nodes[id]||null)}},
    location:{search:''},
    fetch:async(url,options)=>{calls.push({url,options});const body=responses.find(([part])=>url.includes(part))?.[1]||{};return {ok:true,status:200,json:async()=>body}},
    URL,URLSearchParams,console,Number,String,Date,Set,Math,FormData,Error,
  };
  vm.runInNewContext(integration,env,{filename:'admin.js'});
  return {root,calls,handlers,nodes,env};
}
const qa=launch('overview', [['health',{core_version:'0.4.5',table_count:20,db_complete:true}],['admin/summary',{pending_identities:3,pending_field_versions:4}]]);
const test=qa.env.__kgpa_qa;
assert(test&&typeof test.composeStructuredValue==='function');
const compose=test.composeStructuredValue;
let money=compose('money_item',{currency:'KRW',unit:'semester',amount:'12000',applicability:'foreign'},{mode:'range',unit:'year',min_amount:'100',max_amount:'200',label:'Tuition'});
assert.strictEqual(money.applicability,'foreign','metadata must be preserved');
assert.strictEqual(money.amount,undefined,'amount must be removed from range');
assert.strictEqual(money.min_amount,'100');
assert.strictEqual(money.max_amount,'200');
assert.throws(()=>compose('money_item',{}, {mode:'range',unit:'month',min_amount:'300',max_amount:'50'}));
assert.throws(()=>compose('money_item',{}, {mode:'amount',unit:'semester',amount:'-12'}));
assert.throws(()=>compose('date_with_precision',{}, {date:'2027-01-01T13:00',precision:'minute',timezone:''}));
const date=compose('date_with_precision',{timezone:'Asia/Seoul',applicability:'graduate'},{date:'2027-01-01',precision:'day',timezone:''});
assert.strictEqual(date.timezone,undefined);
assert.strictEqual(date.applicability,'graduate');
const duration=compose('duration',{remark:'official'},{value:'8',unit:'week'});
assert.strictEqual(duration.value,8);assert.strictEqual(duration.remark,'official');
assert(test.structuredHelper('money_item',{amount:'300',unit:'semester'}).includes('data-kind="money_item"'));
const adminCss=fs.readFileSync(path.join(__dirname,'../assets/admin.css'),'utf8');
const workflowJs=fs.readFileSync(path.join(__dirname,'../assets/workflows.js'),'utf8');
const php=fs.readFileSync(path.join(__dirname,'../inc/class-admin.php'),'utf8');
assert(adminCss.includes('.kgpa-review-columns') && adminCss.includes('.kgpa-field-editor-grid'));
assert(workflowJs.includes('csvSteps(0)') && workflowJs.includes('csvSteps(2)'));
assert(php.includes('대시보드')&&php.includes('변경 이력·복구'));
assert(!workflowJs.includes('root.innerHTML=navBar()+') && !script.includes('elt(nav()+') );
(async()=>{
  await new Promise(r=>setTimeout(r,35));
  assert(qa.root.innerHTML.includes('기본정보 검토 대기'));
  assert(qa.root.innerHTML.includes('3건'));
  assert(qa.root.innerHTML.includes('4건'));
  assert(!qa.root.innerHTML.includes('class="kgpa-nav"'));
  const rv=launch('review',[['queue',{items:[{entry_id:7,entity_type:'university',entity_id:5,section_code:'DET-01',field_key:'eligibility',version_number:1,created_by:2,value:{eligibility:'<img src=x>'},source_links:[{source_id:1,source:{currently_valid:true,document_title:'Official',public_url:'https://example.com'}}]}],total:1,page:1,per_page:12}],['versions/7',{entry:{published_version_id:10},versions:[{id:10,value_json:'{"old":"published"}',source_links:[]}]}]]);
  await new Promise(r=>setTimeout(r,35));
  assert(rv.root.innerHTML.includes('kgpa-review-columns'));
  assert(rv.root.innerHTML.includes('제출 초안')&&rv.root.innerHTML.includes('기존 공개본'));
  assert(!rv.root.innerHTML.includes('<img src=x>'));
  rv.nodes['kgpa-review-before-field-7']={innerHTML:''};
  rv.handlers.click({target:{closest:()=>({dataset:{action:'review-field-previous',id:'7'},closest:()=>null})}});
  await new Promise(r=>setTimeout(r,30));
  assert(rv.nodes['kgpa-review-before-field-7'].innerHTML.includes('published'));
  const get=rv.calls.find(x=>x.url.includes('versions/7'));
  assert(get&&get.options.method==='GET'&&get.options.headers['X-WP-Nonce']==='TEST');
  assert(!rv.calls.some(x=>x.options.method!=='GET'),'compare must remain read-only');
  console.log('PASS: admin UX alignment, money/date/duration safe composition, review-side comparison, no writes');
})().catch(e=>{console.error(e);process.exit(1)});
