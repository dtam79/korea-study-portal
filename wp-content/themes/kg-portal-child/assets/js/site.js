/* Progressive enhancement only: all public search and detail pages work without JavaScript. */
(() => {
  'use strict';
  const form = document.getElementById('kgp-filter-form');
  if (!form) return;
  const min = form.querySelector('[name="tuition_min"]');
  const max = form.querySelector('[name="tuition_max"]');
  const unit = form.querySelector('[name="cost_unit"]');
  const error = document.getElementById('kgp-filter-client-error');
  const sort = form.querySelector('[name="sort"]');
  function valid() {
    if (!min || !max || !unit) return true;
    const lo = min.value.trim();const hi = max.value.trim();
    if ((lo !== '' || hi !== '') && unit.value === '') return false;
    if (lo !== '' && hi !== '' && Number(lo) > Number(hi)) return false;
    if (sort && sort.value === 'tuition') {
      const levels = form.querySelectorAll('[name="level[]"]:checked');
      if (levels.length !== 1 || unit.value === '') return false;
    }
    return true;
  }
  form.addEventListener('submit', (event) => {
    if (valid()) {if (error) error.hidden=true;return;}
    event.preventDefault();
    if (error) {
      error.textContent = document.documentElement.lang === 'ko'
        ? '\uB4F1\uB85D\uAE08 \uB2E8\uC704\uC640 \uAE08\uC561 \uBC94\uC704\uB97C \uD655\uC778\uD558\uACE0, \uB4F1\uB85D\uAE08\uC21C \uC815\uB82C\uC740 \uD558\uB098\uC758 \uD559\uC704 \uACFC\uC815\uC744 \uC120\uD0DD\uD574 \uC8FC\uC138\uC694.'
        : 'Select a tuition unit and valid range. Tuition sorting requires exactly one degree level and a unit.';
      error.hidden = false;
      error.focus?.();
    }
  });
})();
