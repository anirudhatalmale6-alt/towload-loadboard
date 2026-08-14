/* ══════════════════════════════════════════════════════════════════════════
   SERVICE WORKER — the part that runs when the app is closed.

   Its only critical job is the `push` handler. Everything else here is
   deliberately conservative: a towing board that shows stale jobs is worse
   than one that shows an error, so nothing from the API is ever cached and
   navigations always try the network first.

   Scope is this folder, so it is registered from /towload/ and controls
   /towload/*. Bump CACHE_VERSION on any shell change — an old service worker
   serving an old index.html is the classic "he says he deployed it but I still
   see the old screen".
   ══════════════════════════════════════════════════════════════════════════ */

const CACHE_VERSION = 'towload-v1';
const SHELL = ['./', './index.html', './i18n.js', './manifest.json'];

self.addEventListener('install', (event) => {
  // Take over immediately rather than waiting for every tab to close. An
  // operator with the board open for three days would otherwise never get the
  // new worker, which is exactly the person who most needs the alerts to work.
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then((c) => c.addAll(SHELL).catch(() => {}))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Never cache the API. A job list, a price or a surge multiplier served from
  // yesterday's cache is actively harmful — someone would drive to a job that
  // was taken an hour ago.
  if (url.pathname.includes('/api/')) return;

  // Network first, cache as the fallback. Offline in a dead zone still opens
  // the app; online always gets the current version.
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res && res.ok && url.origin === self.location.origin) {
          const copy = res.clone();
          caches.open(CACHE_VERSION).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      })
      .catch(() => caches.match(req).then((hit) => hit || caches.match('./')))
  );
});

/* ─── The alert ───────────────────────────────────────────────────────────
   Every push MUST result in a visible notification. Chrome and Safari both
   revoke push permission from a site that receives a message and shows
   nothing, and the revocation is silent — the operator simply stops getting
   jobs and nobody finds out for a week. So the catch path still notifies.
   ───────────────────────────────────────────────────────────────────────── */
self.addEventListener('push', (event) => {
  let d = {};
  try {
    d = event.data ? event.data.json() : {};
  } catch (e) {
    d = {};
  }

  const title = d.title || 'New tow job';
  const body  = d.body  || 'Open the board to see it.';

  const options = {
    body,
    icon: './icons/icon-192.png',
    badge: './icons/badge-96.png',
    // Same tag replaces rather than stacks, so a phone left in a cup holder
    // through a busy hour shows the latest job instead of forty banners.
    tag: d.tag || 'towload',
    renotify: true,
    // A towing call is a decision, not an FYI. Keep it on screen.
    requireInteraction: d.kind === 'new_job',
    timestamp: Date.now(),
    data: { url: d.url || './', call_id: d.call_id || null, kind: d.kind || 'info' },
    vibrate: [200, 80, 200],
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || './';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
      // Reuse a window that is already open — on a phone this is the difference
      // between landing on the job and landing on a second copy of the app that
      // has to log in again.
      for (const w of wins) {
        if (w.url.includes('/towload') && 'focus' in w) {
          if ('navigate' in w) w.navigate(target).catch(() => {});
          return w.focus();
        }
      }
      return self.clients.openWindow(target);
    })
  );
});

/* ─── Recovering a rotated subscription ───────────────────────────────────
   Push services expire and reissue subscriptions on their own schedule. When
   that happens the device stops receiving anything while continuing to look
   perfectly healthy from the phone's side — no error, no prompt, no symptom
   until someone notices they haven't had a job in a fortnight.

   This fires with no page open, so there is no localStorage to read the token
   from. It authenticates on the session cookie instead, which is the entire
   reason that cookie exists. If it fails, the page re-registers on next open.
   ───────────────────────────────────────────────────────────────────────── */
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    try {
      const keyRes = await fetch('./api/push/key', { credentials: 'include' });
      const key = (await keyRes.json()).public_key;

      const sub = await self.registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: b64ToBytes(key),
      });

      await fetch('./api/push/subscribe', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...sub.toJSON(), platform: 'unknown', standalone: true }),
      });
    } catch (e) {
      // Fall through — the page will re-register when it is next opened.
    }
    const wins = await self.clients.matchAll({ includeUncontrolled: true });
    wins.forEach((w) => w.postMessage({ type: 'resubscribe' }));
  })());
});

// applicationServerKey must be raw bytes; the API hands out base64url.
function b64ToBytes(b64) {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4);
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}
