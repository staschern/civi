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

  /* Значок эффекта: по одному на каждый эффект технологии. */
  var EFFECT_ICON = {
    resource: '🪨', unit: '⚔️', building: '🏛️', building_level: '⬆️',
    concept: '💡', card: '🃏', resource_rate: '⚡', bonus: '✨',
  };


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
    var lanesEls = [];

    // легенда по категориям, встречающимся на этой доске
    var branches = {};
    tree.nodes.forEach(function (n) { branches[n.branch] = n.color; });
    section.querySelector('.board-legend').innerHTML = Object.keys(branches).map(function (name) {
      return '<span class="sw"><span class="dot" style="background:' + escapeHtml(branches[name]) +
        '"></span>' + escapeHtml(name) + '</span>';
    }).join('');

    var globalColumn = 0;
    tree.eras.forEach(function (era, eraIndex) {
      var col = document.createElement('div');
      col.className = 'era-col' + (eraIndex % 2 ? ' alt' : '');
      col.style.setProperty('--lanes', era.lanes);

      var inEra = tree.nodes.filter(function (n) { return n.era_id === era.era_id; });
      var lanesWord = era.lanes === 1 ? 'колонка' : (era.lanes < 5 ? 'колонки' : 'колонок');
      col.innerHTML =
        '<div class="era-head">' +
          '<div class="idx">Эпоха ' + (eraIndex + 1) + ' · столбцы ' +
            (globalColumn + 1) + '–' + (globalColumn + era.lanes) + '</div>' +
          '<div class="name">' + escapeHtml(era.name) + '</div>' +
          '<div class="count">' + inEra.length + ' позиций · ' + era.lanes + ' ' + lanesWord + '</div>' +
        '</div><div class="node-list"></div>';

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

      globalColumn += era.lanes;
      boardEl.appendChild(col);
    });

    boardEl.appendChild(svgEl);

    // столбцы тянем на одну высоту, карточки расходятся по вертикали
    requestAnimationFrame(function () {
      var natural = 0;
      lanesEls.forEach(function (l) { natural = Math.max(natural, l.scrollHeight); });
      var target = Math.round(natural + 40);
      boardEl.querySelectorAll('.node-list').forEach(function (l) { l.style.height = target + 'px'; });
      requestAnimationFrame(function () { drawEdges(boardEl, svgEl, tree.id); });
    });

    window.addEventListener('resize', function () { drawEdges(boardEl, svgEl, tree.id); });
  });

  function card(n) {
    var el = document.createElement('div');
    el.className = 'node' + (n.source === 'manual' ? ' manual' : '');
    el.dataset.id = n.id;
    el.style.setProperty('--branch', n.color);

    var effects = (n.effects || []).map(function (e) {
      return '<span class="eff" title="' + escapeHtml(e.type + ': ' + e.title) + '">' +
        (EFFECT_ICON[e.code] || '•') + '</span>';
    }).join('');

    // Порядок в правой части сверху вниз: название, стоимость, эффекты.
    // Вся карточка — одна ссылка, поэтому технология открывается кликом
    // в любую точку, а не только по названию.
    el.innerHTML =
      '<a class="node-link" href="index.php?p=technology&id=' + n.tech_id + '">' +
        '<span class="node-art">' +
          (n.image
            ? '<img src="' + escapeHtml(n.image) + '" alt="">'
            : '<span class="node-art-empty">' + escapeHtml(n.name.slice(0, 1)) + '</span>') +
        '</span>' +
        '<span class="node-body">' +
          '<span class="node-head">' +
            '<span class="node-dot" title="' + escapeHtml(n.branch) + '"></span>' +
            '<span class="node-name">' + escapeHtml(n.name) + '</span>' +
            (n.source === 'manual' ? '<span class="tag-manual">вручную</span>' : '') +
          '</span>' +
          '<span class="node-cost" title="Стоимость технологии">' +
            '<span class="node-cost-icon"></span>' + n.cost +
          '</span>' +
          '<span class="node-effects">' + (effects || '<span class="eff-none">нет эффектов</span>') + '</span>' +
        '</span>' +
      '</a>' +
      '<button class="drop" title="Убрать с доски этой версии">×</button>';

    el.querySelector('.drop').addEventListener('click', function (ev) {
      ev.preventDefault();
      if (!confirm('Убрать «' + n.name + '» с доски этой версии?')) return;
      document.getElementById('remove-node-id').value = n.id;
      document.getElementById('remove-form').submit();
    });
    el.addEventListener('mouseenter', function () { highlight(el, n.id); });
    el.addEventListener('mouseleave', clearHighlight);

    return el;
  }

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

  function highlight(el, nodeId) {
    var related = {};
    related[nodeId] = true;
    board.links.forEach(function (l) {
      if (l.from === nodeId) related[l.to] = true;
      if (l.to === nodeId) related[l.from] = true;
    });
    document.querySelectorAll('#board .node').forEach(function (node) {
      var isRelated = related[Number(node.dataset.id)];
      node.classList.toggle('hi', !!isRelated);
      node.classList.toggle('dim', !isRelated);
    });
    document.querySelectorAll('#board .edge-path').forEach(function (p) {
      var isRelated = Number(p.dataset.src) === nodeId || Number(p.dataset.tgt) === nodeId;
      p.classList.toggle('hi', isRelated);
      p.classList.toggle('dim', !isRelated);
    });
  }

  function clearHighlight() {
    document.querySelectorAll('#board .node').forEach(function (n) { n.classList.remove('hi', 'dim'); });
    document.querySelectorAll('#board .edge-path').forEach(function (p) { p.classList.remove('hi', 'dim'); });
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
