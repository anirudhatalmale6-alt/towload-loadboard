<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/stripe.php';

// ═══════════════════════════════════════════════════════════════════════════
//  Thin Stripe wrapper. No SDK — one dependency-free cURL call, form-encoded,
//  matching how the rest of the TowMasters codebase talks to Square/Telnyx.
// ═══════════════════════════════════════════════════════════════════════════

function stripeRequest(string $method, string $path, array $params = [], array $opts = []): array {
    if (STRIPE_SECRET_KEY === '') {
        return ['ok' => false, 'error' => 'Stripe is not configured on this server'];
    }

    $url = STRIPE_API_BASE . $path;
    $headers = [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ];

    // Idempotency keys make webhook/retry storms safe on every POST.
    if (!empty($opts['idempotency_key'])) {
        $headers[] = 'Idempotency-Key: ' . $opts['idempotency_key'];
    }
    // Acting on behalf of a connected account (rarely needed with Transfers,
    // but required for reading a tower's own balance).
    if (!empty($opts['stripe_account'])) {
        $headers[] = 'Stripe-Account: ' . $opts['stripe_account'];
    }

    $body = $params ? http_build_query($params) : '';
    if ($method === 'GET' && $body !== '') {
        $url .= '?' . $body;
        $body = '';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $response = curl_exec($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'Stripe connection failed: ' . $curlErr];
    }

    $data = json_decode($response, true) ?: [];
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'data' => $data];
    }
    return [
        'ok'    => false,
        'error' => $data['error']['message'] ?? ('Stripe error ' . $status),
        'code'  => $data['error']['code'] ?? null,
        'data'  => $data,
    ];
}

// ─── CONNECT ONBOARDING (towers) ─────────────────────────────────────────────

/**
 * Create the tower's Express account. Stripe then collects their EIN, bank
 * details and identity docs on their own hosted flow — we never see any of it.
 */
function stripeCreateConnectAccount(array $account): array {
    $params = [
        'type'                          => STRIPE_CONNECT_TYPE,
        'country'                       => 'US',
        'email'                         => $account['email'],
        'business_type'                 => 'company',
        'capabilities[transfers][requested]' => 'true',
        'business_profile[name]'        => $account['name'],
        'business_profile[mcc]'         => '7549',  // Towing services
        'business_profile[url]'         => $account['website'] ?: APP_URL,
        'metadata[towload_account_id]'  => (string)$account['id'],
    ];
    if (!empty($account['phone'])) {
        $params['business_profile[support_phone]'] = $account['phone'];
    }
    // The idempotency key is bucketed by time, NOT a bare account id.
    //
    // `acct_create_16` looked safe and quietly bricked one company's payouts.
    // Stripe stores the response for an idempotency key for 24 hours and
    // replays it — INCLUDING a failure. Kauffs Towing pressed the button while
    // Accounts v1 was still switched off, got a refusal, and from then on every
    // retry replayed that same refusal even after the setting was fixed. Worse,
    // these parameters are read live from the company's own row, so the moment
    // they edited their phone or website the key came back
    // "Keys for idempotent requests can only be used with the same parameters",
    // which is unrecoverable for that key. One bad minute locked that company
    // out of ever connecting a bank account.
    //
    // Five-minute buckets keep the thing this is actually for — a double-tap or
    // a network retry creating two accounts — while letting a genuine retry
    // after a fix start clean. Contrast stripeTransferToTower(), where the key
    // is permanently the payout row id and MUST be: replaying a transfer would
    // pay somebody twice.
    return stripeRequest('POST', '/accounts', $params, [
        'idempotency_key' => 'acct_create_' . $account['id'] . '_' . floor(time() / 300),
    ]);
}

/** One-time onboarding link. Short-lived by design — regenerate, don't cache. */
function stripeCreateAccountLink(string $stripeAccountId): array {
    return stripeRequest('POST', '/account_links', [
        'account'     => $stripeAccountId,
        'refresh_url' => STRIPE_CONNECT_REFRESH_URL,
        'return_url'  => STRIPE_CONNECT_RETURN_URL,
        'type'        => 'account_onboarding',
    ]);
}

/** Express dashboard link so towers can see their own payouts. */
function stripeCreateLoginLink(string $stripeAccountId): array {
    return stripeRequest('POST', '/accounts/' . $stripeAccountId . '/login_links');
}

function stripeGetAccount(string $stripeAccountId): array {
    return stripeRequest('GET', '/accounts/' . $stripeAccountId);
}

/**
 * Pull Stripe's view of the account into tower_profiles. Called after
 * onboarding returns and from the account.updated webhook.
 */
