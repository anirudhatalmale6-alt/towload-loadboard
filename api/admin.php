<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/adminauth.php';
require_once __DIR__ . '/../includes/market_rates.php';
require_once __DIR__ . '/../includes/zones.php';
require_once __DIR__ . '/../includes/surge.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/lifecycle.php';
require_once __DIR__ . '/../includes/support.php';
require_once __DIR__ . '/../includes/withdrawals.php';
require_once __DIR__ . '/../includes/settlement.php';
setCorsHeaders();

// ═══════════════════════════════════════════════════════════════════════════
//  PLATFORM ADMIN API
//
//  Everything Ricardo has to be able to do by hand: approve the towing
//  companies, override prices in a market, pull the emergency brake on surge,
//  open a city, and work the list of people who wanted a truck somewhere we
//  do not cover yet.
// ═══════════════════════════════════════════════════════════════════════════

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ═══ LOGIN ═══════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'login') {
    $in = jsonInput();
    if (empty($in['email']) || empty($in['password'])) errorResponse(t('err.bad_login'), 400);

    $stmt = getDB()->prepare("SELECT * FROM admin_users WHERE email = :e AND is_active = 1");
    $stmt->execute([':e' => $in['email']]);
    $admin = $stmt->fetch();

    // Same message and roughly the same work either way — a different response
    // for "no such user" tells an attacker which addresses are real.
    if (!$admin || !password_verify($in['password'], $admin['password_hash'])) {
        errorResponse(t('err.bad_login'), 401);
    }

    getDB()->prepare("UPDATE admin_users SET last_login_at = NOW() WHERE id = :id")
           ->execute([':id' => $admin['id']]);
    adminLog((int)$admin['id'], 'login', $admin['email']);

    successResponse([
        'token' => generateAdminJWT((int)$admin['id'], $admin['role']),
        'admin' => ['id' => (int)$admin['id'], 'name' => $admin['name'],
                    'email' => $admin['email'], 'role' => $admin['role']],
    ], t('ok.logged_in'));
}

// ═══ DASHBOARD ═══════════════════════════════════════════════════════════════
if ($method === 'GET' && ($action === 'dashboard' || $action === '')) {
    requireAdmin();
    $pdo = getDB();

    $one = function (string $sql) use ($pdo) {
        return (int)$pdo->query($sql)->fetch()['n'];
    };

    successResponse([
        'pending_review'   => $one("SELECT COUNT(*) n FROM accounts WHERE account_type='tower' AND verification_status='pending'"),
        'approved_towers'  => $one("SELECT COUNT(*) n FROM accounts WHERE account_type='tower' AND verification_status='approved' AND is_active=1"),
        'open_jobs'        => $one("SELECT COUNT(*) n FROM calls WHERE status='open'"),
        'active_jobs'      => $one("SELECT COUNT(*) n FROM calls WHERE status IN ('awarded','en_route','on_scene','in_progress')"),
        'jobs_today'       => $one("SELECT COUNT(*) n FROM calls WHERE DATE(created_at)=CURDATE()"),
        'unclaimed_today'  => $one("SELECT COUNT(*) n FROM calls WHERE status='expired' AND DATE(created_at)=CURDATE()"),
        'new_leads'        => $one("SELECT COUNT(*) n FROM coverage_leads WHERE contacted=0"),
        'expiring_coi'     => $one("SELECT COUNT(*) n FROM compliance_docs WHERE doc_type LIKE 'coi%' AND status='approved' AND expires_at IS NOT NULL AND expires_at < DATE_ADD(CURDATE(), INTERVAL 30 DAY)"),
        'emergency_mode'   => (string)setting('emergency_mode', '0') === '1',
        'surge_enabled'    => (string)setting('surge_enabled', '1') === '1',
        'surge_max'        => (float)setting('surge_max_multiplier', 1.8),
        'revenue_today'    => (float)($pdo->query(
            "SELECT COALESCE(SUM(platform_fee),0) n FROM calls WHERE status='completed' AND DATE(completed_at)=CURDATE()"
        )->fetch()['n'] ?? 0),
    ]);
}

// ═══ REVIEW QUEUE ════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'accounts') {
    requireAdmin();
    $status = $_GET['status'] ?? 'pending';
    $valid = ['unverified','pending','approved','rejected','suspended'];
    if (!in_array($status, $valid, true)) $status = 'pending';

    $stmt = getDB()->prepare(
        "SELECT a.id, a.name, a.legal_name, a.ein, a.email, a.phone, a.city, a.state,
                a.verification_status, a.docs_submitted_at, a.verified_at, a.rejection_reason,
                a.rating_avg, a.jobs_completed, a.created_at,
                tp.trucks_count, tp.service_radius_miles, tp.is_24_7,
                (SELECT COUNT(*) FROM compliance_docs d
                  WHERE d.account_id = a.id AND d.status IN ('pending','approved')) AS doc_count
           FROM accounts a
           LEFT JOIN tower_profiles tp ON tp.account_id = a.id
          WHERE a.account_type = 'tower' AND a.verification_status = :s
          ORDER BY a.docs_submitted_at IS NULL, a.docs_submitted_at ASC, a.created_at ASC
          LIMIT 200"
    );
    $stmt->execute([':s' => $status]);
    successResponse(['accounts' => $stmt->fetchAll()]);
}

