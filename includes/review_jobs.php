<?php
/**
 * Keeps a small set of jobs standing on the board for App Store review.
 *
 * Apple signs in with one account and looks at one screen. If the board is
 * empty at that moment the app is rejected under guideline 2.1 — "we were
 * unable to review the app because the main functionality was not available" —
 * and an empty board is the normal state of a marketplace that has not launched
 * yet. So the demo account needs jobs in front of it whenever it looks, not
 * whenever somebody remembered to seed them.
 *
 * Three things make this safe to leave switched on:
 *
 *   1. It only ever writes calls belonging to ONE account, the id in
 *      `review_jobs_provider_id`. Nothing it does can touch a real job, a real
 *      company or a real customer's money.
 *   2. Those jobs sit in California, and the board is filtered by distance from
 *      each company's own yard. The real companies on the platform are in
 *      Florida, so they never see them.
 *   3. `review_jobs_enabled` turns the whole thing off in one row, and the
 *      demo jobs are identifiable by that same provider id for deletion.
 *
 * It is called from the board endpoint rather than a cron because this host has
 * no cron — the expiry sweep already takes its heartbeat from the same place.
 *
 * On expiry: a job that nobody takes is expired by the sweep, which refunds its
 * escrow to the posting account. The demo balance therefore recycles rather
 * than draining, and the top-up here just posts a fresh one.
 */

require_once __DIR__ . '/escrow.php';

/**
 * Cupertino and the towns around it, because that is where App Review is.
 *
 * Real addresses and real coordinates: a reviewer who taps through to the map
 * should see a road, not a point in the ocean. Phone numbers are 555-01xx,
 * which is the block reserved so fiction cannot dial a real person.
 *
 * The mix is deliberate — a tow, a lockout, a jumpstart, a winch-out, a tyre
 * and a fuel drop — so whichever card the reviewer opens, the app is showing
 * off a different shape of job.
 */
