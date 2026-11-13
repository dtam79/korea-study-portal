'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const script=fs.readFileSync(path.join(__dirname,'../assets/admin.js'),'utf8');
const views=['overview','entities','sources','fields','review'];
function htmlEscape(s){return String(s).replace(/</g,'&lt;').replace(/>/g,'&gt;');}
async function check(view){
  const calls=[];
  const root={innerHTML:'',addEventListener(){},setAttribute(){},removeAttribute(){}};
  const data=(url)=>{
    if(url.includes('/health'))return {core_version:'0.4.0',table_count:20,db_complete:true};
    if(url.includes('/summary'))return {pending_identities:1,pending_field_versions:2};
    if(url.includes('/catalog')&&url.includes('kgp-operator'))return {sections:{'DET-01':{title:'입학 조건',fields:{eligibility:'localized_text'}}}};
    if(url.includes('/entities'))return {items:[{id:1,public_id:'uni-abc',official_name_ko:'Unsafe <script> BAD',official_name_en:'Safe University'}],total:1,page:1,per_page:20};
    if(url.includes('/sources')||url.includes('/issuers'))return {items:[],total:0,page:1,per_page:20};
    if(url.includes('/queue'))return {items:[],total:0,page:1,per_page:12};
    return {};
  };
  const stub={
    window:{KGP_ADMIN:{page:view,apiRoot:'https://example.test/wp-json/kgp/v1/',operatorRoot:'https://example.test/wp-json/kgp-operator/v1/',nonce:'TESTNONCE',adminBase:'https://example.test/wp-admin/admin.php?page=',coreReady:true,permissions:{edit:true,review:true,source_review:true,issuers:true,assign:true}}},
    document:{getElementById:(id)=>id==='kgp-operator-root'?root:null},
    location:{search:''},
    fetch:async(url,options)=>{calls.push({url,options});return {ok:true,status:200,json:async()=>data(url)};},
    URL,URLSearchParams,console,Number,String,Date,Set,Math,FormData,Error,
  };
  vm.runInNewContext(script,stub,{filename:'admin.js'});
  await new Promise(resolve=>setTimeout(resolve,25));
  if(!root.innerHTML.includes('kgpa-'))throw new Error('Empty operator UI: '+view);
  if(root.innerHTML.includes('<script> BAD'))throw new Error('Unescaped private data in operator UI');
  if(view==='entities'&&!root.innerHTML.includes(htmlEscape('Unsafe <script> BAD')))throw new Error('Private entity row not safely escaped');
  if(calls.some(c=>c.options.method!=='GET'||c.options.headers['X-WP-Nonce']!=='TESTNONCE'))throw new Error('Unsafe bootstrap REST request '+view);
  return calls.length;
}
(async()=>{for(const view of views) console.log('PASS:',view,await check(view),'authenticated read requests');})().catch(e=>{console.error('FAIL:',e);process.exit(1)});

// Test an actual reviewer action bound through the delegated click handler.
async function checkReviewerPublish(){
  const handlers={};const calls=[];
  const root={innerHTML:'',addEventListener:(event,fn)=>{handlers[event]=fn;},setAttribute(){},removeAttribute(){},contains:()=>true};
  const stub={
    window:{KGP_ADMIN:{page:'review',apiRoot:'https://example.test/wp-json/kgp/v1/',operatorRoot:'https://example.test/wp-json/kgp-operator/v1/',nonce:'NONCE',adminBase:'https://example.test/wp-admin/admin.php?page=',coreReady:true,permissions:{edit:true,review:true,source_review:true,issuers:true,assign:true}}},
    document:{getElementById:(id)=>id==='kgp-operator-root'?root:id==='kgpa-review-check-7'?{checked:true}:null},
    location:{search:''},
    fetch:async(url,options)=>{
      calls.push({url,options});
      const res=url.includes('/queue')?{items:[{entry_id:7,entity_type:'university',entity_id:1,section_code:'DET-01',field_key:'eligibility',version_number:1,created_by:2,value:'<script>x</script>',source_links:[{source_id:3,source:{currently_valid:true,document_title:'Official guide',public_url:'https://example.org/guide'}}]}],total:1,page:1,per_page:12}: {status:'published'};
      return {ok:true,status:200,json:async()=>res};
    },
    URL,URLSearchParams,console,Number,String,Date,Set,Math,FormData,Error,
  };
  vm.runInNewContext(script,stub,{filename:'admin.js'});
  await new Promise(resolve=>setTimeout(resolve,20));
  if(root.innerHTML.includes('<script>x</script>')||!root.innerHTML.includes('&lt;script&gt;x&lt;/script&gt;'))throw new Error('Reviewer value not HTML-escaped');
  const btn={dataset:{action:'review-field-publish',id:'7'}};
  handlers.click({target:{closest:()=>btn}});
  await new Promise(resolve=>setTimeout(resolve,20));
  const post=calls.find(x=>x.url.includes('/admin/fields/7/publish'));
  if(!post||post.options.method!=='POST'||post.options.headers['X-WP-Nonce']!=='NONCE')throw new Error('Review approval request not bound to authorized Core endpoint');
  console.log('PASS: review publish click is nonce-protected and targets existing Core review API');
}
checkReviewerPublish().catch(e=>{console.error('FAIL:',e);process.exit(1)});