if ($method === 'GET' && $action === 'account') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    $pdo = getDB();

    $stmt = $pdo->prepare(
        "SELECT a.*, tp.* FROM accounts a
           LEFT JOIN tower_profiles tp ON tp.account_id = a.id
          WHERE a.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $account = $stmt->fetch();
    if (!$account) errorResponse(t('err.job_not_found'), 404);

    $stmt = $pdo->prepare(
        "SELECT id, doc_type, file_name, mime_type, file_size, policy_number, carrier_name,
                coverage_amount, issued_at, expires_at, status, review_notes, created_at
           FROM compliance_docs WHERE account_id = :a AND status <> 'expired'
          ORDER BY FIELD(status,'pending','rejected','approved'), created_at DESC"
    );
    $stmt->execute([':a' => $id]);
    $docs = $stmt->fetchAll();
    foreach ($docs as &$d) {
        $d['label'] = t('doc.' . $d['doc_type']);
        // Not a URL to the file — a URL to the authenticated endpoint.
        $d['view_url'] = 'api/docs/file?id=' . (int)$d['id'];
    }
    unset($d);

    $stmt = $pdo->prepare(
        "SELECT doc_key, version, locale, accepted_at, ip_address
           FROM agreement_acceptances WHERE account_id = :a ORDER BY accepted_at DESC"
    );
    $stmt->execute([':a' => $id]);

    $users = $pdo->prepare("SELECT id, email, first_name, last_name, phone, role FROM users WHERE account_id = :a");
    $users->execute([':a' => $id]);

    successResponse([
        'account'     => $account,
        'documents'   => $docs,
        'acceptances' => $stmt->fetchAll(),
        'users'       => $users->fetchAll(),
    ]);
}

// ═══ REVIEW A SINGLE DOCUMENT ════════════════════════════════════════════════
if ($method === 'POST' && $action === 'review-doc') {
    $admin = requireAdmin();
    $in = jsonInput();
    $docId = (int)($in['doc_id'] ?? 0);
    $decision = $in['decision'] ?? '';
    if (!in_array($decision, ['approved', 'rejected'], true)) errorResponse('decision must be approved or rejected');
    if ($decision === 'rejected' && empty($in['notes'])) errorResponse(t('err.reject_reason_required'));

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM compliance_docs WHERE id = :id");
    $stmt->execute([':id' => $docId]);
    $doc = $stmt->fetch();
    if (!$doc) errorResponse(t('err.doc_not_found'), 404);

    $pdo->prepare(
        "UPDATE compliance_docs
            SET status = :s, review_notes = :n, reviewed_by = :r, reviewed_at = NOW()
          WHERE id = :id"
    )->execute([':s' => $decision, ':n' => $in['notes'] ?? null,
                ':r' => $admin['id'], ':id' => $docId]);

    adminLog((int)$admin['id'], 'review_doc', "doc $docId ({$doc['doc_type']}) -> $decision");
    successResponse(['status' => $decision], t('ok.doc_reviewed'));
}

// ═══ APPROVE / REJECT A COMPANY ══════════════════════════════════════════════
if ($method === 'POST' && $action === 'review-account') {
    $admin = requireAdmin(['superadmin', 'support']);
    $in = jsonInput();
    $accountId = (int)($in['account_id'] ?? 0);
    $decision  = $in['decision'] ?? '';

    if (!in_array($decision, ['approved','rejected','suspended','pending'], true)) {
        errorResponse('Unknown decision');
    }
    if (in_array($decision, ['rejected','suspended'], true) && empty($in['reason'])) {
        errorResponse(t('err.reject_reason_required'));
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = :id AND account_type = 'tower'");
    $stmt->execute([':id' => $accountId]);
    $account = $stmt->fetch();
    if (!$account) errorResponse(t('err.job_not_found'), 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE accounts
                SET verification_status = :s,
                    verified_at = IF(:s2 = 'approved', NOW(), verified_at),
                    rejection_reason = :r,
                    reviewed_by_admin_id = :adm
              WHERE id = :id"
        )->execute([
            ':s' => $decision, ':s2' => $decision,
            ':r' => in_array($decision, ['rejected','suspended'], true) ? $in['reason'] : null,
            ':adm' => $admin['id'], ':id' => $accountId,
        ]);

        // Approving the company means the documents were looked at. Leaving
        // them Pending underneath an Approved account would block dispatch on
        // the insurance check for a company that was just cleared.
        if ($decision === 'approved') {
            $pdo->prepare(
                "UPDATE compliance_docs
                    SET status = 'approved', reviewed_by = :r, reviewed_at = NOW()
                  WHERE account_id = :a AND status = 'pending'"
            )->execute([':r' => $admin['id'], ':a' => $accountId]);
        }

        notify($accountId, 'verification',
            $decision === 'approved' ? t('notif.approved_title') : t('notif.review_title'),
            $decision === 'approved' ? t('notif.approved_body')
                                     : ($in['reason'] ?? ''), null);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Could not update: ' . $e->getMessage(), 500);
    }

    adminLog((int)$admin['id'], 'review_account',
        "account $accountId ({$account['name']}) -> $decision " . ($in['reason'] ?? ''));

    // Approving a company opens the market around it if nothing covers them
    // yet, and folds their rates into that market's average either way.
    //
    // Outside the transaction on purpose. A zone that fails to open is a
    // company waiting a little longer for work; a rolled-back approval is a
    // company told it was approved and then finding it was not.
    $market = null;
    if ($decision === 'approved') {
        try {
            $zoneId = ensureZoneForTower($accountId);
            if ($zoneId) {
                $market = ['zone_id' => $zoneId, 'zone' => zoneName(zoneById($zoneId))];
                adminLog((int)$admin['id'], 'market_open',
                    "account $accountId approved -> zone $zoneId");
            }
        } catch (Throwable $e) {
            adminLog((int)$admin['id'], 'market_open_failed',
                "account $accountId: " . $e->getMessage());
        }
    }

    successResponse(['verification_status' => $decision, 'market' => $market],
                    t('ok.account_reviewed'));
}

// ═══ ZONES / MARKETS ═════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'zones') {
    requireAdmin();
    $rows = getDB()->query(
        "SELECT z.*,
                (SELECT COUNT(*) FROM accounts a
                   JOIN tower_profiles t ON t.account_id = a.id
                  WHERE a.account_type='tower' AND a.verification_status='approved'
                    AND a.is_active=1
                    AND (z.state IS NULL OR a.state = z.state)) AS towers
           FROM pricing_zones z ORDER BY z.is_live DESC, z.radius_miles ASC, z.name"
    )->fetchAll();
    successResponse(['zones' => $rows]);
}

