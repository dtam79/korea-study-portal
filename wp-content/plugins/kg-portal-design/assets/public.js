/* Presentation-only progressive enhancements; never mutates API endpoints or data. */
(() => {
  'use strict';
  const run=()=>{
    const shell=document.querySelector('.kgp-shell');
    if(!shell)return;
    const main=shell.querySelector('main');
    if(main)main.classList.add('kgds-page');
    // The admissions filter and no-results message are intentionally compact.
    // Add narrowly scoped classes without replacing native <details> or changing data.
    if(main && /^\/(ko|en)\/admissions\/?$/.test(location.pathname)) {
      const decorate=()=>{
        for(const summary of main.querySelectorAll('details > summary')) {
          const name=(summary.textContent||'').trim().replace(/\s+/g,' ');
          if(/조건별\s*필터|^(?:Filter by conditions|Conditional filters|Filter options|Filters)\b/i.test(name)) {
            const details=summary.parentElement;
            details.classList.add('kgds-filter-compact');
            const wrapper=details.parentElement;
            if(wrapper && wrapper.children.length===1 && wrapper.matches('.kgp-filter-panel,.kgp-filter-container,.kgp-filter-group,.kgp-filter-drawer')) {
              wrapper.classList.add('kgds-filter-wrapper');
            }
          }
        }
        const empty=/해당 조건으로 승인된 결과가 없습니다|조건에 맞는 과정이 없습니다|No approved (?:results|admissions)|No matching (?:admissions|results)/i;
        for(const el of main.querySelectorAll('p,div,section,article')) {
          if(el.classList.contains('kgds-result-empty')) continue;
          const content=(el.textContent||'').trim();
          if(!empty.test(content) || content.length>280 || el.querySelector('form,button,select,input,table,details'))continue;
          if(el.children.length && !(el.children.length===1 && el.firstElementChild.matches('p,span')))continue;
          const target=el.matches('p,span') && el.parentElement && el.parentElement.children.length===1 && el.parentElement!==main
            ?el.parentElement:el;
          target.classList.add('kgds-result-empty');
          break;
        }
      };
      decorate();
      // Only observe child replacement (e.g. AJAX result updates), never attributes.
      if(window.MutationObserver){
        let queued=false;
        new MutationObserver(()=>{
          if(queued)return;queued=true;
          queueMicrotask(()=>{queued=false;decorate();});
        }).observe(main,{childList:true,subtree:true});
      }
    }
    // Ensure an accurate current nav state for templates that omit aria-current.
    const path=location.pathname.replace(/\/+$/,'/') || '/';
    for(const link of shell.querySelectorAll('.kgp-nav a[href]')){
      try{
        const target=new URL(link.href,location.href);
        if(target.origin!==location.origin)continue;
        const targetPath=target.pathname.replace(/\/+$/,'/');
        const active=targetPath===path || (
          targetPath.endsWith('/guides/') && path.startsWith(targetPath)
        );
        if(active) link.setAttribute('aria-current','page');
        else if(link.getAttribute('aria-current')==='page')link.removeAttribute('aria-current');
      }catch{} // Ignore custom or malformed links, do not change their behavior.
    }
    const message=document.getElementById('kgpa-message');
    if(message && !document.getElementById('kgds-message-count')){
      const limit=Number(message.getAttribute('maxlength')) || 2000;
      const counter=document.createElement('p');
      counter.id='kgds-message-count'; counter.className='kgds-character-count';
      counter.setAttribute('aria-live','off');
      const update=()=>{counter.textContent=`${message.value.length.toLocaleString()} / ${limit.toLocaleString()}`};
      message.insertAdjacentElement('afterend',counter);
      message.addEventListener('input',update);update();
      const described=message.getAttribute('aria-describedby')||'';
      message.setAttribute('aria-describedby',(described+' '+counter.id).trim());
    }
    for(const link of shell.querySelectorAll('a[target="_blank"]')){
      const rel=new Set((link.getAttribute('rel')||'').split(/\s+/).filter(Boolean));
      rel.add('noopener');rel.add('noreferrer');
      link.setAttribute('rel',[...rel].join(' '));
    }
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run,{once:true});
  else run();
})();
