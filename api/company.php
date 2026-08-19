<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/matching.php';
require_once __DIR__ . '/../includes/branding.php';

// ═══════════════════════════════════════════════════════════════════════════
//  THE COMPANY'S OWN RECORD
//
//  Everything a towing company can see and change about itself: contact
//  details, the equipment it runs, and the trucks in its yard.
//
//    GET  /api/company/overview
//    POST /api/company/truck-save    {id?, label, truck_type, ...}
//    POST /api/company/truck-delete  {id}
//
//  Details and capability flags are saved through /api/auth/update-profile,
//  which already owns those columns. Duplicating that write here would give two
//  endpoints permission to disagree about the same row.
// ═══════════════════════════════════════════════════════════════════════════

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

const TRUCK_TYPES     = ['flatbed','wheel_lift','wrecker','heavy_wrecker','service_van','other'];
const TRUCK_CLASSES   = ['light','medium','heavy'];
const TRUCK_EQUIPMENT = ['dollies','winch','straps','wheel_lift','low_clearance','ev_kit',
                         'air_cushion','rollback','jump_pack','fuel_can','lockout_kit'];

function myTrucks(int $accountId): array {
    $stmt = getDB()->prepare(
        "SELECT id, label, truck_type, capacity_class, make, model, year, plate, equipment, notes
           FROM tower_trucks
          WHERE account_id = :a AND is_active = 1
          ORDER BY id ASC"
    );
    $stmt->execute([':a' => $accountId]);
    $out = [];
    foreach ($stmt as $r) {
        $r['id']        = (int)$r['id'];
        $r['year']      = $r['year'] !== null ? (int)$r['year'] : null;
        $r['equipment'] = $r['equipment'] ? explode(',', $r['equipment']) : [];
        $out[] = $r;
    }
    return $out;
}

/**
 * trucks_count is shown to customers and used in the operator's own summary.
 * Kept in step with the list rather than left as whatever was typed at signup,
 * which for most accounts is "1" forever.
 */
function syncTruckCount(int $accountId): void {
    getDB()->prepare(
        "UPDATE tower_profiles
            SET trucks_count = GREATEST(1, (SELECT COUNT(*) FROM tower_trucks
                                             WHERE account_id = :a AND is_active = 1))
          WHERE account_id = :a2"
    )->execute([':a' => $accountId, ':a2' => $accountId]);
}

// ═══ OVERVIEW ════════════════════════════════════════════════════════════════
if ($method === 'GET' && ($action === 'overview' || $action === '')) {
    $user = requireAuth();
    requireAccountType($user, 'tower');

    $stmt = getDB()->prepare(
        "SELECT a.id, a.name, a.legal_name, a.ein, a.email, a.phone, a.address, a.city,
                a.state, a.zip, a.website, a.verification_status,
                a.email_verified_at, a.email_verified_value,
                a.phone_verified_at, a.phone_verified_value,
                p.dot_number, p.mc_number, p.service_radius_miles, p.base_lat, p.base_lng,
                p.has_light_duty, p.has_medium_duty, p.has_heavy_duty, p.has_flatbed,
                p.has_wheel_lift, p.has_winch_recovery, p.has_lockout, p.has_jumpstart,
                p.has_tire_change, p.has_fuel_delivery, p.has_motorcycle, p.has_ev_certified,
                p.has_lowclearance, p.is_24_7, p.trucks_count, p.accepts_auto_dispatch,
                p.is_available, p.available_changed_at,
                a.logo_url, a.rating_avg, a.rating_count
           FROM accounts a
           JOIN tower_profiles p ON p.account_id = a.id
          WHERE a.id = :a"
    );
    $stmt->execute([':a' => $user['account_id']]);
    $row = $stmt->fetch();
    if (!$row) errorResponse(t('err.account_not_found'), 404);

    // Numbers as NUMBERS. base_lat/base_lng are DECIMAL columns and PDO hands
    // them back as strings, so json_encode emitted "27.2772854" with quotes.
    // A typed client decoding that into a Double throws, and because one throw
    // fails the WHOLE object, the entire screen died with "something went
    // wrong" — but only for a company that had actually set a yard, which is
    // why it looked account-specific rather than like a bug.
    $row['base_lat'] = $row['base_lat'] !== null ? (float)$row['base_lat'] : null;
    $row['base_lng'] = $row['base_lng'] !== null ? (float)$row['base_lng'] : null;
    $row['service_radius_miles'] = $row['service_radius_miles'] !== null
        ? (int)$row['service_radius_miles'] : null;
    $row['trucks_count'] = $row['trucks_count'] !== null ? (int)$row['trucks_count'] : null;

    // The raw verified values are internal — the client only needs to know
    // whether the CURRENT address and number have been confirmed.
    $row['email_verified'] = verifiedFor($row, 'email');
    $row['phone_verified'] = verifiedFor($row, 'phone');
    unset($row['email_verified_value'], $row['phone_verified_value'],
          $row['email_verified_at'], $row['phone_verified_at']);

    $row['rating_avg'] = (float)$row['rating_avg'];
    // Counted from the ratings rows, exactly as /company/reviews does.
    //
    // accounts.rating_count is the denormalised copy and the two disagree
    // wherever it was seeded or edited directly. Reporting the stored one here
    // put "Customer reviews 2" as a heading directly above the sentence "No
    // reviews yet" — the heading and the panel it labels contradicting each
    // other on the same screen.
    $row['rating_count_stored'] = (int)$row['rating_count'];
    $rc = getDB()->prepare("SELECT COUNT(*) FROM ratings WHERE rated_account_id = :a");
    $rc->execute([':a' => $user['account_id']]);
    $row['rating_count'] = (int)$rc->fetchColumn();
    // Absolute, because the app is not served from the same origin as the
    // site and a relative path there resolves against nothing.
    $row['logo_url'] = absoluteUrl($row['logo_url']);

    successResponse([
        'company'      => $row,
        'trucks'       => myTrucks((int)$user['account_id']),
        'truck_types'  => TRUCK_TYPES,
        'equipment'    => TRUCK_EQUIPMENT,
        'verification' => towerVerificationSteps((int)$user['account_id']),
    ]);
}