const REVIEW_JOB_TEMPLATES = [
    [
        'service_type' => 'tow', 'vehicle_class' => 'light',
        'pickup_address' => '10600 N Tantau Ave', 'pickup_city' => 'Cupertino',
        'pickup_zip' => '95014', 'pickup_lat' => 37.3320, 'pickup_lng' => -122.0110,
        'dropoff_address' => '1751 W San Carlos St', 'dropoff_city' => 'San Jose',
        'dropoff_lat' => 37.3210, 'dropoff_lng' => -121.9210, 'tow_miles' => 6.4,
        'vehicle_year' => '2019', 'vehicle_make' => 'Honda', 'vehicle_model' => 'Civic',
        'vehicle_color' => 'Silver', 'problem' => 'wont_start',
        'has_keys' => 1, 'wheels_lock' => 1, 'needs_flatbed' => 0,
        'customer_name' => 'Dana Whitfield', 'customer_phone' => '+14085550142',
        'offer_amount' => 138.00, 'goa_amount' => 45.00,
        'pickup_notes' => 'Ground level, west side of the visitor car park.',
    ],
    [
        'service_type' => 'lockout', 'vehicle_class' => 'light',
        'pickup_address' => '600 W California Ave', 'pickup_city' => 'Sunnyvale',
        'pickup_zip' => '94086', 'pickup_lat' => 37.3690, 'pickup_lng' => -122.0380,
        'vehicle_year' => '2022', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'RAV4',
        'vehicle_color' => 'Blue', 'problem' => null,
        'has_keys' => 0, 'wheels_lock' => 1, 'needs_flatbed' => 0,
        'customer_name' => 'Marcus Reyes', 'customer_phone' => '+14085550118',
        'offer_amount' => 79.00, 'goa_amount' => 35.00,
        'pickup_notes' => 'Keys visible on the driver seat. Customer waiting by the car.',
    ],
    [
        'service_type' => 'jumpstart', 'vehicle_class' => 'light',
        'pickup_address' => '2855 Stevens Creek Blvd', 'pickup_city' => 'Santa Clara',
        'pickup_zip' => '95050', 'pickup_lat' => 37.3250, 'pickup_lng' => -121.9470,
        'vehicle_year' => '2016', 'vehicle_make' => 'Ford', 'vehicle_model' => 'Escape',
        'vehicle_color' => 'White', 'problem' => 'wont_start',
        'has_keys' => 1, 'wheels_lock' => 1, 'needs_flatbed' => 0,
        'customer_name' => 'Priya Nathan', 'customer_phone' => '+14085550176',
        'offer_amount' => 68.00, 'goa_amount' => 30.00,
        'pickup_notes' => 'Level 2 of the parking structure, bay 214.',
    ],
    [
        'service_type' => 'tow', 'vehicle_class' => 'light',
        'pickup_address' => '3000 Hanover St', 'pickup_city' => 'Palo Alto',
        'pickup_zip' => '94304', 'pickup_lat' => 37.4150, 'pickup_lng' => -122.1440,
        'dropoff_address' => '890 E Charleston Rd', 'dropoff_city' => 'Palo Alto',
        'dropoff_lat' => 37.4180, 'dropoff_lng' => -122.1120, 'tow_miles' => 2.1,
        'vehicle_year' => '2021', 'vehicle_make' => 'Tesla', 'vehicle_model' => 'Model 3',
        'vehicle_color' => 'Black', 'problem' => 'accident', 'is_accident' => 1,
        'has_keys' => 1, 'wheels_lock' => 0, 'needs_flatbed' => 1, 'is_ev' => 1,
        'customer_name' => 'Alex Feeney', 'customer_phone' => '+16505550133',
        'offer_amount' => 196.00, 'goa_amount' => 50.00,
        'pickup_notes' => 'Front wheels will not turn. Flatbed required. EV — no tow hook.',
    ],
    [
        'service_type' => 'tire_change', 'vehicle_class' => 'light',
        'pickup_address' => '1600 Amphitheatre Pkwy', 'pickup_city' => 'Mountain View',
        'pickup_zip' => '94043', 'pickup_lat' => 37.4220, 'pickup_lng' => -122.0840,
        'vehicle_year' => '2018', 'vehicle_make' => 'Subaru', 'vehicle_model' => 'Outback',
        'vehicle_color' => 'Green', 'problem' => 'flat_tire',
        'has_keys' => 1, 'wheels_lock' => 1, 'needs_flatbed' => 0,
        'customer_name' => 'Robin Achebe', 'customer_phone' => '+16505550109',
        'offer_amount' => 92.00, 'goa_amount' => 35.00,
        'pickup_notes' => 'Rear passenger side. Spare is in the boot.',
    ],
    [
        'service_type' => 'winch_recovery', 'vehicle_class' => 'light',
        'pickup_address' => 'Mt Umunhum Rd', 'pickup_city' => 'Los Gatos',
        'pickup_zip' => '95033', 'pickup_lat' => 37.1620, 'pickup_lng' => -121.8980,
        'vehicle_year' => '2020', 'vehicle_make' => 'Jeep', 'vehicle_model' => 'Wrangler',
        'vehicle_color' => 'Red', 'problem' => 'wont_move',
        'has_keys' => 1, 'wheels_lock' => 1, 'needs_flatbed' => 0,
        'customer_name' => 'Tomas Iverson', 'customer_phone' => '+14085550187',
        'offer_amount' => 245.00, 'goa_amount' => 60.00,
        'pickup_notes' => 'Off the shoulder in soft ground, about 15 feet down.',
    ],
    [
        'service_type' => 'fuel_delivery', 'vehicle_class' => 'light',
        'pickup_address' => '1 Great Mall Dr', 'pickup_city' => 'Milpitas',
        'pickup_zip' => '95035', 'pickup_lat' => 37.4150, 'pickup_lng' => -121.8980,
        'vehicle_year' => '2015', 'vehicle_make' => 'Nissan', 'vehicle_model' => 'Altima',
        'vehicle_color' => 'Grey', 'problem' => null,
        'has_keys' => 1, 'wheels_lock' => 1, 'needs_flatbed' => 0,
        'customer_name' => 'Grace Lindqvist', 'customer_phone' => '+14085550164',
        'offer_amount' => 74.00, 'goa_amount' => 30.00,
        'pickup_notes' => 'Petrol, not diesel. Car is at the east entrance.',
    ],
    [
        'service_type' => 'tow', 'vehicle_class' => 'medium',
        'pickup_address' => '1875 S Bascom Ave', 'pickup_city' => 'Campbell',
        'pickup_zip' => '95008', 'pickup_lat' => 37.2870, 'pickup_lng' => -121.9310,
        'dropoff_address' => '2011 Senter Rd', 'dropoff_city' => 'San Jose',
        'dropoff_lat' => 37.3120, 'dropoff_lng' => -121.8570, 'tow_miles' => 8.9,
        'vehicle_year' => '2017', 'vehicle_make' => 'Ford', 'vehicle_model' => 'F-350',
        'vehicle_color' => 'White', 'problem' => 'wont_start',
        'has_keys' => 1, 'wheels_lock' => 1, 'needs_flatbed' => 0,
        'customer_name' => 'Bennett Osei', 'customer_phone' => '+14085550151',
        'offer_amount' => 289.00, 'goa_amount' => 65.00,
        'pickup_notes' => 'Dual rear wheel. Behind the tyre shop.',
    ],
];

