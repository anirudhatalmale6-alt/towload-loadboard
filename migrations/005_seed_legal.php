<?php
/**
 * 005 — Seed the terms of service and privacy policy, v1.0, Spanish + English.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  READ THIS BEFORE RELYING ON IT
 *
 *  I am a developer, not a lawyer. This is a careful, plain-language draft that
 *  covers the things that actually come up in a towing marketplace — who is
 *  responsible when a car gets scratched, when the card is charged, what
 *  happens when nobody accepts, and what the platform is and is not.
 *
 *  It should save an attorney most of the billable hours. It should not be the
 *  final word. Two sections in particular need a real review now that the
 *  platform operates nationwide: the limitation of liability and the dispute
 *  resolution clause. Both are enforceable in some states and not in others,
 *  and consumer-protection rules for roadside services vary considerably.
 *
 *  Every {{PLACEHOLDER}} below has to be filled in before launch.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Run:  php migrations/005_seed_legal.php
 */

require_once __DIR__ . '/../includes/helpers.php';

$VERSION = '1.0';
$EFFECTIVE = date('Y-m-d H:i:s');

// ═══════════════════════════════════════════════════════════════════════════
//  CUSTOMER TERMS — Spanish
// ═══════════════════════════════════════════════════════════════════════════
$customerEs = <<<'HTML'
<p class="lead">Estos términos explican qué hacemos, qué no hacemos, cómo se cobra y qué pasa si algo sale mal. Están escritos para que se entiendan. Al pedir un servicio usted los acepta.</p>

<h2>1. Quiénes somos</h2>
<p>{{COMPANY_NAME}} opera una plataforma que conecta a conductores varados con compañías de grúas independientes. <strong>No somos una compañía de grúas.</strong> No somos dueños de las grúas, no empleamos a los operadores y no realizamos el servicio. Verificamos a las compañías antes de dejarlas trabajar en la plataforma, coordinamos el trabajo y manejamos el pago.</p>

<h2>2. El precio</h2>
<p>Le mostramos <strong>un solo precio</strong> antes de que confirme. Ese precio incluye el enganche, la mano de obra y las millas indicadas. No agregamos cargos después.</p>
<p>El precio depende del servicio, del tipo de vehículo, de la distancia, de la condición del vehículo, de la hora y de la demanda en su zona en ese momento. Cuando la demanda es alta el precio puede ser más alto de lo habitual; si es así se lo indicamos antes de que confirme, no después.</p>
<p><strong>No están incluidos:</strong> peajes, cargos de estacionamiento o de portón en el destino, almacenamiento si el vehículo queda guardado, ni trabajo adicional que usted pida directamente al operador en el lugar. Cualquier trabajo adicional es un acuerdo entre usted y la compañía de grúas.</p>

<h2>3. Cómo se cobra</h2>
<p>Cuando confirma, <strong>autorizamos</strong> su tarjeta por el monto mostrado. Autorizar no es cobrar: el dinero queda reservado, no sale de su cuenta.</p>
<ul>
<li><strong>Si una grúa acepta y completa el servicio</strong>, se cobra el monto autorizado.</li>
<li><strong>Si ninguna grúa acepta</strong>, la autorización se libera y usted no paga nada.</li>
<li><strong>Si cancela antes de que una grúa vaya en camino</strong>, no paga nada.</li>
<li><strong>Si cancela cuando la grúa ya va en camino</strong>, se cobra una tarifa de salida, indicada en la pantalla antes de cancelar.</li>
<li><strong>Si la grúa llega y el vehículo no está</strong>, o no se puede acceder a él por una razón que no le informó, se cobra la misma tarifa de salida. El operador ya hizo el viaje.</li>
</ul>
<p>Su banco puede tardar unos días en liberar una autorización. Eso lo controla su banco, no nosotros.</p>

