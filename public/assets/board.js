/*
 * Отрисовка сохранённой версии деревьев.
 *
 * Данные приходят с сервера в data-board: раскладку не считаем, она уже
 * лежит в базе. Здесь только расстановка карточек по столбцам и рисование
 * связей между ними.
 */
(function () {
  'use strict';

  var mount = document.getElementById('board');
  if (!mount) return;

  var board = JSON.parse(mount.dataset.board);

  /* Значок эффекта: свой цвет и символ на вид.
     Намеренно не эмодзи — они есть не во всех системах и на части машин
     показываются пустыми прямоугольниками. */
  var EFFECT_ICON = {
    resource:       { s: '\u25CF', c: '#4e8c3f' },
    unit:           { s: '\u25B2', c: '#c1121f' },
    building:       { s: '\u2302', c: '#8a5a33' },
    building_level: { s: '\u2191', c: '#5b3a24' },
    concept:        { s: '\u2726', c: '#7e57c2' },
    card:           { s: '\u25A0', c: '#2f5fa8' },
    resource_rate:  { s: '%',       c: '#d98324' },
    bonus:          { s: '\u271A', c: '#2f8f88' },
  };


  var CSRF = mount.dataset.csrf;
  var VERSION = Number(mount.dataset.version);
  var FOCUS = Number(mount.dataset.focus || 0);
  var COST_BASE = Number(mount.dataset.costBase || 130);
  var COST_STEP = 1.5;

  /** Среднее столбца: база версии, умноженная на 1.5 в степени номера. */
  function columnAverage(col) { return Math.round(COST_BASE * Math.pow(COST_STEP, col)); }
  function money(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

  /* Режим правки связей: {node: id, side: 'out'|'in'}.
     'out' — назначаем, кому эта карточка открывает дорогу (правее),
     'in'  — от кого она сама зависит (левее). */
  var editing = null;
  var views = [];        // по одному на дерево: куда перерисовывать

  board.trees.forEach(function (tree) {
    var section = document.createElement('section');
    section.className = 'tree-board';
    section.innerHTML =
      '<h2>' + escapeHtml(tree.name) + '</h2>' +
      '<div class="board-legend"></div>' +
      '<div class="board-scroll"><div class="board"><svg class="edges"></svg></div></div>';
    mount.appendChild(section);

    var boardEl = section.querySelector('.board');
    var svgEl = section.querySelector('svg.edges');
    views.push({ tree: tree, boardEl: boardEl, svgEl: svgEl });
    var lanesEls = [];

    // легенда по категориям, встречающимся на этой доске
    var branches = {};
    tree.nodes.forEach(function (n) { branches[n.branch] = n.color; });
    section.querySelector('.board-legend').innerHTML = Object.keys(branches).map(function (name) {
      return '<span class="sw"><span class="dot" style="background:' + escapeHtml(branches[name]) +
        '"></span>' + escapeHtml(name) + '</span>';
    }).join('');

    renderColumns(boardEl, svgEl, tree);

    window.addEventListener('resize', function () { drawEdges(boardEl, svgEl, tree.id); });
  });

  if (FOCUS) { focusNode(FOCUS); }

  /* Раскладка столбцов одного дерева. Вызывается заново после правки
     связей: сервер присылает новые позиции, доска перерисовывается
     без перезагрузки страницы. */
  function renderColumns(boardEl, svgEl, tree) {
    var lanesEls = [];
    boardEl.querySelectorAll('.era-col').forEach(function (el) { el.remove(); });

    var globalColumn = 0;
    tree.eras.forEach(function (era, eraIndex) {
      var col = document.createElement('div');
      col.className = 'era-col' + (eraIndex % 2 ? ' alt' : '');
      col.style.setProperty('--lanes', era.lanes);

      var inEra = tree.nodes.filter(function (n) { return n.era_id === era.era_id; });
      var lanesWord = era.lanes === 1 ? 'колонка' : (era.lanes < 5 ? 'колонки' : 'колонок');
      var eraSum = inEra.reduce(function (a, n) { return a + n.cost; }, 0);
      var tools = '';
      for (var c = 0; c < era.lanes; c++) {
        var gcol = globalColumn + c;
        var colSum = inEra.filter(function (n) { return n.col === gcol; })
                          .reduce(function (a, n) { return a + n.cost; }, 0);
        tools +=
          '<div class="col-tool" data-col="' + gcol + '" data-era="' + era.era_id + '">' +
            '<span class="ct-title">Столбец ' + (gcol + 1) + '</span>' +
            '<label class="ct-avg">среднее' +
              '<input type="number" min="1" step="1" value="' + columnAverage(gcol) + '">' +
            '</label>' +
            '<button class="ct-apply" title="Применить среднее ко всем столбцам по коэффициенту 1.5">' +
              'применить' +
            '</button>' +
            '<button class="ct-roll" title="Пересчитать стоимости этого столбца">пересчитать</button>' +
            '<span class="ct-sum">сумма <b>' + money(colSum) + '</b></span>' +
          '</div>';
      }

      col.innerHTML =
        '<div class="era-head">' +
          '<div class="idx">Эпоха ' + (eraIndex + 1) + ' · столбцы ' +
            (globalColumn + 1) + '–' + (globalColumn + era.lanes) + '</div>' +
          '<div class="name">' + escapeHtml(era.name) + '</div>' +
          '<div class="count">' + inEra.length + ' позиций · ' + era.lanes + ' ' + lanesWord + '</div>' +
          '<div class="era-cost">' +
            '<button class="ct-roll era" data-era="' + era.era_id + '">пересчитать эпоху</button>' +
            '<span class="ct-sum">итого <b>' + money(eraSum) + '</b></span>' +
          '</div>' +
        '</div>' +
        '<div class="col-tools" style="grid-template-columns:repeat(' + era.lanes + ', var(--lane-w))">' +
          tools +
        '</div>' +
        '<div class="node-list"></div>';

      var list = col.querySelector('.node-list');
      for (var lane = 0; lane < era.lanes; lane++) {
        var laneEl = document.createElement('div');
        laneEl.className = 'lane';
        inEra.filter(function (n) { return n.lane === lane; })
          .sort(function (a, b) { return a.row - b.row; })
          .forEach(function (n) { laneEl.appendChild(card(n)); });
        lanesEls.push(laneEl);
        list.appendChild(laneEl);
      }

      wireCostTools(col, tree, era);
      globalColumn += era.lanes;
      boardEl.insertBefore(col, svgEl);
    });

    requestAnimationFrame(function () {
      var natural = 0;
      lanesEls.forEach(function (l) { natural = Math.max(natural, l.scrollHeight); });
      var target = Math.round(natural + 40);
      boardEl.querySelectorAll('.node-list').forEach(function (l) { l.style.height = target + 'px'; });
      requestAnimationFrame(function () { drawEdges(boardEl, svgEl, tree.id); });
    });
  }

  /* Кнопки стоимости: пересчёт столбца, пересчёт эпохи и правка среднего.
     Правка среднего меняет базу всей версии, поэтому средние всех столбцов
     едут следом по тому же коэффициенту 1.5. */
  function wireCostTools(col, tree, era) {
    col.querySelectorAll('.col-tool').forEach(function (tool) {
      var gcol = Number(tool.dataset.col);
      tool.querySelector('.ct-roll').addEventListener('click', function () {
        recalc({ scope: 'column', tree_id: tree.id, column: gcol });
      });
      tool.querySelector('.ct-apply').addEventListener('click', function () {
        var value = Number(tool.querySelector('input').value);
        if (!value || value < 1) { alert('Среднее должно быть положительным числом'); return; }
        recalc({ scope: 'version', column: gcol, average: value });
      });
      tool.querySelector('input').addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); tool.querySelector('.ct-apply').click(); }
      });
    });
    var eraBtn = col.querySelector('.ct-roll.era');
    if (eraBtn) {
      eraBtn.addEventListener('click', function () {
        recalc({ scope: 'era', tree_id: tree.id, version_era_id: era.era_id });
      });
    }
  }

  function recalc(params) {
    var body = new URLSearchParams();
    body.set('csrf', CSRF);
    body.set('version_id', String(VERSION));
    Object.keys(params).forEach(function (k) { body.set(k, String(params[k])); });

    fetch('index.php?p=cost-recalc', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { alert(res.error || 'Не удалось пересчитать стоимости'); return; }
      COST_BASE = res.cost_base;
      board.trees.forEach(function (tree) {
        tree.nodes.forEach(function (n) {
          if (res.costs[n.id] !== undefined) n.cost = res.costs[n.id];
        });
      });
      redrawAll();
    }).catch(function () { alert('Сервер недоступен, стоимости не изменены'); });
  }

  function redrawAll() {
    views.forEach(function (v) { renderColumns(v.boardEl, v.svgEl, v.tree); });
  }

  function card(n) {
    var el = document.createElement('div');
    el.className = 'node' + (n.source === 'manual' ? ' manual' : '');
    el.dataset.id = n.id;
    el.style.setProperty('--branch', n.color);

    var list = (n.effects || []);
    var icons = list.map(function (e) {
      var ico = EFFECT_ICON[e.code] || { s: '\u2022', c: '#7a6540' };
      return '<span class="eff" style="--eff:' + ico.c + '" title="' +
        escapeHtml(e.type + ': ' + e.title) + '">' + ico.s + '</span>';
    }).join('');

    // Подробности группируются по виду эффекта: заголовок вида и списком
    // то, что технология даёт игре. Блок прокручивается внутри карточки,
    // поэтому длинный список не растягивает её по высоте.
    var groups = {}, order = [];
    list.forEach(function (e) {
      if (!groups[e.type]) { groups[e.type] = []; order.push(e.type); }
      groups[e.type].push(e.title);
    });
    var info = order.map(function (type) {
      return '<span class="grp">' + escapeHtml(type) + '</span><span class="items">' +
        groups[type].map(function (t) { return '<span>' + escapeHtml(t) + '</span>'; }).join('') +
        '</span>';
    }).join('');

    // вся карточка — одна ссылка: технология открывается кликом в любую точку
    el.innerHTML =
      '<a class="node-link" href="index.php?p=technology&id=' + n.tech_id +
          '&from=' + VERSION + '&node=' + n.id + '">' +
        '<span class="node-art">' +
          (n.image
            ? '<img src="' + escapeHtml(n.image) + '" alt="">'
            : '<span class="node-art-empty">' + escapeHtml(n.name.slice(0, 1)) + '</span>') +
        '</span>' +
        '<span class="node-body">' +
          '<span class="node-name">' +
            '<span class="node-dot" title="' + escapeHtml(n.branch) + '"></span>' +
            escapeHtml(n.name) +
            (n.source === 'manual' ? '<span class="tag-manual">вручную</span>' : '') +
          '</span>' +
          '<span class="node-cost" title="Стоимость технологии">' + n.cost + '</span>' +
          '<span class="node-effects">' + icons + '</span>' +
          '<span class="node-info">' + (info || '<span class="grp muted">эффекты не заданы</span>') + '</span>' +
        '</span>' +
      '</a>' +
      '<button class="edge-btn left" title="Связи с предыдущими столбцами">‹</button>' +
      '<button class="edge-btn right" title="Связи со следующими столбцами">›</button>' +
      '<button class="drop" title="Убрать с доски этой версии">×</button>';

    el.querySelector('.drop').addEventListener('click', function (ev) {
      ev.preventDefault();
      if (!confirm('Убрать «' + n.name + '» с доски этой версии?')) return;
      document.getElementById('remove-node-id').value = n.id;
      document.getElementById('remove-form').submit();
    });
    el.querySelector('.edge-btn.left').addEventListener('click', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      startEditing(n.id, 'in');
    });
    el.querySelector('.edge-btn.right').addEventListener('click', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      startEditing(n.id, 'out');
    });

    // в режиме правки клик по карточке ставит или снимает связь,
    // а не открывает технологию
    el.addEventListener('click', function (ev) {
      if (!editing) return;
      ev.preventDefault();
      if (n.id === editing.node) { stopEditing(); return; }
      if (!el.classList.contains('pickable')) return;
      toggleLink(editing.side === 'out' ? editing.node : n.id,
                 editing.side === 'out' ? n.id : editing.node);
    });

    el.addEventListener('mouseenter', function () { if (!editing) highlight(n.id); });
    el.addEventListener('mouseleave', function () { if (!editing) clearHighlight(); });

    return el;
  }

  /* ---------------- правка связей прямо на доске ---------------- */

  function nodeById(id) {
    for (var i = 0; i < board.trees.length; i++) {
      var found = board.trees[i].nodes.filter(function (n) { return n.id === id; })[0];
      if (found) return { node: found, tree: board.trees[i] };
    }
    return null;
  }

  function startEditing(nodeId, side) {
    if (editing && editing.node === nodeId && editing.side === side) { stopEditing(); return; }
    editing = { node: nodeId, side: side };
    paintEditing();
  }

  function stopEditing() {
    editing = null;
    document.querySelectorAll('#board .node').forEach(function (el) {
      el.classList.remove('editing', 'pickable', 'linked', 'dim');
    });
    document.querySelectorAll('#board .edge-path').forEach(function (p) {
      p.classList.remove('hi', 'dim');
    });
    setBanner(null);
  }

  /* Подсвечиваем, что можно выбрать: при правке исходящих — карточки
     правее, при правке входящих — левее. Уже связанные помечены. */
  function paintEditing() {
    var found = nodeById(editing.node);
    if (!found) return;
    var self = found.node;
    var linked = {};
    board.links.forEach(function (l) {
      if (editing.side === 'out' && l.from === self.id) linked[l.to] = true;
      if (editing.side === 'in' && l.to === self.id) linked[l.from] = true;
    });

    document.querySelectorAll('#board .node').forEach(function (el) {
      var id = Number(el.dataset.id);
      var other = nodeById(id);
      var same = other && other.tree.id === found.tree.id;
      // Выбирать можно и карточки того же столбца: связь заставит их
      // разъехаться — зависимая уедет правее, основа встанет левее,
      // насколько позволяют её собственные основы и границы эпохи.
      var ok = same && id !== self.id;
      el.classList.toggle('editing', id === self.id);
      el.classList.toggle('pickable', !!ok);
      el.classList.toggle('linked', !!linked[id]);
      el.classList.toggle('dim', !ok && id !== self.id);
    });
    document.querySelectorAll('#board .edge-path').forEach(function (p) {
      var rel = Number(p.dataset.src) === self.id || Number(p.dataset.tgt) === self.id;
      p.classList.toggle('hi', rel);
      p.classList.toggle('dim', !rel);
    });

    setBanner('«' + self.name + '»: ' +
      (editing.side === 'out'
        ? 'выберите, каким технологиям она открывает дорогу'
        : 'выберите, от каких технологий она зависит') +
      '. Можно брать и соседей по столбцу — карточки сами разъедутся. ' +
      'Повторный клик снимает связь, Esc — выйти.');
  }

  function setBanner(text) {
    var el = document.getElementById('edit-banner');
    if (!el) return;
    el.textContent = text || '';
    el.classList.toggle('on', !!text);
  }

  function toggleLink(fromId, toId) {
    var body = new URLSearchParams();
    body.set('csrf', CSRF);
    body.set('version_id', String(VERSION));
    body.set('from_node_id', String(fromId));
    body.set('to_node_id', String(toId));

    fetch('index.php?p=link-toggle', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { alert(res.error || 'Не удалось изменить связь'); return; }
      applyState(res);
      paintEditing();
    }).catch(function () { alert('Сервер недоступен, связь не изменена'); });
  }

  /* Новые позиции и связи с сервера: обновляем данные и перерисовываем. */
  function applyState(state) {
    var pos = {};
    state.nodes.forEach(function (n) { pos[n.id] = n; });
    board.trees.forEach(function (tree) {
      tree.nodes.forEach(function (n) {
        var p = pos[n.id];
        if (!p) return;
        n.era_id = p.era_id; n.lane = p.lane; n.row = p.row; n.col = p.col;
      });
    });
    board.links = state.links;
    redrawAll();

    var box = document.getElementById('board-problems');
    if (box) {
      if (state.problems && state.problems.length) {
        box.className = 'flash error';
        box.innerHTML = '<strong>Правило столбцов нарушено:</strong><ul>' +
          state.problems.slice(0, 10).map(function (p) {
            return '<li>' + escapeHtml(p) + '</li>';
          }).join('') + '</ul>';
      } else {
        box.className = 'flash ok';
        box.textContent = 'Правило столбцов соблюдено на всей доске.';
      }
    }
  }

  /* Прокрутка к карточке и вспышка — возврат из страницы технологии. */
  function focusNode(nodeId) {
    var el = document.querySelector('#board .node[data-id="' + nodeId + '"]');
    if (!el) return;
    var scroller = el.closest('.board-scroll');
    if (scroller) {
      scroller.scrollLeft = el.offsetLeft - scroller.clientWidth / 2 + el.offsetWidth / 2;
    }
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.classList.add('focused');
    setTimeout(function () { el.classList.remove('focused'); }, 2600);
  }

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && editing) stopEditing();
  });

  function drawEdges(boardEl, svgEl, treeId) {
    var rect = boardEl.getBoundingClientRect();
    var w = boardEl.scrollWidth;
    var h = Math.max(boardEl.scrollHeight, boardEl.clientHeight);
    svgEl.setAttribute('width', w);
    svgEl.setAttribute('height', h);
    svgEl.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
    svgEl.innerHTML =
      '<defs><marker id="arrow-' + treeId + '" viewBox="0 0 8 8" refX="7" refY="4" ' +
      'markerWidth="6" markerHeight="6" orient="auto-start-reverse">' +
      '<path d="M 0 0 L 8 4 L 0 8 z" fill="currentColor"></path></marker></defs>';

    var frag = document.createDocumentFragment();
    board.links.forEach(function (link) {
      if (link.tree !== treeId) return;
      var src = boardEl.querySelector('.node[data-id="' + link.from + '"]');
      var dst = boardEl.querySelector('.node[data-id="' + link.to + '"]');
      if (!src || !dst) return;

      var s = src.getBoundingClientRect();
      var t = dst.getBoundingClientRect();
      var sx = s.right - rect.left, sy = s.top - rect.top + s.height / 2;
      var tx = t.left - rect.left, ty = t.top - rect.top + t.height / 2;
      var dx = Math.max(10, Math.min(110, (tx - sx) * 0.45));

      var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', 'M ' + sx + ' ' + sy + ' C ' + (sx + dx) + ' ' + sy + ', ' +
        (tx - dx) + ' ' + ty + ', ' + tx + ' ' + ty);
      path.setAttribute('class', 'edge-path' + (link.origin === 'manual' ? ' manual' : ''));
      path.setAttribute('marker-end', 'url(#arrow-' + treeId + ')');
      path.dataset.src = link.from;
      path.dataset.tgt = link.to;
      frag.appendChild(path);
    });
    svgEl.appendChild(frag);
  }

  /* Подсветка связей на три уровня в обе стороны: видно не только
     соседей, но и то, что открывается через них дальше. */
  var HIGHLIGHT_DEPTH = 3;

  function highlight(nodeId) {
    var back = {}, fwd = {};
    board.links.forEach(function (l) {
      (fwd[l.from] = fwd[l.from] || []).push(l.to);
      (back[l.to] = back[l.to] || []).push(l.from);
    });

    var level = {};
    level[nodeId] = 0;
    [fwd, back].forEach(function (graph) {
      var wave = [nodeId];
      for (var depth = 1; depth <= HIGHLIGHT_DEPTH && wave.length; depth++) {
        var next = [];
        wave.forEach(function (id) {
          (graph[id] || []).forEach(function (other) {
            if (level[other] === undefined) { level[other] = depth; next.push(other); }
          });
        });
        wave = next;
      }
    });

    document.querySelectorAll('#board .node').forEach(function (el) {
      var lv = level[Number(el.dataset.id)];
      el.classList.toggle('hi', lv !== undefined);
      el.classList.toggle('dim', lv === undefined);
      if (lv === undefined) { el.removeAttribute('data-level'); } else { el.dataset.level = lv; }
    });
    document.querySelectorAll('#board .edge-path').forEach(function (p) {
      var a = level[Number(p.dataset.src)], b = level[Number(p.dataset.tgt)];
      var rel = a !== undefined && b !== undefined;
      p.classList.toggle('hi', rel);
      p.classList.toggle('dim', !rel);
    });
  }

  function clearHighlight() {
    document.querySelectorAll('#board .node').forEach(function (n) {
      n.classList.remove('hi', 'dim');
      n.removeAttribute('data-level');
    });
    document.querySelectorAll('#board .edge-path').forEach(function (p) { p.classList.remove('hi', 'dim'); });
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
