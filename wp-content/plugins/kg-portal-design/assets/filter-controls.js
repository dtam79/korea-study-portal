/* KG Portal Design 2.1.2: native filters with keyword-only reset + active-selection summary. */
(() => {
  'use strict';
  const path=location.pathname;
  if(!/^\/(ko|en)\/(universities|admissions)\/?$/.test(path))return;
  const en=path.startsWith('/en/');
  // Selection UI only. The underlying <select> retains its original name and values.
  const configs=[
    {test:/인증\s*대학\s*여부|accredit(?:ed|ation)\s+university|university\s+accreditation|certified\s+university/i,
     title:en?'University accreditation':'인증대학 여부',
     yes:en?'Accredited':'인증', all:en?'View all':'전체 보기'},
    {test:/100\s*%\s*영어\s*학위\s*과정|영어\s*전용\s*(?:학위\s*)?과정|100\s*%\s*english|fully\s*english\s*(?:taught|degree)|english.only.degree/i,
     title:en?'Degree language':'학위 과정 수업 언어',
     yes:en?'100% English degree':'100% 영어 학위', all:en?'View all':'전체 보기'},
  ];
  const normalize=v=>String(v??'').toLowerCase().replace(/\s+/g,' ').trim();
  function kind(option){
    const t=normalize(option.textContent),v=normalize(option.value);
    if(!v||/^(전체|모두|all|any|무관|전체 보기|선택 안 함)$/.test(t)||/^(all|any|none)$/.test(v))return 'all';
    if(/^(예|해당|인증|인증대학|인증됨|있음|yes|true|y|1)$/.test(t)||/^(yes|true|y|1)$/.test(v))return 'yes';
    if(/^(아니오|아니요|비해당|해당 없음|미인증|없음|no|false|n|0)$/.test(t)||/^(no|false|n|0)$/.test(v))return 'no';
    return '';
  }
  function selectFor(label){
    if(label.control?.tagName==='SELECT')return label.control;
    const nested=label.querySelector('select');if(nested)return nested;
    if(label.htmlFor){const linked=document.getElementById(label.htmlFor);if(linked?.tagName==='SELECT')return linked;}
    let next=label.nextElementSibling;
    if(next?.tagName==='SELECT')return next;
    if(next?.querySelectorAll?.('select')?.length===1)return next.querySelector('select');
    const parent=label.parentElement;
    if(parent && parent.tagName!=='FORM' && parent.querySelectorAll('select').length===1)return parent.querySelector('select');
    return null;
  }
  function upgrade(label,config,index){
    const select=selectFor(label);
    if(!select||select.dataset.kgdsBoolean==='1'||select.multiple||select.disabled)return false;
    const values={};
    for(const option of select.options){const k=kind(option);if(k && !Object.hasOwn(values,k)) values[k]=option.value;}
    if(!Object.hasOwn(values,'all')&&select.options[0]?.value==='')values.all='';
    if(!Object.hasOwn(values,'all')||!Object.hasOwn(values,'yes'))return false;
    const box=document.createElement('div');box.className='kgds-bool kgds-bool-segmented';
    const caption=document.createElement('span');caption.className='kgds-bool-title';
    caption.id='kgds-bool-title-'+index;caption.textContent=config.title;
    const choices=document.createElement('div');choices.className='kgds-bool-options';
    choices.setAttribute('role','group');choices.setAttribute('aria-labelledby',caption.id);
    const yes=document.createElement('button');yes.type='button';yes.className='kgds-bool-option';
    yes.textContent=config.yes;yes.setAttribute('aria-pressed','false');
    const all=document.createElement('button');all.type='button';all.className='kgds-bool-option';
    all.textContent=config.all;all.setAttribute('aria-pressed','false');
    choices.append(yes,all);box.append(caption,choices);
    const nativeSet=value=>{
      if(select.value===value)return;
      select.value=value;
      select.dispatchEvent(new Event('input',{bubbles:true}));
      select.dispatchEvent(new Event('change',{bubbles:true}));
    };
    const sync=()=>{
      // A legacy 'no only' state is not representable in this two-choice control.
      if(Object.hasOwn(values,'no') && select.value===values.no)nativeSet(values.all);
      const selectedYes=select.value===values.yes;
      yes.setAttribute('aria-pressed',String(selectedYes));
      all.setAttribute('aria-pressed',String(!selectedYes));
      box.classList.toggle('kgds-bool-active',selectedYes);
    };
    yes.addEventListener('click',()=>{nativeSet(values.yes);sync();});
    all.addEventListener('click',()=>{nativeSet(values.all);sync();});
    select.addEventListener('change',sync);
    select.form?.addEventListener('reset',()=>setTimeout(sync,0));
    window.addEventListener('pageshow',sync);
    (select.closest('label')===label?label:select).insertAdjacentElement('afterend',box);
    select.classList.add('kgds-bool-native-select');
    label.classList.add('kgds-bool-native-label');
    select.dataset.kgdsBoolean='1';
    sync();return true;
  }
  function findForms(main){
    const forms=new Set();
    for(const label of main.querySelectorAll('label')){
      if(configs.some(c=>c.test.test(normalize(label.textContent)))){
        const form=label.closest('form');if(form)forms.add(form);
      }
    }
    for(const el of main.querySelectorAll('details,.kgp-filter-panel,.kgp-filter-container,.kgp-filter-drawer')){
      const label=normalize(el.querySelector('summary')?.textContent||el.getAttribute('aria-label'));
      if(/조건별\s*필터|필터|filter/i.test(label) || el.classList.contains('kgp-filter-panel')){
        const form=el.querySelector('form');if(form)forms.add(form);
      }
    }
    return forms;
  }
  // This UI adapter never rewrites search handlers, REST calls, or native field names.
  // Query-only reset drops ALL filter parameters, retaining just the current keyword.
  const keywordKeys=new Set(['q','s','keyword','search','query','term','search_query','search_keyword','search_term']);
  const keywordInputSelector=[...keywordKeys].map(k=>`input[name="${k}"]`).join(',')+',input[type="search"]';
  const text=v=>String(v??'').replace(/\s+/g,' ').trim();
  function keyword(main){
    const fields=[...main.querySelectorAll(keywordInputSelector)].filter(input=>
      !input.disabled && input.type!=='hidden' && input.name &&
      (keywordKeys.has(input.name.toLowerCase()) || input.type==='search'));
    // Prefer the visible typed query to the previous URL: deleting a keyword must count.
    if(fields.length) return {name:fields[0].name,value:fields[0].value.trim()};
    const query=new URL(location.href).searchParams;
    for(const key of keywordKeys){if(query.has(key))return {name:key,value:query.get(key)?.trim()||''};}
    return null;
  }
  function clearForKeyword(main,form){
    const query=keyword(main);
    // Reflect the reset immediately. The next GET request is made through the
    // original page route with no filter parameters, never a new API contract.
    for(const el of form.querySelectorAll('select,input,textarea')){
      if(el.disabled || (el.name && keywordKeys.has(el.name.toLowerCase())) || el.type==='hidden') continue;
      if(el.tagName==='SELECT'){
        const all=[...el.options].find(o=>kind(o)==='all'||o.value==='') || el.options[0];
        if(all)el.value=all.value;
      }else if(el.type==='checkbox'||el.type==='radio'){el.checked=false;}
      else if(!['button','submit','reset','file'].includes(el.type)){el.value='';}
      el.dispatchEvent(new Event('input',{bubbles:true}));
      el.dispatchEvent(new Event('change',{bubbles:true}));
    }
    updateSummary(form);
    // Search keyword remains the ONLY URL criterion. This also removes page,
    // sort, amount, certification, region, program and unknown legacy filters.
    const url=new URL(location.pathname,location.origin);
    if(query && query.value)url.searchParams.set(query.name,query.value);
    window.location.assign(url.toString());
  }
  function labelFor(form,el){
    const labels=[...form.querySelectorAll('label')];
    return labels.find(label=>label.control===el || label.contains?.(el) ||
      (el.id && label.htmlFor===el.id))||null;
  }
  function caption(label){
    if(!label)return '';
    const own=[...label.childNodes].filter(n=>n.nodeType===3).map(n=>n.textContent).join('');
    return text(own||label.getAttribute('aria-label')||label.textContent).slice(0,40);
  }
  function summaryItems(form){
    const result=[],seen=new Set();
    for(const el of form.querySelectorAll('select,input,textarea')){
      if(el.disabled||el.type==='hidden'||!el.name||keywordKeys.has(el.name.toLowerCase())||seen.has(el.name))continue;
      seen.add(el.name);
      const label=labelFor(form,el);
      let active=false,shown='',name=caption(label)||text(el.getAttribute('aria-label')||el.name);
      if(el.tagName==='SELECT'){
        const chosen=el.options[el.selectedIndex]||[...el.options].find(o=>o.value===el.value);
        if(el.classList.contains('kgds-bool-native-select')){
          active=kind(chosen||{value:el.value,textContent:''})==='yes';
          const config=configs.find(c=>c.test.test(normalize(name)));
          shown=config?.yes||name;
        }else{
          const first=[...el.options].find(o=>kind(o)==='all'||o.value==='') || el.options[0];
          active=!!(chosen&&chosen.value!==first?.value);
          shown=`${name}: ${text(chosen?.textContent||el.value)}`;
        }
      }else if(el.type==='checkbox'||el.type==='radio'){
        active=el.checked;
        shown=name;
      }else if(['number','text','search','date','range','tel'].includes(el.type)||el.tagName==='TEXTAREA'){
        active=!!el.value.trim();
        shown=`${name}: ${text(el.value)}`;
      }
      label?.classList.toggle('kgds-filter-active-label',active);
      el.classList.toggle('kgds-filter-active-control',active);
      if(active)result.push(shown.slice(0,52));
    }
    return result;
  }
  function updateSummary(form){
    const panel=form.closest('details') || form.closest('.kgp-filter-panel,.kgp-filter-container,.kgp-filter-drawer');
    const summary=panel?.querySelector('summary');
    if(!summary)return;
    let status=summary.querySelector('.kgds-filter-status');
    if(!status){
      status=document.createElement('span');status.className='kgds-filter-status';
      status.setAttribute('aria-live','polite');summary.append(status);
    }
    const items=summaryItems(form);
    status.replaceChildren();
    panel.classList.toggle('kgds-has-active-filters',items.length>0);
    if(!items.length){
      const all=document.createElement('span');all.className='kgds-filter-status-idle';
      all.textContent=en?'All conditions':'전체 조건';status.append(all);
      return;
    }
    const count=document.createElement('span');count.className='kgds-filter-status-count';
    count.textContent=en?`${items.length} active`:`${items.length}개 적용`;status.append(count);
    for(const value of items.slice(0,2)){
      const chip=document.createElement('span');chip.className='kgds-filter-active-chip';
      chip.textContent=value;status.append(chip);
    }
    if(items.length>2){
      const more=document.createElement('span');more.className='kgds-filter-active-more';
      more.textContent=`+${items.length-2}`;status.append(more);
    }
  }
  function installReset(main,form){
    if(form.dataset.kgdsClearReady==='1')return;
    form.dataset.kgdsClearReady='1';
    const candidates=[...form.querySelectorAll('button,input[type="reset"]')];
    let reset=candidates.find(button=>button.type==='reset'||
      /(?:필터|전체|조건).*초기화|초기화|clear\s*filters|reset\s*filters|reset/i.test(text(button.textContent||button.value)));
    if(!reset){
      const actions=form.querySelector('.kgp-filter-actions,.kgp-filter-footer,.kgds-filter-actions')||form;
      reset=document.createElement('button');reset.type='button';actions.append(reset);
    }
    reset.classList.add('kgds-filter-clear');
    reset.textContent=en?'Clear filters':'필터 초기화';
    if(reset.tagName==='INPUT')reset.value=en?'Clear filters':'필터 초기화';
    reset.setAttribute('aria-label',en?'Clear filters and search using keywords only':'필터 조건만 초기화하고 검색어로만 검색');
    // Capture prevents a legacy native reset from dropping the keyword.
    reset.addEventListener('click',event=>{
      event.preventDefault();event.stopImmediatePropagation();
      clearForKeyword(main,form);
    },true);
    form.addEventListener('change',()=>updateSummary(form));
    form.addEventListener('input',()=>updateSummary(form));
    form.addEventListener('reset',()=>setTimeout(()=>updateSummary(form),0));
    window.addEventListener('pageshow',()=>updateSummary(form));
    updateSummary(form);
  }
  function run(){
    const main=document.querySelector('.kgp-shell main');if(!main)return;
    let index=0;
    for(const form of findForms(main)){
      form.classList.add('kgds-filter-form');
      for(const label of form.querySelectorAll('label')){
        if(label.classList.contains('kgds-bool-native-label'))continue;
        const name=normalize(label.textContent);
        for(const c of configs)if(c.test.test(name) && upgrade(label,c,index++))break;
      }
      for(const select of form.querySelectorAll('select:not(.kgds-bool-native-select)'))select.classList.add('kgds-filter-select');
      for(const n of form.querySelectorAll('input[type="number"],input[inputmode="numeric"]'))n.classList.add('kgds-filter-number');
      installReset(main,form);
    }
  }
  function boot(){
    run();
    const main=document.querySelector('.kgp-shell main');
    if(!main||!window.MutationObserver)return;
    let pending=false;
    new MutationObserver(mutations=>{
      // AJAX filters may be replaced after initial load. Ignore ordinary result/card updates.
      const filterAdded=mutations.some(m=>[...m.addedNodes].some(node=>
        node.nodeType===1 && (node.matches?.('form,select,label')||node.querySelector?.('form,select,label'))));
      if(!filterAdded||pending)return;
      pending=true;
      requestAnimationFrame(()=>{pending=false;run();});
    }).observe(main,{childList:true,subtree:true});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});
  else boot();
})();
