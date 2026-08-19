<?php
// ═══════════════════════════════════════════════════════════════════════════
//  TIME ZONES
//
//  Three different clocks meet in this app and they are rarely the same one:
//
//    1. the box          — DreamHost hands out America/Los_Angeles
//    2. the customer     — stranded on a shoulder, anywhere in the US
//    3. the tow operator — dispatching from a yard in a third state
//
//  A MySQL DATETIME carries no zone. "2026-08-19 14:33:35" written by this
//  server means 14:33 Pacific, but a browser handed that string parses it as
//  14:33 *local*, so a customer in Miami saw every timestamp three hours
//  behind their own watch. Nothing was wrong with the data — the string simply
//  never said which clock it came from.
//
//  Two rules follow, and both are enforced here rather than at each call site:
//
//    STORAGE  stays in one zone (SERVER_TZ) and PHP is pinned to it, because
//             every strtotime($row['x']) < time() comparison in this codebase
//             is only correct while PHP and MySQL agree. Changing one alone
//             would silently move every deadline by seven hours.
//
//    DISPLAY  never leaves the API as a bare DATETIME. jsonResponse() rewrites
//             every one into ISO-8601 with a real offset, so the browser and
//             the phone render it in whatever zone the person reading it is
//             standing in. One choke point, so a field added later cannot be
//             forgotten.
//
//  Money is a third case. After-hours and weekend multipliers ask "what time
//  is it" about the *pickup*, not about the server — see localClock().
// ═══════════════════════════════════════════════════════════════════════════

/** The zone the database is written in. Named, not an offset, so DST is real. */
const SERVER_TZ = 'America/Los_Angeles';

// Pinned explicitly. The host's php.ini agrees with this today; if it ever
// changes under us, every stored timestamp would shift without a line of code
// changing. This makes that impossible.
date_default_timezone_set(SERVER_TZ);

function serverTz(): DateTimeZone {
    static $tz = null;
    return $tz ??= new DateTimeZone(SERVER_TZ);
}

/** Exactly the shape MySQL hands back for DATETIME/TIMESTAMP, and nothing else. */
function isMysqlDatetime($v): bool {
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) === 1;
}

/**
 * '2026-08-19 14:33:35'  ->  '2026-08-19T14:33:35-07:00'
 *
 * The offset is resolved per timestamp, not fixed, so a job from January reads
 * -08:00 and one from July reads -07:00. A single hardcoded offset would put
 * half the year's history off by an hour.
 */
function isoTs(?string $ts): ?string {
    if ($ts === null || $ts === '' || $ts === '0000-00-00 00:00:00') return null;
    try {
        return (new DateTimeImmutable($ts, serverTz()))->format('c');
    } catch (Throwable $e) {
        return $ts;
    }
}

/**
 * Walk a response and stamp every DATETIME with its offset.
 *
 * Deliberately blind to field names. Doing this by a list of known keys is how
 * the next timestamp added to a response goes out bare and shows three hours
 * wrong on one screen while every other screen is right.
 */
function isoDeep($value) {
    if (is_string($value)) return isMysqlDatetime($value) ? isoTs($value) : $value;
    if (is_array($value)) {
        foreach ($value as $k => $v) $value[$k] = isoDeep($v);
    }
    return $value;
}

// ─── Where the customer actually is ─────────────────────────────────────────
//
// Used for pricing, never for storage. States that sit in one zone are a
// lookup; the dozen that are split are settled by longitude (or latitude for
// Idaho, which splits north/south). Approximate to a few miles at the seam,
// which is far tighter than the hour-wide window it feeds.

