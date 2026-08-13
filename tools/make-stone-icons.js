#!/usr/bin/env node
/*
 * Иконки технологий каменного века.
 *
 * Рисуем векторно и в одном стиле: круглая «галечная» подложка, поверх —
 * силуэт предмета линией цвета категории. Это не финальная игровая графика,
 * а рабочие иконки, чтобы увидеть, как карточки с картинками выглядят
 * на доске и в карточке технологии.
 *
 *   node tools/make-stone-icons.js
 */
const fs = require('fs');
const path = require('path');

const outDir = path.resolve(__dirname, '../public/uploads/tech/stone');
fs.mkdirSync(outDir, { recursive: true });

/* Силуэты: 64×64, рисуем в координатах 0..64. */
const ART = {
  fire:        { c: '#e8833a', d: 'M32 54c-9 0-15-6-15-13 0-8 7-11 8-19 0-4-1-6-1-6 6 2 10 7 11 12 2-4 2-8 1-12 8 5 11 14 11 21 0 9-6 17-15 17z M32 47c-4 0-6-3-6-6 0-4 4-5 4-10 4 3 8 6 8 11 0 3-2 5-6 5z' },
  tools:       { c: '#8d6e63', d: 'M20 46l10-30 6 2-8 30z M38 14l8 4-14 32-7-3z M18 50h30v4H18z' },
  gathering:   { c: '#7cb342', d: 'M18 34h28l-4 20H22z M18 34c4-6 10-9 14-9s10 3 14 9 M32 25v-8 M28 20c-4-4-9-4-11-2 3 4 8 5 11 2z M36 20c4-4 9-4 11-2-3 4-8 5-11 2z' },
  hunt:        { c: '#a1887f', d: 'M12 52L48 16 M44 12l8 0 0 8 M44 20l8-8 M14 44l6 6 M22 34c6-4 14-4 20 0' },
  fishing:     { c: '#4fc3f7', d: 'M14 32c8-10 24-10 32 0-8 10-24 10-32 0z M46 32l8-6v12z M24 30a2 2 0 1 0 .1 0z M12 46c6 4 12 4 18 0s12-4 18 0' },
  knapping:    { c: '#795548', d: 'M32 10l16 14-8 30H24l-8-30z M32 10v44 M16 24h32' },
  farming:     { c: '#8bc34a', d: 'M20 54V22 M20 22c0-6 4-10 8-10 M20 30c6 0 10-4 10-10 M44 54V22 M44 22c0-6-4-10-8-10 M44 30c-6 0-10-4-10-10 M12 54h40' },
  domestication:{c: '#a1887f', d: 'M16 40c0-8 6-14 14-14h6c8 0 14 6 14 14v10h-8V40h-4v10h-8V40h-4v10h-8z M22 26l-4-8 8 2 M42 26l4-8-8 2' },
  hides:       { c: '#bcaaa4', d: 'M22 12c-6 6-8 16-6 24s6 14 16 16c10-2 14-8 16-16s0-18-6-24c-4 4-6 8-10 8s-6-4-10-8z' },
  weaving:     { c: '#a1887f', d: 'M14 22h36 M14 32h36 M14 42h36 M22 14v36 M32 14v36 M42 14v36' },
  shelter:     { c: '#8d6e63', d: 'M32 10L10 52h44z M32 26L20 52h24z M28 52v-8h8v8' },
  carpentry:   { c: '#795548', d: 'M14 44l24-24 M38 20l4-8 10 10-8 4z M12 46l4 4 M20 38c4 4 8 4 12 0' },
  boat:        { c: '#4fc3f7', d: 'M10 40h44l-6 12H16z M32 38V14 M32 16l16 8-16 6z M10 44c6 4 12 4 18 0s12-4 18 0' },
  herbs:       { c: '#66bb6a', d: 'M32 54V22 M32 30c-8 0-14-6-14-14 8 0 14 6 14 14z M32 34c8 0 14-6 14-14-8 0-14 6-14 14z M24 54h16' },
  counting:    { c: '#7e57c2', d: 'M16 14h6v36h-6z M28 20v24 M36 20v24 M44 20v24 M52 20v24 M28 32h24' },
  speech:      { c: '#26a69a', d: 'M12 18h40v24H32l-12 10v-10h-8z M22 26h20 M22 34h12' },
  pottery:     { c: '#66bb6a', d: 'M22 18h20l-2 6c6 4 8 10 8 16 0 8-7 14-16 14s-16-6-16-14c0-6 2-12 8-16z M20 34h24' },
  travois:     { c: '#6d4c41', d: 'M20 12l-8 42 M32 12l8 42 M18 26h16 M16 38h20 M12 54h12 M40 54h12' },
  bow:         { c: '#ef6c00', d: 'M20 10c14 6 14 38 0 44 M20 10l0 44 M14 32h36 M50 32l-8-5 M50 32l-8 5' },
  chiefdom:    { c: '#5c6bc0', d: 'M32 12l6 10 10-4-4 12h-24l-4-12 10 4z M20 34h24v6H20z M24 40v12h16V40' },
  custom_law:  { c: '#8d6e63', d: 'M32 12v34 M18 20h28 M18 20l-6 14h12z M46 20l6 14H40z M22 50h20' },
  animism:     { c: '#ab47bc', d: 'M32 8c8 8 12 16 12 24 0 10-6 18-12 22-6-4-12-12-12-22 0-8 4-16 12-24z M32 22a6 6 0 1 0 .1 0z' },
  paganism:    { c: '#9575cd', d: 'M32 10l7 14 15 2-11 11 3 15-14-8-14 8 3-15-11-11 15-2z' },
  burial:      { c: '#7986cb', d: 'M18 26c0-8 6-14 14-14s14 6 14 14v26H18z M32 12v-4 M24 34h16 M24 42h16' },
  taboo:       { c: '#c62828', d: 'M32 10a22 22 0 1 0 .1 0z M17 17l30 30' },
  mysticism:   { c: '#7986cb', d: 'M32 12a10 10 0 1 0 .1 0z M14 46c4-10 10-14 18-14s14 4 18 14 M20 20l-6-6 M44 20l6-6 M32 54v-8' },
  militia:     { c: '#ef5350', d: 'M32 10l18 8v16c0 12-8 18-18 20-10-2-18-8-18-20V18z M24 32l6 6 12-12' },
  barter:      { c: '#c0ca33', d: 'M14 24h28l-8-8 M50 40H22l8 8 M14 24l8-8 M50 40l-8 8' },
  division_labor:{c:'#8d6e63', d: 'M20 20a6 6 0 1 0 .1 0z M44 20a6 6 0 1 0 .1 0z M12 46c0-8 4-12 8-12s8 4 8 12 M36 46c0-8 4-12 8-12s8 4 8 12 M28 30h8' },
  teaching:    { c: '#26a69a', d: 'M32 12l22 10-22 10-22-10z M18 28v12c0 4 6 8 14 8s14-4 14-8V28 M54 22v14' },
  cave_art:    { c: '#ec407a', d: 'M12 46c6-14 12-22 20-22s14 8 20 22 M22 34c2-6 6-10 10-10s8 4 10 10 M26 20a3 3 0 1 0 .1 0z M40 46l6 8 M24 46l-6 8' },
  music:       { c: '#ec407a', d: 'M24 46a6 5 0 1 0 .1 0z M48 40a6 5 0 1 0 .1 0z M30 46V16l24-6v30 M30 26l24-6' },
  signs:       { c: '#00897b', d: 'M14 16h36v32H14z M22 24l8 8-8 8 M36 40h8' },
  alliance:    { c: '#4fc3f7', d: 'M22 22a8 8 0 1 0 .1 0z M42 22a8 8 0 1 0 .1 0z M10 50c0-8 5-14 12-14s12 6 12 14 M30 50c0-8 5-14 12-14s12 6 12 14' },
  clans:       { c: '#66bb6a', d: 'M32 12a6 6 0 1 0 .1 0z M18 34a6 6 0 1 0 .1 0z M46 34a6 6 0 1 0 .1 0z M32 52a6 6 0 1 0 .1 0z M32 18v10 M24 34h16 M22 40l8 8 M42 40l-8 8' },
  camp:        { c: '#a1887f', d: 'M32 30l-10 16h20z M14 46l6-10 4 6 M44 42l6 4 M32 54a20 8 0 1 0 .1 0z M32 24v-8' },
  games:       { c: '#fbc02d', d: 'M32 12a20 20 0 1 0 .1 0z M32 20l5 10 11 1-8 8 2 11-10-6-10 6 2-11-8-8 11-1z' },
};

const NAMES = Object.keys(ART);
NAMES.forEach(name => {
  const { c, d } = ART[name];
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64" role="img">
  <title>${name}</title>
  <circle cx="32" cy="32" r="30" fill="#efeae0" stroke="#d8d3c7" stroke-width="1.5"/>
  <path d="${d}" fill="none" stroke="${c}" stroke-width="3"
        stroke-linecap="round" stroke-linejoin="round"/>
</svg>
`;
  fs.writeFileSync(path.join(outDir, name + '.svg'), svg, 'utf8');
});

console.log('иконок записано:', NAMES.length, '→', path.relative(process.cwd(), outDir));
