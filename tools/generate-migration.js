#!/usr/bin/env node
/*
 * Сборка миграции 0001 из источников каталога:
 *   db/schema/0001_schema.sql   — схема, правится руками;
 *   db/catalog/eras.txt         — эпохи и их исторические рамки;
 *   db/catalog/categories.txt   — категории технологий с цветами;
 *   db/catalog/trees.json       — сами деревья: технологии с описанием
 *                                 и исторической справкой, авторские связи
 *                                 и готовая раскладка по столбцам.
 *
 * Запуск из корня репозитория:
 *   node tools/generate-migration.js
 *
 * Результат перезаписывает db/migrations/0001_create_tech_tree_versions.sql.
 * Руками этот файл не правим — правим источники и пересобираем.
 *
 * Кроме каталога миграция кладёт в базу готовую версию №1 — ту самую пару
 * деревьев из db/catalog/trees.json со всеми связями, столбцами и уже
 * посчитанными стоимостями. Поэтому сразу после наката админке есть
 * что показать, генерировать ничего не нужно.
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const catalogDir = path.join(root, 'db', 'catalog');
const schemaPath = path.join(root, 'db', 'schema', '0001_schema.sql');
const outPath = path.join(root, 'db', 'migrations', '0001_create_tech_tree_versions.sql');

const TREES = [
  { id: 1, code: 'science', name: 'Дерево технологий', points: 'Наука', boost: 'Озарения' },
  { id: 2, code: 'culture', name: 'Дерево социальных концепций', points: 'Культура', boost: 'Вдохновения' },
];

/* Стоимость технологии определяется её столбцом: средняя стоимость первого
   столбца — COST_BASE, следующий столбец внутри эпохи дороже в COST_STEP_LANE
   раз, первый столбец новой эпохи — в COST_STEP_ERA раз. Внутри столбца
   стоимости расходятся на ±jitter; разброс считается от меньшего из шагов
   так, чтобы диапазоны соседних столбцов не пересекались — те же формулы,
   что в VersionRepository::jitterFor() и columnFactors(). */
const COST_BASE = 60;
const COST_STEP_LANE = 1.1;
const COST_STEP_ERA = 1.5;
const COST_JITTER = 0.15;
const COST_MAX = 1000000000000000;
const jitterFor = (laneStep, eraStep) => {
  const step = Math.min(laneStep, eraStep);
  return Math.max(0, Math.min(COST_JITTER, (step - 1) / (step + 1) * 0.9));
};
const columnFactors = (laneCounts, laneStep, eraStep) => {
  const factors = [];
  let value = 1;
  laneCounts.forEach((lanes, eraIndex) => {
    for (let lane = 0; lane < Math.max(1, lanes); lane++) {
      if (eraIndex === 0 && lane === 0) value = 1;
      else if (lane === 0) value *= eraStep;
      else value *= laneStep;
      factors.push(value);
    }
  });
  return factors;
};

/* Семя версии №1. Раскладка у неё авторская, а не считанная по семени,
   но поля семени в схеме обязательные — берём приметное. */
const BASE_VERSION = {
  id: 1,
  name: 'Базовые деревья',
  seedCode: '0001-0001-0001',
  seedNumbers: [1, 1, 1],
};

const EFFECT_TYPES = [
  { code: 'resource', name: 'Новый ресурс',
    description: 'Открывает добычу или использование ресурса',
    schema: { resource_code: 'string', base_rate: 'number' } },
  { code: 'unit', name: 'Новый юнит',
    description: 'Даёт доступ к боевому или гражданскому юниту',
    schema: { unit_code: 'string', replaces_unit_code: 'string?' } },
  { code: 'building', name: 'Новое здание',
    description: 'Открывает постройку здания',
    schema: { building_code: 'string' } },
  { code: 'building_level', name: 'Уровень здания',
    description: 'Открывает следующий уровень уже доступного здания',
    schema: { building_code: 'string', level: 'number' } },
  { code: 'concept', name: 'Игровая концепция',
    description: 'Вводит механику: торговля, дипломатия, шпионаж и т.п.',
    schema: { concept_code: 'string' } },
  { code: 'card', name: 'Карточка',
    description: 'Добавляет карточку в колоду игрока',
    schema: { card_code: 'string', slot: 'string?' } },
  { code: 'resource_rate', name: 'Ускорение добычи',
    description: 'Меняет скорость добычи ресурса',
    schema: { resource_code: 'string', percent: 'number' } },
  { code: 'bonus', name: 'Пассивный бонус',
    description: 'Постоянный эффект без отдельного объекта в игре',
    schema: { target: 'string', percent: 'number' } },
];

