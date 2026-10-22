/* Script header variables ("#key=value" lines at the top). Mirrors lib/Vars.php::raw exactly:
   scan from the top, skip blank lines and lines starting "#!" or "<?", stop at the first line not starting "#". */
'use strict';
const UsHeaderVars = (() => {
  const isSkip = (l) => l.startsWith('#!') || l.trim() === '' || l.startsWith('<?');
  const keyOf = (l) => { // Vars.php: explode('=', ltrim(trim($line), '#'), 2), trim key
    if (!l.includes('=')) return null;
    const k = l.trim().replace(/^#+/, '').split('=')[0].trim();
    return k === '' ? null : k;
  };

  // lines: without "\r". Returns header end index, header "#" var/comment line indexes, last leading "#!"/"<?" index.
  function scan(lines) {
    const hash = []; let lead = -1, end = lines.length;
    for (let i = 0; i < lines.length; i++) {
      const l = lines[i];
      if (isSkip(l)) { if (l.trim() !== '' && !hash.length) lead = i; continue; }
      if (!l.startsWith('#')) { end = i; break; }
      hash.push(i);
    }
    return { hash, lead, end };
  }

  function parse(text) {
    const lines = String(text).replace(/\r/g, '').split('\n');
    const out = {};
    for (const i of scan(lines).hash) { const k = keyOf(lines[i]); if (k !== null) out[k] = lines[i].trim().replace(/^#+/, '').split('=').slice(1).join('=').trim(); }
    return out;
  }

  const truthy = (v) => ['1', 'true', 'on', 'yes'].includes(String(v ?? '').trim().toLowerCase()); // FILTER_VALIDATE_BOOLEAN

  function setHeaderVar(text, key, on) {
    const crlf = String(text).includes('\r\n');
    let raw = String(text).split('\n'); // keep each line's own "\r" so mixed endings survive
    const bare = (l) => l.replace(/\r$/, '');
    let { hash } = scan(raw.map(bare));
    const drop = new Set(hash.filter((i) => keyOf(bare(raw[i])) === key));
    raw = raw.filter((_, i) => !drop.has(i));
    if (on) {
      const s = scan(raw.map(bare));
      const at = s.hash.length ? s.hash[s.hash.length - 1] + 1 : s.lead + 1;
      raw.splice(at, 0, `#${key}=true` + (crlf ? '\r' : ''));
    }
    return raw.join('\n');
  }

  return { setHeaderVar, parse, truthy };
})();
if (typeof module !== 'undefined') { module.exports = UsHeaderVars.setHeaderVar; module.exports.parse = UsHeaderVars.parse; module.exports.truthy = UsHeaderVars.truthy; }
