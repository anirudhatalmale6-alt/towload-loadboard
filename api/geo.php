<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/geo.php';

setCorsHeaders();

// GET /api/geo/lang — which language this visitor should see first.
// Public: it is asked before anyone has an account, and the answer is not
// sensitive. Cached hard by the page so it costs one request per browser.
if (($_GET['action'] ?? '') === 'lang') {
    $s = suggestLanguage();
    successResponse([
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

errorResponse('Unknown action', 404);
