/* P08: Only the selected university may populate the offering selector. */
(() => {
  'use strict';
  const form=document.getElementById('kgpa-inquiry-form');
  if (!form) return;
  const uni=document.getElementById('kgpa-preferred-uni');
  const load=document.getElementById('kgpa-load-university');
  const offering=document.getElementById('kgpa-offering');
  const status=document.getElementById('kgpa-offerings-status');
  const ko=document.documentElement.lang==='ko';
  const none=ko?'선택하지 않음':'Not selected';
  let generation=0;
  let controller=null;
  function announce(text) {
    if (!status) return;
    status.textContent=text;
    status.hidden=!text;
  }
  function clearOfferings(){
    if (offering) offering.replaceChildren(new Option(none,''));
  }
  async function loadOfferings(){
    if (!uni || !load || !offering) return;
    // Changing university invalidates old selection immediately. An aborted
    // response may still resolve (cache/transport), so also check generation.
    const current=++generation;
    if (controller) controller.abort();
    controller=new AbortController();
    const chosen=uni.value;
    clearOfferings();
    announce('');
    if (!chosen) { load.disabled=false; return; }
    load.disabled=true;
    const oldText=load.textContent;
    load.textContent=ko?'불러오는 중…':'Loading…';
    announce(ko?'확인된 전공 목록을 불러오는 중입니다.':'Loading verified programs.');
    try {
      const url=new URL(load.dataset.endpoint,location.origin);
      url.searchParams.set('university',chosen);
      url.searchParams.set('lang',ko?'ko':'en');
      const response=await fetch(url.toString(),{
        cache:'no-store',headers:{'Accept':'application/json'},credentials:'same-origin',signal:controller.signal,
      });
      if (!response.ok) throw Error('Published program information could not be loaded.');
      const data=await response.json();
      if (current!==generation || uni.value!==chosen) return;
      for (const item of (Array.isArray(data.items)?data.items:[])) {
        offering.add(new Option(String(item.label),String(item.id)));
      }
      announce(offering.options.length===1
        ? (ko?'공개된 전공·모집 회차가 없습니다.':'No published programs are available.')
        : (ko?'전공 목록을 불러왔습니다.':'Program list loaded.'));
    } catch(e) {
      if (current!==generation || uni.value!==chosen) return;
      if (e?.name!=='AbortError') announce(ko?'전공 목록을 불러오지 못했습니다. 다시 시도해 주세요.':'Could not load programs. Please retry.');
    } finally {
      if (current===generation) {
        load.disabled=false;
        load.textContent=oldText;
        controller=null;
      }
    }
  }
  if (load) load.addEventListener('click',loadOfferings);
  if (uni) uni.addEventListener('change',loadOfferings);
  let submitted=false;
  form.addEventListener('submit',event=>{
    if (submitted) {event.preventDefault();return;}
    submitted=true;
    const send=document.getElementById('kgpa-send');
    if (send) {send.disabled=true;send.setAttribute('aria-busy','true');}
  });
})();
