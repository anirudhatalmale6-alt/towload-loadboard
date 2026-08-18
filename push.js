/* ══════════════════════════════════════════════════════════════════════════
   PUSH ALERTS — the browser half.

   The whole feature lives or dies on one thing: an operator getting through
   the enable flow without help. On iPhone that flow has a step nobody
   discovers by themselves — the site must be added to the Home Screen BEFORE
   iOS will even expose the push API — so most of the code here is about
   detecting exactly which of five states a phone is in and saying the one
   sentence that moves it forward.

   States:
     unsupported       old browser, or a desktop with push disabled
     ios_needs_install iPhone, still in Safari — the common case
     ready             API available, permission not yet asked
     blocked           permission denied; only Settings can undo it
     on                subscribed and registered server-side
   ══════════════════════════════════════════════════════════════════════════ */

const TLPush = (() => {
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
                (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true;

  const hasAPI = () =>
    'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  let reg = null;

  function platform() {
    if (isIOS) return 'ios';
    if (/Android/.test(navigator.userAgent)) return 'android';
    return 'desktop';
  }

  async function state() {
    // Order matters. On iPhone in Safari, PushManager simply does not exist,
    // so "unsupported" would be technically true and completely useless as an
    // instruction — the phone CAN do this, it just needs installing first.
    if (isIOS && !isStandalone()) return 'ios_needs_install';
    if (!hasAPI()) return 'unsupported';
    if (Notification.permission === 'denied') return 'blocked';

    try {
      reg = reg || await navigator.serviceWorker.getRegistration('./');
      const sub = reg && await reg.pushManager.getSubscription();
      if (sub && Notification.permission === 'granted') return 'on';
    } catch (e) { /* fall through to ready */ }

    return 'ready';
  }

  function urlB64ToUint8(b64) {
    const pad = '='.repeat((4 - (b64.length % 4)) % 4);
    const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
  }

  async function registerWorker() {
    if (!('serviceWorker' in navigator)) return null;
    try {
      reg = await navigator.serviceWorker.register('sw.js', { scope: './' });
      await navigator.serviceWorker.ready;
      return reg;
    } catch (e) {
      return null;
    }
  }

  /**
   * Must be called straight off a tap. Safari discards a permission request
   * that is more than a moment removed from a user gesture, and it does so
   * silently — the prompt just never appears and the operator concludes the
   * button is broken.
   */
  async function enable() {
    if (!hasAPI()) throw new Error(t('p.err_unsupported'));

    if (!reg) await registerWorker();
    if (!reg) throw new Error(t('p.err_sw'));

    const perm = await Notification.requestPermission();
    if (perm !== 'granted') throw new Error(t('p.err_denied'));

    const keyRes = await api('/push/key');
    if (!keyRes.success || !keyRes.public_key) throw new Error(t('p.err_server'));

    // An existing subscription from a previous key would be silently dead, so
    // replace rather than reuse when the key does not match.
    let sub = await reg.pushManager.getSubscription();
    if (sub) {
      const current = btoa(String.fromCharCode(...new Uint8Array(sub.options.applicationServerKey || [])))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
      if (current !== keyRes.public_key) { await sub.unsubscribe(); sub = null; }
    }
    if (!sub) {
      sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlB64ToUint8(keyRes.public_key),
      });
    }

    const body = sub.toJSON();
    body.platform = platform();
    body.standalone = isStandalone();

    const res = await api('/push/subscribe', { method: 'POST', body: JSON.stringify(body) });
    if (!res.success) throw new Error(res.error || t('p.err_server'));
    return res;
  }

  async function disable() {
    // Stop reporting before the subscription goes away, or the watch keeps
    // firing against an endpoint the server no longer has a row for.
    stopLocation();
    if (!reg) reg = await navigator.serviceWorker.getRegistration('./');
    const sub = reg && await reg.pushManager.getSubscription();
    if (sub) {
      await api('/push/unsubscribe', {
        method: 'POST', body: JSON.stringify({ endpoint: sub.endpoint }),
      });
      await sub.unsubscribe();
    }
  }

  async function test() {
    return api('/push/test', { method: 'POST' });
  }

  // ─── Where this machine is ────────────────────────────────────────────────
  //
  // So a dispatcher on a laptop in the truck, or an operator working from a
  // phone browser rather than the app, is matched against where he actually is
  // instead of the yard. Exactly the same field the native app fills in.
  //
  // watchPosition rather than a timer around getCurrentPosition: the browser
  // only reports when the machine has actually moved, which on a desktop that
  // never moves means one fix and then silence, at no ongoing cost.
  //
  // A browser location is often a wifi lookup rather than GPS, so anything the
  // browser is not reasonably confident about is dropped. A 5km-accurate fix
  // would put a truck on the wrong side of a city and quietly change which
  // jobs it hears about.
  let watchId = null;
  let lastSentAt = 0;
  const MIN_INTERVAL_MS = 120000;

  async function sendLocation(pos, endpoint) {
    if (!pos || !pos.coords) return;
    const acc = pos.coords.accuracy;
    if (!(acc > 0) || acc > 2000) return;
    if (Date.now() - lastSentAt < MIN_INTERVAL_MS) return;
    lastSentAt = Date.now();
    try {
      await api('/push/location', {
        method: 'POST',
        body: JSON.stringify({
          endpoint: endpoint,
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          accuracy_m: Math.round(acc),
        }),
      });
    } catch (e) {
      // Never surfaced. Failing to report costs nothing immediately — the
      // server falls back to the yard — and an error box about it on a
      // dispatch screen would be worse than the problem.
      lastSentAt = 0;
    }
  }

  /**
   * Start reporting. Safe to call repeatedly; only ever one watch.
   * Does nothing at all without a push subscription, because the subscription
   * endpoint is what identifies which device row to update.
   */
  async function startLocation() {
    if (watchId !== null || !navigator.geolocation) return false;
    if (!reg) reg = await navigator.serviceWorker.getRegistration('./');
    const sub = reg && await reg.pushManager.getSubscription();
    if (!sub) return false;

    const endpoint = sub.endpoint;
    watchId = navigator.geolocation.watchPosition(
      (pos) => { sendLocation(pos, endpoint); },
      () => { /* refused or unavailable: the yard still covers us */ },
      { enableHighAccuracy: false, maximumAge: 300000, timeout: 30000 }
    );
    return true;
  }

  function stopLocation() {
    if (watchId !== null) {
      navigator.geolocation.clearWatch(watchId);
      watchId = null;
    }
  }

  return { state, enable, disable, test, registerWorker, isIOS, isStandalone, platform,
           startLocation, stopLocation };
})();

