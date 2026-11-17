/* A03-only operator UI enhancement. Core relationship, permission and publication rules stay authoritative. */
(function () {
  'use strict';

  // Pure helpers are exported for no-network Node contract tests.
  const positiveId = value => /^\d+$/.test(String(value ?? '').trim()) && Number.isSafeInteger(Number(value)) && Number(value) > 0;
  const safeId = (value, label) => {
    if (!positiveId(value)) throw new Error(label + '을(를) 검색 결과에서 선택해 주세요.');
    return Number(value);
  };
  const copyIntakeFields = row => ({
    public_id: '',                       // Must be a new unique identifier.
    university_id: positiveId(row?.university_id) ? String(row.university_id) : '',
    academic_year: '',                   // Must be verified for the new round.
    academic_term: String(row?.academic_term ?? ''),
    admission_type: String(row?.admission_type ?? ''),
    start_date: '',                      // Never inherit dates/time precision.
    end_date: '',
    application_url: '',                 // Official application link requires rechecking.
  });
  async function checkRelations(type, values, resolve) {
    if (type === 'program') {
      const uni = safeId(values.university_id, '대학');
      const campusId = safeId(values.campus_id, '캠퍼스');
      const campus = await resolve('campus', campusId);
      if (!campus || Number(campus.university_id) !== uni) {
        throw new Error('선택한 캠퍼스가 해당 대학에 속하지 않습니다. 캠퍼스를 다시 선택해 주세요.');
      }
    } else if (type === 'offering') {
      const programId = safeId(values.program_id, '전공');
      const intakeId = safeId(values.intake_id, '모집 회차');
      const [program, intake] = await Promise.all([resolve('program', programId), resolve('intake', intakeId)]);
      if (!program || !intake || !positiveId(program.university_id)
          || Number(program.university_id) !== Number(intake.university_id)) {
        throw new Error('전공과 모집 회차가 같은 대학에 속해야 합니다. 두 항목을 다시 선택해 주세요.');
      }
    } else if (type === 'intake') {
      safeId(values.university_id, '대학');
      if (!/^\d{4}$/.test(String(values.academic_year ?? ''))
          || Number(values.academic_year) < 1900 || Number(values.academic_year) > 2200) {
        throw new Error('새 모집 회차의 학년도를 원문 기준으로 다시 확인해 입력해 주세요.');
      }
      if (values.start_date && values.end_date && values.start_date > values.end_date) {
        throw new Error('접수 시작일은 종료일보다 늦을 수 없습니다.');
      }
    }
    return true;
  }

  if (typeof module !== 'undefined' && module.exports) module.exports = {positiveId, copyIntakeFields, checkRelations};
  if (typeof window === 'undefined' || typeof document === 'undefined') return;

  const admin = window.KGP_ADMIN;
  const lookup = window.KGDS_LOOKUP;
  if (!admin || admin.page !== 'entities' || !lookup || !admin.permissions?.edit) return;
  const root = document.getElementById('kgp-operator-root');
  if (!root) return;

  const valuesFor = form => {
    const values = {};
    for (const name of ['university_id', 'campus_id', 'program_id', 'intake_id', 'academic_year', 'start_date', 'end_date']) {
      values[name] = String(form.elements.namedItem(name)?.value ?? '').trim();
    }
    return values;
  };
  const currentType = () => document.getElementById('kgpa-entity-type')?.value ?? '';
  const api = async (url) => {
    const response = await fetch(url, {
      method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
      headers: { 'X-WP-Nonce': admin.nonce, 'Accept': 'application/json' }
    });
    let payload;
    try { payload = await response.json(); } catch { throw new Error('관계 확인 응답을 읽지 못했습니다.'); }
    if (!response.ok) throw new Error(response.status === 401 || response.status === 403
      ? '조회 권한 또는 로그인 상태를 확인해 주세요.'
      : String(payload?.message || '관계 확인을 완료하지 못했습니다.'));
    return payload;
  };
  const resolve = async (type, id) => {
    const u = new URL(lookup.url, location.href);
    u.searchParams.set('type', type);
    u.searchParams.set('ids', String(safeId(id, '관계 ID')));
    const data = await api(u.href);
    const item = Array.isArray(data.items) ? data.items.find(x => Number(x.id) === id) : null;
    return item && item.selectable !== false ? item : null;
  };
  const getNotice = (form) => {
    let notice = form.querySelector('.kgds-a03-validation');
    if (!notice) {
      notice = document.createElement('p');
      notice.className = 'kgds-a03-validation';
      notice.setAttribute('role', 'alert');
      notice.tabIndex = -1;
      notice.hidden = true;
      form.prepend(notice);
    }
    return notice;
  };
  const pending = new WeakSet();
  const approved = new WeakMap();
  // The Admin plugin owns the real POST. This capture listener only preflights relations,
  // then replays the native form submit so existing nonce, revision and response handlers run.
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.id !== 'kgpa-entity-create-form' || !root.contains(form)) return;
    const type = currentType();
    if (!['program', 'offering', 'intake'].includes(type)) return;
    const values = valuesFor(form);
    const signature = JSON.stringify([type, values]);
    if (approved.get(form) === signature) { approved.delete(form); return; }
    event.preventDefault();
    event.stopPropagation();
    if (pending.has(form)) return;
    pending.add(form);
    const submitter = form.querySelector('button[type="submit"]');
    const notice = getNotice(form);
    if (submitter) submitter.disabled = true;
    notice.hidden = false;
    notice.classList.remove('is-error');
    notice.textContent = '입력한 관계와 모집 회차를 확인하는 중입니다…';
    try {
      await checkRelations(type, values, resolve);
      if (!form.isConnected || currentType() !== type || JSON.stringify([type, valuesFor(form)]) !== signature) {
        throw new Error('확인 도중 입력값이 변경되었습니다. 다시 저장해 주세요.');
      }
      notice.textContent = '관계 확인 완료. 기존 저장 흐름으로 진행합니다.';
      approved.set(form, signature);
    } catch (error) {
      notice.textContent = String(error?.message || error);
      notice.classList.add('is-error');
      approved.delete(form);
      notice.focus?.();
    } finally {
      pending.delete(form);
      if (submitter) submitter.disabled = false;
    }
    if (approved.get(form) === signature) form.requestSubmit();
  }, true);

  function inputField(grid, key, label, type, value, required) {
    const wrapper = document.createElement('label');
    wrapper.className = 'kgpa-field';
    const title = document.createElement('span');
    title.textContent = label + (required ? ' *' : '');
    const input = document.createElement('input');
    input.name = key;
    input.type = type;
    input.required = required;
    if (type === 'number') { input.min = '1'; input.step = '1'; }
    input.value = value;
    wrapper.append(title, input);
    grid.append(wrapper);
    return input;
  }
  function renderCopy(row, anchor) {
    const old = document.getElementById('kgds-a03-copy');
    if (old) old.remove();
    const fields = copyIntakeFields(row);
    const section = document.createElement('section');
    section.id = 'kgds-a03-copy';
    section.className = 'kgpa-panel kgds-a03-copy';
    const heading = document.createElement('h3');
    heading.textContent = '모집 회차 복제 — 새 내부 초안';
    const intro = document.createElement('p');
    intro.textContent = '기존 회차의 대학·학기·전형 구분만 참고합니다. 고유 ID, 학년도, 일정, 공식 지원 URL 및 출처를 반드시 다시 확인하세요. 승인 상태와 출처 연결은 복제하지 않습니다.';
    section.append(heading, intro);
    const form = document.createElement('form');
    form.id = 'kgpa-entity-create-form';    // Reuse the Admin plugin's existing create handler.
    form.dataset.kgdsCopy = '1';
    const grid = document.createElement('div');
    grid.className = 'kgpa-form-grid';
    [
      ['public_id','새 고유 ID','text',true],
      ['university_id','대학','number',true],
      ['academic_year','새 모집 학년도','number',true],
      ['academic_term','학기 코드','text',true],
      ['admission_type','전형 구분','text',true],
      ['start_date','접수 시작일(공식 원문 확인)','date',false],
      ['end_date','접수 종료일(공식 원문 확인)','date',false],
      ['application_url','공식 지원 URL(원문 확인)','url',false]
    ].forEach(([key,label,type,required]) => inputField(grid,key,label,type,fields[key],required));
    form.append(grid);
    const acknowledgement = document.createElement('label');
    acknowledgement.className = 'kgds-a03-check';
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox'; checkbox.required = true;
    acknowledgement.append(checkbox, document.createTextNode('새 학년도·일정·공식 출처를 재확인해야 하며, 이 작업은 자동 게시나 승인 복사가 아님을 확인했습니다.'));
    form.append(acknowledgement);
    const actions = document.createElement('div');
    actions.className = 'kgpa-actions';
    const submit = document.createElement('button'); submit.type='submit'; submit.className='button button-primary'; submit.textContent='새 내부 초안 등록';
    const cancel = document.createElement('button'); cancel.type='button'; cancel.className='button'; cancel.textContent='취소';
    cancel.addEventListener('click', () => { section.remove(); anchor.focus(); });
    actions.append(submit,cancel);form.append(actions);section.append(form);
    anchor.closest('#kgpa-entity-current')?.after(section);
    section.scrollIntoView({block:'nearest'});
    form.querySelector('[name="public_id"]')?.focus();
  }
  function attachCopyButton() {
    if (currentType() !== 'intake') return;
    const updateForm = root.querySelector('#kgpa-entity-update-form');
    if (!updateForm || updateForm.dataset.kgdsCopyReady) return;
    updateForm.dataset.kgdsCopyReady='1';
    const button = document.createElement('button');
    button.type = 'button';
    button.className='button kgds-a03-copy-button';
    button.textContent='이 모집 회차 복제';
    button.setAttribute('aria-label','이 모집 회차를 새 ID의 초안으로 복제');
    updateForm.append(button);
    button.addEventListener('click', async () => {
      const id = updateForm.dataset.id;
      if (!positiveId(id)) return;
      button.disabled=true;
      try {
        const url = new URL(admin.apiRoot + 'admin/entities/intake/' + encodeURIComponent(id), location.href);
        const row = await api(url.href);
        if (!updateForm.isConnected || updateForm.dataset.id !== id || currentType() !== 'intake') return;
        renderCopy(row,button);
      } catch (e) {
        const notice = getNotice(updateForm);
        notice.hidden=false;notice.classList.add('is-error');
        notice.textContent='기존 모집 회차를 불러오지 못했습니다: '+String(e?.message || e);
      } finally { button.disabled=false; }
    });
  }
  let scanQueued=false;
  const scan = () => {scanQueued=false;attachCopyButton();};
  const observer = new MutationObserver(() => {
    if (scanQueued) return;
    scanQueued=true;
    requestAnimationFrame(scan);
  });
  observer.observe(root,{childList:true,subtree:true});
  scan();
})();
