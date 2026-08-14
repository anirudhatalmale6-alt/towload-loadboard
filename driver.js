/* ══════════════════════════════════════════════════════════════════════════
   DRIVER LOCATION SHARING — browser version

   This is what feeds the customer's moving map today, before the iPhone app
   exists. It is also the honest demonstration of why that app is needed: a
   browser can only report a position while this page is open and in front of
   the driver. Lock the phone or switch apps and it stops, because iOS gives a
   web page no background location at all. The native app removes exactly that
   limitation and nothing else.

   The rules, enforced here and again on the server:
     - Only while the driver has a job that is actually live.
     - It stops by itself the moment that job closes, on either side.
     - The driver can switch it off at any time, and can see that it is on.

   Nothing here can track anybody between jobs. There is no code path for it.
   ══════════════════════════════════════════════════════════════════════════ */

const DRIVER = {
  watchId: null,
  callId: null,
  lastSent: 0,
  intervalSec: 10,
  paused: localStorage.getItem('tl_share_off') === '1',
  lastFix: null,
};

const LIVE_STATUSES = ['awarded', 'en_route', 'on_scene', 'in_progress'];

/**
 * Called after every board refresh. Decides whether this driver should be
 * sharing right now, and starts or stops accordingly.
 */
function syncDriverSharing() {
  if (!ME || ME.account.account_type !== 'tower') return;

  const live = (DATA || []).find(c => LIVE_STATUSES.includes(c.status) && c.is_mine !== false);
  const callId = live ? live.id : null;

  if (!callId || DRIVER.paused) {
    stopSharing();
    renderShareBar(callId);
    return;
  }

  if (DRIVER.callId !== callId) {
    stopSharing();
    DRIVER.callId = callId;
    startSharing();
  }
  renderShareBar(callId);
}

function startSharing() {
  if (!navigator.geolocation || DRIVER.watchId !== null) return;

  DRIVER.watchId = navigator.geolocation.watchPosition(
    onFix,
    () => renderShareBar(DRIVER.callId, 'denied'),
    // enableHighAccuracy is the whole point here — a 1km fix is useless for a
    // map that is meant to show a truck turning onto the right street.
    { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 }
  );
}

function stopSharing() {
  if (DRIVER.watchId !== null) {
    navigator.geolocation.clearWatch(DRIVER.watchId);
    DRIVER.watchId = null;
  }
  DRIVER.callId = null;
}

async function onFix(pos) {
  // watchPosition can fire several times a second while a phone settles. The
  // server does not need that and the driver's data plan certainly does not.
  const now = Date.now();
  if (now - DRIVER.lastSent < DRIVER.intervalSec * 1000) return;
  DRIVER.lastSent = now;

  const c = pos.coords;
  DRIVER.lastFix = { at: now, accuracy: Math.round(c.accuracy || 0) };

  const r = await api('/tracking/ping', {
    method: 'POST',
    body: JSON.stringify({
      call_id: DRIVER.callId,
      lat: c.latitude,
      lng: c.longitude,
      accuracy_m: c.accuracy,
      heading: (c.heading !== null && !isNaN(c.heading)) ? c.heading : null,
      // The browser reports metres per second; the rest of this product is
      // American and thinks in miles per hour.
      speed_mph: (c.speed !== null && !isNaN(c.speed)) ? c.speed * 2.23694 : null,
      recorded_at: Math.round(pos.timestamp / 1000),
    }),
  }).catch(() => null);

  // The server owns the decision about when tracking ends. If it says stop,
  // stop — even if this page still believes the job is live.
  if (r && r.success && r.keep_tracking === false) {
    stopSharing();
    renderShareBar(null);
    load();
    return;
  }
  if (r && r.next_ping_seconds) DRIVER.intervalSec = r.next_ping_seconds;
  renderShareBar(DRIVER.callId);
}

function toggleSharing() {
  DRIVER.paused = !DRIVER.paused;
  localStorage.setItem('tl_share_off', DRIVER.paused ? '1' : '0');
  syncDriverSharing();
}

/**
 * The visible switch. A driver must always be able to see that his location is
 * being sent and turn it off in one tap — if the only way to stop it is to log
 * out, he will log out, and then he stops getting jobs too.
 */
function renderShareBar(callId, err) {
  const box = document.getElementById('shareBar');
  if (!box) return;

  if (!callId) { box.innerHTML = ''; return; }

  if (err === 'denied') {
    box.innerHTML = `<div class="share-bar off">
      <span class="dot err"></span>
      <div><b>${t('d.denied_h')}</b><div class="share-sub">${t('d.denied_p')}</div></div>
    </div>`;
    return;
  }

  if (DRIVER.paused) {
    box.innerHTML = `<div class="share-bar off">
      <span class="dot"></span>
      <div><b>${t('d.off_h')}</b><div class="share-sub">${t('d.off_p')}</div></div>
      <button class="btn ghost" onclick="toggleSharing()">${t('d.turn_on')}</button>
    </div>`;
    return;
  }

  const age = DRIVER.lastFix ? Math.round((Date.now() - DRIVER.lastFix.at) / 1000) : null;
  box.innerHTML = `<div class="share-bar on">
    <span class="dot ok"></span>
    <div><b>${t('d.on_h')}</b><div class="share-sub">${
      age === null ? t('d.on_waiting') : t('d.on_last', { n: age })
    }</div></div>
    <button class="btn ghost" onclick="toggleSharing()">${t('d.turn_off')}</button>
  </div>`;
}

// Keep the "last fix" age honest while nothing else is happening. A bar that
// says "2 seconds ago" for four minutes is how a driver ends up believing the
// customer can see him when they cannot.
setInterval(() => { if (DRIVER.callId) renderShareBar(DRIVER.callId); }, 5000);
