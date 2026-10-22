/* Exit-code rules ("0=ok 1,3-5=warn *=fail"). Mirrors lib/ExitCodes.php; both are tested against
   tests/fixtures/exit-codes-cases.json. The server stays authoritative: this only previews and validates early. */
'use strict';
const UsExitCodes = (() => {
  const DEFAULT = '0=ok *=fail';
  const OUTCOMES = ['ok', 'warn', 'ignore', 'fail'];

  function parse(text) {
    const raw = String(text ?? '').trim();
    const src = raw || DEFAULT;
    if (/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(src)) return { error: 'control character in exit codes' };
    const rules = [];
    let star = false;
    for (const tok of src.split(/\s+/)) {
      const m = /^([^=]+)=([a-z]+)$/.exec(tok);
      if (!m) return { error: `bad rule "${tok}": use codes=outcome, e.g. 1=warn` };
      if (!OUTCOMES.includes(m[2])) return { error: `unknown outcome "${m[2]}" in "${tok}": use ok, warn, ignore or fail` };
      if (m[1] === '*') {
        if (star) return { error: `"*" used twice ("${tok}")` };
        star = true;
        rules.push({ codes: null, outcome: m[2] });
        continue;
      }
      const codes = [];
      for (const c of m[1].split(',')) {
        const r = /^(\d+)(?:-(\d+))?$/.exec(c);
        if (!r) return { error: `bad code "${c}" in "${tok}"` };
        const a = Number(r[1]), b = r[2] === undefined ? a : Number(r[2]);
        if (a > 255 || b > 255) return { error: `code "${c}" is outside 0-255` };
        if (a > b) return { error: `range "${c}" is reversed` };
        codes.push([a, b]);
      }
      rules.push({ codes, outcome: m[2] });
    }
    return { rules, text: raw === '' ? '' : raw.split(/\s+/).join(' ') };
  }

  function outcome(rules, exit) {
    if (exit < 0 || exit > 255) return 'fail';
    for (const r of rules) {
      if (r.codes === null) return r.outcome;
      if (r.codes.some(([a, b]) => exit >= a && exit <= b)) return r.outcome;
    }
    return 'fail';
  }

  const toRows = (rules) => rules.map((r) => ({ codes: r.codes === null ? '*' : r.codes.map(([a, b]) => (a === b ? `${a}` : `${a}-${b}`)).join(','), outcome: r.outcome }));
  const fromRows = (rows) => rows.filter((r) => r.codes.trim() !== '').map((r) => `${r.codes.replace(/\s+/g, '')}=${r.outcome}`).join(' ');

  return { DEFAULT, OUTCOMES, parse, outcome, toRows, fromRows };
})();
if (typeof module !== 'undefined') module.exports = UsExitCodes;
