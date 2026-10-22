// Run: node tests/js/layout-ops.test.mjs
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const L = require('../../source/voz.cron/usr/local/emhttp/plugins/voz.cron/ui/layout-ops.js');
let n = 0;
const t = (name, fn) => { fn(); n++; console.log('ok  ', name); };
const items = () => [
  { type: 'folder', id: 'f-a', name: 'A', icon: '💾', scripts: ['x', 'y'] },
  { type: 'script', id: 'p' },
  { type: 'script', id: 'q' },
];
const shape = (its) => its.map((it) => (it.type === 'folder' ? `${it.id}[${it.scripts.join(',')}]` : it.id)).join(' ');

t('locate', () => {
  assert.deepEqual(L.locate(items(), 's:y'), { folder: 0, index: 1 });
  assert.deepEqual(L.locate(items(), 'f:f-a'), { folder: null, index: 0 });
  assert.deepEqual(L.locate(items(), 's:q'), { folder: null, index: 2 });
  assert.equal(L.locate(items(), 's:nope'), null);
});
t('into a folder appends', () => assert.equal(shape(L.moveTo(items(), 's:p', 'f:f-a', 'into')), 'f-a[x,y,p] q'));
t('before a script inside a folder', () => assert.equal(shape(L.moveTo(items(), 's:q', 's:x', 'before')), 'f-a[q,x,y] p'));
t('out of a folder after a top-level script', () => assert.equal(shape(L.moveTo(items(), 's:x', 's:q', 'after')), 'f-a[y] p q x'));
t('a folder lands beside the folder of its anchor', () => {
  const two = [...items(), { type: 'folder', id: 'f-b', name: 'B', icon: '', scripts: ['z'] }];
  assert.equal(shape(L.moveTo(two, 'f:f-b', 's:x', 'before')), 'f-b[z] f-a[x,y] p q');
  assert.equal(shape(L.moveTo(two, 'f:f-b', 'f:f-a', 'after')), 'f-a[x,y] f-b[z] p q');
});
t('impossible moves return the same array', () => {
  const i = items();
  assert.equal(L.moveTo(i, 'f:f-a', 'f:f-a', 'into'), i);
  assert.equal(L.moveTo(i, 's:p', 's:q', 'into'), i);
  assert.equal(L.moveTo(i, 's:nope', 's:p', 'before'), i);
  assert.equal(L.moveTo(i, 's:p', 's:p', 'after'), i);
});
t('inputs are never mutated', () => {
  const i = items();
  L.moveTo(i, 's:x', 's:q', 'after'); L.step(i, 's:y', 1); L.deleteFolder(i, 'f-a'); L.updateFolder(i, 'f-a', { name: 'Z' }); L.addFolder(i, 'New');
  assert.deepEqual(i, items());
});
t('Alt+arrow inside a folder swaps, at its edge steps out', () => {
  assert.equal(shape(L.step(items(), 's:y', -1)), 'f-a[y,x] p q');
  assert.equal(shape(L.step(items(), 's:x', -1)), 'x f-a[y] p q');
  assert.equal(shape(L.step(items(), 's:y', 1)), 'f-a[x] y p q');
});
t('Alt+arrow at top level jumps over a folder; the ends stay put', () => {
  assert.equal(shape(L.step(items(), 's:p', -1)), 'p f-a[x,y] q');
  assert.equal(shape(L.step(items(), 'f:f-a', 1)), 'p f-a[x,y] q');
  const i = items();
  assert.equal(L.step(i, 's:q', 1), i);
  assert.equal(L.step(i, 'f:f-a', -1), i);
});
t('add, rename, re-icon and delete a folder', () => {
  const { items: added, id } = L.addFolder(items(), 'Media');
  assert.match(id, /^f-[a-z0-9]+$/);
  assert.deepEqual(added[0], { type: 'folder', id, name: 'Media', icon: '📁', scripts: [] });
  assert.equal(L.updateFolder(added, id, { name: 'Films', icon: '🎬' })[0].name, 'Films');
  assert.equal(shape(L.deleteFolder(items(), 'f-a')), 'x y p q', 'its scripts stay where the folder was');
  assert.deepEqual(L.folders(items()).map((f) => f.id), ['f-a']);
  assert.equal(L.keyOf({ type: 'folder', id: 'f-a' }), 'f:f-a');
});
t('folder edits leave the input alone, and return it unchanged when the folder is unknown', () => {
  const i = items();
  const before = JSON.stringify(i);
  assert.notEqual(L.updateFolder(i, 'f-a', { name: 'Z' }), i);
  assert.notEqual(L.deleteFolder(i, 'f-a'), i);
  assert.notEqual(L.addFolder(i, 'N').items, i);
  assert.equal(JSON.stringify(i), before, 'input not mutated');
  assert.equal(L.updateFolder(i, 'f-nope', { name: 'Z' }), i);
  assert.equal(L.deleteFolder(i, 'f-nope'), i);
});
t('a folder anchored on its own child stays put', () => {
  const i = items();
  for (const where of ['before', 'after', 'into']) assert.equal(L.moveTo(i, 'f:f-a', 's:x', where), i, where);
});
console.log(`${n} passed`);
