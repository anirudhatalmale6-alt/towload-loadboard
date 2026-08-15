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

errorResponse('Unknown action', 404);
