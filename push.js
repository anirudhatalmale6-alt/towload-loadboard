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

  return { state, enable, disable, test, registerWorker, isIOS, isStandalone, platform };
})();

/* ─── UI ────────────────────────────────────────────────────────────────── */

// Shown at the top of the board until alerts are on. Deliberately hard to
// ignore: an operator who never turns this on gets no jobs, blames the
// platform, and leaves — and neither of us ever finds out why.
async function renderPushBanner() {
  const box = document.getElementById('pushBanner');
  if (!box || !ME || ME.account.account_type !== 'tower') return;

  const s = await TLPush.state();
  if (s === 'on') { box.innerHTML = ''; return; }

  if (s === 'ios_needs_install') {
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

  const statusRow = {
    on:                `<span class="dot ok"></span> ${t('p.st_on')}`,
    ready:             `<span class="dot warn"></span> ${t('p.st_ready')}`,
    ios_needs_install: `<span class="dot warn"></span> ${t('p.st_ios')}`,
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
  window.addEventListener('load', () => TLPush.registerWorker());

  // The worker asks for this after a push service rotates a subscription.
  navigator.serviceWorker.addEventListener('message', (e) => {
    if (e.data && e.data.type === 'resubscribe' && Notification.permission === 'granted') {
      TLPush.enable().catch(() => {});
    }
  });
}