/* ─── UI ────────────────────────────────────────────────────────────────── */

// Where the native apps live, asked once and cached for the page. Empty string
// means not published yet.
let APP_LINKS = null;
async function appLinks() {
  if (APP_LINKS) return APP_LINKS;
  try {
    const r = await api('/push/prefs');
    APP_LINKS = r && r.app ? r.app : { ios: '', android: '' };
  } catch (e) { APP_LINKS = { ios: '', android: '' }; }
  return APP_LINKS;
}

// The Apple mark, drawn rather than loaded, so the button needs no asset and
// works offline like the rest of the shell.
function appleLogoSVG() {
  return `<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">
    <path d="M17.05 12.54c-.02-2.2 1.8-3.26 1.88-3.31-1.02-1.5-2.61-1.7-3.18-1.72-1.35-.14-2.64.79-3.33.79-.69 0-1.75-.77-2.87-.75-1.48.02-2.84.86-3.6 2.18-1.53 2.66-.39 6.6 1.1 8.76.73 1.06 1.6 2.25 2.74 2.21 1.1-.04 1.52-.71 2.85-.71 1.33 0 1.7.71 2.86.69 1.18-.02 1.93-1.08 2.65-2.14.83-1.22 1.18-2.41 1.2-2.47-.03-.01-2.3-.88-2.32-3.5zM14.9 5.6c.6-.74 1.01-1.75.9-2.77-.87.04-1.94.59-2.57 1.32-.56.65-1.06 1.7-.93 2.7.97.08 1.97-.5 2.6-1.25z"/>
  </svg>`;
}

// An App Store button. Apple's own badge is a licensed image asset; this is the
// same shape drawn locally, which keeps the page self-contained.
function appStoreButton(url, kind) {
  const label = kind === 'android' ? t('p.get_on_play') : t('p.get_on_app_store');
  const small = kind === 'android' ? t('p.get_it_on')   : t('p.download_on_the');
  const logo  = kind === 'android' ? '' : appleLogoSVG();
  return `<a class="store-btn" href="${esc(url)}" target="_blank" rel="noopener">
    ${logo}<span class="store-txt"><small>${small}</small><b>${label}</b></span></a>`;
}

