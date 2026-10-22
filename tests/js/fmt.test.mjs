// Run: node tests/js/fmt.test.mjs
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const UsFmt = require('../../source/voz.cron/usr/local/emhttp/plugins/voz.cron/ui/fmt.js');
let n = 0;
const t = (name, fn) => { fn(); n++; console.log('ok  ', name); };
const ts = (iso) => Date.parse(iso) / 1000;

t('formats in the Tower zone, not the machine zone', () => {
  const f = UsFmt.make('America/Sao_Paulo');
  assert.equal(f.time(ts('2026-10-12T06:00:00Z')), '03:00');
  assert.equal(f.when(ts('2026-10-12T06:00:00Z'), ts('2026-10-10T15:00:00Z')), 'Mon 12 Oct 03:00');
});
t('today is decided in the Tower zone', () => {
  const f = UsFmt.make('America/Sao_Paulo');
  // 01:30 UTC on the 11th is still the 10th in São Paulo
  assert.equal(f.when(ts('2026-10-11T01:30:00Z'), ts('2026-10-10T15:00:00Z')), 'today 22:30');
  assert.equal(UsFmt.make('Asia/Tokyo').when(ts('2026-10-11T01:30:00Z'), ts('2026-10-10T15:00:00Z')), 'today 10:30');
});
t('an unknown zone falls back to UTC', () => {
  const f = UsFmt.make('Not/AZone');
  assert.equal(f.zone, 'UTC');
  assert.equal(f.time(ts('2026-10-12T06:00:00Z')), '06:00');
});
console.log(`${n} passed`);
