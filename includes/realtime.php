<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  REALTIME — the PHP side of the push
//
//  Every function here is a NUDGE. The payload says what changed and which id
//  it concerns; it never carries the data itself. The browser hears "job 412
//  changed" and refetches through the normal authenticated API, which is the
//  only place that decides what that particular viewer is allowed to see.
//
//  That is deliberate and it is the whole security model:
//    - the realtime server never touches the database or sees customer PII
//    - a dropped event costs one polling interval, not a wrong screen
//    - a forged event can make a browser refetch, and nothing else
//
//  If the realtime server is down, missing, or misconfigured, every call here
//  fails silently and the product behaves exactly as it does today.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * The secret shared with the realtime server, generated once on first use.
 * Same reasoning as the VAPID keypair: no secret in the repo, none to copy
 * between environments by hand, and none that a deploy can overwrite.
 */
function realtimeSecret(): string {
    $s = (string)setting('realtime_internal_secret', '');
    if ($s !== '') return $s;

    $s = bin2hex(random_bytes(32));
    getDB()->prepare(
        "INSERT INTO platform_settings (setting_key, setting_value)
              VALUES ('realtime_internal_secret', :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([':v' => $s]);
    return $s;
}

function realtimeEnabled(): bool {
    return (string)setting('realtime_enabled', '1') === '1'
        && (string)setting('realtime_internal_url', '') !== ''
        && realtimeSecret() !== '';
}

/**
 * Fire an event at one or more rooms.
 *
 * Never throws and never blocks for long. This runs inside requests that are
 * creating paid jobs; a realtime server that has fallen over must not be able
 * to slow a stranded customer down, let alone fail their booking.
 */
function realtimePublish($room, string $event, array $data = []): bool {
    if (!realtimeEnabled()) return false;

    $url = rtrim((string)setting('realtime_internal_url', ''), '/') . '/publish';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['room' => $room, 'event' => $event, 'data' => $data]),
        CURLOPT_RETURNTRANSFER => true,
        // Localhost. If this cannot answer in a second it is not answering.
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Internal-Secret: ' . realtimeSecret(),
        ],
    ]);
    $ok = curl_exec($ch) !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    return $ok;
}

/**
 * A customer's ticket to listen on their own job.
 *
 * There is no login on that side — the tracking token in their link is the
 * credential, and this converts it into something short-lived. A tracking link
 * sits in a text message for months and gets forwarded; a ticket that outlived
 * the job would let anyone who ever saw the link keep a socket open on it.
 */
function realtimeTicket(string $trackingToken, int $ttlSeconds = 3600): ?string {
    if (!preg_match('/^[a-f0-9]{32}$/', $trackingToken)) return null;
    $secret = realtimeSecret();
    if ($secret === '') return null;

    $exp = time() + max(60, $ttlSeconds);
    $sig = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $trackingToken . '.' . $exp, $secret, true)
    ), '+/', '-_'), '=');

    return $trackingToken . '.' . $exp . '.' . $sig;
}

// ─── The events, one function each ──────────────────────────────────────────
// Named for what happened rather than for what a screen should do with it, so
// a new screen can listen without the publisher knowing it exists.

/** A new job is on the board. Sent to every connected operator. */
function rtJobPosted(int $callId, ?string $city = null, ?string $state = null): void {
    realtimePublish('board', 'job.posted', [
        'call_id' => $callId,
        // Enough to decide whether to bother refetching, and nothing a
        // competitor could not see on the board anyway.
        'area'    => trim(($city ?? '') . ', ' . ($state ?? ''), ', '),
    ]);
}

/** A job left the board — taken, cancelled or expired. */
function rtJobClosed(int $callId, string $reason): void {
    realtimePublish('board', 'job.closed', ['call_id' => $callId, 'reason' => $reason]);
}

/** Something changed on one job: status, award, completion. */
function rtJobChanged(int $callId, ?string $trackingToken, ?int $towerAccountId, string $what): void {
    $rooms = [];
    if ($trackingToken)  $rooms[] = 'job:' . $trackingToken;
    if ($towerAccountId) $rooms[] = 'account:' . $towerAccountId;
    if (!$rooms) return;

    realtimePublish($rooms, 'job.changed', ['call_id' => $callId, 'what' => $what]);
}

/**
 * The truck moved. Only ever to the customer watching that one job, and
 * carrying only the position their own tracking feed would have given them a
 * few seconds later anyway.
 */
function rtTruckMoved(string $trackingToken, float $lat, float $lng, ?int $etaMinutes): void {
    realtimePublish('job:' . $trackingToken, 'truck.moved', [
        'lat' => $lat, 'lng' => $lng, 'eta_minutes' => $etaMinutes,
    ]);
}
