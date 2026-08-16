/* ═══════════════════════════════════════════════════════════════════════════
   TowSling translations — Spanish first.

   Miami-Dade is majority Spanish-speaking, so 'es' is the default and English
   is the opt-in. The choice is remembered per browser and sent to the API on
   every request, so server messages come back in the same language the page
   is showing.

   Markup usage:
     data-i18n="key"        -> replaces textContent
     data-i18n-html="key"   -> replaces innerHTML (for strings with <b> etc.)
     data-i18n-ph="key"     -> replaces the placeholder attribute
   ═══════════════════════════════════════════════════════════════════════════ */

const TL_STRINGS = {
es: {
  /* shared */
  'lang.other':          'English',
  'brand.tagline':       'La grúa que necesita, cuando la necesita',
  'nav.operator_login':  'Entrar operadores →',
  'nav.signout':         'Cerrar sesión',
  'foot.licensed':       'Solo compañías con licencia, seguro y documentos verificados',
  'foot.verified':       'Verificamos el seguro de cada grúa antes de que pueda tomar un trabajo.',

  /* consumer — hero */
  'c.drivers_available': 'Grúas disponibles',
  'c.hero_1':            '¿Se quedó varado?',
  'c.hero_2':            'Pida una grúa ahora.',
  'c.hero_sub':          'Precio real en segundos. Sin llamadas, sin regateo.',
  'c.trust_1':           'Con licencia y seguro',
  'c.trust_2':           'Paga después del servicio',
  'c.trust_3':           'Seguimiento en vivo',

  /* consumer — step 1 */
  'c.q_what':            '¿Qué necesita?',
  'c.q_what_hint':       'Elija lo más parecido.',
  'c.svc_tow':           'Grúa',
  'c.svc_jump':          'Paso de corriente',
  'c.svc_tire':          'Llanta pinchada',
  'c.svc_lockout':       'Llaves adentro',
  'c.svc_fuel':          'Sin gasolina',
  'c.svc_winch':         'Atascado',
  'c.continue':          'Continuar',
  'c.back':              '← Atrás',

  /* consumer — step 2 */
  'c.q_where':           '¿Dónde está?',
  'c.q_where_hint':      'Sirve la dirección más cercana, una esquina o el marcador de milla.',
  'c.your_location':     'Su ubicación',
  'c.ph_pickup':         'ej. 1200 NW 27th Ave, Miami',
  'c.use_gps':           '📍 Usar mi ubicación actual',
  'c.gps_finding':       'Buscando su ubicación…',
  'c.gps_found':         '📍 Ubicación encontrada',
  'c.where_to':          '¿A dónde la llevamos?',
  'c.ph_dropoff':        'Taller, casa o concesionario',

  /* consumer — step 3 */
  'c.q_vehicle':         'Sobre el vehículo',
  'c.q_vehicle_hint':    'Esto define qué grúa le enviamos.',
  'c.type':              'Tipo',
  'c.cls_light':         'Auto / SUV',
  'c.cls_medium':        'Van / camioneta grande',
  'c.cls_moto':          'Motocicleta',
  'c.cls_heavy':         'Camión / casa rodante',
  'c.make_model':        'Año, marca y modelo',
  'c.ph_vehicle':        'ej. 2019 Honda Accord',
  'c.q_wheels':          '¿Ruedan las llantas?',
  'c.q_accident':        '¿Fue un accidente?',
  'c.yes':               'Sí',
  'c.no':                'No',
  'c.see_price':         'Ver mi precio',
  'c.getting_price':     'Calculando su precio…',

  /* consumer — price */
  'c.all_in':            'Todo incluido. Sin cargos ocultos.',
  'c.after_hours':       'Tarifa nocturna · todo incluido',
  'c.weekend':           'Tarifa de fin de semana · todo incluido',
  'c.total':             'Total',

  /* Demanda alta. Se avisa ANTES de confirmar, nunca después, y sin mostrar el
     multiplicador — un número que el cliente no puede verificar solo genera
     discusión. */
  'c.busy_now':          'Hay mucha demanda en su zona en este momento, así que el precio está más alto de lo habitual. Es el precio final — no cambia después.',

  'c.accept_terms':      'Acepto los <a href="terms?doc=customer" target="_blank">Términos de Servicio</a> y la <a href="terms?doc=privacy" target="_blank">Política de Privacidad</a>, y autorizo la retención en mi tarjeta.',
  'c.err_terms':         'Marque la casilla de los Términos de Servicio para continuar.',
  'c.verified':          'Verificada',

  /* sin cobertura */
  'c.nocov_head':        'Todavía no llegamos a su zona',
  'c.nocov_msg':         'Todavía no tenemos grúas disponibles en su zona. No le hemos cobrado nada.',
  'c.notify_me':         'Avísenme cuando lleguen',
  'c.lead_done':         '✓ Listo — le avisamos',
  'c.nocov_note':        'Si necesita una grúa ahora mismo, busque una compañía local. No queremos hacerle perder tiempo cuando no podemos ayudarle.',

  'c.assure':            '<b>No le cobramos ahora.</b> Solo dejamos una retención. Usted paga cuando el trabajo esté hecho, y si ninguna grúa lo toma, se libera la retención y no paga nada.',
  'c.your_name':         'Su nombre',
  'c.ph_name':           'Nombre y apellido',
  'c.mobile':            'Número de celular',
  'c.email_receipt':     'Correo para el recibo',
  'c.ph_email':          'usted@correo.com',
  'c.confirm':           'Confirmar y buscar grúa',
  'c.sending':           'Enviando a las grúas…',
  'c.trucks_near':       'grúas disponibles cerca de usted',
  'c.truck_near':        'grúa disponible cerca de usted',
  'c.will_send_all':     'Enviaremos esto a todas las grúas del área',

  /* consumer — validation */
  'c.err_name':          'Ponga su nombre para que el operador sepa a quién buscar.',
  'c.err_phone':         'Ponga un celular válido — el operador lo va a llamar.',
  'c.err_where':         'Necesitamos saber dónde está.',
  'c.err_gps':           'No pudimos obtener su ubicación. Escriba la dirección.',
  'c.err_gps_none':      'Su navegador no comparte la ubicación. Escriba la dirección.',

  /* consumer — tracking */
  'c.finding':           'Buscando una grúa',
  'c.finding_sub':       'Enviando su solicitud a las grúas cercanas…',
  'c.eta_about':         'Llega en unos {n} minutos',
  'c.eta_about_one':     'Llega en menos de un minuto',
  'c.new_company':       'Compañía nueva en la plataforma',
  'c.thanks':            'Gracias por usar nuestro servicio. Su recibo va en camino.',
  'c.tl_sent':           'Solicitud enviada',
  'c.tl_assigned':       'Grúa asignada',
  'c.tl_enroute':        'Grúa en camino',
  'c.tl_arrived':        'Grúa llegó',
  'c.tl_towing':         'Grúa remolcando',
  'c.tl_done':           'Servicio completado',
  'c.call':              'Llamar',
  'c.jobs':              'servicios',
  'c.cancel_req':        'Cancelar esta solicitud',
  'c.cancel_confirm':    '¿Cancelar esta solicitud?',
  'c.charged':           'Cobrado',
  'c.map_min':           'min de distancia',
  'c.map_close':         'La grúa está muy cerca',
  'c.map_updating':      'Actualizando ubicación…',

  /* operator board */
  'o.available_jobs':    'Trabajos disponibles',
  'o.my_jobs':           'Mis trabajos',
  'o.jobs_within':       '{n} trabajos en {r} millas',
  'o.job_within':        '{n} trabajo en {r} millas',
  'o.all_services':      'Todos los servicios',
  'o.within':            'Hasta {n} millas',
  'o.paid_job':          'Pagado',
  'o.to_you':            'para usted',
  'o.accept':            'Aceptar',
  'o.miles':             'millas',
  'o.left':              'restante',
  'o.nothing':           'No hay nada por ahora',
  'o.nothing_sub':       'Los trabajos aparecen aquí en cuanto un cliente pide una grúa.',
  'o.nothing_mine':      'Los trabajos que tome aparecerán aquí.',
  'o.enroute':           'En camino',
  'o.onscene':           'En el lugar',
  'o.towing':            'Remolcando',
  'o.complete':          'Completar',
  'o.accept_job':        'Aceptar este trabajo',
  'o.eta_min':           'Llego en (minutos)',
  'o.you_receive':       'Usted recibe',
  'o.less_fee':          '({total} menos {fee} de comisión)',
  'o.cancel':            'Cancelar',
  'o.signin':            'Entrar',
  'o.email':             'Correo',
  'o.password':          'Contraseña',
  'o.no_account':        '¿No tiene cuenta?',
  'o.signup_link':       'Registre su compañía de grúas',

  /* desglose para el operador — el cliente ve un solo precio */
  'o.breakdown':         'Ver desglose del precio',
  'o.job_total':         'Total del trabajo',
  'o.platform_fee':      'Comisión de la plataforma',
  'o.you_get':           'Usted recibe',
  'o.high_demand':       'alta demanda',

  /* verificación */
  'o.verified_h':        'Cuenta verificada',
  'o.verified_p':        'Su empresa está aprobada. Los clientes ven su distintivo de verificada cuando usted toma un trabajo.',
  'o.review_h':          'Su cuenta está en revisión',
  'o.review_p':          'Recibimos su registro y estamos revisando su empresa. Puede ver los trabajos mientras tanto. Si necesitamos su EIN o algún documento se lo pedimos directamente — no hace falta subir nada todavía.',
  'o.unverified_h':      'Su empresa está en revisión',
  'j.docs_optional':     'Subir documentos ahora (opcional)',
  'j.docs_fix':          'Documentos que hay que corregir',
  'o.unverified_p':      'Hasta que su empresa esté aprobada puede ver los trabajos pero no aceptarlos ni cobrar. Si quiere adelantar, puede subir sus documentos abajo — es opcional.',
  'o.rejected_p':        'Su verificación fue rechazada. Revise los documentos marcados abajo y vuelva a subirlos.',
  'o.job_yours':         'El trabajo es suyo.',
  'o.completed_msg':     'Completado. {amount} va en camino a su banco.',
  'o.flag_nokeys':       'Sin llaves',
  'o.flag_wheels':       'Llantas trabadas',
  'o.flag_accident':     'Accidente',
  'o.flag_under':        'Subterráneo',
  'o.flag_ev':           'Eléctrico',
  'o.flag_flatbed':      'Plataforma',
  'o.mi_tow':            '{n} millas de remolque',
  'o.cannot_accept':     'No puede aceptar trabajos:',

  /* alertas push */
  'o.alerts':            'Alertas',
  'p.off_h':             'Active las alertas',
  'p.off_p':             'Un trabajo de grúa se vence en unos 20 minutos. Sin alertas va a enterarse tarde.',
  'p.enable':            'Activar alertas',
  'p.enabling':          'Activando…',
  'p.ios_h':             'Instale la app para recibir alertas',
  'p.ios_p':             'En iPhone las alertas solo funcionan si agrega esta página a la pantalla de inicio. Toma 20 segundos y se hace una sola vez.',
  'p.ios_1':             'Toque el botón Compartir, abajo en Safari',
  'p.ios_2':             'Elija “Agregar a inicio”',
  'p.ios_3':             'Abra TowSling desde el ícono nuevo y active las alertas',
  'p.ios_fine':          'Es una regla de Apple, no de nosotros. Safari no permite pedir permiso de alertas hasta que la app esté en la pantalla de inicio.',
  'p.blocked_h':         'Las alertas están bloqueadas en este dispositivo',
  'p.blocked_p':         'Usted rechazó el permiso antes. Vaya a Ajustes → TowSling → Notificaciones y actívelas; el navegador ya no vuelve a preguntar.',
  'p.unsupported_h':     'Este navegador no soporta alertas',
  'p.unsupported_p':     'Abra el tablero en Safari (iPhone) o Chrome (Android) para recibir los trabajos en el teléfono.',
  'p.err_unsupported':   'Este navegador no soporta alertas.',
  'p.err_sw':            'No se pudo iniciar el servicio de alertas. Recargue la página.',
  'p.err_denied':        'Usted no dio permiso. Sin permiso no podemos avisarle de los trabajos.',
  'p.err_server':        'El servidor no pudo registrar este teléfono. Intente otra vez.',
  'p.this_phone':        'Este dispositivo',
  'p.st_on':             'Alertas activas',
  'p.st_ready':          'Alertas apagadas',
  'p.st_ios':            'Falta instalar en la pantalla de inicio',
  'p.st_blocked':        'Bloqueadas en los ajustes del teléfono',
  'p.st_unsupported':    'No soportadas en este navegador',
  'p.send_test':         'Enviar alerta de prueba',
  'p.turn_off':          'Apagar en este dispositivo',
  'p.testing':           'Enviando…',
  'p.test_ok':           'Enviada a {n} teléfono(s). Si no llega en 30 segundos, revise las notificaciones en Ajustes.',
  'p.test_fail':         'No se pudo enviar.',
  'p.when_h':            'Cuándo avisarle',
  'p.pref_enabled':      'Avisarme de trabajos nuevos',
  'p.pref_radius':       'Avisarme solo dentro de (millas)',
  'p.pref_radius_hint':  'Déjelo vacío para usar su radio de servicio ({n} millas). Sirve para aceptar trabajos lejos sin que lo despierten por ellos.',
  'p.pref_min':          'No avisarme por menos de ($)',
  'p.pref_min_hint':     'Sobre lo que usted recibe, ya descontada la comisión. Deje 0 para recibir todo.',
  'p.pref_quiet_from':   'Silencio desde',
  'p.pref_quiet_to':     'Silencio hasta',
  'p.pref_tz':           'Su zona horaria',
  'p.pref_tz_hint':      'Las horas de silencio se calculan en SU hora local, no en la del servidor.',
  'p.pref_247':          'Su perfil dice 24/7, así que las horas de silencio no se aplican.',
  'p.save':              'Guardar',
  'p.saved':             'Guardado',
  'p.save_fail':         'No se pudo guardar.',
  'p.devices_h':         'Dispositivos registrados',
  'p.no_devices':        'Todavía ningún dispositivo tiene las alertas activas.',
  'p.dev_ok':            'Funcionando',
  'p.dev_untested':      'Registrado, sin probar',
  'p.dev_not_installed': 'No va a recibir nada — falta agregarlo a la pantalla de inicio',
  'p.dev_stopped':       'Dejó de responder',
  'p.dev_browser':       'navegador',
  'p.dev_last':          'última entrega {when}',
  'p.dev_never':         'todavía sin entregas',

  /* compartir ubicación (conductor) */
  'd.on_h':              'Compartiendo su ubicación con el cliente',
  'd.on_waiting':        'Buscando su ubicación…',
  'd.on_last':           'Última actualización hace {n} segundos',
  'd.off_h':             'No está compartiendo su ubicación',
  'd.off_p':             'El cliente no puede ver dónde está su grúa. Solo se comparte mientras el trabajo está activo.',
  'd.turn_on':           'Compartir',
  'd.turn_off':          'Dejar de compartir',
  'd.denied_h':          'El navegador bloqueó su ubicación',
  'd.denied_p':          'Actívela en los ajustes del navegador para que el cliente pueda ver la grúa acercarse.',
  'd.yard_h':            'Ubicación de su patio',
  'd.yard_p':            'Esto decide qué trabajos le ofrecemos. Póngalo una vez, estando en su patio o base, y ya queda guardado.',
  'd.yard_btn':          '📍 Usar mi ubicación actual',
  'd.yard_set':          'Guardada',
  'd.yard_unset':        'Sin configurar',
  'd.yard_finding':      'Buscando su ubicación…',
  'd.yard_saved':        'Listo — ya usamos su patio para buscarle trabajos cerca.',
  'd.yard_nogps':        'No pudimos obtener su ubicación. Actívela en los ajustes del navegador.',

  'svc.tow':             'Grúa',
  'svc.winch_recovery':  'Rescate',
  'svc.lockout':         'Apertura',
  'svc.jumpstart':       'Corriente',
  'svc.tire_change':     'Llanta',
  'svc.fuel_delivery':   'Gasolina',
  'svc.impound':         'Depósito',
  'svc.transport':       'Transporte',

  /* operator signup */
  'j.hero_1':            'Trabajos reales.',
  'j.hero_2':            'Ya pagados.',
  'j.hero_3':            'Directo a su teléfono.',
  'j.hero_sub':          'El cliente pide la grúa, nosotros ponemos el precio, usted recibe el aviso. Acepta y sale. Sin subastas, sin perseguir facturas, sin papeleo de clubes.',
  'j.cta':               'Registre su compañía',
  'j.free':              'Estados Unidos · registro gratis',
  'j.p1_n':              '90%',
  'j.p1_h':              'de cada trabajo es suyo',
  'j.p1_p':              'Nos quedamos con el 10%. Nada más — sin mensualidad, sin cargo por contacto, sin costo de registro.',
  'j.p2_n':              'Pagado',
  'j.p2_h':              'antes de que usted lo vea',
  'j.p2_p':              'La tarjeta del cliente se retiene antes de que el trabajo llegue a su pantalla. Nunca persigue un pago.',
  'j.p3_n':              'Suyo',
  'j.p3_h':              'para tomar o dejar',
  'j.p3_p':              'Ve el precio y la distancia desde el principio. ¿No le sirve? Lo ignora. Nada es obligatorio.',
  'j.how':               'Cómo funciona',
  'j.how_sub':           'Cuatro pasos, y usted solo participa en dos.',
  'j.s1_h':              'Entra el trabajo',
  'j.s1_p':              'Un conductor varado pide una grúa y paga el precio que cotizamos.',
  'j.s2_h':              'Le llega el aviso',
  'j.s2_p':              'Servicio, ubicación, distancia y exactamente cuánto le paga.',
  'j.s3_h':              'Usted acepta',
  'j.s3_p':              'Se desbloquea nombre, teléfono y dirección. Sale a hacer el trabajo.',
  'j.s4_h':              'Le pagamos',
  'j.s4_p':              'Marca completado. El dinero va a su banco automáticamente.',
  'j.plain_h':           'Respuestas claras, porque ya le han hecho este cuento antes',
  'j.plain_1':           'Usted se queda con el 90% del trabajo. Nuestro 10% es lo único que tomamos.',
  'j.plain_2':           'El dinero se retiene en la tarjeta del cliente antes de ofrecerle el trabajo.',
  'j.plain_3':           'Ve el pago y la distancia antes de aceptar. Sin sorpresas.',
  'j.plain_4':           '¿Llegó y el vehículo no estaba? Le pagamos la salida, no lo dejamos en cero.',
  'j.plain_5':           'Los pagos van a su banco por Stripe. Usted lleva su propia contabilidad.',
  'j.plain_6':           'Sin mensualidad.',
  'j.plain_7':           'Sin cobro por trabajos que no tomó.',
  'j.plain_8':           'Sin exclusividad. Conserve todos los clubes y cuentas que ya tiene.',
  'j.form_h':            'Registre su compañía',
  'j.form_sub':          'Toma unos dos minutos. Verificamos su seguro antes del primer trabajo.',
  'j.company':           'Nombre de la compañía',
  'j.first':             'Su nombre',
  'j.last':              'Apellido',
  'j.mobile':            'Celular',
  'j.city':              'Ciudad',
  'j.email':             'Correo',
  'j.password':          'Elija una contraseña',
  'j.ph_password':       'Mínimo 8 caracteres',
  'j.what_run':          '¿Qué equipo tiene?',
  'j.cap_light':         'Liviano',
  'j.cap_flatbed':       'Plataforma',
  'j.cap_wheel':         'Wheel lift',
  'j.cap_medium':        'Mediano',
  'j.cap_heavy':         'Pesado',
  'j.cap_winch':         'Winche / rescate',
  'j.cap_lockout':       'Aperturas',
  'j.cap_jump':          'Paso de corriente',
  'j.cap_tire':          'Cambio de llanta',
  'j.cap_moto':          'Motocicletas',
  'j.cap_247':           'Abierto 24/7',
  'j.how_far':           '¿Hasta dónde viaja?',
  'j.upto':              'Hasta {n} millas',
  'j.how_many':          '¿Cuántas grúas?',
  'j.more_than':         'Más de 10',
  'j.create':            'Crear mi cuenta',
  'j.creating':          'Creando su cuenta…',
  'j.fine':              'Registro gratis. Tomamos el 10% de los trabajos que complete — nada más, nunca.',
  'j.fine2':             'Necesitará un certificado de seguro vigente antes del primer trabajo.',
  'j.done_h':            '¡Listo!',
  'j.done_p':            'Envíenos su certificado de seguro y conecte su cuenta bancaria para empezar a tomar trabajos. Ya puede ver el tablero.',
  'j.open_board':        'Abrir mi tablero de trabajos →',
  'j.err_company':       '¿Cómo se llama su compañía?',
  'j.err_name':          'Necesitamos su nombre para que clientes y operadores sepan con quién tratan.',
  'j.err_email':         'Ese correo no parece válido.',
  'j.err_password':      'La contraseña debe tener al menos 8 caracteres.',
  'j.err_phone':         'Necesitamos un celular real — ahí llegan los avisos de trabajo.',

  /* verificación */
  'j.ein':               'EIN',
  'j.state':             'Estado',
  'j.use_gps':           '📍 Usar la ubicación de mi patio',
  'j.gps_finding':       'Buscando su ubicación…',
  'j.gps_found':         '📍 Ubicación guardada',
  'j.err_gps':           'No pudimos obtener su ubicación. Usaremos el estado que eligió.',
  'j.err_ein':           'El EIN debe tener 9 dígitos.',
  'j.err_terms':         'Marque la casilla de los Términos de Servicio para continuar.',
  'j.accept_terms':      'Acepto los <a href="terms?doc=tower" target="_blank">Términos de Servicio para compañías de grúas</a> y la <a href="terms?doc=privacy" target="_blank">Política de Privacidad</a>.',

  'j.docs_h':            'Suba sus documentos',
  'j.docs_sub':          'Necesitamos estos cuatro para verificar su empresa. Puede tomarles una foto con el celular ahora mismo — se guardan en privado y solo los ve nuestro equipo de revisión.',
  'j.doc_upload':        'Subir',
  'j.doc_replace':       'Reemplazar',
  'j.doc_received':      'Recibido — en revisión',
  'j.doc_missing':       'Falta',
  'j.doc_rejected':      'Rechazado',
  'j.doc_expiry':        'Fecha de vencimiento de la póliza',
  'j.err_expiry':        'Ponga la fecha de vencimiento de la póliza antes de subirla.',
  'j.docs_waiting':      'Cuando estén los cuatro, su cuenta pasa a revisión. Mientras tanto puede ver el tablero, pero todavía no aceptar trabajos.',
  'j.docs_submitted':    'Documentos completos. Su cuenta está en revisión — le avisamos apenas quede aprobada y aparezca su distintivo de verificada.'
},

en: {
  'lang.other':          'Español',
  'brand.tagline':       'The tow you need, when you need it',
  'nav.operator_login':  'Operator login →',
  'nav.signout':         'Sign out',
  'foot.licensed':       'Licensed, insured, document-verified companies only',
  'foot.verified':       "Every driver's insurance is verified before they can take a job.",

  'c.drivers_available': 'Drivers available',
  'c.hero_1':            'Stuck? Get a truck',
  'c.hero_2':            'on the way now.',
  'c.hero_sub':          'A real price in seconds. No phone calls, no haggling.',
  'c.trust_1':           'Licensed & insured',
  'c.trust_2':           'Pay after the job',
  'c.trust_3':           'Live tracking',

  'c.q_what':            'What do you need?',
  'c.q_what_hint':       'Pick the closest match.',
  'c.svc_tow':           'Tow',
  'c.svc_jump':          'Jump start',
  'c.svc_tire':          'Flat tire',
  'c.svc_lockout':       'Locked out',
  'c.svc_fuel':          'Out of fuel',
  'c.svc_winch':         'Stuck / ditch',
  'c.continue':          'Continue',
  'c.back':              '← Back',

  'c.q_where':           'Where are you?',
  'c.q_where_hint':      'Nearest address, intersection or mile marker is fine.',
  'c.your_location':     'Your location',
  'c.ph_pickup':         'e.g. 1200 NW 27th Ave, Miami',
  'c.use_gps':           '📍 Use my current location',
  'c.gps_finding':       'Getting your location…',
  'c.gps_found':         '📍 Location found',
  'c.where_to':          'Where should we take it?',
  'c.ph_dropoff':        'Shop, home, or dealership',

  'c.q_vehicle':         'About the vehicle',
  'c.q_vehicle_hint':    'This decides which truck we send.',
  'c.type':              'Type',
  'c.cls_light':         'Car / SUV',
  'c.cls_medium':        'Van / big truck',
  'c.cls_moto':          'Motorcycle',
  'c.cls_heavy':         'Semi / RV',
  'c.make_model':        'Year, make and model',
  'c.ph_vehicle':        'e.g. 2019 Honda Accord',
  'c.q_wheels':          'Do the wheels roll?',
  'c.q_accident':        'Was it in an accident?',
  'c.yes':               'Yes',
  'c.no':                'No',
  'c.see_price':         'See my price',
  'c.getting_price':     'Getting your price…',

  'c.all_in':            'All in. No hidden fees.',
  'c.after_hours':       'After-hours rate · all in, no hidden fees',
  'c.weekend':           'Weekend rate · all in, no hidden fees',
  'c.total':             'Total',

  'c.busy_now':          'It is busy in your area right now, so the price is higher than usual. This is the final price — it does not change afterwards.',

  'c.accept_terms':      'I accept the <a href="terms?doc=customer" target="_blank">Terms of Service</a> and <a href="terms?doc=privacy" target="_blank">Privacy Policy</a>, and authorise the hold on my card.',
  'c.err_terms':         'Tick the Terms of Service box to continue.',
  'c.verified':          'Verified',

  'c.nocov_head':        'We are not in your area yet',
  'c.nocov_msg':         'We do not have trucks available in your area yet. You have not been charged anything.',
  'c.notify_me':         'Let me know when you get here',
  'c.lead_done':         "✓ Done — we'll be in touch",
  'c.nocov_note':        'If you need a truck right now, try a local company. We are not going to waste your time when we cannot help.',

  'c.assure':            '<b>Your card is not charged now.</b> We only place a hold. You pay when the job is finished — and if no driver takes it, the hold is released and you pay nothing.',
  'c.your_name':         'Your name',
  'c.ph_name':           'First and last name',
  'c.mobile':            'Mobile number',
  'c.email_receipt':     'Email for the receipt',
  'c.ph_email':          'you@email.com',
  'c.confirm':           'Confirm & find my driver',
  'c.sending':           'Sending to drivers…',
  'c.trucks_near':       'trucks available near you',
  'c.truck_near':        'truck available near you',
  'c.will_send_all':     'We will send this to every driver in range',

  'c.err_name':          'Please enter your name so the driver knows who to look for.',
  'c.err_phone':         'Please enter a valid mobile number — the driver will call you.',
  'c.err_where':         'We need to know where you are.',
  'c.err_gps':           'Could not get your location. Type the address instead.',
  'c.err_gps_none':      'Your browser will not share location. Type the address instead.',

  'c.finding':           'Finding you a truck',
  'c.finding_sub':       'Sending your job to nearby drivers…',
  'c.eta_about':         'Estimated arrival in about {n} minutes',
  'c.eta_about_one':     'Arriving in under a minute',
  'c.new_company':       'New company on the platform',
  'c.thanks':            'Thanks for using us. Your receipt is on its way.',
  'c.tl_sent':           'Request sent',
  'c.tl_assigned':       'Driver assigned',
  'c.tl_enroute':        'Driver on the way',
  'c.tl_arrived':        'Driver arrived',
  'c.tl_towing':         'Towing',
  'c.tl_done':           'Job complete',
  'c.call':              'Call',
  'c.jobs':              'jobs',
  'c.cancel_req':        'Cancel this request',
  'c.cancel_confirm':    'Cancel this request?',
  'c.charged':           'Charged',
  'c.map_min':           'min away',
  'c.map_close':         'Your truck is very close',
  'c.map_updating':      'Updating location…',

  'o.available_jobs':    'Available jobs',
  'o.my_jobs':           'My jobs',
  'o.jobs_within':       '{n} jobs within {r} miles',
  'o.job_within':        '{n} job within {r} miles',
  'o.all_services':      'All services',
  'o.within':            'Within {n} mi',
  'o.paid_job':          'Paid job',
  'o.to_you':            'to you',
  'o.accept':            'Accept',
  'o.miles':             'miles',
  'o.left':              'left',
  'o.nothing':           'Nothing here right now',
  'o.nothing_sub':       'New jobs appear here the moment a customer requests one.',
  'o.nothing_mine':      'Jobs you take will show up here.',
  'o.enroute':           'En route',
  'o.onscene':           'On scene',
  'o.towing':            'Towing',
  'o.complete':          'Complete',
  'o.accept_job':        'Accept this job',
  'o.eta_min':           'ETA (minutes)',
  'o.you_receive':       'You receive',
  'o.less_fee':          '({total} less {fee} platform fee)',
  'o.cancel':            'Cancel',
  'o.signin':            'Sign in',
  'o.email':             'Email',
  'o.password':          'Password',
  'o.no_account':        "Don't have an account?",
  'o.signup_link':       'Sign up your tow company',

  'o.breakdown':         'See price breakdown',
  'o.job_total':         'Job total',
  'o.platform_fee':      'Platform fee',
  'o.you_get':           'You receive',
  'o.high_demand':       'high demand',

  'o.verified_h':        'Verified account',
  'o.verified_p':        'Your company is approved. Customers see your verified badge when you take a job.',
  'o.review_h':          'Your account is under review',
  'o.review_p':          'We have your registration and we are reviewing your company. You can browse jobs in the meantime. If we need your EIN or any documents we will ask you directly — nothing to upload yet.',
  'o.unverified_h':      'Your company is under review',
  'j.docs_optional':     'Upload documents now (optional)',
  'j.docs_fix':          'Documents that need fixing',
  'o.unverified_p':      'Until your company is approved you can browse jobs but not accept them or get paid. If you want to speed it up you can upload your documents below — optional.',
  'o.rejected_p':        'Your verification was rejected. Check the documents flagged below and upload them again.',
  'o.job_yours':         'Job is yours.',
  'o.completed_msg':     'Completed. {amount} is on its way to your bank.',
  'o.flag_nokeys':       'No keys',
  'o.flag_wheels':       'Wheels locked',
  'o.flag_accident':     'Accident',
  'o.flag_under':        'Underground',
  'o.flag_ev':           'EV',
  'o.flag_flatbed':      'Flatbed',
  'o.mi_tow':            '{n} mi tow',
  'o.cannot_accept':     'Cannot accept jobs:',

  /* push alerts */
  'o.alerts':            'Alerts',
  'p.off_h':             'Turn on job alerts',
  'p.off_p':             'A tow job is gone in about 20 minutes. Without alerts you will hear about it too late.',
  'p.enable':            'Turn on alerts',
  'p.enabling':          'Turning on…',
  'p.ios_h':             'Install the app to get alerts',
  'p.ios_p':             'On iPhone, alerts only work once you add this page to your Home Screen. Takes 20 seconds, once.',
  'p.ios_1':             'Tap the Share button at the bottom of Safari',
  'p.ios_2':             'Choose “Add to Home Screen”',
  'p.ios_3':             'Open TowSling from the new icon, then turn on alerts',
  'p.ios_fine':          'This is Apple’s rule, not ours. Safari will not let a website even ask for alert permission until it has been added to the Home Screen.',
  'p.blocked_h':         'Alerts are blocked on this device',
  'p.blocked_p':         'Permission was denied earlier. Go to Settings → TowSling → Notifications and switch them on — the browser will not ask again on its own.',
  'p.unsupported_h':     'This browser cannot do alerts',
  'p.unsupported_p':     'Open the board in Safari (iPhone) or Chrome (Android) to get jobs on your phone.',
  'p.err_unsupported':   'This browser cannot do alerts.',
  'p.err_sw':            'Could not start the alert service. Reload the page.',
  'p.err_denied':        'You did not allow notifications. Without permission we cannot tell you about jobs.',
  'p.err_server':        'The server could not register this phone. Try again.',
  'p.this_phone':        'This device',
  'p.st_on':             'Alerts on',
  'p.st_ready':          'Alerts off',
  'p.st_ios':            'Needs adding to the Home Screen',
  'p.st_blocked':        'Blocked in phone settings',
  'p.st_unsupported':    'Not supported in this browser',
  'p.send_test':         'Send a test alert',
  'p.turn_off':          'Turn off on this device',
  'p.testing':           'Sending…',
  'p.test_ok':           'Sent to {n} phone(s). If nothing arrives within 30 seconds, check Notifications in Settings.',
  'p.test_fail':         'Could not send.',
  'p.when_h':            'When to alert you',
  'p.pref_enabled':      'Alert me about new jobs',
  'p.pref_radius':       'Only alert me within (miles)',
  'p.pref_radius_hint':  'Leave blank to use your service radius ({n} miles). Useful for taking jobs further out without being woken for them.',
  'p.pref_min':          'Do not alert me under ($)',
  'p.pref_min_hint':     'Based on what you take home, after the platform fee. Leave 0 to get everything.',
  'p.pref_quiet_from':   'Quiet from',
  'p.pref_quiet_to':     'Quiet until',
  'p.pref_tz':           'Your timezone',
  'p.pref_tz_hint':      'Quiet hours are worked out in YOUR local time, not the server’s.',
  'p.pref_247':          'Your profile says 24/7, so quiet hours do not apply.',
  'p.save':              'Save',
  'p.saved':             'Saved',
  'p.save_fail':         'Could not save.',
  'p.devices_h':         'Registered devices',
  'p.no_devices':        'No device has alerts turned on yet.',
  'p.dev_ok':            'Working',
  'p.dev_untested':      'Registered, never tested',
  'p.dev_not_installed': 'Will receive nothing — not added to the Home Screen',
  'p.dev_stopped':       'Stopped responding',
  'p.dev_browser':       'browser',
  'p.dev_last':          'last delivery {when}',
  'p.dev_never':         'no deliveries yet',

  /* driver location sharing */
  'd.on_h':              'Sharing your location with the customer',
  'd.on_waiting':        'Getting your location…',
  'd.on_last':           'Last update {n} seconds ago',
  'd.off_h':             'Not sharing your location',
  'd.off_p':             'The customer cannot see where your truck is. It is only ever shared while a job is live.',
  'd.turn_on':           'Share',
  'd.turn_off':          'Stop sharing',
  'd.denied_h':          'Your browser blocked location access',
  'd.denied_p':          'Turn it on in browser settings so the customer can watch the truck approach.',
  'd.yard_h':            'Your yard location',
  'd.yard_p':            'This decides which jobs you are offered. Set it once while you are at your yard or base and it stays saved.',
  'd.yard_btn':          '📍 Use my current location',
  'd.yard_set':          'Saved',
  'd.yard_unset':        'Not set',
  'd.yard_finding':      'Getting your location…',
  'd.yard_saved':        'Done — we now use your yard to find jobs near you.',
  'd.yard_nogps':        'Could not get your location. Turn it on in browser settings.',
  'svc.tow':             'Tow',
  'svc.winch_recovery':  'Recovery',
  'svc.lockout':         'Lockout',
  'svc.jumpstart':       'Jump start',
  'svc.tire_change':     'Tire',
  'svc.fuel_delivery':   'Fuel',
  'svc.impound':         'Impound',
  'svc.transport':       'Transport',

  'j.hero_1':            'Real tow jobs.',
  'j.hero_2':            'Already paid for.',
  'j.hero_3':            'Sent to your phone.',
  'j.hero_sub':          'Customers request a tow, we price it, you get the alert. Accept it and go. No bidding wars, no chasing invoices, no motor club paperwork.',
  'j.cta':               'Sign up your company',
  'j.free':              'Nationwide · free to join',
  'j.p1_n':              '90%',
  'j.p1_h':              'of every job is yours',
  'j.p1_p':              "We keep 10%. That's it — no monthly fee, no per-lead charge, no sign-up cost.",
  'j.p2_n':              'Prepaid',
  'j.p2_h':              'before you ever see it',
  'j.p2_p':              "The customer's card is authorised before the job hits your screen. You are never chasing a payment.",
  'j.p3_n':              'Yours',
  'j.p3_h':              'to take or leave',
  'j.p3_p':              'You see the price and the distance up front. Not interested? Ignore it. Nothing is forced on you.',
  'j.how':               'How it works',
  'j.how_sub':           'Four steps, and you are only involved in two of them.',
  'j.s1_h':              'Job comes in',
  'j.s1_p':              'A stranded driver requests a tow and pays the price we quote.',
  'j.s2_h':              'You get the alert',
  'j.s2_p':              'Service, location, distance and exactly what it pays you.',
  'j.s3_h':              'You accept',
  'j.s3_p':              'Customer name, number and address unlock. Go do the job.',
  'j.s4_h':              'You get paid',
  'j.s4_p':              'Mark it complete. Money goes to your bank automatically.',
  'j.plain_h':           'Straight answers, since you have heard this pitch before',
  'j.plain_1':           'You keep 90% of the job. Our 10% is the only thing we take.',
  'j.plain_2':           "The money is authorised on the customer's card before you are offered the job.",
  'j.plain_3':           'You see the pay and the distance before you accept. No surprises.',
  'j.plain_4':           'Drove out and the car was gone? You are paid a call-out fee, not nothing.',
  'j.plain_5':           'Payouts go to your bank through Stripe. You keep your own books.',
  'j.plain_6':           'No monthly subscription.',
  'j.plain_7':           'No charge for leads you did not take.',
  'j.plain_8':           'No exclusivity. Keep every motor club and account you already run.',
  'j.form_h':            'Sign up your company',
  'j.form_sub':          'Takes about two minutes. We verify your insurance before your first job.',
  'j.company':           'Company name',
  'j.first':             'Your first name',
  'j.last':              'Last name',
  'j.mobile':            'Mobile number',
  'j.city':              'City',
  'j.email':             'Email',
  'j.password':          'Choose a password',
  'j.ph_password':       'At least 8 characters',
  'j.what_run':          'What can you run?',
  'j.cap_light':         'Light duty',
  'j.cap_flatbed':       'Flatbed',
  'j.cap_wheel':         'Wheel lift',
  'j.cap_medium':        'Medium duty',
  'j.cap_heavy':         'Heavy duty',
  'j.cap_winch':         'Winch / recovery',
  'j.cap_lockout':       'Lockouts',
  'j.cap_jump':          'Jump starts',
  'j.cap_tire':          'Tire changes',
  'j.cap_moto':          'Motorcycles',
  'j.cap_247':           'Open 24/7',
  'j.how_far':           "How far will you travel?",
  'j.upto':              'Up to {n} miles',
  'j.how_many':          'How many trucks?',
  'j.more_than':         'More than 10',
  'j.create':            'Create my account',
  'j.creating':          'Creating your account…',
  'j.fine':              'Free to join. We take 10% of jobs you complete — nothing else, ever.',
  'j.fine2':             'You will need a current liability insurance certificate before your first job.',
  'j.done_h':            "You're in.",
  'j.done_p':            'Send us your liability insurance certificate and connect your bank account, and you can start taking jobs. You can look at the board right now.',
  'j.open_board':        'Open your job board →',
  'j.err_company':       'What is your company called?',
  'j.err_name':          'We need your name so drivers and customers know who they are dealing with.',
  'j.err_email':         'That email does not look right.',
  'j.err_password':      'Your password needs to be at least 8 characters.',
  'j.err_phone':         'We need a working mobile number — that is where job alerts go.',

  'j.ein':               'EIN',
  'j.state':             'State',
  'j.use_gps':           '📍 Use my yard location',
  'j.gps_finding':       'Finding your location…',
  'j.gps_found':         '📍 Location saved',
  'j.err_gps':           "We couldn't get your location. We'll use the state you picked.",
  'j.err_ein':           'An EIN is 9 digits.',
  'j.err_terms':         'Tick the Terms of Service box to continue.',
  'j.accept_terms':      'I accept the <a href="terms?doc=tower" target="_blank">Terms of Service for towing companies</a> and the <a href="terms?doc=privacy" target="_blank">Privacy Policy</a>.',

  'j.docs_h':            'Upload your documents',
  'j.docs_sub':          'We need these four to verify your company. A photo from your phone is fine — they are stored privately and only our review team sees them.',
  'j.doc_upload':        'Upload',
  'j.doc_replace':       'Replace',
  'j.doc_received':      'Received — under review',
  'j.doc_missing':       'Missing',
  'j.doc_rejected':      'Rejected',
  'j.doc_expiry':        'Policy expiry date',
  'j.err_expiry':        'Enter the policy expiry date before uploading.',
  'j.docs_waiting':      'Once all four are in, your account goes to review. You can browse the board meanwhile, but not accept jobs yet.',
  'j.docs_submitted':    'All documents in. Your account is under review — we will let you know the moment it is approved and your verified badge goes live.'
}
};

