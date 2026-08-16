<?php
require_once __DIR__ . '/helpers.php';

// ═══════════════════════════════════════════════════════════════════════════
//  TURNING A TYPED ADDRESS INTO A POSITION, SERVER-SIDE
//
//  The booking form only ever had coordinates when the customer PICKED a
//  Google suggestion, or used the GPS button. Type a full, correct address and
//  carry on without tapping the dropdown and there is no position — and worse,
//  the previous one was left sitting in memory, so the job was priced, matched
//  and checked for coverage at wherever they had been before.
//
//  That is the shape of failure this whole file exists to remove: a form that
//  succeeds while doing the wrong thing. Somebody types an address in Port St
//  Lucie, the system quotes them against a point in Miami, and the only symptom
//  is a wrong answer that looks completely ordinary.
//
//  Results are cached. The same handful of addresses get typed over and over
//  during testing and by customers correcting themselves, and Google bills per
//  lookup.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Geocode a free-text address. Returns
 * ['lat','lng','address','city','state','zip'] or null.
 */
function geocodeAddress(string $query): ?array {
    $query = trim(preg_replace('/\s+/', ' ', $query));
    if (mb_strlen($query) < 5) return null;

    $key = trim((string)setting('google_maps_key', ''));
    if ($key === '') return null;

    $hash = hash('sha256', mb_strtolower($query));
    if ($hit = geocodeCacheGet($hash)) return $hit;

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?'
         . http_build_query([
             'address'    => $query,
             'components' => 'country:US',
             'key'        => $key,
         ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body === false) return null;

    $data = json_decode($body, true);
    if (($data['status'] ?? '') !== 'OK' || empty($data['results'][0])) {
        // ZERO_RESULTS is an ordinary answer — the address is not real. Anything
        // else is worth a line in the log, because a billing or key problem here
        // is invisible otherwise: bookings just quietly stop having positions.
        if (($data['status'] ?? '') !== 'ZERO_RESULTS') {
            error_log('[geocode] ' . ($data['status'] ?? 'no status') . ' for ' . $query);
        }
        return null;
    }

    $r = $data['results'][0];
    $loc = $r['geometry']['location'] ?? null;
    if (!$loc) return null;

    $parts = ['city' => null, 'state' => null, 'zip' => null];
    foreach ($r['address_components'] ?? [] as $c) {
        $types = $c['types'] ?? [];
        if (in_array('locality', $types, true))                    $parts['city']  = $c['long_name'];
        elseif (!$parts['city'] && in_array('sublocality', $types, true)) $parts['city'] = $c['long_name'];
        if (in_array('administrative_area_level_1', $types, true)) $parts['state'] = $c['short_name'];
        if (in_array('postal_code', $types, true))                 $parts['zip']   = $c['long_name'];
    }

    $out = [
        'lat'     => (float)$loc['lat'],
        'lng'     => (float)$loc['lng'],
        'address' => $r['formatted_address'] ?? $query,
        'city'    => $parts['city'],
        'state'   => $parts['state'],
        'zip'     => $parts['zip'],
    ];
    geocodeCachePut($hash, $query, $out);
    return $out;
}

function geocodeCacheGet(string $hash): ?array {
    try {
        $stmt = getDB()->prepare(
            "SELECT lat, lng, formatted, city, state, zip FROM geocode_cache
              WHERE query_hash = :h AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $stmt->execute([':h' => $hash]);
        $r = $stmt->fetch();
        if (!$r) return null;
        return [
            'lat' => (float)$r['lat'], 'lng' => (float)$r['lng'],
            'address' => $r['formatted'], 'city' => $r['city'],
            'state' => $r['state'], 'zip' => $r['zip'],
        ];
    } catch (Throwable $e) {
        return null;   // no cache table yet is not a reason to stop geocoding
    }
}

function geocodeCachePut(string $hash, string $query, array $out): void {
    try {
        getDB()->prepare(
            "INSERT INTO geocode_cache (query_hash, query_text, lat, lng, formatted, city, state, zip)
             VALUES (:h, :q, :la, :ln, :f, :c, :s, :z)
             ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng),
                                     formatted = VALUES(formatted), city = VALUES(city),
                                     state = VALUES(state), zip = VALUES(zip),
                                     created_at = NOW()"
        )->execute([
            ':h' => $hash, ':q' => mb_substr($query, 0, 255),
            ':la' => $out['lat'], ':ln' => $out['lng'],
            ':f' => mb_substr((string)$out['address'], 0, 255),
            ':c' => $out['city'], ':s' => $out['state'], ':z' => $out['zip'],
        ]);
    } catch (Throwable $e) { /* caching is a nicety, never a failure */ }
}

/**
 * The requester's IP, honestly.
 *
 * X-Forwarded-For is attacker-supplied unless a proxy we trust wrote it, so the
 * FIRST entry is taken (the original client) and the whole header is kept
 * alongside for the record. This is evidence for a damage dispute; it should
 * read like evidence, not like a single number somebody could have chosen.
 */
function requestIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd) {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) $ip = $first;
    }
    return mb_substr($ip, 0, 45);
}
