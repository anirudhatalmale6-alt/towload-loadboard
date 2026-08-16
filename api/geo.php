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

errorResponse('Unknown action', 404);
