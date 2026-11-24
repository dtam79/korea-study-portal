/* KG Portal Design 2.1.5: faithful native filter values, applied-condition summary and Reset all. */
(() => {
  'use strict';
  const path=location.pathname;
  if(!/^\/(ko|en)\/(universities|admissions)\/?$/.test(path))return;
  const en=path.startsWith('/en/');
  // Selection UI only. The underlying <select> retains its original name and values.
  const configs=[
    {test:/인증\s*대학\s*여부|accredit(?:ed|ation)\s+university|university\s+accreditation|certified\s+university/i,
     title:en?'University accreditation':'인증대학 여부',
     yes:en?'Accredited':'인증', no:en?'Not accredited':'비해당', all:en?'View all':'전체 보기'},
    {test:/100\s*%\s*영어\s*학위\s*과정|영어\s*전용\s*(?:학위\s*)?과정|100\s*%\s*english|fully\s*english\s*(?:taught|degree)|english.only.degree/i,
     title:en?'Degree language':'학위 과정 수업 언어',
     yes:en?'100% English degree':'100% 영어 학위', no:en?'Not English-only':'비해당', all:en?'View all':'전체 보기'},
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
    const no=Object.hasOwn(values,'no')?document.createElement('button'):null;
    if(no){no.type='button';no.className='kgds-bool-option';no.textContent=config.no;no.setAttribute('aria-pressed','false');}
    const all=document.createElement('button');all.type='button';all.className='kgds-bool-option';
    all.textContent=config.all;all.setAttribute('aria-pressed','false');
    if(no)choices.append(yes,no,all);else choices.append(yes,all);
    box.append(caption,choices);
    const nativeSet=value=>{
      if(select.value===value)return;
      select.value=value;
      select.dispatchEvent(new Event('input',{bubbles:true}));
      select.dispatchEvent(new Event('change',{bubbles:true}));
    };
    const sync=()=>{
      const selectedYes=select.value===values.yes;
      const selectedNo=!!no&&select.value===values.no;
      yes.setAttribute('aria-pressed',String(selectedYes));
      if(no)no.setAttribute('aria-pressed',String(selectedNo));
      all.setAttribute('aria-pressed',String(!selectedYes&&!selectedNo));
      box.classList.toggle('kgds-bool-active',selectedYes||selectedNo);
    };
    yes.addEventListener('click',()=>{nativeSet(values.yes);sync();});
    if(no)no.addEventListener('click',()=>{nativeSet(values.no);sync();});
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
  // UI adapter never changes the submitted query or the native filter data contract.
  // The template's Reset all link is a plain navigation to the default search URL;
  // collapsing the native disclosure never applies or clears pending form values.
  const keywordKeys=new Set(['q','s','keyword','search','query','term','search_query','search_keyword','search_term']);
  const text=v=>String(v??'').replace(/\s+/g,' ').trim();
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
    const controls=[...form.querySelectorAll('select,input,textarea')];
    for(const el of controls){
      if(el.disabled||el.type==='hidden'||!el.name||keywordKeys.has(el.name.toLowerCase())||seen.has(el.name))continue;
      seen.add(el.name);
      const label=labelFor(form,el);
      let active=false,shown='',name=caption(label)||text(el.getAttribute('aria-label')||el.name);
      if(el.tagName==='SELECT'){
        const chosen=el.options[el.selectedIndex]||[...el.options].find(o=>o.value===el.value);
        if(el.classList.contains('kgds-bool-native-select')){
          const choice=kind(chosen||{value:el.value,textContent:''});
          active=choice==='yes'||choice==='no';
          const config=configs.find(c=>c.test.test(normalize(name)));
          shown=choice==='no'?(config?.no||name):(config?.yes||name);
        }else{
          const first=[...el.options].find(o=>kind(o)==='all'||o.value==='') || el.options[0];
          active=!!(chosen&&chosen.value!==first?.value);
          shown=`${name}: ${text(chosen?.textContent||el.value)}`;
        }
      }else if(el.type==='checkbox'||el.type==='radio'){
        // Multiple checkboxes share a name (e.g. region[]). Count the GROUP once,
        // but inspect ALL choices; the first checkbox may be unchecked.
        const group=controls.filter(item=>item.name===el.name && !item.disabled && item.type===el.type);
        const choices=group.filter(item=>item.checked);
        const legend=el.closest?.('fieldset')?.querySelector?.('legend');
        const groupName=text(legend?.textContent||el.name.replace(/\[\]$/,''));
        const selectedNames=choices.map(item=>{
          const childLabel=labelFor(form,item);
          childLabel?.classList.toggle('kgds-filter-active-label',true);
          return caption(childLabel)||text(item.value);
        });
        for(const item of group){
          const itemLabel=labelFor(form,item);
          itemLabel?.classList.toggle('kgds-filter-active-label',item.checked);
          item.classList.toggle('kgds-filter-active-control',item.checked);
        }
        active=choices.length>0;
        shown=groupName+': '+selectedNames.join(', ');
      }else if(['number','text','search','date','range','tel'].includes(el.type)||el.tagName==='TEXTAREA'){
        active=!!el.value.trim();
        shown=`${name}: ${text(el.value)}`;
      }
      if(el.type!=='checkbox'&&el.type!=='radio'){
        label?.classList.toggle('kgds-filter-active-label',active);
        el.classList.toggle('kgds-filter-active-control',active);
      }
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
    // Reuse the server-rendered Reset all anchor, do not inject a second action.
    // Do not navigate or preserve the keyword from this adapter.
    form.addEventListener('change',()=>updateSummary(form));
    form.addEventListener('input',()=>updateSummary(form));
    form.addEventListener('reset',()=>setTimeout(()=>updateSummary(form),0));
    window.addEventListener('pageshow',()=>updateSummary(form));
    updateSummary(form);
  }
  // Keep the final initially visible checkbox row whole instead of clipping a label halfway.
  // This adjusts only the scroll viewport height; field values and the form itself are untouched.
  function fitCompleteRows(grid){
    grid.style.removeProperty('max-height');
    const limit=parseFloat(getComputedStyle(grid).maxHeight);
    if(!Number.isFinite(limit)||grid.scrollHeight<=grid.clientHeight+3)return;
    const top=grid.getBoundingClientRect().top;
    const bottoms=[...grid.children]
      .filter(el=>el.classList.contains('kgp-check'))
      .map(el=>el.getBoundingClientRect().bottom-top+grid.scrollTop);
    const complete=bottoms.filter(y=>y<=limit-12);
    if(!complete.length)return;
    const height=Math.floor(Math.max(...complete)+9);
    if(height<limit-2)grid.style.maxHeight=height+'px';
  }
  // A visual affordance only: all native checkbox fields and their request values stay untouched.
  function refreshScrollHint(grid){
    const hint=grid.nextElementSibling;
    if(!hint?.classList.contains('kgds-filter-scroll-note'))return;
    const overflow=grid.scrollHeight>grid.clientHeight+3;
    const below=overflow && grid.scrollTop+grid.clientHeight<grid.scrollHeight-3;
    hint.hidden=!below;
    if(overflow)grid.setAttribute('aria-describedby',hint.id);
    else grid.removeAttribute('aria-describedby');
  }
  function prepareScrollHints(form){
    let idx=0;
    for(const grid of form.querySelectorAll('.kgp-chip-grid')){
      if(grid.dataset.kgdsScrollReady==='1')continue;
      grid.dataset.kgdsScrollReady='1';
      const hint=document.createElement('p');
      hint.className='kgds-filter-scroll-note';
      hint.id='kgds-scroll-note-'+(++idx)+'-'+(form.id||'filter');
      hint.textContent=en?'Scroll down for more options':'아래로 스크롤하여 더 보기';
      hint.hidden=true;
      grid.insertAdjacentElement('afterend',hint);
      grid.addEventListener('scroll',()=>refreshScrollHint(grid),{passive:true});
      requestAnimationFrame(()=>{fitCompleteRows(grid);refreshScrollHint(grid);});
    }
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
      prepareScrollHints(form);
    }
  }
  function boot(){
    run();
    window.addEventListener('resize',()=>{
      for(const grid of document.querySelectorAll('.kgds-filter-form .kgp-chip-grid')){fitCompleteRows(grid);refreshScrollHint(grid);}
    });
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
