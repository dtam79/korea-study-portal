/* A08 inquiry access and A09 privacy/retention settings. Core field workflow is unchanged. */
(() => {
  'use strict';
  const conf=window.KGP_ADMIN, root=document.getElementById('kgp-operator-root');
  if (!conf || !root || !['inquiries','settings'].includes(conf.page)) return;
  const cap=conf.permissions||{};
  const escapeHTML=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let busy=false,state={page:1,status:'',q:'',language:'',assignee:'',current:null,currentRecord:null};
  const known=['new','in_progress','completed'];
  const names={new:'신규',in_progress:'처리 중',completed:'완료'};
  const link=(page)=>conf.adminBase+page;
  function nav(){const items=[['kgp-operator','대시보드'],['kgp-operator-entities','대학·모집 목록'],['kgp-operator-sources','공식 출처'],['kgp-operator-fields','필드 초안'],['kgp-operator-review','검토·게시'],['kgp-operator-translations','번역'],['kgp-operator-imports','CSV'],['kgp-operator-history','이력·복구'],['kgp-operator-inquiries','문의 관리'],['kgp-operator-settings','운영 설정']];
    return '<nav class="kgpa-nav">'+items.filter(([page])=>{
      if(page==='kgp-operator-settings')return !!cap.assign;
      if(page==='kgp-operator-inquiries')return !!cap.inquiries;
      if(page==='kgp-operator-imports')return !!cap.edit;
      if(page==='kgp-operator-review')return !!cap.review;
      if(page==='kgp-operator-sources')return !!(cap.edit||cap.source_review||cap.issuers);
      if(['kgp-operator-fields','kgp-operator-translations','kgp-operator-history'].includes(page))return !!(cap.edit||cap.review);
      if(page==='kgp-operator-entities')return !!(cap.edit||cap.review||cap.assign);
      return true;
    }).map(([page,label])=>`<a href="${escapeHTML(link(page))}" ${page==='kgp-operator-'+conf.page?'aria-current="page"':''}>${escapeHTML(label)}</a>`).join('')+'</nav>';
  }
  function frame(title,contents){root.innerHTML=`<section class="kgpa-inquiry-panel"><h2>${escapeHTML(title)}</h2><div id="kgpai-message" role="status" aria-live="polite" hidden></div>${contents}</section>`;}
  function show(message,bad=false){const x=document.getElementById('kgpai-message');if(x){x.hidden=false;x.className='kgpa-notice '+(bad?'kgpa-notice-error':'kgpa-notice-success');x.textContent=String(message);x.scrollIntoView({block:'nearest'});}}
  async function req(path,method='GET',data=null){
    const response=await fetch(conf.operatorRoot+path,{method,cache:'no-store',credentials:'same-origin',headers:{'X-WP-Nonce':conf.nonce,'Accept':'application/json',...(data!==null?{'Content-Type':'application/json'}:{})},...(data!==null?{body:JSON.stringify(data)}:{})});
    const result=await response.json();if(!response.ok)throw Error(result?.message||`HTTP ${response.status}`);return result;
  }
  async function work(job){if(busy)return;busy=true;root.setAttribute('aria-busy','true');try{await job();}catch(e){show(e.message||e,true);}finally{busy=false;root.removeAttribute('aria-busy');}}
  const statusOptions=(selected)=>known.map(x=>`<option value="${x}" ${x===selected?'selected':''}>${names[x]}</option>`).join('');
  /** Private search values travel in the CSV POST body, never the browser URL. */
  function exportForm(){
    const url=new URL(conf.exportUrl);
    const nonce=url.searchParams.get('_wpnonce')||'';
    const fields={action:'kgpa_export_inquiries',_wpnonce:nonce,status:state.status,q:state.q,language:state.language,assignee:state.assignee};
    return `<form id="kgpai-export-form" class="kgpai-export" method="post" action="${escapeHTML(url.origin+url.pathname)}">
      ${Object.entries(fields).map(([name,value])=>`<input type="hidden" name="${escapeHTML(name)}" value="${escapeHTML(value)}">`).join('')}
      <button type="submit" class="button">현재 검색 범위 CSV 내보내기</button>
      <small>접수번호·이메일·언어·상태·담당자·대학/전공 ID·접수/삭제 예정일만 포함합니다. 문의 본문과 내부 메모는 제외됩니다.</small>
    </form>`;
  }
  function maskEmail(v){
    const s=String(v||'');const n=s.lastIndexOf('@');
    return n>0?s[0]+'***'+s.slice(n):'***';
  }
  async function list(){
    frame('A08 · 문의 관리',`<p>문의는 허가받은 담당자에게만 공개됩니다. 상태 변경·내부 메모는 기록되며, 실제 답변은 별도 이메일로 발송합니다.</p>
      <section class="kgpa-panel"><form id="kgpai-search" class="kgpai-filter-form" aria-label="문의 검색">
        <label>상태<select name="status"><option value="">전체</option>${statusOptions(state.status)}</select></label>
        <label>상담 언어<select name="language"><option value="">전체</option><option value="ko" ${state.language==='ko'?'selected':''}>한국어</option><option value="en" ${state.language==='en'?'selected':''}>English</option></select></label>
        <label>담당자<select name="assignee"><option value="">전체</option><option value="unassigned" ${state.assignee==='unassigned'?'selected':''}>미배정</option><option value="assigned" disabled>특정 담당자는 ID로 검색</option></select></label>
        <label>담당자 WP 사용자 ID<input name="assignee_id" type="number" min="1" step="1" value="${/^[0-9]+$/.test(state.assignee)?escapeHTML(state.assignee):''}" placeholder="전체 또는 미배정"></label>
        <label>접수번호 또는 이메일<input name="q" type="search" maxlength="120" value="${escapeHTML(state.q)}" placeholder="접수번호 우선"></label>
        <div class="kgpa-actions"><button type="submit" class="button button-primary">검색</button><button type="button" class="button" id="kgpai-reset">초기화</button></div>
      </form>${exportForm()}</section>
      <div class="kgpai-layout"><section class="kgpa-panel kgpai-list-pane"><h3>문의 목록</h3><div id="kgpai-list" aria-live="polite">조회 중…</div></section>
      <section class="kgpa-panel kgpai-detail-pane" aria-label="선택한 문의 상세"><div id="kgpai-detail"><p>목록에서 문의를 선택해 주세요.</p></div></section></div>
      <div id="kgpai-deletion-log"></div>`);
    const query=new URLSearchParams({page:String(state.page),per_page:'20'});
    for(const key of ['status','q','language','assignee'])if(state[key])query.set(key,state[key]);
    const result=await req('inquiries?'+query);
    const list=document.getElementById('kgpai-list');
    if(!list)return;
    list.innerHTML=`<p>${escapeHTML(result.total)}건 · ${escapeHTML(result.page)} / ${Math.max(1,result.total_pages)}페이지</p>`+
      (result.items.length?`<div class="kgpai-scroll" role="region" aria-label="문의 검색 결과"><table class="widefat kgpai-table"><thead><tr><th scope="col">접수번호</th><th scope="col">이메일</th><th scope="col">언어</th><th scope="col">상태</th><th scope="col">담당자 ID</th><th scope="col">접수일</th><th scope="col">알림 메일</th><th scope="col">작업</th></tr></thead><tbody>${result.items.map(r=>`<tr><td>${escapeHTML(r.receipt_code)}</td><td title="목록에서는 일부 가림">${escapeHTML(maskEmail(r.email))}</td><td>${escapeHTML(r.inquiry_locale)}</td><td>${escapeHTML(names[r.handling_status]||r.handling_status)}</td><td>${escapeHTML(r.assigned_to||'—')}</td><td>${escapeHTML(r.created_at)}</td><td>${escapeHTML(r.email_delivery_status)}</td><td><button type="button" class="button" data-open="${Number(r.id)}" aria-label="접수 ${escapeHTML(r.receipt_code)} 상세 보기">상세</button></td></tr>`).join('')}</tbody></table></div>`:'<p>이 조건에 해당하는 문의가 없습니다.</p>')+
      `<div class="kgpa-actions">${state.page>1?'<button class="button" data-page="prev">이전</button>':''}${state.page<result.total_pages?'<button class="button" data-page="next">다음</button>':''}</div>`;
    try {
      const deletions=await req('inquiry-deletions');
      const x=document.getElementById('kgpai-deletion-log');
      if(x)x.innerHTML=`<section class="kgpa-panel"><h3>최근 개인정보 삭제 기록</h3><p>삭제 기록에는 문의 본문·이메일·접수번호가 포함되지 않습니다.</p><ul>${deletions.items.slice(0,10).map(a=>`<li>${escapeHTML(a.erased_at)} UTC · ${escapeHTML(a.reason_code)} · 처리자 ${escapeHTML(a.actor_id||'system')}</li>`).join('')}</ul></section>`;
    } catch(_) { /* The inquiry list must remain usable if the deletion log is unavailable. */ }
  }
  async function detail(id){
    const data=await req('inquiries/'+id);state.current=id;
    const r=data.inquiry,e=data.events;
    state.currentRecord={id:Number(id),handling_status:r.handling_status,assigned_to:r.assigned_to===null?null:Number(r.assigned_to)};
    const target=document.getElementById('kgpai-detail');
    if(!target)return;
    target.innerHTML=`<section class="kgpa-panel"><h3>문의 상세 #${escapeHTML(r.receipt_code)}</h3>
      <dl class="kgpai-details"><dt>접수 이메일</dt><dd>${escapeHTML(r.email)}</dd><dt>상담 언어</dt><dd>${escapeHTML(r.inquiry_locale)}</dd><dt>희망 대학 내부 ID</dt><dd>${escapeHTML(r.university_id||'미선택')}</dd><dt>희망 전공 내부 ID</dt><dd>${escapeHTML(r.program_id||'미선택')}</dd><dt>희망 시기</dt><dd>${escapeHTML(r.preferred_intake||'미선택')}</dd><dt>문의</dt><dd class="kgpai-pre">${escapeHTML(r.message_body)}</dd>
      <dt>동의 정책/시각</dt><dd>${escapeHTML(r.consent_policy_version)} / ${escapeHTML(r.consented_at)}</dd><dt>삭제 예정일</dt><dd>${escapeHTML(r.delete_after)} UTC</dd><dt>알림 메일 상태</dt><dd>${escapeHTML(r.email_delivery_status)}</dd></dl>
      <form id="kgpai-update" class="kgpa-inline-form" data-id="${Number(id)}"><label>진행 상태<select name="handling_status">${statusOptions(r.handling_status)}</select></label><label>담당자 WP 사용자 ID (비우면 미배정)<input name="assigned_to" type="number" min="1" value="${escapeHTML(r.assigned_to||'')}"></label><button class="button button-primary">변경 저장</button></form>
      <form id="kgpai-note" data-id="${Number(id)}"><label>상담 담당자 내부 메모<textarea name="note" rows="3" maxlength="2000" required></textarea></label><button class="button">메모 추가</button></form>
      <h4>처리 기록</h4><ul>${e.map(x=>`<li>${escapeHTML(x.recorded_at)} · ${escapeHTML(x.event_type)} · 담당 ${escapeHTML(x.actor_id||'system')}${x.internal_note?`: ${escapeHTML(x.internal_note)}`:''}</li>`).join('')}</ul>
      <button type="button" id="kgpai-erase" data-id="${Number(id)}" class="button">개인정보 영구 삭제</button></section>`;
    target.scrollIntoView({block:'start',behavior:'smooth'});
  }
  function checked(value){return value?'확인됨':'확인 필요';}
  function operationsPanel(o){
    const v=x=>escapeHTML(x??'확인 필요');
    return `<section class="kgpa-panel" aria-label="운영 진단"><h3>운영 환경 · 읽기 전용</h3><p>이 화면은 실제 메일 전송, 백업 생성 또는 복원 검사를 수행하지 않습니다. 비밀번호·API 키·라이선스 키도 표시하지 않습니다.</p>
      <dl class="kgpa-definition">
      <div><dt>버전</dt><dd>WordPress ${v(o.wordpress_version)} / PHP ${v(o.php_version)} / Core ${v(o.core_version)} / Admin ${v(o.admin_version)} / Ops ${v(o.ops_version)}</dd></div>
      <div><dt>사이트 HTTPS</dt><dd>${checked(o.home_https)}</dd></div>
      <div><dt>WP-Cron 상태</dt><dd>${o.wp_cron_disabled?'WP-Cron 자동 실행 비활성(서버 Cron 확인 필요)':'WP-Cron 활성화 가능 — 서버 Cron 설정 별도 확인'}</dd></div>
      <div><dt>공식 출처 점검 예정</dt><dd>${v(o.recheck_next_utc)} UTC</dd></div>
      <div><dt>문의 개인정보 삭제 예정</dt><dd>${v(o.inquiry_cleanup_next_utc)} UTC</dd></div>
      <div><dt>이메일 전달</dt><dd>실제 발송 시험 필요</dd></div>
      <div><dt>백업·복원</dt><dd>별도 환경에서 복원 시험 필요</dd></div>
      <div><dt>플러그인 라이선스</dt><dd>WordPress 플러그인 화면에서 별도 확인</dd></div></dl>
      <p class="kgpai-admin-links"><a class="button" href="${escapeHTML(link('kgp-operator-entities'))}">대학·담당자 배정</a>
      <a class="button" href="${escapeHTML(link('kgp-operator-sources'))}">공식 출처·기관</a></p>
    </section>`;
  }
  async function settings(){
    frame('A09 · 운영 설정','<p>실제 개인정보 수집은 승인된 수집 목적·고지문·보관 기간이 갖춰진 경우에만 활성화하세요.</p><nav class="kgpai-settings-nav" aria-label="설정 구성"><a href="#kgpai-settings">문의·개인정보</a><a href="#kgpai-ops-panel">운영 환경·권한</a></nav><div id="kgpai-settings">설정 로딩 중…</div><div id="kgpai-ops-panel" role="region" aria-live="polite"></div>');
    const result=await req('inquiry-settings');const s=result.settings;
    document.getElementById('kgpai-settings').innerHTML=`<section class="kgpa-panel"><h3>문의·개인정보 운영 정책</h3><p>현재 접수 상태: <strong>${result.ready?'활성화':'비활성화'}</strong> · 예약 삭제: ${escapeHTML(result.cron_next||'미설정')}</p>
      <form id="kgpai-settings-form" class="kgpai-settings-form">
        <label>정책 버전<input name="policy_version" maxlength="64" required value="${escapeHTML(s.policy_version)}" placeholder="2026-11-v1"></label>
        <label>보관 기간(일)<input name="retention_days" type="number" min="1" max="3650" required value="${Number(s.retention_days)||''}"></label>
        <label>한국어 수집 목적<textarea name="purpose_ko" rows="3" required>${escapeHTML(s.purpose_ko)}</textarea></label>
        <label>English purpose of collection<textarea name="purpose_en" rows="3" required>${escapeHTML(s.purpose_en)}</textarea></label>
        <label>한국어 개인정보 안내문<textarea name="notice_ko" rows="5" required>${escapeHTML(s.notice_ko)}</textarea></label>
        <label>English privacy notice<textarea name="notice_en" rows="5" required>${escapeHTML(s.notice_en)}</textarea></label>
        <label>삭제·열람 문의 이메일<input name="deletion_contact" type="email" maxlength="254" required value="${escapeHTML(s.deletion_contact)}"></label>
        <label>신규 문의 알림 이메일<input name="notify_email" type="email" maxlength="254" required value="${escapeHTML(s.notify_email)}"></label>
        <label>시간당 문의 접수 최대 건수(기본 5)<input name="max_submissions_per_hour" type="number" min="1" max="20" required value="${Number(s.max_submissions_per_hour)}"></label>
        <label class="kgpai-checkbox"><input name="intake_enabled" type="checkbox" ${s.intake_enabled?'checked':''}> 개인정보 기준 검토 후 운영자 문의 접수를 활성화합니다.</label>
        <p>보관 기간 변경은 이후 신규 문의에 적용됩니다. 이전 문의는 기록된 삭제 예정일을 따릅니다.</p>
        <button type="submit" class="button button-primary">운영 설정 저장</button></form>
      <p>서버 예약 작업에서 <code>kgpa_inquiry_retention_cleanup</code> 훅이 매일 실행되도록 실제 Cron을 설정해야 합니다.</p></section>`;
    const ops=document.getElementById('kgpai-ops-panel');
    if(ops){
      try {ops.innerHTML=operationsPanel(await req('operations'));}
      catch(_){ops.innerHTML='<section class="kgpa-panel"><h3>운영 환경·권한</h3><p>운영 진단은 WordPress 최고 관리자 권한이 있어야 볼 수 있습니다. 설정 변경·승인 권한은 기존 서버 정책을 따릅니다.</p></section>';}
    }
  }
  root.addEventListener('click',e=>{
    const reset=e.target.closest('#kgpai-reset');
    if(reset){state.page=1;state.status='';state.q='';state.language='';state.assignee='';state.current=null;state.currentRecord=null;work(list);return;}
    const open=e.target.closest('[data-open]');if(open){work(()=>detail(Number(open.dataset.open)));return;}
    const pager=e.target.closest('[data-page]');if(pager){state.page+=pager.dataset.page==='next'?1:-1;work(list);return;}
    const erase=e.target.closest('#kgpai-erase');if(erase){work(async()=>{
      if(prompt('이 문의와 내부 메모를 영구 삭제합니다. 확인하려면 DELETE를 입력하세요.')!=='DELETE')return;
      if(!confirm('실제 삭제 후 복구되지 않습니다. 계속할까요?'))return;
      const result=await req('inquiries/'+erase.dataset.id+'/erase','POST',{confirm:'DELETE'});
      if(result.erased){await list();show('문의 및 내부 메모가 영구 삭제되었습니다.');}
    });}
  });
  root.addEventListener('submit',e=>{
    const form=e.target;
    if(form.id==='kgpai-export-form'){
      if(!window.confirm('현재 검색 조건에 해당하는 문의 메타데이터를 CSV로 내보내시겠습니까? 접수 이메일 등이 포함됩니다. 저장 목적·보관 위치·접근 권한을 확인하세요.'))e.preventDefault();
      return;
    }
    if(!['kgpai-search','kgpai-update','kgpai-note','kgpai-settings-form'].includes(form.id))return;
    e.preventDefault();
    work(async()=>{
      const f=new FormData(form);
      if(form.id==='kgpai-search'){
        state.status=String(f.get('status')||'');state.q=String(f.get('q')||'').trim();
        state.language=String(f.get('language')||'');
        const who=String(f.get('assignee_id')||'').trim();
        state.assignee=who||String(f.get('assignee')||'');
        if(who&&!/^[1-9][0-9]{0,11}$/.test(who))throw new Error('담당자 ID는 양의 정수여야 합니다.');
        state.page=1;state.current=null;state.currentRecord=null;await list();return;
      }
      if(form.id==='kgpai-update'){
        if(!state.currentRecord||state.currentRecord.id!==Number(form.dataset.id))throw Error('문의 상세를 다시 열어 주세요.');
        await req('inquiries/'+form.dataset.id+'/update','POST',{handling_status:f.get('handling_status'),assigned_to:f.get('assigned_to')||null,
          expected_status:state.currentRecord.handling_status,expected_assigned_to:state.currentRecord.assigned_to});
        await detail(Number(form.dataset.id));show('문의 상태·담당자를 저장했습니다.');return;
      }
      if(form.id==='kgpai-note'){
        await req('inquiries/'+form.dataset.id+'/note','POST',{note:f.get('note')});
        await detail(Number(form.dataset.id));show('내부 메모를 추가했습니다.');return;
      }
      if(form.id==='kgpai-settings-form'){
        const data=Object.fromEntries([...f.entries()].filter(([k])=>k!=='intake_enabled'));
        data.retention_days=Number(data.retention_days);data.max_submissions_per_hour=Number(data.max_submissions_per_hour);
        data.intake_enabled=f.has('intake_enabled');
        if(data.intake_enabled && !confirm('현재 개인정보 안내문·수집 목적·보관 기간이 법적·운영상 검토를 마쳤나요? 저장하면 실제 문의 수집이 시작됩니다.'))return;
        await req('inquiry-settings','POST',data);await settings();show('운영 설정을 저장했습니다.');return;
      }
    });
  });
  work(conf.page==='inquiries'?list:settings);
})();
