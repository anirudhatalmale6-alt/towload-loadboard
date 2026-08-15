<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  WHICH LANGUAGE SHOULD THIS VISITOR SEE FIRST
//
//  The rule: Spanish by default in the Miami area, plain English everywhere
//  else in the US, with a one-tap switch either way. Someone who has already
//  chosen is never overridden — that choice outranks every guess below.
//
//  Guessing the metro from an IP needs a lookup service, so this is built as a
//  chain that degrades honestly instead of pretending:
//
//    1. A geo header from the host or CDN, if one exists (free, instant)
//    2. A configured lookup provider, if a key has been set
//    3. The visitor's own browser language — a Spanish-preferring browser gets
//       Spanish wherever it is, which is arguably a better answer than
//       geography anyway
//    4. English
//
//  With no provider configured the product still behaves sensibly. Turning one
//  on later is two settings and no code.
// ═══════════════════════════════════════════════════════════════════════════

function spanishRegions(): array {
    $raw = (string)setting('spanish_regions', 'FL:MIAMI-DADE,FL:BROWARD,FL:MONROE');
    $out = [];
    foreach (explode(',', $raw) as $r) {
        $r = strtoupper(trim($r));
        if ($r !== '') $out[] = $r;
    }
    return $out;
}

/**
 * A geo signal from the request itself, if the host or a CDN provides one.
 * Costs nothing and is the only source that works on the first byte.
 */
function geoFromHeaders(): ?array {
    $state  = $_SERVER['HTTP_CF_REGION_CODE'] ?? $_SERVER['GEOIP_REGION'] ?? null;
    $city   = $_SERVER['HTTP_CF_IPCITY'] ?? $_SERVER['GEOIP_CITY'] ?? null;
    $county = $_SERVER['GEOIP_AREA_NAME'] ?? null;

    if (!$state && !$city) return null;
    return [
        'state'  => $state ? strtoupper(substr((string)$state, 0, 2)) : null,
        'city'   => $city ? strtoupper((string)$city) : null,
        'county' => $county ? strtoupper((string)$county) : null,
        'source' => 'headers',
    ];
}

/**
 * A paid or keyed lookup, only if one has been configured. Deliberately never
 * called without a key — a free public endpoint used commercially is a
 * dependency that breaks on someone else's rate limit, at their schedule.
 */
function geoFromProvider(string $ip): ?array {
    $provider = (string)setting('geoip_provider', '');
    $key      = (string)setting('geoip_key', '');
    if ($provider === '' || $key === '') return null;

    // Cached per IP for a day. A visitor's metro does not change, and the
    // lookup is billed per call.
    $cacheKey = 'geoip:' . md5($ip);
    $row = getDB()->prepare("SELECT setting_value FROM platform_settings WHERE setting_key = :k");
    $row->execute([':k' => $cacheKey]);
    if ($hit = $row->fetchColumn()) {
        $c = json_decode((string)$hit, true);
        if (is_array($c) && ($c['at'] ?? 0) > time() - 86400) return $c['geo'];
    }

    $url = str_replace(['{ip}', '{key}'], [urlencode($ip), urlencode($key)], $provider);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return null;

    $d = json_decode((string)$body, true);
    if (!is_array($d)) return null;

    $geo = [
        'state'  => strtoupper((string)($d['region_code'] ?? $d['region'] ?? $d['state_prov'] ?? '')),
        'city'   => strtoupper((string)($d['city'] ?? '')),
        'county' => strtoupper((string)($d['county'] ?? $d['district'] ?? '')),
        'source' => 'provider',
    ];

    getDB()->prepare(
        "INSERT INTO platform_settings (setting_key, setting_value, description)
              VALUES (:k, :v, 'Cached IP geolocation, expires after a day')
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([':k' => $cacheKey, ':v' => json_encode(['at' => time(), 'geo' => $geo])]);

    return $geo;
}

function clientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h])) return (string)$_SERVER[$h];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // First entry is the client; the rest are proxies, and anything after
        // the first is not ours to trust.
        return trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function geoIsSpanishArea(?array $geo): bool {
    if (!$geo) return false;
    $state = $geo['state'] ?? '';
    if ($state === '') return false;

    foreach (spanishRegions() as $rule) {
        [$rState, $rArea] = array_pad(explode(':', $rule, 2), 2, '');
        if ($rState !== $state) continue;
        if ($rArea === '') return true;                      // whole state
        if ($rArea === ($geo['county'] ?? '')) return true;
        if ($rArea === ($geo['city'] ?? '')) return true;
        // "MIAMI-DADE" should also match a city reported simply as "MIAMI".
        if (str_contains($rArea, (string)($geo['city'] ?? '~')) && !empty($geo['city'])) return true;
    }
    return false;
}

/**
 * The suggestion handed to the browser. Never a decision — the page still
 * prefers whatever the visitor chose last time.
 */
function suggestLanguage(?string $browserLangs = null): array {
    $fallback = (string)setting('default_language', 'en') === 'es' ? 'es' : 'en';

    $geo = geoFromHeaders() ?: geoFromProvider(clientIp());
    if (geoIsSpanishArea($geo)) {
        return ['lang' => 'es', 'source' => $geo['source'], 'area' => $geo['city'] ?? $geo['state']];
    }

    // A geo answer that is confidently NOT a Spanish-default area settles it.
    if ($geo && !empty($geo['state'])) {
        return ['lang' => $fallback, 'source' => $geo['source'], 'area' => $geo['city'] ?? $geo['state']];
    }

    // No geo signal. Ask the browser what the person actually reads — for a
    // Spanish speaker in Orlando this is a better answer than geography.
    $langs = strtolower((string)($browserLangs ?? $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    if ($langs !== '' && preg_match('/\bes\b|\bes-/', $langs)) {
        return ['lang' => 'es', 'source' => 'browser', 'area' => null];
    }

    return ['lang' => $fallback, 'source' => 'default', 'area' => null];
}
