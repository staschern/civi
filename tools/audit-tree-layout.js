#!/usr/bin/env node
/*
 * Проверка правил раскладки в прототипе доски.
 *
 * Открывает HTML в headless-браузере и на живой странице проверяет:
 *   1. правило сквозных столбцов — технология без зависимостей возможна
 *      только в первом столбце первой эпохи, все остальные опираются
 *      минимум на одну технологию из непосредственно предыдущего столбца
 *      (для первого столбца эпохи правило смягчено — это считается
 *      отдельно и нарушением не является);
 *   2. все связи идут строго слева направо;
 *   3. пустых столбцов нет;
 *   4. раскладка воспроизводится: после перезагрузки состав столбцов
 *      и множество связей совпадают.
 *
 * Проверка гоняется несколько раз с перегенерацией («Перемешать»),
 * потому что раскладка случайная и единичный прогон ничего не доказывает.
 *
 * Запуск:
 *   npm i playwright-core
 *   node tools/audit-tree-layout.js [путь-к-html] [число-прогонов]
 *
 * Путь к Chromium берётся из CHROMIUM_PATH, иначе из стандартных мест
 * установки playwright.
 */
const path = require('path');
const fs = require('fs');

const root = path.resolve(__dirname, '..');
const htmlPath = process.argv[2] || path.join(root, 'docs',
  '2026.08.12 - Этап 1. Прототип дерева технологий (интерактив).html');
const rounds = Number(process.argv[3] || 5);

let chromium;
try {
  ({ chromium } = require('playwright-core'));
} catch (e) {
  console.error('нужен playwright-core: npm i playwright-core');
  process.exit(2);
}

function findChromium(){
  if(process.env.CHROMIUM_PATH) return process.env.CHROMIUM_PATH;
  const base = process.env.PLAYWRIGHT_BROWSERS_PATH || '/opt/pw-browsers';
  if(!fs.existsSync(base)) return null;
  const dir = fs.readdirSync(base).find(d => /^chromium-\d+$/.test(d));
  return dir ? path.join(base, dir, 'chrome-linux', 'chrome') : null;
}

/* Снимок доски одного дерева: состав столбцов и связи. Выполняется в браузере. */
const SNAPSHOT = (mount) => {
  const sec = document.getElementById(mount);
  const lanes = [...sec.querySelectorAll('.lane')];
  const colOf = new Map();
  lanes.forEach((lane, gi) =>
    [...lane.querySelectorAll('.node')].forEach(n => colOf.set(n.dataset.id, gi)));

  // первые столбцы эпох — там правило смягчено
  const eraFirst = new Set();
  let g = 0;
  [...sec.querySelectorAll('.era-col')].forEach(ec => {
    eraFirst.add(g);
    g += ec.querySelectorAll('.lane').length;
  });

  const edges = [...sec.querySelectorAll('.edge-path')]
    .map(p => ({ from: p.dataset.src, to: p.dataset.tgt }));
  const inbound = new Map();
  edges.forEach(e => {
    if(!inbound.has(e.to)) inbound.set(e.to, []);
    inbound.get(e.to).push(colOf.get(e.from));
  });

  const problems = [];
  let relaxed = 0;
  lanes.forEach((lane, gi) => {
    if(!lane.children.length) problems.push(`пустой столбец ${gi + 1}`);
    [...lane.querySelectorAll('.node')].forEach(n => {
      const name = n.textContent.trim().slice(0, 40);
      const src = inbound.get(n.dataset.id) || [];
      if(gi === 0){
        if(src.length) problems.push(`корневая технология с зависимостями: ${name}`);
        return;
      }
      if(!src.length){ problems.push(`нет ни одной зависимости: [ст. ${gi + 1}] ${name}`); return; }
      if(Math.max(...src) >= gi) problems.push(`зависимость не левее: [ст. ${gi + 1}] ${name}`);
      if(!src.includes(gi - 1)){
        if(eraFirst.has(gi)) relaxed++;
        else problems.push(`нет связи с предыдущим столбцом: [ст. ${gi + 1}] ${name}`);
      }
    });
  });

  return {
    columns: lanes.length,
    nodes: colOf.size,
    edges: edges.length,
    problems,
    relaxed,
    signature: {
      lanes: lanes.map(l => [...l.querySelectorAll('.node')].map(n => n.dataset.id).join(',')),
      edges: edges.map(e => e.from + '>' + e.to).sort(),
    },
  };
};

(async () => {
  const executablePath = findChromium();
  const browser = await chromium.launch({
    ...(executablePath ? { executablePath } : {}),
    args: ['--no-sandbox'],
  });
  const page = await browser.newPage({ viewport: { width: 1700, height: 1000 } });

  const jsErrors = [];
  page.on('pageerror', e => jsErrors.push(String(e.message)));
  page.on('console', m => { if(m.type() === 'error') jsErrors.push(m.text()); });

  await page.goto('file://' + htmlPath, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);

  const trees = ['app-sci', 'app-cul'];
  let failures = 0;

  for(let round = 1; round <= rounds; round++){
    for(const mount of trees){
      const r = await page.evaluate(SNAPSHOT, mount);
      const status = r.problems.length ? 'ПРОВАЛ' : 'ok    ';
      console.log(`  ${status} прогон ${round} ${mount}: столбцов ${r.columns}, ` +
        `карточек ${r.nodes}, связей ${r.edges}, смягчённых ${r.relaxed}`);
      r.problems.slice(0, 8).forEach(p => console.log('          ' + p));
      failures += r.problems.length;
    }
    if(round < rounds){
      for(const mount of trees) await page.click(`#${mount} [data-act="shuffle"]`);
      await page.waitForTimeout(400);
    }
  }

  // воспроизводимость: перезагрузка не должна менять раскладку
  const before = {};
  for(const mount of trees) before[mount] = (await page.evaluate(SNAPSHOT, mount)).signature;
  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
  for(const mount of trees){
    const after = (await page.evaluate(SNAPSHOT, mount)).signature;
    const same = JSON.stringify(before[mount]) === JSON.stringify(after);
    console.log(`  ${same ? 'ok    ' : 'ПРОВАЛ'} воспроизводимость после перезагрузки ${mount}`);
    if(!same) failures++;
  }

  if(jsErrors.length){
    failures += jsErrors.length;
    console.log('  ПРОВАЛ ошибки JS:');
    jsErrors.slice(0, 5).forEach(e => console.log('          ' + e));
  } else {
    console.log('  ok     ошибок JS нет');
  }

  await browser.close();
  console.log(failures ? `\nПровалов: ${failures}` : '\nВсе проверки пройдены');
  process.exit(failures ? 1 : 0);
})();
