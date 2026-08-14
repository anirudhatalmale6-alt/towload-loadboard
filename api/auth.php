<?php
require_once __DIR__ . '/../includes/helpers.php';
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─── REGISTER ────────────────────────────────────────────────────────────────
// One endpoint, two sides of the marketplace. account_type decides which
// profile row gets created and what the account can do.
if ($method === 'POST' && $action === 'register') {
    $in = jsonInput();

    foreach (['account_type', 'company_name', 'email', 'password', 'first_name', 'last_name'] as $f) {
        if (empty($in[$f])) errorResponse("$f is required");
    }
    if (!in_array($in['account_type'], ['provider', 'tower'], true)) {
        errorResponse('account_type must be "provider" or "tower"');
    }
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) errorResponse('Invalid email address');
    if (strlen($in['password']) < 8) errorResponse('Password must be at least 8 characters');

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e");
    $stmt->execute([':e' => $in['email']]);
    if ($stmt->fetch()) errorResponse('An account with this email already exists');

    // Geo-gate at signup while we're launching one market at a time.
    if ($msg = outsideLaunchArea(
            isset($in['lat']) ? (float)$in['lat'] : null,
            isset($in['lng']) ? (float)$in['lng'] : null)) {
        errorResponse($msg);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO accounts (account_type, name, slug, email, phone, address, city, state, zip, lat, lng, website)
             VALUES (:t, :n, :s, :e, :p, :ad, :c, :st, :z, :lat, :lng, :w)"
        )->execute([
            ':t' => $in['account_type'], ':n' => $in['company_name'],
            ':s' => uniqueSlug($in['company_name']), ':e' => $in['email'],
            ':p' => !empty($in['company_phone']) ? normalizePhone($in['company_phone']) : null,
            ':ad' => $in['address'] ?? null, ':c' => $in['city'] ?? null,
            ':st' => !empty($in['state']) ? strtoupper(substr($in['state'], 0, 2)) : null,
            ':z' => $in['zip'] ?? null,
            ':lat' => $in['lat'] ?? null, ':lng' => $in['lng'] ?? null,
            ':w' => $in['website'] ?? null,
        ]);
        $accountId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO users (account_id, email, password_hash, first_name, last_name, phone, role)
             VALUES (:a, :e, :ph, :f, :l, :p, 'owner')"
        )->execute([
            ':a' => $accountId, ':e' => $in['email'],
            ':ph' => password_hash($in['password'], PASSWORD_DEFAULT),
            ':f' => $in['first_name'], ':l' => $in['last_name'],
            ':p' => !empty($in['phone']) ? normalizePhone($in['phone']) : null,
        ]);
        $userId = (int)$pdo->lastInsertId();

        if ($in['account_type'] === 'tower') {
            $pdo->prepare(
                "INSERT INTO tower_profiles (account_id, base_lat, base_lng, service_radius_miles, dot_number, mc_number)
                 VALUES (:a, :lat, :lng, :r, :dot, :mc)"
            )->execute([
                ':a' => $accountId,
                ':lat' => $in['lat'] ?? null, ':lng' => $in['lng'] ?? null,
                ':r' => min((int)($in['service_radius_miles'] ?? 25), MAX_SEARCH_RADIUS_MILES),
                ':dot' => $in['dot_number'] ?? null, ':mc' => $in['mc_number'] ?? null,
            ]);
            // Trial subscription so they can work the board while we verify docs.
            $pdo->prepare(
                "INSERT INTO subscriptions (account_id, plan, status, trial_ends_at)
                 VALUES (:a, 'trial', 'trialing', DATE_ADD(NOW(), INTERVAL 14 DAY))"
            )->execute([':a' => $accountId]);
        } else {
            $pdo->prepare("INSERT INTO provider_profiles (account_id) VALUES (:a)")
                ->execute([':a' => $accountId]);
            $pdo->prepare("INSERT INTO provider_balances (account_id) VALUES (:a)")
                ->execute([':a' => $accountId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        errorResponse('Registration failed: ' . $e->getMessage(), 500);
    }

    successResponse([
        'token' => generateJWT(['user_id' => $userId, 'account_id' => $accountId, 'account_type' => $in['account_type']]),
        'user' => [
            'id' => $userId, 'email' => $in['email'],
            'first_name' => $in['first_name'], 'last_name' => $in['last_name'],
            'role' => 'owner',
        ],
        'account' => [
            'id' => $accountId, 'name' => $in['company_name'],
            'account_type' => $in['account_type'], 'verification_status' => 'unverified',
        ],
        'next_step' => $in['account_type'] === 'tower'
            ? 'Upload your insurance certificate and connect your bank account to start accepting calls.'
            : 'Add funds to your balance to start posting calls.',
    ], 'Account created');
}

// ─── LOGIN ───────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'login') {
    $in = jsonInput();
    if (empty($in['email']) || empty($in['password'])) {
        errorResponse('Email and password are required');
    }

    $stmt = getDB()->prepare(
        "SELECT u.*, a.account_type, a.name AS account_name, a.verification_status, a.is_active AS account_active
           FROM users u JOIN accounts a ON u.account_id = a.id
          WHERE u.email = :e AND u.is_active = 1"
    );
    $stmt->execute([':e' => $in['email']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($in['password'], $user['password_hash'])) {
        errorResponse('Invalid email or password', 401);
    }
    if (!$user['account_active']) errorResponse('Account is deactivated', 403);

    getDB()->prepare("UPDATE users SET last_login_at = NOW() WHERE id = :id")
           ->execute([':id' => $user['id']]);

    successResponse([
        'token' => generateJWT([
            'user_id' => (int)$user['id'],
            'account_id' => (int)$user['account_id'],
            'account_type' => $user['account_type'],
        ]),
        'user' => [
            'id' => (int)$user['id'], 'email' => $user['email'],
            'first_name' => $user['first_name'], 'last_name' => $user['last_name'],
            'role' => $user['role'],
        ],
        'account' => [
            'id' => (int)$user['account_id'], 'name' => $user['account_name'],
            'account_type' => $user['account_type'],
            'verification_status' => $user['verification_status'],
        ],
    ], 'Logged in');
}

// ─── ME ──────────────────────────────────────────────────────────────────────
if ($method === 'GET' && ($action === 'me' || $action === '')) {
    $user = requireAuth();
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE id = :a");
    $stmt->execute([':a' => $user['account_id']]);
    $account = $stmt->fetch();

    $extra = [];
    if ($user['account_type'] === 'tower') {
        $stmt = $pdo->prepare("SELECT * FROM tower_profiles WHERE account_id = :a");
        $stmt->execute([':a' => $user['account_id']]);
        $extra['profile'] = $stmt->fetch() ?: null;

        $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE account_id = :a ORDER BY id DESC LIMIT 1");
        $stmt->execute([':a' => $user['account_id']]);
        $extra['subscription'] = $stmt->fetch() ?: null;

        // Surface expiring insurance before it blocks them, not after.
        $stmt = $pdo->prepare(
            "SELECT doc_type, expires_at, status FROM compliance_docs
              WHERE account_id = :a AND status = 'approved' AND expires_at IS NOT NULL
              ORDER BY expires_at ASC"
        );
        $stmt->execute([':a' => $user['account_id']]);
        $docs = $stmt->fetchAll();
        $extra['compliance'] = $docs;
        $extra['compliance_warnings'] = array_values(array_filter($docs, function ($d) {
            return strtotime($d['expires_at']) < strtotime('+30 days');
        }));
    } else {
        $stmt = $pdo->prepare("SELECT * FROM provider_profiles WHERE account_id = :a");
        $stmt->execute([':a' => $user['account_id']]);
        $extra['profile'] = $stmt->fetch() ?: null;
        $extra['balance'] = getBalance((int)$user['account_id']);
    }

    successResponse(array_merge([
        'user' => [
            'id' => (int)$user['id'], 'email' => $user['email'],
            'first_name' => $user['first_name'], 'last_name' => $user['last_name'],
            'phone' => $user['phone'], 'role' => $user['role'],
            'avatar_url' => $user['avatar_url'],
        ],
        'account' => $account,
    ], $extra));
}

// ─── UPDATE PROFILE ──────────────────────────────────────────────────────────
if (($method === 'PUT' || $method === 'POST') && $action === 'update-profile') {
    $user = requireAuth();
    requireRole($user, ['owner', 'dispatcher']);
    $in = jsonInput();
    $pdo = getDB();

    $accountFields = ['name','phone','address','city','state','zip','lat','lng','logo_url','website'];
    $sets = [];
    $params = [':a' => $user['account_id']];
    foreach ($accountFields as $f) {
        if (array_key_exists($f, $in)) {
            $sets[] = "$f = :$f";
            $params[":$f"] = $f === 'phone' && $in[$f] ? normalizePhone($in[$f]) : $in[$f];
        }
    }
    if ($sets) {
        $pdo->prepare("UPDATE accounts SET " . implode(', ', $sets) . " WHERE id = :a")->execute($params);
    }

    if ($user['account_type'] === 'tower') {
        $towerFields = ['dot_number','mc_number','service_radius_miles','base_lat','base_lng',
                        'has_light_duty','has_medium_duty','has_heavy_duty','has_flatbed',
                        'has_wheel_lift','has_winch_recovery','has_lockout','has_jumpstart',
                        'has_tire_change','has_fuel_delivery','has_motorcycle','has_ev_certified',
                        'has_lowclearance','is_24_7','trucks_count','accepts_auto_dispatch'];
        $sets = [];
        $params = [':a' => $user['account_id']];
        foreach ($towerFields as $f) {
            if (array_key_exists($f, $in)) {
                $v = $in[$f];
                if ($f === 'service_radius_miles') $v = min((int)$v, MAX_SEARCH_RADIUS_MILES);
                if (strpos($f, 'has_') === 0 || strpos($f, 'is_') === 0 || $f === 'accepts_auto_dispatch') {
                    $v = !empty($v) ? 1 : 0;
                }
                $sets[] = "$f = :$f";
                $params[":$f"] = $v;
            }
        }
        if ($sets) {
            $pdo->prepare("UPDATE tower_profiles SET " . implode(', ', $sets) . " WHERE account_id = :a")
                ->execute($params);
        }
    } else {
        $provFields = ['default_goa_amount','default_call_expiry_minutes','auto_award_lowest_bid'];
        $sets = [];
        $params = [':a' => $user['account_id']];
        foreach ($provFields as $f) {
            if (array_key_exists($f, $in)) {
                $sets[] = "$f = :$f";
                $params[":$f"] = $f === 'auto_award_lowest_bid' ? (!empty($in[$f]) ? 1 : 0) : $in[$f];
            }
        }
        if ($sets) {
            $pdo->prepare("UPDATE provider_profiles SET " . implode(', ', $sets) . " WHERE account_id = :a")
                ->execute($params);
        }
    }

    successResponse([], 'Profile updated');
}

// ─── CHANGE PASSWORD ─────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'change-password') {
    $user = requireAuth();
    $in = jsonInput();
    if (empty($in['current_password']) || empty($in['new_password'])) {
        errorResponse('Current and new password are required');
    }
    if (strlen($in['new_password']) < 8) errorResponse('New password must be at least 8 characters');

    $stmt = getDB()->prepare("SELECT password_hash FROM users WHERE id = :id");
    $stmt->execute([':id' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($in['current_password'], $row['password_hash'])) {
        errorResponse('Current password is incorrect', 401);
    }

    getDB()->prepare("UPDATE users SET password_hash = :p WHERE id = :id")
           ->execute([':p' => password_hash($in['new_password'], PASSWORD_DEFAULT), ':id' => $user['id']]);

    successResponse([], 'Password changed');
}

// ─── TEAM ────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'team') {
    $user = requireAuth();
    $stmt = getDB()->prepare(
        "SELECT id, email, first_name, last_name, phone, role, avatar_url, is_active, last_login_at
           FROM users WHERE account_id = :a ORDER BY role, first_name"
    );
    $stmt->execute([':a' => $user['account_id']]);
    successResponse(['team' => $stmt->fetchAll()]);
}

