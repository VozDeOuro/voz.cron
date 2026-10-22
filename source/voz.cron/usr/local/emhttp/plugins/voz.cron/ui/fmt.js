/* Tower-time formatting: every time is shown in the server's zone (list payload "tz"), never the browser's. */
'use strict';
const UsFmt = (() => {
  function make(tz) {
    let zone = 'UTC';
    try { new Intl.DateTimeFormat('en-US', { timeZone: tz }); zone = tz; } catch { /* unknown zone: UTC */ }
    const dayKey = new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' });
    const hm = new Intl.DateTimeFormat('en-GB', { timeZone: zone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
    const day = new Intl.DateTimeFormat('en-GB', { timeZone: zone, weekday: 'short', day: 'numeric', month: 'short' });
    const time = (ts) => hm.format(new Date(ts * 1000));
    const when = (ts, now = Date.now() / 1000) => {
      const d = new Date(ts * 1000);
      if (dayKey.format(d) === dayKey.format(new Date(now * 1000))) return `today ${time(ts)}`;
      // Assembled from parts: ICU builds differ on the separator ("Mon 12 Oct" in Node, "Mon, 12 Oct" in Chromium).
      const p = Object.fromEntries(day.formatToParts(d).map((x) => [x.type, x.value]));
      return `${p.weekday} ${p.day} ${p.month} ${time(ts)}`;
    };
    return { zone, time, when };
  }
  return { make };
})();
if (typeof module !== 'undefined') module.exports = UsFmt;