<h2>4. La compañía de grúas</h2>
<p>La compañía que atiende su servicio es un <strong>contratista independiente</strong>. Antes de aprobarla verificamos su registro estatal, su número EIN, su certificado de seguro de responsabilidad y la identificación del dueño. Verificar no es garantizar: no controlamos cómo trabaja el operador en el lugar.</p>
<p>Si su vehículo sufre daños durante el servicio, la responsabilidad es de la compañía de grúas y de su seguro. Avísenos de inmediato: guardamos el registro completo del trabajo, las fotos y los datos de la póliza, y le ayudamos a presentar el reclamo. La reclamación es contra la compañía, no contra nosotros.</p>

<h2>5. Su responsabilidad</h2>
<ul>
<li>Debe ser el dueño del vehículo o tener autorización para pedir que lo remolquen.</li>
<li>La información que nos da debe ser correcta: ubicación, tipo de vehículo, si tiene llaves, si las llantas giran, si hubo un accidente. El precio y la grúa que enviamos dependen de eso.</li>
<li>Retire sus objetos de valor antes del remolque. No respondemos por objetos dejados dentro del vehículo.</li>
<li>Debe estar presente, o dejar a alguien autorizado, salvo que haya acordado lo contrario con el operador.</li>
</ul>

<h2>6. Reclamos y reembolsos</h2>
<p>Si algo salió mal, contáctenos primero por {{CONTACT}}. Revisamos cada caso con el registro del trabajo. Si el servicio no se prestó, reembolsamos. Si hubo un problema con la calidad del servicio, mediamos con la compañía de grúas.</p>
<p>Le pedimos que nos contacte antes de disputar el cargo con su banco. Una disputa bancaria congela el pago del operador que sí hizo el trabajo, y casi siempre podemos resolverlo más rápido directamente.</p>

<h2>7. Límite de responsabilidad</h2>
<p>Ofrecemos la plataforma tal como está. En la medida que la ley lo permita, nuestra responsabilidad total frente a usted por cualquier reclamo relacionado con un servicio se limita al monto que usted pagó por ese servicio. Esto no limita la responsabilidad de la compañía de grúas frente a usted, ni ninguna responsabilidad que por ley no se pueda limitar.</p>

<h2>8. Emergencias</h2>
<p>Esta plataforma no es un servicio de emergencia. <strong>Si hay personas heridas, si el vehículo está en un carril de circulación o si hay riesgo inmediato, llame al 911 primero.</strong></p>

<h2>9. Cambios y ley aplicable</h2>
<p>Podemos actualizar estos términos. La versión que usted aceptó al pedir su servicio es la que aplica a ese servicio; guardamos cada versión. Estos términos se rigen por las leyes del estado de {{GOVERNING_STATE}}.</p>

<h2>10. Contacto</h2>
<p>{{COMPANY_NAME}} — {{CONTACT}} — {{ADDRESS}}</p>
<p class="version">Versión {{VERSION}}, vigente desde {{EFFECTIVE}}.</p>
HTML;

// ═══════════════════════════════════════════════════════════════════════════
//  CUSTOMER TERMS — English
// ═══════════════════════════════════════════════════════════════════════════
$customerEn = <<<'HTML'
<p class="lead">These terms explain what we do, what we don't do, how you're charged, and what happens if something goes wrong. They're written to be understood. By requesting service you accept them.</p>

<h2>1. Who we are</h2>
<p>{{COMPANY_NAME}} operates a platform that connects stranded drivers with independent towing companies. <strong>We are not a towing company.</strong> We do not own the trucks, we do not employ the operators, and we do not perform the service. We verify companies before they can work on the platform, we coordinate the job, and we handle the payment.</p>

<h2>2. The price</h2>
<p>We show you <strong>one price</strong> before you confirm. It covers the hook-up, the labour and the miles stated. We do not add charges afterwards.</p>
<p>The price depends on the service, the vehicle, the distance, the condition of the vehicle, the time of day, and how busy your area is at that moment. When demand is high the price can be above the usual rate — if it is, we tell you before you confirm, not after.</p>
<p><strong>Not included:</strong> tolls, parking or gate fees at the destination, storage if the vehicle is kept somewhere, or extra work you arrange directly with the operator on scene. Any extra work is an agreement between you and the towing company.</p>

