(() => {
  'use strict';
  const form=document.getElementById('kgpa-inquiry-form');
  if (!form) return;
  const uni=document.getElementById('kgpa-preferred-uni');
  const load=document.getElementById('kgpa-load-university');
  const offering=document.getElementById('kgpa-offering');
  let loading=false;
  async function loadOfferings(){
    if (!uni || !load || !offering || loading) return;
    loading=true;load.disabled=true;
    const oldText=load.textContent;
    load.textContent=document.documentElement.lang==='ko'?'불러오는 중…':'Loading…';
    offering.replaceChildren(new Option(document.documentElement.lang==='ko'?'선택하지 않음':'Not selected',''));
    try {
      if (uni.value) {
        const url=new URL(load.dataset.endpoint,location.origin);
        url.searchParams.set('university',uni.value);
        url.searchParams.set('lang',document.documentElement.lang==='en'?'en':'ko');
        const response=await fetch(url.toString(),{cache:'no-store',headers:{'Accept':'application/json'},credentials:'same-origin'});
        if (!response.ok) throw Error('Published program information could not be loaded.');
        const data=await response.json();
        for (const item of (data.items||[])) offering.add(new Option(String(item.label),String(item.id)));
      }
    } catch(e) {
      alert(document.documentElement.lang==='ko'?'공식 확인된 전공 목록을 불러오지 못했습니다.':'Could not load verified programs.');
    } finally {loading=false;load.disabled=false;load.textContent=oldText;}
  }
  if (load) load.addEventListener('click',loadOfferings);
  if (uni) uni.addEventListener('change',loadOfferings);
  let submitted=false;
  form.addEventListener('submit', event => {
    if (submitted) {event.preventDefault();return;}
    submitted=true;
    const send=document.getElementById('kgpa-send');
    if (send) {send.disabled=true;send.setAttribute('aria-busy','true');}
  });
})();
