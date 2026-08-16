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
//  Language comes from ?lang= or the X-Lang header. With neither, it follows
//  the `default_language` setting — Spanish is the default in the Miami market
//  and English everywhere else, decided per visitor in includes/geo.php.
// ═══════════════════════════════════════════════════════════════════════════

function currentLang(): string {
    static $lang = null;
    if ($lang !== null) return $lang;

    $raw = $_GET['lang'] ?? $_SERVER['HTTP_X_LANG'] ?? '';
    $raw = strtolower(substr((string)$raw, 0, 2));
    if ($raw === 'en' || $raw === 'es') return $lang = $raw;

    // Nothing asked for. The page almost always says which language it is in,
    // so this only covers direct API callers — but it follows the platform
    // default rather than being hardcoded to Spanish, now that Spanish is the
    // Miami default and English is the default everywhere else.
    $lang = (string)setting('default_language', 'en') === 'es' ? 'es' : 'en';
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
                // ─── Rate sheet ───────────────────────────────────────────────
        'err.bad_request'         => 'Solicitud inválida.',
        'ok.rates_saved'          => 'Tarifas guardadas',
        'rate.promise'            => 'Trataremos de igualar sus tarifas. Esto nos ayuda a fijar precios justos en su zona. No se muestran a ninguna otra compañía.',
        'rate.tow_light'          => 'Grúa — auto liviano',
        'rate.tow_medium'         => 'Grúa — mediana',
        'rate.tow_heavy'          => 'Grúa — pesada',
        'rate.winch_recovery_light' => 'Winche / recuperación',
        'rate.lockout_light'      => 'Apertura de puerta',
        'rate.jumpstart_light'    => 'Paso de corriente',
        'rate.tire_change_light'  => 'Cambio de llanta',
        'rate.fuel_delivery_light'=> 'Entrega de combustible',
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

        // demand pricing — the tower sees this line, the customer never does
        'price.demand'      => 'Alta demanda ({x}x)',
        'surge.computed'    => 'Alta demanda en la zona',
        'surge.manual'      => 'Precio ajustado por la plataforma',
        'surge.emergency'   => 'Precios congelados por emergencia',
        'surge.off'         => 'Precio normal',
        'surge.normal'      => 'Precio normal',

        // what the one price covers (customer side)
        'inc.miles'         => 'Remolque hasta {n} millas incluido',
        'inc.tow'           => 'Remolque incluido',
        'inc.service'       => 'Servicio completo en el lugar',
        'inc.hookup'        => 'Enganche y mano de obra incluidos',
        'inc.allin'         => 'Precio final — sin cargos sorpresa',

        // coverage
        'msg.no_coverage'       => 'Todavía no tenemos grúas disponibles en su zona. Estamos creciendo rápido — déjenos su teléfono y le avisamos apenas lleguemos.',
        'msg.no_coverage_saved' => 'Guardamos su solicitud. Todavía no tenemos grúas en su zona, y no le hemos cobrado nada. Le avisamos apenas tengamos cobertura.',
        'err.need_contact'      => 'Déjenos un teléfono o un correo para poder avisarle.',
        'ok.lead_saved'         => 'Listo, le avisamos',

        // terms
        'err.terms_required'    => 'Debe aceptar los Términos de Servicio para continuar.',

        // company verification
        'err.ein_required'      => 'Necesitamos el número EIN de su empresa.',
        'err.ein_format'        => 'El EIN debe tener 9 dígitos.',
        'err.company_phone_required' => 'Necesitamos el teléfono de la empresa — es el número que le damos al cliente cuando usted acepta un trabajo.',
        'msg.next_upload_docs'  => 'Su cuenta está en revisión. Puede ver los trabajos mientras tanto. Si necesitamos algún documento se lo pedimos directamente.',

        'doc.ein_letter'        => 'Carta EIN del IRS',
        'doc.state_registration'=> 'Registro estatal de la empresa',
        'doc.coi_liability'     => 'Certificado de seguro de responsabilidad',
        'doc.coi_garage_keepers'=> 'Seguro de garage keepers',
        'doc.coi_on_hook'       => 'Seguro on-hook',
        'doc.owner_id'          => 'Identificación del dueño o licencia de conducir',
        'doc.w9'                => 'Formulario W-9',
        'doc.business_license'  => 'Licencia comercial',
        'doc.tow_license'       => 'Licencia de grúas',
        'doc.dot_authority'     => 'Autoridad DOT',
        'doc.other'             => 'Otro documento',

        'err.doc_type_unknown'  => 'Tipo de documento no válido.',
        'err.doc_expiry_required'=> 'Indique la fecha de vencimiento de la póliza.',
        'err.doc_not_found'     => 'No encontramos ese documento.',
        'err.doc_approved_locked'=> 'Un documento ya aprobado no se puede borrar.',
        'err.upload_failed'     => 'No pudimos subir el archivo. Intente otra vez.',
        'err.upload_none'       => 'Seleccione un archivo.',
        'err.upload_too_big'    => 'El archivo supera el límite de {mb} MB.',
        'err.upload_type'       => 'Solo aceptamos PDF, JPG, PNG o HEIC.',
        'ok.doc_uploaded'       => 'Documento subido',
        'ok.docs_submitted'     => 'Documentos completos — su cuenta pasó a revisión',
        'ok.doc_deleted'        => 'Documento eliminado',

        // admin
        'ok.doc_reviewed'       => 'Documento revisado',
        'ok.account_reviewed'   => 'Cuenta actualizada',
        'ok.saved'              => 'Guardado',
        'ok.override_set'       => 'Ajuste manual aplicado',
        'ok.override_cleared'   => 'Ajuste manual eliminado',
        'ok.emergency_on'       => 'MODO EMERGENCIA ACTIVADO — precios congelados en todo el país',
        'ok.emergency_off'      => 'Modo emergencia desactivado',
        'err.reject_reason_required' => 'Escriba el motivo del rechazo — la empresa lo va a ver.',
        'err.surge_range'       => 'El multiplicador debe estar entre 0.5 y {max}.',

        // live tracking
        'err.tracking_off'      => 'El seguimiento en vivo está desactivado.',
        'err.not_your_job'      => 'Este trabajo no es suyo.',
        'err.job_not_live'      => 'Este trabajo ya terminó — el seguimiento se detuvo.',
        'err.bad_location'      => 'Ubicación inválida.',

        // push notifications
        'ok.push_on'            => 'Alertas activadas en este teléfono',
        'ok.push_off'           => 'Alertas desactivadas en este teléfono',
        'ok.test_sent'          => 'Alerta de prueba enviada — revise su teléfono',
        'err.test_failed'       => 'No se pudo entregar la alerta de prueba. Vuelva a activar las alertas en el teléfono.',
        'err.no_devices'        => 'Ningún teléfono tiene las alertas activadas todavía.',
        'err.radius_range'      => 'El radio de alertas debe estar entre 1 y {max} millas.',
        'err.min_payout_range'  => 'El mínimo debe estar entre $0 y $1,000.',
        'err.bad_time'          => 'La hora debe ir en formato 24 horas, por ejemplo 22:00.',
        'err.quiet_all_day'     => 'La hora de inicio y de fin no pueden ser la misma — eso silenciaría las alertas todo el día.',
        'err.bad_timezone'      => 'Zona horaria desconocida.',
        'push.test_title'       => 'Prueba — así se ve un trabajo nuevo',
        'push.test_body'        => 'Sus alertas funcionan. Los trabajos reales muestran el pago, la zona y la distancia.',

        // field names, for "Falta {field}"
        'field.pickup_address'  => 'la dirección',
        'field.pickup_lat'      => 'la ubicación',
        'field.pickup_lng'      => 'la ubicación',
        'field.customer_name'   => 'su nombre',
        'field.customer_phone'  => 'su teléfono',

        // notifications
        'notif.approved_title'  => 'Su cuenta fue aprobada',
        'notif.approved_body'   => 'Ya puede aceptar trabajos. Su perfil ahora muestra el distintivo de verificado.',
        'notif.review_title'    => 'Actualización sobre su verificación',
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
        // ─── Rate sheet ───────────────────────────────────────────────
        'err.bad_request'         => 'Invalid request.',
        'ok.rates_saved'          => 'Rates saved',
        'rate.promise'            => "We'll try to match your rates. This helps us set fair prices in your area. They are never shown to any other company.",
        'rate.tow_light'          => 'Tow - light duty',
        'rate.tow_medium'         => 'Tow - medium duty',
        'rate.tow_heavy'          => 'Tow - heavy duty',
        'rate.winch_recovery_light' => 'Winch / recovery',
        'rate.lockout_light'      => 'Lockout',
        'rate.jumpstart_light'    => 'Jump start',
        'rate.tire_change_light'  => 'Tire change',
        'rate.fuel_delivery_light'=> 'Fuel delivery',
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

        'price.demand'      => 'High demand ({x}x)',
        'surge.computed'    => 'High demand in this area',
        'surge.manual'      => 'Price adjusted by the platform',
        'surge.emergency'   => 'Pricing frozen — emergency mode',
        'surge.off'         => 'Standard pricing',
        'surge.normal'      => 'Standard pricing',

        'inc.miles'         => 'Tow up to {n} miles included',
        'inc.tow'           => 'Tow included',
        'inc.service'       => 'Full roadside service',
        'inc.hookup'        => 'Hook-up and labour included',
        'inc.allin'         => 'Final price — no surprise charges',

        'msg.no_coverage'       => 'We do not have trucks in your area yet. We are expanding fast — leave your number and we will let you know the moment we do.',
        'msg.no_coverage_saved' => 'We saved your request. We do not have trucks in your area yet, and you have not been charged anything. We will let you know as soon as we cover you.',
        'err.need_contact'      => 'Leave a phone number or an email so we can reach you.',
        'ok.lead_saved'         => 'Got it — we will be in touch',

        'err.terms_required'    => 'You need to accept the Terms of Service to continue.',

        'err.ein_required'      => 'We need your company EIN.',
        'err.ein_format'        => 'An EIN is 9 digits.',
        'err.company_phone_required' => 'We need your company phone — it is the number the customer is given when you accept a job.',
        'msg.next_upload_docs'  => 'Your account is under review. You can browse jobs in the meantime. If we need any documents we will ask you directly.',

        'doc.ein_letter'        => 'IRS EIN letter',
        'doc.state_registration'=> 'State business registration',
        'doc.coi_liability'     => 'Certificate of liability insurance',
        'doc.coi_garage_keepers'=> 'Garage keepers insurance',
        'doc.coi_on_hook'       => 'On-hook insurance',
        'doc.owner_id'          => "Owner ID or driver's licence",
        'doc.w9'                => 'W-9 form',
        'doc.business_license'  => 'Business licence',
        'doc.tow_license'       => 'Towing licence',
        'doc.dot_authority'     => 'DOT authority',
        'doc.other'             => 'Other document',

        'err.doc_type_unknown'  => 'Unknown document type.',
        'err.doc_expiry_required'=> 'Enter the policy expiry date.',
        'err.doc_not_found'     => 'We could not find that document.',
        'err.doc_approved_locked'=> 'An approved document cannot be deleted.',
        'err.upload_failed'     => 'We could not upload that file. Please try again.',
        'err.upload_none'       => 'Choose a file.',
        'err.upload_too_big'    => 'That file is over the {mb} MB limit.',
        'err.upload_type'       => 'We accept PDF, JPG, PNG or HEIC only.',
        'ok.doc_uploaded'       => 'Document uploaded',
        'ok.docs_submitted'     => 'All documents in — your account is now under review',
        'ok.doc_deleted'        => 'Document deleted',

        'ok.doc_reviewed'       => 'Document reviewed',
        'ok.account_reviewed'   => 'Account updated',
        'ok.saved'              => 'Saved',
        'ok.override_set'       => 'Manual override applied',
        'ok.override_cleared'   => 'Manual override cleared',
        'ok.emergency_on'       => 'EMERGENCY MODE ON — surge pricing frozen nationwide',
        'ok.emergency_off'      => 'Emergency mode off',
        'err.reject_reason_required' => 'Write the reason for rejection — the company will see it.',
        'err.surge_range'       => 'Multiplier must be between 0.5 and {max}.',

        // live tracking
        'err.tracking_off'      => 'Live tracking is switched off.',
        'err.not_your_job'      => 'This job is not yours.',
        'err.job_not_live'      => 'This job has ended — tracking stopped.',
        'err.bad_location'      => 'Invalid location.',

        // push notifications
        'ok.push_on'            => 'Alerts are on for this phone',
        'ok.push_off'           => 'Alerts are off for this phone',
        'ok.test_sent'          => 'Test alert sent — check your phone',
        'err.test_failed'       => 'The test alert could not be delivered. Turn alerts on again on the phone.',
        'err.no_devices'        => 'No phone has alerts turned on yet.',
        'err.radius_range'      => 'Alert radius must be between 1 and {max} miles.',
        'err.min_payout_range'  => 'The minimum must be between $0 and $1,000.',
        'err.bad_time'          => 'Use 24-hour time, for example 22:00.',
        'err.quiet_all_day'     => 'Start and end cannot be the same time — that would silence alerts all day.',
        'err.bad_timezone'      => 'Unknown timezone.',
        'push.test_title'       => 'Test — this is what a new job looks like',
        'push.test_body'        => 'Your alerts are working. Real jobs show the payout, the area and the distance.',

        'field.pickup_address'  => 'the address',
        'field.pickup_lat'      => 'the location',
        'field.pickup_lng'      => 'the location',
        'field.customer_name'   => 'your name',
        'field.customer_phone'  => 'your phone number',

        'notif.approved_title'  => 'Your account is approved',
        'notif.approved_body'   => 'You can accept jobs now. Your profile shows the verified badge.',
        'notif.review_title'    => 'Update on your verification',
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
