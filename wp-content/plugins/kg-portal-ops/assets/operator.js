/* v1.0.0 — authenticated operations UI. No automatic source-approval paths. */
(() => {
  'use strict';
  const conf=window.KGPO_ADMIN,root=document.getElementById('kgpo-dashboard');
  if(!conf||!root)return;
  const state={sourcePage:1,sourceFilter:'',taskPage:1,taskFilter:'pending',sourceDetail:null,busy:false,checked:new Set()};
  const categories={admissions:'모집 일정 (주간 점검 제안)',fees:'등록금·장학·어학 조건',university:'대학 기본 정보',certification:'인증·평가',visa:'비자·체류 규정',statistics:'공식 공시',other:'기타 공식 출처'};
  const e=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const pos=(v,label)=>{const n=Number(v);if(!Number.isSafeInteger(n)||n<1)throw Error(label+'에 양의 정수를 입력해 주세요.');return n;};
  const safeUrl=u=>{try{const x=new URL(String(u));return ['http:','https:'].includes(x.protocol)?x.href:'';}catch{return '';}};
  const safeLink=(u,caption)=>{const url=safeUrl(u);return url?`<a href="${e(url)}" target="_blank" rel="noopener noreferrer">${e(caption)} ↗</a>`:e(caption);};
  const button=(action,label,attrs='',primary=false)=>`<button type="button" class="button ${primary?'button-primary':''}" data-action="${e(action)}" ${attrs}>${e(label)}</button>`;
  const nav=()=>[['kgpo-operations','운영 현황'],['kgpo-sources','공식 출처'],['kgpo-review','검토 대기열'],...(conf.manager?[['kgpo-seo','캐시·SEO 설정']]:[])].map(([k,l])=>`<a href="${e(conf.adminBase+k)}" ${conf.page===k?'aria-current="page"':''}>${e(l)}</a>`).join('');
  function frame(title,html){root.innerHTML=`<nav class="kgpo-nav" aria-label="운영 메뉴">${nav()}</nav><div id="kgpo-feedback" role="status" aria-live="polite" hidden></div><div class="kgpo-page"><h2>${e(title)}</h2>${html}</div>`;}
  function feedback(msg,error=false){const el=document.getElementById('kgpo-feedback');if(!el)return;el.textContent=String(msg);el.hidden=false;el.className=error?'kgpo-feedback kgpo-error':'kgpo-feedback kgpo-success';el.scrollIntoView({block:'nearest'});}
  async function req(path,method='GET',body=null){
    const response=await fetch(conf.root+path,{method,credentials:'same-origin',cache:'no-store',redirect:'error',headers:{'X-WP-Nonce':conf.nonce,'Accept':'application/json',...(body!==null?{'Content-Type':'application/json'}:{})},...(body!==null?{body:JSON.stringify(body)}:{})});
    let j;try{j=await response.json();}catch{throw Error('서버 응답을 읽지 못했습니다. HTTP '+response.status);}
    if(!response.ok)throw Error((j.message||j.code||'오류')+' (HTTP '+response.status+')');
    return j;
  }
  async function busy(fn){if(state.busy)return;state.busy=true;root.setAttribute('aria-busy','true');try{await fn();}catch(err){feedback(err.message||String(err),true);}finally{state.busy=false;root.removeAttribute('aria-busy');}}
  function pageButton(curr,total,action){const max=Math.max(1,total);return max===1?'':`<div class="kgpo-paging" role="navigation" aria-label="페이지 이동">${curr>1?button(action,'이전',`data-page="${curr-1}"`):''}<span>${curr} / ${max}</span>${curr<max?button(action,'다음',`data-page="${curr+1}"`):''}</div>`;}
  function badge(text){const cls=['changed','unavailable','unsafe_url','source_invalid','pending'].includes(String(text))?'kgpo-warn':'kgpo-subtle';return `<span class="kgpo-badge ${cls}">${e(text||'untracked')}</span>`;}
  async function overview(){
    frame('공식 출처 운영 현황','<p class="kgpo-muted">최신 검토 대기 건수와 예약 실행 상태를 조회하고 있습니다.</p>');
    const data=await req('overview');
    const c=data.counts;const s=data.settings;
    let html=`<div class="kgpo-kpis">${[['기한 도래',c.due],['검토 대기',c.pending],['검토 중',c.in_progress],['해결 완료',c.resolved]].map(([k,v])=>`<article><small>${e(k)}</small><strong>${e(v)}</strong></article>`).join('')}</div>`;
    html+=`<section class="kgpo-panel"><h3>자동 확인 스케줄러</h3><dl class="kgpo-facts"><dt>자동 확인 상태</dt><dd>${s.monitor_enabled?'활성':'비활성 (초기값)'}</dd><dt>WP-Cron 다음 예정</dt><dd>${data.wp_cron_next?new Date(data.wp_cron_next*1000).toLocaleString('ko-KR'):'없음'}</dd><dt>마지막 실행</dt><dd>${e(s.last_run_at||'없음')} UTC</dd><dt>출처 서비스</dt><dd>${data.core_ready?'연결 가능':'Core 확인 필요'}</dd></dl>
    <p class="kgpo-note">WP-Cron은 방문량이 적으면 지연될 수 있습니다. 실제 서버에서 <code>ddev wp kgpo scan --limit=15</code>과 동일한 명령을 하루 한 번 Cron으로 등록해야 합니다.</p>
    ${conf.manager?button('scan-due','확인 기한 도래 출처 검사 (최대 25건)','',true):''}</section>`;
    html+=`<section class="kgpo-panel"><h3>검토 원칙</h3><p>원문 HTTP 응답이나 일부 바이트의 해시 변화는 <strong>변경 가능성</strong>일 뿐입니다. 실제 내용·적용 회차·공식 정정 공지를 직접 확인하고 Core의 기존 출처 검토 절차로 승인 또는 무효화하세요.</p><p>${safeLink(conf.coreSourceUrl,'기존 출처 검증 화면')} · ${safeLink(conf.adminBase+'kgpo-review','검토 대기열')}</p></section>`;
    frame('공식 출처 운영 현황',html);
  }
  function sourceForm(row){const sel=Object.entries(categories).map(([k,v])=>`<option value="${e(k)}" ${k===(row.category||'other')?'selected':''}>${e(v)}</option>`).join('');
    return `<section class="kgpo-panel"><h3>출처 #${e(row.id)} 운영 주기</h3><p>${safeLink(row.public_url,row.document_title||'공식 원문')}</p>
    <p>현재 출처 검증 상태: ${badge(row.evidence_status)} · 최근 자동 결과: ${badge(row.last_result)}</p>
    ${conf.manager?`<form id="kgpo-source-form" data-id="${Number(row.id)}" class="kgpo-fields"><label>분류<select name="category">${sel}</select></label><label>확인 주기(일)<input name="cadence_days" type="number" min="1" max="365" value="${e(row.cadence_days||90)}" required></label><label>담당 검토자 WP 사용자 ID<input name="assigned_to" type="number" min="1" value="${e(row.assigned_to||'')}"></label><button class="button button-primary" type="submit">검토 주기·담당자 저장</button></form>`:''}
    <div class="kgpo-actions">${button('scan-source','해당 출처 확인',`data-id="${Number(row.id)}"`,true)}${button('view-history','이전 확인 기록',`data-id="${Number(row.id)}"`)}</div><div id="kgpo-source-history"></div></section>`;}
  async function sources(){
    const q=new URLSearchParams({page:String(state.sourcePage)});if(state.sourceFilter)q.set('filter',state.sourceFilter);
    frame('공식 출처 모니터링','<p>목록을 조회하는 중입니다…</p>');
    const data=await req('sources?'+q);
    const select=`<label>상태<select id="kgpo-source-filter"><option value="" ${!state.sourceFilter?'selected':''}>전체</option><option value="due" ${state.sourceFilter==='due'?'selected':''}>재확인 기한 도래</option><option value="flagged" ${state.sourceFilter==='flagged'?'selected':''}>이상 신호</option></select></label>`;
    let table=`<div class="kgpo-scroll"><table class="widefat striped"><thead><tr><th>ID</th><th>공식 자료</th><th>분류</th><th>다음 확인(UTC)</th><th>신호</th><th>작업</th></tr></thead><tbody>`;
    table+=(data.items||[]).map(r=>`<tr><td>${e(r.id)}</td><td>${safeLink(r.public_url,r.document_title||'원문')}</td><td>${e(categories[r.category]||'미설정')}</td><td>${e(r.next_check_at||'미설정')}</td><td>${badge(r.last_result)}</td><td>${button('source-open','관리',`data-id="${Number(r.id)}"`)}</td></tr>`).join('')||'<tr><td colspan="6">조회된 출처가 없습니다.</td></tr>';
    table+='</tbody></table></div>';
    frame('공식 출처 모니터링',`<section class="kgpo-panel"><div class="kgpo-toolbar">${select}<p>${e(data.total)}개 공식 출처</p></div>${table}${pageButton(data.page,data.total_pages,'source-page')}</section><div id="kgpo-source-detail"></div>`);
    if(state.sourceDetail){const row=data.items?.find(x=>Number(x.id)===state.sourceDetail);if(row)document.getElementById('kgpo-source-detail').innerHTML=sourceForm(row);}
  }
  async function sourceOpen(id){const data=await req('sources?page='+state.sourcePage+(state.sourceFilter?'&filter='+encodeURIComponent(state.sourceFilter):''));
    const row=data.items.find(x=>Number(x.id)===id);if(!row)throw Error('출처가 현재 페이지에 없습니다.');state.sourceDetail=id;
    document.getElementById('kgpo-source-detail').innerHTML=sourceForm(row);
    document.getElementById('kgpo-source-detail').scrollIntoView({block:'nearest'});
  }
  async function sourceHistory(id){const d=await req('sources/'+id+'/history');const target=document.getElementById('kgpo-source-history');if(!target)return;
    target.innerHTML=`<h4>최근 확인 기록</h4><p>원문 내용은 보관하지 않고 해시·HTTP 상태만 남깁니다.</p>${(d.items||[]).map(v=>`<details><summary>${e(v.checked_at)} UTC · ${e(v.result_code)} · HTTP ${e(v.http_status||'없음')}</summary><div class="kgpo-diff"><pre>${e(v.before_json||'null')}</pre><pre>${e(v.after_json||'null')}</pre></div></details>`).join('')||'<p>확인 기록이 없습니다.</p>'}`;
  }
  async function tasks(){
    frame('출처 재검토 대기열','<p>검토 요청을 읽는 중입니다…</p>');
    const data=await req('tasks?'+new URLSearchParams({status:state.taskFilter,page:String(state.taskPage)}));
    let content=`<section class="kgpo-panel"><label>처리 상태<select id="kgpo-task-status">${['pending','in_progress','resolved'].map(v=>`<option value="${v}" ${v===state.taskFilter?'selected':''}>${v}</option>`).join('')}</select></label><p>총 ${e(data.total)}건</p></section>`;
    content+=(data.items||[]).map(x=>`<article class="kgpo-panel kgpo-task"><h3>출처 #${e(x.source_id)} · ${e(x.reason_code)} ${badge(x.status_code)}</h3><p>${safeLink(x.public_url,x.document_title||'공식 원문')}</p><dl class="kgpo-facts"><dt>검토 요청일</dt><dd>${e(x.opened_at)} UTC</dd><dt>검토 담당자</dt><dd>${e(x.assigned_to||'미배정')}</dd><dt>최근 변경 신호</dt><dd>확인 기록 #${e(x.check_id||'없음')}</dd></dl>
    ${x.status_code==='resolved'?`<p>해결: ${e(x.resolution_code)} · ${e(x.review_note||'')}</p>`:conf.reviewer?`<div class="kgpo-review-form" data-task="${Number(x.id)}"><label>확인 결과<select class="kgpo-resolution"><option value="">검토 중으로 변경</option><option value="verified_no_change">원문에 실질 변경 없음</option><option value="updated_source">Core에서 변경 내용 검증·승인 완료</option><option value="invalidated_in_core">Core에서 근거 무효화 완료</option><option value="deferred_to_editor">담당자에게 재검토 이관</option></select></label><label>공식 원문 확인·조치 메모 (10~2,000자)<textarea class="kgpo-review-note" rows="3" minlength="10" maxlength="2000" placeholder="어떤 자료의 어느 위치를 직접 확인했는지 기록"></textarea></label><p>원문 변경 신호만으로 기존 공개값을 자동 변경하지 않습니다.</p>${button('review-task','검토 결과 저장',`data-id="${Number(x.id)}"`,true)}</div>`:'<p>검토자 권한이 있어야 완료할 수 있습니다.</p>'}</article>`).join('')||'<p>현재 조건에 해당하는 검토 요청이 없습니다.</p>';
    frame('출처 재검토 대기열',content+pageButton(data.page,data.total_pages,'task-page'));
  }
  async function seo(){frame('캐시·SEO 운영 설정','<p>현재 운영 옵션을 불러오는 중입니다…</p>');
    const [settings,preview]=await Promise.all([req('settings'),req('seo/preview')]);
    const css=`<section class="kgpo-panel"><h3>운영 스케줄</h3><form id="kgpo-settings" class="kgpo-fields"><label class="kgpo-check"><input type="checkbox" name="monitor_enabled" ${settings.monitor_enabled?'checked':''}> 자동 출처 점검 활성화</label>
      <label class="kgpo-check"><input type="checkbox" name="strict_no_cache" ${settings.strict_no_cache?'checked':''}> 공개 정보 경로에서 캐시를 엄격하게 우회 (권장)</label>
      <label>회차별 검사 개수 (1–25)<input name="batch_size" type="number" min="1" max="25" value="${e(settings.batch_size)}" required></label>
      <label>원문 응답 대기(초)<input name="fetch_timeout" type="number" min="3" max="12" value="${e(settings.fetch_timeout)}" required></label>
      <label>문서 해시 표본 크기(바이트)<input name="max_sample_bytes" type="number" min="16384" max="262144" value="${e(settings.max_sample_bytes)}" required></label>
      <label>재검토 알림 이메일(선택)<input name="notifications_email" type="email" maxlength="254" value="${e(settings.notifications_email)}"></label>
      <button type="submit" class="button button-primary">운영 설정 저장</button></form></section>`;
    frame('캐시·SEO 운영 설정',css+`<section class="kgpo-panel"><h3>검색엔진·사이트맵 상태</h3><dl class="kgpo-facts"><dt>검수 환경 차단</dt><dd>${preview.staging?'활성: 사이트맵 404 / robots Disallow':'운영 주소 기준 공개'}</dd><dt>승인된 대학</dt><dd>${e(preview.published_universities)}개 (조회 가능한 대학만)</dd><dt>승인된 가이드·정책·FAQ</dt><dd>${e(preview.published_articles)}개</dd><dt>사이트맵</dt><dd>${safeLink(preview.sitemap_url,'kgp-sitemap.xml')}</dd></dl>
      <p>실제 색인은 운영 도메인에서만 허용합니다. 필터·검수·초안·문의 상세는 인덱싱하지 않습니다.</p>
      <div class="kgpo-actions">${button('purge-cache','WordPress 오브젝트 캐시 비우기')}</div><p class="kgpo-note">WP-Optimize 디스크 캐시와 CDN은 별도로 Purge해야 합니다. 외부 캐시까지 삭제했다고 표시하지 않습니다.</p></section>`);
  }
  root.addEventListener('change',evt=>{
    if(evt.target.id==='kgpo-source-filter'){state.sourceFilter=evt.target.value;state.sourcePage=1;state.sourceDetail=null;busy(sources);}
    if(evt.target.id==='kgpo-task-status'){state.taskFilter=evt.target.value;state.taskPage=1;busy(tasks);}
  });
  root.addEventListener('click',evt=>{
    const btn=evt.target.closest('[data-action]');if(!btn||!root.contains(btn))return;
    busy(async()=>{const a=btn.dataset.action;
      if(a==='source-page'){state.sourcePage=pos(btn.dataset.page,'페이지');state.sourceDetail=null;await sources();return;}
      if(a==='task-page'){state.taskPage=pos(btn.dataset.page,'페이지');await tasks();return;}
      if(a==='source-open'){await sourceOpen(pos(btn.dataset.id,'출처 ID'));return;}
      if(a==='view-history'){await sourceHistory(pos(btn.dataset.id,'출처 ID'));return;}
      if(a==='scan-source'){
        const id=pos(btn.dataset.id,'출처 ID');if(!confirm('공식 자료 URL에 짧은 HTTP 요청을 보내 해시와 접속 여부만 확인할까요?'))return;
        const result=await req('sources/'+id+'/check','POST',{});await sources();feedback(`출처 #${id}: ${result.result||result.status}. 변경 신호가 있으면 검토 대기열을 확인하세요.`);return;
      }
      if(a==='scan-due'){const result=await req('scan','POST',{limit:Math.min(25,Number((await req('overview')).settings.batch_size)||15)});await overview();feedback(`확인 완료: ${result.checked}건. ${result.status}`);return;}
      if(a==='review-task'){
        const id=pos(btn.dataset.id,'검토 건');const form=btn.closest('[data-task]');
        const note=form.querySelector('.kgpo-review-note').value.trim();const resolution=form.querySelector('.kgpo-resolution').value;
        if(note.length<10)throw Error('원문 확인 내용을 최소 10자 이상 작성하세요.');
        if(resolution==='updated_source' && !confirm('실제로 Core의 공식 출처 승인 처리를 완료했나요? 이 버튼은 Core 내용을 승인하지 않습니다.'))return;
        await req('tasks/'+id+'/review','POST',{status:resolution?'resolved':'in_progress',resolution,note});await tasks();feedback('검토 기록을 저장했습니다.');return;
      }
      if(a==='purge-cache'){
        if(!confirm('WordPress 오브젝트 캐시를 비울까요? 외부 CDN과 WP-Optimize 페이지 캐시는 포함하지 않습니다.'))return;
        const result=await req('cache/purge','POST',{});feedback(result.message);return;
      }
    });
  });
  root.addEventListener('submit',evt=>{
    const form=evt.target;if(!['kgpo-source-form','kgpo-settings'].includes(form.id))return;
    evt.preventDefault();busy(async()=>{
      const f=new FormData(form);
      if(form.id==='kgpo-source-form'){
        const id=pos(form.dataset.id,'출처 ID');
        const payload={category:f.get('category'),cadence_days:pos(f.get('cadence_days'),'확인 주기'),assigned_to:f.get('assigned_to')?pos(f.get('assigned_to'),'담당자'):null};
        await req('sources/'+id+'/policy','POST',payload);await sources();feedback('출처별 주기·담당자 설정을 저장했습니다.');return;
      }
      if(form.id==='kgpo-settings'){
        const payload={monitor_enabled:f.has('monitor_enabled'),strict_no_cache:f.has('strict_no_cache'),
          batch_size:pos(f.get('batch_size'),'검사 개수'),fetch_timeout:pos(f.get('fetch_timeout'),'대기 시간'),
          max_sample_bytes:pos(f.get('max_sample_bytes'),'표본 크기'),notifications_email:f.get('notifications_email')};
        await req('settings','POST',payload);await seo();feedback('운영 설정을 저장했습니다.');return;
      }
    });
  });
  const screens={'kgpo-operations':overview,'kgpo-sources':sources,'kgpo-review':tasks,'kgpo-seo':seo};
  (screens[conf.page]||overview)().catch(err=>{frame('운영 화면 오류',`<p role="alert" class="kgpo-error">${e(err.message||err)}</p>`);});
})();
