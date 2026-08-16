/* ══════════════════════════════════════════════════════════════════════════
   TowLoad realtime server
   ══════════════════════════════════════════════════════════════════════════

   One job: push a change to the browsers that care about it, the instant it
   happens, instead of every screen asking "anything new?" on a timer.

   ── What it is NOT ──────────────────────────────────────────────────────
   It is not the source of truth and it is not required. Every screen still
   polls on a slow timer underneath, and every payload here is a NUDGE, not
   data to be trusted: the browser is told "job 412 changed", and it refetches
   through the normal authenticated API. That means a socket that is down, an
   event that is dropped, or a payload that is forged degrades to exactly the
   product we have today rather than to a wrong screen.

   It also means this server never needs a database connection, never sees
   customer PII, and cannot leak anything by being compromised — the worst an
   attacker with full control of it can do is tell a browser to refresh.

   ── Who is allowed in ───────────────────────────────────────────────────
     Operators  a JWT signed by the PHP app with the shared HS256 secret.
                Verified here; the account id in it decides which room.
     Customers  a short-lived TICKET minted by PHP for one tracking token.
                No login exists on that side, so a ticket is the credential.
     PHP        an internal secret on a localhost-only POST /publish.

   Runs on 127.0.0.1 only. Nothing reaches it from outside except through the
   front door the operator chooses — see ws/README.md.
   ══════════════════════════════════════════════════════════════════════════ */

const http = require('http');
const crypto = require('crypto');
const { Server } = require('socket.io');

const PORT            = parseInt(process.env.TL_WS_PORT || '3003', 10);
const JWT_SECRET      = process.env.TL_JWT_SECRET      || '';
const INTERNAL_SECRET = process.env.TL_INTERNAL_SECRET || '';
const ORIGINS         = (process.env.TL_WS_ORIGINS || 'https://bot24.io').split(',').map(s => s.trim());

if (!JWT_SECRET || !INTERNAL_SECRET) {
  console.error('TL_JWT_SECRET and TL_INTERNAL_SECRET must be set. Refusing to start.');
  process.exit(1);
}

// ─── Crypto helpers, matching includes/helpers.php exactly ──────────────────
function b64urlDecode(str) {
  return Buffer.from(str.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

function hmacB64url(data, secret) {
  return crypto.createHmac('sha256', secret).update(data).digest('base64')
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** The same HS256 JWT the PHP side issues. Returns null on anything suspect. */
function verifyJWT(token) {
  try {
    const parts = String(token).split('.');
    if (parts.length !== 3) return null;
    const [header, payload, sig] = parts;
    // timingSafeEqual needs equal lengths, so compare through a fixed-size digest.
    const expected = hmacB64url(`${header}.${payload}`, JWT_SECRET);
    const a = crypto.createHash('sha256').update(sig).digest();
    const b = crypto.createHash('sha256').update(expected).digest();
    if (!crypto.timingSafeEqual(a, b)) return null;

    const data = JSON.parse(b64urlDecode(payload).toString());
    if (data.exp && data.exp < Math.floor(Date.now() / 1000)) return null;
    return data;
  } catch (e) {
    return null;
  }
}

/**
 * A customer's ticket: "<trackingToken>.<exp>.<sig>", minted by PHP.
 *
 * Short-lived on purpose. A tracking link lives in a text message for months
 * and may be forwarded; a ticket that outlived the job would let anyone who
 * ever held the link keep a live socket open on it.
 */
function verifyTicket(ticket) {
  try {
    const [token, exp, sig] = String(ticket).split('.');
    if (!token || !exp || !sig) return null;
    if (!/^[a-f0-9]{32}$/.test(token)) return null;
    if (parseInt(exp, 10) < Math.floor(Date.now() / 1000)) return null;

    const expected = hmacB64url(`${token}.${exp}`, INTERNAL_SECRET);
    const a = crypto.createHash('sha256').update(sig).digest();
    const b = crypto.createHash('sha256').update(expected).digest();
    if (!crypto.timingSafeEqual(a, b)) return null;
    return token;
  } catch (e) {
    return null;
  }
}

// ─── HTTP surface: a health check and the publish hook ──────────────────────
const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify({
      ok: true,
      clients: io ? io.engine.clientsCount : 0,
      uptime_s: Math.round(process.uptime()),
    }));
  }

  if (req.method === 'POST' && req.url === '/publish') {
    let body = '';
    // A publish body is a handful of ids. Anything larger is not ours.
    req.on('data', (c) => {
      body += c;
      if (body.length > 16384) { req.destroy(); }
    });
    req.on('end', () => {
      let msg;
      try { msg = JSON.parse(body); } catch (e) { msg = null; }

      const given = String(req.headers['x-internal-secret'] || '');
      const ok = given.length === INTERNAL_SECRET.length &&
                 crypto.timingSafeEqual(Buffer.from(given), Buffer.from(INTERNAL_SECRET));
      if (!ok) {
        res.writeHead(403, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ ok: false }));
      }
      if (!msg || !msg.room || !msg.event) {
        res.writeHead(422, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ ok: false, error: 'room and event required' }));
      }

      const rooms = Array.isArray(msg.room) ? msg.room : [msg.room];
      rooms.forEach((r) => io.to(String(r)).emit(msg.event, msg.data || {}));

      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true, rooms: rooms.length }));
    });
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ ok: false }));
});

