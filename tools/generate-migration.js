#!/usr/bin/env node
/*
 * Сборка миграции 0001 из двух источников:
 *   db/schema/0001_schema.sql — схема, правится руками;
 *   прототип доски           — каталог эпох, веток и технологий.
 *
 * Каталог тянем прямо из данных прототипа, чтобы стандартный набор в БД
 * был ровно тем же, что на доске, и не расходился с ней при правках.
 *
 * Запуск из корня репозитория:
 *   node tools/generate-migration.js
 *
 * Результат перезаписывает db/migrations/0001_create_tech_tree_versions.sql.
 * Руками этот файл не правим — правим схему или прототип и пересобираем.
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const htmlPath = process.argv[2] || path.join(root, 'docs',
  '2026.08.12 - Этап 1. Прототип дерева технологий (интерактив).html');
const schemaPath = process.argv[3] || path.join(root, 'db', 'schema', '0001_schema.sql');
const outPath = process.argv[4] || path.join(root, 'db', 'migrations',
  '0001_create_tech_tree_versions.sql');

const src = fs.readFileSync(htmlPath, 'utf8');
const pick = (name) => {
  const m = src.match(new RegExp('const ' + name + ' = (\\{.*?\\});\\n', 's'));
  if(!m) throw new Error('не найден блок данных ' + name + ' в ' + htmlPath);
  return JSON.parse(m[1]);
};
const TECH = pick('TECH_DATA');
const CIVIC = pick('CIVIC_DATA');

const q = s => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "''") + "'";
const rows = list => list.map(r => '  (' + r.join(', ') + ')').join(',\n');

const TREES = [
  { id: 1, code: 'science', name: 'Дерево технологий', points: 'Наука', boost: 'Озарения', data: TECH },
  { id: 2, code: 'culture', name: 'Дерево социальных концепций', points: 'Культура', boost: 'Вдохновения', data: CIVIC },
];

// эпохи: сетка у обоих деревьев одна и та же, поэтому каталог общий
const eras = TECH.eras.slice().sort((a, b) => a.order - b.order);
const civicEras = CIVIC.eras.slice().sort((a, b) => a.order - b.order);
if(JSON.stringify(eras) !== JSON.stringify(civicEras)){
  throw new Error('сетки эпох у деревьев разошлись — общий каталог эпох больше не годится');
}
const eraId = new Map(eras.map((e, i) => [e.id, i + 1]));

const branches = [];
TREES.forEach(t => t.data.branches.forEach(b =>
  branches.push({ id: branches.length + 1, tree: t.id, code: b.id, name: b.name, color: b.color })));
const branchId = new Map(branches.map(b => [b.tree + ':' + b.code, b.id]));

const techs = [];
TREES.forEach(t => t.data.nodes.forEach(n =>
  techs.push({ id: techs.length + 1, tree: t.id, code: n.id, name: n.name, branch: n.branch, era: n.era })));
const techId = new Map(techs.map(t => [t.code, t.id]));
if(techId.size !== techs.length) throw new Error('коды технологий не уникальны');

const prereqs = [];
TREES.forEach(t => t.data.nodes.forEach(n => n.prereqs.forEach(p => {
  if(!techId.has(p)) throw new Error('пререквизит ' + p + ' не найден в каталоге');
  if(p === n.id) throw new Error('технология ' + p + ' ссылается сама на себя');
  prereqs.push([techId.get(n.id), techId.get(p)]);
})));

const out = [];
out.push(fs.readFileSync(schemaPath, 'utf8').trimEnd());
out.push('');
out.push('-- =====================================================================');
out.push('--  Стандартный набор: всё, что на 2026.08.12 лежит в прототипе доски.');
out.push('--  Все эти строки помечены is_standard = 1 и участвуют в каждой');
out.push('--  первоначальной генерации новой версии деревьев.');
out.push('--');
out.push('--  Секция собрана скриптом tools/generate-migration.js — руками не правим.');
out.push('-- =====================================================================');
out.push('');

out.push('INSERT INTO trees (id, code, name, points_name, boost_name, position) VALUES');
out.push(rows(TREES.map(t => [t.id, q(t.code), q(t.name), q(t.points), q(t.boost), t.id])) + ';');
out.push('');

out.push('-- 15 эпох стандартной сетки (раздел 3.2 проектного документа)');
out.push('INSERT INTO eras (id, code, name, default_position, is_standard) VALUES');
out.push(rows(eras.map((e, i) => [eraId.get(e.id), q(e.id), q(e.name), i + 1, 1])) + ';');
out.push('');

out.push(`-- ${branches.length} веток (${TECH.branches.length} научных + ${CIVIC.branches.length} культурных)`);
out.push('INSERT INTO branches (id, tree_id, code, name, color) VALUES');
out.push(rows(branches.map(b => [b.id, b.tree, q(b.code), q(b.name), q(b.color)])) + ';');
out.push('');

out.push(`-- ${techs.length} позиций каталога (${TECH.nodes.length} технологий + ${CIVIC.nodes.length} соц. концепций)`);
out.push('INSERT INTO technologies (id, tree_id, code, name, branch_id, default_era_id, is_standard) VALUES');
out.push(rows(techs.map(t => [
  t.id, t.tree, q(t.code), q(t.name),
  branchId.get(t.tree + ':' + t.branch), eraId.get(t.era), 1,
])) + ';');
out.push('');

out.push(`-- ${prereqs.length} авторских связей каталога (кросс-эпохальные, проставлены вручную)`);
out.push('INSERT INTO technology_prereqs (technology_id, prereq_technology_id) VALUES');
out.push(rows(prereqs) + ';');
out.push('');

fs.writeFileSync(outPath, out.join('\n') + '\n', 'utf8');
console.log(`эпох=${eras.length} веток=${branches.length} технологий=${techs.length} связей=${prereqs.length}`);
console.log('записано:', path.relative(root, outPath), fs.statSync(outPath).size, 'байт');
