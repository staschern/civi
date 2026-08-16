#!/usr/bin/env node
/*
 * Иллюстрации технологий каменного века.
 *
 * Каждая — квадрат 128×128 с собственным цветным фоном и сценой из залитых
 * фигур: не контурная иконка, а маленькая живая картинка, которая читается
 * и в 100 пикселях на карточке дерева, и крупнее в карточке технологии.
 *
 * Это рабочая графика для проверки вёрстки, а не финальная игровая
 * иллюстрация: рисуется кодом, чтобы её можно было пересобрать и менять
 * пачкой, пока состав технологий не устоялся.
 *
 *   node tools/make-stone-icons.js
 */
const fs = require('fs');
const path = require('path');

const outDir = path.resolve(__dirname, '../public/uploads/tech/stone');
fs.mkdirSync(outDir, { recursive: true });

/* ---------- кисти ---------- */

const ground = (color, y = 96, curve = 10) =>
  `<path d="M0 128V${y}q32-${curve} 64 0t64 0V128z" fill="${color}"/>`;

const sun = (x, y, r, color, glow) =>
  (glow ? `<circle cx="${x}" cy="${y}" r="${r * 1.9}" fill="${glow}" opacity=".35"/>` : '') +
  `<circle cx="${x}" cy="${y}" r="${r}" fill="${color}"/>`;

const hill = (x, y, w, h, color) =>
  `<path d="M${x} ${y}q${w / 2} -${h} ${w} 0z" fill="${color}"/>`;

const tree = (x, y, s, trunk, crown) =>
  `<rect x="${x - s * 0.12}" y="${y - s * 0.5}" width="${s * 0.24}" height="${s * 0.5}" rx="${s * 0.08}" fill="${trunk}"/>` +
  `<circle cx="${x}" cy="${y - s * 0.62}" r="${s * 0.38}" fill="${crown}"/>`;

const poly = (points, fill, extra = '') => `<polygon points="${points}" fill="${fill}"${extra}/>`;
const rect = (x, y, w, h, fill, rx = 0) =>
  `<rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${rx}" fill="${fill}"/>`;
const circle = (x, y, r, fill, opacity) =>
  `<circle cx="${x}" cy="${y}" r="${r}" fill="${fill}"${opacity ? ` opacity="${opacity}"` : ''}/>`;
const stroke = (d, color, w = 5, cap = 'round') =>
  `<path d="${d}" fill="none" stroke="${color}" stroke-width="${w}" stroke-linecap="${cap}" stroke-linejoin="round"/>`;
const blob = (d, fill) => `<path d="${d}" fill="${fill}"/>`;

/* ---------- сцены ---------- */