// Shown at the top of the board until alerts are on. Deliberately hard to
// ignore: an operator who never turns this on gets no jobs, blames the
// platform, and leaves — and neither of us ever finds out why.
async function renderPushBanner() {
  const box = document.getElementById('pushBanner');
  if (!box || !ME || ME.account.account_type !== 'tower') return;

  const s = await TLPush.state();
  if (s === 'on') { box.innerHTML = ''; return; }

  if (s === 'ios_needs_install') {
    const links = await appLinks();

    // Once the app is published this is one button instead of three steps.
    // Until then the steps stay, because they are the ONLY way an iPhone
    // receives a job alert — swapping them for a dead button would switch
    // alerts off for every iPhone operator without telling anybody.
    if (links.ios) {
      box.innerHTML = `
        <div class="push-bar warn">
          <h4>${t('p.app_h')}</h4>
          <p>${t('p.app_p')}</p>
          ${appStoreButton(links.ios, 'ios')}
        </div>`;
      return;
    }

    box.innerHTML = `
      <div class="push-bar warn">
        <h4>${t('p.ios_h')}</h4>
        <p>${t('p.ios_p')}</p>
        <ol class="push-steps">
          <li><span class="sq">${shareIconSVG()}</span> ${t('p.ios_1')}</li>
          <li><span class="sq">+</span> ${t('p.ios_2')}</li>
          <li><span class="sq">&#10003;</span> ${t('p.ios_3')}</li>
        </ol>
        <p class="push-fine">${t('p.ios_fine')}</p>
      </div>`;
    return;
  }

  if (s === 'blocked') {
    box.innerHTML = `
      <div class="push-bar err">
        <h4>${t('p.blocked_h')}</h4>
        <p>${t('p.blocked_p')}</p>
      </div>`;
    return;
  }

  if (s === 'unsupported') {
    box.innerHTML = `
      <div class="push-bar">
        <h4>${t('p.unsupported_h')}</h4>
        <p>${t('p.unsupported_p')}</p>
      </div>`;
    return;
  }

  box.innerHTML = `
    <div class="push-bar warn">
      <h4>${t('p.off_h')}</h4>
      <p>${t('p.off_p')}</p>
      <button class="btn go" onclick="doEnablePush(this)">${t('p.enable')}</button>
    </div>`;
}

function shareIconSVG() {
  // The iOS Share glyph. Described in words it is "the square with the arrow",
  // which people hunt for; drawn, they find it instantly.
  return `<svg viewBox="0 0 24 24" width="15" height="15" fill="none"
    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M12 16V4"/><path d="M8 8l4-4 4 4"/>
    <path d="M5 12v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6"/></svg>`;
}

async function doEnablePush(btn) {
  const original = btn.textContent;
  btn.disabled = true;
  btn.textContent = t('p.enabling');
  try {
    await TLPush.enable();
    // Now there is a subscription to attach a position to.
    TLPush.startLocation().catch(() => {});
    await renderPushBanner();
    if (document.getElementById('alertsPane')) await renderAlertsPane();
  } catch (e) {
    btn.disabled = false;
    btn.textContent = original;
    const p = btn.parentElement.querySelector('p');
    if (p) p.textContent = e.message;
  }
}

/* ─── Settings pane ─────────────────────────────────────────────────────── */

