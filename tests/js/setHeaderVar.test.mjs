// Run: node tests/js/setHeaderVar.test.mjs
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const setHeaderVar = require('../../source/voz.cron/usr/local/emhttp/plugins/voz.cron/ui/header-vars.js');
const { parse } = setHeaderVar;
let n = 0;
const t = (name, fn) => { fn(); n++; console.log('ok  ', name); };

t('A uncheck removes the var', () => {
  const out = setHeaderVar('#!/bin/bash\n\n#arrayStarted=true\necho hi\n', 'arrayStarted', false);
  assert.equal(out, '#!/bin/bash\n\necho hi\n');
});
t('A2 uncheck removes every occurrence, any spacing', () => {
  const out = setHeaderVar('#!/bin/bash\n# arrayStarted = true\n#name=x\n##arrayStarted=1\necho\n#arrayStarted=true\n', 'arrayStarted', false);
  assert.equal(out, '#!/bin/bash\n#name=x\necho\n#arrayStarted=true\n'); // line after the header is not header
});
t('B check replaces =false with one =true', () => {
  const out = setHeaderVar('#!/bin/bash\n\n#noParity=false\necho\n', 'noParity', true);
  assert.equal(out, '#!/bin/bash\n#noParity=true\n\necho\n'); // removed, then inserted after the leading #! line
  assert.ok(!out.includes('=false'));
  assert.equal(out.match(/noParity/g).length, 1);
});
t('B2 check is idempotent', () => {
  const once = setHeaderVar('#!/bin/bash\necho\n', 'clearLog', true);
  assert.equal(setHeaderVar(once, 'clearLog', true), once);
});
t('C php: inserted after #name, <?php stays first', () => {
  const out = setHeaderVar('<?php\n#name=x\necho 1;\n', 'arrayStarted', true);
  assert.equal(out, '<?php\n#name=x\n#arrayStarted=true\necho 1;\n');
});
t('C2 php with no header: after <?php, never above it', () => {
  assert.equal(setHeaderVar('#!/usr/bin/php\n<?php\necho 1;\n', 'noParity', true), '#!/usr/bin/php\n<?php\n#noParity=true\necho 1;\n');
});
t('D CRLF stays CRLF', () => {
  const out = setHeaderVar('#!/bin/bash\r\n#name=x\r\necho\r\n', 'arrayStarted', true);
  assert.equal(out, '#!/bin/bash\r\n#name=x\r\n#arrayStarted=true\r\necho\r\n');
  assert.ok(!/[^\r]\n/.test(out));
  assert.equal(setHeaderVar(out, 'arrayStarted', false), '#!/bin/bash\r\n#name=x\r\necho\r\n');
});
t('E no header, no shebang: inserted at top', () => {
  assert.equal(setHeaderVar('echo hi\n', 'clearLog', true), '#clearLog=true\necho hi\n');
});
t('F shebang only: inserted on line 2', () => {
  assert.equal(setHeaderVar('#!/bin/bash', 'clearLog', true), '#!/bin/bash\n#clearLog=true');
  assert.equal(setHeaderVar('#!/bin/bash\n', 'clearLog', true), '#!/bin/bash\n#clearLog=true\n');
});
t('parse mirrors Vars::raw (stops at first code line, skips blank/#!/<?)', () => {
  assert.deepEqual(parse('#!/bin/bash\r\n\r\n# name = My script\r\n#comment\r\n#arrayStarted=true\r\necho\r\n#noParity=true\r\n'),
    { name: 'My script', arrayStarted: 'true' });
});
console.log(`${n} passed`);