<h2>3. How you're charged</h2>
<p>When you confirm, we <strong>authorise</strong> your card for the amount shown. An authorisation is not a charge: the money is held, it does not leave your account.</p>
<ul>
<li><strong>If a truck accepts and completes the job</strong>, the authorised amount is captured.</li>
<li><strong>If no truck accepts</strong>, the authorisation is released and you pay nothing.</li>
<li><strong>If you cancel before a driver is on the way</strong>, you pay nothing.</li>
<li><strong>If you cancel once a driver is already on the way</strong>, a call-out fee applies. It is shown on screen before you cancel.</li>
<li><strong>If the driver arrives and the vehicle is gone</strong>, or cannot be reached for a reason you did not tell us about, the same call-out fee applies. The operator has already made the trip.</li>
</ul>
<p>Your bank may take a few days to release an authorisation. That is controlled by your bank, not by us.</p>

<h2>4. The towing company</h2>
<p>The company that handles your job is an <strong>independent contractor</strong>. Before approving one we verify its state registration, EIN, certificate of liability insurance and the owner's identification. Verification is not a guarantee: we do not control how an operator works on scene.</p>
<p>If your vehicle is damaged during the service, responsibility sits with the towing company and its insurer. Tell us straight away — we keep the full job record, the photos and the policy details, and we will help you file the claim. The claim is against the company, not against us.</p>

<h2>5. Your responsibilities</h2>
<ul>
<li>You must own the vehicle or be authorised to have it towed.</li>
<li>The information you give us must be accurate: location, vehicle type, whether you have keys, whether the wheels roll, whether there was an accident. Both the price and the truck we send depend on it.</li>
<li>Take your valuables out before the tow. We are not responsible for items left in the vehicle.</li>
<li>Be present, or leave someone authorised, unless you have agreed otherwise with the operator.</li>
</ul>

<h2>6. Complaints and refunds</h2>
<p>If something went wrong, contact us first at {{CONTACT}}. We review every case against the job record. If the service was not performed, we refund. If there was a problem with the quality of the work, we mediate with the towing company.</p>
<p>Please come to us before disputing the charge with your bank. A bank dispute freezes payment to an operator who did do the work, and we can almost always resolve it faster directly.</p>

<h2>7. Limitation of liability</h2>
<p>The platform is provided as is. To the extent permitted by law, our total liability to you for any claim relating to a job is limited to the amount you paid for that job. This does not limit the towing company's liability to you, or any liability that cannot be limited by law.</p>

<h2>8. Emergencies</h2>
<p>This platform is not an emergency service. <strong>If anyone is injured, if the vehicle is in a live traffic lane, or if there is immediate danger, call 911 first.</strong></p>

<h2>9. Changes and governing law</h2>
<p>We may update these terms. The version you accepted when you requested your job is the version that applies to that job; we keep every version. These terms are governed by the laws of the State of {{GOVERNING_STATE}}.</p>

<h2>10. Contact</h2>
<p>{{COMPANY_NAME}} — {{CONTACT}} — {{ADDRESS}}</p>
<p class="version">Version {{VERSION}}, effective {{EFFECTIVE}}.</p>
HTML;

// ═══════════════════════════════════════════════════════════════════════════
//  TOWING COMPANY TERMS — Spanish
// ═══════════════════════════════════════════════════════════════════════════
$towerEs = <<<'HTML'
<p class="lead">Estos son los términos para las compañías de grúas que trabajan en la plataforma. Léalos: definen cómo le pagamos, qué esperamos de usted y qué puede hacer que le suspendamos la cuenta.</p>

