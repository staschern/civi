#!/usr/bin/env node
/*
 * Сборка миграции 0001 из трёх источников:
 *   db/schema/0001_schema.sql      — схема, правится руками;
 *   db/catalog/categories.txt      — категории технологий;
 *   db/catalog/technologies.txt    — сам каталог, размеченный по эпохам.
 *
 * Запуск из корня репозитория:
 *   node tools/generate-migration.js
 *
 * Результат перезаписывает db/migrations/0001_create_tech_tree_versions.sql.
 * Руками этот файл не правим — правим источники и пересобираем.
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const catalogDir = path.join(root, 'db', 'catalog');
const schemaPath = path.join(root, 'db', 'schema', '0001_schema.sql');
const outPath = path.join(root, 'db', 'migrations', '0001_create_tech_tree_versions.sql');

const ERAS = [
  ['stone', 'Каменный век'],
  ['bronze', 'Бронзовый век'],
  ['antiquity', 'Античность'],
  ['early_medieval', 'Раннее Средневековье'],
  ['high_medieval', 'Высокое Средневековье'],
  ['exploration', 'Эпоха открытий'],
  ['renaissance', 'Возрождение'],
  ['enlightenment', 'Просвещение'],
  ['industrial_rev', 'Промышленная революция'],
  ['industrial', 'Индустриальная эра'],
  ['modern', 'Новое время'],
  ['atomic', 'Атомная эра'],
  ['information', 'Информационная эра'],
  ['digital', 'Цифровая эра'],
  ['future', 'Будущее'],
];

const TREES = [
  { id: 1, code: 'science', name: 'Дерево технологий', points: 'Наука', boost: 'Озарения' },
  { id: 2, code: 'culture', name: 'Дерево социальных концепций', points: 'Культура', boost: 'Вдохновения' },
];

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

/* Латинский код из русского названия. */
const TRANSLIT = {
  'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'e','ж':'zh','з':'z','и':'i','й':'i',
  'к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f',
  'х':'h','ц':'c','ч':'ch','ш':'sh','щ':'sch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya',
};
function slug(text){
  const s = text.toLowerCase().split('').map(ch => TRANSLIT[ch] !== undefined ? TRANSLIT[ch] : ch).join('');
  return s.replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 70) || 'x';
}

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

// ---- эпохи -----------------------------------------------------------
const eraId = new Map(ERAS.map(([code], i) => [code, i + 1]));

// ---- технологии ------------------------------------------------------
const notes = loadNotes();
const techs = [];
const seenCode = new Map();
readLines('technologies.txt').forEach(line => {
  const [era, tree, category, name] = line.split('|');
  if (!eraId.has(era)) throw new Error('неизвестная эпоха ' + era + ' у «' + name + '»');
  if (!branchId.has(tree + ':' + category)) throw new Error('нет категории ' + tree + '/' + category);

  let code = (tree === 'science' ? 't_' : 'c_') + slug(name);
  if (seenCode.has(code)) throw new Error('повтор кода ' + code + ' («' + name + '»)');
  seenCode.set(code, true);

  const extra = notes[name] || {};
  techs.push({
    id: techs.length + 1,
    tree: treeIdByCode[tree],
    code, name,
    branch: branchId.get(tree + ':' + category),
    era: eraId.get(era),
    image: extra.image,
    description: extra.description,
    historical_note: extra.historical_note,
  });
});

/* Описания и исторические справки лежат отдельным файлом: их немного
   и заполняются они по мере проработки эпох. */
function loadNotes(){
  const file = path.join(catalogDir, 'notes.json');
  return fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
}

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

out.push(`-- ${ERAS.length} эпох стандартной сетки`);
out.push('INSERT INTO eras (id, code, name, default_position, is_standard) VALUES');
out.push(rows(ERAS.map(([code, name], i) => [i + 1, q(code), q(name), i + 1, 1])) + ';');
out.push('');

const sciCount = branches.filter(b => b.tree === 1).length;
out.push(`-- ${branches.length} категорий (${sciCount} научных + ${branches.length - sciCount} культурных)`);
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

out.push('-- Стартовые виды игровых эффектов. Список открытый: новые заводятся');
out.push('-- через админку, миграция для этого не нужна.');
out.push('INSERT INTO effect_types (id, code, name, description, payload_schema, position) VALUES');
out.push(rows(EFFECT_TYPES.map((e, i) => [
  i + 1, q(e.code), q(e.name), q(e.description), q(JSON.stringify(e.schema)), i + 1,
])) + ';');
out.push('');

fs.writeFileSync(outPath, out.join('\n') + '\n', 'utf8');
const withNotes = techs.filter(t => t.historical_note).length;
const withImages = techs.filter(t => t.image).length;
console.log(`эпох=${ERAS.length} категорий=${branches.length} технологий=${techs.length}`);
console.log(`со справкой=${withNotes} с картинкой=${withImages}`);
console.log('записано:', path.relative(root, outPath), fs.statSync(outPath).size, 'байт');