const q = s => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "''") + "'";
const nullable = s => (s === undefined || s === null || s === '') ? 'NULL' : q(s);
const rows = list => list.map(r => '  (' + r.join(', ') + ')').join(',\n');

const readLines = f => fs.readFileSync(path.join(catalogDir, f), 'utf8')
  .split('\n').map(l => l.trim()).filter(l => l && !l.startsWith('#'));

/* Тот же генератор псевдослучайных чисел, что в app/Rng.php: одно семя —
   одни и те же стоимости, поэтому кнопка «пересчитать» в админке даёт
   ровно то, что уже лежит в миграции. */
function mulberry32(seed) {
  let a = seed >>> 0;
  return function () {
    a = (a + 0x6D2B79F5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1) >>> 0;
    t = (t ^ (t + Math.imul(t ^ (t >>> 7), t | 61))) >>> 0;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const JITTER = jitterFor(COST_STEP_LANE, COST_STEP_ERA);
const costFor = (factor, rnd) => Math.max(1, Math.min(COST_MAX, Math.round(
  COST_BASE * factor * (1 + (rnd() * 2 - 1) * JITTER)
)));

// ---- эпохи -----------------------------------------------------------
const eras = readLines('eras.txt').map((line, i) => {
  const [code, name, period] = line.split('|');
  return { id: i + 1, code, name, period, position: i + 1 };
});
const eraId = new Map(eras.map(e => [e.code, e.id]));

// ---- категории -------------------------------------------------------
const treeIdByCode = Object.fromEntries(TREES.map(t => [t.code, t.id]));
const branches = [];
const branchId = new Map();
readLines('categories.txt').forEach(line => {
  const [tree, code, name, color] = line.split('|');
  if (!treeIdByCode[tree]) throw new Error('неизвестное дерево ' + tree);
  const id = branches.length + 1;
  branches.push({ id, tree: treeIdByCode[tree], code, name, color, position: id });
  branchId.set(tree + ':' + code, id);
});

// ---- технологии и авторские связи ------------------------------------
const catalog = JSON.parse(fs.readFileSync(path.join(catalogDir, 'trees.json'), 'utf8'));
const techs = [];
const techId = new Map();       // 'science:tools' => id технологии
const prereqs = [];             // [id технологии, id основы]
const board = [];               // карточки версии №1
const lanes = [];               // сколько столбцов у эпохи в каждом дереве
const factorsByTree = {};       // множители стоимости по столбцам дерева

catalog.trees.forEach(tree => {
  const treeId = treeIdByCode[tree.code];
  if (!treeId) throw new Error('неизвестное дерево ' + tree.code);

  // сквозная нумерация столбцов: смещение эпохи + столбец внутри эпохи
  let offset = 0;
  const firstColumn = {};
  const treeLanes = [];
  eras.forEach(era => {
    const count = tree.lanes[era.code];
    if (!count) throw new Error('нет числа столбцов у ' + tree.code + '/' + era.code);
    firstColumn[era.code] = offset;
    offset += count;
    treeLanes.push(count);
    lanes.push({ tree: treeId, era: era.id, lanes: count });
  });
  factorsByTree[treeId] = columnFactors(treeLanes, COST_STEP_LANE, COST_STEP_ERA);

  tree.nodes.forEach(node => {
    if (!eraId.has(node.era)) throw new Error('неизвестная эпоха ' + node.era + ' у ' + node.code);
    if (!branchId.has(tree.code + ':' + node.category)) {
      throw new Error('нет категории ' + tree.code + '/' + node.category);
    }
    const code = (tree.code === 'science' ? 't_' : 'c_') + node.code;
    if (techId.has(tree.code + ':' + node.code)) throw new Error('повтор кода ' + code);

    const id = techs.length + 1;
    techId.set(tree.code + ':' + node.code, id);
    techs.push({
      id, tree: treeId, code, name: node.name,
      branch: branchId.get(tree.code + ':' + node.category),
      era: eraId.get(node.era),
      image: node.image,
      description: node.description,
      historical_note: node.historical_note,
    });
    board.push({
      id: board.length + 1, tech: id, tree: treeId, era: eraId.get(node.era),
      lane: node.lane, row: node.row, column: firstColumn[node.era] + node.lane,
    });
  });
});

const nodeIdByTech = new Map(board.map(b => [b.tech, b.id]));
const links = [];
catalog.trees.forEach(tree => {
  tree.links.forEach(([from, to]) => {
    const fromId = techId.get(tree.code + ':' + from);
    const toId = techId.get(tree.code + ':' + to);
    if (!fromId || !toId) throw new Error('связь на несуществующую технологию: ' + from + ' → ' + to);
    prereqs.push([toId, fromId]);
    links.push([nodeIdByTech.get(fromId), nodeIdByTech.get(toId)]);
  });
});

/* Стоимости версии №1 считаются тем же способом, что и кнопка «пересчитать
   всё» в админке: семя собирается из номера версии и базовой стоимости. */
const rnd = mulberry32(((BASE_VERSION.id * 7919) ^ COST_BASE ^ 12) >>> 0);
board.forEach(card => { card.cost = costFor(factorsByTree[card.tree][card.column], rnd); });

// ---- сборка ----------------------------------------------------------
const out = [];
out.push(fs.readFileSync(schemaPath, 'utf8').trimEnd());
out.push('');
out.push('-- =====================================================================');
out.push('--  Стандартный набор технологий.');
out.push('--  Все строки помечены is_standard = 1 и участвуют в каждой');
out.push('--  первоначальной генерации новой версии деревьев.');
out.push('--');
out.push('--  Секция собрана скриптом tools/generate-migration.js из файлов');
out.push('--  db/catalog/ — руками не правим.');
out.push('-- =====================================================================');
out.push('');

out.push('INSERT INTO trees (id, code, name, points_name, boost_name, position) VALUES');
out.push(rows(TREES.map(t => [t.id, q(t.code), q(t.name), q(t.points), q(t.boost), t.id])) + ';');
out.push('');

out.push(`-- ${eras.length} эпох стандартной сетки`);
out.push('INSERT INTO eras (id, code, name, period, default_position, is_standard) VALUES');
out.push(rows(eras.map(e => [e.id, q(e.code), q(e.name), nullable(e.period), e.position, 1])) + ';');
out.push('');

const sciBranch = branches.filter(b => b.tree === 1).length;
out.push(`-- ${branches.length} категорий (${sciBranch} научных + ${branches.length - sciBranch} культурных)`);
out.push('INSERT INTO branches (id, tree_id, code, name, color, position) VALUES');
out.push(rows(branches.map(b => [b.id, b.tree, q(b.code), q(b.name), q(b.color), b.position])) + ';');
out.push('');

const sciTech = techs.filter(t => t.tree === 1).length;
out.push(`-- ${techs.length} позиций каталога (${sciTech} технологий + ${techs.length - sciTech} соц. концепций)`);
out.push('INSERT INTO technologies');
out.push('  (id, tree_id, code, name, branch_id, default_era_id, is_standard,');
out.push('   image_path, description, historical_note) VALUES');
out.push(rows(techs.map(t => [
  t.id, t.tree, q(t.code), q(t.name), t.branch, t.era, 1,
  nullable(t.image), nullable(t.description), nullable(t.historical_note),
])) + ';');
out.push('');

out.push(`-- ${prereqs.length} авторских связей каталога: из них генератор собирает`);
out.push('-- связи origin = \'standard\' в каждой новой версии');
out.push('INSERT INTO technology_prereqs (technology_id, prereq_technology_id) VALUES');
out.push(rows(prereqs) + ';');
out.push('');

out.push('-- Стартовые виды игровых эффектов. Список открытый: новые заводятся');
out.push('-- через админку, миграция для этого не нужна.');
out.push('INSERT INTO effect_types (id, code, name, description, payload_schema, position) VALUES');
out.push(rows(EFFECT_TYPES.map((e, i) => [
  i + 1, q(e.code), q(e.name), q(e.description), q(JSON.stringify(e.schema)), i + 1,
])) + ';');
out.push('');

out.push('-- =====================================================================');
out.push('--  Версия деревьев по умолчанию.');
out.push('--  Авторская раскладка из db/catalog/trees.json целиком: столбцы,');
out.push('--  порядок карточек, связи и стоимости. Сразу после наката миграции');
out.push('--  админка открывает её как есть.');
out.push('-- =====================================================================');
out.push('');

out.push('INSERT INTO tree_versions');
out.push('  (id, name, seed_code, seed_numbers, seed_science, seed_culture, seed_layout,');
out.push('   generator_version, cost_base, cost_step_lane, cost_step_era, status, notes) VALUES');
out.push(rows([[
  BASE_VERSION.id, q(BASE_VERSION.name), q(BASE_VERSION.seedCode),
  q(JSON.stringify(BASE_VERSION.seedNumbers)),
  BASE_VERSION.seedNumbers[0], BASE_VERSION.seedNumbers[1], BASE_VERSION.seedNumbers[2],
  q('1'), COST_BASE, COST_STEP_LANE, COST_STEP_ERA, q('generated'),
  q('Базовая пара деревьев из миграции: раскладка авторская, а не считанная по семени.'),
]]) + ';');
out.push('');

out.push(`-- ${eras.length} эпох версии`);
out.push('INSERT INTO tree_version_eras (id, version_id, era_id, position) VALUES');
out.push(rows(eras.map(e => [e.id, BASE_VERSION.id, e.id, e.position])) + ';');
out.push('');

out.push('-- сколько столбцов у эпохи в каждом дереве');
out.push('INSERT INTO tree_version_era_lanes (version_era_id, tree_id, lanes) VALUES');
out.push(rows(lanes.map(l => [l.era, l.tree, l.lanes])) + ';');
out.push('');

out.push(`-- ${board.length} карточек на доске`);
out.push('INSERT INTO tree_version_nodes');
out.push('  (id, version_id, tree_id, technology_id, version_era_id, lane, row_index,');
out.push('   global_column, cost, source, is_relaxed) VALUES');
out.push(rows(board.map(c => [
  c.id, BASE_VERSION.id, c.tree, c.tech, c.era, c.lane, c.row, c.column, c.cost,
  q('standard'), 0,
])) + ';');
out.push('');

out.push(`-- ${links.length} связей доски`);
out.push('INSERT INTO tree_version_links (version_id, from_node_id, to_node_id, origin) VALUES');
out.push(rows(links.map(([from, to]) => [BASE_VERSION.id, from, to, q('standard')])) + ';');
out.push('');

fs.writeFileSync(outPath, out.join('\n') + '\n', 'utf8');
const withNotes = techs.filter(t => t.historical_note).length;
const withImages = techs.filter(t => t.image).length;
const columns = lanes.reduce((a, l) => a + (l.tree === 1 ? l.lanes : 0), 0);
console.log(`эпох=${eras.length} категорий=${branches.length} технологий=${techs.length}`);
console.log(`со справкой=${withNotes} с картинкой=${withImages} авторских связей=${prereqs.length}`);
console.log(`версия по умолчанию: карточек=${board.length} связей=${links.length}`);
const dearest = board.reduce((a, c) => Math.max(a, c.cost), 0);
console.log(`стоимости: база=${COST_BASE} внутри эпохи=${COST_STEP_LANE} между эпохами=${COST_STEP_ERA}`);
console.log(`столбцов науки=${columns} разброс=±${(JITTER * 100).toFixed(1)}% дороже всех=${dearest}`);
console.log('записано:', path.relative(root, outPath), fs.statSync(outPath).size, 'байт');