<h2>1. Usted es un contratista independiente</h2>
<p>Usted no es empleado nuestro. No hay exclusividad, no hay horario obligatorio y no hay cuota mínima de trabajos. Usted decide qué trabajos acepta. Usted es responsable de sus equipos, sus operadores, sus impuestos, sus licencias y sus seguros.</p>

<h2>2. Requisitos para trabajar en la plataforma</h2>
<ul>
<li>Empresa registrada y en regla en el estado donde opera.</li>
<li>Número EIN válido.</li>
<li>Seguro de responsabilidad comercial vigente, con certificado a nombre de la empresa.</li>
<li>Identificación del dueño o licencia de conducir.</li>
<li>Las licencias de grúa que exija su estado, condado o ciudad.</li>
</ul>
<p><strong>Mantenerlos vigentes es su responsabilidad.</strong> Si su certificado de seguro vence, el sistema deja de mostrarle trabajos automáticamente hasta que suba uno nuevo. No es un castigo — es lo que protege a todos.</p>

<h2>3. Aceptar un trabajo</h2>
<p>Aceptar es un compromiso. El cliente está varado y ya se le dijo que usted va en camino.</p>
<ul>
<li>Dé un tiempo estimado realista y cúmplalo. Si se atrasa, actualícelo.</li>
<li>Si no puede ir, libere el trabajo de inmediato para que otro lo tome.</li>
<li>Cancelar repetidamente después de aceptar afecta su calificación y puede suspender su cuenta.</li>
<li>Marque el trabajo como completado solo cuando realmente lo esté.</li>
</ul>

<h2>4. Cómo le pagamos</h2>
<p>El precio del trabajo se le muestra completo y desglosado antes de aceptar, junto con lo que usted recibe. Usted recibe <strong>el monto del trabajo menos la comisión de la plataforma ({{FEE_PERCENT}}%)</strong>. No hay cuota mensual, no hay cargo por prospecto y no hay exclusividad.</p>
<p>El dinero del cliente se retiene antes de que el trabajo aparezca en su pantalla. Por eso todo trabajo que ve está pagado por adelantado — usted no persigue el cobro.</p>
<p>El pago se libera cuando el trabajo se marca como completado y se transfiere a su cuenta bancaria a través de Stripe. Los tiempos de depósito los define Stripe y su banco.</p>
<p><strong>Vehículo ausente:</strong> si llega y el vehículo no está, suba una foto desde el lugar y repórtelo. Se le paga la tarifa de salida. La foto es lo que sostiene el cobro si el cliente reclama.</p>

<h2>5. Conducta</h2>
<ul>
<li>Cobre al cliente únicamente lo que la plataforma indica. Pedir dinero adicional en el lugar por el mismo servicio es motivo de suspensión inmediata.</li>
<li>No use los datos del cliente para ofrecerle servicios fuera de la plataforma. Los datos se le entregan para hacer ese trabajo.</li>
<li>Trate el vehículo y al cliente con profesionalismo. Documente con fotos antes y después.</li>
<li>Cumpla las leyes de tránsito y de remolque del lugar donde opera, incluidas las tarifas máximas donde existan.</li>
</ul>

<h2>6. Daños y responsabilidad</h2>
<p>Si daña un vehículo, la responsabilidad es suya y de su seguro. Su póliza es la principal. Usted nos mantiene indemnes frente a reclamos derivados de su trabajo. Nosotros le entregamos el registro completo del trabajo para su reclamo.</p>

<h2>7. Verificación, suspensión y baja</h2>
<p>Revisamos sus documentos antes de aprobarlo. Podemos suspender o dar de baja una cuenta por documentos vencidos o falsos, por cobros indebidos al cliente, por cancelaciones repetidas, por calificaciones muy bajas o por incumplir la ley. Le decimos el motivo. Usted puede darse de baja cuando quiera; los trabajos ya aceptados debe completarlos.</p>

