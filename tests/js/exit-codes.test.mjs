// Run: node tests/js/exit-codes.test.mjs   (same cases as tests/ExitCodesTest.php)
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const X = require('../../source/voz.cron/usr/local/emhttp/plugins/voz.cron/ui/exit-codes.js');
const cases = JSON.parse(readFileSync(new URL('../fixtures/exit-codes-cases.json', import.meta.url), 'utf8'));
let n = 0;
const t = (name, fn) => { fn(); n++; console.log('ok  ', name); };

for (const c of cases.valid) {
  t(`valid ${JSON.stringify(c.in)}`, () => {
    const p = X.parse(c.in);
    assert.equal(p.error, undefined);
    assert.equal(p.text, c.text);
    for (const [exit, want] of c.probe) assert.equal(X.outcome(p.rules, exit), want, `exit ${exit}`);
  });
}
for (const c of cases.invalid) {
  t(`invalid ${JSON.stringify(c.in)}`, () => {
    const p = X.parse(c.in);
    assert.ok(p.error, 'rejected');
    assert.ok(p.error.includes(c.has), p.error);
  });
}
t('rows round-trip and empty rows are ignored', () => {
  const rows = X.toRows(X.parse('0=ok 1,3-5=warn *=fail').rules);
  assert.deepEqual(rows, [{ codes: '0', outcome: 'ok' }, { codes: '1,3-5', outcome: 'warn' }, { codes: '*', outcome: 'fail' }]);
  assert.equal(X.fromRows(rows), '0=ok 1,3-5=warn *=fail');
  assert.equal(X.fromRows([...rows, { codes: '  ', outcome: 'warn' }]), '0=ok 1,3-5=warn *=fail');
  assert.equal(X.fromRows([{ codes: ' 1 , 2 ', outcome: 'warn' }]), '1,2=warn');
});
console.log(`${n} passed`);
