/* KG Portal: browser-language display translation. No form values, free-text queries or private text go to Gemini. */
(function (host) {
  'use strict';
  const OMIT = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'SVG', 'MATH', 'CODE', 'PRE', 'TEXTAREA', 'SELECT', 'OPTION', 'IFRAME']);
  function norm(text) { return String(text || '').replace(/[\s\u00a0\u2000-\u200b\u202f\u3000]+/g, ' ').trim(); }
  function locale(raw, supported) {
    const tag = String(raw || '').replace(/_/g, '-').toLowerCase();
    if (!tag) return null;
    if (tag === 'zh-hant' || /^zh-(tw|hk|mo)(-|$)/.test(tag)) return supported['zh-TW'] ? 'zh-TW' : null;
    if (tag === 'zh' || tag === 'zh-hans' || /^zh-(cn|sg)(-|$)/.test(tag)) return supported['zh-CN'] ? 'zh-CN' : null;
    const base = tag.split('-')[0];
    return Object.keys(supported).find(x => x.toLowerCase() === tag) || (supported[base] ? base : null);
  }
  function preferred(config, nav, store) {
    let setting = null;
    try { setting = store && store.getItem('kgpt-language'); } catch (e) { /* storage disabled */ }
    if (setting && setting !== 'auto') {
      const manual = locale(setting, config.languages);
      if (manual) return { language: manual, setting };
    }
    const browser = nav && Array.isArray(nav.languages) && nav.languages.length ? nav.languages[0] : (nav && nav.language);
    return { language: locale(browser, config.languages) || 'en', setting: 'auto' };
  }
  function isSafe(node) {
    let element = node.nodeType === 3 ? node.parentElement : node;
    while (element) {
      if (OMIT.has(element.tagName)) return false;
      if (element.hasAttribute && (element.hasAttribute('data-kgpt-ignore') || element.hasAttribute('hidden') || element.hasAttribute('contenteditable'))) return false;
      if (element.getAttribute && element.getAttribute('aria-hidden') === 'true') return false;
      if (element.classList && Array.from(element.classList).some(x => x.startsWith('kgpt-'))) return false;
      element = element.parentElement;
    }
    return true;
  }
  function eligible(text) {
    const value = norm(text);
    return value.length >= 2 && value.length <= 1100 && /\p{L}/u.test(value)
      && !/[\d@]/u.test(value) && !/https?:\/\/|www\./i.test(value);
  }
  function findNodes(root) {
    const doc = root.ownerDocument;
    const tw = doc.createTreeWalker(root, (host.NodeFilter || doc.defaultView.NodeFilter).SHOW_TEXT);
    const list = [];
    for (let node = tw.nextNode(); node; node = tw.nextNode()) {
      if (isSafe(node) && eligible(node.nodeValue)) list.push({ node, raw: node.nodeValue, key: norm(node.nodeValue) });
    }
    return list;
  }
  function findAttrs(root) {
    const items = [];
    root.querySelectorAll('[placeholder], [aria-label], [title]').forEach(el => {
      if (!isSafe(el)) return;
      ['placeholder', 'aria-label', 'title'].forEach(name => {
        if (el.hasAttribute(name)) {
          const raw = el.getAttribute(name);
          if (eligible(raw)) items.push({ el, name, raw, key: norm(raw) });
        }
      });
    });
    return items;
  }
  function applyTranslation(nodes, attrs, map) {
    nodes.forEach(item => {
      const translated = map[item.key];
      if (typeof translated !== 'string' || !norm(translated) || translated === item.key) return;
      const leading = item.raw.match(/^\s*/u)[0];
      const trailing = item.raw.match(/\s*$/u)[0];
      item.node.nodeValue = leading + translated + trailing;
    });
    attrs.forEach(item => {
      const translated = map[item.key];
      if (typeof translated === 'string' && norm(translated)) item.el.setAttribute(item.name, translated);
    });
  }
  function restore(nodes, attrs) {
    nodes.forEach(item => { item.node.nodeValue = item.raw; });
    attrs.forEach(item => { item.el.setAttribute(item.name, item.raw); });
  }
  function findLink(lang) {
    const group = host.document.querySelector('.kgp-language');
    if (!group) return null;
    const links = group.querySelectorAll('a[href]');
    for (const link of links) {
      try {
        if (new URL(link.href, host.location.href).pathname.match(new RegExp('/' + lang + '(/|$)'))) return link;
      } catch (e) { /* ignore malformed URL */ }
    }
    return null;
  }
  function targetPage(language, config) {
    const base = language === 'ko' ? 'ko' : 'en';
    if (base === config.source) return false;
    const link = findLink(base);
    if (!link) return false;
    const dest = new URL(link.href, host.location.href);
    if (dest.origin !== host.location.origin || dest.href === host.location.href) return false;
    host.location.assign(dest.href);
    return true;
  }
  function control(config, initial) {
    const group = host.document.querySelector('.kgp-language');
    const main = host.document.querySelector('main');
    if (!group) return null;
    const label = host.document.createElement('label');
    label.className = 'kgpt-control';
    label.setAttribute('data-kgpt-ignore', '');
    const caption = host.document.createElement('span');
    caption.className = 'kgpt-control-label';
    caption.textContent = 'Language / 언어';
    const select = host.document.createElement('select');
    select.setAttribute('aria-label', 'Display language / 표시 언어');
    select.className = 'kgpt-select';
    const make = (value, name) => {
      const el = host.document.createElement('option');
      el.value = value; el.textContent = name; select.appendChild(el);
    };
    make('auto', 'Auto (browser) / 자동');
    Object.entries(config.languages).forEach(([code, name]) => make(code, name));
    select.value = initial.setting === 'auto' ? 'auto' : initial.language;
    label.append(caption, select);
    group.appendChild(label);
    const notice = host.document.createElement('p');
    notice.className = 'kgpt-notice';
    notice.setAttribute('data-kgpt-ignore', '');
    notice.setAttribute('role', 'status');
    notice.hidden = true;
    if (main) main.prepend(notice);
    group.querySelectorAll('a[href]').forEach(a => {
      a.addEventListener('click', () => {
        const l = new URL(a.href, host.location.href).pathname.match(/\/(ko|en)(?:\/|$)/);
        if (l) try { const s = host.localStorage; if (s) s.setItem('kgpt-language', l[1]); } catch (e) { /* disabled */ }
      });
    });
    return { select, notice };
  }
  async function start() {
    const json = host.document.getElementById('kgpt-config');
    const parsed = json ? JSON.parse(json.textContent) : null;
    const config = parsed || { source: host.document.documentElement.lang === 'ko' ? 'ko' : 'en',
      enabled: false, manifest: '', count: 0, languages: {ko:'한국어',en:'English'} };
    if (!config.languages || !config.source) return;
    let storage = null;
    try { storage = host.localStorage; } catch (e) { /* private browsing */ }
    const chosen = preferred(config, host.navigator, storage);
    const ui = control(config, chosen);
    if (!ui) return;
    const root = host.document.querySelector('.kgp-shell');
    if (!root) return;
    const nodes = findNodes(root), attrs = findAttrs(root);
    let generation = 0;
    const setNotice = (message) => { ui.notice.hidden = !message; ui.notice.textContent = message || ''; };
    const run = async (lang) => {
      generation += 1;
      const sequence = generation;
      restore(nodes, attrs);
      setNotice('');
      host.document.documentElement.lang = config.source;
      host.document.documentElement.dir = 'ltr';
      if (targetPage(lang, config)) return;
      if (lang === 'ko' || lang === 'en') return;
      if (!config.enabled || !config.manifest || !config.count) {
        setNotice('Automatic translation unavailable. Showing approved Korean / English text.');
        return;
      }
      setNotice('Translating… / 자동 번역 중…');
      const translations = Object.create(null);
      const getPart = async (part) => {
        const response = await host.fetch(config.endpoint, {
          method: 'POST', credentials: 'omit',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ manifest: config.manifest, chunk: part, language: lang }),
        });
        if (!response.ok) throw new Error('translation-unavailable');
        const data = await response.json();
        if (!data || !data.translations || data.chunk !== part || !Number.isInteger(data.total) || data.total < 1 || data.total > 16) throw new Error('translation-response');
        return data;
      };
      try {
        // First response supplies total chunk count; all subsequent requests are bounded.
        const first = await getPart(0);
        Object.assign(translations, first.translations);
        if (generation !== sequence) return;
        applyTranslation(nodes, attrs, translations);
        for (let i = 1; i < first.total; i += 2) {
          const indexes = [i, i + 1].filter(x => x < first.total);
          const results = await Promise.all(indexes.map(getPart));
          if (generation !== sequence) return;
          results.forEach(result => Object.assign(translations, result.translations));
          applyTranslation(nodes, attrs, translations);
        }
        if (generation !== sequence) return;
        host.document.documentElement.lang = lang;
        if (lang === 'ar' || lang === 'fa' || lang === 'ur') host.document.documentElement.dir = 'rtl';
        setNotice(translations['Machine translation. Check important dates, fees and requirements against the linked official sources.'] ||
          'Machine translation. Check important dates, fees and requirements against the linked official sources.');
      } catch (e) {
        if (generation !== sequence) return;
        restore(nodes, attrs);
        host.document.documentElement.lang = config.source;
        host.document.documentElement.dir = 'ltr';
        setNotice('Automatic translation unavailable. Showing approved Korean / English text.');
      }
    };
    ui.select.addEventListener('change', () => {
      const v = ui.select.value;
      try { if (storage) storage.setItem('kgpt-language', v); } catch (e) { /* storage disabled */ }
      const language = v === 'auto' ? preferred(config, host.navigator, { getItem: () => null }).language : v;
      run(language);
    });
    run(chosen.language);
  }
  const api = { norm, locale, preferred, eligible, isSafe };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (host && host.document && typeof host.addEventListener === 'function') {
    const boot = () => { start().catch(() => { /* render original approved text on unexpected failure */ }); };
    if (host.document.readyState === 'loading') host.document.addEventListener('DOMContentLoaded', boot, {once:true});
    else boot();
  }
})(typeof window === 'undefined' ? null : window);
