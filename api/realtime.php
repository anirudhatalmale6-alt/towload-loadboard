<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/realtime.php';

setCorsHeaders();

// GET /api/realtime/config          — operator: where to connect
// GET /api/realtime/config?ticket=1&token=<tracking> — customer: where, plus a ticket
//
// The URL is public knowledge (the browser has to dial it), the ticket is not.
if (($_GET['action'] ?? '') !== 'config') errorResponse('Unknown action', 404);

$publicUrl = (string)setting('realtime_public_url', '');
$enabled   = realtimeEnabled() && $publicUrl !== '';

// A customer has no login, so this branch is unauthenticated by necessity —
// the tracking token in their own link is what is being exchanged.
if (!empty($_GET['ticket'])) {
    $token = $_GET['token'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) errorResponse(t('err.bad_tracking'), 404);

    // Only for a job that exists AND is still live. A finished job needs no
    // socket, and handing out tickets for one would keep a channel open on a
    // link that has been forwarded around for months.
    $stmt = getDB()->prepare(
        "SELECT status FROM calls WHERE tracking_token = :t"
    );
    $stmt->execute([':t' => $token]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    $live = in_array($call['status'], ['open','awarded','en_route','on_scene','in_progress'], true);

    successResponse([
        'enabled' => $enabled && $live,
        'url'     => $enabled && $live ? $publicUrl : null,
        // An hour, refreshed by the page as needed. Long enough to cover a job,
        // short enough that a forwarded link is not a permanent subscription.
        'ticket'  => $enabled && $live ? realtimeTicket($token, 3600) : null,
    ]);
}

$user = requireAuth();
successResponse([
    'enabled' => $enabled,
    'url'     => $enabled ? $publicUrl : null,
]);
