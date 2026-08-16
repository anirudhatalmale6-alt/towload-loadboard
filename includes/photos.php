<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/uploads.php';

// ═══════════════════════════════════════════════════════════════════════════
//  JOB PHOTOS
//
//  What a towing company photographs, and why each one is on the list.
//
//  This exists for one argument: three weeks after a tow, a customer says the
//  bumper was not like that when they handed the car over. Without photographs
//  that argument is one person's word against another's, and the company —
//  and behind them the platform — loses it by default.
//
//  The shot list is defined once, here, and read by both the API and the
//  dashboard. A checklist on screen that disagreed with what the server counted
//  would be worse than no checklist: the driver would believe he was covered.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * The required shots, in the order a driver actually walks around the vehicle.
 *
 * 'stage' is when it is taken:
 *   pickup  — before the car goes on the truck. This is the set that answers a
 *             damage claim, so it is the one that matters.
 *   dropoff — proof the job finished and the car was handed over as it left.
 */
function photoRequirements(): array {
    return [
        ['key' => 'corner_fl', 'stage' => 'pickup',  'label' => t('ph.corner_fl'), 'hint' => t('ph.corner_fl_h')],
        ['key' => 'corner_fr', 'stage' => 'pickup',  'label' => t('ph.corner_fr'), 'hint' => t('ph.corner_fr_h')],
        ['key' => 'corner_rr', 'stage' => 'pickup',  'label' => t('ph.corner_rr'), 'hint' => t('ph.corner_rr_h')],
        ['key' => 'corner_rl', 'stage' => 'pickup',  'label' => t('ph.corner_rl'), 'hint' => t('ph.corner_rl_h')],
        ['key' => 'plate',     'stage' => 'pickup',  'label' => t('ph.plate'),     'hint' => t('ph.plate_h')],
        ['key' => 'vin',       'stage' => 'pickup',  'label' => t('ph.vin'),       'hint' => t('ph.vin_h')],
        ['key' => 'dropoff',   'stage' => 'dropoff', 'label' => t('ph.dropoff'),   'hint' => t('ph.dropoff_h')],
    ];
}

/** Types a driver may add as many of as he likes, on top of the required set. */
function photoExtraTypes(): array {
    return ['damage', 'hookup', 'arrival', 'goa', 'other'];
}

function photoTypeIsValid(string $type): bool {
    foreach (photoRequirements() as $r) {
        if ($r['key'] === $type) return true;
    }
    return in_array($type, photoExtraTypes(), true);
}

/**
 * What has been taken and what is still missing for one job.
 *
 * Returned as a list rather than a yes/no so the dashboard can tick items off
 * as they are taken, and so the message on the completion screen can name the
 * ones that are actually missing instead of saying "some photos".
 */
function photoState(int $callId): array {
    $stmt = getDB()->prepare(
        "SELECT id, photo_type, taken_at, created_at FROM call_photos
          WHERE call_id = :c ORDER BY id ASC"
    );
    $stmt->execute([':c' => $callId]);
    $rows = $stmt->fetchAll();

    $byType = [];
    foreach ($rows as $r) {
        $byType[$r['photo_type']][] = ['id' => (int)$r['id'], 'at' => $r['created_at']];
    }

    $items = [];
    $missingPickup = [];
    $missingDropoff = [];

    foreach (photoRequirements() as $req) {
        $have = $byType[$req['key']] ?? [];
        $done = count($have) > 0;
        $items[] = [
            'key'   => $req['key'],
            'stage' => $req['stage'],
            'label' => $req['label'],
            'hint'  => $req['hint'],
            'done'  => $done,
            'count' => count($have),
            'photo_id' => $done ? $have[0]['id'] : null,
        ];
        if (!$done) {
            if ($req['stage'] === 'pickup') $missingPickup[] = $req['label'];
            else                            $missingDropoff[] = $req['label'];
        }
    }

    $extras = 0;
    foreach (photoExtraTypes() as $t) {
        $extras += count($byType[$t] ?? []);
    }

    return [
        'required'         => (string)setting('require_job_photos', '1') === '1',
        'items'            => $items,
        'total'            => count($rows),
        'extras'           => $extras,
        'pickup_done'      => count($missingPickup) === 0,
        'dropoff_done'     => count($missingDropoff) === 0,
        'complete'         => count($missingPickup) === 0 && count($missingDropoff) === 0,
        'missing_pickup'   => $missingPickup,
        'missing_dropoff'  => $missingDropoff,
        // One sentence naming what is actually absent, for the confirmation the
        // driver sees before finishing a job without them.
        'missing_summary'  => implode(', ', array_merge($missingPickup, $missingDropoff)),
    ];
}

/**
 * Every photo on a job, for whoever is allowed to see it.
 * Never returns a path — files come back through the serving endpoint.
 */
function photoList(int $callId): array {
    $stmt = getDB()->prepare(
        "SELECT id, photo_type, note, taken_at, created_at, mime_type
           FROM call_photos WHERE call_id = :c ORDER BY id ASC"
    );
    $stmt->execute([':c' => $callId]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'id'         => (int)$r['id'],
            'photo_type' => $r['photo_type'],
            'label'      => photoLabel($r['photo_type']),
            'note'       => $r['note'],
            'taken_at'   => $r['taken_at'] ?: $r['created_at'],
            'url'        => 'api/calls/photo?id=' . (int)$r['id'],
        ];
    }
    return $out;
}

function photoLabel(string $type): string {
    foreach (photoRequirements() as $r) {
        if ($r['key'] === $type) return $r['label'];
    }
    // t() returns the key itself when it has no translation, so an unknown
    // type would print "ph.type_hookup" on screen. Check for that rather than
    // passing a default t() does not take.
    $key = 'ph.type_' . $type;
    $label = t($key);
    return $label === $key ? t('ph.type_other') : $label;
}
