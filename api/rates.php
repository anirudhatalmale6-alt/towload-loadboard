<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/market_rates.php';
setCorsHeaders();

// ═══════════════════════════════════════════════════════════════════════════
//  A COMPANY'S OWN RATE SHEET
//
//  Read and write, and only ever your own. There is deliberately no endpoint
//  here that returns anyone else's numbers, and no admin listing of them
//  either: what a towing company charges is its own commercial information,
//  and the only thing that ever leaves this table is an average across a
//  whole market.
//
//  Saving triggers a recompute of the zone the company sits in, so a rate
//  changed at 9am is in the price a customer sees at 9:01. There is no cron
//  and nothing to remember to run.
// ═══════════════════════════════════════════════════════════════════════════

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ GET — my sheet, plus the questions to ask ═══════════════════════════════
if ($method === 'GET' && ($action === '' || $action === 'mine')) {
    $user = requireAuth();
    requireAccountType($user, 'tower');

    $mine = towerRates((int)$user['account_id']);
    $rows = [];
    foreach (rateSheetShape() as $s) {
        $key = $s['service'] . ':' . $s['class'];
        $rows[] = [
            'service_type'   => $s['service'],
            'vehicle_class'  => $s['class'],
            'asks_miles'     => $s['miles'],
            'label'          => t('rate.' . $s['service'] . '_' . $s['class']),
            'base_fee'       => $mine[$key]['base_fee']       ?? null,
            'included_miles' => $mine[$key]['included_miles'] ?? null,
            'per_mile'       => $mine[$key]['per_mile']       ?? null,
        ];
    }

    successResponse([
        'rows'       => $rows,
        'updated_at' => $mine ? max(array_column($mine, 'updated_at')) : null,
        'note'       => t('rate.promise'),
    ]);
}

// ═══ POST — save my sheet ════════════════════════════════════════════════════
if ($method === 'POST' && ($action === '' || $action === 'save')) {
    $user = requireAuth();
    requireAccountType($user, 'tower');

    $in = jsonInput();
    $rows = $in['rows'] ?? null;
    if (!is_array($rows)) errorResponse(t('err.bad_request'));

    $saved = saveTowerRates((int)$user['account_id'], $rows);

    // Only an approved company moves the market average. An unapproved one can
    // fill the sheet in — it is one of the things that makes approving them
    // worth doing — but it must not price a city it has not been vetted for.
    $report = null;
    if (($user['verification_status'] ?? '') === 'approved') {
        $st = getDB()->prepare(
            "SELECT tp.base_lat, tp.base_lng, a.state
               FROM tower_profiles tp JOIN accounts a ON a.id = tp.account_id
              WHERE tp.account_id = :a"
        );
        $st->execute([':a' => (int)$user['account_id']]);
        if ($p = $st->fetch()) {
            $zone = resolveZone(
                $p['base_lat'] !== null ? (float)$p['base_lat'] : null,
                $p['base_lng'] !== null ? (float)$p['base_lng'] : null,
                $p['state'] ?? null
            );
            if ((int)$zone['id'] !== NATIONAL_ZONE_ID) {
                $report = recomputeZoneRates((int)$zone['id']);
            }
        }
    }

    successResponse(['saved' => $saved, 'recomputed' => $report], t('ok.rates_saved'));
}

errorResponse('Unknown action', 404);
