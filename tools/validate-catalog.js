#!/usr/bin/env node
/*
 * Проверка источников каталога перед сборкой миграции.
 *
 *   node tools/validate-catalog.js
 *
 * Каталог задаёт не только состав технологий, но и готовую раскладку
 * базовой пары деревьев, поэтому проверяются обе стороны:
 *
 *   1. ссылочная целостность — эпохи, категории и концы связей существуют,
 *      коды технологий уникальны внутри дерева;
 *   2. раскладка — столбцов у эпохи хватает на все её карточки, в каждом
 *      столбце кто-то есть, номера строк внутри столбца не повторяются;
 *   3. правило сквозных столбцов — связи идут строго слева направо,
 *      технология без основ возможна только в самом первом столбце,
 *      у остальных есть основа в непосредственно предыдущем столбце
 *      (для первого столбца эпохи правило смягчено — это норма);
 *   4. стоимости — при заданных базе и коэффициентах диапазоны соседних
 *      столбцов не пересекаются, а самый дорогой столбец не упирается
 *      в потолок стоимости.
 *
 * Выход 1, если нашлась хоть одна проблема.
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const catalogDir = path.join(root, 'db', 'catalog');

const COST_BASE = 60;
const COST_STEP_LANE = 1.1;
const COST_STEP_ERA = 1.5;
const COST_JITTER = 0.15;
const COST_MAX = 1000000000000000;
const jitterFor = (laneStep, eraStep) => {
  const step = Math.min(laneStep, eraStep);
  return Math.max(0, Math.min(COST_JITTER, (step - 1) / (step + 1) * 0.9));
};

const readLines = f => fs.readFileSync(path.join(catalogDir, f), 'utf8')
  .split('\n').map(l => l.trim()).filter(l => l && !l.startsWith('#'));

const problems = [];
const note = (...parts) => problems.push(parts.join(' '));

// ---- источники -------------------------------------------------------
const eras = readLines('eras.txt').map(l => {
  const [code, name, period] = l.split('|');
  return { code, name, period };
});
const eraByCode = new Map(eras.map(e => [e.code, e]));

const categories = new Map();   // 'science:energy' => название
readLines('categories.txt').forEach(l => {
  const [tree, code, name, color] = l.split('|');
  if (!/^#[0-9a-fA-F]{6}$/.test(color || '')) note('категория', tree + '/' + code, 'без цвета');
  categories.set(tree + ':' + code, name);
});

const catalog = JSON.parse(fs.readFileSync(path.join(catalogDir, 'trees.json'), 'utf8'));

if (catalog.eras.length !== eras.length) {
  note('в trees.json', catalog.eras.length, 'эпох, в eras.txt —', eras.length);
}

// ---- по дереву -------------------------------------------------------
let totalNodes = 0;
let totalLinks = 0;
let maxColumn = 0;
let maxFactor = 1;

catalog.trees.forEach(tree => {
  const byCode = new Map();
  const seenName = new Map();

  // сквозная нумерация столбцов
  let offset = 0;
  const firstColumn = {};
  eras.forEach(era => {
    const lanes = tree.lanes[era.code];
    if (!lanes) { note(tree.code + ':', 'нет числа столбцов у эпохи', era.code); return; }
    firstColumn[era.code] = offset;
    offset += lanes;
  });
  maxColumn = Math.max(maxColumn, offset - 1);

  // множитель самого правого столбца: во что обходится конец дерева
  let factor = 1;
  eras.forEach((era, eraIndex) => {
    for (let lane = 0; lane < (tree.lanes[era.code] || 0); lane++) {
      if (eraIndex === 0 && lane === 0) factor = 1;
      else if (lane === 0) factor *= COST_STEP_ERA;
      else factor *= COST_STEP_LANE;
    }
  });
  maxFactor = Math.max(maxFactor, factor);

  tree.nodes.forEach(n => {
    if (byCode.has(n.code)) note(tree.code + ':', 'повтор кода', n.code);
    if (seenName.has(n.name)) note(tree.code + ':', 'повтор названия «' + n.name + '»');
    byCode.set(n.code, n);
    seenName.set(n.name, true);

    if (!eraByCode.has(n.era)) note(tree.code + '/' + n.code + ':', 'неизвестная эпоха', n.era);
    if (!categories.has(tree.code + ':' + n.category)) {
      note(tree.code + '/' + n.code + ':', 'неизвестная категория', n.category);
    }
    if (!n.description) note(tree.code + '/' + n.code + ':', 'нет описания');
    if (!n.historical_note) note(tree.code + '/' + n.code + ':', 'нет исторической справки');

    const lanes = tree.lanes[n.era] || 0;
    if (n.lane < 0 || n.lane >= lanes) {
      note(tree.code + '/' + n.code + ':', 'столбец', n.lane, 'вне эпохи', n.era, '(' + lanes + ')');
    }
    n.column = (firstColumn[n.era] || 0) + n.lane;
  });
  totalNodes += tree.nodes.length;

  // столбцы: пустых нет, строки внутри столбца не повторяются
  const inColumn = new Map();
  tree.nodes.forEach(n => {
    if (!inColumn.has(n.column)) inColumn.set(n.column, []);
    inColumn.get(n.column).push(n);
  });
  for (let c = 0; c < offset; c++) {
    if (!inColumn.has(c)) note(tree.code + ':', 'столбец', c + 1, 'пуст');
  }
  for (const [column, list] of inColumn) {
    const rows = new Set();
    list.forEach(n => {
      if (rows.has(n.row)) note(tree.code + ':', 'в столбце', column + 1, 'две карточки в строке', n.row);
      rows.add(n.row);
    });
  }

  // связи и правило столбцов
  const incoming = new Map();
  tree.links.forEach(([from, to]) => {
    const a = byCode.get(from);
    const b = byCode.get(to);
    if (!a) { note(tree.code + ':', 'связь от несуществующей', from); return; }
    if (!b) { note(tree.code + ':', 'связь к несуществующей', to); return; }
    if (a.column >= b.column) {
      note(tree.code + ':', 'связь', from, '→', to, 'идёт не слева направо');
    }
    if (!incoming.has(to)) incoming.set(to, []);
    incoming.get(to).push(a);
  });
  totalLinks += tree.links.length;

  tree.nodes.forEach(n => {
    const sources = incoming.get(n.code) || [];
    if (sources.length === 0) {
      if (n.column !== 0) note(tree.code + '/' + n.code + ':', 'нет основ, а столбец', n.column + 1);
      return;
    }
    if (n.lane === 0) return;   // первый столбец эпохи — правило смягчено
    if (!sources.some(s => s.column === n.column - 1)) {
      note(tree.code + '/' + n.code + ':', 'нет основы в предыдущем столбце');
    }
  });

  const counts = eras.map(e => tree.nodes.filter(n => n.era === e.code).length);
  console.log(tree.code.padEnd(8),
    'технологий', String(tree.nodes.length).padStart(3),
    '| связей', String(tree.links.length).padStart(4),
    '| столбцов', String(offset).padStart(2),
    '| по эпохам', counts.join(' '));
});

// ---- стоимости -------------------------------------------------------
const jitter = jitterFor(COST_STEP_LANE, COST_STEP_ERA);
const top = COST_BASE * maxFactor * (1 + jitter);
if (top > COST_MAX) {
  note('при базе', COST_BASE, 'и коэффициентах', COST_STEP_LANE + '/' + COST_STEP_ERA,
    'последний столбец стоит', Math.round(top), '— больше потолка', COST_MAX);
}
if (Math.min(COST_STEP_LANE, COST_STEP_ERA) * (1 - jitter) <= 1 + jitter) {
  note('при коэффициентах', COST_STEP_LANE + '/' + COST_STEP_ERA, 'и разбросе', jitter.toFixed(3),
    'диапазоны соседних столбцов пересекаются');
}

console.log('---');
console.log('эпох', eras.length, '| категорий', categories.size,
  '| технологий', totalNodes, '| связей', totalLinks,
  '| разброс ±' + (jitter * 100).toFixed(1) + '%',
  '| самый правый столбец', maxColumn + 1,
  '| его стоимость до', Math.round(top));

if (problems.length === 0) {
  console.log('проблем нет');
  process.exit(0);
}
console.log('проблем:', problems.length);
problems.slice(0, 40).forEach(p => console.log('  •', p));
if (problems.length > 40) console.log('  …и ещё', problems.length - 40);
process.exit(1);
