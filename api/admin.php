<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/adminauth.php';
require_once __DIR__ . '/../includes/zones.php';
require_once __DIR__ . '/../includes/surge.php';
require_once __DIR__ . '/../includes/pricing.php';
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

    successResponse(['verification_status' => $decision], t('ok.account_reviewed'));
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
];

if ($method === 'GET' && $action === 'settings') {
    requireAdmin();
    $rows = getDB()->query("SELECT setting_key, setting_value, description FROM platform_settings")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = $r + ['editable' => in_array($r['setting_key'], EDITABLE_SETTINGS, true)];
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

errorResponse('Unknown action', 404);