function syncConnectStatus(int $accountId, string $stripeAccountId): array {
    $res = stripeGetAccount($stripeAccountId);
    if (!$res['ok']) return $res;

    $a = $res['data'];
    $due = $a['requirements']['currently_due'] ?? [];

    getDB()->prepare(
        "UPDATE tower_profiles
            SET stripe_charges_enabled   = :ce,
                stripe_payouts_enabled   = :pe,
                stripe_details_submitted = :ds,
                stripe_requirements_due  = :req
          WHERE account_id = :a"
    )->execute([
        ':ce'  => !empty($a['charges_enabled']) ? 1 : 0,
        ':pe'  => !empty($a['payouts_enabled']) ? 1 : 0,
        ':ds'  => !empty($a['details_submitted']) ? 1 : 0,
        ':req' => $due ? implode(',', $due) : null,
        ':a'   => $accountId,
    ]);

    return ['ok' => true, 'data' => [
        'charges_enabled'   => !empty($a['charges_enabled']),
        'payouts_enabled'   => !empty($a['payouts_enabled']),
        'details_submitted' => !empty($a['details_submitted']),
        'requirements_due'  => $due,
    ]];
}

// ─── FUNDING (providers) ─────────────────────────────────────────────────────

/**
 * Top-up PaymentIntent against the PLATFORM account. Funds land in our Stripe
 * balance, which is what we later Transfer out of.
 *
 * ACH (us_bank_account) is 0.8% capped at $5 vs 2.9% + 30c on a card. On a
 * $2,000 top-up that's $5 instead of $58.30, so ACH is the default and the
 * card path exists only for new accounts who want to start immediately.
 */
function stripeCreateTopupIntent(array $account, float $amount, string $method, ?string $customerId): array {
    $params = [
        'amount'   => (int)round($amount * 100),
        'currency' => 'usd',
        'description' => APP_NAME . ' balance top-up — ' . $account['name'],
        'metadata[towload_account_id]' => (string)$account['id'],
        'metadata[kind]' => 'balance_topup',
    ];

    if ($method === 'ach') {
        $params['payment_method_types[]'] = 'us_bank_account';
    } else {
        $params['payment_method_types[]'] = 'card';
    }
    if ($customerId) $params['customer'] = $customerId;

    return stripeRequest('POST', '/payment_intents', $params);
}

function stripeCreateCustomer(array $account): array {
    return stripeRequest('POST', '/customers', [
        'name'  => $account['name'],
        'email' => $account['email'],
        'phone' => $account['phone'] ?: '',
        'metadata[towload_account_id]' => (string)$account['id'],
        // Same trap as the Connect account above, and for the same reason:
        // these fields are read live from a row the account can edit, so a
        // fixed key breaks permanently the first time they change their phone.
    ], ['idempotency_key' => 'cust_create_' . $account['id'] . '_' . floor(time() / 300)]);
}

// ─── PAYOUT (platform -> tower) ──────────────────────────────────────────────

/**
 * Send a completed job's net amount to the tower's Connect account.
 * The idempotency key is the payout row id, so a retried webhook or a
 * double-clicked release button can never pay twice.
 */
function stripeTransferToTower(int $payoutId, string $stripeAccountId, float $netAmount,
                              int $callId, ?string $sourceCharge = null,
                              ?int $withdrawalId = null, int $attempt = 1): array {
    $params = [
        'amount'      => (int)round($netAmount * 100),
        'currency'    => 'usd',
        'destination' => $stripeAccountId,
        'description' => 'Call #' . $callId . ' payout',
        // Constant for the life of this payout, whatever attempt we are on.
        // Everything varying was moved out — see the idempotency note below.
        'transfer_group' => 'payout_' . $payoutId,
        'metadata[towload_payout_id]' => (string)$payoutId,
        'metadata[towload_call_id]'   => (string)$callId,
    ];
    // The withdrawal this job went out under is NOT sent to Stripe. It used to
    // be, as metadata, so a reversal webhook could find it — but it changes on
    // every retry, and see below for what that cost. The webhook now reads
    // payouts.withdrawal_id instead, which is the same fact from the row that
    // owns it.

    // Earmark the transfer against the charge that paid for this job.
    //
    // Without it Stripe refuses with "you have insufficient available funds",
    // and that refusal is CORRECT: a card capture lands in the PENDING balance
    // and only becomes available after the settlement delay — around two
    // business days. So money we had genuinely collected from a customer could
    // not be sent on to the company that earned it, and the operator was told
    // to go and create test charges.
    //
    // source_transaction ties the payout to its own charge. Stripe accepts it
    // immediately and releases it when that specific charge settles, which is
    // the designed answer for separate charges and transfers. It also means a
    // tower can only ever be paid out of the job they actually did, rather
    // than out of whatever happens to be sitting in the platform balance.
    //
    // Omitted for board jobs: those were funded from the posting provider's
    // topped-up balance, which really is available platform money with no
    // single charge behind it.
    if ($sourceCharge) $params['source_transaction'] = $sourceCharge;

    // ─── Why the key has an attempt number in it ────────────────────────────
    //
    // It used to be plain 'payout_<id>', with a withdrawal id riding along in
    // the metadata. The withdrawal id changes every time somebody presses the
    // button, so the SECOND attempt at any payout sent the same key with
    // different parameters and Stripe refused it:
    //
    //   "Keys for idempotent requests can only be used with the same
    //    parameters they were first used with. Try using a key other than
    //    'payout_18'."
    //
    // Which made a first failure permanent. The job could never be retried,
    // the real reason for the original failure was overwritten by this one,
    // and the money sat in the balance looking withdrawable forever.
    //
    // Now the key names the attempt, and every parameter above is constant for
    // a given payout — so a key can never be reused with different parameters
    // again. A network retry inside one attempt still collapses to one
    // transfer, which is the thing an idempotency key is actually for.
    //
    // Stripe expires keys after 24h anyway, so this was never the last line of
    // defence against paying twice. That is withdrawalTransferAlreadyExists()
    // plus the stripe_transfer_id check in withdraw().
    return stripeRequest('POST', '/transfers', $params,
        ['idempotency_key' => 'payout_' . $payoutId . '_try' . max(1, $attempt)]);
}