<h2>8. Ley aplicable</h2>
<p>Estos términos se rigen por las leyes del estado de {{GOVERNING_STATE}}.</p>

<h2>9. Contacto</h2>
<p>{{COMPANY_NAME}} — {{CONTACT}} — {{ADDRESS}}</p>
<p class="version">Versión {{VERSION}}, vigente desde {{EFFECTIVE}}.</p>
HTML;

// ═══════════════════════════════════════════════════════════════════════════
//  TOWING COMPANY TERMS — English
// ═══════════════════════════════════════════════════════════════════════════
$towerEn = <<<'HTML'
<p class="lead">These are the terms for towing companies working on the platform. Read them: they set out how you get paid, what we expect, and what will get your account suspended.</p>

<h2>1. You are an independent contractor</h2>
<p>You are not our employee. There is no exclusivity, no required hours and no minimum number of jobs. You decide which jobs you take. You are responsible for your equipment, your operators, your taxes, your licensing and your insurance.</p>

<h2>2. Requirements to work on the platform</h2>
<ul>
<li>A registered business in good standing in the state where you operate.</li>
<li>A valid EIN.</li>
<li>Current commercial liability insurance, with the certificate in the company's name.</li>
<li>Owner identification or driver's licence.</li>
<li>Any towing licences required by your state, county or city.</li>
</ul>
<p><strong>Keeping them current is your responsibility.</strong> If your certificate of insurance expires, the system stops showing you jobs automatically until you upload a current one. That is not a penalty — it is what protects everyone.</p>

<h2>3. Accepting a job</h2>
<p>Accepting is a commitment. The customer is stranded and has already been told you are on the way.</p>
<ul>
<li>Give a realistic ETA and keep it. If you fall behind, update it.</li>
<li>If you cannot make it, release the job immediately so someone else can take it.</li>
<li>Repeatedly cancelling after accepting affects your rating and can suspend your account.</li>
<li>Only mark a job complete when it actually is.</li>
</ul>

<h2>4. How you get paid</h2>
<p>The job price is shown to you in full, itemised, before you accept, along with what you take home. You receive <strong>the job amount less the platform fee ({{FEE_PERCENT}}%)</strong>. No monthly fee, no lead fees, no exclusivity.</p>
<p>The customer's money is held before the job ever appears on your screen. That is why every job you see is prepaid — you are not chasing payment.</p>
<p>Payment is released when the job is marked complete and transferred to your bank account through Stripe. Deposit timing is set by Stripe and your bank.</p>
<p><strong>Gone on arrival:</strong> if you arrive and the vehicle is not there, upload a photo from the scene and report it. You are paid the call-out fee. That photo is what holds the charge up if the customer disputes it.</p>

<h2>5. Conduct</h2>
<ul>
<li>Charge the customer only what the platform states. Asking for extra money on scene for the same service is grounds for immediate suspension.</li>
<li>Do not use customer details to sell services off the platform. Those details are given to you to do that job.</li>
<li>Treat the vehicle and the customer professionally. Document with photos before and after.</li>
<li>Comply with the traffic and towing laws where you operate, including rate caps where they exist.</li>
</ul>

<h2>6. Damage and liability</h2>
<p>If you damage a vehicle, that is on you and your insurer. Your policy is primary. You indemnify us against claims arising from your work. We give you the full job record for your claim.</p>

<h2>7. Verification, suspension and leaving</h2>
<p>We review your documents before approving you. We may suspend or remove an account for expired or false documents, for charging customers outside the platform, for repeated cancellations, for very low ratings, or for breaking the law. We tell you the reason. You can leave whenever you like; jobs you have already accepted must be completed.</p>

<h2>8. Governing law</h2>
<p>These terms are governed by the laws of the State of {{GOVERNING_STATE}}.</p>

<h2>9. Contact</h2>
<p>{{COMPANY_NAME}} — {{CONTACT}} — {{ADDRESS}}</p>
<p class="version">Version {{VERSION}}, effective {{EFFECTIVE}}.</p>
HTML;

