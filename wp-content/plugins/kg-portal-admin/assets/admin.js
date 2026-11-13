/* KG Portal Admin 0.7.0 — vanilla JS, WordPress cookie+nonce auth, server-enforced roles. */
(() => {
  'use strict';
  const conf = window.KGP_ADMIN;
  const root = document.getElementById('kgp-operator-root');
  if (!conf || !root || ['inquiries','settings'].includes(conf.page)) return;
  const perms = conf.permissions || {};
  const state = {page:conf.page, type:'university', q:'', pageNumber:1,
    sourcePage:1, sourceKind:'sources', reviewKind:'field', reviewPage:1,
    entity:null, identity:null, records:[], fieldTarget:null, fieldList:[],
    fieldCatalog:null, draft:null, draftSources:[], currentSourceList:[], sources:[],
    loading:false, busy:false};
  const params = new URLSearchParams(location.search);
  if (['university','campus','program','intake','offering'].includes(params.get('type'))) state.type=params.get('type');
  if (params.has('id') && /^\d+$/.test(params.get('id'))) state.fieldTarget={type:state.type,id:Number(params.get('id'))};
  const types=['university','campus','program','intake','offering'];
  const title={university:'대학',campus:'캠퍼스',program:'전공·과정',intake:'모집 회차',offering:'조건 묶음(Offering)'};
  const fields={
    university:[['public_id','고유 ID','text',true],['official_name_ko','공식 한국어명','text',true],['official_name_en','공식 영어명','text',true],['official_website','공식 홈페이지 URL','url',true],['institution_type','설립 유형','select',false,['public','private','other']]],
    campus:[['public_id','고유 ID','text',true],['university_id','대학 내부 ID','number',true],['official_name_ko','캠퍼스 한국어명','text',true],['official_name_en','영문명','text',false],['region_code','지역 코드','text',false],['address_ko','공식 주소','text',false],['address_en','영문 주소','text',false],['is_primary','주 캠퍼스','boolean',false]],
    program:[['public_id','고유 ID','text',true],['university_id','대학 내부 ID','number',true],['campus_id','캠퍼스 내부 ID','number',true],['official_name_ko','공식 한국어명','text',true],['official_name_en','공식 영어명','text',false],['field_code','전공 분야 코드','text',true],['level_code','학위/과정 코드','text',true],['language_code','기본 언어 코드','text',false],['department_url','학과 URL','url',false]],
    intake:[['public_id','고유 ID','text',true],['university_id','대학 내부 ID','number',true],['academic_year','모집 학년도','number',true],['academic_term','학기 코드','text',true],['admission_type','전형 구분','text',true],['start_date','접수 시작일','date',false],['end_date','접수 종료일','date',false],['application_url','공식 접수 URL','url',false]],
    offering:[['public_id','고유 ID','text',true],['program_id','전공 내부 ID','number',true],['intake_id','회차 내부 ID','number',true],['variant_code','전형 구분 코드','text',false]],
  };
  // Fields that the Core repository accepts during updates; identity and relations are deliberately immutable.
  const editable={
    university:['official_name_ko','official_name_en','official_website','institution_type'],
    campus:['official_name_ko','official_name_en','region_code','address_ko','address_en','is_primary'],
    program:['official_name_ko','official_name_en','field_code','level_code','language_code','department_url'],
    intake:['academic_term','admission_type','start_date','end_date','application_url'],
    offering:['variant_code'],
  };
  const h=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const safeUrl=v=>{try {const u=new URL(String(v));return ['https:','http:'].includes(u.protocol)?u.href:'';}catch{return ''}};
  const pretty=v=>JSON.stringify(v??null,null,2);
  const attr=(key,val)=>`data-${key}="${h(val)}"`;
  const adminLink=(page,extras={})=>conf.adminBase+encodeURIComponent(page)+(Object.keys(extras).length?'&'+new URLSearchParams(extras).toString():'');
  const cap=(name)=>!!perms[name];
  const canCreateEntity=type=>type==='university'?cap('assign'):cap('edit');
  const canEditEntity=type=>type==='university'?cap('assign'):cap('edit');
  function parseJSON(value){try{return JSON.parse(value??'null')}catch{return null}}
  function elt(html){root.innerHTML=html;}
  function note(message,kind='info'){return `<div role="status" class="kgpa-note kgpa-${h(kind)}">${h(message)}</div>`;}
  function report(error){
    const el=document.getElementById('kgpa-feedback');
    if(el){el.textContent=String(error instanceof Error?error.message:error);el.className='kgpa-notice kgpa-notice-error';el.hidden=false;el.scrollIntoView({block:'nearest'});}
  }
  function success(message){const el=document.getElementById('kgpa-feedback');if(el){el.textContent=message;el.className='kgpa-notice kgpa-notice-success';el.hidden=false;}}
  async function request(kind,path,method='GET',body=null){
    const base=kind==='operator'?conf.operatorRoot:conf.apiRoot;
    const response=await fetch(base+path.replace(/^\//,''),{
      method, credentials:'same-origin', headers:{'X-WP-Nonce':conf.nonce,'Accept':'application/json',...(body!==null?{'Content-Type':'application/json'}:{})},
      ...(body!==null?{body:JSON.stringify(body)}:{}),cache:'no-store',redirect:'error',
    });
    let result;
    try {result=await response.json();}catch{throw new Error(`HTTP ${response.status}: JSON 응답을 읽을 수 없습니다.`)}
    if(!response.ok){
      if(response.status===401||response.status===403)throw new Error('권한 또는 세션을 확인해 주세요. 필요하면 다시 로그인하고 화면을 새로고침해 주세요.');
      throw new Error(`${result.message||result.code||'요청 실패'} (HTTP ${response.status})`);
    }
    return result;
  }
  const core=(path,method='GET',body=null)=>request('core',path,method,body);
  const op=(path)=>request('operator',path);
  async function withBusy(job){if(state.busy)return;state.busy=true;root.setAttribute('aria-busy','true');try{await job();}catch(e){report(e)}finally{state.busy=false;root.removeAttribute('aria-busy')}}
  function nav(){const n=[['kgp-operator','대시보드'],['kgp-operator-entities','대학·모집 목록'],['kgp-operator-sources','공식 출처'],['kgp-operator-fields','항목별 데이터'],['kgp-operator-review','검토·게시'],['kgp-operator-translations','번역 관리'],['kgp-operator-imports','CSV 가져오기'],['kgp-operator-history','변경 이력·복구'],['kgp-operator-inquiries','문의 관리'],['kgp-operator-settings','운영 설정']];
    return `<nav class="kgpa-nav" aria-label="운영 메뉴">${n.filter(([id])=>{if(id==='kgp-operator-inquiries')return cap('inquiries');if(id==='kgp-operator-settings')return cap('assign');if(id==='kgp-operator-review')return cap('review');if(id==='kgp-operator-translations'||id==='kgp-operator-history')return cap('edit')||cap('review');if(id==='kgp-operator-imports')return cap('edit');if(id==='kgp-operator-sources')return cap('edit')||cap('source_review')||cap('issuers');if(id==='kgp-operator-fields')return cap('edit')||cap('review');if(id==='kgp-operator-entities')return cap('edit')||cap('review')||cap('assign');return true;}).map(([id,label])=>`<a href="${h(adminLink(id))}" ${id==='kgp-operator'+(state.page==='overview'?'':'-'+state.page)?'aria-current="page"':''}>${h(label)}</a>`).join('')}</nav>`;}
  function frame(title,body){elt(nav()+`<div id="kgpa-feedback" class="kgpa-notice" role="status" aria-live="polite" hidden></div><div class="kgpa-heading"><h2>${h(title)}</h2><p>모든 등록·게시에는 서버 권한과 공식 출처 검증이 적용됩니다.</p></div><div class="kgpa-content">${body}</div>`);}
  const button=(text,action,meta='',variant='secondary')=>`<button type="button" class="button kgpa-button kgpa-button-${h(variant)}" data-action="${h(action)}" ${meta}>${h(text)}</button>`;
  function pager(total,p,size,action){const count=Math.max(1,Math.ceil(total/size));if(count===1)return'';return `<nav class="kgpa-pager" aria-label="페이지 이동">${p>1?button('이전',action,attr('page',p-1)):''}<span>${p} / ${count} · ${total}건</span>${p<count?button('다음',action,attr('page',p+1)):''}</nav>`;}
  function linkToSource(source){const url=safeUrl(source?.public_url||source?.source?.public_url);return url?`<a target="_blank" rel="noopener noreferrer" href="${h(url)}">${h(source?.document_title||source?.source?.document_title||'공식 원문 열기')} ↗</a>`:h(source?.document_title||'원문 링크 없음');}
  function objectView(value){return `<pre class="kgpa-json">${h(pretty(value))}</pre>`;}
  function summaryLabel(row,type){return row.official_name_ko||row.public_id||(type==='intake'?`${row.academic_year||''} ${row.academic_term||''}`:row.variant_code)||`#${row.id}`;}
  function screenError(){frame('연결 상태',note('KG Portal Core v0.4.0 이상이 필요합니다. Core를 활성화한 후 새로고침하세요.','error'));}

  async function overview(){
    frame('운영 대시보드',note('데이터와 권한 정보를 확인하는 중입니다…'));
    const [health,s]=await Promise.all([op('health'),cap('review')||cap('assign')?core('admin/summary').catch(e=>({error:e.message})):Promise.resolve(null)]);
    const ready=health.db_complete;
    const cards=[['Core 버전',health.core_version],['데이터 테이블',`${health.table_count}개 · ${ready?'정상':'설치 확인 필요'}`],['대기 중 기본정보',s?.pending_identities??'검토자 전용'],['대기 중 필드',s?.pending_field_versions??'검토자 전용']];
    const content=`<div class="kgpa-stats">${cards.map(([key,val])=>`<article><small>${h(key)}</small><strong>${h(val)}</strong></article>`).join('')}</div>`+
      (s?.error?note(s.error,'warning'):'')+(!ready?note('데이터베이스가 완전하지 않습니다. Core의 schema status/install 명령어를 확인하세요.','error'):'')+
      `<div class="kgpa-panels"><section><h3>운영 순서</h3><ol><li>대학·캠퍼스·전공·모집 회차·Offering을 등록</li><li>공식 출처기관·자료를 등록하고 원문을 실제로 검증</li><li>기본정보와 항목별 필드를 초안으로 제출</li><li>검토자가 원문과 값을 대조하여 승인 또는 반려</li><li>공개 화면에서 승인된 자료만 조회되는지 확인</li></ol></section><section><h3>바로가기</h3><div class="kgpa-actions"><a class="button button-primary" href="${h(adminLink('kgp-operator-entities'))}">대학 목록 관리</a>${cap('edit')||cap('review')?`<a class="button" href="${h(adminLink('kgp-operator-fields'))}">필드 작성</a>`:''}${cap('edit')||cap('source_review')||cap('issuers')?`<a class="button" href="${h(adminLink('kgp-operator-sources'))}">출처 등록</a>`:''}${cap('review')?`<a class="button" href="${h(adminLink('kgp-operator-review'))}">승인 대기열</a>`:''}</div></section></div>`+
      (cap('assign')?`<section class="kgpa-panel"><h3>정보 담당자 배정</h3><p>WordPress 사용자 ID와 대학 내부 ID를 입력합니다. 배정 후 담당자는 해당 대학의 초안만 수정할 수 있습니다.</p><form id="kgpa-assignment-form" class="kgpa-inline-form"><label>WordPress 사용자 ID<input name="user_id" type="number" min="1" required></label><label>대학 내부 ID<input name="university_id" type="number" min="1" required></label><button type="submit" class="button button-primary">담당자 배정</button></form></section>`:'');
    frame('운영 대시보드',content);
  }

  function fieldInput([key,label,type,required,choices],row=null,mode='create'){
    const lock=mode==='edit'&&!editable[state.type].includes(key);
    if(lock)return '';
    const val=row?.[key]??'';
    const req=required&&mode==='create'?'required':'';
    const help=key.endsWith('_id')?'<small>WordPress 게시물 ID가 아닌 KGP 테이블 내부 ID입니다.</small>':'';
    let input;
    if(type==='select')input=`<select name="${h(key)}" ${req}><option value="">선택 안 함</option>${choices.map(c=>`<option value="${h(c)}" ${String(val)===String(c)?'selected':''}>${h(c)}</option>`).join('')}</select>`;
    else if(type==='boolean')input=`<select name="${h(key)}"><option value="0" ${!val?'selected':''}>아니요</option><option value="1" ${val?'selected':''}>예</option></select>`;
    else input=`<input name="${h(key)}" type="${type==='date'?'date':type==='url'?'url':type==='number'?'number':'text'}" ${req} ${type==='number'?'min="0" step="1"':''} value="${h(val)}" autocomplete="off">`;
    return `<label class="kgpa-field"><span>${h(label)}${req?' <b aria-label="필수">*</b>':''}</span>${input}${help}</label>`;
  }
  function parseForm(form,type,mode){
    const out={};const fd=new FormData(form);
    for(const [key,,kind,required] of fields[type]){
      if(mode==='edit'&&!editable[type].includes(key))continue;
      if(!fd.has(key))continue;
      const raw=String(fd.get(key)).trim();
      if(raw===''&&!required){
        if(mode==='edit'&&['official_name_en','region_code','address_ko','address_en','department_url','application_url','language_code','start_date','end_date'].includes(key))out[key]=null;
        continue;
      }
      if(kind==='number'){
        if(!/^\d+$/.test(raw))throw new Error(`${key}: 양의 정수를 입력하세요.`);
        out[key]=Number(raw);
      }else if(kind==='boolean')out[key]=raw==='1';
      else out[key]=raw;
    }
    return out;
  }
  async function entities(){
    frame('대학·모집 데이터',note('대학 데이터를 읽는 중입니다…'));
    const result=await op(`entities?type=${encodeURIComponent(state.type)}&q=${encodeURIComponent(state.q)}&page=${state.pageNumber}&per_page=20`);
    state.records=result.items||[];
    const select=`<label>관리 대상<select id="kgpa-entity-type">${types.map(t=>`<option value="${h(t)}" ${state.type===t?'selected':''}>${h(title[t])}</option>`).join('')}</select></label>`;
    let table=`<div class="kgpa-table-wrap"><table class="widefat striped kgpa-table"><thead><tr><th scope="col">ID</th><th scope="col">공식 식별 ID</th><th scope="col">데이터</th><th scope="col">작업</th></tr></thead><tbody>`;
    table+=state.records.length?state.records.map(r=>`<tr><td>${h(r.id)}</td><td><code>${h(r.public_id)}</code></td><td>${h(summaryLabel(r,state.type))}</td><td class="kgpa-actions">${button('열기','entity-open',attr('id',r.id))}${cap('edit')||cap('review')?`<a class="button" href="${h(adminLink('kgp-operator-fields',{type:state.type,id:r.id}))}">필드</a>`:''}</td></tr>`).join(''):`<tr><td colspan="4">이 조건에 해당하는 데이터가 없습니다.</td></tr>`;
    table+='</tbody></table></div>';
    frame('대학·모집 데이터',`<section class="kgpa-panel"><div class="kgpa-toolbar">${select}<form id="kgpa-entity-search" class="kgpa-inline-form"><label>현재 종류 검색<input name="q" value="${h(state.q)}" placeholder="ID 또는 공식 명칭"></label><button class="button" type="submit">검색</button></form>${canCreateEntity(state.type)?button('새 데이터 등록','entity-create','', 'primary'):''}</div>${table}${pager(result.total,result.page,result.per_page,'entity-page')}</section><div id="kgpa-entity-editor"></div>`);
    if(state.entity&&state.entity.type===state.type)await openEntity(state.entity.id,false);
  }
  async function openEntity(id,scroll=true){
    const row=await core(`admin/entities/${state.type}/${id}`);
    state.entity={type:state.type,id:Number(id),row};
    const div=document.getElementById('kgpa-entity-editor');if(!div)return;
    let identity=null;
    try{identity=await core(`admin/identities/${state.type}/${id}`);}catch(e){identity={error:e.message}}
    state.identity=identity;
    div.innerHTML=`<section class="kgpa-panel" id="kgpa-entity-current"><h3>${h(title[state.type])} #${h(id)} · ${h(row.public_id||'')}</h3>
        <div class="kgpa-tabs"><span>내부 데이터 수정</span><span>공식 기본정보 검토</span></div>
        <form id="kgpa-entity-update-form" data-id="${h(id)}"><input type="hidden" name="expected_revision" value="${h(row.revision)}"><div class="kgpa-form-grid">${fields[state.type].map(f=>fieldInput(f,row,'edit')).join('')}</div>${canEditEntity(state.type)?'<button class="button button-primary" type="submit">수정 초안 저장</button>':note('현재 계정은 수정 권한이 없습니다.')}</form>
        <hr><section id="kgpa-identity-block">${identity?.error?note(identity.error,'error'):identityPanel(state.type,id,identity?.review)}</section></section>`;
    if(scroll)div.scrollIntoView({block:'start',behavior:'smooth'});
  }
  function identityPanel(type,id,review){
    const rev=review||{};
    const lock=Number(rev.lock_version||0);
    const published=!!rev.published_json;
    const pending=rev.draft_status==='pending';
    const sourceId=Number(rev.draft_source_id||0);
    return `<h3>공식 기본정보 게시 상태</h3><p>${published?'<span class="kgpa-state kgpa-approved">게시본 있음</span>':'<span class="kgpa-state kgpa-muted">게시본 없음</span>'} ${pending?'<span class="kgpa-state kgpa-pending">검토 대기</span>':''} <small>잠금 버전: ${h(lock)}</small></p>
       ${published?`<details><summary>기존 공개 스냅샷 확인</summary>${objectView(parseJSON(rev.published_json))}</details>`:''}
       ${pending?`<details open><summary>검토 요청된 기본정보</summary>${objectView(parseJSON(rev.draft_json))}<p>공식 근거 ID: ${h(sourceId)} · ${h(rev.draft_source_locator||'')}</p></details>`:''}
       ${canEditEntity(type)?`<form class="kgpa-identity-form" id="kgpa-identity-submit" data-type="${h(type)}" data-id="${h(id)}">
       <p>현재 내부 기본정보를 새 승인 후보로 제출합니다. 공개 근거를 먼저 등록하고 원문에서 일치 여부를 확인하세요.</p><div class="kgpa-form-grid">
       <label>공식 출처 ID *<input name="source_id" type="number" min="1" required></label>
       <label>원문 위치(PDF 페이지 등)<input name="source_locator" placeholder="예: 3쪽, 대학 개요 표" maxlength="500"></label>
       ${type==='university'?'<label>공식 확인된 별칭(쉼표 구분)<input name="aliases" maxlength="1000" placeholder="예: official alias"></label>':''}
       </div><input name="expected_lock" type="hidden" value="${h(lock)}"><button type="submit" class="button button-primary">기본정보 검토 요청</button></form>`:''}
       ${cap('review')&&pending?`<div class="kgpa-action-panel"><label><input type="checkbox" id="kgpa-identity-check"> 공식 원문, 발행기관, 적용 회차 및 스냅샷 값을 직접 대조했습니다.</label><div class="kgpa-actions">${button('승인·게시','identity-approve',`${attr('id',id)} ${attr('type',type)} ${attr('lock',lock)}`,'primary')}${button('반려','identity-reject',`${attr('id',id)} ${attr('type',type)} ${attr('lock',lock)}`)}</div><label>반려 사유<textarea id="kgpa-identity-reason" rows="2"></textarea></label></div>`:''}
       ${cap('review')&&published?`<div class="kgpa-danger">${button('기본정보 비공개','identity-unpublish',`${attr('id',id)} ${attr('type',type)}`,'danger')}</div>`:''}`;
  }
  function entityCreateForm(){return `<section class="kgpa-panel"><h3>새 ${h(title[state.type])} 등록</h3><p>고유 ID와 각 관계 ID는 등록 후 일반 수정이 불가능합니다. 실제 대학 데이터를 확인한 후 입력하세요.</p><form id="kgpa-entity-create-form"><div class="kgpa-form-grid">${fields[state.type].map(f=>fieldInput(f)).join('')}</div><div class="kgpa-actions"><button class="button button-primary" type="submit">내부 초안 등록</button>${button('취소','entity-cancel')}</div></form></section>`;}

  async function sourcePage(){
    frame('공식 출처 관리',note('공식 출처 목록을 불러오는 중입니다…'));
    const plural=state.sourceKind;
    const result=await core(`admin/${plural}?page=${state.sourcePage}&per_page=20`);
    const rows=result.items||[];
    let content=`<div class="kgpa-actions">${button('출처 자료','source-kind',attr('kind','sources'),plural==='sources'?'primary':'secondary')}${button('출처기관','source-kind',attr('kind','issuers'),plural==='issuers'?'primary':'secondary')}</div>`;
    if(plural==='issuers'){
      if(cap('issuers')) content+=`<section class="kgpa-panel"><h3>공식 출처기관 등록</h3><form id="kgpa-issuer-create"><div class="kgpa-form-grid"><label>고유 ID *<input name="public_id" required></label><label>기관 공식명 *<input name="official_name" required></label><label>기관 유형 *<select name="issuer_type" required><option value="university">대학교</option><option value="government">정부</option><option value="public_agency">공공기관</option><option value="accredited_evaluator">공인 평가기관</option><option value="official_application_provider">공식 원서 접수 기관</option></select></label><label>공식 도메인<input name="official_domain" placeholder="example.ac.kr"></label></div><button class="button button-primary" type="submit">기관 초안 등록</button></form></section>`;
      content+=`<section class="kgpa-panel"><h3>기관 목록</h3><div class="kgpa-table-wrap"><table class="widefat striped kgpa-table"><thead><tr><th>기관 ID</th><th>공식 명칭</th><th>상태</th><th>작업</th></tr></thead><tbody>`;
      content+=rows.length?rows.map(row=>`<tr><td>${h(row.id)}</td><td>${h(row.official_name)}<br><small>${h(row.official_domain||'')}</small></td><td>${status(row.approval_status)}</td><td>${cap('issuers')&&row.approval_status!=='approved'?button('승인','issuer-approve',attr('id',row.id)):'—'}</td></tr>`).join(''):'<tr><td colspan="4">등록된 기관이 없습니다.</td></tr>';
      content+='</tbody></table></div>'+pager(result.total,result.page,result.per_page,'source-page')+'</section>';
    } else {
      if(cap('edit')) content+=`<section class="kgpa-panel"><h3>공식 문서 등록</h3><p>공개 접근 가능한 원문 URL을 입력하세요. PDF는 페이지 또는 표 위치를 기록해야 합니다.</p>
      <form id="kgpa-source-create"><div class="kgpa-form-grid"><label>자료 고유 ID *<input name="public_id" required></label><label>발행기관 ID *<input name="issuer_id" type="number" min="1" required></label><label>원문 제목 *<input name="document_title" required></label><label>공식 원문 URL *<input name="public_url" type="url" required></label><label>원문 위치<input name="document_locator" placeholder="예: p.4, Admissions Table"></label><label>적용 대상<input name="applicable_to" placeholder="예: 2027학년도 외국인 신입학"></label><label>적용 시작일<input name="valid_from" type="date"></label><label>적용 종료일<input name="valid_to" type="date"></label></div><button class="button button-primary" type="submit">자료 등록(미검증)</button></form></section>`;
      content+=`<section class="kgpa-panel"><h3>공식 자료 목록</h3><div class="kgpa-table-wrap"><table class="widefat striped kgpa-table"><thead><tr><th>ID</th><th>문서명·URL</th><th>검증 상태</th><th>작업</th></tr></thead><tbody>`;
      content+=rows.length?rows.map(row=>`<tr><td>${h(row.id)}</td><td>${linkToSource(row)}<br><small>${h(row.document_locator||'원문 위치 미지정')}</small></td><td>${status(row.evidence_status)}<br><small>${h(row.checked_at||'미확인')}</small></td><td class="kgpa-actions">${button('상세','source-detail',attr('id',row.id))}${cap('source_review')?button('검증','source-verify',attr('id',row.id)) + button('무효화','source-invalidate',attr('id',row.id),'danger'):''}</td></tr>`).join(''):'<tr><td colspan="4">등록된 자료가 없습니다.</td></tr>';
      content+='</tbody></table></div>'+pager(result.total,result.page,result.per_page,'source-page')+'</section>';
    }
    frame('공식 출처 관리',content+'<section id="kgpa-source-detail" aria-live="polite"></section>');
  }
  function status(v){const val=String(v||'none');const cls=['approved','verified','published'].includes(val)?'approved':['pending','draft','unchecked'].includes(val)?'pending':['invalid','rejected'].includes(val)?'invalid':'muted';return `<span class="kgpa-state kgpa-${cls}">${h(val)}</span>`;}
  async function showSource(id){const r=await op('sources/'+id);const container=document.getElementById('kgpa-source-detail');if(!container)return;
    container.innerHTML=`<div class="kgpa-panel"><h3>공식 출처 #${h(id)}</h3><p>${linkToSource(r)}</p><dl class="kgpa-definition">${[['발행기관',r.issuer_name],['기관 승인',r.issuer_status],['자료 상태',r.evidence_status],['원문 위치',r.document_locator],['적용 대상',r.applicable_to],['적용 시작',r.valid_from],['적용 종료',r.valid_to],['확인일',r.checked_at],['유효 여부',r.currently_valid?'현재 검증됨':'검증되지 않음']].map(([a,b])=>`<div><dt>${h(a)}</dt><dd>${h(b||'—')}</dd></div>`).join('')}</dl></div>`;
    container.scrollIntoView({block:'nearest'});
  }

  function sectionOptions(){if(!state.fieldCatalog)return'';return Object.entries(state.fieldCatalog.sections).map(([code,section])=>`<option value="${h(code)}">${h(code)} · ${h(section.title)}</option>`).join('')}
  function jsonExample(type){const base=type.replace(/^repeat:/,'');
    if(base==='money_item')return {currency:'KRW',unit:'semester',amount:'',label:'공식 항목명'};
    if(base==='date_with_precision')return {date:'2027-03-01',precision:'day'};
    if(base==='structured_schedule')return {start:{date:'2027-03-01',precision:'day'},end:{date:'2027-03-15',precision:'day'}};
    if(base==='duration')return {value:8,unit:'week'};
    if(base==='published_statistic')return {count:null,reference_date:{date:'2027-04-01',precision:'day'}};
    const required={document:{name:'공식 서류명'},document_authentication:{method:'인증 방식'},language_requirement:{test:'TOPIK',score:'4'},waiver:{condition:'공식 면제 조건'},official_major:{name:'공식 전공명'},scholarship:{name:'장학금명',benefit:'지원 내용'},official_evaluation:{organization:'기관명',year:2027},certification:{name:'인증명',course_type:'degree',status:'certified'},restriction:{name:'제한명',course_type:'degree'},official_address:{address:'공식 주소'},official_guidance:{title:'공식 가이드명',url:'https://example.org'},official_contact:{department:'국제교류 담당 부서'},official_program:{name:'프로그램명'},official_case:{title:'공식 사례 제목'}};
    return required[base]||{};
  }
  function fieldType(){const section=document.getElementById('kgpa-section')?.value;const key=document.getElementById('kgpa-field-key')?.value;return state.fieldCatalog?.sections?.[section]?.fields?.[key]||'localized_text';}
  function typeInput(type,previous=undefined){
    const repeater=type.startsWith('repeat:');const t=type.replace(/^repeat:/,'');let value=previous;
    if(value===undefined)value=['localized_text','url','locale_code'].includes(t)?'':t==='year'?2027:t==='nullable_boolean'?null:t.startsWith('enum:')?t.slice(5).split('|')[0]:jsonExample(type);
    if(t==='nullable_boolean')return `<label>공식 자료 값<select id="kgpa-field-value"><option value="null" ${value===null?'selected':''}>미확인(null)</option><option value="true" ${value===true?'selected':''}>해당(true)</option><option value="false" ${value===false?'selected':''}>비해당(false)</option></select></label>`;
    if(t.startsWith('enum:'))return `<label>필드 값<select id="kgpa-field-value">${t.slice(5).split('|').map(x=>`<option value="${h(x)}" ${value===x?'selected':''}>${h(x)}</option>`).join('')}</select></label>`;
    if(t==='localized_text')return `<label>공식 자료에 근거한 설명<textarea id="kgpa-field-value" rows="6" maxlength="15000">${h(value)}</textarea></label>`;
    if(t==='url'||t==='locale_code'||t==='year')return `<label>공식 값<input id="kgpa-field-value" type="${t==='year'?'number':t==='url'?'url':'text'}" value="${h(value)}" ${t==='year'?'min="1900" max="2200"':''}></label>`;
    return `<label>구조화 값 (JSON, 반복 항목은 한 개씩 저장)<textarea id="kgpa-field-value" data-json="1" rows="9" spellcheck="false">${h(pretty(value))}</textarea></label><p class="description">자료에 없는 값은 예제 그대로 저장하지 마세요. 위 구조를 공식 원문과 일치하도록 수정하세요.</p>`;
  }
  function sourceRow(index,row={}){
    return `<div class="kgpa-source-row" data-index="${h(index)}"><label>출처 ID<input type="number" min="1" class="kgpa-source-id" value="${h(row.source_id||'')}" aria-label="근거 ID"></label><label>원문 위치<input class="kgpa-source-locator" maxlength="1000" value="${h(row.original_locator||'')}" placeholder="예: p. 7"></label><label>적용 대상<input class="kgpa-source-applies" value="${h(row.applies_to||'')}" maxlength="2000"></label><label>적용 시작일<input type="date" class="kgpa-source-from" value="${h(row.valid_from||'')}"></label><label>적용 종료일<input type="date" class="kgpa-source-to" value="${h(row.valid_to||'')}"></label>${button('출처 제거','source-row-remove',attr('index',index),'danger')}</div>`;
  }
  function fillFieldSelector(){
    const section=document.getElementById('kgpa-section')?.value;const key=document.getElementById('kgpa-field-key');
    const all=state.fieldCatalog?.sections?.[section]?.fields||{};
    if(!key)return;const old=key.value;
    key.innerHTML=Object.entries(all).map(([name,type])=>`<option value="${h(name)}">${h(name)} · ${h(type)}</option>`).join('');
    if(old&&all[old])key.value=old;
  }
  function changeTypePreview(previous=undefined){
    const type=fieldType(),repeat=type.startsWith('repeat:');
    const value=document.getElementById('kgpa-value-container');const item=document.getElementById('kgpa-item-key-wrap');
    if(value)value.innerHTML=typeInput(type,previous);
    if(item)item.hidden=!repeat;
    const ds=document.getElementById('kgpa-field-datum-status');
    if(ds&&ds.value!=='available'&&value){value.querySelectorAll('textarea,input,select').forEach(i=>{i.disabled=true;});}
  }
  function getFieldValue(){const input=document.getElementById('kgpa-field-value');const type=fieldType().replace(/^repeat:/,'');
    const status=document.getElementById('kgpa-field-datum-status')?.value||'available';if(status!=='available')return null;
    if(!input)throw new Error('필드 입력을 찾을 수 없습니다.');
    if(input.dataset.json==='1'){
      try{return JSON.parse(input.value)}catch{throw new Error('JSON 형식에 오류가 있습니다. 쉼표, 괄호, 따옴표를 확인하세요.');}
    }
    if(type==='nullable_boolean')return input.value==='null'?null:input.value==='true';
    if(type==='year'){
      if(!/^\d{4}$/.test(input.value))throw new Error('연도는 4자리 정수여야 합니다.');
      return Number(input.value);
    }
    return input.value.trim();
  }
  function currentSourceLinks(){return [...document.querySelectorAll('.kgpa-source-row')].map(n=>({source_id:Number(n.querySelector('.kgpa-source-id')?.value||0),original_locator:n.querySelector('.kgpa-source-locator')?.value.trim()||undefined,applies_to:n.querySelector('.kgpa-source-applies')?.value.trim()||undefined,valid_from:n.querySelector('.kgpa-source-from')?.value||undefined,valid_to:n.querySelector('.kgpa-source-to')?.value||undefined}));}
  function fieldEditor(entry=null,version=null,sourceLinks=[]){
    const readonly=!!entry;
    const section=entry?.section_code||'DET-01';const field=entry?.field_key||'';
    const type=state.fieldCatalog?.sections?.[section]?.fields?.[field]||'localized_text';
    const data=version?.value_json?parseJSON(version.value_json):undefined;
    state.draft={entry,version};
    const container=document.getElementById('kgpa-field-editor');if(!container)return;
    let html=`<section class="kgpa-panel"><h3>${entry?'항목 수정 초안 작성':'새 상세 항목 작성'}</h3><form id="kgpa-field-form">
        <div class="kgpa-form-grid"><label>DET 영역<select id="kgpa-section" ${readonly?'disabled':''}>${sectionOptions()}</select></label>
        <label>필드<select id="kgpa-field-key" ${readonly?'disabled':''}></select></label>
        <label id="kgpa-item-key-wrap">반복 항목 키(item_key)<input id="kgpa-item-key" maxlength="64" placeholder="예: tuition-2027" ${readonly?'readonly':''} value="${h(entry?.item_key||'')}"></label>
        <label>자료 상태<select id="kgpa-field-datum-status"><option value="available">공식 값 확인됨</option><option value="unpublished">자료 미공개</option><option value="under_review">내부 확인 중</option></select></label></div>
        <div id="kgpa-value-container"></div><h4>공식 출처 연결</h4><p>서로 다른 적용 기간·조건에 해당하는 반복값은 각기 다른 항목 키와 출처를 사용하세요.</p>
        <div id="kgpa-source-rows">${(sourceLinks.length?sourceLinks:[{}]).map((s,i)=>sourceRow(i,s)).join('')}</div>
        ${button('출처 추가','source-row-add')}<div class="kgpa-actions"><button type="submit" class="button button-primary">새 초안 저장</button>${button('입력 취소','field-cancel')}</div></form></section>`;
    container.innerHTML=html;
    document.getElementById('kgpa-section').value=section;fillFieldSelector();
    if(field)document.getElementById('kgpa-field-key').value=field;
    if(version?.datum_status)document.getElementById('kgpa-field-datum-status').value=version.datum_status;
    changeTypePreview(data);
    if(readonly)container.scrollIntoView({block:'nearest'});
  }
  async function loadTarget(type,id){
    if(!types.includes(type)||!Number.isSafeInteger(id)||id<1)throw new Error('유효한 대상 종류와 내부 ID를 선택하세요.');
    state.fieldTarget={type,id};state.type=type;state.draft=null;
    const data=await core(`admin/fields?entity_type=${encodeURIComponent(type)}&entity_id=${id}`);
    state.fieldList=data.items||[];
    await renderFieldTarget();
  }
  async function fieldPage(){
    frame('세부 정보 초안',note('14개 영역의 필드 카탈로그를 확인하는 중입니다…'));
    if(!state.fieldCatalog)state.fieldCatalog=await op('catalog');
    if(state.fieldTarget) return loadTarget(state.fieldTarget.type,state.fieldTarget.id);
    const selects=`<section class="kgpa-panel"><h3>입력 대상 선택</h3><form id="kgpa-field-target" class="kgpa-inline-form"><label>대상 종류<select name="type">${types.map(t=>`<option value="${t}" ${t===state.type?'selected':''}>${h(title[t])}</option>`).join('')}</select></label><label>KGP 내부 ID<input name="id" type="number" min="1" required placeholder="예: 17"></label><button class="button button-primary" type="submit">불러오기</button></form><p class="description">내부 ID는 대학·모집 목록에서 확인할 수 있습니다. WordPress 포스트 ID와 다릅니다.</p></section>`;
    frame('세부 정보 초안',selects);
  }
  async function renderFieldTarget(){
    const {type,id}=state.fieldTarget;
    let list=`<section class="kgpa-panel"><div class="kgpa-toolbar"><h3>${h(title[type])} #${h(id)} · 상세 필드</h3>${cap('edit')?button('새 항목 입력','field-new','', 'primary'):''}</div><div class="kgpa-table-wrap"><table class="widefat striped kgpa-table"><thead><tr><th>영역 / 필드</th><th>공개 상태</th><th>초안 상태</th><th>작업</th></tr></thead><tbody>`;
    list+=state.fieldList.length?state.fieldList.map(row=>{
      const draft=row.versions?.draft_version_id; const published=row.versions?.published_version_id;
      return `<tr><td><strong>${h(row.section_code)}</strong> / ${h(row.field_key)}<br><small>${h(row.item_key||'(단일 값)')} · 필드 #${h(row.id)}</small></td><td>${published?status(published.review_status):status('unpublished')}</td><td>${draft?status(draft.review_status):'—'}<br><small>잠금: ${h(row.lock_version)}</small></td><td class="kgpa-actions">${button('버전 보기','field-history',attr('id',row.id))}${cap('edit')?button('새 수정 초안','field-edit',attr('id',row.id)):''}${cap('edit')&&draft?.review_status==='draft'?button('검토 요청','field-submit',`${attr('id',row.id)} ${attr('lock',row.lock_version)}`,'primary'):''}${cap('review')&&published?button('비공개','field-unpublish',attr('id',row.id),'danger'):''}</td></tr>`;
    }).join(''):'<tr><td colspan="4">아직 이 대상에 입력된 필드가 없습니다.</td></tr>';
    list+='</tbody></table></div></section><div id="kgpa-field-editor"></div><section id="kgpa-history-block"></section>';
    frame('세부 정보 초안',`<section class="kgpa-panel"><form id="kgpa-field-target" class="kgpa-inline-form"><label>대상 종류<select name="type">${types.map(t=>`<option value="${t}" ${t===type?'selected':''}>${h(title[t])}</option>`).join('')}</select></label><label>KGP 내부 ID<input name="id" type="number" min="1" value="${h(id)}" required></label><button type="submit" class="button">대상 변경</button></form></section>${list}`);
  }
  async function showHistory(entryId){
    const history=await op(`versions/${entryId}`);
    const el=document.getElementById('kgpa-history-block');if(!el)return;
    el.innerHTML=`<section class="kgpa-panel"><h3>필드 #${h(entryId)} 변경 버전</h3>${history.versions?.map(v=>`<details><summary>v${h(v.version_number)} · ${h(v.review_status)} · ${h(v.created_at)}</summary><p>자료 상태: ${h(v.datum_status)} · 작성 사용자 ID: ${h(v.created_by)}</p>${objectView(parseJSON(v.value_json))}${sourceEvidence(v.source_links||[])}</details>`).join('')||note('버전이 없습니다.')}</section>`;
    el.scrollIntoView({block:'nearest'});
  }
  function sourceEvidence(links){return links.length?`<ul class="kgpa-evidence">${links.map(link=>`<li><strong>출처 #${h(link.source_id)}</strong> · ${link.source?.currently_valid?status('verified'):status('unverified')} · ${linkToSource(link.source)}<br><small>원문 위치: ${h(link.original_locator||link.source?.document_locator||'없음')} · 적용 대상: ${h(link.applies_to||'미기재')}</small></li>`).join('')}</ul>`:note('연결된 공식 출처가 없습니다.','warning');}

  async function reviewPage(){
    if(!cap('review'))return frame('검토·게시',note('검토 권한이 없습니다.','error'));
    frame('검토·게시',note('검토 대기열을 불러오는 중입니다…'));
    const result=await op(`queue?kind=${encodeURIComponent(state.reviewKind)}&page=${state.reviewPage}&per_page=12`);
    let html=`<div class="kgpa-actions">${button('상세 항목 검토','review-kind',attr('kind','field'),state.reviewKind==='field'?'primary':'secondary')}${button('대학 기본정보 검토','review-kind',attr('kind','identity'),state.reviewKind==='identity'?'primary':'secondary')}</div>`;
    html+=`<section class="kgpa-panel"><h3>검토 요청 ${h(result.total)}건</h3><p>원본 링크를 직접 열어 발행기관·적용 연도·기준 시점·값을 대조한 다음 승인하세요. 출처가 확인되지 않으면 승인할 수 없습니다.</p></section>`;
    if(!(result.items||[]).length)html+=note('현재 검토 대기 항목이 없습니다.');
    else html+=(result.items||[]).map(item=>state.reviewKind==='identity'?identityReviewCard(item):fieldReviewCard(item)).join('');
    html+=pager(result.total,result.page,result.per_page,'review-page');
    frame('검토·게시',html);
  }
  function identityReviewCard(row){
    const src=row.source||{};
    const valid=row.source_valid;
    return `<article class="kgpa-review-card"><div class="kgpa-review-head"><h3>${h(title[row.entity_type]||row.entity_type)} · 엔터티 #${h(row.entity_id)}</h3>${valid?status('verified'):status('unverified')}</div>
      <p>검토 요청자 ID: ${h(row.draft_by)} · 제출: ${h(row.draft_at)} · 잠금: ${h(row.lock_version)}</p>
      <details><summary>제출된 기본정보 스냅샷</summary>${objectView(row.draft_value)}</details>
      <section class="kgpa-evidence-panel"><h4>공식 원문 #${h(row.draft_source_id||'없음')}</h4><p>${linkToSource(src)}</p>
      <p>발행기관: ${h(src.issuer_name||'알 수 없음')} · 기관 승인: ${h(src.issuer_status||'미승인')} · 문서 상태: ${h(src.evidence_status||'미검증')}</p>
      <p>원문 위치: ${h(row.draft_source_locator||src.document_locator||'미기재')} · 확인일: ${h(src.checked_at||'미확인')}</p></section>
      <div class="kgpa-action-panel"><label><input type="checkbox" id="kgpa-review-check-${h(row.id)}"> 원문과 제출된 기본정보, 발행기관, 적용 대상을 직접 대조했습니다.</label>
      <div class="kgpa-actions">${button('승인·게시','review-identity-approve',`${attr('entitytype',row.entity_type)} ${attr('entityid',row.entity_id)} ${attr('lock',row.lock_version)} ${attr('rowid',row.id)}`,'primary')}${button('반려','review-identity-reject',`${attr('entitytype',row.entity_type)} ${attr('entityid',row.entity_id)} ${attr('lock',row.lock_version)} ${attr('rowid',row.id)}`)}</div>
      <label>반려 사유<textarea id="kgpa-reason-${h(row.id)}" rows="2" placeholder="원문과 맞지 않는 내용을 구체적으로 기록"></textarea></label></div></article>`;
  }
  function fieldReviewCard(row){
    const links=row.source_links||[];
    const verified=links.length>0&&links.every(l=>l.source?.currently_valid);
    return `<article class="kgpa-review-card"><div class="kgpa-review-head"><h3>${h(row.section_code)} / ${h(row.field_key)} <small>${h(row.item_key||'')}</small></h3>${verified?status('verified'):status('unverified')}</div>
      <p>대상: ${h(title[row.entity_type]||row.entity_type)} #${h(row.entity_id)} · 필드 #${h(row.entry_id)} · v${h(row.version_number)} · 작성자 ID ${h(row.created_by)}</p>
      <details open><summary>공개 요청된 값</summary>${objectView(row.value)}</details>
      <section class="kgpa-evidence-panel"><h4>연결된 공식 원문</h4>${sourceEvidence(links)}</section>
      <div class="kgpa-action-panel"><label><input type="checkbox" id="kgpa-review-check-${h(row.entry_id)}"> 항목 값·단위·출처·적용 회차를 원문과 대조했습니다.</label>
      <div class="kgpa-actions">${button('승인·게시','review-field-publish',`${attr('id',row.entry_id)}`,'primary')}${button('반려','review-field-reject',`${attr('id',row.entry_id)}`)}${button('버전 보기','review-field-history',`${attr('id',row.entry_id)}`)}</div>
      <label>반려 사유<textarea id="kgpa-reason-${h(row.entry_id)}" rows="2" placeholder="수정이 필요한 이유"></textarea></label></div></article>`;
  }
  async function start(){
    if(['translations','imports','history'].includes(state.page))return;
    if(!conf.coreReady)return screenError();
    if(state.page==='overview')await overview();
    else if(state.page==='entities')await entities();
    else if(state.page==='sources')await sourcePage();
    else if(state.page==='fields')await fieldPage();
    else if(state.page==='review')await reviewPage();
    else await overview();
  }
  function integer(value,label){const s=String(value).trim();if(!/^\d+$/.test(s)||Number(s)<1||!Number.isSafeInteger(Number(s)))throw new Error(`${label}: 올바른 양의 정수가 필요합니다.`);return Number(s)}
  function text(form,key){return String(new FormData(form).get(key)||'').trim()}
  function reviewerConfirmed(id){if(!document.getElementById(`kgpa-review-check-${id}`)?.checked)throw new Error('공식 원문과 값을 직접 대조한 후 확인란을 선택해 주세요.');}
  async function reload(){await start();}

  root.addEventListener('change',event=>{
    const t=event.target;
    if(t.id==='kgpa-entity-type'){state.type=t.value;state.entity=null;state.q='';state.pageNumber=1;withBusy(entities);}
    if(t.id==='kgpa-section'){fillFieldSelector();changeTypePreview();}
    if(t.id==='kgpa-field-key')changeTypePreview();
    if(t.id==='kgpa-field-datum-status'){
      const disable=t.value!=='available';
      document.querySelectorAll('#kgpa-value-container textarea, #kgpa-value-container input, #kgpa-value-container select').forEach(input=>{input.disabled=disable;});
    }
  });

  root.addEventListener('click',event=>{
    const btn=event.target.closest('[data-action]');if(!btn||!root.contains(btn))return;
    const a=btn.dataset.action;
    withBusy(async()=>{
      if(a==='entity-create'){const el=document.getElementById('kgpa-entity-editor');if(el){el.innerHTML=entityCreateForm();el.scrollIntoView({block:'nearest'});}return;}
      if(a==='entity-cancel'){const el=document.getElementById('kgpa-entity-editor');if(el)el.innerHTML='';return;}
      if(a==='entity-open'){await openEntity(integer(btn.dataset.id,'대상 ID'));return;}
      if(a==='entity-page'){state.pageNumber=integer(btn.dataset.page,'페이지');state.entity=null;await entities();return;}
      if(a==='source-kind'){state.sourceKind=btn.dataset.kind;state.sourcePage=1;await sourcePage();return;}
      if(a==='source-page'){state.sourcePage=integer(btn.dataset.page,'페이지');await sourcePage();return;}
      if(a==='source-detail'){await showSource(integer(btn.dataset.id,'출처 ID'));return;}
      if(a==='issuer-approve'){
        if(!window.confirm('출처기관의 공식 지위와 도메인을 확인했나요? 확인하지 않았다면 취소하세요.'))return;
        await core(`admin/issuers/${integer(btn.dataset.id,'기관 ID')}/approve`,'POST',{});await sourcePage();success('출처기관 승인 결과를 저장했습니다.');return;
      }
      if(a==='source-verify'){
        const id=integer(btn.dataset.id,'출처 ID');const src=await op(`sources/${id}`);
        const block=document.getElementById('kgpa-source-detail');
        block.innerHTML=`<section class="kgpa-panel"><h3>출처 원문 직접 확인 #${h(id)}</h3><p>${linkToSource(src)}</p>${objectView({issuer_name:src.issuer_name,issuer_status:src.issuer_status,document_title:src.document_title,document_locator:src.document_locator,applicable_to:src.applicable_to,valid_from:src.valid_from,valid_to:src.valid_to})}<label><input type="checkbox" id="kgpa-source-reviewed"> 공개 접근·발행기관·원문 내용·적용 대상을 직접 확인했습니다.</label>${button('검증 승인 기록','source-verify-confirm',attr('id',id),'primary')}</section>`;
        block.scrollIntoView({block:'nearest'});return;
      }
      if(a==='source-verify-confirm'){
        if(!document.getElementById('kgpa-source-reviewed')?.checked)throw new Error('출처 원문을 확인하고 체크해 주세요.');
        await core(`admin/sources/${integer(btn.dataset.id,'출처 ID')}/verify`,'POST',{});await sourcePage();success('출처 검증이 기록되었습니다.');return;
      }
      if(a==='source-invalidate'){
        const id=integer(btn.dataset.id,'출처 ID');
        const reason=window.prompt('출처를 무효로 판단한 구체적 이유를 입력하세요. 영향받는 게시 값이 즉시 비공개됩니다.');
        if(reason===null)return;
        if(!reason.trim())throw new Error('무효화 사유는 필수입니다.');
        if(!window.confirm('공식 출처를 무효 처리하면 관련 게시 값이 숨겨집니다. 계속할까요?'))return;
        const done=await core(`admin/sources/${id}/invalidate`,'POST',{reason:reason.trim()});await sourcePage();success(`출처 무효화 완료. 연관된 공개 필드 ${done.unpublished_fields||0}개가 비공개 처리됐습니다.`);return;
      }
      if(a==='identity-approve'||a==='identity-reject'||a==='identity-unpublish'){
        const id=integer(btn.dataset.id,'엔터티 ID');const type=btn.dataset.type;
        if(a==='identity-approve'&&!document.getElementById('kgpa-identity-check')?.checked)throw new Error('원문 대조 확인이 필요합니다.');
        if(a==='identity-unpublish'){
          const reason=window.prompt('기본정보 비공개 사유를 기록하세요.');if(reason===null)return;if(!reason.trim())throw new Error('사유가 필요합니다.');
          if(!window.confirm('이 대학·과정의 공개 상태가 달라집니다. 계속할까요?'))return;
          await core(`admin/identities/${type}/${id}/unpublish`,'POST',{reason:reason.trim()});
        }else if(a==='identity-reject'){
          const reason=document.getElementById('kgpa-identity-reason')?.value.trim();if(!reason)throw new Error('반려 사유를 입력하세요.');
          await core(`admin/identities/${type}/${id}/reject`,'POST',{expected_lock:Number(btn.dataset.lock),reason});
        }else await core(`admin/identities/${type}/${id}/approve`,'POST',{expected_lock:Number(btn.dataset.lock)});
        await openEntity(id,false);success('기본정보 검토 결과를 저장했습니다.');return;
      }
      if(a==='field-new'){fieldEditor();return;}
      if(a==='field-cancel'){const el=document.getElementById('kgpa-field-editor');if(el)el.innerHTML='';return;}
      if(a==='source-row-add'){
        const parent=document.getElementById('kgpa-source-rows');if(!parent)return;
        const max=Math.max(-1,...[...parent.querySelectorAll('.kgpa-source-row')].map(n=>Number(n.dataset.index)));
        if(parent.children.length>=50)throw new Error('출처 연결은 최대 50개입니다.');
        parent.insertAdjacentHTML('beforeend',sourceRow(max+1));return;
      }
      if(a==='source-row-remove'){btn.closest('.kgpa-source-row')?.remove();return;}
      if(a==='field-history'){await showHistory(integer(btn.dataset.id,'필드 ID'));return;}
      if(a==='field-edit'){
        const id=integer(btn.dataset.id,'필드 ID');const entry=state.fieldList.find(f=>Number(f.id)===id);
        if(!entry)throw new Error('필드를 찾지 못했습니다. 새로고침해 주세요.');
        const history=await op(`versions/${id}`);const idActive=Number(entry.draft_version_id||entry.published_version_id||0);
        const version=history.versions?.find(v=>Number(v.id)===idActive)||history.versions?.[0];
        const links=version?.source_links||[];
        fieldEditor(entry,version,links);return;
      }
      if(a==='field-unpublish'){
        const id=integer(btn.dataset.id,'필드 ID');
        const reason=window.prompt('게시된 공식 필드를 비공개 처리할 사유를 입력하세요.');if(reason===null)return;if(!reason.trim())throw new Error('사유는 필수입니다.');
        if(!window.confirm('관련 게시 필드를 비공개 처리할까요?'))return;
        await core(`admin/fields/${id}/unpublish`,'POST',{reason:reason.trim()});await loadTarget(state.fieldTarget.type,state.fieldTarget.id);success('게시 필드를 비공개 처리했습니다.');return;
      }
      if(a==='field-submit'){
        await core(`admin/fields/${integer(btn.dataset.id,'필드 ID')}/submit`,'POST',{expected_lock:integer(btn.dataset.lock,'잠금 버전')});
        await loadTarget(state.fieldTarget.type,state.fieldTarget.id);success('검토 요청이 제출되었습니다.');return;
      }
      if(a==='review-kind'){state.reviewKind=btn.dataset.kind;state.reviewPage=1;await reviewPage();return;}
      if(a==='review-page'){state.reviewPage=integer(btn.dataset.page,'페이지');await reviewPage();return;}
      if(a==='review-field-publish'||a==='review-field-reject'){
        const id=integer(btn.dataset.id,'필드 ID');
        if(a==='review-field-publish'){
          reviewerConfirmed(id);await core(`admin/fields/${id}/publish`,'POST',{});
        } else {
          const reason=document.getElementById(`kgpa-reason-${id}`)?.value.trim();
          if(!reason)throw new Error('반려 사유가 필요합니다.');
          await core(`admin/fields/${id}/reject`,'POST',{reason});
        }
        await reviewPage();success('항목 검토 결과가 기록되었습니다.');return;
      }
      if(a==='review-identity-approve'||a==='review-identity-reject'){
        const id=integer(btn.dataset.entityid,'엔터티 ID');const type=btn.dataset.entitytype;
        const lock=integer(btn.dataset.lock,'잠금 버전');const rowid=integer(btn.dataset.rowid,'검토 번호');
        if(a==='review-identity-approve'){
          reviewerConfirmed(rowid);await core(`admin/identities/${type}/${id}/approve`,'POST',{expected_lock:lock});
        } else {
          const reason=document.getElementById(`kgpa-reason-${rowid}`)?.value.trim();
          if(!reason)throw new Error('반려 사유를 입력하세요.');
          await core(`admin/identities/${type}/${id}/reject`,'POST',{expected_lock:lock,reason});
        }
        await reviewPage();success('기본정보 검토 결과가 기록되었습니다.');return;
      }
      if(a==='review-field-history'){
        const data=await op(`versions/${integer(btn.dataset.id,'필드 ID')}`);
        const card=btn.closest('.kgpa-review-card');if(card){const el=document.createElement('div');el.className='kgpa-history-inline';el.innerHTML=data.versions.map(v=>`<details><summary>v${h(v.version_number)} · ${h(v.review_status)}</summary>${objectView(parseJSON(v.value_json))}${sourceEvidence(v.source_links||[])}</details>`).join('');card.append(el);}return;
      }
    });
  });

  root.addEventListener('submit',event=>{
    const form=event.target;if(!(form instanceof HTMLFormElement))return;event.preventDefault();
    withBusy(async()=>{
      const id=form.id;
      if(id==='kgpa-assignment-form'){
        const data={user_id:integer(text(form,'user_id'),'사용자 ID'),university_id:integer(text(form,'university_id'),'대학 ID')};
        await core('admin/assignments','POST',data);success('정보 담당자가 배정되었습니다.');return;
      }
      if(id==='kgpa-entity-search'){state.q=text(form,'q');state.pageNumber=1;state.entity=null;await entities();return;}
      if(id==='kgpa-entity-create-form'){
        const result=await core(`admin/entities/${state.type}`,'POST',parseForm(form,state.type,'create'));
        state.entity={type:state.type,id:Number(result.id)};
        state.q='';state.pageNumber=1;await entities();success('기본 엔터티가 내부 초안으로 등록됐습니다. 검토 후에만 공개됩니다.');return;
      }
      if(id==='kgpa-entity-update-form'){
        const entityId=integer(form.dataset.id,'엔터티 ID');const revision=Number(new FormData(form).get('expected_revision'));
        const changes=parseForm(form,state.type,'edit');
        await core(`admin/entities/${state.type}/${entityId}`,'PATCH',{expected_revision:revision,changes});
        await openEntity(entityId,false);success('내부 데이터가 저장되었습니다. 공개본에는 별도 검토가 필요합니다.');return;
      }
      if(id==='kgpa-identity-submit'){
        const entityId=integer(form.dataset.id,'엔터티 ID');const fd=new FormData(form);
        const payload={source_id:integer(fd.get('source_id'),'출처 ID'),expected_lock:Number(fd.get('expected_lock')||0),source_locator:String(fd.get('source_locator')||'').trim()};
        if(form.dataset.type==='university')payload.aliases=String(fd.get('aliases')||'').split(',').map(s=>s.trim()).filter(Boolean);
        await core(`admin/identities/${form.dataset.type}/${entityId}/submit`,'POST',payload);
        await openEntity(entityId,false);success('기본정보 검토 요청이 제출되었습니다.');return;
      }
      if(id==='kgpa-issuer-create'){
        const payload={public_id:text(form,'public_id'),official_name:text(form,'official_name'),issuer_type:text(form,'issuer_type')};
        if(text(form,'official_domain'))payload.official_domain=text(form,'official_domain');
        await core('admin/issuers','POST',payload);await sourcePage();success('기관이 미승인 상태로 등록되었습니다.');return;
      }
      if(id==='kgpa-source-create'){
        const payload={public_id:text(form,'public_id'),issuer_id:integer(text(form,'issuer_id'),'기관 ID'),document_title:text(form,'document_title'),public_url:text(form,'public_url')};
        for(const key of ['document_locator','applicable_to','valid_from','valid_to'])if(text(form,key))payload[key]=text(form,key);
        await core('admin/sources','POST',payload);await sourcePage();success('공식 원문 자료가 미검증으로 등록되었습니다.');return;
      }
      if(id==='kgpa-field-target'){
        const fd=new FormData(form);await loadTarget(String(fd.get('type')),integer(fd.get('id'),'대상 ID'));return;
      }
      if(id==='kgpa-field-form'){
        if(!state.fieldTarget)throw new Error('등록 대상을 먼저 선택하세요.');
        const section=document.getElementById('kgpa-section')?.value;
        const field=document.getElementById('kgpa-field-key')?.value;
        const itemKey=document.getElementById('kgpa-item-key-wrap')?.hidden?'':String(document.getElementById('kgpa-item-key')?.value||'').trim();
        const datumStatus=document.getElementById('kgpa-field-datum-status')?.value||'available';
        const value=getFieldValue();
        const sourceLinks=datumStatus==='available'?currentSourceLinks():[];
        if(datumStatus==='available'&&sourceLinks.length===0)throw new Error('공개 가능 값에는 출처를 최소 하나 연결해야 합니다.');
        if(sourceLinks.some(l=>!Number.isSafeInteger(l.source_id)||l.source_id<1))throw new Error('출처 ID를 정확히 입력하세요.');
        const payload={entity_type:state.fieldTarget.type,entity_id:state.fieldTarget.id,section,field,item_key:itemKey,
          datum_status:datumStatus,value,source_links:sourceLinks,expected_lock:Number(state.draft?.entry?.lock_version||0)};
        const saved=await core('admin/fields','POST',payload);
        await loadTarget(state.fieldTarget.type,state.fieldTarget.id);
        success(`새 필드 초안이 저장되었습니다 (필드 #${saved.entry_id}, 버전 ${saved.version_number}). 필요하면 검토 요청을 눌러 주세요.`);return;
      }
    });
  });

  start().catch(error=>{frame('화면을 불러올 수 없습니다',note(error.message||String(error),'error'));});
})();
