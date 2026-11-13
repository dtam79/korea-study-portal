/* Searchable references: preserve original controls and Core submission payloads. */
(() => {
 'use strict';
 const conf=window.KGDS_LOOKUP;if(!conf)return;
 const mounted=new WeakMap(); let serial=0;
 const labels={university:'대학',campus:'캠퍼스',program:'전공',intake:'모집 회차',offering:'모집 과정',source:'공식 출처',issuer:'발행기관',user:'담당자',article:'한국어 원본',entry:'정보 항목'};
 const make=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text)n.textContent=text;return n;};
 const spec=input=>{
  const name=input.name||'';
  const map={university_id:'university',campus_id:'campus',program_id:'program',intake_id:'intake',offering_id:'offering',source_id:'source',issuer_id:'issuer',user_id:'user',assigned_to:'user',entry_id:'entry',kgpc_source_ids:'source',kgpc_translation_of:'article'};
  if(map[name])return {type:()=>map[name],multi:name==='kgpc_source_ids'};
  if(input.classList.contains('kgpa-source-id'))return {type:()=> 'source'};
  if((name==='id'&&input.closest('#kgpa-field-target'))||(name==='entity_id'&&input.closest('#kgpaw-audit-form')))return {type:()=>input.form.querySelector('[name="type"]').value,dynamic:true};
  return null;
 };
 function attach(input,s){
  if(mounted.has(input)||input.type==='hidden'||input.disabled)return;
  const root=make('div','kgds-reference'), picked=make('div','kgds-reference-picked'),row=make('div','kgds-reference-search');
  const query=make('input');query.type='search';query.autocomplete='off';query.id='kgds-search-'+(++serial);
  const results=make('div','kgds-reference-results');results.id=query.id+'-results';results.setAttribute('role','listbox');results.hidden=true;
  query.setAttribute('role','combobox');query.setAttribute('aria-autocomplete','list');query.setAttribute('aria-controls',results.id);query.setAttribute('aria-expanded','false');
  const live=make('p','kgds-reference-help');live.setAttribute('role','status');
  const required=input.required; input.required=false; input.type='hidden';input.before(root);row.append(query);root.append(picked,row,results,live);root.after(input);
  const label=input.closest('label')||document.querySelector('label[for="'+CSS.escape(input.id)+'"]');
  if(label){if(label.hasAttribute('for'))label.setAttribute('for',query.id);for(const node of label.childNodes){if(node.nodeType===3&&/ID|내부/.test(node.textContent))node.textContent=(labels[s.type()]||'대상')+(required?' *':'');}
   const span=label.querySelector(':scope > span');if(span&&/ID|내부/.test(span.textContent))span.textContent=(labels[s.type()]||'대상')+(required?' *':'');
   const hint=label.querySelector(':scope > small');if(hint&&/내부 ID/.test(hint.textContent))hint.remove();
  }
  let selected=[],controller=null,timer=null,ticket=0,active=-1,options=[],page=1;
  const scope=input.form||input.closest('#kgpc_metadata')||document;
  const peer=name=>scope.querySelector('input[name="'+name+'"]');
  const parent=()=>{
    const type=s.type(), params={};let missing='';
    if(['campus','program','intake'].includes(type)&&peer('university_id')){params.university_id=peer('university_id').value;if(!params.university_id)missing='대학';}
    if(type==='program'&&peer('campus_id')){params.campus_id=peer('campus_id').value;if(!params.campus_id)missing=missing||'캠퍼스';}
    if(type==='intake'&&peer('program_id')){const p=mounted.get(peer('program_id'))?.selected()[0];params.university_id=p?.university_id||'';if(!params.university_id)missing='전공';}
    return {params,missing};
  };
  const close=()=>{results.hidden=true;query.setAttribute('aria-expanded','false');query.removeAttribute('aria-activedescendant');active=-1;};
  const validity=()=>query.setCustomValidity((required&&!selected.length)||selected.some(x=>x.unresolved||x.selectable===false)?'검색 결과에서 항목을 선택해 주세요.':'');
  const update=()=>{
    input.value=selected.map(x=>x.id).join(',');picked.replaceChildren();
    selected.forEach(item=>{
      const chip=make('div','kgds-reference-chip'+(item.unresolved||item.selectable===false?' is-unresolved':''));const text=make('span');text.append(make('strong','',item.label),make('small','',item.detail||''));
      const remove=make('button','kgds-reference-remove','×');remove.type='button';remove.setAttribute('aria-label',item.label+' 선택 해제');
      remove.onclick=()=>{selected=selected.filter(x=>x.id!==item.id);update();emit();query.focus();};chip.append(text,remove);picked.append(chip);
    });
    validity();query.placeholder=(labels[s.type()]||'항목')+' 이름으로 검색';query.setAttribute('aria-label',(labels[s.type()]||'항목')+' 검색');
  };
  const emit=()=>{input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));};
  const refreshParents=()=>{const {missing}=parent();query.disabled=!!missing;live.textContent=missing?missing+'을 먼저 선택해 주세요.':'이름을 검색하고 결과를 선택하세요. 연결값은 자동으로 저장됩니다.';};
  const clear=()=>{ticket++;controller?.abort();selected=[];query.value='';update();close();refreshParents();emit();};
  mounted.set(input,{clear,refresh:refreshParents,selected:()=>selected});
  root.kgdsClose=close;
  const select=item=>{if(item.selectable===false)return;ticket++;controller?.abort();if(s.multi){if(!selected.some(x=>x.id===item.id))selected.push(item);}else selected=[item];query.value='';update();close();live.textContent=item.label+' 선택됨';emit();query.focus();};
  const fetchRows=async(params,signal)=>{const url=new URL(conf.url,location.href);Object.entries({type:s.type(),...params}).forEach(([k,v])=>{if(v!==''&&v!=null)url.searchParams.set(k,v);});const res=await fetch(url,{headers:{'X-WP-Nonce':conf.nonce},credentials:'same-origin',signal});const json=await res.json();if(!res.ok)throw Error(json.message||'검색에 실패했습니다.');return json;};
  async function search(next=1){
    const {missing,params}=parent();if(missing)return;
    const current=++ticket;controller?.abort();controller=new AbortController();page=next;live.textContent='검색 중…';
    try{
      const data=await fetchRows({...params,q:query.value.trim(),page},controller.signal);if(current!==ticket||!input.isConnected)return;
      results.replaceChildren();options=[];active=-1;
      for(const item of data.items){const b=make('button','kgds-reference-option');b.type='button';b.id=query.id+'-option-'+item.id;b.setAttribute('role','option');b.setAttribute('aria-selected','false');
        b.append(make('strong','',item.label),make('small','',(item.detail||'')+' · #'+item.id));
        if(item.selectable===false){b.disabled=true;b.setAttribute('aria-disabled','true');}else {b.onclick=()=>select(item);options.push(b);}
        b.onmousedown=e=>e.preventDefault();results.append(b);
      }
      if(!data.items.length)results.append(make('p','kgds-reference-empty','검색 결과가 없습니다. 검색어를 바꾸거나 항목을 먼저 등록해 주세요.'));
      if(page>1||data.has_more){const paging=make('div','kgds-reference-pages');for(const [title,num,show] of [['이전',page-1,page>1],['다음',page+1,data.has_more]])if(show){const b=make('button','',title);b.type='button';b.onclick=()=>search(num);paging.append(b);}results.append(paging);}
      results.hidden=false;query.setAttribute('aria-expanded','true');live.textContent=data.items.length+'개 결과'+(data.has_more?' · 다음 결과 있음':'');
    }catch(e){if(e.name==='AbortError')return;if(current!==ticket)return;close();live.textContent=e.message+' 검색창을 다시 선택하면 재시도합니다.';}
  }
  query.addEventListener('focus',()=>search());query.addEventListener('input',()=>{clearTimeout(timer);ticket++;controller?.abort();close();timer=setTimeout(()=>search(),220);});
  query.addEventListener('keydown',e=>{
    if(e.key==='Escape'){close();e.preventDefault();return;}
    if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();if(results.hidden){search();return;}if(!options.length)return;active=(active+(e.key==='ArrowDown'?1:-1)+options.length)%options.length;options.forEach((b,i)=>b.setAttribute('aria-selected',String(i===active)));query.setAttribute('aria-activedescendant',options[active].id);options[active].scrollIntoView({block:'nearest'});}
    if(e.key==='Enter'){e.preventDefault();if(!results.hidden&&active>=0)options[active].click();else search();}
  });
  root.addEventListener('focusout',()=>setTimeout(()=>{if(!root.contains(document.activeElement))close();},0));

  if(s.dynamic)scope.querySelector('[name="type"]').addEventListener('change',clear);
  const type=s.type();for(const name of (type==='campus'?['university_id']:type==='program'?['university_id','campus_id']:type==='intake'?['university_id','program_id']:[])){peer(name)?.addEventListener('change',clear);}
  input.form?.addEventListener('reset',()=>setTimeout(()=>{selected=[];hydrate();},0));
  async function hydrate(){
    const ids=input.value.split(',').map(x=>x.trim()).filter(Boolean).join(',');if(!ids){update();refreshParents();return;}
    selected=ids.split(',').filter(Boolean).map(id=>({id:Number(id),label:'연결 항목 #'+id,detail:'이름 확인 중…',unresolved:true}));update();
    const current=++ticket;
    try{const data=await fetchRows({ids});if(current!==ticket||!input.isConnected)return;selected=selected.map(x=>data.items.find(y=>y.id===x.id)||{...x,detail:'삭제되었거나 조회 권한이 없습니다. 다시 선택해 주세요.'});update();refreshParents();scope.querySelectorAll('input').forEach(el=>mounted.get(el)?.refresh());}
    catch{live.textContent='연결된 항목을 확인하지 못했습니다. 검색해서 다시 선택해 주세요.';}
  }
  hydrate();refreshParents();
 }
 document.addEventListener('pointerdown',e=>document.querySelectorAll('.kgds-reference').forEach(root=>{if(!root.contains(e.target))root.kgdsClose?.();}));
 function scan(){
  document.querySelectorAll('body.kgds-ui-admin input').forEach(input=>{const s=spec(input);if(s)attach(input,s);});
  document.querySelectorAll('.kgpa-nav,.kgpo-nav').forEach(nav=>{if(nav.dataset.workspace)return;nav.dataset.workspace='1';const parent=nav.parentElement;parent.classList.add('kgds-workspace');nav.setAttribute('aria-label','작업 메뉴');});
 }
 let queued=false;const observe=()=>{scan();new MutationObserver(()=>{if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;scan();});}).observe(document.getElementById('wpbody-content')||document.body,{childList:true,subtree:true});};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',observe,{once:true});else observe();
})();