// ═══════════════════════════════════════════════════════════════════════════
//  PRIVACY — Spanish
// ═══════════════════════════════════════════════════════════════════════════
$privacyEs = <<<'HTML'
<p class="lead">Qué datos recogemos, por qué, y con quién los compartimos. Sin rodeos.</p>

<h2>1. Si usted pide una grúa</h2>
<p>Recogemos su nombre, su teléfono, su ubicación, los datos del vehículo y, si lo da, su correo. Lo necesitamos para enviarle una grúa y para que usted siga el servicio.</p>
<p><strong>No creamos una cuenta ni le pedimos una contraseña.</strong> Su enlace de seguimiento es su identificación.</p>
<p>Su nombre, su teléfono y su dirección exacta se comparten <strong>únicamente con la compañía de grúas que acepta su trabajo</strong>, y solo cuando lo acepta. Antes de eso, las compañías ven la zona y el tipo de trabajo, no sus datos.</p>
<p>Los datos de su tarjeta los procesa Stripe. Nosotros no los vemos ni los guardamos.</p>

<h2>2. Si usted es una compañía de grúas</h2>
<p>Recogemos los datos de la empresa, el EIN, los documentos de verificación (registro estatal, seguro, identificación del dueño) y los datos bancarios a través de Stripe.</p>
<p>Los documentos de verificación se guardan cifrados en el servidor, no son accesibles públicamente y solo los ve el personal de la plataforma que revisa cuentas. No los compartimos con clientes ni con otras compañías. Al cliente solo le mostramos el nombre de su empresa, su calificación y el distintivo de verificado.</p>

<h2>3. Con quién compartimos</h2>
<ul>
<li><strong>La compañía de grúas asignada</strong> — los datos necesarios para hacer el trabajo.</li>
<li><strong>Stripe</strong> — para procesar pagos y transferencias.</li>
<li><strong>Autoridades</strong> — solo cuando la ley lo exige.</li>
</ul>
<p>No vendemos datos personales.</p>

<h2>4. Mensajes de texto</h2>
<p>Al pedir un servicio usted acepta recibir mensajes SMS sobre ese servicio: confirmación, grúa asignada, llegada y recibo. Son mensajes operativos, no publicidad. Pueden aplicar tarifas de su operador.</p>

<h2>5. Cuánto tiempo guardamos los datos</h2>
<p>Los registros de trabajos y pagos se guardan mientras la ley lo exija para efectos contables y fiscales. Los documentos de verificación se guardan mientras la cuenta esté activa y por el período que exija la ley después. Puede pedirnos que borremos sus datos cuando ya no exista obligación legal de conservarlos.</p>

<h2>6. Sus derechos</h2>
<p>Puede pedirnos una copia de sus datos, una corrección o su eliminación escribiendo a {{CONTACT}}. Según el estado donde viva puede tener derechos adicionales; los respetamos.</p>

<h2>7. Contacto</h2>
<p>{{COMPANY_NAME}} — {{CONTACT}} — {{ADDRESS}}</p>
<p class="version">Versión {{VERSION}}, vigente desde {{EFFECTIVE}}.</p>
HTML;

// ═══════════════════════════════════════════════════════════════════════════
//  PRIVACY — English
// ═══════════════════════════════════════════════════════════════════════════
$privacyEn = <<<'HTML'
<p class="lead">What we collect, why, and who we share it with. No runaround.</p>

<h2>1. If you request a tow</h2>
<p>We collect your name, phone number, location, vehicle details and, if you give it, your email. We need these to send you a truck and to let you track the job.</p>
<p><strong>We do not create an account or ask you for a password.</strong> Your tracking link is your identity.</p>
<p>Your name, phone number and exact address are shared <strong>only with the towing company that accepts your job</strong>, and only once they accept. Before that, companies see the area and the type of job, not your details.</p>
<p>Card details are processed by Stripe. We never see them and never store them.</p>