/**
 * Post whatever is missing, up to the configured count.
 *
 * Deliberately quiet: any failure is logged and swallowed. This exists to make
 * a demo look right, and it must never be the reason a real company's board
 * fails to load.
 */
function reviewJobsTopUp(): void
{
    static $ranThisRequest = false;
    if ($ranThisRequest) return;
    $ranThisRequest = true;

    if ((string)setting('review_jobs_enabled', '0') !== '1') return;

    $providerId = (int)setting('review_jobs_provider_id', 0);
    if ($providerId <= 0) return;

    $want = max(1, min(20, (int)setting('review_jobs_count', 8)));
    $ttl  = max(15, (int)setting('review_jobs_ttl_minutes', 90));

    $pdo = getDB();

    try {
        // Same claim-a-row trick the sweep uses. The app polls the board every
        // ten seconds; without this, every poll from every device would run the
        // count and race to insert the same job.
        $pdo->prepare(
            "INSERT IGNORE INTO platform_settings (setting_key, setting_value)
             VALUES ('review_jobs_last_run_at', '2000-01-01 00:00:00')"
        )->execute();

        $claim = $pdo->prepare(
            "UPDATE platform_settings
                SET setting_value = NOW()
              WHERE setting_key = 'review_jobs_last_run_at'
                AND setting_value < DATE_SUB(NOW(), INTERVAL 60 SECOND)"
        );
        $claim->execute();
        if ($claim->rowCount() === 0) return;
    } catch (Throwable $e) {
        error_log('[reviewjobs] could not claim: ' . $e->getMessage());
        return;
    }

    try {
        $have = $pdo->prepare(
            "SELECT COUNT(*) n FROM calls
              WHERE provider_account_id = :p AND status = 'open' AND expires_at > NOW()"
        );
        $have->execute([':p' => $providerId]);
        $missing = $want - (int)$have->fetch()['n'];
        if ($missing <= 0) return;

        // Which templates are already standing, so a top-up refills the gaps
        // instead of posting eight copies of the first one.
        $live = $pdo->prepare(
            "SELECT pickup_address FROM calls
              WHERE provider_account_id = :p AND status = 'open' AND expires_at > NOW()"
        );
        $live->execute([':p' => $providerId]);
        $standing = array_column($live->fetchAll(), 'pickup_address');

        foreach (REVIEW_JOB_TEMPLATES as $tpl) {
            if ($missing <= 0) break;
            if (in_array($tpl['pickup_address'], $standing, true)) continue;
            if (reviewJobPost($pdo, $providerId, $tpl, $ttl)) $missing--;
        }
    } catch (Throwable $e) {
        error_log('[reviewjobs] top-up failed: ' . $e->getMessage());
    }
}

