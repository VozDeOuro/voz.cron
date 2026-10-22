/* Pure operations on the folder layout: items are {type:'folder', id, name, icon, scripts:[ids]} or
   {type:'script', id}; keys are "s:<script id>" and "f:<folder id>". A function that changes the layout
   returns a new array (addFolder: in {items, id}); when it has nothing to do (unknown key or folder id, a move onto
   itself or into a non-folder, a step past an end) it returns the input itself, so callers can test
   `next !== items`. Inputs are never mutated. */
'use strict';
const UsLayout = (() => {
  const clone = (items) => items.map((it) => (it.type === 'folder' ? { ...it, scripts: [...it.scripts] } : { ...it }));
  const keyOf = (it) => (it.type === 'folder' ? 'f:' : 's:') + it.id;
  const idOf = (key) => key.slice(2);

  /** @returns {{folder: number|null, index: number}|null} folder: index of the containing folder item, or null at top level */
  function locate(items, key) {
    for (let i = 0; i < items.length; i++) {
      const it = items[i];
      if (keyOf(it) === key) return { folder: null, index: i };
      if (key.startsWith('s:') && it.type === 'folder') {
        const j = it.scripts.indexOf(idOf(key));
        if (j >= 0) return { folder: i, index: j };
      }
    }
    return null;
  }

  /** Removes key from a cloned list (mutating that clone) and returns what was removed. */
  function take(out, key) {
    const at = locate(out, key);
    if (!at) return null;
    if (at.folder === null) return out.splice(at.index, 1)[0];
    out[at.folder].scripts.splice(at.index, 1);
    return { type: 'script', id: idOf(key) };
  }

  /** Puts key before/after anchorKey, or 'into' folder anchorKey (at its end). Folders only live at top level. */
  function moveTo(items, key, anchorKey, where) {
    if (key === anchorKey) return items;
    const isFolder = key.startsWith('f:');
    if (where === 'into' && (isFolder || !anchorKey.startsWith('f:'))) return items;
    const from = locate(items, key), anchor = locate(items, anchorKey);
    if (!from || !anchor) return items;
    if (isFolder && anchor.folder === from.index) return items; // a folder cannot be placed beside its own child
    const out = clone(items);
    const moved = take(out, key);
    if (where === 'into') {
      out.find((it) => keyOf(it) === anchorKey).scripts.push(moved.id);
      return out;
    }
    let at = locate(out, anchorKey);
    if (!at) return items;
    if (isFolder && at.folder !== null) at = { folder: null, index: at.folder }; // beside the anchor's folder
    const pos = at.index + (where === 'after' ? 1 : 0);
    if (at.folder === null) out.splice(pos, 0, moved);
    else out[at.folder].scripts.splice(pos, 0, moved.id);
    return out;
  }

  /** Alt+Arrow: one place up (-1) or down (+1). A script at the edge of its folder steps out of it; at top
      level an item jumps over a whole folder. */
  function step(items, key, dir) {
    const at = locate(items, key);
    if (!at) return items;
    const where = dir < 0 ? 'before' : 'after';
    if (at.folder !== null) {
      const f = items[at.folder];
      const j = at.index + dir;
      return j >= 0 && j < f.scripts.length ? moveTo(items, key, `s:${f.scripts[j]}`, where) : moveTo(items, key, keyOf(f), where);
    }
    const j = at.index + dir;
    return j < 0 || j >= items.length ? items : moveTo(items, key, keyOf(items[j]), where);
  }

  function addFolder(items, name, icon = '📁') {
    const taken = new Set(items.filter((it) => it.type === 'folder').map((it) => it.id));
    let id;
    do { id = `f-${Math.random().toString(36).slice(2, 10)}`; } while (taken.has(id) || id.length < 3);
    return { items: [{ type: 'folder', id, name, icon, scripts: [] }, ...clone(items)], id };
  }

  const hasFolder = (items, id) => items.some((it) => it.type === 'folder' && it.id === id);

  function updateFolder(items, id, patch) {
    if (!hasFolder(items, id)) return items;
    return clone(items).map((it) => (it.type === 'folder' && it.id === id ? { ...it, ...patch } : it));
  }

  function deleteFolder(items, id) {
    if (!hasFolder(items, id)) return items;
    const out = [];
    for (const it of clone(items)) {
      if (it.type === 'folder' && it.id === id) out.push(...it.scripts.map((s) => ({ type: 'script', id: s })));
      else out.push(it);
    }
    return out;
  }

  const folders = (items) => items.filter((it) => it.type === 'folder');

  return { keyOf, locate, moveTo, step, addFolder, updateFolder, deleteFolder, folders };
})();
if (typeof module !== 'undefined') module.exports = UsLayout;