// ═══ ADD / EDIT A TRUCK ══════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'truck-save') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner', 'dispatcher']);

    $in    = jsonInput();
    $id    = (int)($in['id'] ?? 0);
    $label = trim((string)($in['label'] ?? ''));
    if ($label === '') errorResponse(t('err.truck_label_required'));

    $type  = in_array($in['truck_type'] ?? '', TRUCK_TYPES, true) ? $in['truck_type'] : 'flatbed';
    $class = in_array($in['capacity_class'] ?? '', TRUCK_CLASSES, true) ? $in['capacity_class'] : 'light';

    // Whitelisted, so an unknown key cannot be stored and then rendered back
    // onto the page as a label nobody has a translation for.
    $equip = array_values(array_intersect(
        is_array($in['equipment'] ?? null) ? $in['equipment'] : [],
        TRUCK_EQUIPMENT
    ));

    $year = !empty($in['year']) ? (int)$in['year'] : null;
    if ($year !== null && ($year < 1950 || $year > (int)date('Y') + 2)) $year = null;

    $params = [
        ':label' => mb_substr($label, 0, 80),
        ':type'  => $type,
        ':class' => $class,
        ':make'  => !empty($in['make'])  ? mb_substr(trim($in['make']), 0, 60)  : null,
        ':model' => !empty($in['model']) ? mb_substr(trim($in['model']), 0, 60) : null,
        ':year'  => $year,
        ':plate' => !empty($in['plate']) ? mb_substr(trim($in['plate']), 0, 20) : null,
        ':equip' => $equip ? implode(',', $equip) : null,
        ':notes' => !empty($in['notes']) ? mb_substr(trim($in['notes']), 0, 255) : null,
        ':a'     => $user['account_id'],
    ];

    if ($id > 0) {
        // account_id in the WHERE, not just the id — otherwise any operator can
        // edit any other company's truck by guessing a number.
        $params[':id'] = $id;
        $stmt = getDB()->prepare(
            "UPDATE tower_trucks
                SET label = :label, truck_type = :type, capacity_class = :class,
                    make = :make, model = :model, year = :year, plate = :plate,
                    equipment = :equip, notes = :notes
              WHERE id = :id AND account_id = :a AND is_active = 1"
        );
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            // Either it is not theirs, or nothing changed. Confirm ownership
            // before claiming success.
            $chk = getDB()->prepare("SELECT id FROM tower_trucks WHERE id = :id AND account_id = :a AND is_active = 1");
            $chk->execute([':id' => $id, ':a' => $user['account_id']]);
            if (!$chk->fetch()) errorResponse(t('err.truck_not_found'), 404);
        }
    } else {
        getDB()->prepare(
            "INSERT INTO tower_trucks
                (account_id, label, truck_type, capacity_class, make, model, year, plate, equipment, notes)
             VALUES (:a, :label, :type, :class, :make, :model, :year, :plate, :equip, :notes)"
        )->execute($params);
        $id = (int)getDB()->lastInsertId();
    }

    syncTruckCount((int)$user['account_id']);
    successResponse(['id' => $id, 'trucks' => myTrucks((int)$user['account_id'])], t('ok.truck_saved'));
}

