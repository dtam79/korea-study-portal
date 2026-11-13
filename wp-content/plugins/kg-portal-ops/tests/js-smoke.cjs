'use strict';
const fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/operator.js'),'utf8');
async function check(page){
  const handlers={};const calls=[];
  const root={innerHTML:'',addEventListener:(event,fn)=>{handlers[event]=fn;},setAttribute(){},removeAttribute(){},contains(){return true;}};
  const sample={
    'overview':{counts:{due:2,pending:1,in_progress:0,resolved:0},settings:{monitor_enabled:false,last_run_at:null,batch_size:15},wp_cron_next:null,core_ready:true},
    'sources':{items:[{id:5,document_title:'University <script>doNotRun()</script>',public_url:'https://example.org/admissions',evidence_status:'verified',category:'admissions',next_check_at:'2026-11-14 00:00:00',last_result:'changed',assigned_to:2}],total:1,page:1,per_page:25,total_pages:1},
    'tasks':{items:[{id:12,source_id:5,reason_code:'possible_change',status_code:'pending',document_title:'Official <img src=x onerror=alert(1)>',public_url:'https://example.org/guide',opened_at:'2026-11-13 00:00:00',check_id:44}],total:1,page:1,per_page:25,total_pages:1},
    'settings':{monitor_enabled:false,strict_no_cache:true,batch_size:15,fetch_timeout:7,max_sample_bytes:131072,notifications_email:''},
    'seo/preview':{staging:true,sitemap_url:'https://example.test/kgp-sitemap.xml',published_universities:0,published_articles:0},
  };
  const conf={root:'https://example.test/wp-json/kgpo/v1/',nonce:'CHECKNONCE',page,manager:true,reviewer:true,
    adminBase:'https://example.test/wp-admin/admin.php?page=',coreSourceUrl:'https://example.test/wp-admin/admin.php?page=kgp-operator-sources'};
  const fakeFetch=async(url,opts)=>{
    calls.push({url,opts});
    const key=Object.keys(sample).find(x=>url.includes('/'+x))||'overview';
    return {ok:true,status:200,json:async()=>sample[key]};
  };
  const ctx={window:{KGPO_ADMIN:conf},document:{getElementById:(id)=>id==='kgpo-dashboard'?root:null},
    fetch:fakeFetch,console,URL,URLSearchParams,Number,String,Math,Set,Error};
  vm.runInNewContext(source,ctx,{filename:'operator.js'});
  await new Promise(resolve=>setTimeout(resolve,20));
  if(!root.innerHTML.includes('kgpo-nav'))throw Error('Missing menu on '+page);
  if(root.innerHTML.includes('<script>doNotRun()')||root.innerHTML.includes('<img src=x'))throw Error('Unescaped official source title on '+page);
  if(!calls.length||calls.some(x=>x.opts.method!=='GET'||x.opts.headers['X-WP-Nonce']!=='CHECKNONCE'))throw Error('Missing authenticated read calls '+page);
  return [root,handlers,calls];
}
(async()=>{
 for (const [page,name] of [['kgpo-operations','overview'],['kgpo-sources','sources'],['kgpo-review','queue'],['kgpo-seo','seo']]){
   const [root,,calls]=await check(page);console.log('PASS:',name, 'rendered and escaped, read requests:', calls.length);
 }
})().catch(e=>{console.error('FAIL:',e.message);process.exit(1)});
