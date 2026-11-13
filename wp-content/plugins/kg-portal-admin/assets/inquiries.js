/* A08 inquiry access and A09 privacy/retention settings. Core field workflow is unchanged. */
(() => {
  'use strict';
  const conf=window.KGP_ADMIN, root=document.getElementById('kgp-operator-root');
  if (!conf || !root || !['inquiries','settings'].includes(conf.page)) return;
  const cap=conf.permissions||{};
  const escapeHTML=(v)=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let busy=false,state={page:1,status:'',q:'',current:null};
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
  function frame(title,contents){root.innerHTML=nav()+`<section class="kgpa-inquiry-panel"><h2>${escapeHTML(title)}</h2><div id="kgpai-message" role="status" aria-live="polite" hidden></div>${contents}</section>`;}
  function show(message,bad=false){const x=document.getElementById('kgpai-message');if(x){x.hidden=false;x.className='kgpa-notice '+(bad?'kgpa-notice-error':'kgpa-notice-success');x.textContent=String(message);x.scrollIntoView({block:'nearest'});}}
  async function req(path,method='GET',data=null){
    const response=await fetch(conf.operatorRoot+path,{method,cache:'no-store',credentials:'same-origin',headers:{'X-WP-Nonce':conf.nonce,'Accept':'application/json',...(data!==null?{'Content-Type':'application/json'}:{})},...(data!==null?{body:JSON.stringify(data)}:{})});
    const result=await response.json();if(!response.ok)throw Error(result?.message||`HTTP ${response.status}`);return result;
  }
  async function work(job){if(busy)return;busy=true;root.setAttribute('aria-busy','true');try{await job();}catch(e){show(e.message||e,true);}finally{busy=false;root.removeAttribute('aria-busy');}}
  const statusOptions=(selected)=>known.map(x=>`<option value="${x}" ${x===selected?'selected':''}>${names[x]}</option>`).join('');
  function exportLink(){const url=new URL(conf.exportUrl,location.origin);if(state.status)url.searchParams.set('status',state.status);if(state.q)url.searchParams.set('q',state.q);return url.toString();}
  async function list(){
    frame('A08 · 문의 관리',`<p>문의 내용과 내부 메모는 상담 담당자만 볼 수 있습니다. 별도의 이메일로 답변하며 자동 답장 기능은 사용하지 않습니다.</p>
      <form id="kgpai-search" class="kgpa-inline-form"><label>상태<select name="status"><option value="">전체</option>${statusOptions(state.status)}</select></label><label>접수번호 또는 이메일<input name="q" type="search" maxlength="120" value="${escapeHTML(state.q)}"></label><button type="submit" class="button button-primary">검색</button></form>
      <p><a id="kgpai-export" class="button" href="${escapeHTML(exportLink())}">검색 결과 CSV 내보내기</a> (본문·내부 메모 제외)</p><div id="kgpai-list">조회 중…</div><div id="kgpai-detail"></div><div id="kgpai-deletion-log"></div>`);
    const query=new URLSearchParams({page:String(state.page),per_page:'20'});if(state.status)query.set('status',state.status);if(state.q)query.set('q',state.q);
    const result=await req('inquiries?'+query);
    const list=document.getElementById('kgpai-list');
    list.innerHTML=`<p>${result.total}건 · ${result.page} / ${Math.max(1,result.total_pages)}페이지</p>`+
      (result.items.length?`<div class="kgpai-scroll"><table class="widefat striped"><thead><tr><th>접수번호</th><th>이메일</th><th>상태</th><th>담당자 ID</th><th>접수일</th><th>메일</th><th>열기</th></tr></thead><tbody>${result.items.map(r=>`<tr><td>${escapeHTML(r.receipt_code)}</td><td>${escapeHTML(r.email)}</td><td>${escapeHTML(names[r.handling_status]||r.handling_status)}</td><td>${escapeHTML(r.assigned_to||'-')}</td><td>${escapeHTML(r.created_at)}</td><td>${escapeHTML(r.email_delivery_status)}</td><td><button type="button" class="button" data-open="${Number(r.id)}">상세</button></td></tr>`).join('')}</tbody></table></div>`:'<p>조회된 문의가 없습니다.</p>')+
      `<div class="kgpa-actions">${state.page>1?'<button class="button" data-page="prev">이전</button>':''}${state.page<result.total_pages?'<button class="button" data-page="next">다음</button>':''}</div>`;
    try {
      const deletions=await req('inquiry-deletions');
      const x=document.getElementById('kgpai-deletion-log');
      if(x)x.innerHTML=`<section class="kgpa-panel"><h3>최근 개인정보 삭제 기록</h3><p>삭제 감사 기록에는 문의 내용·이메일·원본 접수번호가 포함되지 않습니다.</p><ul>${deletions.items.slice(0,10).map(a=>`<li>${escapeHTML(a.erased_at)} UTC · ${escapeHTML(a.reason_code)} · 처리자 ${escapeHTML(a.actor_id||'system')}</li>`).join('')}</ul></section>`;
    } catch(_) { /* Inquiry listing is still usable when audit table is temporarily unavailable. */ }
  }
  async function detail(id){
    const data=await req('inquiries/'+id);state.current=id;
    const r=data.inquiry,e=data.events;
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
  async function settings(){
    frame('A09 · 문의 및 개인정보 운영 설정','<p>문의 수집은 처음에 비활성화되어 있습니다. 정책 버전·수집 목적·개인정보 안내·보관 기간을 확정한 뒤에만 접수 가능 상태로 변경하세요.</p><div id="kgpai-settings">설정 로딩 중…</div>');
    const result=await req('inquiry-settings');const s=result.settings;
    document.getElementById('kgpai-settings').innerHTML=`<p>현재 접수 상태: <strong>${result.ready?'활성화':'비활성화'}</strong> · 예약 삭제: ${escapeHTML(result.cron_next||'미설정')}</p>
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
        <label class="kgpai-checkbox"><input name="intake_enabled" type="checkbox" ${s.intake_enabled?'checked':''}> 개인정보 기준을 검토하고 운영자 문의 접수를 활성화합니다.</label>
        <p>보관 기간 변경은 이후 신규 문의에 적용됩니다. 이전 문의는 각 문의의 기록된 삭제 예정일을 따릅니다.</p>
        <button type="submit" class="button button-primary">운영 설정 저장</button></form>
      <p>서버 예약 작업에서 <code>kgpa_inquiry_retention_cleanup</code> 훅이 매일 실행되도록 실제 Cron을 설정해야 합니다. 사이트 방문에만 의존하지 마세요.</p>`;
  }
  root.addEventListener('click',e=>{
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
    if(!['kgpai-search','kgpai-update','kgpai-note','kgpai-settings-form'].includes(form.id))return;
    e.preventDefault();
    work(async()=>{
      const f=new FormData(form);
      if(form.id==='kgpai-search'){
        state.status=f.get('status')||'';state.q=String(f.get('q')||'').trim();state.page=1;await list();return;
      }
      if(form.id==='kgpai-update'){
        await req('inquiries/'+form.dataset.id+'/update','POST',{handling_status:f.get('handling_status'),assigned_to:f.get('assigned_to')||null});
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