async function renderAlertsPane() {
  const pane = document.getElementById('alertsPane');
  if (!pane) return;

  const [s, prefsRes, devRes] = await Promise.all([
    TLPush.state(), api('/push/prefs'), api('/push/devices'),
  ]);
  const p = prefsRes.prefs || {};
  const devices = devRes.devices || [];
  APP_LINKS = prefsRes.app || APP_LINKS || { ios: '', android: '' };
  const hasApp = !!(TLPush.isIOS ? APP_LINKS.ios : APP_LINKS.android);

  const statusRow = {
    on:                `<span class="dot ok"></span> ${t('p.st_on')}`,
    ready:             `<span class="dot warn"></span> ${t('p.st_ready')}`,
    ios_needs_install: `<span class="dot warn"></span> ${hasApp ? t('p.st_get_app') : t('p.st_ios')}`,
    blocked:           `<span class="dot err"></span> ${t('p.st_blocked')}`,
    unsupported:       `<span class="dot err"></span> ${t('p.st_unsupported')}`,
  }[s];

  pane.innerHTML = `
    <div class="pane">
      <div class="pane-h">
        <h3>${t('p.this_phone')}</h3>
        <div class="pane-status">${statusRow}</div>
      </div>
      <div class="pane-b">
        ${s === 'on'
          ? `<button class="btn go" onclick="doTestPush(this)">${t('p.send_test')}</button>
             <button class="btn ghost" onclick="doDisablePush(this)">${t('p.turn_off')}</button>
             <div id="testResult" class="push-fine"></div>`
          : s === 'ready'
            ? `<button class="btn go" onclick="doEnablePush(this)">${t('p.enable')}</button><p></p>`
            : s === 'ios_needs_install' && hasApp
              // He asked for the download, not the Home Screen walkthrough.
              // The walkthrough only survives while there is no app to point at.
              ? `<p>${t('p.app_p')}</p>${appStoreButton(APP_LINKS.ios, 'ios')}`
              : `<p class="push-fine">${s === 'ios_needs_install' ? t('p.ios_p') : t('p.blocked_p')}</p>`}
      </div>
    </div>

    <div class="pane">
      <div class="pane-h"><h3>${t('p.when_h')}</h3></div>
      <div class="pane-b">
        <label class="chk"><input type="checkbox" id="apEnabled" ${p.enabled ? 'checked' : ''}>
          ${t('p.pref_enabled')}</label>

        <div class="f">
          <label>${t('p.pref_radius')}</label>
          <input type="number" id="apRadius" min="1" max="150" placeholder="${p.service_radius || 25}"
                 value="${p.radius_miles ?? ''}">
          <div class="hint">${t('p.pref_radius_hint', { n: p.service_radius || 25 })}</div>
        </div>

        <div class="f">
          <label>${t('p.pref_min')}</label>
          <input type="number" id="apMin" min="0" max="1000" step="5" value="${p.min_payout || 0}">
          <div class="hint">${t('p.pref_min_hint')}</div>
        </div>

        <div class="f2">
          <div class="f"><label>${t('p.pref_quiet_from')}</label>
            <input type="time" id="apQs" value="${p.quiet_start || ''}"></div>
          <div class="f"><label>${t('p.pref_quiet_to')}</label>
            <input type="time" id="apQe" value="${p.quiet_end || ''}"></div>
        </div>
        <div class="f">
          <label>${t('p.pref_tz')}</label>
          <select id="apTz">${tzOptions(p.timezone)}</select>
          <div class="hint">${t('p.pref_tz_hint')}</div>
        </div>
        ${p.is_24_7 ? `<div class="hint warn-text">${t('p.pref_247')}</div>` : ''}

        <button class="btn go" onclick="saveAlertPrefs(this)">${t('p.save')}</button>
        <div id="apMsg" class="push-fine"></div>
      </div>
    </div>

    <div class="pane">
      <div class="pane-h">
        <h3>${t('d.yard_h')}</h3>
        <div class="pane-status">${p.base_set
          ? `<span class="dot ok"></span> ${t('d.yard_set')}`
          : `<span class="dot warn"></span> ${t('d.yard_unset')}`}</div>
      </div>
      <div class="pane-b">
        <p class="push-fine" style="margin-top:0">${t('d.yard_p')}</p>
        <button class="btn go" onclick="setYard(this)">${t('d.yard_btn')}</button>
        <div id="yardMsg" class="push-fine"></div>
      </div>
    </div>

    <div class="pane">
      <div class="pane-h"><h3>${t('p.devices_h')}</h3></div>
      <div class="pane-b">
        ${devices.length ? devices.map(deviceRow).join('') : `<p class="push-fine">${t('p.no_devices')}</p>`}
      </div>
    </div>`;
}

// A tower operating in Alaska and one in Florida cannot share a list of three
// zones. These are the ones the lower 48 plus AK/HI actually fall into.
function tzOptions(current) {
  const zones = [
    ['America/New_York',    'Eastern'],
    ['America/Chicago',     'Central'],
    ['America/Denver',      'Mountain'],
    ['America/Phoenix',     'Arizona (no DST)'],
    ['America/Los_Angeles', 'Pacific'],
    ['America/Anchorage',   'Alaska'],
    ['Pacific/Honolulu',    'Hawaii'],
  ];
  return zones.map(([v, label]) =>
    `<option value="${v}" ${v === current ? 'selected' : ''}>${label}</option>`).join('');
}

