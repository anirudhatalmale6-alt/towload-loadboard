/* ══════════════════════════════════════════════════════════════════════════
   REALTIME — the browser half.

   Connects to the realtime server and turns an event into a refetch. It never
   renders anything from a socket payload: the message says "job 412 changed"
   and the page calls the same authenticated API it already uses. So a socket
   that drops, lies, or never connects at all costs one polling interval and
   nothing else.

   The polling timers stay exactly as they are. This makes them feel instant;
   it does not replace them. A realtime layer that the product DEPENDS on is a
   single point of failure sitting between a stranded customer and a truck.
   ══════════════════════════════════════════════════════════════════════════ */

const TLRealtime = (() => {
  let socket = null;
  let loaded = false;
  let handlers = {};
  let config = null;

  /** Pull the socket.io client only when there is a server to talk to. */
  function loadClient(url) {
    if (loaded) return Promise.resolve(true);
    return new Promise((resolve) => {
      const s = document.createElement('script');
      s.src = url.replace(/\/$/, '') + '/socket.io/socket.io.js';
      s.async = true;
      s.onload = () => { loaded = true; resolve(true); };
      s.onerror = () => resolve(false);
      document.head.appendChild(s);
    });
  }

  /**
   * @param auth  {token}  for an operator, or {ticket} for a customer's job
   * @param on    map of event name -> handler
   * @param cfg   an already-fetched config, when the caller had to ask for one
   *              anyway. The customer's page does: its config request carries a
   *              tracking token and mints the ticket in the same round trip, so
   *              fetching it again here would be a second call — and an
   *              unauthenticated one, which for a customer is simply a 401.
   */
  async function connect(auth, on, cfg) {
    handlers = on || {};

    if (cfg && cfg.enabled && cfg.url) {
      config = cfg;
    } else {
      try {
        const r = await fetch('api/realtime/config',
                              { headers: authHeader() }).then(x => x.json());
        if (!r || !r.success || !r.enabled || !r.url) return false;
        config = r;
      } catch (e) {
        return false;   // no config, no realtime — polling carries on regardless
      }
    }

    if (!(await loadClient(config.url))) return false;
    if (typeof io === 'undefined') return false;

    socket = io(config.url, {
      transports: ['websocket'],
      auth,
      reconnectionDelay: 1000,
      reconnectionDelayMax: 15000,
      timeout: 8000,
    });

    socket.on('connect', () => {
      // Slow the pollers down while the socket is healthy, rather than turning
      // them off. If the connection dies quietly — a proxy idle timeout, a
      // phone changing networks — a slow poll still gets the job to the driver.
      if (typeof onRealtimeUp === 'function') onRealtimeUp();
    });

    socket.on('disconnect', () => {
      if (typeof onRealtimeDown === 'function') onRealtimeDown();
    });

    // connect_error fires for a rejected handshake too, which is the normal
    // outcome of an expired ticket. Not worth shouting about.
    socket.on('connect_error', () => {
      if (typeof onRealtimeDown === 'function') onRealtimeDown();
    });

    Object.keys(handlers).forEach((evt) => socket.on(evt, handlers[evt]));
    return true;
  }

  function authHeader() {
    return (typeof TOKEN !== 'undefined' && TOKEN)
      ? { 'Authorization': 'Bearer ' + TOKEN } : {};
  }

  function connected() { return !!(socket && socket.connected); }

  function disconnect() {
    if (socket) { socket.close(); socket = null; }
  }

  return { connect, disconnect, connected };
})();