if ($method === 'POST' && $action === 'zone-save') {
    $admin = requireAdmin(['superadmin', 'finance']);
    $in = jsonInput();

    $fields = ['name','name_es','state','center_lat','center_lng','radius_miles',
               'rate_multiplier','is_live','surge_enabled','is_active'];
    $data = [];
    foreach ($fields as $f) if (array_key_exists($f, $in)) $data[$f] = $in[$f];

    if (isset($data['state']) && $data['state']) $data['state'] = strtoupper(substr($data['state'], 0, 2));
    foreach (['is_live','surge_enabled','is_active'] as $b) {
        if (isset($data[$b])) $data[$b] = !empty($data[$b]) ? 1 : 0;
    }
    // A market multiplier is a blunt instrument. Bound it so a stray keystroke
    // cannot make every tow in a state cost ten times what it should.
    if (isset($data['rate_multiplier'])) {
        $data['rate_multiplier'] = max(0.5, min(3.0, (float)$data['rate_multiplier']));
    }

    $pdo = getDB();
    $id = (int)($in['id'] ?? 0);

    if ($id) {
        if (!$data) errorResponse('Nothing to update');
        $sets = implode(', ', array_map(fn($f) => "$f = :$f", array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) $params[":$k"] = $v;
        $params[':id'] = $id;
        $pdo->prepare("UPDATE pricing_zones SET $sets WHERE id = :id")->execute($params);
    } else {
        if (empty($data['name'])) errorResponse(t('err.field_required', ['field' => 'name']));
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_map(fn($f) => ":$f", array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) $params[":$k"] = $v;
        $pdo->prepare("INSERT INTO pricing_zones ($cols) VALUES ($vals)")->execute($params);
        $id = (int)$pdo->lastInsertId();
    }

    adminLog((int)$admin['id'], 'zone_save', "zone $id " . json_encode($data));
    successResponse(['id' => $id], t('ok.saved'));
}

// ═══ REMOVE A MARKET ═════════════════════════════════════════════════════════
//
//  There was no way to undo "Add a market", so a typo was permanent. It is not
//  always a delete, though: `calls` and `surge_snapshots` carry the zone id of
//  the market a job was priced in, with no foreign key to stop the row going
//  away underneath them. Deleting a zone that has traded would leave completed
//  jobs pointing at a market that no longer exists and quietly corrupt the
//  history they are evidence for.
//
//  So: a market that never traded is deleted outright. One that has is switched
//  off and kept. Both stop it being used; only one is destructive, and it is
//  the one where there is nothing to destroy.
if ($method === 'POST' && $action === 'zone-delete') {
    $admin = requireAdmin(['superadmin']);
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if (!$id) errorResponse('id is required');
    if ($id === NATIONAL_ZONE_ID) errorResponse(t('err.zone_national'));

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM pricing_zones WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $zone = $stmt->fetch();
    if (!$zone) errorResponse(t('err.zone_not_found'), 404);

    $used = function (string $table) use ($pdo, $id): int {
        $s = $pdo->prepare("SELECT COUNT(*) n FROM $table WHERE zone_id = :z");
        $s->execute([':z' => $id]);
        return (int)$s->fetch()['n'];
    };
    $jobs = $used('calls') + $used('surge_snapshots');

    if ($jobs > 0) {
        $pdo->prepare("UPDATE pricing_zones SET is_active = 0, is_live = 0 WHERE id = :id")
            ->execute([':id' => $id]);
        adminLog((int)$admin['id'], 'zone_archive', "zone $id ({$zone['name']}) — $jobs rows reference it");
        successResponse(['archived' => true, 'referenced_by' => $jobs], t('ok.zone_archived'));
    }

    // Its price rules are derived configuration, not history — they go with it.
    $pdo->prepare("DELETE FROM pricing_rules WHERE zone_id = :z")->execute([':z' => $id]);
    $pdo->prepare("DELETE FROM pricing_zones WHERE id = :id")->execute([':id' => $id]);
    adminLog((int)$admin['id'], 'zone_delete', "zone $id ({$zone['name']})");
    successResponse(['archived' => false], t('ok.zone_removed'));
}

// ═══ MANUAL PRICE OVERRIDE FOR A MARKET ══════════════════════════════════════
// Always with an expiry. An override set during a storm and forgotten will
// quietly wreck conversion for months, and nobody will connect the two.
if ($method === 'POST' && $action === 'zone-surge') {
    $admin = requireAdmin(['superadmin', 'finance']);
    $in = jsonInput();
    $zoneId = (int)($in['zone_id'] ?? 0);
    if (!$zoneId) errorResponse('zone_id is required');

    if (!empty($in['clear'])) {
        getDB()->prepare(
            "UPDATE pricing_zones SET manual_surge = NULL, manual_surge_until = NULL,
                    manual_surge_note = NULL WHERE id = :id"
        )->execute([':id' => $zoneId]);
        adminLog((int)$admin['id'], 'zone_surge_clear', "zone $zoneId");
        successResponse([], t('ok.override_cleared'));
    }

    $mult  = (float)($in['multiplier'] ?? 1.0);
    $hours = max(1, min(168, (int)($in['hours'] ?? 6)));   // one week ceiling
    if ($mult < 0.5 || $mult > SURGE_ABSOLUTE_MAX) {
        errorResponse(t('err.surge_range', ['max' => SURGE_ABSOLUTE_MAX]));
    }

    getDB()->prepare(
        "UPDATE pricing_zones
            SET manual_surge = :m,
                manual_surge_until = DATE_ADD(NOW(), INTERVAL :h HOUR),
                manual_surge_note = :n
          WHERE id = :id"
    )->execute([':m' => $mult, ':h' => $hours,
                ':n' => substr((string)($in['note'] ?? ''), 0, 255), ':id' => $zoneId]);

    adminLog((int)$admin['id'], 'zone_surge_set', "zone $zoneId -> {$mult}x for {$hours}h");
    successResponse(['multiplier' => $mult, 'hours' => $hours], t('ok.override_set'));
}

// ═══ EMERGENCY BRAKE ═════════════════════════════════════════════════════════
// Raising towing prices during a declared state of emergency is unlawful in
// Florida and most other states. This is the switch, and it beats everything.
if ($method === 'POST' && $action === 'emergency') {
    $admin = requireAdmin(['superadmin']);
    $in = jsonInput();
    $on = !empty($in['on']) ? '1' : '0';

    getDB()->prepare(
        "INSERT INTO platform_settings (setting_key, setting_value, description)
         VALUES ('emergency_mode', :v, 'EMERGENCY BRAKE: forces every surge multiplier to 1.0')
         ON DUPLICATE KEY UPDATE setting_value = :v2"
    )->execute([':v' => $on, ':v2' => $on]);

    adminLog((int)$admin['id'], 'emergency_mode', $on === '1' ? 'ENABLED' : 'disabled');
    successResponse(['emergency_mode' => $on === '1'],
        $on === '1' ? t('ok.emergency_on') : t('ok.emergency_off'));
}

// ═══ PUSH HEALTH ═════════════════════════════════════════════════════════════
// Exists to answer one support call: "we never got that job."
//
// The number that matters is not how many notifications went out — it is how
// many approved companies have no working phone at all. Those are trucks that
// are live on the platform, allowed to take work, and structurally incapable of
// hearing about any of it. Nothing else on the platform surfaces them.
if ($method === 'GET' && $action === 'push-health') {
    requireAdmin();
    $pdo = getDB();

    $summary = $pdo->query(
        "SELECT
            COUNT(*)                                              AS devices_total,
            SUM(is_active = 1)                                    AS devices_active,
            SUM(is_active = 1 AND platform = 'ios')               AS ios_active,
            SUM(is_active = 1 AND platform = 'android')           AS android_active,
            SUM(is_active = 1 AND platform = 'ios' AND is_standalone = 0) AS ios_not_installed,
            SUM(is_active = 0)                                    AS devices_stopped
           FROM push_subscriptions"
    )->fetch() ?: [];

    // Approved, active towing companies with nothing that can receive an alert.
    $silent = $pdo->query(
        "SELECT a.id, a.name, a.phone, a.created_at,
                (SELECT COUNT(*) FROM push_subscriptions s
                  WHERE s.account_id = a.id AND s.is_active = 1) AS devices
           FROM accounts a
           JOIN tower_profiles p ON p.account_id = a.id
          WHERE a.account_type = 'tower'
            AND a.is_active = 1
            AND a.verification_status = 'approved'
         HAVING devices = 0
          ORDER BY a.created_at DESC
          LIMIT 100"
    )->fetchAll();

    // Companies that switched alerts off themselves. Different problem, and a
    // different conversation — worth separating from the ones who never managed
    // to turn them on.
    $optedOut = (int)$pdo->query(
        "SELECT COUNT(*) FROM tower_profiles p
           JOIN accounts a ON a.id = p.account_id
          WHERE p.push_enabled = 0 AND a.verification_status = 'approved' AND a.is_active = 1"
    )->fetchColumn();

    $day = $pdo->query(
        "SELECT COUNT(*) AS attempts, SUM(ok = 1) AS delivered, SUM(ok = 0) AS failed
           FROM push_deliveries WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"
    )->fetch() ?: [];

    $failures = $pdo->query(
        "SELECT d.created_at, d.http_code, d.error, d.kind, a.name AS company
           FROM push_deliveries d
      LEFT JOIN accounts a ON a.id = d.account_id
          WHERE d.ok = 0
          ORDER BY d.id DESC LIMIT 25"
    )->fetchAll();

    // Jobs in the last day that reached nobody. A run of these in one city is
    // not a push problem, it is a coverage problem wearing a push costume.
    $unheard = $pdo->query(
        "SELECT c.id, c.call_number, c.pickup_city, c.pickup_state, c.offer_amount, c.created_at
           FROM calls c
      LEFT JOIN push_deliveries d ON d.call_id = c.id AND d.ok = 1
          WHERE c.source = 'consumer'
            AND c.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            AND d.id IS NULL
          ORDER BY c.id DESC LIMIT 25"
    )->fetchAll();

    successResponse([
        'summary'       => array_map('intval', $summary),
        'silent_towers' => $silent,
        'opted_out'     => $optedOut,
        'last_24h'      => array_map('intval', $day),
        'failures'      => $failures,
        'unheard_jobs'  => $unheard,
        'push_enabled'  => (string)setting('push_enabled', '1') === '1',
        'configured'    => (string)setting('vapid_public_key', '') !== '',
    ]);
}

// ═══ SETTINGS ════════════════════════════════════════════════════════════════
// Whitelisted. platform_settings also holds the Stripe-adjacent and fee keys,
// and a generic write endpoint over all of them is a fee-percentage change one
// typo away from a support incident.
const EDITABLE_SETTINGS = [
    'surge_enabled','surge_max_multiplier','surge_window_minutes','surge_min_demand',
    'surge_tiers','surge_unclaimed_minutes','surge_unclaimed_weight','surge_disabled_states',
    'coverage_radius_miles','min_trucks_for_coverage',
    'consumer_fee_percent','consumer_fee_minimum','consumer_goa_amount',
    'consumer_call_expiry_min','after_hours_start','after_hours_end',
    'tower_minimum_net','required_tower_docs','require_terms_accept','terms_version',
    'auto_submit_for_review','require_coi_to_accept','max_upload_mb',
    'default_language','spanish_regions','geoip_provider',
    'tracking_enabled','tracking_ping_seconds','tracking_stale_seconds',
    'tracking_retain_days','tracking_road_factor','tracking_avg_speed_mph',
    'ios_app_url','android_app_url',
    'google_server_key',
];

// Keys whose VALUE must never leave the server, admin or not. The VAPID private
// key is the platform's push signing identity: anyone holding it can send a
// notification to every registered truck in the country. Being behind an admin
// login is not enough — it would sit in a browser cache, in a screenshot, and
// in whatever a support session copies out of the panel.
// google_server_key is IP-restricted to this machine and is never meant to
// reach a browser, so it is written but never read back — same treatment as the
// push signing key.
const SECRET_SETTINGS = ['vapid_private_key', 'geoip_key', 'google_server_key'];

if ($method === 'GET' && $action === 'settings') {
    requireAdmin();
    $rows = getDB()->query("SELECT setting_key, setting_value, description FROM platform_settings")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $secret = in_array($r['setting_key'], SECRET_SETTINGS, true);
        if ($secret) {
            // Shown as present-or-missing, never as a value. "Is push configured"
            // is a legitimate admin question; "what is the key" is not.
            $r['setting_value'] = $r['setting_value'] === '' ? '' : '••••••••  (set)';
        }
        $out[] = $r + [
            'editable' => !$secret && in_array($r['setting_key'], EDITABLE_SETTINGS, true),
            'secret'   => $secret,
        ];
    }
    successResponse(['settings' => $out]);
}

