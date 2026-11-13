/* KG Portal Design System — progressive enhancement only; leaves all input names, values,
 * forms, permission checks, submit handlers, nonces and REST payloads unchanged.
 */
(() => {
  'use strict';
  const names={
    amount:'금액',currency:'통화',unit:'부과 단위',label:'항목명',name:'명칭',title:'제목',
    description:'설명',benefit:'지원 내용',period:'적용 기간',year:'연도',date:'날짜',
    precision:'날짜 정밀도',start:'시작',end:'종료',score:'점수',test:'시험',
    status:'상태',value:'값',url:'공식 URL',official_url:'공식 URL',
    organization:'발행기관',department:'담당 부서',address:'주소',
    condition:'조건',method:'방식',language:'언어',instructions:'안내 내용',
    requirements:'요건',count:'건수',reference_date:'기준일',
    course_type:'과정 유형',applicability:'적용 대상',
  };
  const humanize=(key)=>names[key]||String(key).replaceAll('_',' ').replace(/^./,s=>s.toUpperCase());
  const isObject=x=>x!==null&&typeof x==='object';
  const clone=x=>JSON.parse(JSON.stringify(x));
  const setter=(obj,path,value)=>{
    let current=obj;
    for(let i=0;i<path.length-1;i++)current=current[path[i]];
    current[path[path.length-1]]=value;
  };
  const add=(parent,name,attrs={})=>{
    const node=document.createElement(name);
    for(const [key,value] of Object.entries(attrs)){
      if(key==='class')node.className=value;
      else if(key==='text')node.textContent=value;
      else node.setAttribute(key,String(value));
    }
    parent.append(node);return node;
  };
  function metadataEditor(){
    const container=document.querySelector('#kgpc_metadata .inside') || document.querySelector('.kgds-metadata-editor form');
    if(!container||container.dataset.kgdsMeta==='1')return;
    const paras=[...container.children].filter(el=>el.tagName==='P');
    if(!paras.some(p=>p.querySelector('[name="kgpc_slug"]')))return;
    container.dataset.kgdsMeta='1';
    const intro=add(container,'div',{class:'kgds-meta-help',text:'검토 요청 전 필수 정보를 확인하세요. 검증된 공식 출처와 적용 대상은 원문에서 확인한 값만 입력해야 합니다.'});
    container.insertBefore(intro,paras[0]);
    const specs=[
      {title:'01 · 콘텐츠 유형 및 분류',hint:'가이드·FAQ·정책 유형과 언어를 정합니다.',keys:['kind','lang','topic','category']},
      {title:'02 · 공개 URL 및 적용 대상',hint:'한국어와 영어 콘텐츠는 같은 영문 slug를 사용합니다.',keys:['slug','audience','translation_of']},
      {title:'03 · 공식 근거 및 확인일',hint:'날짜를 추정하지 않고 실제 출처 문서 ID와 위치를 입력합니다.',keys:['basis_date','checked_at','source_ids','source_locator']},
      {title:'04 · 정책 운영 정보',hint:'정책 또는 운영 안내에 해당하는 경우 입력합니다.',keys:['effective_at','revised_at','contact']},
    ];
    const groups=new Map();
    for(const item of specs){
      const section=add(container,'section',{class:'kgds-meta-section'});
      add(section,'h3',{text:item.title});
      add(section,'p',{class:'kgds-section-hint',text:item.hint});
      const grid=add(section,'div',{class:'kgds-meta-grid'});
      for(const key of item.keys)groups.set(key,grid);
    }
    for(const p of paras){
      const keys=[...p.querySelectorAll('input[name^="kgpc_"],select[name^="kgpc_"],textarea[name^="kgpc_"]')].map(e=>e.name.slice(5));
      const selected=keys.map(k=>groups.get(k)).find(Boolean);
      if(selected){
        selected.append(p);
        if(keys.length>1){
          const pairs=[];
          for(const label of p.querySelectorAll('label[for]')){
            const id=label.getAttribute('for');
            const control=id?document.getElementById(id):null;
            if(!control || !p.contains(control))continue;
            const pair=document.createElement('span');pair.className='kgds-field-pair';
            pair.append(label,control);pairs.push(pair);
          }
          if(pairs.length===keys.length)p.replaceChildren(...pairs);
          p.classList.add('kgds-multi-line');
        }
      }
    }
  }
  function consoleLayout(){
    const page=new URLSearchParams(location.search).get('page')||'';
    if(!/^kgpc-(console|review|metadata)$/.test(page))return;
    const wrap=document.querySelector('#wpbody-content > .wrap')||document.querySelector('#wpbody-content .wrap');
    if(!wrap)return;
    wrap.classList.add(page==='kgpc-console'?'kgds-content-console':page==='kgpc-review'?'kgds-review-console':'kgds-metadata-editor');
    for(const p of wrap.querySelectorAll(':scope > p')){
      if(p.querySelector('.button'))p.classList.add('kgds-content-actions');
    }
    // Group the six required guide topics into a recognizable section without losing links.
    const order=wrap.querySelector(':scope > ol');
    if(order&&!order.closest('.kgds-console-card')){
      const head=order.previousElementSibling;
      const box=document.createElement('section');box.className='kgds-console-card';
      if(head&&/^H[2-4]$/.test(head.tagName))head.before(box);
      else order.before(box);
      if(head&&/^H[2-4]$/.test(head.tagName))box.append(head);
      box.append(order);
    }
  }
  function structuredEditor(textarea){
    if(!textarea || textarea.dataset.kgdsStructured==='1' || textarea.disabled || textarea.dataset.json!=='1')return;
    let model;
    try{model=JSON.parse(textarea.value)}catch{return;}
    if(!isObject(model))return;
    const entries=Array.isArray(model)?model.length:Object.keys(model).length;
    if(entries===0)return; // Empty objects require free editing to supply schema keys.
    const container=textarea.parentElement;
    if(!container)return;
    textarea.dataset.kgdsStructured='1';
    const wrapper=document.createElement('section');wrapper.className='kgds-structured-editor';
    add(wrapper,'h4',{class:'kgds-structured-title',text:'구조화된 항목 입력'});
    add(wrapper,'p',{class:'kgds-structured-hint',text:'각 항목을 입력하면 기존 JSON 값에 자동 반영됩니다. 복잡한 구조는 고급 편집을 사용할 수 있습니다.'});
    const list=add(wrapper,'div',{class:'kgds-json-fields'});
    const advanced=add(wrapper,'details',{class:'kgds-advanced-json'});
    add(advanced,'summary',{text:'고급 편집 · JSON 원본 보기'});
    const error=add(advanced,'p',{class:'kgds-json-error',text:'올바른 JSON을 입력해 주세요.'});error.hidden=true;
    textarea.before(wrapper);
    advanced.append(textarea);
    wrapper.append(advanced);

    let count=0;
    const renderFields=(parent,value,path=[],depth=0)=>{
      if(depth>5 || count>80){
        add(parent,'p',{class:'description',text:'깊은 중첩 구조는 아래의 고급 JSON 편집에서 수정할 수 있습니다.'});return;
      }
      Object.entries(value).forEach(([key,v])=>{
        count++;
        const fieldPath=[...path,Array.isArray(value)?Number(key):key];
        if(isObject(v)){
          const group=add(parent,'fieldset',{class:'kgds-json-group'});
          add(group,'legend',{text:Array.isArray(value)?'항목 '+(Number(key)+1):humanize(key)});
          const grid=add(group,'div',{class:'kgds-json-fields'});
          renderFields(grid,v,fieldPath,depth+1);
          return;
        }
        const field=add(parent,'div',{class:'kgds-json-field'+(typeof v==='string'&&v.length>70?' kgds-json-long':'')});
        const id='kgds-json-'+[...fieldPath].map(x=>String(x).replace(/[^a-z0-9]/gi,'-')).join('-');
        add(field,'label',{for:id,text:Array.isArray(value)?'항목 '+(Number(key)+1):humanize(key)});
        let control;
        if(typeof v==='boolean'){
          control=add(field,'select',{id});
          for(const bool of ['true','false']){
            const opt=add(control,'option',{value:bool,text:bool==='true'?'해당':'비해당'});
            opt.selected=String(v)===bool;
          }
        }else if(v===null){
          // Null must remain null: never accidentally convert unknown to zero.
          add(field,'p',{class:'description',text:'공식 확인 전 값 없음 (null) — 고급 편집에서 수정'});return;
        }else{
          const k=String(key).toLowerCase();
          const type=typeof v==='number'?'number':/^(https?:\/\/)/.test(String(v))?'url':/(^date$|_date$|^valid_from$|^valid_to$)/.test(k)&&/^\d{4}-\d{2}-\d{2}$/.test(v)?'date':'text';
          control=add(field,'input',{id,type});
          if(type==='number')control.step='any';
          control.value=String(v);
        }
        const update=()=>{
          const value=typeof v==='boolean'?control.value==='true':typeof v==='number'?Number(control.value):control.value;
          setter(model,fieldPath,value);
          textarea.value=JSON.stringify(model,null,2);
          textarea.dispatchEvent(new Event('input',{bubbles:false}));
        };
        control.addEventListener('input',update);control.addEventListener('change',update);
      });
    };
    const rebuild=()=>{list.replaceChildren();count=0;renderFields(list,model)};
    rebuild();
    textarea.addEventListener('change',()=>{
      try{const parsed=JSON.parse(textarea.value);if(!isObject(parsed))throw Error('expected object');model=parsed;error.hidden=true;rebuild();}
      catch{error.hidden=false;}
    });
    const status=document.getElementById('kgpa-field-datum-status');
    const updateDisabled=()=>{
      const shouldDisable=(status && status.value!=='available') || textarea.disabled;
      list.querySelectorAll('input,select').forEach(input=>input.disabled=shouldDisable);
    };
    status?.addEventListener('change',updateDisabled);
    updateDisabled();
  }
  function readableData(){
    document.querySelectorAll('pre.kgpa-json').forEach(pre=>{
      if(pre.dataset.readable)return;
      let data;try{data=JSON.parse(pre.textContent);}catch{return;}
      if(!data||typeof data!=='object')return;
      pre.dataset.readable='1';const box=add(document.createElement('div'),'div',{class:'kgds-readable-data'});
      const dl=add(box,'dl',{class:'kgds-data-view'});
      const walk=(value,path='',depth=0)=>Object.entries(value).forEach(([key,v])=>{
        const name=path?path+' / '+humanize(key):humanize(key);
        if(v&&typeof v==='object'&&depth<4){walk(v,name,depth+1);return;}
        add(dl,'dt',{text:name});add(dl,'dd',{text:v===null?'확인 전':typeof v==='boolean'?(v?'해당':'비해당'):typeof v==='object'?JSON.stringify(v):String(v)});
      });walk(data);
      const details=add(box,'details',{class:'kgds-advanced-json'});add(details,'summary',{text:'원본 데이터 보기'});
      pre.before(box);details.append(pre);
    });
    const descriptions={
      'kgp-operator':'대학 정보 등록부터 검토·게시까지, 필요한 작업을 선택하세요.',
      'kgp-operator-entities':'대학, 캠퍼스, 전공과 모집 회차를 관리합니다. 연관 항목은 이름으로 찾아 연결하세요.',
      'kgp-operator-sources':'공식 자료를 등록하고 원문을 확인한 뒤 콘텐츠에 연결하세요.',
      'kgp-operator-fields':'대상을 선택하고 정보와 근거를 함께 작성하세요.',
      'kgp-operator-review':'제출된 정보와 공식 원문을 대조한 뒤 승인하거나 수정 사항을 전달하세요.'
    };
    const page=new URLSearchParams(location.search).get('page');const hint=document.querySelector('.kgpa-heading p');
    if(hint&&descriptions[page]&&hint.textContent!==descriptions[page])hint.textContent=descriptions[page];
    document.querySelectorAll('.kgpa-panel > p').forEach(p=>{
      if(p.textContent.startsWith('WordPress 사용자 ID와 대학 내부 ID'))p.textContent='담당자와 대학을 검색해서 선택하세요. 배정된 담당자는 해당 대학의 정보를 수정할 수 있습니다.';
      if(p.textContent.startsWith('내부 ID는 대학·모집 목록'))p.textContent='대상 종류를 고른 뒤 이름으로 검색해 선택하세요.';
    });
  }
  const scan=()=>{
    consoleLayout();metadataEditor();readableData();
    structuredEditor(document.getElementById('kgpa-field-value'));
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',scan,{once:true});
  else scan();
  // The private operator editor is a small SPA: inputs are replaced when a section changes.
  const target=document.getElementById('kgp-operator-root');
  if(target){
    let scheduled=false;
    const observer=new MutationObserver(()=>{
      if(scheduled)return;scheduled=true;
      queueMicrotask(()=>{scheduled=false;scan();});
    });
    observer.observe(target,{childList:true,subtree:true});
  }
})();
