<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/i18n.php';

// ─── CORS ────────────────────────────────────────────────────────────────────
function setCorsHeaders(): void {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ─── RESPONSES ───────────────────────────────────────────────────────────────
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function errorResponse(string $message, int $code = 400): void {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

function successResponse(array $data = [], string $message = 'OK'): void {
    jsonResponse(array_merge(['success' => true, 'message' => $message], $data));
}

function jsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ($_POST ?: []);
}

// ─── JWT ─────────────────────────────────────────────────────────────────────
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function generateJWT(array $payload): string {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRY;
    $body = base64url_encode(json_encode($payload));
    $sig = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    return "$header.$body.$sig";
}

function verifyJWT(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $body, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(base64url_decode($body), true);
    if (!$data || ($data['exp'] ?? 0) < time()) return null;
    return $data;
}

/**
 * Mirror the session token into a cookie alongside the JSON response.
 *
 * The app itself authenticates from localStorage and always will. This exists
 * for the service worker, which cannot read localStorage but does send cookies:
 * when a push service rotates a subscription out from under a phone, the worker
 * has to re-register itself with no page open and no token to hand. Without
 * this the device goes silent permanently while looking perfectly healthy.
 *
 * SameSite=Lax, deliberately. Lax still travels on the top-level navigation a
 * notification tap produces, but is not sent on a cross-site POST — which
 * matters because jsonInput() falls back to $_POST, so a form-encoded request
 * from another origin would otherwise be a working CSRF against every
 * state-changing endpoint the moment this cookie started existing.
 */
function issueSessionCookie(string $token): void {
    $secure = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
           || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    setcookie('tl_token', $token, [
        'expires'  => time() + JWT_EXPIRY,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearSessionCookie(): void {
    setcookie('tl_token', '', [
        'expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

// ─── AUTH ────────────────────────────────────────────────────────────────────
function bearerToken(): ?string {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
    if (preg_match('/^Bearer\s+(.+)$/i', (string)$authHeader, $m)) return $m[1];
    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) return $_SERVER['HTTP_X_AUTH_TOKEN'];
    if (!empty($_COOKIE['tl_token'])) return $_COOKIE['tl_token'];
    return null;
}

function requireAuth(): array {
    $token = bearerToken();
    if (!$token) errorResponse(t('err.auth_required'), 401);

    $claims = verifyJWT($token);
    if (!$claims) errorResponse(t('err.token_invalid'), 401);

    $stmt = getDB()->prepare(
        "SELECT u.*, a.account_type, a.name AS account_name, a.slug AS account_slug,
                a.verification_status, a.is_active AS account_active,
                a.rating_avg, a.rating_count, a.jobs_completed
           FROM users u
           JOIN accounts a ON u.account_id = a.id
          WHERE u.id = :id AND u.is_active = 1"
    );
    $stmt->execute([':id' => $claims['user_id'] ?? 0]);
    $user = $stmt->fetch();

    if (!$user) errorResponse(t('err.account_disabled'), 401);
    if (!$user['account_active']) errorResponse(t('err.account_disabled'), 403);
    if ($user['verification_status'] === 'suspended') {
        errorResponse('Account suspended. Contact support.', 403);
    }
    unset($user['password_hash']);
    return $user;
}

function requireAccountType(array $user, string $type): void {
    if ($user['account_type'] !== $type) {
        errorResponse('This endpoint is for ' . $type . ' accounts only', 403);
    }
}

function requireRole(array $user, array $roles): void {
    if (!in_array($user['role'], $roles, true)) {
        errorResponse(t('err.no_permission'), 403);
    }
}

// A tower can browse the board unverified, but cannot take money-bearing
// actions until they're approved and their insurance is current.
function requireVerified(array $user): void {
    if ($user['verification_status'] !== 'approved') {
        errorResponse(t('err.not_verified'), 403);
    }
}

// ─── PLATFORM SETTINGS ───────────────────────────────────────────────────────
function setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (getDB()->query("SELECT setting_key, setting_value FROM platform_settings") as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

// ─── GEO ─────────────────────────────────────────────────────────────────────
function haversineMiles(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Bounding box for the cheap index-using prefilter before the exact haversine.
function boundingBox(float $lat, float $lng, float $miles): array {
    $latDelta = $miles / 69.0;
    $cos = cos(deg2rad($lat));
    $lngDelta = abs($cos) < 0.000001 ? 180.0 : $miles / (69.0 * $cos);
    return [
        'min_lat' => $lat - $latDelta, 'max_lat' => $lat + $latDelta,
        'min_lng' => $lng - $lngDelta, 'max_lng' => $lng + $lngDelta,
    ];
}

// Launch market gate. Liquidity is everything on a loadboard — a handful of
// calls spread across the country looks dead to everyone. Returns null when the
// point is inside the fence (or the fence is off), otherwise the refusal text.
function outsideLaunchArea(?float $lat, ?float $lng): ?string {
    $radius = (float)setting('launch_radius_miles', 0);
    if ($radius <= 0) return null;                 // 0 = open everywhere

    $clat = (float)setting('launch_center_lat', 0);
    $clng = (float)setting('launch_center_lng', 0);
    if (!$clat || !$clng) return null;

    // No coordinates yet (address not geocoded) — don't block on a guess.
    if ($lat === null || $lng === null || (!$lat && !$lng)) return null;

    if (haversineMiles($clat, $clng, $lat, $lng) <= $radius) return null;

    // The market name is itself translated — "en Miami-Dade County" reads like
    // a machine wrote it, and this is the first sentence some customers see.
    $area = currentLang() === 'es'
        ? setting('launch_area_name_es', setting('launch_area_name', 'Miami-Dade'))
        : setting('launch_area_name', 'Miami-Dade County');
    return t('err.outside_area', ['area' => $area]);
}

// ─── MONEY ───────────────────────────────────────────────────────────────────
function money($v): string {
    return number_format((float)$v, 2, '.', '');
}

// Our cut. Percentage of the awarded amount with a floor so a $40 lockout
// still covers the Stripe transfer.
function platformFee(float $awarded): float {
    $pct = (float)setting('platform_fee_percent', 10.0);
    $min = (float)setting('platform_fee_minimum', 5.00);
    $fee = round($awarded * $pct / 100, 2);
    if ($fee < $min) $fee = $min;
    if ($fee > $awarded) $fee = $awarded;   // never take more than the job
    return round($fee, 2);
}

function estimateTopupFee(float $amount, string $method): float {
    if ($method === 'ach') {
        return min(round($amount * ACH_FEE_PERCENT / 100, 2), ACH_FEE_CAP);
    }
    return round($amount * CARD_FEE_PERCENT / 100 + CARD_FEE_FIXED, 2);
}

function getBalance(int $accountId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM provider_balances WHERE account_id = :a");
    $stmt->execute([':a' => $accountId]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare("INSERT INTO provider_balances (account_id) VALUES (:a)")
            ->execute([':a' => $accountId]);
        return ['account_id' => $accountId, 'available' => '0.00', 'held' => '0.00',
                'lifetime_funded' => '0.00', 'lifetime_spent' => '0.00'];
    }
    return $row;
}

// Append-only. Never edit a ledger row; write a reversing entry instead.
function ledgerWrite(int $accountId, string $type, float $amount, string $description,
                     ?int $callId = null, ?string $stripeRef = null, ?int $userId = null): void {
    $pdo = getDB();
    $bal = getBalance($accountId);
    $pdo->prepare(
        "INSERT INTO ledger_entries
            (account_id, call_id, entry_type, amount, balance_after, description, stripe_ref, created_by_user_id)
         VALUES (:a, :c, :t, :amt, :bal, :d, :ref, :u)"
    )->execute([
        ':a' => $accountId, ':c' => $callId, ':t' => $type,
        ':amt' => money($amount), ':bal' => money($bal['available']),
        ':d' => $description, ':ref' => $stripeRef, ':u' => $userId,
    ]);
}

// ─── NOTIFICATIONS ───────────────────────────────────────────────────────────
function notify(int $accountId, string $type, string $title, string $body,
                ?int $callId = null, ?int $userId = null): void {
    getDB()->prepare(
        "INSERT INTO notifications (account_id, user_id, call_id, type, title, body)
         VALUES (:a, :u, :c, :t, :ti, :b)"
    )->execute([
        ':a' => $accountId, ':u' => $userId, ':c' => $callId,
        ':t' => $type, ':ti' => $title, ':b' => $body,
    ]);
}

// ─── CALL EVENTS ─────────────────────────────────────────────────────────────
function logCallEvent(int $callId, string $eventType, ?string $detail = null,
                      ?int $accountId = null, ?int $userId = null,
                      ?float $lat = null, ?float $lng = null): void {
    getDB()->prepare(
        "INSERT INTO call_events (call_id, account_id, user_id, event_type, detail, lat, lng)
         VALUES (:c, :a, :u, :e, :d, :lat, :lng)"
    )->execute([
        ':c' => $callId, ':a' => $accountId, ':u' => $userId,
        ':e' => $eventType, ':d' => $detail, ':lat' => $lat, ':lng' => $lng,
    ]);
}

// ─── MISC ────────────────────────────────────────────────────────────────────
function slugify(string $text): string {
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $s !== '' ? $s : 'account';
}

function uniqueSlug(string $base): string {
    $pdo = getDB();
    $slug = slugify($base);
    $try = $slug;
    $n = 1;
    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE slug = :s");
    while (true) {
        $stmt->execute([':s' => $try]);
        if (!$stmt->fetch()) return $try;
        $try = $slug . '-' . (++$n);
    }
}

function generateCallNumber(): string {
    // Sortable and human-readable on a dispatch screen: TL-260814-4821
    return 'TL-' . date('ymd') . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

function normalizePhone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10) return '+1' . $digits;
    if (strlen($digits) === 11 && $digits[0] === '1') return '+' . $digits;
    return '+' . $digits;
}

// Customer PII stays off the open board. Only the awarded tower sees it.
function maskPhone(?string $phone): string {
    if (!$phone) return '';
    $d = preg_replace('/\D+/', '', $phone);
    return strlen($d) >= 4 ? '(•••) •••-' . substr($d, -4) : '••••';
}

function maskName(?string $name): string {
    if (!$name) return '';
    $parts = preg_split('/\s+/', trim($name));
    $out = $parts[0];
    if (count($parts) > 1) $out .= ' ' . strtoupper(substr(end($parts), 0, 1)) . '.';
    return $out;
}