if ($method === 'POST' && $action === 'settings-save') {
    $admin = requireAdmin(['superadmin', 'finance']);
    $in = jsonInput();
    $updates = $in['settings'] ?? [];
    if (!is_array($updates) || !$updates) errorResponse('Nothing to save');

    $pdo = getDB();
    $stmt = $pdo->prepare(
        "UPDATE platform_settings SET setting_value = :v WHERE setting_key = :k"
    );
    $changed = [];
    foreach ($updates as $key => $value) {
        if (!in_array($key, EDITABLE_SETTINGS, true)) continue;
        // The fee is the number that decides whether towers stay. Bounded here
        // rather than trusted, because there is no undo on a job already priced.
        if ($key === 'consumer_fee_percent') $value = max(0, min(40, (float)$value));
        if ($key === 'surge_max_multiplier')  $value = max(1.0, min(SURGE_ABSOLUTE_MAX, (float)$value));
        $stmt->execute([':v' => (string)$value, ':k' => $key]);
        $changed[$key] = $value;
    }

    adminLog((int)$admin['id'], 'settings_save', json_encode($changed));
    successResponse(['changed' => $changed], t('ok.saved'));
}

// ═══ RATE TABLE ══════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'rates') {
    requireAdmin();
    $zoneId = (int)($_GET['zone_id'] ?? 0);
    $stmt = getDB()->prepare(
        "SELECT * FROM pricing_rules WHERE zone_id = :z ORDER BY service_type, vehicle_class"
    );
    $stmt->execute([':z' => $zoneId]);
    successResponse(['zone_id' => $zoneId, 'rates' => $stmt->fetchAll()]);
}

