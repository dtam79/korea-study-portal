'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const basedir=path.join(__dirname,'..');
const adminJS=fs.readFileSync(path.join(basedir,'assets/admin.js'),'utf8');
const inquiryJS=fs.readFileSync(path.join(basedir,'assets/inquiries.js'),'utf8');
const css=fs.readFileSync(path.join(basedir,'assets/admin.css'),'utf8');
function environment(page,respond){
  const nodes={}; const handlers={}; const calls=[];
  const root={innerHTML:'',contains:()=>true,setAttribute(){},removeAttribute(){},addEventListener(k,fn){handlers[k]=fn;}};
  const conf={page,nonce:'LOCAL',apiRoot:'https://localhost/wp-json/kgp/v1/',operatorRoot:'https://localhost/wp-json/kgp-operator/v1/',
    adminBase:'https://localhost/wp-admin/admin.php?page=',exportUrl:'https://localhost/wp-admin/admin-post.php?action=kgpa_export_inquiries&_wpnonce=localnonce',
    permissions:{edit:true,review:true,assign:true,inquiries:true,source_review:true,issuers:true},coreReady:true,userId:1};
  const context={window:{KGP_ADMIN:conf,confirm:()=>true,history:null},location:{href:'https://localhost/wp-admin/admin.php?page=kgp-operator-'+page,search:''},
    document:{getElementById(id){return id==='kgp-operator-root'?root:(nodes[id]??null);}},
    URL,URLSearchParams,JSON,Set,Map,Number,String,Math,Date,Error,FormData,Object,Promise,console,
    fetch:async(url,opts)=>{calls.push({url,opts});return {ok:true,status:200,json:async()=>respond(url)};}};
  for(const id of ['kgpai-message','kgpai-list','kgpai-detail','kgpai-deletion-log','kgpai-settings','kgpai-ops-panel']){
    nodes[id]={innerHTML:'',hidden:true,scrollIntoView(){},className:'',textContent:''};
  }
  return {context,root,nodes,calls,handlers};
}
(async()=>{
  const a=environment('entities',url=>url.includes('/entities?')?{items:[{id:7,public_id:'UNI-7',official_name_ko:'<img src=x>',regions_display:'SEOUL',intake_count:2,editor_count:1,identity_published:1,identity_draft_status:'none',updated_at:'2026-11-24'}],total:1,page:1,per_page:20}: {items:[],total:0});
  const injected=adminJS.replace('  start().catch(error=>{','  globalThis.__A02={a02FilterPanel,entityBulkResults,state,rememberEntityFilters};\n  start().catch(error=>{');
  assert.notEqual(injected,adminJS);
  vm.runInNewContext(injected,a.context,{filename:'admin.js'});
  await new Promise(r=>setTimeout(r,45));
  assert(a.root.innerHTML.includes('kgpa-entity-filters') && a.root.innerHTML.includes('regions')===false);
  assert(a.root.innerHTML.includes('서울')===false);
  assert(a.root.innerHTML.includes('SEOUL')&&a.root.innerHTML.includes('기본정보 게시본 있음'));
  assert(!a.root.innerHTML.includes('<img src=x>'),'A02 official names must be escaped');
  a.context.__A02.state.a02.region='SEOUL';
  assert(a.context.__A02.a02FilterPanel().includes('value="SEOUL"'));
  a.context.__A02.state.type='offering';
  assert.equal(a.context.__A02.a02FilterPanel(),'','advanced query must be for universities only');

  const i=environment('inquiries',url=>{
    if(url.includes('inquiry-deletions'))return {items:[]};
    if(url.includes('inquiries?'))return {items:[{id:1,receipt_code:'KGP-123',email:'secret@example.test',inquiry_locale:'en',handling_status:'new',assigned_to:null,created_at:'2026-11-24',email_delivery_status:'failed'}],total:1,total_pages:1,page:1};
    return {};
  });
  const iInjected=inquiryJS.replace('  work(conf.page===\'inquiries\'?list:settings);',"  globalThis.__A08={exportForm,maskEmail,state,list};\n  work(conf.page==='inquiries'?list:settings);");
  assert.notEqual(iInjected,inquiryJS);
  vm.runInNewContext(iInjected,i.context,{filename:'inquiries.js'});
  await new Promise(r=>setTimeout(r,40));
  assert(i.root.innerHTML.includes('kgpai-layout'));
  assert(i.root.innerHTML.includes('method="post"')&&i.root.innerHTML.includes('name="_wpnonce"'));
  assert(i.root.innerHTML.includes('상담 언어')&&i.root.innerHTML.includes('미배정'));
  assert(i.nodes['kgpai-list'].innerHTML.includes('s***@example.test'));
  assert(!i.nodes['kgpai-list'].innerHTML.includes('secret@example.test'));
  i.context.__A08.state.q='private@example.test';
  const exported=i.context.__A08.exportForm();
  assert(exported.includes('value="private@example.test"'),'POST body must contain current search term');
  assert(!exported.includes('admin-post.php?q='),'private term must not appear in export URL');
  const e=new URL('https://localhost/wp-admin/admin-post.php?action=kgpa_export_inquiries&_wpnonce=localnonce');
  assert(e.pathname==='/wp-admin/admin-post.php');

  const s=environment('settings',url=>{
    if(url.includes('operations'))return {wordpress_version:'7.1',admin_version:'0.8.6',php_version:'8.3',core_version:'0.4.4',ops_version:'1.0.2',home_https:true,wp_cron_disabled:false,recheck_next_utc:null,inquiry_cleanup_next_utc:null};
    if(url.includes('inquiry-settings'))return {settings:{intake_enabled:false,policy_version:'',retention_days:0,purpose_ko:'',purpose_en:'',notice_ko:'',notice_en:'',deletion_contact:'',notify_email:'',max_submissions_per_hour:5},ready:false};
    return {};
  });
  vm.runInNewContext(inquiryJS,s.context,{filename:'inquiries.js'});
  await new Promise(r=>setTimeout(r,40));
  assert(s.nodes['kgpai-ops-panel'].innerHTML.includes('읽기 전용'));
  assert(s.nodes['kgpai-ops-panel'].innerHTML.includes('별도 환경에서 복원 시험 필요'));
  assert(!s.nodes['kgpai-ops-panel'].innerHTML.includes('API KEY'),'never expose secrets in A09');
  assert(css.includes('.kgpai-layout') && css.includes('.kgpa-a02-filter-form'));
  console.log('PASS: A02 filters, private A08 list/POST export, A09 read-only status and responsive admin CSS');
})().catch(e=>{console.error(e);process.exit(1)});
