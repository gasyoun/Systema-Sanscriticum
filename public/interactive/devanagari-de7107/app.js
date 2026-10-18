/* ============================================================
   ПРИЛОЖЕНИЕ КУРСА «ДЕВАНАГАРИ С НУЛЯ»
   Без внешних зависимостей. Прогресс — localStorage + SCORM.
   ============================================================ */
(function () {
'use strict';

/* ---------- утилиты ---------- */
function $(sel, root) { return (root || document).querySelector(sel); }
function el(tag, cls, html) {
  var e = document.createElement(tag);
  if (cls) e.className = cls;
  if (html !== undefined) e.innerHTML = html;
  return e;
}
function shuffle(a) {
  a = a.slice();
  for (var i = a.length - 1; i > 0; i--) {
    var j = Math.floor(Math.random() * (i + 1));
    var t = a[i]; a[i] = a[j]; a[j] = t;
  }
  return a;
}
function pick(a) { return a[Math.floor(Math.random() * a.length)]; }
function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/* ---------- данные: доступ ---------- */
function vow(id) { return VOWELS.filter(function (v) { return v.id === id; })[0]; }
function cons(id) { return CONSONANTS.filter(function (c) { return c.id === id; })[0]; }
function sign(id) { return SIGNS.filter(function (s) { return s.id === id; })[0]; }

var VARGA_NAMES = {
  ka: 'ка-варга (заднеязычные)', ca: 'ча-варга (палатальные)',
  Ta: 'ṭa-варга (церебральные)', ta: 'та-варга (зубные)', pa: 'па-варга (губные)',
  antahstha: 'антахстха («стоящие между»)', ushman: 'ушман («жаркие»)'
};
var COL_NAMES = ['глухой', 'глухой придыхат.', 'звонкий', 'звонкий придыхат.', 'носовой'];

/* элемент набора -> {dev, iast, ru, obj} */
function resolve(item) {
  if (item.kind === 'cons') { var c = cons(item.id); return { dev: c.dev, iast: c.iast, ru: c.ru, obj: c, type: 'cons' }; }
  if (item.kind === 'vow') { var v = vow(item.id); return { dev: v.dev, iast: v.iast, ru: v.ru, obj: v, type: 'vow' }; }
  if (item.kind === 'sign') { var s = sign(item.id); return { dev: s.dev, iast: s.iast, ru: s.name, obj: s, type: 'sign' }; }
  if (item.kind === 'lig') return { dev: item.dev, iast: item.iast, ru: '', type: 'lig' };
  if (item.kind === 'num') return { dev: item.dev, iast: item.iast, ru: '', type: 'num' };
  return null;
}
function setItems(setId) {
  var s = SETS[setId] || [];
  return s.map(resolve).filter(Boolean);
}
/* случайный слог согласная+гласная */
function randomSyllable(consIds, vowIds) {
  var c = cons(pick(consIds)), v = vow(pick(vowIds));
  var iast = c.iast.replace(/a$/, '') + v.iast;
  return { dev: c.dev + v.matra, iast: iast, c: c, v: v };
}

/* ---------- озвучка ---------- */
var hiVoice = null, speechOK = ('speechSynthesis' in window);
function detectVoice() {
  if (!speechOK) return;
  var vs = speechSynthesis.getVoices();
  hiVoice = null;
  for (var i = 0; i < vs.length; i++) {
    if (/^hi/i.test(vs[i].lang)) { hiVoice = vs[i]; break; }
  }
  if (!hiVoice) for (var j = 0; j < vs.length; j++) if (/hi|sa/i.test(vs[j].name)) hiVoice = vs[j];
}
if (speechOK) { detectVoice(); speechSynthesis.onvoiceschanged = detectVoice; }
function speak(text) {
  if (!speechOK || !hiVoice) return false;
  try {
    speechSynthesis.cancel();
    var u = new SpeechSynthesisUtterance(text);
    u.voice = hiVoice; u.lang = hiVoice.lang; u.rate = 0.85;
    speechSynthesis.speak(u);
    return true;
  } catch (e) { return false; }
}
function speakAvailable() { return speechOK && !!hiVoice; }

/* ---------- состояние и прогресс ---------- */
var LS_KEY = 'devanagari-course-v1';
var state = { modules: {}, scores: {}, hw: {}, final: null };
function loadState() {
  try {
    var raw = localStorage.getItem(LS_KEY);
    if (raw) { var p = JSON.parse(raw); if (p && p.modules) state = p; }
  } catch (e) {}
  /* подмешиваем SCORM suspend_data, если локально пусто */
  if (!localStorage.getItem(LS_KEY) && SCORM.available()) {
    try {
      var s = SCORM.getSuspend();
      if (s) { var q = JSON.parse(s); if (q && q.modules) state = q; }
    } catch (e) {}
  }
}
function saveState() {
  try { localStorage.setItem(LS_KEY, JSON.stringify(state)); } catch (e) {}
  if (SCORM.available()) {
    SCORM.setSuspend(JSON.stringify(state));
    SCORM.setLocation(location.hash || '#/');
  }
}
function modState(id) {
  if (!state.modules[id]) state.modules[id] = { done: false };
  return state.modules[id];
}
function moduleDone(id) { return state.modules[id] && state.modules[id].done; }
function progressPercent() {
  var done = MODULES.filter(function (m) { return moduleDone(m.id); }).length;
  var p = Math.round(100 * done / MODULES.length);
  if (state.final && state.final.passed) p = 100;
  return p;
}
function saveScore(exId, pct) {
  if (!state.scores[exId] || pct > state.scores[exId]) state.scores[exId] = pct;
  saveState();
}

/* ---------- роутер ---------- */
var main, sidebar;
function route() {
  var h = location.hash || '#/';
  var parts = h.replace(/^#\//, '').split('/');
  sidebar.classList.remove('open');
  renderSidebar(h);
  window.scrollTo(0, 0);
  if (parts[0] === 'm') renderModule(parseInt(parts[1], 10));
  else if (parts[0] === 'ref') renderRef(parts[1]);
  else renderHome();
  updatePill();
}
window.addEventListener('hashchange', route);

/* ---------- сайдбар ---------- */
function renderSidebar(current) {
  sidebar.innerHTML = '';
  sidebar.appendChild(el('h1', null, 'Деванагари<br>с нуля'));
  sidebar.appendChild(el('p', 'sub', 'интерактивный курс письменности санскрита'));
  sidebar.appendChild(el('div', 'navsec', 'Модули'));
  MODULES.forEach(function (m) {
    var a = el('a', 'navitem' + (current === '#/m/' + m.id ? ' active' : ''),
      '<span class="num">' + m.id + '</span><span>' + esc(m.title) + '</span>' +
      (moduleDone(m.id) ? '<span class="done">✓</span>' : ''));
    a.href = '#/m/' + m.id;
    sidebar.appendChild(a);
  });
  sidebar.appendChild(el('div', 'navsec', 'Справочник'));
  REFS.forEach(function (r) {
    var a = el('a', 'navitem' + (current === '#/ref/' + r.id ? ' active' : ''), '<span>' + esc(r.title) + '</span>');
    a.href = '#/ref/' + r.id;
    sidebar.appendChild(a);
  });
}
function updatePill() {
  $('#progress-pill').textContent = 'Прогресс: ' + progressPercent() + '%';
}

/* ---------- главная ---------- */
function renderHome() {
  $('#crumbs').textContent = 'Обзор курса';
  main.innerHTML = '';
  main.appendChild(el('h2', 'modtitle', 'Деванагари с нуля'));
  main.appendChild(el('p', 'lede',
    'Интерактивный курс по материалам первых занятий живых групп «Грамматика санскрита с нуля» (Общество ревнителей санскрита). Восемь модулей — от первого квадрата согласных до чтения связного текста из Махабхараты.'));
  var pb = el('div', 'progressbar', '<div style="width:' + progressPercent() + '%"></div>');
  main.appendChild(pb);
  main.appendChild(el('p', 'lede', 'Пройдено модулей: ' + MODULES.filter(function (m) { return moduleDone(m.id); }).length + ' из ' + MODULES.length +
    (state.final ? ' · итоговый зачёт: ' + state.final.best + '%' + (state.final.passed ? ' (сдан)' : '') : '')));
  var cards = el('div', 'dashcards');
  MODULES.forEach(function (m) {
    var c = el('div', 'dashcard',
      '<h4>' + m.id + '. ' + esc(m.title) + '</h4>' +
      '<div class="meta">' + esc(m.lede) + '</div>');
    c.onclick = function () { location.hash = '#/m/' + m.id; };
    cards.appendChild(c);
  });
  main.appendChild(cards);
  var row = el('div', 'btnrow');
  var start = el('button', 'btn', MODULES.every(function (m) { return moduleDone(m.id); }) ? 'Повторить курс' : 'Начать с модуля 0');
  start.onclick = function () { location.hash = '#/m/0'; };
  row.appendChild(start);
  if (state.final) {
    var fin = el('button', 'btn ghost', 'К итоговому зачёту');
    fin.onclick = function () { location.hash = '#/m/7'; };
    row.appendChild(fin);
  }
  var reset = el('button', 'btn ghost', 'Сбросить прогресс');
  reset.onclick = function () {
    if (confirm('Сбросить весь прогресс курса?')) {
      state = { modules: {}, scores: {}, hw: {}, final: null };
      saveState(); route();
    }
  };
  row.appendChild(reset);
  main.appendChild(row);
  main.appendChild(el('h3', null, 'Как здесь заниматься'));
  main.appendChild(el('ul', null,
    '<li>Проходите модули по порядку — так же, как группы проходят занятия.</li>' +
    '<li>Каждый знак открывайте кликом: карточка покажет произношение, мнемонику и пример из уроков; там же — озвучка (если в системе есть голос хинди) и мини-пропись.</li>' +
    '<li>Упражнения можно перепроходить: сохраняется лучший результат.</li>' +
    '<li>«Что слышится, то и говорится» — произносите вслух всё, что видите.</li>'));
}

/* ---------- модуль ---------- */
function renderModule(id) {
  var m = MODULES.filter(function (x) { return x.id === id; })[0];
  if (!m) { location.hash = '#/'; return; }
  $('#crumbs').textContent = 'Модуль ' + m.id + ' · ' + m.title;
  main.innerHTML = '';
  main.appendChild(el('h2', 'modtitle', m.id + '. ' + m.title));
  main.appendChild(el('p', 'lede', m.lede));
  m.blocks.forEach(function (b) { renderBlock(b, m); });

  /* футер модуля */
  var foot = el('div', 'modfooter');
  var prev = el('button', 'btn ghost', '← Модуль ' + (id - 1));
  if (id > 0) { prev.onclick = function () { location.hash = '#/m/' + (id - 1); }; foot.appendChild(prev); }
  var doneBtn = el('button', 'btn', moduleDone(id) ? '✓ Модуль пройден' : 'Отметить модуль пройденным');
  doneBtn.onclick = function () {
    var st = modState(id); st.done = !st.done; saveState();
    doneBtn.textContent = st.done ? '✓ Модуль пройден' : 'Отметить модуль пройденным';
    renderSidebar(location.hash); updatePill();
  };
  foot.appendChild(doneBtn);
  if (id < MODULES.length - 1) {
    var next = el('button', 'btn', 'Модуль ' + (id + 1) + ' →');
    next.onclick = function () { location.hash = '#/m/' + (id + 1); };
    foot.appendChild(next);
  }
  main.appendChild(foot);
}

/* ---------- блоки урока ---------- */
function renderBlock(b, m) {
  switch (b.t) {
    case 'h': main.appendChild(el('h3', null, b.text)); break;
    case 'p': main.appendChild(el('p', null, b.html)); break;
    case 'ul': main.appendChild(el('ul', null, b.items.map(function (i) { return '<li>' + i + '</li>'; }).join(''))); break;
    case 'ol': main.appendChild(el('ol', null, b.items.map(function (i) { return '<li>' + i + '</li>'; }).join(''))); break;
    case 'note': {
      var n = el('div', 'note' + (b.tag === 'Мнемоника' ? ' mnemo' : ''),
        '<span class="tag">' + esc(b.tag) + '</span><div>' + b.html + '</div>');
      main.appendChild(n); break;
    }
    case 'quote': {
      var q = el('blockquote', 'quote', '«' + b.text + '»<span class="who">— ' + esc(b.who) + '</span>');
      main.appendChild(q); break;
    }
    case 'letters': renderLettersBlock(b); break;
    case 'matrix': renderMatrix(b); break;
    case 'words': renderWords(b.m, b.title); break;
    case 'sentence': renderSentence(SENTENCES[b.i]); break;
    case 'homework': renderHomework(b, m); break;
    case 'exercise': renderExercise(b); break;
  }
}

function letterCardHTML(r) {
  var c = el('div', 'lcard');
  c.innerHTML = '<span class="dev">' + r.dev + '</span><span class="ia">' + esc(r.iast) + '</span><span class="ru">' + esc(r.ru || '') + '</span>';
  c.onclick = function () { openLetterModal(r); };
  return c;
}
function renderLettersBlock(b) {
  if (b.title) main.appendChild(el('h4', null, b.title));
  if (b.set === 'signs') {
    var wrap = el('div', null);
    SIGNS.forEach(function (s) {
      var d = el('div', 'note',
        '<span class="tag">' + s.name + '</span>' +
        '<div style="font-size:26px;font-family:var(--devfont)">' + s.dev + '</div>' +
        '<div>' + s.desc + '</div>');
      wrap.appendChild(d);
    });
    main.appendChild(wrap);
    return;
  }
  if (b.lig) {
    var lg = el('div', 'lettergrid');
    LIGATURES.forEach(function (l) {
      var r = { dev: l.dev, iast: l.iast, ru: '', type: 'lig', note: l.note, ligType: l.type };
      lg.appendChild(letterCardHTML(r));
    });
    main.appendChild(lg);
    return;
  }
  if (b.numerals) {
    var ng = el('div', 'lettergrid');
    NUMERALS.forEach(function (n) {
      var r = { dev: n.dev, iast: String(n.v), ru: '', type: 'num' };
      ng.appendChild(letterCardHTML(r));
    });
    main.appendChild(ng);
    return;
  }
  if (b.varnamala) {
    main.appendChild(el('h4', null, 'Гласные'));
    var vg = el('div', 'lettergrid');
    VOWELS.forEach(function (v) { vg.appendChild(letterCardHTML({ dev: v.dev, iast: v.iast, ru: v.ru, obj: v, type: 'vow' })); });
    main.appendChild(vg);
    main.appendChild(el('h4', null, 'Согласные: пять варг'));
    ['ka', 'ca', 'Ta', 'ta', 'pa'].forEach(function (vg2) {
      main.appendChild(el('p', null, '<b>' + VARGA_NAMES[vg2] + '</b>'));
      var g = el('div', 'lettergrid');
      CONSONANTS.filter(function (c) { return c.varga === vg2; }).forEach(function (c) {
        g.appendChild(letterCardHTML({ dev: c.dev, iast: c.iast, ru: c.ru, obj: c, type: 'cons' }));
      });
      main.appendChild(g);
    });
    main.appendChild(el('h4', null, 'Антахстха и ушман'));
    var g2 = el('div', 'lettergrid');
    CONSONANTS.filter(function (c) { return c.varga === 'antahstha' || c.varga === 'ushman'; }).forEach(function (c) {
      g2.appendChild(letterCardHTML({ dev: c.dev, iast: c.iast, ru: c.ru, obj: c, type: 'cons' }));
    });
    main.appendChild(g2);
    return;
  }
  var g3 = el('div', 'lettergrid');
  setItems(b.set).forEach(function (r) { g3.appendChild(letterCardHTML(r)); });
  main.appendChild(g3);
}

/* матрица слогов */
function renderMatrix(b) {
  main.appendChild(el('h4', null, b.title));
  if (b.note) main.appendChild(el('p', 'lede', b.note));
  var t = el('table', 'matrix');
  var thead = el('thead');
  var hr = el('tr');
  hr.appendChild(el('th', null, ''));
  b.vow.forEach(function (vid) { var v = vow(vid); hr.appendChild(el('th', null, '<span class="dev-inline">' + v.dev + '</span> ' + v.iast)); });
  thead.appendChild(hr); t.appendChild(thead);
  var tb = el('tbody');
  b.cons.forEach(function (cid) {
    var c = cons(cid);
    var tr = el('tr');
    tr.appendChild(el('th', null, '<span class="dev-inline">' + c.dev + '</span> ' + c.iast));
    b.vow.forEach(function (vid) {
      var v = vow(vid);
      var dev = v.id === 'a' ? c.dev : c.dev + v.matra;
      var iast = v.id === 'a' ? c.iast : c.iast.replace(/a$/, '') + v.iast;
      var td = el('td', null, '<span class="cell">' + dev + '</span>');
      td.onclick = (function (dv, ia) {
        return function () { openSyllableModal(dv, ia); };
      })(dev, iast);
      tr.appendChild(td);
    });
    tb.appendChild(tr);
  });
  t.appendChild(tb);
  main.appendChild(t);
}
function openSyllableModal(dev, iast) {
  var box = modalShell();
  var w = el('div', 'letter-modal');
  w.innerHTML = '<button class="close">✕</button>' +
    '<div class="big">' + dev + '</div>' +
    '<div class="ia-big">' + esc(iast) + '</div>' +
    '<dl><dt>Как читать</dt><dd>Согласная + гласная-матра. Матра «меряется» по согласной: i — перед ней (читается после), ā — палочка справа, u — запятая снизу.</dd></dl>';
  var tools = el('div', 'tools');
  var sb = el('button', 'btn-speak', '🔊 Озвучить');
  sb.disabled = !speakAvailable();
  sb.onclick = function () { speak(dev); };
  tools.appendChild(sb);
  w.appendChild(tools);
  wireClose(w);
  box.appendChild(w);
}

/* слова */
function renderWords(mTag, title) {
  var list = VOCAB.filter(function (w) { return w.m === mTag; });
  if (!list.length) return;
  if (title) main.appendChild(el('h4', null, title + ' (' + list.length + ')'));
  var chips = el('div', 'wordchips');
  list.forEach(function (w) {
    var c = el('div', 'wchip',
      '<span class="dev">' + w.dev + '</span><span class="ia">' + esc(w.iast) + '</span> <span class="ru">' + esc(w.ru) + '</span>');
    c.onclick = function () {
      var box = modalShell();
      var wm = el('div', 'letter-modal');
      wm.innerHTML = '<button class="close">✕</button>' +
        '<div class="big">' + w.dev + '</div>' +
        '<div class="ia-big">' + esc(w.iast) + '</div>' +
        '<div class="ru-big">' + esc(w.ru) + '</div>' +
        (w.note ? '<dl><dt>Заметка</dt><dd>' + esc(w.note) + '</dd></dl>' : '');
      var tools = el('div', 'tools');
      var sb = el('button', 'btn-speak', '🔊 Озвучить');
      sb.disabled = !speakAvailable();
      sb.onclick = function () { speak(w.dev); };
      tools.appendChild(sb);
      wm.appendChild(tools);
      wireClose(wm);
      box.appendChild(wm);
    };
    chips.appendChild(c);
  });
  main.appendChild(chips);
}

/* предложение */
function renderSentence(s) {
  var box = el('div', 'note');
  box.innerHTML = '<span class="tag">Читаем</span>' +
    '<div style="font-family:var(--devfont);font-size:30px;line-height:1.7">' + s.dev + '</div>';
  var row = el('div', 'btnrow');
  var b1 = el('button', 'btn ghost', 'Показать IAST');
  var b2 = el('button', 'btn ghost', 'Показать перевод');
  var ia = el('p', null, ''); ia.style.display = 'none';
  var tr = el('p', null, ''); tr.style.display = 'none';
  b1.onclick = function () { ia.style.display = ''; ia.innerHTML = '<b>IAST:</b> ' + esc(s.iast); b1.disabled = true; };
  b2.onclick = function () { tr.style.display = ''; tr.innerHTML = '<b>Перевод:</b> ' + esc(s.ru); b2.disabled = true; };
  row.appendChild(b1); row.appendChild(b2);
  if (speakAvailable()) {
    var sp = el('button', 'btn-speak', '🔊 Слушать');
    sp.onclick = function () { speak(s.dev); };
    row.appendChild(sp);
  }
  box.appendChild(row); box.appendChild(ia); box.appendChild(tr);
  main.appendChild(box);
}

/* домашка */
function renderHomework(b, m) {
  var key = 'm' + m.id;
  if (!state.hw[key]) state.hw[key] = b.items.map(function () { return false; });
  var hw = el('div', 'homework');
  hw.innerHTML = '<h4>Домашнее задание</h4>';
  b.items.forEach(function (item, i) {
    var lb = el('label');
    var cb = el('input'); cb.type = 'checkbox'; cb.checked = !!state.hw[key][i];
    cb.onchange = function () { state.hw[key][i] = cb.checked; saveState(); };
    lb.appendChild(cb);
    lb.appendChild(el('span', null, item));
    hw.appendChild(lb);
  });
  main.appendChild(hw);
}

/* ---------- модальная карточка буквы ---------- */
function modalShell() {
  var root = $('#modal-root');
  root.innerHTML = '';
  var ov = el('div', 'overlay');
  ov.onclick = function (e) { if (e.target === ov) { root.innerHTML = ''; } };
  root.appendChild(ov);
  /* закрытие по Escape */
  document.onkeydown = function (e) { if (e.key === 'Escape') root.innerHTML = ''; };
  return ov;
}
function wireClose(w) {
  var c = w.querySelector('.close');
  if (c) c.onclick = function () { $('#modal-root').innerHTML = ''; };
}
function openLetterModal(r) {
  var box = modalShell();
  var w = el('div', 'letter-modal');
  var html = '<button class="close">✕</button><div class="big">' + r.dev + '</div>' +
    '<div class="ia-big">' + esc(r.iast) + '</div><div class="ru-big">' + esc(r.ru || '') + '</div>';
  if (r.type === 'cons') {
    var c = r.obj;
    html += '<dl>' +
      '<dt>Ряд</dt><dd>' + VARGA_NAMES[c.varga] + (c.varga !== 'antahstha' && c.varga !== 'ushman' ? ' · ' + COL_NAMES[c.col] : '') + '</dd>' +
      '<dt>Произношение</dt><dd>' + esc(c.sound) + '</dd>' +
      (c.mnemo ? '<dt>Мнемоника</dt><dd>' + esc(c.mnemo) + '</dd>' : '') +
      (c.ex ? '<dt>Пример</dt><dd><span class="dev-inline" style="font-size:22px">' + c.ex.dev + '</span> <b>' + esc(c.ex.iast) + '</b> — ' + esc(c.ex.ru) + '</dd>' : '') +
      '</dl>';
  } else if (r.type === 'vow') {
    var v = r.obj;
    html += '<dl>' +
      '<dt>Звук</dt><dd>' + esc(v.sound) + (v.long ? ' Долгота смыслоразличительна.' : '') + '</dd>' +
      (v.matra ? '<dt>Матра</dt><dd>Внутри слога: <span class="dev-inline" style="font-size:22px">क' + v.matra + '</span> (' + (v.id === 'i' ? 'пишется перед согласной' : 'приписывается к согласной') + ')</dd>' : '<dt>Матра</dt><dd>Нет: краткое a не пишется — оно «встроено» в согласную</dd>') +
      (v.mnemo ? '<dt>Мнемоника</dt><dd>' + esc(v.mnemo) + '</dd>' : '') +
      (v.ex ? '<dt>Пример</dt><dd><span class="dev-inline" style="font-size:22px">' + v.ex.dev + '</span> <b>' + esc(v.ex.iast) + '</b> — ' + esc(v.ex.ru) + '</dd>' : '') +
      '</dl>';
  } else if (r.type === 'lig') {
    html += '<dl><dt>Лигатура</dt><dd>' + (r.note ? esc(r.note) : '') + '</dd></dl>';
  } else if (r.type === 'num') {
    html += '<dl><dt>Цифра</dt><dd>' + esc(r.iast) + '</dd></dl>';
  }
  w.innerHTML = html;
  $('.close', w).onclick = function () { $('#modal-root').innerHTML = ''; };
  var tools = el('div', 'tools');
  if (speakAvailable()) {
    var sb = el('button', 'btn-speak', '🔊 Озвучить');
    sb.onclick = function () { speak(r.dev); };
    tools.appendChild(sb);
  }
  var wb = el('button', 'btn ghost', '✍ Прописать');
  wb.onclick = function () { openWriteTrainer(r.dev); };
  tools.appendChild(wb);
  w.appendChild(tools);
  box.appendChild(w);
}

/* ---------- тренажёр письма (канвас) ---------- */
function makeWriteCanvas(container, size, initialGlyph) {
  size = size || 400;
  var wrap = el('div', 'writewrap');
  var cv = el('canvas'); cv.id = 'writepad-' + Math.random().toString(36).slice(2, 7);
  cv.width = size; cv.height = size;
  cv.style.width = size + 'px'; cv.style.height = size + 'px';
  var ctl = el('div', 'writectl');
  var glyph = initialGlyph || 'क';
  var showGhost = true, thick = 3;

  function draw() {
    var ctx = cv.getContext('2d');
    ctx.clearRect(0, 0, size, size);
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
    var cell = size / 5;
    ctx.strokeStyle = '#dfe7ef'; ctx.lineWidth = 1;
    for (var i = 1; i < 5; i++) {
      ctx.beginPath(); ctx.moveTo(i * cell, 0); ctx.lineTo(i * cell, size); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(0, i * cell); ctx.lineTo(size, i * cell); ctx.stroke();
    }
    ctx.strokeStyle = '#b8c4d4'; ctx.strokeRect(0.5, 0.5, size - 1, size - 1);
    ctx.strokeStyle = '#9db4d0';
    ctx.beginPath(); ctx.moveTo(0, cell); ctx.lineTo(size, cell); ctx.stroke(); /* верхняя линия */
    if (showGhost && glyph) {
      ctx.fillStyle = 'rgba(120,110,100,.22)';
      ctx.font = (cell * 3.6) + 'px "Nirmala UI","Noto Sans Devanagari",sans-serif';
      ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText(glyph, size / 2, cell * 2.9);
    }
  }
  draw();

  /* рисование */
  var drawing = false, last = null;
  function pos(e) {
    var rect = cv.getBoundingClientRect();
    var p = e.touches ? e.touches[0] : e;
    return { x: (p.clientX - rect.left) * (size / rect.width), y: (p.clientY - rect.top) * (size / rect.height) };
  }
  function stroke(from, to) {
    var ctx = cv.getContext('2d');
    ctx.strokeStyle = '#2b2620'; ctx.lineWidth = thick; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ctx.beginPath(); ctx.moveTo(from.x, from.y); ctx.lineTo(to.x, to.y); ctx.stroke();
  }
  function down(e) { drawing = true; last = pos(e); if (e.touches) e.preventDefault(); }
  function move(e) {
    if (!drawing) return;
    var p = pos(e); stroke(last, p); last = p;
    if (e.touches) e.preventDefault();
  }
  function up() { drawing = false; }
  cv.addEventListener('mousedown', down); cv.addEventListener('mousemove', move);
  window.addEventListener('mouseup', up);
  cv.addEventListener('touchstart', down, { passive: false });
  cv.addEventListener('touchmove', move, { passive: false });
  cv.addEventListener('touchend', up);

  /* элементы управления */
  var pickWrap = el('div', 'letterpick');
  var letters = ['क', 'ख', 'ग', 'घ', 'ङ', 'च', 'ज', 'ट', 'ठ', 'ड', 'त', 'थ', 'द', 'न', 'प', 'फ', 'ब', 'भ', 'म', 'य', 'र', 'ल', 'व', 'श', 'ष', 'स', 'ह', 'अ', 'आ', 'इ', 'ई', 'उ', 'ऊ', 'ए', 'ऐ', 'ओ', 'औ'];
  letters.forEach(function (L) {
    var b = el('button', 'lp' + (L === glyph ? ' on' : ''), L);
    b.onclick = function () {
      glyph = L;
      pickWrap.querySelectorAll('.lp').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
      draw();
    };
    pickWrap.appendChild(b);
  });
  var slider = el('input'); slider.type = 'range'; slider.min = 1; slider.max = 10; slider.value = thick;
  slider.oninput = function () { thick = +slider.value; };
  var gBtn = el('button', 'btn ghost', 'Образец: вкл');
  gBtn.onclick = function () { showGhost = !showGhost; gBtn.textContent = 'Образец: ' + (showGhost ? 'вкл' : 'выкл'); draw(); };
  var cBtn = el('button', 'btn ghost', 'Очистить');
  cBtn.onclick = function () { draw(); };
  ctl.appendChild(el('div', null, '<b>Знак:</b>'));
  ctl.appendChild(pickWrap);
  ctl.appendChild(el('div', null, '<b>Толщина пера</b>'));
  ctl.appendChild(slider);
  var row = el('div', 'btnrow');
  row.appendChild(gBtn); row.appendChild(cBtn);
  ctl.appendChild(row);
  ctl.appendChild(el('div', null, '<span style="color:var(--muted);font-size:12.5px">Верхняя линия — граница «палубы». Пишите знак под ней, палубу — последним движением, «чтобы стыка не было видно». Проговаривайте вслух.</span>'));

  wrap.appendChild(cv); wrap.appendChild(ctl);
  container.appendChild(wrap);
}
function openWriteTrainer(glyph) {
  var box = modalShell();
  var w = el('div', 'letter-modal');
  w.style.maxWidth = '760px';
  w.innerHTML = '<button class="close">✕</button><h3 style="margin-top:0">Пропись</h3>';
  $('.close', w).onclick = function () { $('#modal-root').innerHTML = ''; };
  makeWriteCanvas(w, 420, glyph);
  box.appendChild(w);
}

/* ============================================================
   УПРАЖНЕНИЯ
   ============================================================ */
function renderExercise(b) {
  var box = el('div', 'exercise');
  box.appendChild(el('div', 'ex-title', '🎯 ' + b.title));
  if (b.sub) box.appendChild(el('div', 'ex-sub', b.sub));
  var exId = b.id || ('m' + b.set + '-' + b.kind);
  var zone = el('div');
  box.appendChild(zone);
  main.appendChild(box);

  if (state.scores[exId] !== undefined) {
    box.appendChild(el('div', 'exscore', 'Лучший результат: <b>' + state.scores[exId] + '%</b>'));
  }

  switch (b.kind) {
    case 'quiz': exQuiz(zone, b, exId); break;
    case 'flashcards': exFlash(zone, b, exId); break;
    case 'drill': exDrill(zone, b, exId); break;
    case 'listen': exListen(zone, b, exId); break;
    case 'build': exBuild(zone, b, exId); break;
    case 'order': exOrder(zone, b, exId); break;
    case 'write': exWrite(zone, b); break;
    case 'final': exFinal(zone, b, exId); break;
  }
}

/* --- квиз (генерированный или фиксированный) --- */
function exQuiz(zone, b, exId) {
  var questions = [];
  if (b.fixed) {
    questions = b.fixed.slice();
  } else if (b.set === 'final_read') {
    shuffle(VOCAB).slice(0, b.n || 12).forEach(function (w) {
      var others = shuffle(VOCAB.filter(function (x) { return x.iast !== w.iast; })).slice(0, 3);
      var opts = shuffle([w.iast].concat(others.map(function (o) { return o.iast; })));
      questions.push({ dev: w.dev, opts: opts, a: opts.indexOf(w.iast) });
    });
  } else {
    var items = setItems(b.set);
    var n = Math.min(b.n || 10, items.length);
    shuffle(items).slice(0, n).forEach(function (r) {
      var others = shuffle(items.filter(function (x) { return x.iast !== r.iast; })).slice(0, 3);
      var opts = shuffle([r].concat(others)).map(function (o) { return o.iast; });
      var qdev = r.dev;
      questions.push({ q: 'dev', dev: qdev, opts: opts, a: opts.indexOf(r.iast) });
    });
  }
  runChoiceQuiz(zone, questions, exId);
}

function runChoiceQuiz(zone, questions, exId, cb) {
  var idx = 0, right = 0, answered = false;
  function renderQ() {
    answered = false;
    zone.innerHTML = '';
    var q = questions[idx];
    var head = el('div', 'quizq');
    if (q.q === 'dev' || q.dev) head.innerHTML = 'Как читается знак? <span class="dev">' + (q.dev || q.q) + '</span>';
    else head.innerHTML = esc(q.q || q.text);
    zone.appendChild(head);
    var opts = el('div', 'opts');
    q.opts.forEach(function (o, i) {
      var isDev = /^[\u0900-\u097F]/.test(o);
      var btn = el('button', 'opt' + (isDev ? '' : ' lat'), isDev ? o : esc(o));
      btn.onclick = function () {
        if (answered) return;
        answered = true;
        var ok = i === q.a;
        if (ok) { right++; btn.classList.add('right'); }
        else {
          btn.classList.add('wrong');
          opts.children[q.a].classList.add('right');
        }
        Array.prototype.forEach.call(opts.children, function (x) { x.disabled = true; });
        setTimeout(next, 700);
      };
      opts.appendChild(btn);
    });
    zone.appendChild(opts);
    zone.appendChild(el('div', 'exscore', 'Вопрос ' + (idx + 1) + ' / ' + questions.length + ' · верно: ' + right));
  }
  function next() {
    idx++;
    if (idx < questions.length) renderQ();
    else {
      var pct = Math.round(100 * right / questions.length);
      zone.innerHTML = '<div class="quizq">Готово: <b>' + right + ' / ' + questions.length + '</b> (' + pct + '%)</div>';
      var msg = pct >= 80 ? '«Сдал бы Марцис Юрьевич»' : pct >= 60 ? 'Уровень группы после проверки — повторите карточки' : '«Квадраты надо писать до потери пульса» — и вернуться к модулю';
      zone.appendChild(el('p', null, msg));
      var again = el('button', 'btn', 'Ещё раз');
      again.onclick = function () { idx = 0; right = 0; renderQ(); };
      zone.appendChild(again);
      saveScore(exId, pct);
      if (cb) cb(pct);
      /* обновить строку лучшего результата */
      var ex = zone.closest('.exercise');
      if (ex) {
        var old = $('.exscore', ex);
        if (old && old.parentElement === ex) old.remove();
        ex.appendChild(el('div', 'exscore', 'Лучший результат: <b>' + state.scores[exId] + '%</b>'));
      }
    }
  }
  renderQ();
}

/* --- флеш-карточки --- */
function exFlash(zone, b, exId) {
  var items = setItems(b.set);
  var deck = shuffle(items);
  var i = 0, known = 0;
  function render() {
    if (i >= deck.length) {
      var pct = Math.round(100 * known / deck.length);
      zone.innerHTML = '<div class="quizq">Колода пройдена: знаете <b>' + known + ' / ' + deck.length + '</b></div>';
      var again = el('button', 'btn', 'Повторить');
      again.onclick = function () { deck = shuffle(items); i = 0; known = 0; render(); };
      zone.appendChild(again);
      saveScore(exId, pct);
      return;
    }
    var r = deck[i];
    zone.innerHTML = '';
    var fz = el('div', 'flashzone');
    var card = el('div', 'flashcard');
    var back = r.type === 'cons' || r.type === 'vow' ? (r.ru || '') : '';
    var backNote = r.obj && r.obj.mnemo ? r.obj.mnemo : (r.note || '');
    card.innerHTML = '<div class="inner">' +
      '<div class="face"><span class="dev">' + r.dev + '</span><span class="hint">кликните, чтобы перевернуть</span></div>' +
      '<div class="face back"><span class="ia">' + esc(r.iast) + '</span><span class="hint">' + esc(back) + '</span>' +
      (backNote ? '<span class="hint" style="max-width:260px">' + esc(backNote) + '</span>' : '') + '</div></div>';
    card.onclick = function () { card.classList.add('flip'); };
    fz.appendChild(card);
    var row = el('div', 'btnrow');
    var no = el('button', 'btn ghost', 'Повторить ещё');
    no.onclick = function () { deck.push(r); i++; render(); };
    var yes = el('button', 'btn', 'Знаю');
    yes.onclick = function () { known++; i++; render(); };
    row.appendChild(no); row.appendChild(yes);
    fz.appendChild(row);
    fz.appendChild(el('div', 'exscore', 'Карточка ' + (i + 1) + ' / ' + deck.length + ' · знаю: ' + known));
    zone.appendChild(fz);
  }
  render();
}

/* --- тренажёр слогов --- */
function exDrill(zone, b, exId) {
  var cvSets = {
    cv6: { cons: CONSONANTS.map(function (c) { return c.id; }), vow: ['a', 'aa', 'i', 'ii', 'u', 'uu'] },
    cvall: { cons: CONSONANTS.map(function (c) { return c.id; }), vow: ['a', 'aa', 'i', 'ii', 'u', 'uu', 'e', 'ai', 'o', 'au', 'r'] }
  };
  var total = b.n || 12, done = 0, right = 0;
  function render() {
    if (done >= total) {
      var pct = Math.round(100 * right / total);
      zone.innerHTML = '<div class="quizq">Тренажёр: <b>' + right + ' / ' + total + '</b> (' + pct + '%)</div>';
      var again = el('button', 'btn', 'Ещё подход');
      again.onclick = function () { done = 0; right = 0; render(); };
      zone.appendChild(again);
      saveScore(exId, pct);
      return;
    }
    var target, pool;
    if (b.set === 'cv6' || b.set === 'cvall') {
      var cfg = cvSets[b.set];
      target = randomSyllable(cfg.cons, cfg.vow);
      pool = [target];
      var guard = 0;
      while (pool.length < 4 && guard++ < 50) {
        var s = randomSyllable(cfg.cons, cfg.vow);
        if (s.iast !== target.iast && !pool.some(function (p) { return p.iast === s.iast; })) pool.push(s);
      }
    } else {
      var items = setItems(b.set);
      target = pick(items);
      pool = [target].concat(shuffle(items.filter(function (x) { return x.iast !== target.iast; })).slice(0, 3));
    }
    pool = shuffle(pool);
    var correctIast = target.iast;
    zone.innerHTML = '';
    zone.appendChild(el('div', 'quizq', 'Читайте вслух, затем проверьте: <span class="dev">' + target.dev + '</span>'));
    var opts = el('div', 'opts');
    var answered = false;
    pool.forEach(function (p) {
      var btn = el('button', 'opt lat', esc(p.iast));
      btn.onclick = function () {
        if (answered) return; answered = true;
        var ok = p.iast === correctIast;
        if (ok) { right++; btn.classList.add('right'); } else btn.classList.add('wrong');
        Array.prototype.forEach.call(opts.children, function (x) {
          x.disabled = true;
          if (x.textContent === correctIast) x.classList.add('right');
        });
        done++;
        setTimeout(render, 600);
      };
      opts.appendChild(btn);
    });
    zone.appendChild(opts);
    zone.appendChild(el('div', 'exscore', 'Слог ' + (done + 1) + ' / ' + total));
  }
  render();
}

/* --- на слух --- */
var MINPAIRS = [
  { pairs: ['त', 'ट'] }, { pairs: ['द', 'ड'] }, { pairs: ['न', 'ण'] },
  { pairs: ['श', 'ष'] }, { pairs: ['ब', 'व'] }, { pairs: ['क', 'ख'] },
  { pairs: ['ग', 'घ'] }, { pairs: ['द', 'ध'] }, { pairs: ['स', 'श'] }
];
function exListen(zone, b, exId) {
  if (!speakAvailable()) {
    zone.innerHTML = '<p class="lede">🔊 В системе нет голоса хинди — упражнение недоступно. Установите голос хинди (Windows: Параметры → Время и язык → Речь) или пропустите: главное различие — церебральные «языком назад» и придыхание «единым порывом».</p>';
    return;
  }
  var total = 8, done = 0, right = 0;
  function render() {
    if (done >= total) {
      var pct = Math.round(100 * right / total);
      zone.innerHTML = '<div class="quizq">На слух: <b>' + right + ' / ' + total + '</b> (' + pct + '%)</div>';
      var again = el('button', 'btn', 'Ещё раз');
      again.onclick = function () { done = 0; right = 0; render(); };
      zone.appendChild(again);
      saveScore(exId, pct);
      return;
    }
    var mp = pick(MINPAIRS);
    var target = pick(mp.pairs);
    var iastMap = { 'त': 'ta', 'ट': 'ṭa', 'द': 'da', 'ड': 'ḍa', 'न': 'na', 'ण': 'ṇa', 'श': 'śa', 'ष': 'ṣa', 'ब': 'ba', 'व': 'va', 'क': 'ka', 'ख': 'kha', 'ग': 'ga', 'घ': 'gha', 'ध': 'dha', 'स': 'sa' };
    zone.innerHTML = '';
    zone.appendChild(el('div', 'quizq', 'Прослушайте и выберите знак'));
    var row = el('div', 'btnrow');
    var play = el('button', 'btn', '🔊 Играть');
    play.onclick = function () { speak(target); };
    row.appendChild(play);
    zone.appendChild(row);
    var opts = el('div', 'opts');
    opts.style.marginTop = '10px';
    var answered = false;
    shuffle(mp.pairs.concat(pick(shuffle(MINPAIRS.filter(function (m) { return m !== mp; })).pairs))).filter(function (v, i, a) { return a.indexOf(v) === i; }).slice(0, 3).concat([target]);
    var uniq = [];
    [target].concat(mp.pairs).forEach(function (x) { if (!uniq.includes(x)) uniq.push(x); });
    uniq = shuffle(uniq);
    uniq.forEach(function (d) {
      var btn = el('button', 'opt', d);
      btn.onclick = function () {
        if (answered) return; answered = true;
        var ok = d === target;
        if (ok) { right++; btn.classList.add('right'); } else btn.classList.add('wrong');
        Array.prototype.forEach.call(opts.children, function (x) { x.disabled = true; if (x.textContent === target) x.classList.add('right'); });
        done++;
        setTimeout(render, 800);
      };
      opts.appendChild(btn);
    });
    zone.appendChild(opts);
    zone.appendChild(el('div', 'exscore', 'Звук ' + (done + 1) + ' / ' + total));
    setTimeout(function () { speak(target); }, 300);
  }
  render();
}

/* --- конструктор слов --- */
function exBuild(zone, b, exId) {
  var words = b.words.filter(function (w) { return BUILD_WORDS[w]; });
  var wi = -1, solved = 0;
  function next() {
    wi++;
    if (wi >= words.length) {
      var pct = Math.round(100 * solved / words.length);
      zone.innerHTML = '<div class="quizq">Собрано слов: <b>' + solved + ' / ' + words.length + '</b></div>';
      var again = el('button', 'btn', 'Сначала');
      again.onclick = function () { wi = -1; solved = 0; next(); };
      zone.appendChild(again);
      saveScore(exId, pct);
      return;
    }
    var w = BUILD_WORDS[words[wi]];
    var slots = w.parts.map(function () { return null; });
    var order = shuffle(w.parts.map(function (p, i) { return { p: p, i: i }; }));
    var placed = 0;
    zone.innerHTML = '';
    zone.appendChild(el('div', 'quizq', 'Соберите слово по слогам:'));
    var slotsEl = el('div', 'build-slots');
    slots.forEach(function (_, i) {
      var s = el('div', 'slot'); s.dataset.i = i;
      s.onclick = function () {
        if (slots[i]) { slots[i] = null; s.textContent = ''; s.classList.remove('filled'); placed--; syncPool(); }
      };
      slotsEl.appendChild(s);
    });
    var pool = el('div', 'build-pool');
    function syncPool() {
      pool.innerHTML = '';
      var usedCount = {};
      slots.forEach(function (sv) { if (sv !== null) usedCount[sv] = (usedCount[sv] || 0) + 1; });
      order.forEach(function (o) {
        var cnt = (usedCount[o.p] || 0);
        var tile = el('button', 'tile' + (cnt > 0 ? ' used' : ''), o.p);
        tile.dataset.p = o.p;
        tile.onclick = function () {
          if (tile.classList.contains('used')) return;
          for (var k = 0; k < slots.length; k++) {
            if (slots[k] === null) {
              slots[k] = o.p; placed++;
              slotsEl.children[k].textContent = o.p;
              slotsEl.children[k].classList.add('filled');
              break;
            }
          }
          syncPool();
          if (placed === slots.length) check();
        };
        pool.appendChild(tile);
      });
      /* пересчёт used: сколько слотов занято каждым слогом vs сколько таких плиток */
      var tilesByP = {};
      Array.prototype.forEach.call(pool.children, function (t) {
        tilesByP[t.dataset.p] = (tilesByP[t.dataset.p] || 0) + 1;
      });
      Array.prototype.forEach.call(pool.children, function (t) {
        var need = (usedCount[t.dataset.p] || 0);
        if (need > 0) {
          /* пометить первые `need` плиток этого слога */
          var seen = 0;
          Array.prototype.forEach.call(pool.children, function (t2) {
            if (t2.dataset.p === t.dataset.p) {
              seen++;
              if (seen <= need) t2.classList.add('used');
            }
          });
        } else t.classList.remove('used');
      });
    }
    function check() {
      var ok = slots.every(function (s, i) { return s === w.parts[i]; });
      var res = el('div', 'quizq', ok
        ? '<span style="color:var(--good)">✓ Верно!</span> <span class="dev">' + w.parts.join('') + '</span> — <b>' + esc(w.iast) + '</b>, ' + esc(w.ru)
        : '<span style="color:var(--bad)">Не тот порядок — щёлкните по слоту, чтобы убрать слог. Слово: <b>' + esc(w.iast) + '</b> (' + esc(w.ru) + ')</span>');
      if (ok) { solved++; if (speakAvailable()) speak(w.parts.join('')); }
      zone.insertBefore(res, slotsEl);
      var show = el('button', 'btn ghost', 'Показать слово');
      show.onclick = function () {
        res.innerHTML = 'Слово: <span class="dev">' + w.parts.join('') + '</span> — <b>' + esc(w.iast) + '</b>, ' + esc(w.ru);
        slots.forEach(function (sv, k) { slotsEl.children[k].textContent = w.parts[k]; });
      };
      var nb = el('button', 'btn', 'Дальше →');
      nb.onclick = next;
      var row = el('div', 'btnrow');
      if (!ok) row.appendChild(show);
      row.appendChild(nb);
      zone.appendChild(row);
    }
    zone.appendChild(slotsEl);
    zone.appendChild(pool);
    syncPool();
  }
  next();
}

/* --- порядок варнамалы --- */
function exOrder(zone, b, exId) {
  var seq = SETS.varnamala.map(resolve);
  var pos = 0;
  function render() {
    zone.innerHTML = '';
    zone.appendChild(el('p', 'lede', 'Сдавайте алфавит, как у доски: ' +
      (pos >= seq.length ? 'пройден полностью!' : 'следующий знак — №' + (pos + 1) + ' из ' + seq.length)));
    var seqEl = el('div', 'seq');
    seq.slice(0, pos).forEach(function (r) { seqEl.appendChild(el('span', 'chip', r.dev)); });
    zone.appendChild(seqEl);
    if (pos >= seq.length) {
      zone.appendChild(el('div', 'quizq', '<b>Варнамала сдана!</b> «Индусы мыслят акшарами» — теперь и вы.'));
      var again = el('button', 'btn', 'Заново');
      again.onclick = function () { pos = 0; render(); };
      zone.appendChild(again);
      saveScore(exId, 100);
      return;
    }
    /* пул: следующий + 7 случайных последующих (или любых) */
    var nextItem = seq[pos];
    var distractors = shuffle(seq.slice(pos + 1).concat(seq.slice(0, pos))).slice(0, 7);
    var poolItems = shuffle([nextItem].concat(distractors));
    var pool = el('div', 'orderpool');
    var wrongFlash = 0;
    poolItems.forEach(function (r) {
      var ch = el('button', 'chip', r.dev);
      ch.onclick = function () {
        if (r === nextItem) { pos++; render(); }
        else {
          wrongFlash++;
          ch.style.borderColor = 'var(--bad)';
          ch.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-4px)' }, { transform: 'translateX(4px)' }, { transform: 'translateX(0)' }], { duration: 200 });
        }
      };
      pool.appendChild(ch);
    });
    zone.appendChild(pool);
    /* подсказка последовательности */
    var hintRow = el('div', 'btnrow');
    var hint = el('button', 'btn ghost', 'Подсказать (IAST)');
    hint.onclick = function () { hint.textContent = '№' + (pos + 1) + ': ' + nextItem.iast; hint.disabled = true; };
    hintRow.appendChild(hint);
    zone.appendChild(hintRow);
  }
  render();
}

/* --- пропись-упражнение --- */
function exWrite(zone, b) {
  makeWriteCanvas(zone, 420);
}

/* --- итоговый зачёт --- */
function exFinal(zone, b, exId) {
  var btn = el('button', 'btn', 'Начать зачёт (20 вопросов)');
  zone.appendChild(btn);
  btn.onclick = function () {
    var qs = [];
    /* 6 согласных */
    shuffle(CONSONANTS).slice(0, 6).forEach(function (c) {
      var others = shuffle(CONSONANTS.filter(function (x) { return x.iast !== c.iast; })).slice(0, 3);
      var opts = shuffle([c.iast].concat(others.map(function (o) { return o.iast; })));
      qs.push({ dev: c.dev, opts: opts, a: opts.indexOf(c.iast) });
    });
    /* 4 гласных/матры */
    shuffle(VOWELS.slice(0, 13)).slice(0, 4).forEach(function (v) {
      var base = cons('ka');
      var syl = v.id === 'a' ? 'क' : 'क' + v.matra;
      var iast = v.id === 'a' ? 'ka' : 'k' + v.iast;
      var others = shuffle(['a', 'aa', 'i', 'ii', 'u', 'uu', 'e', 'ai', 'o', 'au', 'r'].filter(function (id) { return id !== v.id; })).slice(0, 3);
      var opts = shuffle([iast].concat(others.map(function (id) { return 'k' + vow(id).iast; })));
      qs.push({ dev: syl, opts: opts, a: opts.indexOf(iast) });
    });
    /* 4 лигатуры */
    shuffle(LIGATURES).slice(0, 4).forEach(function (l) {
      var others = shuffle(LIGATURES.filter(function (x) { return x.iast !== l.iast; })).slice(0, 3);
      var opts = shuffle([l.iast].concat(others.map(function (o) { return o.iast; })));
      qs.push({ dev: l.dev, opts: opts, a: opts.indexOf(l.iast) });
    });
    /* 4 цифры */
    shuffle(NUMERALS).slice(0, 3).forEach(function (n) {
      var others = shuffle(NUMERALS.filter(function (x) { return x.v !== n.v; })).slice(0, 3);
      var opts = shuffle([String(n.v)].concat(others.map(function (o) { return String(o.v); })));
      qs.push({ dev: n.dev, opts: opts, a: opts.indexOf(String(n.v)) });
    });
    /* 3 слова */
    shuffle(VOCAB.filter(function (w) { return w.dev.length <= 7; })).slice(0, 3).forEach(function (w) {
      var others = shuffle(VOCAB.filter(function (x) { return x.iast !== w.iast; })).slice(0, 3);
      var opts = shuffle([w.iast].concat(others.map(function (o) { return o.iast; })));
      qs.push({ dev: w.dev, opts: opts, a: opts.indexOf(w.iast) });
    });
    qs = shuffle(qs).slice(0, 20);
    runChoiceQuiz(zone, qs, exId, function (pct) {
      state.final = { best: Math.max(pct, state.final ? state.final.best : 0), passed: !!(pct >= 80 || (state.final && state.final.passed)), date: new Date().toISOString() };
      saveState();
      updatePill();
      if (SCORM.available()) {
        SCORM.reportScore(state.final.best);
        if (state.final.passed) SCORM.reportStatus('completed');
      }
    });
  };
}

/* ============================================================
   СПРАВОЧНИК
   ============================================================ */
function renderRef(id) {
  var r = REFS.filter(function (x) { return x.id === id; })[0];
  if (!r) { location.hash = '#/'; return; }
  $('#crumbs').textContent = 'Справочник · ' + r.title;
  main.innerHTML = '';
  main.appendChild(el('h2', 'modtitle', r.title));

  if (id === 'alphabet') {
    renderLettersBlock({ set: 'varnamala', varnamala: true, title: '' });
    main.appendChild(el('h3', null, 'Знаки-призвуки'));
    renderLettersBlock({ set: 'signs' });
    main.appendChild(el('h3', null, 'Цифры'));
    renderLettersBlock({ set: 'numerals', numerals: true });
  }
  else if (id === 'ligatures') {
    main.appendChild(el('p', 'lede', 'Три типа: горизонтальные («характерная часть + целиком»), вертикальные (носовая своей варги), неразличимые «уродцы» (заучиваются лицом). Всего в текстах ~800 реальных лигатур; для чтения достаточно этих.'));
    var types = [
      ['r-solo', 'r в сочетаниях'],
      ['h', 'Горизонтальные'],
      ['v', 'Вертикальные (носовые)'],
      ['special', 'Неразличимые (заучить)']
    ];
    types.forEach(function (tp) {
      main.appendChild(el('h4', null, tp[1]));
      var t = el('table', 'plain');
      t.innerHTML = '<tr><th>Знак</th><th>IAST</th><th>Комментарий</th></tr>';
      LIGATURES.filter(function (l) { return l.type === tp[0]; }).forEach(function (l) {
        var tr = el('tr');
        tr.innerHTML = '<td style="font-family:var(--devfont);font-size:26px">' + l.dev + '</td><td><b>' + esc(l.iast) + '</b></td><td>' + esc(l.note || '') + '</td>';
        tr.style.cursor = 'pointer';
        tr.onclick = function () { openLetterModal({ dev: l.dev, iast: l.iast, type: 'lig', note: l.note }); };
        t.appendChild(tr);
      });
      main.appendChild(t);
    });
  }
  else if (id === 'vocab') {
    main.appendChild(el('p', 'lede', VOCAB.length + ' слов и корней, звучавших на первых занятиях групп. Кликните по строке — озвучка.'));
    var filter = el('input');
    filter.type = 'search'; filter.placeholder = 'Фильтр: слово, IAST, перевод…';
    filter.style.cssText = 'padding:9px 14px;border:1px solid var(--line);border-radius:10px;font-size:15px;width:320px;max-width:100%';
    main.appendChild(filter);
    var t = el('table', 'plain');
    var head = el('tr'); head.innerHTML = '<th>Деванагари</th><th>IAST</th><th>Перевод</th><th>Модуль</th>';
    t.appendChild(head);
    function fill(f) {
      t.innerHTML = '';
      t.appendChild(head);
      VOCAB.filter(function (w) {
        if (!f) return true;
        f = f.toLowerCase();
        return (w.iast.toLowerCase().indexOf(f) >= 0) || (w.ru.toLowerCase().indexOf(f) >= 0) || w.dev.indexOf(f) >= 0;
      }).forEach(function (w) {
        var tr = el('tr');
        tr.style.cursor = 'pointer';
        tr.innerHTML = '<td style="font-family:var(--devfont);font-size:24px">' + w.dev + '</td><td><b>' + esc(w.iast) + '</b></td><td>' + esc(w.ru) + '</td><td>М' + w.m + '</td>';
        tr.onclick = function () { if (speakAvailable()) speak(w.dev); };
        t.appendChild(tr);
      });
    }
    fill('');
    filter.oninput = function () { fill(filter.value.trim()); };
    main.appendChild(t);
  }
  else if (id === 'writing') {
    main.appendChild(el('p', 'lede', 'Техника письма по курсам каллиграфии деванагари Общества ревнителей санскрита (М. Гасунс, С. Кройцер, Ачьют Палав).'));
    var sections = [
      ['Инструменты',
        '<ul>' +
        '<li><b>Для учёбы достаточно шариковой ручки</b>. Дальше по возрастающей: фломастер → маркер со скошенным срезом → калам из палочки от мороженого (берёза, бук, бамбук) → кисть со срезом 45°.</li>' +
        '<li><b>Правило среза: 40–45°, длинная сторона вверх</b> (в европейской каллиграфике — вниз; тибетские и европейские прямые перья не подходят).</li>' +
        '<li>Бумага: черновики — принтерная; под чернила — плотная 120–200 г; <b>под лист подкладывайте стопку бумаги</b> — «по одному листу на столе линия не идёт».</li>' +
        '</ul>'],
      ['Посадка и рука',
        '<ul>' +
        '<li>Движение — <b>всей рукой от плеча</b>, кисть зафиксирована: «чем меньше участвует кисть, тем лучше». Лист наклоняйте к себе.</li>' +
        '<li>Разминка: расслабить руку, повороты на границе усилия «16 раз», «понять, сколько весит ваша рука».</li>' +
        '<li>«Как гладить бумагу, а не скрести» — перо не передавливать.</li>' +
        '</ul>'],
      ['Сетка и пропорции',
        '<ul>' +
        '<li>Знак вписывается в <b>квадрат 4+1 клетки</b> (4 высоты + 1 на соединительный штрих); овал — 2–2,5 клетки.</li>' +
        '<li>Единая мера — <b>толщина пера</b>: высота знака ≈ 7 ширин среза. Учиться начинают с крупных букв и постепенно уменьшают.</li>' +
        '<li>«Вы будете злостно нарушать пропорции, пока пишете на белом листе» — всегда пишите по клеткам.</li>' +
        '</ul>'],
      ['Порядок штрихов',
        '<ul>' +
        '<li>Сначала — базовые элементы рядами: вертикаль (верх и низ среза строго 45°), горизонталь, овал (справа через верх налево), полуовалы, «клюшка».</li>' +
        '<li><b>Палуба (широ-рекха) пишется последней</b>, слева направо, закрывая всю ширину знака, «чтобы стыка не было видно».</li>' +
        '<li>Сами знаки пишутся справа налево (наследие кхароштхи), а текст читается слева направо.</li>' +
        '<li>Очерёдность прописей — <b>не фонетическая, а графическая</b>: va → ka → pa → ta → bha (родственные формы рядом).</li>' +
        '</ul>'],
      ['Ошибки, за которые ругают',
        '<ul>' +
        '<li>«Копыта» и «сардельки» вместо чётких колышков; хвост «на полпути»; палуба не закрывает знак; зазор между палубой и телом знака.</li>' +
        '<li>Разное давление («вначале худенько, потом толстенько»); скоба заваливается на стержень.</li>' +
        '<li>«Не мы подражаем компьютеру, а компьютер подражает нам» — не имитируйте толщины шрифта.</li>' +
        '</ul>'],
      ['Кривая мастерства',
        '<ul>' +
        '<li>«Первых 80 часов практики — и рука встанет; от 80 до 10 000 — наработка». Навык «быстро обретается и так же быстро теряется».</li>' +
        '<li>Письмо сопровождайте произнесением: «алфавит должен звучать, а не оставаться мёртвым на бумаге».</li>' +
        '</ul>']
    ];
    sections.forEach(function (s) {
      main.appendChild(el('h3', null, s[0]));
      main.appendChild(el('div', null, s[1]));
    });
    main.appendChild(el('h3', null, 'Тренажёр'));
    var z = el('div');
    main.appendChild(z);
    makeWriteCanvas(z, 420);
  }
  else if (id === 'print') {
    main.appendChild(el('p', 'lede', 'Распечатайте и пишите от руки. Правило групп: каждая разновидность знака — минимум 16 раз (строка А4), «до потери пульса».'));
    var pr = el('button', 'btn noprint', '🖨 Печать');
    pr.onclick = function () { window.print(); };
    main.appendChild(pr);
    main.appendChild(el('h3', null, 'Квадрат 5×5 (спарша)'));
    var grid1 = el('div', 'printgrid');
    for (var i = 0; i < 25; i++) grid1.appendChild(el('div', 'printcell', CONSONANTS[i].dev));
    main.appendChild(grid1);
    main.appendChild(el('h3', null, 'Гласные и матры'));
    var grid2 = el('div', 'printgrid');
    VOWELS.slice(0, 13).forEach(function (v) {
      grid2.appendChild(el('div', 'printcell', v.dev));
      if (v.matra) grid2.appendChild(el('div', 'printcell', 'क' + v.matra));
    });
    main.appendChild(grid2);
    main.appendChild(el('h3', null, 'Лигатуры'));
    var grid3 = el('div', 'printgrid');
    LIGATURES.forEach(function (l) { grid3.appendChild(el('div', 'printcell', l.dev)); });
    main.appendChild(grid3);
    main.appendChild(el('h3', null, 'Слова для прописи'));
    var grid4 = el('div', 'printgrid');
    ['गज', 'धन', 'वन', 'देव', 'राजा', 'सुख', 'दुःख', 'धर्म', 'कृष्ण', 'ज्ञान', 'गङ्गा', 'शास्त्र', 'महाभारत', 'ओम् नमः शिवाय', 'आसीद् राजा नलो नाम'].forEach(function (w) {
      var c = el('div', 'printcell', w);
      if (w.length > 6) { c.style.fontSize = '26px'; }
      grid4.appendChild(c);
    });
    main.appendChild(grid4);
  }
  else if (id === 'about') {
    main.appendChild(el('p', null,
      'Курс собран автоматически-редакционно по стенограммам первых занятий курса <b>«Грамматика санскрита с нуля»</b> Общества ревнителей санскрита:'));
    var t = el('table', 'plain');
    t.innerHTML = '<tr><th>Поток</th><th>Преподаватель</th><th>Период</th><th>Занятий в анализе</th></tr>' +
      '<tr><td>51</td><td>М. Ю. Гасунс</td><td>30.06–25.08.2025</td><td>9</td></tr>' +
      '<tr><td>53</td><td>М. Ю. Гасунс</td><td>22.09–24.11.2025</td><td>9</td></tr>' +
      '<tr><td>60</td><td>М. Ю. Гасунс</td><td>14.07–08.09.2026</td><td>9</td></tr>' +
      '<tr><td>61</td><td>М. Ю. Гасунс</td><td>27.07–21.09.2026</td><td>9 (вкл. «строение алфавита»)</td></tr>' +
      '<tr><td>62</td><td>И. Е. Толчельников</td><td>29.07–16.09.2026</td><td>7 (с 3-го)</td></tr>';
    main.appendChild(t);
    main.appendChild(el('p', null,
      'Итого 56 стенограмм (~510 тысяч слов). Дополнительно — курсы каллиграфии деванагари ОРС (раздел «Техника письма»). ' +
      'Учебник групп — В. А. Кочергина, «Начальный курс санскрита» (занятия 1–5); вспомогательные — Миллер–Кнауэр (вкладыш-лигатуры), конспект А. А. Зализняка.'));
    main.appendChild(el('h3', null, 'Материалы анализа'));
    main.appendChild(el('ul', null,
      '<li>Сводная программа: <code>_analysis/devanagari-course/программа-курса.md</code></li>' +
      '<li>Поурочные отчёты по потокам: <code>_analysis/devanagari-course/отчёты/</code></li>' +
      '<li>Покрытие исходников: <code>_analysis/devanagari-course/покрытие-групп.csv</code></li>'));
    main.appendChild(el('h3', null, 'Интеграция в LMS'));
    main.appendChild(el('ul', null,
      '<li><b>SCORM 1.2</b>: запакуйте папку курса в ZIP с imsmanifest.xml и загрузите как SCORM-пакет. Прогресс и оценка зачёта передаются автоматически (cmi.core.score.raw, lesson_status).</li>' +
      '<li><b>iframe</b>: любой модуль доступен по адресу <code>index.html#/m/N</code> — можно встроить в урок LMS напрямую.</li>' +
      '<li><b>Автономно</b>: прогресс хранится в localStorage браузера.</li>'));
  }
}

/* ---------- инициализация ---------- */
document.addEventListener('DOMContentLoaded', function () {
  main = $('#main');
  sidebar = $('#sidebar');
  $('#burger').onclick = function () { sidebar.classList.toggle('open'); };
  loadState();
  route();
});
})();