if ($method === 'POST' && $action === 'rate-save') {
    $admin = requireAdmin(['superadmin', 'finance']);
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);
    if (!$id) errorResponse('id is required');

    $numeric = ['base_fee','included_miles','per_mile','minimum_total',
                'after_hours_multiplier','weekend_multiplier','accident_surcharge',
                'no_keys_surcharge','wheels_locked_surcharge','underground_surcharge'];
    $data = [];
    foreach ($numeric as $f) {
        if (array_key_exists($f, $in)) $data[$f] = max(0, (float)$in[$f]);
    }
    if (array_key_exists('is_active', $in)) $data['is_active'] = !empty($in['is_active']) ? 1 : 0;
    if (!$data) errorResponse('Nothing to update');

    $sets = implode(', ', array_map(fn($f) => "$f = :$f", array_keys($data)));
    $params = [];
    foreach ($data as $k => $v) $params[":$k"] = $v;
    $params[':id'] = $id;
    getDB()->prepare("UPDATE pricing_rules SET $sets WHERE id = :id")->execute($params);

    adminLog((int)$admin['id'], 'rate_save', "rule $id " . json_encode($data));
    successResponse([], t('ok.saved'));
}

// ═══ COVERAGE LEADS ══════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'leads') {
    requireAdmin();
    $stmt = getDB()->prepare(
        "SELECT * FROM coverage_leads
          WHERE (:only = 0 OR contacted = 0)
          ORDER BY created_at DESC LIMIT 300"
    );
    $stmt->execute([':only' => !empty($_GET['new_only']) ? 1 : 0]);
    $leads = $stmt->fetchAll();

    // Where the demand actually is. This is the recruiting map — the cities
    // with the most unserved requests are the cities to open next.
    $clusters = getDB()->query(
        "SELECT city, state, COUNT(*) n, MAX(created_at) last_seen
           FROM coverage_leads WHERE kind = 'customer' AND city IS NOT NULL
          GROUP BY city, state ORDER BY n DESC LIMIT 25"
    )->fetchAll();

    successResponse(['leads' => $leads, 'clusters' => $clusters]);
}

if ($method === 'POST' && $action === 'lead-contacted') {
    $admin = requireAdmin();
    $in = jsonInput();
    getDB()->prepare("UPDATE coverage_leads SET contacted = 1 WHERE id = :id")
           ->execute([':id' => (int)($in['id'] ?? 0)]);
    adminLog((int)$admin['id'], 'lead_contacted', (string)($in['id'] ?? ''));
    successResponse([], t('ok.saved'));
}

