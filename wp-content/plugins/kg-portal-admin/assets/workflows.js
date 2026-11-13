/* Addon A05/A06/A07. All writes use private WordPress REST with nonce. */
(() => {
  'use strict';
  const conf=window.KGP_ADMIN, root=document.getElementById('kgp-operator-root');
  if(!conf || !root || !['translations','imports','history'].includes(conf.page))return;
  const permissions=conf.permissions||{};
  const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const json=x=>JSON.stringify(x??null,null,2);
  const val=id=>document.getElementById(id)?.value?.trim()||'';
  const safeLink=u=>{try{const url=new URL(String(u));return ['https:','http:'].includes(url.protocol)?url.href:'';}catch{return '';}};
  const num=(x,name)=>{const v=Number(x);if(!Number.isSafeInteger(v)||v<1)throw Error(`${name}: 양의 정수 ID를 입력해 주세요.`);return v;};
  const revision=x=>{const n=Number(x);if(!Number.isSafeInteger(n)||n<0)throw Error('변경 버전이 올바르지 않습니다.');return n;};
  const state={busy:false,translation:null,entry:null,csv:null,history:null,identity:null};
  const nav=[['kgp-operator','대시보드'],['kgp-operator-entities','대학·모집 목록'],['kgp-operator-sources','공식 출처'],['kgp-operator-fields','항목별 데이터'],['kgp-operator-review','검토·게시'],['kgp-operator-translations','번역 관리'],['kgp-operator-imports','CSV 가져오기'],['kgp-operator-history','변경 이력·복구'],['kgp-operator-inquiries','문의 관리'],['kgp-operator-settings','운영 설정']];
  function navBar(){return `<nav class="kgpa-nav" aria-label="운영 메뉴">${nav.filter(([name])=>{
    if(name.endsWith('-inquiries'))return permissions.inquiries;if(name.endsWith('-settings'))return permissions.assign;if(name.endsWith('-review'))return permissions.review;
    if(name.endsWith('-sources'))return permissions.edit||permissions.source_review||permissions.issuers;
    if(name.endsWith('-imports'))return permissions.edit;
    if(name.endsWith('-fields')||name.endsWith('-translations')||name.endsWith('-history'))return permissions.edit||permissions.review;
    if(name.endsWith('-entities'))return permissions.edit||permissions.review||permissions.assign;
    return true;
  }).map(([name,label])=>`<a href="${esc(conf.adminBase+name)}" ${name==='kgp-operator-'+conf.page?'aria-current="page"':''}>${esc(label)}</a>`).join('')}</nav>`;}
  const button=(action,label,extra='',primary=false)=>`<button type="button" class="button ${primary?'button-primary':''}" data-wf="${esc(action)}" ${extra}>${esc(label)}</button>`;
  function frame(title,body){root.innerHTML=navBar()+`<div class="kgpa-notice" id="kgpaw-feedback" role="status" aria-live="polite" hidden></div><div class="kgpa-heading"><h2>${esc(title)}</h2><p>번역·가져오기·복구는 출처 및 검토 규칙을 유지합니다.</p></div><div class="kgpa-content">${body}</div>`;}
  function message(m,bad=false){const el=document.getElementById('kgpaw-feedback');if(!el)return;el.textContent=String(m);el.hidden=false;el.className='kgpa-notice '+(bad?'kgpa-notice-error':'kgpa-notice-success');el.scrollIntoView({block:'nearest'});}
  const pre=x=>`<pre class="kgpa-json">${esc(json(x))}</pre>`;
  async function req(path,method='GET',body=null,form=false){
    const opts={method,credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'X-WP-Nonce':conf.nonce,'Accept':'application/json'}};
    if(body!==null){if(form)opts.body=body;else {opts.headers['Content-Type']='application/json';opts.body=JSON.stringify(body);}}
    const response=await fetch(conf.operatorRoot+path,{...opts});let data;
    try{data=await response.json();}catch{throw Error('서버에서 JSON 결과를 받지 못했습니다. HTTP '+response.status);}
    if(!response.ok)throw Error((data?.message||data?.code||'요청 실패')+' (HTTP '+response.status+')');
    return data;
  }
  async function core(path){const response=await fetch(conf.apiRoot+path,{credentials:'same-origin',headers:{'X-WP-Nonce':conf.nonce}});const data=await response.json();if(!response.ok)throw Error(data.message||'Core API 오류');return data;}
  async function busy(fn){if(state.busy)return;state.busy=true;root.setAttribute('aria-busy','true');try{await fn();}catch(e){message(e.message||e,true);}finally{state.busy=false;root.removeAttribute('aria-busy');}}
  function linkToField(n){return `<a href="${esc(conf.adminBase+'kgp-operator-fields')}&type=${esc(n.entry?.entity_type||'university')}&id=${esc(n.entry?.entity_id||0)}">원본 필드 관리</a>`;}
  function translationEditor(d){
    const v=d.source_value, draft=d.draft, text=typeof v==='string', revision=Number(draft?.revision||0);
    state.translation=d;
    const current=draft?JSON.parse(draft.translated_json):v;
    let html=`<section class="kgpa-panel"><h3>원문 버전 #${esc(d.source_version_id)} · 영어 번역</h3>
      <p>원문과 번역이 연결된 버전은 고정됩니다. 금액·날짜·통화·출처 URL은 수정할 수 없습니다.</p>
      <div class="kgpaw-columns"><div><strong>원문(승인 정보)</strong>${pre(v)}</div><div><strong>영문 번역 초안</strong><label for="kgpaw-translation">${text?'영문 내용':'영문 JSON (원문의 키·숫자·URL 유지)'}</label>
      <textarea id="kgpaw-translation" rows="13" spellcheck="false" ${text?'':'data-json="1"'}>${esc(text?current:json(current))}</textarea></div></div>
      <p>상태: <strong>${esc(draft?.status||'미작성')}</strong> · 수정 버전: ${revision}</p><div class="kgpa-actions">
      ${permissions.edit?button('translation-save','번역 초안 저장','',true):''}
      ${permissions.edit && draft && ['draft','rejected'].includes(draft.status)?button('translation-submit','검토 요청'):''}
      </div></section>`;
    return html;
  }
  async function loadVersion(id){const d=await req('translations/version/'+id);const el=document.getElementById('kgpaw-translation-editor');if(el)el.innerHTML=translationEditor(d);}
  async function translationPage(){
    const query=new URLSearchParams(location.search);const initial=query.get('entry_id')||'';
    frame('A05 · 번역 관리',`<section class="kgpa-panel"><h3>필드 원문 버전 선택</h3><form id="kgpaw-entry-form" class="kgpa-inline-form">
      <label>필드 항목 ID<input name="entry_id" type="number" min="1" required value="${esc(initial)}"></label><button type="submit" class="button button-primary">원문 불러오기</button></form></section>
      <div id="kgpaw-version-list"></div><div id="kgpaw-translation-editor"></div>
      ${permissions.review?'<section class="kgpa-panel"><h3>번역 검토 대기열</h3><div id="kgpaw-translation-queue">불러오는 중…</div></section>':''}`);
    if(initial && /^\d+$/.test(initial)) await loadEntry(Number(initial));
    if(permissions.review)await loadTranslationQueue();
  }
  async function loadEntry(id){const d=await req('translations/entry/'+id);state.entry=d;
    document.getElementById('kgpaw-version-list').innerHTML=`<section class="kgpa-panel"><h3>필드 #${esc(id)} · 원문 버전</h3>
      <p>${esc(d.entry.entity_type)} #${esc(d.entry.entity_id)} · ${esc(d.entry.section_code)}/${esc(d.entry.field_key)}</p>
      <div class="kgpa-actions">${(d.versions||[]).map(v=>button('translation-open',`v${v.version_number} (${v.review_status})`, `data-id="${esc(v.id)}"`)).join('')}</div></section>`;
    document.getElementById('kgpaw-translation-editor').innerHTML='';
  }
  async function loadTranslationQueue(){const r=await req('translations/queue');
    const el=document.getElementById('kgpaw-translation-queue');if(!el)return;
    el.innerHTML=(r.items||[]).length?(r.items||[]).map(x=>`<article class="kgpaw-review kgpa-review-card">
      <h4>번역 #${esc(x.id)} · 원문 버전 #${esc(x.field_version_id)} · ${esc(x.entry.entity_type)} #${esc(x.entry.entity_id)}</h4>
      <p>${esc(x.entry.section_code)}/${esc(x.entry.field_key)} · 작성자 #${esc(x.created_by)} · 제출자 #${esc(x.submitted_by)}</p>
      <p><strong>공식 근거:</strong> ${(x.sources||[]).map(s=>{const url=safeLink(s.public_url);return url?`<a href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(s.document_title)}</a> · 원문 위치 ${esc(s.original_locator||'확인 필요')} · 확인일 ${esc(s.checked_at||'미확인')}`:esc(s.document_title);}).join('; ')||'연결된 공식 문서 없음'}</p>
      <div class="kgpaw-columns"><div><strong>검토 대상 원문</strong>${pre(x.source_value)}</div><div><strong>영문 초안</strong>${pre(JSON.parse(x.translated_json))}</div></div>
      <label class="kgpaw-check"><input type="checkbox" id="kgpaw-confirm-${x.id}"> 원문과 번역의 의미·조건·적용 대상이 일치함을 직접 대조했습니다.</label>
      <div class="kgpa-actions">${button('translation-approve','번역 승인',`data-id="${x.id}" data-revision="${x.revision}"`,true)}${button('translation-reject','반려',`data-id="${x.id}" data-revision="${x.revision}"`)}</div>
      </article>`).join(''):'<p>검토 대기 중인 영어 번역이 없습니다.</p>';
  }
  function csvTemplate(){const header=['entity_type','public_id','university_ref','campus_ref','program_ref','intake_ref','issuer_ref','official_name_ko','official_name_en','official_website','institution_type','region_code','is_primary','field_code','level_code','language_code','department_url','academic_year','academic_term','admission_type','start_date','end_date','application_url','variant_code','issuer_type','official_name','official_domain','document_title','public_url','document_locator','applicable_to','valid_from','valid_to','target_type','target_public_id','section_code','field_key','item_key','value_json','source_ref','source_locator'];
    const blob=new Blob(['\uFEFF'+header.join(',')+'\r\n'],{type:'text/csv;charset=utf-8'});const url=URL.createObjectURL(blob);
    const a=document.createElement('a');a.href=url;a.download='kgp-import-template.csv';document.body.append(a);a.click();a.remove();URL.revokeObjectURL(url);
  }
  function csvRows(data){state.csv=data;
    const rows=data.rows||[];
    return `<section class="kgpa-panel"><h3>배치 #${esc(data.batch.id)} · ${esc(data.batch.original_filename)}</h3><p>${rows.length}행 · 상태: ${esc(data.batch.status)}</p>
    <label class="kgpaw-check"><input id="kgpaw-select-all" type="checkbox"> 정상 행 전체 선택</label>
    <div class="kgpa-table-wrap"><table class="widefat striped kgpa-table"><thead><tr><th>반영</th><th>행</th><th>유형·식별자</th><th>예상 작업</th><th>검증 결과</th></tr></thead><tbody>
    ${rows.map(x=>`<tr><td>${x.status==='ready'?`<input type="checkbox" class="kgpaw-row" value="${x.id}" aria-label="CSV ${x.csv_line}행 선택">`:'—'}</td>
    <td>${esc(x.csv_line)}</td><td>${esc(x.entity_type)} / ${esc(x.public_id||x.normalized?.target_public_id||'—')}</td><td>${esc(x.action_code)}</td>
    <td>${x.errors?.length?`<strong class="kgpaw-error">${esc(x.errors.join('; '))}</strong>`:esc(x.status)}
    <details><summary>변경 전·후 검토</summary><div class="kgpaw-columns"><div><strong>현재 데이터</strong>${pre(x.before)}</div><div><strong>CSV 입력</strong>${pre(x.normalized)}</div></div></details></td></tr>`).join('')}
    </tbody></table></div><div class="kgpa-actions">${button('csv-apply','선택한 정상 행을 내부 초안으로 반영', '',true)}</div>
    <p class="description">미리보기는 대학 정보를 변경하지 않습니다. 반영해도 공개 승인이나 출처 검증이 자동으로 완료되지 않습니다.</p></section>`;
  }
  async function loadBatch(id){const d=await req('csv/'+id);const el=document.getElementById('kgpaw-preview');if(el)el.innerHTML=csvRows(d);}
  async function csvPage(){frame('A06 · CSV 가져오기',`<section class="kgpa-panel"><h3>UTF-8 CSV 미리보기</h3>
    <p>최대 2MiB · 500행. 대학·캠퍼스·전공·회차·묶음·출처기관·출처·필드를 동일 양식으로 처리합니다.</p>
    <div class="kgpa-actions">${button('csv-template','표준 CSV 빈 양식 받기')}</div>
    <form id="kgpaw-csv-form"><label>CSV 파일<input type="file" accept=".csv,text/csv" name="file" required></label>
    <button type="submit" class="button button-primary">검증·미리보기 생성</button></form></section>
    <section class="kgpa-panel"><h3>최근 배치</h3><div id="kgpaw-batches">불러오는 중…</div></section><div id="kgpaw-preview"></div>`);
    await loadBatches();
  }
  async function loadBatches(){const data=await req('csv/batches');
    const el=document.getElementById('kgpaw-batches');if(el)el.innerHTML=(data.items||[]).length?data.items.map(x=>
      `<div class="kgpaw-batch"><span>#${esc(x.id)} · ${esc(x.original_filename)} · ${esc(x.row_count)}행 · ${esc(x.status)}</span>
      ${button('csv-open','열기',`data-id="${esc(x.id)}"`)}</div>`).join(''):'<p>아직 업로드된 CSV 배치가 없습니다.</p>';
  }
  async function historyPage(){const params=new URLSearchParams(location.search);
    frame('A07 · 변경 이력·복구',`<section class="kgpa-panel"><h3>항목별 버전 비교·복원</h3>
    <form class="kgpa-inline-form" id="kgpaw-field-history-form"><label>필드 ID<input type="number" name="entry_id" min="1" required value="${esc(params.get('entry_id')||'')}"></label>
    <button class="button button-primary" type="submit">버전 조회</button></form><div id="kgpaw-field-history"></div></section>
    <section class="kgpa-panel"><h3>대학·전공·회차 변경 감사 기록</h3><form id="kgpaw-audit-form" class="kgpa-inline-form">
    <label>종류<select name="type">${['university','campus','program','intake','offering'].map(x=>`<option>${x}</option>`).join('')}</select></label>
    <label>내부 ID<input type="number" name="entity_id" min="1" required></label><button type="submit" class="button button-primary">감사 기록 조회</button></form>
    <div id="kgpaw-audit-history"></div></section>`);
    if(params.get('entry_id'))await loadFieldHistory(num(params.get('entry_id'),'필드 ID'));
  }
  async function loadFieldHistory(id){const d=await req('history/entry/'+id);state.history=d;
    const versions=d.versions||[];
    document.getElementById('kgpaw-field-history').innerHTML=`<h4>항목 #${esc(id)} · ${esc(d.entry.section_code)}/${esc(d.entry.field_key)}</h4>
    <p>현재 잠금 버전: ${esc(d.entry.lock_version)} · 게시 버전 #${esc(d.entry.published_version_id||'없음')}</p>
    <div class="kgpaw-compare">두 버전의 값과 출처를 비교: <select id="kgpaw-old">${versions.map(v=>`<option value="${v.id}">v${v.version_number}</option>`).join('')}</select>
    <select id="kgpaw-new">${versions.map((v,i)=>`<option value="${v.id}" ${i===1?'selected':''}>v${v.version_number}</option>`).join('')}</select>
    ${button('history-compare','버전 비교')}</div><div id="kgpaw-diff"></div>
    <div class="kgpa-table-wrap"><table class="widefat striped kgpa-table"><thead><tr><th>버전</th><th>상태</th><th>원문 값·출처</th><th>복구</th></tr></thead><tbody>
    ${versions.map(v=>`<tr><td>v${v.version_number} · #${v.id}</td><td>${esc(v.review_status)}</td><td><details><summary>값·출처 보기</summary>${pre(v.decoded_value)}${pre(v.source_links)}</details></td>
    <td>${permissions.edit?button('history-restore-field','새 복구 초안',`data-id="${v.id}"`):'조회 전용'}</td></tr>`).join('')}</tbody></table></div>`;
  }
  async function loadAudit(type,id){const d=await req('history/events?'+new URLSearchParams({type,entity_id:id}));state.identity={type,id,events:d.items};
    const el=document.getElementById('kgpaw-audit-history');
    if(el)el.innerHTML=`<h4>${esc(type)} #${esc(id)} · 총 ${esc(d.total)}건 (최근 30건)</h4>
      ${(d.items||[]).map(x=>`<details class="kgpaw-audit"><summary>#${esc(x.id)} · ${esc(x.recorded_at)} · ${esc(x.event_type)} · 담당자 #${esc(x.actor_id)}</summary>
        <div class="kgpaw-columns"><div><strong>변경 전</strong>${pre(x.before_json)}</div><div><strong>변경 후</strong>${pre(x.after_json)}</div></div>
        ${pre(x.details_json)}
        ${permissions.edit||permissions.assign?['identity.published','identity.unpublished'].includes(x.event_type)?button('history-restore-identity','과거 기본정보 복구 초안',`data-audit="${x.id}"`):'':''}
      </details>`).join('')}`;
  }
  root.addEventListener('submit',event=>{const form=event.target;
    if(!['kgpaw-entry-form','kgpaw-csv-form','kgpaw-field-history-form','kgpaw-audit-form'].includes(form.id))return;
    event.preventDefault();busy(async()=>{
      if(form.id==='kgpaw-entry-form'){await loadEntry(num(new FormData(form).get('entry_id'),'필드 ID'));return;}
      if(form.id==='kgpaw-csv-form'){const d=await req('csv/preview','POST',new FormData(form),true);document.getElementById('kgpaw-preview').innerHTML=csvRows(d);await loadBatches();message('미리보기 생성 완료. 정상 행을 선택한 다음에만 초안으로 반영할 수 있습니다.');return;}
      if(form.id==='kgpaw-field-history-form'){await loadFieldHistory(num(new FormData(form).get('entry_id'),'필드 ID'));return;}
      if(form.id==='kgpaw-audit-form'){await loadAudit(String(new FormData(form).get('type')),num(new FormData(form).get('entity_id'),'대상 ID'));return;}
    });
  });
  root.addEventListener('click',event=>{const b=event.target.closest('[data-wf]');if(!b)return;
    busy(async()=>{
      const action=b.dataset.wf;
      if(action==='translation-open'){await loadVersion(num(b.dataset.id,'원문 버전'));return;}
      if(action==='translation-save'){
        const d=state.translation;if(!d)throw Error('원문 버전을 먼저 선택하세요.');
        const input=document.getElementById('kgpaw-translation');if(!input)throw Error('번역 입력이 없습니다.');
        let value=input.value;if(input.dataset.json==='1'){try{value=JSON.parse(value);}catch{throw Error('영문 JSON 문법이 올바르지 않습니다.');}}
        const response=await req('translations/version/'+d.source_version_id+'/save','POST',{translation:value,expected_revision:revision(d.draft?.revision||0)});
        await loadVersion(d.source_version_id);message('번역 초안을 저장했습니다. 상태: '+response.status);return;
      }
      if(action==='translation-submit'){
        const d=state.translation;if(!d?.draft)throw Error('저장된 번역 초안이 없습니다.');
        await req('translations/'+d.draft.id+'/submit','POST',{expected_revision:revision(d.draft.revision)});
        await loadVersion(d.source_version_id);if(permissions.review)await loadTranslationQueue();message('검토 요청 완료.');return;
      }
      if(action==='translation-approve'||action==='translation-reject'){
        const id=num(b.dataset.id,'번역 ID');
        if(action==='translation-approve'&&!document.getElementById('kgpaw-confirm-'+id)?.checked)throw Error('원문과 번역을 실제 대조한 뒤 확인란을 체크해 주세요.');
        let payload={expected_revision:revision(b.dataset.revision)};
        if(action==='translation-reject'){const reason=prompt('반려 사유를 입력하세요.');if(!reason)return;payload.reason=reason;}
        if(!confirm(action==='translation-approve'?'번역을 승인하고 영어 공개 조회에 반영할까요?':'해당 번역을 반려할까요?'))return;
        await req('translations/'+id+'/'+(action==='translation-approve'?'approve':'reject'),'POST',payload);
        await loadTranslationQueue();message('번역 검토 상태를 반영했습니다.');return;
      }
      if(action==='csv-template'){csvTemplate();return;}
      if(action==='csv-open'){await loadBatch(num(b.dataset.id,'배치 ID'));return;}
      if(action==='csv-apply'){
        const d=state.csv;if(!d)throw Error('CSV 미리보기를 먼저 생성하거나 열어 주세요.');
        const rows=[...root.querySelectorAll('.kgpaw-row:checked')].map(x=>num(x.value,'CSV 행'));
        if(!rows.length)throw Error('반영할 정상 행을 선택해 주세요.');
        if(!confirm(`${rows.length}개의 정상 행을 내부 데이터/초안에 실제 반영합니다. 승인된 공개 값으로 자동 게시되지는 않습니다. 계속할까요?`))return;
        const outcome=await req('csv/'+d.batch.id+'/apply','POST',{row_ids:rows});await loadBatch(d.batch.id);await loadBatches();
        message(`적용 ${outcome.applied}건 · 유지 ${outcome.kept}건 · 실패 ${outcome.failed}건. 실패 행은 미리보기에서 확인하세요.`,outcome.failed>0);return;
      }
      if(action==='history-compare'){
        const d=state.history;if(!d)throw Error('필드 이력을 먼저 열어 주세요.');
        const out=await req('history/entry/'+d.entry.id+'/compare?'+new URLSearchParams({old:val('kgpaw-old'),new:val('kgpaw-new')}));
        document.getElementById('kgpaw-diff').innerHTML=`<div class="kgpaw-columns"><section><h4>이전 버전 #${esc(out.old.id)}</h4>${pre(out.old.value)}${pre(out.old.sources)}</section>
        <section><h4>비교 버전 #${esc(out.new.id)}</h4>${pre(out.new.value)}${pre(out.new.sources)}</section></div><p>값 변경: ${out.value_changed?'있음':'없음'} / 출처 변경: ${out.sources_changed?'있음':'없음'}</p>`;return;
      }
      if(action==='history-restore-field'){
        const d=state.history;if(!d)throw Error('이력을 먼저 선택해 주세요.');
        if(!confirm('과거 필드 값을 새 비공개 초안으로 복원할까요? 기존 공개본은 변경하지 않습니다.'))return;
        const result=await req('history/entry/'+d.entry.id+'/restore','POST',{source_version_id:num(b.dataset.id,'과거 버전'),expected_lock:revision(d.entry.lock_version)});
        await loadFieldHistory(d.entry.id);message(`복구 초안 생성: 버전 #${result.version_id}. 공식 원문 재확인 후 검토 요청하세요.`);return;
      }
      if(action==='history-restore-identity'){
        const d=state.identity;if(!d)throw Error('감사 기록을 먼저 불러와 주세요.');
        const audit=num(b.dataset.audit,'이력 ID');const review=await core(`admin/identities/${d.type}/${d.id}`);
        if(!review.review)throw Error('기본정보 검토 기록이 없습니다.');
        const locator=prompt('복구본의 공식 출처 위치(페이지·표 등)를 확인하고 입력하세요.','');if(locator===null)return;
        if(!confirm('과거 기본정보를 비공개 복구 초안으로 생성할까요? 현재 공개본은 유지됩니다.'))return;
        const result=await req(`history/identity/${d.type}/${d.id}/restore`,'POST',{audit_id:audit,expected_lock:revision(review.review.lock_version),source_locator:locator});
        const target=document.getElementById('kgpaw-audit-history');
        const panel=document.createElement('section');panel.className='kgpa-panel';panel.innerHTML=`<h3>복구 초안 생성 완료</h3><p>상태: ${esc(result.status)}, 잠금 버전: ${esc(result.lock_version)}</p>
          ${button('history-submit-identity','검토 요청',`data-lock="${result.lock_version}"`,'primary')}`;
        target?.prepend(panel);message('복구 초안을 작성했습니다. 검토 요청·승인을 거쳐야 공개됩니다.');return;
      }
      if(action==='history-submit-identity'){
        const d=state.identity;if(!d)throw Error('기본정보 대상을 먼저 선택해 주세요.');
        const result=await req(`history/identity/${d.type}/${d.id}/submit-restored`,'POST',{expected_lock:revision(b.dataset.lock)});
        message('검토 요청 완료. 검토자는 기존 A04에서 승인 또는 반려할 수 있습니다. 상태: '+result.status);return;
      }
    });
  });
  root.addEventListener('change',e=>{
    if(e.target.id==='kgpaw-select-all')root.querySelectorAll('.kgpaw-row').forEach(x=>x.checked=e.target.checked);
  });
  ({translations:translationPage,imports:csvPage,history:historyPage})[conf.page]().catch(e=>{
    frame('화면 연결 실패',`<div class="kgpa-note kgpa-error">${esc(e.message||e)}</div>`);
  });
})();