if ($method === 'POST' && $action === 'invite') {
    $user = requireAuth();
    requireRole($user, ['owner']);
    $in = jsonInput();

    foreach (['email', 'password', 'first_name', 'last_name'] as $f) {
        if (empty($in[$f])) errorResponse("$f is required");
    }
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) errorResponse('Invalid email address');
    if (strlen($in['password']) < 8) errorResponse('Password must be at least 8 characters');

    $role = in_array($in['role'] ?? '', ['dispatcher', 'driver'], true) ? $in['role'] : 'dispatcher';

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :e");
    $stmt->execute([':e' => $in['email']]);
    if ($stmt->fetch()) errorResponse('That email is already in use');

    $pdo->prepare(
        "INSERT INTO users (account_id, email, password_hash, first_name, last_name, phone, role)
         VALUES (:a, :e, :p, :f, :l, :ph, :r)"
    )->execute([
        ':a' => $user['account_id'], ':e' => $in['email'],
        ':p' => password_hash($in['password'], PASSWORD_DEFAULT),
        ':f' => $in['first_name'], ':l' => $in['last_name'],
        ':ph' => !empty($in['phone']) ? normalizePhone($in['phone']) : null,
        ':r' => $role,
    ]);

    successResponse(['user_id' => (int)$pdo->lastInsertId()], 'Team member added');
}

errorResponse('Unknown action', 404);