const ART = {
  // --- наука ---
  fire: { bg: ['#2b1a14', '#7a2f16'], art:
    ground('#3b2317') +
    blob('M64 26c10 18 22 24 22 44 0 18-12 32-22 32s-22-14-22-32c0-20 12-26 22-44z', '#ff9f1c') +
    blob('M64 52c5 10 12 14 12 24 0 10-6 18-12 18s-12-8-12-18c0-10 7-14 12-24z', '#ffe066') +
    stroke('M34 104h60', '#5b3a24', 7) },

  tools: { bg: ['#3a3a44', '#6f6f7d'], art:
    ground('#4a4a55') +
    poly('40,88 58,30 70,36 52,94', '#c8b8a0') +
    poly('58,30 70,36 78,26 66,20', '#e8dfd0') +
    stroke('M74 92l26-24', '#8b5e3c', 8) +
    poly('96,72 112,56 120,64 104,80', '#b9c2cc') },

  gathering: { bg: ['#254d2b', '#4e8c3f'], art:
    ground('#2f5f34') +
    blob('M36 74h56l-8 40H44z', '#c98a4b') +
    stroke('M36 74q28-18 56 0', '#a86d38', 6) +
    circle(52, 62, 9, '#e63946') + circle(70, 58, 9, '#ff8fa3') + circle(84, 66, 8, '#ffd166') },

  hunt: { bg: ['#3b2a1e', '#8a5a2b'], art:
    ground('#4a3521') +
    hill(0, 96, 60, 26, '#5d4126') +
    blob('M28 92c0-14 10-22 24-22h20c14 0 24 8 24 22v6H28z', '#7b4b26') +
    poly('86,64 104,44 110,50 92,70', '#e8dfd0') +
    circle(46, 78, 5, '#2b1a14') },

  fishing: { bg: ['#0f3b5c', '#2f8fb8'], art:
    blob('M0 92q32-12 64 0t64 0V128H0z', '#1f6f96') +
    blob('M30 70c14-16 40-16 54 0-14 16-40 16-54 0z', '#ffd166') +
    poly('84,70 104,58 104,82', '#f4a261') +
    circle(46, 66, 4, '#0f3b5c') +
    stroke('M0 104q32-10 64 0t64 0', '#3ba0c9', 5) },

  knapping: { bg: ['#38323c', '#786a76'], art:
    ground('#463d47') +
    poly('64,20 96,52 84,104 44,104 32,52', '#d8d2c6') +
    poly('64,20 96,52 64,66', '#f2eee6') +
    poly('64,66 84,104 44,104', '#b3ab9d') },

  farming: { bg: ['#3b5a1e', '#8ab547'], art:
    ground('#4d7024', 92, 6) +
    sun(102, 26, 13, '#ffd166', '#ffe9a8') +
    stroke('M40 100V52', '#e9c46a', 6) + stroke('M40 66q-14-6-14-18 14 0 14 12', '#9bc53d', 5) +
    stroke('M64 100V44', '#e9c46a', 6) + stroke('M64 58q14-6 14-18-14 0-14 12', '#9bc53d', 5) +
    stroke('M88 100V56', '#e9c46a', 6) + stroke('M88 70q-12-6-12-16 12 0 12 10', '#9bc53d', 5) },

  domestication: { bg: ['#4a3620', '#a97c4e'], art:
    ground('#5b4126') +
    blob('M34 66h44c10 0 16 8 16 18v18h-12V84h-8v18H54V84h-8v18H34z', '#f2e8d5') +
    poly('34,66 26,48 44,56', '#f2e8d5') + poly('78,66 92,50 84,68', '#f2e8d5') +
    circle(48, 60, 4, '#2b1a14') },

  hides: { bg: ['#4a3a2c', '#a5866a'], art:
    ground('#5a4737') +
    blob('M44 22c-14 12-18 34-12 52s16 30 32 34c16-4 26-16 32-34s2-40-12-52c-8 10-12 16-20 16s-12-6-20-16z', '#e6d2b5') +
    stroke('M64 44v52', '#c2a583', 4) },

  cordage: { bg: ['#4a3f24', '#a89150'], art:
    ground('#5b4d2c') +
    rect(28, 28, 72, 72, '#d9c08a', 8) +
    stroke('M28 46h72M28 64h72M28 82h72', '#8a7038', 5) +
    stroke('M46 28v72M64 28v72M82 28v72', '#b39a5e', 5) },

  shelter: { bg: ['#2f3a2a', '#6c7f52'], art:
    ground('#3d4a33') +
    poly('64,22 108,100 20,100', '#8a5a33') +
    poly('64,38 96,100 32,100', '#a76e3f') +
    poly('64,66 82,100 46,100', '#3b2317') +
    sun(102, 26, 10, '#ffe066') },

  carpentry: { bg: ['#3a2a1c', '#8a6236'], art:
    ground('#4a3623') +
    rect(20, 62, 88, 16, '#c58a4e', 4) + rect(20, 82, 88, 12, '#a06d38', 4) +
    stroke('M76 58l22-22', '#6b4a2a', 9) +
    poly('92,40 112,20 122,30 102,50', '#b9c2cc') },

  rafts: { bg: ['#123a52', '#3f96b8'], art:
    blob('M0 96q32-12 64 0t64 0V128H0z', '#1d6b8f') +
    poly('64,18 64,78 100,66', '#f2e8d5') +
    stroke('M64 16v72', '#8a5a33', 5) +
    blob('M18 86h92l-14 20H32z', '#8a5a33') +
    sun(24, 28, 11, '#ffd166') },

  herbs: { bg: ['#1e4a2e', '#5fae5f'], art:
    ground('#2a5e38') +
    stroke('M64 104V44', '#3f7d3f', 6) +
    blob('M64 62c-16 0-28-12-28-28 16 0 28 12 28 28z', '#8fd18f') +
    blob('M64 74c16 0 28-12 28-28-16 0-28 12-28 28z', '#b8e6a1') +
    circle(64, 38, 8, '#ffd166') },

  count: { bg: ['#2e2a44', '#6b5f9e'], art:
    ground('#3a3454') +
    rect(24, 26, 22, 76, '#d8d2c6', 5) +
    stroke('M58 36v56M72 36v56M86 36v56M100 36v56', '#cbb7ff', 6) +
    stroke('M52 64h54', '#8f7fd6', 5) },

  speech: { bg: ['#123f42', '#37a8a0'], art:
    ground('#1b5a5c') +
    blob('M20 26h88v52H60L36 98V78H20z', '#e9f7f5') +
    stroke('M36 44h56M36 60h34', '#2f8f88', 6) },

  pottery: { bg: ['#4a2a1c', '#b06a3a'], art:
    ground('#5b3623') +
    blob('M42 30h44l-6 14c14 10 18 24 18 34 0 16-16 26-34 26S30 94 30 78c0-10 4-24 18-34z', '#e2803f') +
    stroke('M34 70h60', '#f6c28b', 6) +
    stroke('M44 88h40', '#f6c28b', 5) },

  travois: { bg: ['#3a3122', '#8a7a4e'], art:
    ground('#4a3f2a') +
    stroke('M40 20L20 106', '#8a5a33', 7) + stroke('M64 20l20 86', '#8a5a33', 7) +
    stroke('M36 44h30M30 66h40M24 88h50', '#c58a4e', 6) },

  bow: { bg: ['#3a2418', '#a05a2c'], art:
    ground('#4a3020') +
    stroke('M40 20q30 44 0 88', '#8a5a33', 8) +
    stroke('M40 20v88', '#e6d2b5', 4) +
    stroke('M34 64h64', '#d8d2c6', 5) +
    poly('98,58 112,64 98,70', '#ffd166') },

  // --- культура ---
  chiefdom: { bg: ['#22284a', '#5a63a8'], art:
    ground('#2c3358') +
    poly('64,18 74,42 100,32 90,62 38,62 28,32 54,42', '#ffd166') +
    rect(36, 62, 56, 12, '#e9c46a', 3) +
    rect(44, 74, 40, 30, '#8a8fc4', 3) },

  custom: { bg: ['#3a3226', '#8a7a58'], art:
    ground('#4a4133') +
    stroke('M64 22v78', '#e6d2b5', 6) +
    stroke('M30 38h68', '#e6d2b5', 6) +
    poly('30,38 18,66 42,66', '#c9b48a') + poly('98,38 86,66 110,66', '#c9b48a') },

  animism: { bg: ['#2a1e42', '#7a5aa8'], art:
    ground('#37285a') +
    blob('M64 16c16 16 24 30 24 44 0 20-12 34-24 40-12-6-24-20-24-40 0-14 8-28 24-44z', '#c9a7ff') +
    circle(64, 56, 11, '#ffe066') },

  ancestor_cult: { bg: ['#2e2450', '#6f5ab0'], art:
    ground('#3a2e62') +
    poly('64,16 76,50 112,50 82,70 94,104 64,84 34,104 46,70 16,50 52,50', '#ffd166') },

  burial: { bg: ['#242a30', '#5c6b78'], art:
    ground('#2e363e') +
    blob('M38 46c0-16 12-26 26-26s26 10 26 26v56H38z', '#b9c2cc') +
    stroke('M64 20V8', '#e9eef2', 5) +
    stroke('M48 62h32M48 80h32', '#7c8a97', 5) },

  taboo: { bg: ['#3e1c1c', '#a33b3b'], art:
    ground('#4e2424') +
    circle(64, 62, 36, '#f2e8d5') +
    circle(64, 62, 28, '#c1121f') +
    stroke('M44 42l40 40', '#f2e8d5', 9) },

  shamanism: { bg: ['#1e2444', '#5a63a8'], art:
    ground('#282e56') +
    circle(64, 40, 14, '#ffe066') +
    blob('M28 104c6-20 18-30 36-30s30 10 36 30z', '#8f7fd6') +
    stroke('M24 30l10 8M104 30l-10 8', '#ffe066', 5) },

  clan_militia: { bg: ['#3a2020', '#9a4a3a'], art:
    ground('#4a2828') +
    blob('M64 18l34 14v30c0 24-16 36-34 42-18-6-34-18-34-42V32z', '#d8d2c6') +
    stroke('M50 62l10 12 22-24', '#c1121f', 8) },

  gift_exchange: { bg: ['#3f3a1c', '#9a9040'], art:
    ground('#4d471f') +
    stroke('M24 48h64', '#ffd166', 7) + poly('88,38 106,48 88,58', '#ffd166') +
    stroke('M104 84H40', '#e9c46a', 7) + poly('40,74 22,84 40,94', '#e9c46a') },

  labor_division: { bg: ['#2e3a2a', '#6f8a52'], art:
    ground('#39482f') +
    circle(42, 40, 12, '#f2e8d5') + circle(86, 40, 12, '#f2e8d5') +
    blob('M20 104c0-18 10-28 22-28s22 10 22 28z', '#c58a4e') +
    blob('M64 104c0-18 10-28 22-28s22 10 22 28z', '#8fb14e') },

  oral_teaching: { bg: ['#123f42', '#3fa89c'], art:
    ground('#1b5a5c') +
    poly('64,22 116,44 64,66 12,44', '#e9f7f5') +
    blob('M34 54v22c0 10 14 18 30 18s30-8 30-18V54L64 70z', '#ffd166') },

  cave_art: { bg: ['#3a2418', '#a0603a'], art:
    ground('#4a2f1e') +
    blob('M18 100c10-30 24-46 46-46s36 16 46 46z', '#c9743f') +
    blob('M40 98c6-18 12-26 24-26s18 8 24 26z', '#e2a06a') +
    circle(50, 66, 6, '#2b1a14') +
    stroke('M84 100l12 16M40 100l-10 16', '#7a3f1f', 5) },

  music: { bg: ['#3a1e3a', '#9a4a8a'], art:
    ground('#4a2848') +
    circle(42, 92, 13, '#ffd166') + circle(90, 82, 13, '#ffd166') +
    stroke('M55 92V30l48-12v64', '#f2e8d5', 7) +
    stroke('M55 48l48-12', '#f2e8d5', 6) },

  signs: { bg: ['#1e3a38', '#3f8a80'], art:
    ground('#274a46') +
    rect(24, 26, 80, 72, '#e9f7f5', 8) +
    stroke('M42 44l16 18-16 18', '#2f8f88', 7) +
    stroke('M70 80h20', '#2f8f88', 7) },

  tribal_alliance: { bg: ['#123a52', '#3f96b8'], art:
    ground('#1b5478') +
    circle(44, 44, 15, '#f2e8d5') + circle(84, 44, 15, '#ffd166') +
    blob('M14 104c0-18 12-30 30-30s30 12 30 30z', '#f2e8d5') +
    blob('M54 104c0-18 12-30 30-30s30 12 30 30z', '#ffd166') },

  kinship: { bg: ['#1e3a24', '#4e8a52'], art:
    ground('#26492c') +
    circle(64, 28, 11, '#ffd166') + circle(30, 62, 11, '#8fd18f') +
    circle(98, 62, 11, '#8fd18f') + circle(64, 96, 11, '#8fd18f') +
    stroke('M64 39v18M41 62h46M46 74l12 12M82 74L70 86', '#cfe8c9', 5) },

  camp: { bg: ['#2a2a3e', '#6a5a78'], art:
    ground('#343148') +
    poly('42,96 62,52 82,96', '#8a5a33') + poly('50,96 62,70 74,96', '#a76e3f') +
    circle(96, 96, 12, '#ff9f1c') + circle(96, 96, 6, '#ffe066') +
    stroke('M14 96h100', '#4a4459', 5) },

  spear: { bg: ['#2f3524', '#7e8a4a'], art:
    ground('#3a4229') +
    stroke('M28 108L96 30', '#8b5e3c', 7) +
    poly('96,30 108,18 104,38 88,42', '#e8dfd0') +
    stroke('M40 96l16-4', '#5b4a2a', 5) +
    circle(104, 96, 10, '#c8b8a0', '.5') },

  ground_axe: { bg: ['#33323a', '#7b7686'], art:
    ground('#403e49') +
    stroke('M34 104L86 44', '#8b5e3c', 9) +
    poly('72,52 100,26 116,44 88,68', '#cfd8e0') +
    poly('72,52 88,68 96,52 82,40', '#9fb0bd') +
    stroke('M74 58l14 14', '#6d7b88', 4) },

  sling: { bg: ['#3a2f22', '#96794a'], art:
    ground('#463a29') +
    stroke('M44 26q22 42 0 70', '#d8c9a8', 5) +
    stroke('M84 26q-22 42 0 70', '#d8c9a8', 5) +
    blob('M44 92q20 18 40 0q-20 10-40 0z', '#8b6a3c') +
    circle(64, 96, 9, '#b9b2a6') +
    circle(102, 44, 7, '#b9b2a6', '.8') },

  myth_thinking: { bg: ['#241d3f', '#6a4f9c'], art:
    ground('#2c2450') +
    circle(64, 54, 26, '#ffd166', '.25') +
    blob('M64 30c12 0 22 10 22 22 0 14-10 18-10 28H52c0-10-10-14-10-28 0-12 10-22 22-22z', '#f2e8d5') +
    circle(56, 50, 4, '#3a2f5a') + circle(72, 50, 4, '#3a2f5a') +
    sun(102, 26, 7, '#ffd166', '#ffe066') +
    stroke('M30 30l6 6M26 44h9', '#cdbdf0', 4) },

  games: { bg: ['#3f3a14', '#a89840'], art:
    ground('#4d4720') +
    circle(64, 60, 34, '#ffd166') +
    poly('64,34 71,54 92,54 75,66 81,86 64,74 47,86 53,66 36,54 57,54', '#b06a1a') },
};

const NAMES = Object.keys(ART);
NAMES.forEach(name => {
  const { bg, art } = ART[name];
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128" role="img">
  <title>${name}</title>
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="${bg[0]}"/><stop offset="1" stop-color="${bg[1]}"/>
    </linearGradient>
    <radialGradient id="vig" cx="0.5" cy="0.42" r="0.75">
      <stop offset="0.55" stop-color="#000" stop-opacity="0"/>
      <stop offset="1" stop-color="#000" stop-opacity="0.35"/>
    </radialGradient>
  </defs>
  <rect width="128" height="128" fill="url(#bg)"/>
  ${art}
  <rect width="128" height="128" fill="url(#vig)"/>
</svg>
`;
  fs.writeFileSync(path.join(outDir, name + '.svg'), svg, 'utf8');
});

console.log('иллюстраций записано:', NAMES.length, '→', path.relative(process.cwd(), outDir));