// ═══ SURGE HISTORY ═══════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'surge-history') {
    requireAdmin();
    $stmt = getDB()->prepare(
        "SELECT * FROM surge_snapshots
          WHERE (:z = -1 OR zone_id = :z2)
            AND minute_bucket >= DATE_SUB(NOW(), INTERVAL :h HOUR)
          ORDER BY minute_bucket DESC LIMIT 500"
    );
    $z = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : -1;
    $stmt->execute([':z' => $z, ':z2' => $z, ':h' => max(1, min(168, (int)($_GET['hours'] ?? 24)))]);
    successResponse(['snapshots' => $stmt->fetchAll()]);
}

// ═══ RECENT JOBS ═════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'jobs') {
    requireAdmin();
    $stmt = getDB()->prepare(
        "SELECT c.id, c.call_number, c.status, c.service_type, c.pickup_city, c.pickup_state,
                c.offer_amount, c.platform_fee, c.surge_multiplier, c.surge_reason,
                c.payment_status, c.created_at, c.completed_at,
                t.name AS tower_name
           FROM calls c LEFT JOIN accounts t ON c.awarded_tower_account_id = t.id
          ORDER BY c.created_at DESC LIMIT 100"
    );
    $stmt->execute();
    successResponse(['jobs' => $stmt->fetchAll()]);
}

// ═══ MY LOGIN ════════════════════════════════════════════════════════════════
//
//  The admin password was seeded by hand at build time and there was no way to
//  change it from inside the panel, so "you should change this" was advice that
//  could not be acted on. Both the password and the login name are editable
//  here now. The login name is not an email address and nothing is ever sent to
//  it — it is a username that happens to be shaped like one.
if ($method === 'GET' && $action === 'me') {
    $me = requireAdmin();
    $stmt = getDB()->prepare("SELECT id, email, name, role, last_login_at FROM admin_users WHERE id = :id");
    $stmt->execute([':id' => (int)$me['id']]);
    successResponse(['admin' => $stmt->fetch()]);
}

if ($method === 'POST' && $action === 'change-login') {
    $me = requireAdmin();
    $in = jsonInput();

    $stmt = getDB()->prepare("SELECT * FROM admin_users WHERE id = :id");
    $stmt->execute([':id' => (int)$me['id']]);
    $admin = $stmt->fetch();
    if (!$admin) errorResponse(t('err.bad_login'), 401);

    // Proving you know the current one is the whole point: a stolen token
    // otherwise lets someone lock the real owner out of their own platform.
    if (empty($in['current_password']) || !password_verify($in['current_password'], $admin['password_hash'])) {
        errorResponse(t('err.current_password_wrong'), 401);
    }

    $sets   = [];
    $params = [':id' => (int)$admin['id']];
    $changed = [];

    if (!empty($in['new_password'])) {
        if (strlen((string)$in['new_password']) < 10) {
            errorResponse(t('err.admin_password_short'));
        }
        $sets[] = 'password_hash = :p';
        $params[':p'] = password_hash((string)$in['new_password'], PASSWORD_DEFAULT);
        $changed[] = 'password';
    }

    if (!empty($in['new_email'])) {
        $email = trim((string)$in['new_email']);
        if (strlen($email) < 5 || strpos($email, ' ') !== false) errorResponse(t('err.bad_request'));
        $dup = getDB()->prepare("SELECT id FROM admin_users WHERE email = :e AND id <> :id");
        $dup->execute([':e' => $email, ':id' => (int)$admin['id']]);
        if ($dup->fetch()) errorResponse(t('err.email_exists'));
        $sets[] = 'email = :e';
        $params[':e'] = $email;
        $changed[] = 'login name';
    }

    if (!$sets) errorResponse(t('err.bad_request'));

    getDB()->prepare("UPDATE admin_users SET " . implode(', ', $sets) . " WHERE id = :id")
           ->execute($params);

    // The detail is what changed, never the value.
    adminLog((int)$admin['id'], 'change_login', implode(' + ', $changed));

    successResponse(['changed' => $changed], t('ok.login_updated'));
}

// ═══ WHAT WOULD BE DESTROYED ═════════════════════════════════════════════════
// Always call this before offering the button. It is the only place that knows
// a company is still owed money, or still has a truck out on a job.
if ($method === 'GET' && $action === 'account-impact') {
    $admin = requireAdmin();
    $id = (int)($_GET['id'] ?? 0);
    $impact = deletionImpact($id);
    if (empty($impact['found'])) errorResponse(t('err.account_not_found'), 404);
    successResponse($impact);
}

// ═══ DELETE A COMPANY ════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'account-delete') {
    $admin = requireAdmin();
    $in = jsonInput();
    $id = (int)($in['id'] ?? 0);

    $impact = deletionImpact($id);
    if (empty($impact['found'])) errorResponse(t('err.account_not_found'), 404);

    // The name has to be typed. This screen can destroy a company's entire
    // history in one request, and an id in a JSON body is far too easy to get
    // wrong — the difference between account 13 and 16 is one keystroke, and
    // both are called "R&M Towing & Recovery".
    if (trim((string)($in['confirm_name'] ?? '')) !== $impact['account']['name']) {
        errorResponse(t('err.confirm_name_mismatch', ['name' => $impact['account']['name']]), 422);
    }

    if (!$impact['can_proceed']) errorResponse(implode(' ', $impact['blockers']), 409);

    $mode   = ($in['mode'] ?? '') === 'deleted' ? 'deleted' : 'anonymized';
    $reason = !empty($in['reason']) ? (string)$in['reason'] : null;

    // Erasing the money rows is possible but never the default, and it takes a
    // second, separate acknowledgement. `has_financial_history` is true when
    // there are completed jobs, ledger entries or payouts behind this account —
    // deleting those does not tidy the books, it shrinks them.
    if ($mode === 'deleted' && $impact['has_financial_history'] && empty($in['understand_records_lost'])) {
        errorResponse(t('err.financial_history_ack', [
            'jobs'   => $impact['counts']['completed_jobs'],
            'ledger' => $impact['counts']['ledger_entries'],
        ]), 422);
    }

    $res = $mode === 'deleted'
        ? deleteAccountCompletely($id, (int)$admin['id'], $reason)
        : anonymizeAccount($id, 'admin', (int)$admin['id'], $reason);

    if (empty($res['ok'])) errorResponse($res['error'] ?? t('err.delete_failed'), 500);

    adminLog((int)$admin['id'], 'account_' . $mode,
             "#$id {$impact['account']['name']}" . ($reason ? " — $reason" : ''));

    successResponse($res, $mode === 'deleted' ? t('ok.account_deleted') : t('ok.account_anonymized'));
}

