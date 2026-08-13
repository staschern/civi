#!/usr/bin/env node
/*
 * Проверка каталога перед сборкой миграции.
 *
 * Следит за тем, что вручную собранный db/catalog/technologies.txt
 * не разъехался: счётчики по эпохам в норме, категории существуют,
 * названия не повторяются, а количество технологий одной категории
 * в эпохе не превышает числа столбцов.
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const MAX_LANES = 3;            // не больше трёх столбцов в эпохе
const MIN_PER_ERA = 15;
const MAX_PER_ERA = 20;

const ERAS = ['stone','bronze','antiquity','early_medieval','high_medieval','exploration',
  'renaissance','enlightenment','industrial_rev','industrial','modern','atomic',
  'information','digital','future'];

const read = f => fs.readFileSync(path.join(root, 'db/catalog', f), 'utf8')
  .split('\n').map(l => l.trim()).filter(l => l && !l.startsWith('#'));

const categories = new Map();
read('categories.txt').forEach(line => {
  const [tree, code, name, color] = line.split('|');
  categories.set(tree + ':' + code, { tree, code, name, color });
});

const techs = read('technologies.txt').map((line, i) => {
  const [era, tree, category, name] = line.split('|');
  return { era, tree, category, name, line: i + 1 };
});

const problems = [];
const perEra = {};
const perCategoryEra = {};
const names = new Map();

techs.forEach(t => {
  if (!ERAS.includes(t.era)) problems.push(`неизвестная эпоха ${t.era} у «${t.name}»`);
  if (!categories.has(t.tree + ':' + t.category)) {
    problems.push(`нет категории ${t.tree}/${t.category} у «${t.name}»`);
  }
  const key = t.era + '|' + t.tree;
  perEra[key] = (perEra[key] || 0) + 1;
  const ck = t.era + '|' + t.tree + '|' + t.category;
  perCategoryEra[ck] = (perCategoryEra[ck] || 0) + 1;

  const nk = t.name.trim().toLowerCase().replace(/ё/g, 'е');
  if (names.has(nk)) problems.push(`дубль названия «${t.name}» (строки ${names.get(nk)} и ${t.line})`);
  else names.set(nk, t.line);
});

Object.entries(perCategoryEra).forEach(([k, n]) => {
  if (n > MAX_LANES) problems.push(`${k}: ${n} технологий одной категории, а столбцов максимум ${MAX_LANES}`);
});

console.log('Эпоха                      наука  культура');
ERAS.forEach(era => {
  const s = perEra[era + '|science'] || 0;
  const c = perEra[era + '|culture'] || 0;
  const mark = v => (v < MIN_PER_ERA || v > MAX_PER_ERA) ? '!' : ' ';
  console.log(`  ${era.padEnd(24)} ${String(s).padStart(3)}${mark(s)}  ${String(c).padStart(4)}${mark(c)}`);
  if (s < MIN_PER_ERA || s > MAX_PER_ERA) problems.push(`${era}/наука: ${s}, нужно ${MIN_PER_ERA}–${MAX_PER_ERA}`);
  if (c < MIN_PER_ERA || c > MAX_PER_ERA) problems.push(`${era}/культура: ${c}, нужно ${MIN_PER_ERA}–${MAX_PER_ERA}`);
});
console.log(`  ИТОГО ${techs.length} технологий, ${categories.size} категорий`);

// не потерялось ли что-то из присланного списка и из прежнего каталога
const norm = s => s.trim().toLowerCase().replace(/ё/g, 'е').replace(/\s+/g, ' ');
const have = new Set(techs.map(t => norm(t.name)));
const checkList = (file, label, aliases = {}) => {
  if (!fs.existsSync(file)) return;
  const wanted = fs.readFileSync(file, 'utf8').split('\n')
    .map(l => l.replace(/^\s*\d+\s*/, '').trim()).filter(Boolean);
  const missing = [...new Set(wanted)].filter(w => !have.has(norm(aliases[norm(w)] || w)));
  console.log(`\n${label}: ${wanted.length} позиций, не размещено ${missing.length}`);
  missing.forEach(m => console.log('  нет: ' + m));
};
checkList(process.argv[2], 'Присланный список', { 'лодки': 'Плоты и лодки' });

console.log(problems.length ? `\nПРОБЛЕМЫ (${problems.length}):` : '\nПроблем нет.');
problems.forEach(p => console.log('  ' + p));
process.exit(problems.length ? 1 : 0);
