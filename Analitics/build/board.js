/* Отрисовка статических досок. Алгоритм тот же, что в public/assets/board.js:
   колонки эпох, дорожки внутри эпохи, кривые Безье между карточками и
   подсветка связей при наведении. Правки на доске здесь не нужны — разбор
   показывает готовое решение, а не редактируется. */
(function () {
  "use strict";

  var byId = {};
  var incoming = {};   // код -> кто является основанием
  var outgoing = {};   // код -> кому открывает дорогу

  TREES.forEach(function (tree) {
    tree.nodes.forEach(function (n) { byId[n.id] = n; });
    tree.links.forEach(function (l) {
      (outgoing[l.from] = outgoing[l.from] || []).push(l.to);
      (incoming[l.to] = incoming[l.to] || []).push(l.from);
    });
  });

  function esc(s) {
    return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  /* ---------------- вкладки ---------------- */

  var tabs = document.getElementById("tabs");
  TREES.forEach(function (tree, i) {
    var b = document.createElement("button");
    b.dataset.tree = tree.code;
    b.textContent = (tree.code === "science" ? "Научное дерево" : "Социальное дерево") +
      " · " + tree.nodes.length;
    b.addEventListener("click", function () { show(tree.code); });
    tabs.appendChild(b);
    document.getElementById("board-" + tree.code).hidden = i !== 0;
  });

  function show(code) {
    TREES.forEach(function (t) {
      document.getElementById("board-" + t.code).hidden = t.code !== code;
    });
    Array.prototype.forEach.call(tabs.children, function (b) {
      b.classList.toggle("on", b.dataset.tree === code);
    });
    // Высоту дорожек можно измерить только у видимой доски: у скрытой все
    // размеры нулевые. Поэтому подгонка выполняется при каждом показе.
    var view = views.filter(function (v) { return v.tree.code === code; })[0];
    if (view) { requestAnimationFrame(function () { fit(view); }); }
  }

  function fit(view) {
    var natural = 0;
    view.laneEls.forEach(function (l) {
      l.parentNode.style.height = "auto";
    });
    view.laneEls.forEach(function (l) {
      natural = Math.max(natural, l.scrollHeight);
    });
    if (!natural) { return; }
    view.boardEl.querySelectorAll(".node-list").forEach(function (l) {
      l.style.height = natural + "px";
    });
    requestAnimationFrame(function () { draw(view); });
  }

  /* ---------------- доски ---------------- */

  var views = [];

  TREES.forEach(function (tree) {
    var section = document.getElementById("board-" + tree.code);
    var boardEl = section.querySelector(".board");
    var svgEl = section.querySelector("svg.edges");

    section.querySelector(".board-legend").innerHTML = tree.cats.map(function (c) {
      return '<span class="sw"><span class="dot" style="background:' + c.color +
             '"></span>' + esc(c.name) + "</span>";
    }).join("");

    var view = { tree: tree, boardEl: boardEl, svgEl: svgEl };
    views.push(view);
    renderColumns(view);
  });

  window.addEventListener("resize", function () {
    views.forEach(function (v) {
      if (!document.getElementById("board-" + v.tree.code).hidden) { draw(v); }
    });
  });

  function renderColumns(view) {
    var tree = view.tree, boardEl = view.boardEl;
    var laneEls = [];
    var globalColumn = 0;

    tree.eras.forEach(function (era, eraIndex) {
      var col = document.createElement("div");
      col.className = "era-col" + (eraIndex % 2 ? " alt" : "");
      col.style.setProperty("--lanes", era.lanes);

      var inEra = tree.nodes.filter(function (n) { return n.era === era.id; });
      var word = era.lanes === 1 ? "колонка" : (era.lanes < 5 ? "колонки" : "колонок");

      col.innerHTML =
        '<div class="era-head">' +
          '<div class="idx">Эпоха ' + (eraIndex + 1) + " · столбцы " +
            (globalColumn + 1) + "–" + (globalColumn + era.lanes) + "</div>" +
          '<div class="name">' + esc(era.name) + "</div>" +
          '<div class="count">' + era.count + " технологий · " + era.lanes + " " + word +
            " · " + esc(era.span) + "</div>" +
        "</div>" +
        '<div class="node-list"></div>';

      var list = col.querySelector(".node-list");
      for (var lane = 0; lane < era.lanes; lane++) {
        var laneEl = document.createElement("div");
        laneEl.className = "lane";
        inEra.filter(function (n) { return n.lane === lane; })          // eslint-disable-line
             .sort(function (a, b) { return a.row - b.row; })
             .forEach(function (n) { laneEl.appendChild(card(n)); });
        laneEls.push(laneEl);
        list.appendChild(laneEl);
      }

      boardEl.appendChild(col);
      globalColumn += era.lanes;
    });

    boardEl.appendChild(view.svgEl);

    // все дорожки выравниваются по самой высокой, чтобы карточки
    // распределялись по вертикали и линии связей шли ровнее
    view.laneEls = laneEls;
    requestAnimationFrame(function () { fit(view); });
  }

  function card(n) {
    var el = document.createElement("div");
    el.className = "node";
    el.tabIndex = 0;
    el.dataset.id = n.id;
    el.style.setProperty("--branch", n.color);
    el.innerHTML =
      '<span class="node-name"><span class="node-dot"></span>' + esc(n.name) + "</span>" +
      '<span class="node-cat">' + esc(n.cat) + "</span>" +
      '<span class="node-desc">' + esc(n.desc) + "</span>";

    el.addEventListener("click", function () { openSheet(n.id); });
    el.addEventListener("keydown", function (ev) {
      if (ev.key === "Enter" || ev.key === " ") { ev.preventDefault(); openSheet(n.id); }
    });
    el.addEventListener("mouseenter", function () { highlight(n.id); });
    el.addEventListener("mouseleave", clearHighlight);
    return el;
  }

  function draw(view) {
    var boardEl = view.boardEl, svgEl = view.svgEl;
    var rect = boardEl.getBoundingClientRect();
    var w = boardEl.scrollWidth;
    var h = Math.max(boardEl.scrollHeight, boardEl.clientHeight);
    svgEl.setAttribute("width", w);
    svgEl.setAttribute("height", h);
    svgEl.setAttribute("viewBox", "0 0 " + w + " " + h);
    svgEl.innerHTML =
      '<defs><marker id="arrow-' + view.tree.code + '" viewBox="0 0 8 8" refX="7" refY="4" ' +
      'markerWidth="6" markerHeight="6" orient="auto-start-reverse">' +
      '<path d="M 0 0 L 8 4 L 0 8 z" fill="currentColor"></path></marker></defs>';

    var frag = document.createDocumentFragment();
    view.tree.links.forEach(function (link) {
      var src = boardEl.querySelector('.node[data-id="' + link.from + '"]');
      var dst = boardEl.querySelector('.node[data-id="' + link.to + '"]');
      if (!src || !dst) { return; }
      var s = src.getBoundingClientRect(), t = dst.getBoundingClientRect();
      var sx = s.right - rect.left, sy = s.top - rect.top + s.height / 2;
      var tx = t.left - rect.left, ty = t.top - rect.top + t.height / 2;
      var dx = Math.max(10, Math.min(110, (tx - sx) * 0.45));
      var path = document.createElementNS("http://www.w3.org/2000/svg", "path");
      path.setAttribute("d", "M " + sx + " " + sy + " C " + (sx + dx) + " " + sy + ", " +
        (tx - dx) + " " + ty + ", " + tx + " " + ty);
      path.setAttribute("class", "edge-path");
      path.setAttribute("marker-end", "url(#arrow-" + view.tree.code + ")");
      path.dataset.src = link.from;
      path.dataset.tgt = link.to;
      frag.appendChild(path);
    });
    svgEl.appendChild(frag);
  }

  /* ---------------- подсветка связей ---------------- */

  function highlight(id) {
    var keep = {};
    keep[id] = true;
    (incoming[id] || []).forEach(function (c) { keep[c] = true; });
    (outgoing[id] || []).forEach(function (c) { keep[c] = true; });

    document.querySelectorAll(".node").forEach(function (el) {
      el.classList.toggle("hi", !!keep[el.dataset.id]);
      el.classList.toggle("dim", !keep[el.dataset.id]);
    });
    document.querySelectorAll(".edge-path").forEach(function (p) {
      var on = p.dataset.src === id || p.dataset.tgt === id;
      p.classList.toggle("hi", on);
      p.classList.toggle("dim", !on);
    });
  }

  function clearHighlight() {
    document.querySelectorAll(".node").forEach(function (el) {
      el.classList.remove("hi", "dim");
    });
    document.querySelectorAll(".edge-path").forEach(function (p) {
      p.classList.remove("hi", "dim");
    });
  }

  /* ---------------- справка по технологии ---------------- */

  var overlay = document.getElementById("overlay");
  var sheet = document.getElementById("sheet");

  function eraName(code) {
    for (var i = 0; i < TREES.length; i++) {
      var found = TREES[i].eras.filter(function (e) { return e.id === code; })[0];
      if (found) { return found.name; }
    }
    return code;
  }

  function listOf(codes) {
    if (!codes || !codes.length) { return "<li class=\"era\">—</li>"; }
    return codes.map(function (c) {
      var n = byId[c];
      if (!n) { return ""; }
      return "<li><b>" + esc(n.name) + "</b> <span class=\"era\">· " +
             esc(eraName(n.era)) + "</span></li>";
    }).join("");
  }

  function openSheet(id) {
    var n = byId[id];
    if (!n) { return; }
    sheet.style.setProperty("--branch", n.color);
    sheet.innerHTML =
      "<h3>" + esc(n.name) + "</h3>" +
      '<div class="meta">' +
        '<span class="chip">' + esc(eraName(n.era)) + "</span>" +
        '<span class="chip">' + esc(n.cat) + "</span>" +
        '<span class="chip">столбец ' + (n.col + 1) + "</span>" +
      "</div>" +
      '<p class="sum">' + esc(n.desc) + "</p>" +
      '<div class="note">' + n.note.split("\n\n").map(function (p) {
        return "<p>" + esc(p) + "</p>";
      }).join("") + "</div>" +
      '<div class="rel">' +
        "<div><h4>Опирается на</h4><ul>" + listOf(incoming[id]) + "</ul></div>" +
        "<div><h4>Открывает дорогу</h4><ul>" + listOf(outgoing[id]) + "</ul></div>" +
      "</div>" +
      '<button class="close" type="button">Закрыть</button>';
    sheet.querySelector(".close").addEventListener("click", closeSheet);
    overlay.classList.add("open");
    sheet.querySelector(".close").focus();
  }

  function closeSheet() { overlay.classList.remove("open"); }

  overlay.addEventListener("click", function (ev) {
    if (ev.target === overlay) { closeSheet(); }
  });
  document.addEventListener("keydown", function (ev) {
    if (ev.key === "Escape") { closeSheet(); }
  });

  show(TREES[0].code);
})();