function deviceRow(d) {
  const health = {
    ok:            ['ok',   t('p.dev_ok')],
    untested:      ['warn', t('p.dev_untested')],
    not_installed: ['err',  t('p.dev_not_installed')],
    stopped:       ['err',  t('p.dev_stopped')],
  }[d.health] || ['warn', d.health];

  const when = d.last_success
    ? t('p.dev_last', { when: new Date(d.last_success.replace(' ', 'T')).toLocaleString() })
    : t('p.dev_never');

  return `<div class="device">
    <span class="dot ${health[0]}"></span>
    <div>
      <div class="device-t">${d.label || d.platform}${d.installed ? '' : ' · ' + t('p.dev_browser')}</div>
      <div class="push-fine">${health[1]} · ${when}</div>
      ${d.last_error ? `<div class="push-fine err-text">${d.last_error}</div>` : ''}
    </div>
  </div>`;
}

async function doTestPush(btn) {
  const out = document.getElementById('testResult');
  btn.disabled = true;
  out.textContent = t('p.testing');
  const r = await TLPush.test();
  btn.disabled = false;
  out.textContent = r.success
    ? t('p.test_ok', { n: r.sent }) : (r.error || t('p.test_fail'));
}

async function doDisablePush(btn) {
  btn.disabled = true;
  await TLPush.disable();
  await renderAlertsPane();
  await renderPushBanner();
}

async function saveAlertPrefs(btn) {
  const msg = document.getElementById('apMsg');
  btn.disabled = true;
  msg.textContent = '';

  const radius = document.getElementById('apRadius').value.trim();
  const qs = document.getElementById('apQs').value;
  const qe = document.getElementById('apQe').value;

  const r = await api('/push/prefs', {
    method: 'POST',
    body: JSON.stringify({
      enabled:      document.getElementById('apEnabled').checked,
      radius_miles: radius === '' ? null : Number(radius),
      min_payout:   Number(document.getElementById('apMin').value || 0),
      quiet_start:  qs || '',
      quiet_end:    qe || '',
      timezone:     document.getElementById('apTz').value,
    }),
  });
  btn.disabled = false;
  msg.textContent = r.success ? t('p.saved') : (r.error || t('p.save_fail'));
}

/* ─── Wiring ────────────────────────────────────────────────────────────── */

// Register the worker on every load, not just when alerts are enabled: it is
// also what makes the app open offline, and having it already installed makes
// the enable tap a single fast step rather than a spinner.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', async () => {
    await TLPush.registerWorker();
    // Report where this machine is, but only for somebody already subscribed —
    // startLocation() checks that itself and does nothing otherwise. The
    // browser shows its own permission prompt, and refusing it costs nothing:
    // the yard still covers this company exactly as before.
    TLPush.startLocation().catch(() => {});
  });

  // The worker asks for this after a push service rotates a subscription.
  navigator.serviceWorker.addEventListener('message', (e) => {
    if (e.data && e.data.type === 'resubscribe' && Notification.permission === 'granted') {
      TLPush.enable().catch(() => {});
    }
  });
}

/* ─── Yard location ───────────────────────────────────────────────────────
   Used to be a button on the signup form. It came off there because every
   extra step on that form is a chance for a one-truck operator to give up
   halfway — but the point itself still decides which jobs this company is
   offered at all, so it has to live somewhere. Here, where it can be set once
   from the yard and forgotten. */
async function setYard(btn){
  const msg = document.getElementById('yardMsg');
  if(!navigator.geolocation){ msg.textContent = t('d.yard_nogps'); return; }

  btn.disabled = true;
  msg.textContent = t('d.yard_finding');

  navigator.geolocation.getCurrentPosition(async (pos) => {
    const r = await api('/push/prefs', {
      method: 'POST',
      body: JSON.stringify({ base_lat: pos.coords.latitude, base_lng: pos.coords.longitude }),
    });
    btn.disabled = false;
    msg.textContent = r.success ? t('d.yard_saved') : (r.error || t('p.save_fail'));
    if(r.success) renderAlertsPane();
  }, () => {
    btn.disabled = false;
    msg.textContent = t('d.yard_nogps');
  }, { enableHighAccuracy: true, timeout: 12000 });
}