// ═══ DISABLE / RE-ENABLE ═════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'account-disable') {
    $admin = requireAdmin();
    $in = jsonInput();
    $id       = (int)($in['id'] ?? 0);
    $disabled = !empty($in['disabled']);

    $res = setAccountDisabled($id, $disabled, $in['reason'] ?? null, (int)$admin['id']);
    if (empty($res['ok'])) errorResponse($res['error'], 422);

    adminLog((int)$admin['id'], $disabled ? 'account_disable' : 'account_enable',
             "#$id" . ($disabled ? ' — ' . trim((string)$in['reason']) : ''));

    successResponse($res, $disabled ? t('ok.account_disabled') : t('ok.account_enabled'));
}

// ═══ WHAT HAS BEEN REMOVED ═══════════════════════════════════════════════════
if ($method === 'GET' && $action === 'deletions') {
    requireAdmin();
    $rows = getDB()->query(
        "SELECT id, account_id, account_type, account_name, account_email, mode,
                requested_by, reason, removed_counts, created_at
           FROM account_deletions ORDER BY id DESC LIMIT 200"
    )->fetchAll();
    foreach ($rows as &$r) $r['removed_counts'] = json_decode((string)$r['removed_counts'], true);
    unset($r);
    successResponse(['deletions' => $rows]);
}

// ═══ SUPPORT ═════════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'tickets') {
    requireAdmin();
    $status = $_GET['status'] ?? '';
    $sql = "SELECT t.*, a.name AS account_name
              FROM support_tickets t
         LEFT JOIN accounts a ON a.id = t.account_id";
    $params = [];
    if (in_array($status, ['open','answered','closed'], true)) {
        $sql .= " WHERE t.status = :s";
        $params[':s'] = $status;
    }
    $sql .= " ORDER BY t.id DESC LIMIT 200";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);

    $counts = getDB()->query(
        "SELECT status, COUNT(*) n FROM support_tickets GROUP BY status"
    )->fetchAll();

    successResponse(['tickets' => $stmt->fetchAll(), 'counts' => $counts]);
}

if ($method === 'POST' && $action === 'ticket-reply') {
    $admin = requireAdmin();
    $in = jsonInput();
    $res = replyToTicket((int)($in['id'] ?? 0), (string)($in['reply'] ?? ''), (int)$admin['id']);
    if (empty($res['ok'])) errorResponse($res['error'], 422);

    adminLog((int)$admin['id'], 'ticket_reply', '#' . (int)$in['id']);
    // Whether the email actually left is reported, not assumed — a reply that
    // silently failed to send looks identical to one that worked.
    successResponse($res, $res['emailed'] ? t('ok.reply_sent') : t('ok.reply_saved_not_sent'));
}

if ($method === 'POST' && $action === 'ticket-status') {
    $admin = requireAdmin();
    $in = jsonInput();
    $st = in_array($in['status'] ?? '', ['open','answered','closed'], true) ? $in['status'] : 'closed';
    getDB()->prepare("UPDATE support_tickets SET status = :s WHERE id = :id")
           ->execute([':s' => $st, ':id' => (int)($in['id'] ?? 0)]);
    successResponse(['status' => $st], t('ok.saved'));
}

// ═══ MONEY ═══════════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'finance') {
    requireAdmin();
    $fin = platformFinance();

    $pdo = getDB();
    // Recent movement, both directions, in one list.
    $recent = $pdo->query(
        "SELECT 'payout' AS kind, w.id, w.amount, w.status, w.requested_at AS at,
                a.name AS who
           FROM withdrawals w JOIN accounts a ON a.id = w.account_id
          ORDER BY w.id DESC LIMIT 25"
    )->fetchAll();

    $byTower = $pdo->query(
        "SELECT a.id, a.name,
                COALESCE(SUM(CASE WHEN p.status='pending' AND p.withdrawal_id IS NULL
                                  THEN p.net_amount END),0) AS owed,
                COALESCE(SUM(CASE WHEN p.status='paid' THEN p.net_amount END),0) AS paid,
                COALESCE(SUM(p.platform_fee),0) AS fees
           FROM accounts a
           JOIN payouts p ON p.tower_account_id = a.id
          WHERE a.account_type = 'tower'
          GROUP BY a.id, a.name
          ORDER BY owed DESC, paid DESC LIMIT 100"
    )->fetchAll();

    successResponse(array_merge($fin, ['recent' => $recent, 'by_tower' => $byTower]));
}

if ($method === 'POST' && $action === 'platform-payout') {
    $admin = requireAdmin();
    $in = jsonInput();
    $amount = round((float)($in['amount'] ?? 0), 2);

    $res = platformPayout($amount, $in['note'] ?? null);
    if (empty($res['ok'])) errorResponse($res['error'], 409);

    adminLog((int)$admin['id'], 'platform_payout', '$' . number_format($amount, 2));
    successResponse($res, t('ok.payout_queued', ['amount' => number_format($amount, 2)]));
}