/**
 * Has this payout already been transferred, whatever our own row says?
 *
 * The dangerous case for retrying is not "Stripe said no" — it is "Stripe said
 * yes and we never heard it". The row then reads failed while the money is
 * gone, and a retry would send it a second time. transfer_group is constant
 * per payout, so this asks Stripe directly rather than trusting our own record.
 */
function payoutAlreadyTransferred(int $payoutId): ?array {
    $res = stripeRequest('GET', '/transfers', [
        'transfer_group' => 'payout_' . $payoutId,
        'limit'          => 10,
    ]);
    if (empty($res['ok'])) return null;          // cannot tell — caller decides
    foreach (($res['data']['data'] ?? []) as $t) {
        if (!empty($t['reversed'])) continue;    // reversed = genuinely not paid
        return $t;
    }
    return [];                                   // asked, and there is none
}

// ─── WEBHOOK SIGNATURE ───────────────────────────────────────────────────────
function stripeVerifyWebhook(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool {
    if ($secret === '' || $sigHeader === '') return false;

    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't') $timestamp = $kv[1];
        if ($kv[0] === 'v1') $signatures[] = $kv[1];
    }
    if (!$timestamp || !$signatures) return false;
    if (abs(time() - (int)$timestamp) > $tolerance) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

// ─── CONSUMER CARD PAYMENTS (authorise now, capture on completion) ───────────

/**
 * Authorise the quoted amount on the customer's card without taking it.
 *
 * capture_method=manual is the whole point: the customer is charged only when
 * a truck actually completes the job. If nobody accepts, or the tower no-shows,
 * we cancel the authorisation and they were never charged — which is the only
 * defensible way to take money from someone stranded on a roadside.
 *
 * Stripe authorisations last 7 days, far longer than any tow.
 */
function stripeAuthorizeConsumerPayment(float $amount, int $callId, string $description,
                                        ?string $email = null): array {
    $params = [
        'amount'                     => (int)round($amount * 100),
        'currency'                   => 'usd',
        'capture_method'             => 'manual',
        'description'                => $description,
        'metadata[towload_call_id]'  => (string)$callId,
        'metadata[kind]'             => 'consumer_job',
        // Cards only, stated explicitly rather than letting Stripe decide.
        //
        // automatic_payment_methods offered Klarna and US bank debit alongside
        // the card. Neither belongs on this screen: this whole flow authorises
        // now and captures when the truck finishes, and a bank debit gives no
        // authorisation to capture against. Beyond the mechanics, "pay in 4"
        // for a $110 roadside tow is a promise to the towing company that the
        // money is secured, made against a method that can still fall through.
        //
        // Card-only also means no redirect off to a bank's own site and back,
        // which for someone standing on a hard shoulder with one bar of signal
        // is a journey that does not reliably complete.
        'payment_method_types[0]' => 'card',
    ];
    if ($email) $params['receipt_email'] = $email;

    return stripeRequest('POST', '/payment_intents', $params, [
        'idempotency_key' => 'consumer_call_' . $callId,
    ]);
}

/**
 * Take the money. Capturing less than was authorised is allowed and is what
 * happens on a GOA — the customer is charged the call-out fee, not the full tow.
 */
function stripeCapturePayment(string $paymentIntentId, ?float $amount = null): array {
    $params = [];
    if ($amount !== null) $params['amount_to_capture'] = (int)round($amount * 100);
    return stripeRequest('POST', '/payment_intents/' . $paymentIntentId . '/capture', $params, [
        'idempotency_key' => 'capture_' . $paymentIntentId,
    ]);
}

/** Release the authorisation. The customer is never charged. */
function stripeCancelPayment(string $paymentIntentId): array {
    return stripeRequest('POST', '/payment_intents/' . $paymentIntentId . '/cancel', [], [
        'idempotency_key' => 'cancel_' . $paymentIntentId,
    ]);
}