// ═══ DELETE A TRUCK ══════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'truck-delete') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner']);

    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if (!$id) errorResponse(t('err.truck_not_found'), 404);

    // Soft delete — see the migration. The row may be referenced by a job that
    // has already happened.
    $stmt = getDB()->prepare(
        "UPDATE tower_trucks SET is_active = 0 WHERE id = :id AND account_id = :a AND is_active = 1"
    );
    $stmt->execute([':id' => $id, ':a' => $user['account_id']]);
    if ($stmt->rowCount() === 0) errorResponse(t('err.truck_not_found'), 404);

    syncTruckCount((int)$user['account_id']);
    successResponse(['trucks' => myTrucks((int)$user['account_id'])], t('ok.truck_deleted'));
}

// ═══ READY FOR JOBS ══════════════════════════════════════════════════════════
// The duty switch. Off means the company is not offered work: no job alerts,
// and it stops counting as coverage for customers looking for a truck nearby.
//
// It deliberately does NOT hide the job board or block accepting. Someone who
// flips back on should not have to wait for the next alert to work, and a
// company that spots a job it wants should be able to take it. "Not offered"
// and "not allowed" are different things.
if ($method === 'POST' && $action === 'availability') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner', 'dispatcher']);

    $in = jsonInput();
    if (!array_key_exists('available', $in)) errorResponse(t('err.bad_request'));
    $on = !empty($in['available']);

    getDB()->prepare(
        "UPDATE tower_profiles SET is_available = :v, available_changed_at = NOW()
          WHERE account_id = :a"
    )->execute([':v' => $on ? 1 : 0, ':a' => $user['account_id']]);

    successResponse(['available' => $on], t($on ? 'ok.now_available' : 'ok.now_unavailable'));
}

// ═══ COMPANY LOGO ════════════════════════════════════════════════════════════
//
// Shown to a stranded customer: beside the company name on the tracking screen,
// and as the marker on the map. Public by design — see includes/branding.php
// for why that is stored differently from a compliance document.
if ($method === 'POST' && $action === 'logo') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner', 'dispatcher']);

    $stored = storeCompanyLogo($_FILES['file'] ?? [], (int)$user['account_id']);
    if (empty($stored['ok'])) errorResponse($stored['error'], 400);

    $pdo = getDB();
    // The old files go only after the new row is committed. Deleting first and
    // then failing the UPDATE would leave a company pointing at nothing, which
    // is worse than leaving two files on disk.
    $prev = $pdo->prepare("SELECT logo_url FROM accounts WHERE id = :a");
    $prev->execute([':a' => $user['account_id']]);
    $old = $prev->fetch()['logo_url'] ?? null;

    $pdo->prepare("UPDATE accounts SET logo_url = :u WHERE id = :a")
        ->execute([':u' => $stored['logo_url'], ':a' => $user['account_id']]);

    if ($old && $old !== $stored['logo_url']) deleteCompanyLogo($old);

    successResponse([
        'logo_url' => absoluteUrl($stored['logo_url']),
        'pin_url'  => absoluteUrl($stored['pin_url']),
    ], t('ok.logo_saved'));
}

if ($method === 'POST' && $action === 'logo-remove') {
    $user = requireAuth();
    requireAccountType($user, 'tower');
    requireRole($user, ['owner', 'dispatcher']);

    $pdo = getDB();
    $prev = $pdo->prepare("SELECT logo_url FROM accounts WHERE id = :a");
    $prev->execute([':a' => $user['account_id']]);
    $old = $prev->fetch()['logo_url'] ?? null;

    $pdo->prepare("UPDATE accounts SET logo_url = NULL WHERE id = :a")
        ->execute([':a' => $user['account_id']]);
    deleteCompanyLogo($old);

    successResponse(['logo_url' => null], t('ok.logo_removed'));
}

