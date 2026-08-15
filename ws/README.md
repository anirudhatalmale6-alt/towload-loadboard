# TowLoad realtime server

Pushes "something changed" to connected browsers so screens update instantly
instead of on a timer. It is **not** required: every screen still polls
underneath, and this only stretches those intervals out while a socket is
healthy. With `realtime_public_url` empty, the whole feature is off and the
product behaves exactly as it did before.

## What it holds

Nothing. No database connection, no customer data, no job details. Every event
is a nudge — `{"call_id": 412, "what": "en_route"}` — and the browser refetches
through the normal authenticated API, which is the only place that decides what
a given viewer may see. An attacker with complete control of this process can
make a browser refresh, and that is all.

## Deploying it

It listens on `127.0.0.1` only. Something has to terminate TLS and forward to
it. On a DreamHost VPS the supported way, and the one that needs no Apache
config edit, is **Passenger**:

1. In the DreamHost panel, add the websocket domain (e.g. `ws.example.com`),
   point it at this VPS, tick **Passenger (Ruby/NodeJS/Python apps only)** and
   turn on the free Let's Encrypt certificate.
2. Passenger serves from `~/<domain>/public` and runs `~/<domain>/app.js`.
   Copy `passenger-app.js` there as `app.js`, and put this directory at
   `~/towload-ws`.
3. Create `~/towload-ws/.env` (chmod 600):

       TL_WS_PORT=3003
       TL_JWT_SECRET=<same value as config/env.php>
       TL_INTERNAL_SECRET=<value of the realtime_internal_secret setting>
       TL_WS_ORIGINS=https://bot24.io

4. Start it and keep it alive, exactly like the two servers already on this box:

       cd ~/towload-ws && npm install --omit=dev
       set -a && . ./.env && set +a && pm2 start server.js --name towload-ws --time
       pm2 save
       crontab -e   # */5 * * * * /home/USER/towload-ws/ensure-running.sh > /dev/null 2>&1

5. In the admin panel set `realtime_public_url` to `https://ws.example.com`.
   That single value is the on switch.

## Why a separate domain is the right call

- It is the only route that needs no edit to the DreamHost-managed Apache
  config, because Passenger is configured from the panel.
- The session cookie is scoped to the app's own domain, so it is never sent to
  the socket host. Authentication here is an explicit token in the handshake,
  which is what it should have been anyway.
- A restart, a bad deploy or a flood on this service cannot touch the main site
  or the other products sharing the box.

## Checking it

    curl -s http://127.0.0.1:3003/health
    {"ok":true,"clients":3,"uptime_s":81234}
