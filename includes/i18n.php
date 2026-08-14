<?php
// ═══════════════════════════════════════════════════════════════════════════
//  SERVER-SIDE TRANSLATIONS
//
//  Miami-Dade is majority Spanish-speaking, so Spanish is the default and
//  English is the opt-in — not the other way round.
//
//  API messages are translated too. A Spanish interface that throws English
//  errors is worse than an English one: it fails exactly at the moment the
//  user is confused, stranded or about to give up on paying.
//
//  Language comes from ?lang= or the X-Lang header, defaulting to Spanish.
// ═══════════════════════════════════════════════════════════════════════════

function currentLang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;

    $raw = $_GET['lang'] ?? $_SERVER['HTTP_X_LANG'] ?? '';
    $raw = strtolower(substr((string)$raw, 0, 2));
    $lang = $raw === 'en' ? 'en' : 'es';   // Spanish unless English is asked for
    return $lang;
}

/**
 * Translate by key. Unknown keys return the key itself rather than an empty
 * string, so a missing translation is visible in testing instead of silently
 * blanking a message.
 */
function t(string $key, array $params = []): string {
    static $strings = null;
    if ($strings === null) $strings = i18nCatalogue();

    $lang = currentLang();
    $text = $strings[$lang][$key] ?? $strings['es'][$key] ?? $key;

    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

function i18nCatalogue(): array {
    return [
    // ─── SPANISH (default) ───────────────────────────────────────────────
    'es' => [
        // auth
        'err.field_required'      => 'Falta {field}.',
        'err.invalid_email'       => 'Ese correo no parece válido.',
        'err.password_short'      => 'La contraseña debe tener al menos 8 caracteres.',
        'err.email_exists'        => 'Ya existe una cuenta con este correo.',
        'err.bad_login'           => 'Correo o contraseña incorrectos.',
        'err.account_disabled'    => 'Esta cuenta está desactivada.',
        'err.auth_required'       => 'Debe iniciar sesión.',
        'err.token_invalid'       => 'Su sesión venció. Inicie sesión otra vez.',
        'err.no_permission'       => 'No tiene permiso para hacer esto.',
        'err.not_verified'        => 'Su cuenta todavía se está verificando. Puede ver los trabajos, pero aún no aceptarlos.',
        'err.providers_closed'    => 'No estamos aceptando cuentas de despachadores ni clubes de asistencia. Si tiene grúas, regístrese como compañía de grúas.',
        'err.posting_closed'      => 'La publicación de trabajos por despachadores está desactivada. Todos los trabajos vienen directamente de los clientes.',
        'err.bidding_closed'      => 'Los trabajos tienen precio fijo — acepte el trabajo en vez de ofertar.',
        'ok.account_created'      => 'Cuenta creada',
        'ok.logged_in'            => 'Sesión iniciada',

        // launch area
        'err.outside_area'        => 'Por ahora solo operamos en {area}. Pronto llegaremos a más zonas.',

        // jobs
        'err.job_not_found'       => 'No encontramos ese trabajo.',
        'err.job_taken'           => 'Otra grúa ya tomó este trabajo.',
        'err.job_expired'         => 'Este trabajo ya venció.',
        'err.job_closed'          => 'Este trabajo ya está cerrado.',
        'err.job_done'            => 'Este trabajo ya fue completado.',
        'err.eta_required'        => 'Indique en cuántos minutos llega — el cliente necesita saberlo.',
        'err.no_insurance'        => 'No tiene un certificado de seguro de responsabilidad aprobado.',
        'err.insurance_expired'   => 'Su seguro de responsabilidad venció el {date}. Suba un certificado vigente para seguir aceptando trabajos.',
        'err.goa_photo'           => 'Suba una foto desde el lugar antes de reportar que el vehículo no estaba — esa foto es lo que lo protege si el cliente reclama.',
        'err.goa_no_amount'       => 'Este trabajo no tiene tarifa por vehículo ausente. Abra una disputa.',
        'ok.job_accepted'         => 'Trabajo aceptado — datos del cliente desbloqueados',
        'ok.status_updated'       => 'Estado actualizado',
        'ok.job_completed'        => 'Trabajo completado — pago en camino',
        'ok.goa_recorded'         => 'Vehículo ausente registrado',
        'ok.job_canceled'         => 'Trabajo cancelado',

        // consumer
        'err.need_location'       => 'Necesitamos su ubicación para calcular el precio.',
        'err.no_pricing'          => 'Todavía no tenemos precio para ese servicio. Por favor llámenos.',
        'err.bad_tracking'        => 'Ese enlace de seguimiento no es válido.',
        'err.request_failed'      => 'No pudimos crear su solicitud: {detail}',
        'ok.finding_truck'        => 'Buscando una grúa para usted',
        'note.not_charged_yet'    => 'Su tarjeta no se cobra ahora. Solo se cobra cuando el trabajo esté hecho.',

        // consumer status
        'status.open'             => 'Buscando una grúa',
        'status.awarded'          => 'Grúa asignada',
        'status.en_route'         => 'La grúa va en camino',
        'status.on_scene'         => 'La grúa llegó',
        'status.in_progress'      => 'Trabajando en su vehículo',
        'status.completed'        => 'Servicio completado',
        'status.goa'              => 'La grúa llegó y el vehículo no estaba',
        'status.canceled'         => 'Cancelado',
        'status.expired'          => 'No hubo grúa disponible — no se le cobró',

        // cancel outcomes
        'msg.canceled_free'       => 'Cancelado. No se le ha cobrado nada.',
        'msg.canceled_fee'        => 'Cancelado. Se aplica una tarifa de salida de ${amount} porque la grúa ya iba en camino.',

        // pricing breakdown — the customer reads these to judge the price
        'price.base'        => 'Tarifa base del servicio',
        'price.miles'       => '{n} millas después de las primeras {inc}',
        'price.accident'    => 'Recuperación de accidente',
        'price.no_keys'     => 'Sin llaves disponibles',
        'price.wheels'      => 'Las llantas no ruedan',
        'price.underground' => 'Subterráneo / techo bajo',
        'price.after_hours' => 'Tarifa nocturna ({x}x)',
        'price.weekend'     => 'Tarifa de fin de semana ({x}x)',
        'price.minimum'     => 'Ajuste por cargo mínimo',

        // notifications
        'notif.job_taken_title'   => 'Trabajo {number} aceptado',
        'notif.job_taken_body'    => '{tower} va en camino. Llega en unos {eta} minutos.',
        'notif.job_done_title'    => '{number} completado',
        'notif.payout_title'      => 'Pago enviado — ${amount}',
        'notif.payout_body'       => 'Su pago va en camino a su banco.',
        'notif.expired_title'     => '{number} venció',
        'notif.expired_body'      => 'Ninguna grúa aceptó este trabajo.',
    ],

    // ─── ENGLISH ─────────────────────────────────────────────────────────
    'en' => [
        'err.field_required'      => '{field} is required.',
        'err.invalid_email'       => 'That email does not look valid.',
        'err.password_short'      => 'Password must be at least 8 characters.',
        'err.email_exists'        => 'An account with this email already exists.',
        'err.bad_login'           => 'Invalid email or password.',
        'err.account_disabled'    => 'This account is deactivated.',
        'err.auth_required'       => 'You need to sign in.',
        'err.token_invalid'       => 'Your session expired. Please sign in again.',
        'err.no_permission'       => 'You do not have permission to do that.',
        'err.not_verified'        => 'Your account is still being verified. You can browse jobs, but not accept them yet.',
        'err.providers_closed'    => 'We are not taking dispatcher or motor club accounts. If you run tow trucks, sign up as a towing company.',
        'err.posting_closed'      => 'Job posting by dispatchers is disabled. All jobs now come directly from customers.',
        'err.bidding_closed'      => 'Jobs are offered at a fixed price — accept the job instead of bidding.',
        'ok.account_created'      => 'Account created',
        'ok.logged_in'            => 'Logged in',

        'err.outside_area'        => 'We have only launched in {area} so far. Everywhere else is coming soon.',

        'err.job_not_found'       => 'We could not find that job.',
        'err.job_taken'           => 'Another operator has already taken this job.',
        'err.job_expired'         => 'This job has expired.',
        'err.job_closed'          => 'This job is already closed.',
        'err.job_done'            => 'This job is already completed.',
        'err.eta_required'        => 'Tell us your ETA in minutes — the customer needs to know.',
        'err.no_insurance'        => 'No approved liability insurance certificate on file.',
        'err.insurance_expired'   => 'Your liability insurance expired on {date}. Upload a current certificate to keep accepting jobs.',
        'err.goa_photo'           => 'Upload a photo from the scene before reporting the vehicle was gone — that photo is what protects you if the customer disputes it.',
        'err.goa_no_amount'       => 'This job has no gone-on-arrival fee set. Open a dispute instead.',
        'ok.job_accepted'         => 'Job accepted — customer details unlocked',
        'ok.status_updated'       => 'Status updated',
        'ok.job_completed'        => 'Job completed — payout queued',
        'ok.goa_recorded'         => 'Gone on arrival recorded',
        'ok.job_canceled'         => 'Job canceled',

        'err.need_location'       => 'We need your location to price the job.',
        'err.no_pricing'          => 'We do not price that service yet. Please call us.',
        'err.bad_tracking'        => 'That tracking link is not valid.',
        'err.request_failed'      => 'Could not create your request: {detail}',
        'ok.finding_truck'        => 'Finding you a truck',
        'note.not_charged_yet'    => 'Your card is not charged now. You only pay once the job is done.',

        'status.open'             => 'Finding you a truck',
        'status.awarded'          => 'Driver assigned',
        'status.en_route'         => 'Driver on the way',
        'status.on_scene'         => 'Driver has arrived',
        'status.in_progress'      => 'Working on your vehicle',
        'status.completed'        => 'Job complete',
        'status.goa'              => 'Driver arrived, vehicle not found',
        'status.canceled'         => 'Canceled',
        'status.expired'          => 'No truck available — you were not charged',

        'msg.canceled_free'       => 'Canceled. You have not been charged.',
        'msg.canceled_fee'        => 'Canceled. A ${amount} call-out fee applies because a driver was already on the way.',

        'price.base'        => 'Base service fee',
        'price.miles'       => '{n} mi beyond the first {inc}',
        'price.accident'    => 'Accident recovery',
        'price.no_keys'     => 'No keys available',
        'price.wheels'      => 'Wheels will not roll',
        'price.underground' => 'Underground / low clearance',
        'price.after_hours' => 'After-hours rate ({x}x)',
        'price.weekend'     => 'Weekend rate ({x}x)',
        'price.minimum'     => 'Minimum charge adjustment',

        'notif.job_taken_title'   => 'Job {number} accepted',
        'notif.job_taken_body'    => '{tower} is on the way. ETA about {eta} minutes.',
        'notif.job_done_title'    => '{number} completed',
        'notif.payout_title'      => 'Payment sent — ${amount}',
        'notif.payout_body'       => 'Your payout is on its way to your bank.',
        'notif.expired_title'     => '{number} expired',
        'notif.expired_body'      => 'No operator accepted this job.',
    ],
    ];
}