// ═══ REVIEWS LEFT FOR A COMPANY ══════════════════════════════════════════════
//
// Two callers, one query. A signed-in operator reading his own reviews, and a
// customer looking up the company that has just been assigned to them — the
// second has no account, so it is keyed on the job's tracking token instead.
//
// Nothing here is private: a review is a public statement about a business.
// What is NOT returned is who left it. The customer's name is on the job, and
// pairing "★1, drove off without me" with a name and a pickup address is how a
// review becomes an address for a reprisal.
if ($method === 'GET' && $action === 'reviews') {
    $accountId = 0;

    if (!empty($_GET['token'])) {
        if (!preg_match('/^[a-f0-9]{32}$/', $_GET['token'])) errorResponse(t('err.bad_tracking'), 404);
        $s = getDB()->prepare("SELECT awarded_tower_account_id FROM calls WHERE tracking_token = :t");
        $s->execute([':t' => $_GET['token']]);
        $accountId = (int)($s->fetch()['awarded_tower_account_id'] ?? 0);
        if (!$accountId) errorResponse(t('err.job_not_found'), 404);
    } else {
        $user = requireAuth();
        requireAccountType($user, 'tower');
        $accountId = (int)$user['account_id'];
    }

    $limit  = max(1, min(100, (int)($_GET['limit'] ?? 25)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $head = getDB()->prepare(
        "SELECT name, rating_avg, rating_count, jobs_completed, logo_url, verification_status
           FROM accounts WHERE id = :a"
    );
    $head->execute([':a' => $accountId]);
    $company = $head->fetch();
    if (!$company) errorResponse(t('err.account_not_found'), 404);

    $stmt = getDB()->prepare(
        "SELECT r.stars, r.comment, r.created_at, c.service_type, c.pickup_city, c.pickup_state
           FROM ratings r
           LEFT JOIN calls c ON c.id = r.call_id
          WHERE r.rated_account_id = :a
          ORDER BY r.created_at DESC
          LIMIT $limit OFFSET $offset"
    );
    $stmt->execute([':a' => $accountId]);

    $reviews = [];
    foreach ($stmt->fetchAll() as $r) {
        $reviews[] = [
            'stars'        => (int)$r['stars'],
            // Blank comments are common and a card with nothing in it is worse
            // than no card. The client decides; it gets null, not "".
            'comment'      => ($r['comment'] !== null && trim($r['comment']) !== '')
                                ? trim($r['comment']) : null,
            'created_at'   => $r['created_at'],
            'service_type' => $r['service_type'],
            'area'         => trim(($r['pickup_city'] ?? '') . ', ' . ($r['pickup_state'] ?? ''), ', ') ?: null,
        ];
    }

    // The star breakdown is computed over ALL reviews, never over the page that
    // was just fetched. "5 five-star reviews" under a company with two hundred
    // is a different claim from the one the bars are meant to make.
    $bd = getDB()->prepare(
        "SELECT stars, COUNT(*) AS n FROM ratings
          WHERE rated_account_id = :a GROUP BY stars"
    );
    $bd->execute([':a' => $accountId]);
    $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach ($bd->fetchAll() as $b) $breakdown[(int)$b['stars']] = (int)$b['n'];

    // Counted from the rows this endpoint can actually show, NOT from
    // accounts.rating_count.
    //
    // Those two are supposed to be the same number — recalcRating() derives one
    // from the other — but a seeded or hand-edited counter never passes through
    // it. A screen that promises 63 reviews and then lists none reads as broken
    // in a way that no amount of "no comments yet" copy rescues, so this screen
    // only ever claims what it can display.
    $realCount = array_sum($breakdown);

    // How many carry words. A company with 40 ratings and 3 comments should not
    // look like it has 40 written reviews further down the page.
    $wc = getDB()->prepare(
        "SELECT COUNT(*) FROM ratings
          WHERE rated_account_id = :a AND comment IS NOT NULL AND TRIM(comment) <> ''"
    );
    $wc->execute([':a' => $accountId]);

    successResponse([
        'company' => [
            'name'          => $company['name'],
            // The average is still the stored one — it is what the board and
            // the customer's driver card show, and disagreeing with them on the
            // same screen is worse than either number alone.
            'rating_avg'    => (float)$company['rating_avg'],
            'rating_count'  => $realCount,
            // What the rest of the product believes. Equal to rating_count on
            // any account whose ratings came through saveCustomerRating().
            // Different means somebody wrote the counter directly.
            'rating_count_stored' => (int)$company['rating_count'],
            'jobs_completed'=> (int)$company['jobs_completed'],
            'verified'      => $company['verification_status'] === 'approved',
            'logo_url'      => absoluteUrl($company['logo_url']),
        ],
        'reviews'        => $reviews,
        'breakdown'      => $breakdown,
        'with_comments'  => (int)$wc->fetchColumn(),
        'has_more'       => ($offset + count($reviews)) < $realCount,
    ]);
}

errorResponse('Unknown action', 404);