const STATE_TZ = [
    'CT'=>'America/New_York','DC'=>'America/New_York','DE'=>'America/New_York',
    'GA'=>'America/New_York','MA'=>'America/New_York','MD'=>'America/New_York',
    'ME'=>'America/New_York','NC'=>'America/New_York','NH'=>'America/New_York',
    'NJ'=>'America/New_York','NY'=>'America/New_York','OH'=>'America/New_York',
    'PA'=>'America/New_York','RI'=>'America/New_York','SC'=>'America/New_York',
    'VA'=>'America/New_York','VT'=>'America/New_York','WV'=>'America/New_York',

    'AL'=>'America/Chicago','AR'=>'America/Chicago','IA'=>'America/Chicago',
    'IL'=>'America/Chicago','LA'=>'America/Chicago','MN'=>'America/Chicago',
    'MO'=>'America/Chicago','MS'=>'America/Chicago','OK'=>'America/Chicago',
    'WI'=>'America/Chicago',

    'CO'=>'America/Denver','MT'=>'America/Denver','NM'=>'America/Denver',
    'UT'=>'America/Denver','WY'=>'America/Denver',

    'AZ'=>'America/Phoenix',            // no DST at all

    'CA'=>'America/Los_Angeles','NV'=>'America/Los_Angeles',
    'WA'=>'America/Los_Angeles',

    'HI'=>'Pacific/Honolulu',
];

/** IANA zone for a US pickup point. */
function tzForPoint(?float $lat, ?float $lng, ?string $state): string {
    $st = $state !== null ? strtoupper(trim($state)) : '';

    // Split states first — the seam matters more than the state name.
    if ($lng !== null) {
        switch ($st) {
            case 'FL': return $lng < -85.0  ? 'America/Chicago'  : 'America/New_York'; // panhandle
            case 'MI': return $lng < -90.0  ? 'America/Chicago'  : 'America/New_York'; // western UP
            case 'IN': return $lng < -87.2  ? 'America/Chicago'  : 'America/New_York'; // Gary, Evansville
            case 'KY': return $lng < -86.0  ? 'America/Chicago'  : 'America/New_York';
            case 'TN': return $lng < -85.3  ? 'America/Chicago'  : 'America/New_York';
            case 'TX': return $lng < -105.0 ? 'America/Denver'   : 'America/Chicago';  // El Paso
            case 'KS': return $lng < -101.5 ? 'America/Denver'   : 'America/Chicago';
            case 'NE': return $lng < -101.5 ? 'America/Denver'   : 'America/Chicago';
            case 'ND': return $lng < -101.0 ? 'America/Denver'   : 'America/Chicago';
            case 'SD': return $lng < -100.0 ? 'America/Denver'   : 'America/Chicago';
            case 'OR': return ($lng > -117.2 && $lat !== null && $lat < 45.0)
                              ? 'America/Denver' : 'America/Los_Angeles';              // Malheur
            case 'ID': return ($lat !== null && $lat > 45.5)
                              ? 'America/Los_Angeles' : 'America/Denver';              // panhandle
            case 'AK': return $lng < -169.5 ? 'America/Adak'     : 'America/Anchorage';
        }
    }
    if ($st === 'AK') return 'America/Anchorage';
    if (isset(STATE_TZ[$st])) return STATE_TZ[$st];

    // No state, but we know where they are. Rough bands are still far better
    // than answering with the server's own zone.
    if ($lng !== null) {
        if ($lng > -85.0)  return 'America/New_York';
        if ($lng > -101.0) return 'America/Chicago';
        if ($lng > -115.0) return 'America/Denver';
        return 'America/Los_Angeles';
    }
    return SERVER_TZ;
}

/**
 * The wall clock at the pickup: hour 0-23 and ISO day-of-week 1-7.
 *
 * A tow at 10pm in Miami is a night call whatever the server thinks, and a
 * Friday-night call is a weekend call. Reading date('G') on the box made both
 * of those wrong by three hours for every Florida job.
 */
function localClock(?float $lat, ?float $lng, ?string $state, ?string $at = null): array {
    $zone = tzForPoint($lat, $lng, $state);
    try {
        $when = new DateTimeImmutable($at ?: 'now', serverTz());
        $when = $when->setTimezone(new DateTimeZone($zone));
    } catch (Throwable $e) {
        $when = new DateTimeImmutable('now', serverTz());
        $zone = SERVER_TZ;
    }
    return [
        'tz'   => $zone,
        'hour' => (int)$when->format('G'),
        'dow'  => (int)$when->format('N'),
    ];
}