const io = new Server(server, {
  cors: { origin: ORIGINS, credentials: false },
  // Both transports, deliberately.
  //
  // This was WebSocket-only while the plan was to run behind Passenger, which
  // may spawn several copies of an app: long-polling needs every request from
  // one browser to reach the same process, and there was no way to guarantee
  // that. Passenger turned out to be unnecessary — a proxy rule in .htaccess
  // reaches this server directly — so there is exactly one process, held open
  // by pm2 and guarded by the port itself. The constraint is gone.
  //
  // Polling earns its place as the fallback. socket.io opens on it and
  // upgrades a moment later, and on the networks that block WebSockets
  // outright — hotel wifi, some corporate proxies, the odd mobile carrier — it
  // is the only thing that connects at all. A tow operator sitting in a truck
  // stop is precisely the person this is for.
  transports: ['websocket', 'polling'],
  pingInterval: 25000,
  pingTimeout: 20000,
});

// ─── Who gets to join what ──────────────────────────────────────────────────
io.use((socket, next) => {
  const { token, ticket } = socket.handshake.auth || {};

  if (token) {
    const claims = verifyJWT(token);
    if (!claims || !claims.account_id) return next(new Error('unauthorised'));
    socket.data.kind = 'account';
    socket.data.accountId = parseInt(claims.account_id, 10);
    return next();
  }

  if (ticket) {
    const trackingToken = verifyTicket(ticket);
    if (!trackingToken) return next(new Error('unauthorised'));
    socket.data.kind = 'job';
    socket.data.job = trackingToken;
    return next();
  }

  return next(new Error('unauthorised'));
});

io.on('connection', (socket) => {
  if (socket.data.kind === 'account') {
    // An operator hears about their own account, and about new jobs appearing.
    // Which jobs they are ELIGIBLE for is decided by PHP when it publishes —
    // this server has no idea about radius, capability or quiet hours and
    // should not pretend to.
    socket.join(`account:${socket.data.accountId}`);
    socket.join('board');
  } else {
    socket.join(`job:${socket.data.job}`);
  }

  socket.on('error', () => {});
});

server.listen(PORT, '127.0.0.1', () => {
  console.log(`TowLoad realtime server on 127.0.0.1:${PORT}`);
});

// pm2 restarts on a crash, but a clean exit on a signal keeps the logs honest
// about the difference between "restarted" and "fell over".
['SIGTERM', 'SIGINT'].forEach((sig) => {
  process.on(sig, () => {
    console.log(`${sig} — closing`);
    io.close(() => server.close(() => process.exit(0)));
    setTimeout(() => process.exit(0), 3000).unref();
  });
});
