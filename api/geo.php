<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/geocode.php';
require_once __DIR__ . '/../includes/geo.php';

setCorsHeaders();

// GET /api/geo/lang — which language this visitor should see first.
// Public: it is asked before anyone has an account, and the answer is not
// sensitive. Cached hard by the page so it costs one request per browser.
if (($_GET['action'] ?? '') === 'lang') {
    $s = suggestLanguage();
    successResponse([
        // Whether the client should ACT on this. Off by default: the site
        // defaults to English, and silently reloading somebody into Spanish
        // because of where they are undoes that. Flip `auto_language_by_region`
        // in Settings to turn regional detection back on — no deploy needed.
        'auto'   => (string)setting('auto_language_by_region', '0') === '1',
        'lang'   => $s['lang'],
        // Returned so the behaviour is debuggable from the outside — "why is
        // this in English" has a one-word answer instead of a support thread.
        'source' => $s['source'],
        'area'   => $s['area'],
    ]);
}

// GET /api/geo/maps-key — the browser key for Places autocomplete.
//
// Public on purpose, and safe: a Maps JavaScript key is visible to anyone who
// views the page source, by design — there is no way to use the JS API without
// shipping it to the browser. What protects it is the referrer restriction and
// the API allowlist set in the Google console, not secrecy. Serving it from
// here rather than hardcoding it into the HTML means it can be rotated from the
// admin panel without a deploy.
if (($_GET['action'] ?? '') === 'maps-key') {
    $key = trim((string)setting('google_maps_key', ''));
    successResponse([
        'key'     => $key,
        'enabled' => $key !== '',
    ]);
}

// ═══ GEOCODE ═════════════════════════════════════════════════════════════════
// A typed address turned into a position, when the customer never tapped a
// suggestion. Without this the form kept whatever coordinates were already in
// memory — so an address typed in one city was quoted, matched and checked for
// coverage in another, and the only symptom was a wrong answer that looked
// completely ordinary.
//
// $_GET['action'] read directly: this file has no $action variable, and the
// first version of this block compared one that was always null, so the
// endpoint answered "Unknown action" to everything.
if (($_GET['action'] ?? '') === 'geocode') {
    $q = (string)($_GET['q'] ?? ($_POST['q'] ?? ''));
    $hit = geocodeAddress($q);
    if (!$hit) errorResponse(t('err.geocode_none'), 404);
    successResponse($hit);
}

// ═══ ADDRESS SUGGESTIONS ═════════════════════════════════════════════════════
// Type-ahead for the native app, which cannot use the browser key.
//
// The key in the page is locked to the towsling.com REFERRER. An iPhone app
// sends no referrer, so that key is refused outright — and loosening it to make
// the app work would hand a usable key to anyone who views source on the
// website. The server key is locked to this machine's IP instead, so the call
// is made from here and the key never leaves the building.
//
// Only the descriptions come back. Resolving the one the operator taps is the
// existing /api/geo/geocode call, so there is one geocoding path with one cache
// rather than two that can disagree about where an address is.
if (($_GET['action'] ?? '') === 'suggest') {
    requireAuth();          // operators only; this costs money per keystroke

    $q = trim(preg_replace('/\s+/', ' ', (string)($_GET['q'] ?? '')));
    // Below three characters every request is a different guess at the same
    // street and none of them are useful. Answered as an empty list rather than
    // an error so the field simply shows nothing while somebody is still typing.
    if (mb_strlen($q) < 3) successResponse(['suggestions' => []]);

    $key = trim((string)setting('google_server_key', ''));
    if ($key === '') $key = trim((string)setting('google_maps_key', ''));
    if ($key === '') successResponse(['suggestions' => [], 'reason' => 'no_key']);

    $url = 'https://maps.googleapis.com/maps/api/place/autocomplete/json?'
         . http_build_query([
             'input'      => $q,
             'components' => 'country:us',
             // Street addresses, not restaurants. An operator setting a yard
             // wants "1200 NW 27th Ave", not the business currently in it.
             'types'      => 'address',
             'key'        => $key,
         ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
    $body = curl_exec($ch);
    curl_close($ch);

    $out = [];
    $data = $body !== false ? json_decode($body, true) : null;
    $status = $data['status'] ?? 'REQUEST_FAILED';
    if ($status === 'OK') {
        foreach (($data['predictions'] ?? []) as $p) {
            if (!empty($p['description'])) $out[] = $p['description'];
            if (count($out) >= 6) break;
        }
    } elseif ($status !== 'ZERO_RESULTS') {
        // A key or billing problem here is otherwise invisible — the field just
        // stops suggesting and everybody assumes they typed it wrong.
        error_log('[geo] places autocomplete: ' . $status
                  . ' ' . ($data['error_message'] ?? ''));
    }

    successResponse(['suggestions' => $out]);
}

errorResponse('Unknown action', 404);
