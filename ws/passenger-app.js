/* Passenger entry point.
 *
 * Passenger owns the port and the process lifecycle, so this cannot simply be
 * server.js — that binds 127.0.0.1:3003 itself. This is a thin, stateless
 * WebSocket-aware proxy in front of the real server, which stays under pm2 as a
 * single process.
 *
 * Why not let Passenger run the socket server directly: Passenger may spawn
 * several copies of an app, and socket.io keeps its room membership in memory.
 * Two copies means a publish reaching half the browsers, intermittently, with
 * nothing in any log to explain it. One stateful process behind N stateless
 * proxies has no such failure mode.
 */
const http = require('http');
const net = require('net');
const { URL } = require('url');

const TARGET_PORT = parseInt(process.env.TL_WS_PORT || '3003', 10);
const TARGET_HOST = '127.0.0.1';

const server = http.createServer((req, res) => {
  const proxy = http.request(
    { host: TARGET_HOST, port: TARGET_PORT, path: req.url, method: req.method, headers: req.headers },
    (up) => { res.writeHead(up.statusCode, up.headers); up.pipe(res); }
  );
  proxy.on('error', () => { res.writeHead(502); res.end('{"ok":false}'); });
  req.pipe(proxy);
});

// The upgrade handshake is the whole point; without this the socket never opens.
server.on('upgrade', (req, socket, head) => {
  const up = net.connect(TARGET_PORT, TARGET_HOST, () => {
    up.write(
      `${req.method} ${req.url} HTTP/1.1\r\n` +
      Object.entries(req.headers).map(([k, v]) => `${k}: ${v}`).join('\r\n') +
      '\r\n\r\n'
    );
    if (head && head.length) up.write(head);
    up.pipe(socket);
    socket.pipe(up);
  });
  up.on('error', () => socket.destroy());
  socket.on('error', () => up.destroy());
});

server.listen(process.env.PORT || 3010, () => {
  console.log('TowLoad realtime proxy up');
});