/* Spanish unless this browser has explicitly chosen English. */
/* Language priority, highest first:
     1. ?lang= in the URL      — lets an ad campaign land in a chosen language
     2. what this visitor chose last time, which is never overridden by a guess
     3. a suggestion from the server: Spanish in the Miami market, English
        elsewhere; see includes/geo.php
     4. the browser's own preference
     5. English
   Steps 3 and 4 are resolved once, asynchronously, and cached — so the first
   paint never waits on a network call, and a returning visitor never does the
   lookup at all. */
function tlLang() {
  const q = new URLSearchParams(location.search).get('lang');
  if (q === 'en' || q === 'es') { localStorage.setItem('tl_lang', q); return q; }

  const chosen = localStorage.getItem('tl_lang');
  if (chosen === 'en' || chosen === 'es') return chosen;

  const suggested = localStorage.getItem('tl_lang_suggested');
  if (suggested === 'en' || suggested === 'es') return suggested;

  // Nothing known yet. Use the browser now so the page renders immediately;
  // tlResolveLanguage() may refine it a moment later.
  return (navigator.languages || [navigator.language || 'en'])
           .some(l => String(l).toLowerCase().startsWith('es')) ? 'es' : 'en';
}

/* Ask the server once which language this visitor should see. Only ever writes
   a SUGGESTION — an explicit choice is never overwritten by geography, because
   nothing is more irritating than a site that keeps switching back. */
