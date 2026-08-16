/* ═══════════════════════════════════════════════════════════════════════════
   LIVE PRESENCE — one heartbeat per browser

   Loaded on every page so the admin panel can answer "who is on the site right
   now". Deliberately tiny and deliberately quiet: it must never be the reason a
   stranded motorist's booking form misbehaves.

   Three rules it follows:
     - Beat only while the tab is actually visible. A phone in a pocket with
       twelve old tabs open is not twelve people standing on the site, and
       beating in the background would say it was.
     - Never throw. Every call is wrapped; a failed beat is a beat that did not
       happen, nothing more.
     - Carry the login token when there is one, so a signed-in company shows up
       by name rather than as another anonymous visitor.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  var BEAT_MS = 25000;          // must stay well under presence_window_seconds
  var API = 'api';

  // A random id for this browser. Not a login, not shared with anyone, and it
  // exists purely so two tabs from one person are counted once.
  var key;
  try {
    key = localStorage.getItem('tl_pkey');
    if (!key) {
      key = 'p' + Math.random().toString(36).slice(2) + Date.now().toString(36);
      localStorage.setItem('tl_pkey', key);
    }
  } catch (e) {
    // Private browsing with storage blocked. Fall back to a per-tab id: the
    // count is then slightly high rather than the feature being dead.
    key = 'p' + Math.random().toString(36).slice(2) + Date.now().toString(36);
  }

  // Which screen this is, in words Ricardo will recognise in the admin list.
  function pageName() {
    var p = location.pathname.replace(/\/+$/, '');
    if (/\/track\/[a-f0-9]{32}$/.test(p)) return 'customer · tracking a job';
    if (/\/tow$/.test(p))                 return 'operator · dashboard';
    if (/\/join$/.test(p))                return 'operator · signup';
    if (/\/admin$/.test(p))               return 'admin';
    if (/\/terms$/.test(p) || /\/privacy$/.test(p)) return 'terms';
    if (p === '' || /\/index\.html$/.test(p)) return 'customer · booking';
    return p || 'customer · booking';
  }

  function token() {
    try {
      // The operator app and the admin panel keep their tokens separately.
      return localStorage.getItem('tl_token') || localStorage.getItem('tl_admin') || '';
    } catch (e) { return ''; }
  }

  function beat() {
    if (document.visibilityState !== 'visible') return;
    try {
      var t = token();
      fetch(API + '/presence/ping', {
        method: 'POST',
        headers: Object.assign(
          { 'Content-Type': 'application/json' },
          t ? { 'Authorization': 'Bearer ' + t } : {}
        ),
        body: JSON.stringify({
          key: key,
          page: pageName(),
          ref: document.referrer || null
        }),
        // Never let a heartbeat hold the page open or block a real request.
        keepalive: true
      }).catch(function () {});
    } catch (e) { /* nothing here is worth breaking a page over */ }
  }

  beat();
  setInterval(beat, BEAT_MS);
  // Coming back to the tab should show up immediately rather than up to a beat
  // later — this is the moment somebody actually returned to the site.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') beat();
  });
})();