/** One job, funded, on the board. Returns false rather than throwing. */
function reviewJobPost(PDO $pdo, int $providerId, array $tpl, int $ttlMinutes): bool
{
    try {
        $pdo->beginTransaction();

        // The escrow below spends from the demo account's balance. Top it up
        // when it runs low so a week of review never silently stops posting
        // jobs. Ring-fenced to this one account id by the caller — no real
        // provider's balance is reachable from here.
        $bal = $pdo->prepare("SELECT available FROM provider_balances WHERE account_id = :a FOR UPDATE");
        $bal->execute([':a' => $providerId]);
        $row = $bal->fetch();
        if (!$row) {
            $pdo->prepare("INSERT INTO provider_balances (account_id, available, lifetime_funded)
                           VALUES (:a, 5000.00, 5000.00)")->execute([':a' => $providerId]);
        } elseif ((float)$row['available'] < 1000.00) {
            $pdo->prepare("UPDATE provider_balances
                              SET available = available + 5000.00,
                                  lifetime_funded = lifetime_funded + 5000.00
                            WHERE account_id = :a")->execute([':a' => $providerId]);
        }

        $pdo->prepare(
            "INSERT INTO calls
               (call_number, source, provider_account_id, tracking_token,
                service_type, vehicle_class,
                pickup_address, pickup_city, pickup_state, pickup_zip,
                pickup_lat, pickup_lng, pickup_notes,
                dropoff_address, dropoff_city, dropoff_state,
                dropoff_lat, dropoff_lng, tow_miles,
                vehicle_year, vehicle_make, vehicle_model, vehicle_color,
                has_keys, wheels_lock, is_accident, needs_flatbed, is_ev, problem,
                customer_name, customer_phone,
                pricing_mode, offer_amount, goa_amount, payment_status,
                expires_at, status)
             VALUES
               (:num, 'board', :prov, :tok,
                :stype, :vclass,
                :paddr, :pcity, 'CA', :pzip,
                :plat, :plng, :pnotes,
                :daddr, :dcity, :dstate,
                :dlat, :dlng, :miles,
                :vyear, :vmake, :vmodel, :vcolor,
                :keys, :wheels, :accident, :flatbed, :ev, :problem,
                :cname, :cphone,
                'accept', :offer, :goa, 'none',
                DATE_ADD(NOW(), INTERVAL :ttl MINUTE), 'open')"
        )->execute([
            ':num'   => generateCallNumber((float)$tpl['pickup_lat'], (float)$tpl['pickup_lng'], 'CA'),
            ':prov'  => $providerId,
            ':tok'   => bin2hex(random_bytes(16)),
            ':stype' => $tpl['service_type'],
            ':vclass'=> $tpl['vehicle_class'],
            ':paddr' => $tpl['pickup_address'],
            ':pcity' => $tpl['pickup_city'],
            ':pzip'  => $tpl['pickup_zip'],
            ':plat'  => $tpl['pickup_lat'],
            ':plng'  => $tpl['pickup_lng'],
            ':pnotes'=> $tpl['pickup_notes'] ?? null,
            ':daddr' => $tpl['dropoff_address'] ?? null,
            ':dcity' => $tpl['dropoff_city'] ?? null,
            ':dstate'=> isset($tpl['dropoff_address']) ? 'CA' : null,
            ':dlat'  => $tpl['dropoff_lat'] ?? null,
            ':dlng'  => $tpl['dropoff_lng'] ?? null,
            ':miles' => $tpl['tow_miles'] ?? null,
            ':vyear' => $tpl['vehicle_year'] ?? null,
            ':vmake' => $tpl['vehicle_make'] ?? null,
            ':vmodel'=> $tpl['vehicle_model'] ?? null,
            ':vcolor'=> $tpl['vehicle_color'] ?? null,
            ':keys'  => $tpl['has_keys'] ?? 1,
            ':wheels'=> $tpl['wheels_lock'] ?? 1,
            ':accident' => $tpl['is_accident'] ?? 0,
            ':flatbed'  => $tpl['needs_flatbed'] ?? 0,
            ':ev'    => $tpl['is_ev'] ?? 0,
            ':problem'  => $tpl['problem'] ?? null,
            ':cname' => $tpl['customer_name'] ?? null,
            ':cphone'=> $tpl['customer_phone'] ?? null,
            ':offer' => number_format((float)$tpl['offer_amount'], 2, '.', ''),
            ':goa'   => number_format((float)$tpl['goa_amount'], 2, '.', ''),
            ':ttl'   => $ttlMinutes,
        ]);

        $callId = (int)$pdo->lastInsertId();

        // Funded like any other board job. Without a hold the accept works but
        // COMPLETING the job throws "no escrow hold found", which is the exact
        // screen a reviewer would reach at the end of the happy path.
        escrowHold($callId, $providerId, null, (float)$tpl['offer_amount'], 'balance');

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[reviewjobs] could not post ' . ($tpl['pickup_address'] ?? '?') . ': ' . $e->getMessage());
        return false;
    }
}