// ═══ WHO IS ON THE SITE RIGHT NOW ════════════════════════════════════════════
if ($method === 'GET' && $action === 'live') {
    requireAdmin();
    $pdo = getDB();

    // The window has to be longer than the client heartbeat, or everyone
    // flickers between online and offline between beats.
    $window = max(20, (int)setting('presence_window_seconds', 75));

    $stmt = $pdo->prepare(
        "SELECT session_key, account_id, kind, page, label, ip, user_agent, referrer,
                first_seen, last_seen,
                TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_ago,
                TIMESTAMPDIFF(SECOND, first_seen, NOW()) AS on_site_seconds
           FROM presence
          WHERE last_seen > DATE_SUB(NOW(), INTERVAL :w SECOND)
          ORDER BY last_seen DESC LIMIT 300"
    );
    $stmt->execute([':w' => $window]);
    $rows = $stmt->fetchAll();

    $counts = ['customer' => 0, 'tower' => 0, 'admin' => 0, 'anon' => 0];
    $byPage = [];
    foreach ($rows as $r) {
        $counts[$r['kind']] = ($counts[$r['kind']] ?? 0) + 1;
        $p = $r['page'] ?: '(unknown)';
        $byPage[$p] = ($byPage[$p] ?? 0) + 1;
    }
    arsort($byPage);

    // Context for the live number: without it, "3 people" means nothing.
    $seen = function (string $interval) use ($pdo) {
        $q = $pdo->query("SELECT COUNT(DISTINCT session_key) n FROM presence
                           WHERE last_seen > DATE_SUB(NOW(), INTERVAL $interval)");
        return (int)($q->fetch()['n'] ?? 0);
    };

    successResponse([
        'window_seconds' => $window,
        'online'         => count($rows),
        'counts'         => $counts,
        'by_page'        => $byPage,
        'visitors'       => $rows,
        'last_hour'      => $seen('1 HOUR'),
        'last_24h'       => $seen('24 HOUR'),
    ]);
}

// ═══ SETTLEMENT ISSUES ═══════════════════════════════════════════════════════
// Jobs that are finished but whose money did not land. There is no admin
// notification table — admins live in admin_users, not accounts — so this
// worklist IS the alert, and it is derived from state rather than from a
// message that could have been missed.
//
// Two shapes end up here:
//   payment_status = 'failed'  the customer's card was declined at capture
//   escrow still 'held'        the job closed but settlement never ran to the
//                              end (a process that died between the two)
// Both are fixed the same way: retry.
if ($action === 'settlement-issues') {
    requireAdmin(['superadmin', 'finance']);

    $rows = getDB()->query(
        "SELECT c.id, c.call_number, c.status, c.payment_status, c.source,
                c.offer_amount, c.awarded_amount, c.goa_amount, c.completed_at,
                c.stripe_payment_intent_id,
                a.name AS tower_name, e.status AS escrow_status
           FROM calls c
           LEFT JOIN accounts a     ON c.awarded_tower_account_id = a.id
           LEFT JOIN escrow_holds e ON e.call_id = c.id
          WHERE c.status IN ('completed', 'goa')
            AND (c.payment_status = 'failed' OR e.status = 'held')
          ORDER BY c.completed_at DESC
          LIMIT 200"
    )->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'call_id'        => (int)$r['id'],
            'call_number'    => $r['call_number'],
            'status'         => $r['status'],
            'payment_status' => $r['payment_status'],
            'escrow_status'  => $r['escrow_status'],
            'source'         => $r['source'],
            'tower'          => $r['tower_name'],
            'completed_at'   => $r['completed_at'],
            'has_intent'     => !empty($r['stripe_payment_intent_id']),
            // What a retry would try to take.
            'amount_due'     => (float)($r['status'] === 'goa'
                                        ? $r['goa_amount']
                                        : ($r['awarded_amount'] ?? $r['offer_amount'])),
        ];
    }
    successResponse(['issues' => $out, 'count' => count($out)]);
}

// ═══ RETRY A SETTLEMENT ══════════════════════════════════════════════════════
// Idempotent by construction: Stripe replays a capture that already went
// through, and settleCall() refuses to release an escrow hold twice.
if ($method === 'POST' && $action === 'retry-settlement') {
    $admin = requireAdmin(['superadmin', 'finance']);
    $in = jsonInput();
    $callId = (int)($in['call_id'] ?? 0);
    if (!$callId) errorResponse('call_id is required');

    $stmt = getDB()->prepare("SELECT * FROM calls WHERE id = :id");
    $stmt->execute([':id' => $callId]);
    $call = $stmt->fetch();
    if (!$call) errorResponse(t('err.job_not_found'), 404);

    // Only a job that is actually finished. Retrying settlement on a live job
    // would charge a customer for work still in progress.
    if (!in_array($call['status'], ['completed', 'goa'], true)) {
        errorResponse('That job is not finished, so there is nothing to settle', 409);
    }

    $mode   = $call['status'] === 'goa' ? 'goa' : 'complete';
    $amount = (float)($mode === 'goa'
                ? $call['goa_amount']
                : ($call['awarded_amount'] ?? $call['offer_amount']));

    $settle = settleCall($callId, $mode, $amount);

    adminLog((int)$admin['id'], 'retry_settlement',
        "call $callId — " . ($settle['ok']
            ? 'settled $' . money($settle['gross'] ?? 0)
            : 'failed at ' . ($settle['stage'] ?? '?') . ': ' . ($settle['error'] ?? '')));

    if (!$settle['ok']) errorResponse($settle['error'] ?? 'Settlement failed', 402);

    getDB()->prepare("UPDATE calls SET platform_fee = :fee, tower_net = :net WHERE id = :id")
        ->execute([':fee' => money($settle['fee']), ':net' => money($settle['net']), ':id' => $callId]);

    successResponse([
        'settled'    => (bool)$settle['settled'],
        'gross'      => money($settle['gross']),
        'net_to_tower' => money($settle['net']),
        'payout_id'  => $settle['payout_id'],
    ], 'Settled');
}

errorResponse('Unknown action', 404);