async function tlResolveLanguage() {
  if (localStorage.getItem('tl_lang')) return;            // they chose already
  if (localStorage.getItem('tl_lang_suggested')) return;  // asked before

  let r;
  try {
    r = await fetch('api/geo/lang').then(x => x.json());
  } catch (e) { return; }
  if (!r || !r.success || (r.lang !== 'en' && r.lang !== 'es')) return;

  const before = tlLang();
  localStorage.setItem('tl_lang_suggested', r.lang);

  // Reload rather than re-translate. tlApply() only touches elements carrying a
  // data-i18n attribute, and by this point the board or the tracking screen has
  // rendered a lot of text from JavaScript that it would leave in the old
  // language. This happens at most once per browser — the suggestion is stored
  // above, so the reloaded page takes this branch's early return.
  if (r.lang !== before) location.reload();
}

function t(key, params) {
  const lang = tlLang();
  let s = (TL_STRINGS[lang] && TL_STRINGS[lang][key]) || TL_STRINGS.es[key];

  // Neither language has it. That means this file is older than the page that
  // referenced the key — the exact shape of a stale cached asset. Returning the
  // raw key put `c.tl_towing` on a customer's screen, so fall back to something
  // a human can read, and complain loudly enough that it is findable.
  if (s === undefined) {
    if (typeof console !== 'undefined' && console.warn) {
      console.warn('[i18n] missing key: ' + key + ' — this file may be a stale cached copy');
    }
    s = String(key).split('.').pop().replace(/_/g, ' ');
    s = s.charAt(0).toUpperCase() + s.slice(1);
  }

  if (params) for (const k in params) s = s.split('{' + k + '}').join(params[k]);
  return s;
}

function tlApply(root) {
  const scope = root || document;
  scope.querySelectorAll('[data-i18n]').forEach(el => { el.textContent = t(el.dataset.i18n); });
  scope.querySelectorAll('[data-i18n-html]').forEach(el => { el.innerHTML = t(el.dataset.i18nHtml); });
  scope.querySelectorAll('[data-i18n-ph]').forEach(el => { el.placeholder = t(el.dataset.i18nPh); });
  document.documentElement.lang = tlLang();
  const btn = document.getElementById('langBtn');
  if (btn) btn.textContent = t('lang.other');
}

/* One tap swaps the whole interface, including anything already rendered. */
function tlToggle() {
  // Written to tl_lang, not tl_lang_suggested: from here on this visitor has
  // decided, and no geo lookup gets to argue with them.
  localStorage.setItem('tl_lang', tlLang() === 'es' ? 'en' : 'es');
  location.reload();
}

/* Appended to every API call so server messages match the page language. */
function tlQuery(sep) {
  return (sep || '?') + 'lang=' + tlLang();
}

document.addEventListener('DOMContentLoaded', () => {
  tlApply();
  // Fire and forget. The page is already painted in the browser's best guess;
  // this only steps in when the server knows better.
  tlResolveLanguage();
});