<h2>2. If you are a towing company</h2>
<p>We collect your company details, EIN, verification documents (state registration, insurance, owner ID) and bank details through Stripe.</p>
<p>Verification documents are stored on the server behind authentication, are not publicly reachable, and are seen only by platform staff reviewing accounts. We do not share them with customers or other companies. Customers see only your company name, your rating and the verified badge.</p>

<h2>3. Who we share with</h2>
<ul>
<li><strong>The assigned towing company</strong> — what is needed to do the job.</li>
<li><strong>Stripe</strong> — to process payments and payouts.</li>
<li><strong>Authorities</strong> — only where the law requires it.</li>
</ul>
<p>We do not sell personal data.</p>

<h2>4. Text messages</h2>
<p>By requesting a job you agree to receive SMS about that job: confirmation, driver assigned, arrival and receipt. These are operational messages, not marketing. Carrier rates may apply.</p>

<h2>5. How long we keep things</h2>
<p>Job and payment records are kept for as long as accounting and tax law requires. Verification documents are kept while the account is active and for the period required by law afterwards. You can ask us to delete your data once no legal obligation to keep it remains.</p>

<h2>6. Your rights</h2>
<p>You can ask for a copy of your data, a correction, or deletion, at {{CONTACT}}. Depending on your state you may have additional rights; we honour them.</p>

<h2>7. Contact</h2>
<p>{{COMPANY_NAME}} — {{CONTACT}} — {{ADDRESS}}</p>
<p class="version">Version {{VERSION}}, effective {{EFFECTIVE}}.</p>
HTML;

// ─── Insert ──────────────────────────────────────────────────────────────────
$docs = [
    ['terms_customer', 'es', 'Términos de Servicio — Clientes',        $customerEs],
    ['terms_customer', 'en', 'Terms of Service — Customers',           $customerEn],
    ['terms_tower',    'es', 'Términos de Servicio — Compañías de Grúas', $towerEs],
    ['terms_tower',    'en', 'Terms of Service — Towing Companies',    $towerEn],
    ['privacy',        'es', 'Política de Privacidad',                 $privacyEs],
    ['privacy',        'en', 'Privacy Policy',                         $privacyEn],
];

$fee = (float)setting('consumer_fee_percent', 10.0);
$replacements = [
    '{{VERSION}}'   => $VERSION,
    '{{EFFECTIVE}}' => date('Y-m-d'),
    '{{FEE_PERCENT}}' => rtrim(rtrim(number_format($fee, 1), '0'), '.'),
];

$pdo = getDB();
$stmt = $pdo->prepare(
    "INSERT INTO legal_documents (doc_key, version, locale, title, body, effective_at, is_current)
     VALUES (:k, :v, :l, :t, :b, :e, 1)
     ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body),
                             effective_at = VALUES(effective_at), is_current = 1"
);

foreach ($docs as [$key, $locale, $title, $body]) {
    // Older versions stay in the table — an acceptance has to keep pointing at
    // the text that was actually agreed to.
    $pdo->prepare("UPDATE legal_documents SET is_current = 0 WHERE doc_key = :k AND locale = :l AND version <> :v")
        ->execute([':k' => $key, ':l' => $locale, ':v' => $VERSION]);

    $stmt->execute([
        ':k' => $key, ':v' => $VERSION, ':l' => $locale, ':t' => $title,
        ':b' => strtr($body, $replacements), ':e' => $EFFECTIVE,
    ]);
    echo "seeded $key/$locale\n";
}

echo "\nPlaceholders still to fill in before launch:\n";
echo "  {{COMPANY_NAME}}  {{CONTACT}}  {{ADDRESS}}  {{GOVERNING_STATE}}\n";
echo "They are stored in the document body — edit legal_documents.body, or rerun\n";
echo "this file after adding them to \$replacements above.\n";
