<?php
/* ===================================================================
   El cobro con Stripe (14-10).
   -------------------------------------------------------------------
   Adrián reserva cada cita en Cal.com. Cal.com avisa al portal
   (webhook), el portal crea la cita con su enlace de pago y le manda
   al paciente, al momento, «sesión reservada · pagar la sesión». El
   enlace no caduca: al abrirlo se crea (o se reutiliza) una sesión de
   pago de Stripe, que sí caduca a las 24 horas. Stripe avisa al
   portal cuando se paga (webhook, con firma) y el Mac lo lee en
   `estado_pagos`.

   Lo que el servidor sabe de una cita: el correo, la fecha y la hora,
   el importe y si está pagada. Nada clínico. A Stripe solo va el
   correo y {cod_web, reserva_uid, inicio}: nunca el código clínico.

   Las tres claves (la restringida de Stripe, el secreto del webhook de
   Stripe y el del webhook de Cal.com) las pone Adrián desde la consola
   y se guardan cifradas con openssl (Hostinger no tiene sodium).
   =================================================================== */
if (!defined('ADR_DENTRO')) { http_response_code(404); exit; }
require_once __DIR__ . '/correo.php';

function adr_pagos_tabla(PDO $db): void {
    $db->exec('
      CREATE TABLE IF NOT EXISTS citas (
        uid          TEXT PRIMARY KEY,
        cod          TEXT,
        correo       TEXT NOT NULL,
        inicio       TEXT NOT NULL,
        importe      INTEGER NOT NULL,
        estado       TEXT NOT NULL DEFAULT \'activa\',
        token        TEXT NOT NULL UNIQUE,
        checkout_id  TEXT,
        checkout_url TEXT,
        checkout_caduca INTEGER,
        pagado_en    TEXT,
        pago_ref     TEXT,
        reembolsado  INTEGER NOT NULL DEFAULT 0,
        origen       TEXT NOT NULL,
        creado       TEXT NOT NULL,
        avisado      TEXT
      )');
    $db->exec('CREATE INDEX IF NOT EXISTS i_citas_cod ON citas(cod, inicio)');
    /* 14-10, tarde: el nombre del invitado (para el cliente de Stripe: la
       directora ve quién pagó), el cliente de Stripe y la comisión. */
    $hay = array_column($db->query('PRAGMA table_info(citas)')->fetchAll(), 'name');
    foreach (['nombre' => 'TEXT', 'cliente' => 'TEXT', 'comision' => 'REAL', 'lugar' => 'TEXT'] as $col => $tipo) {
        if (!in_array($col, $hay, true)) $db->exec("ALTER TABLE citas ADD COLUMN $col $tipo");
    }
}

/** La configuración de pagos: {stripe_clave, stripe_webhook, cal_secreto,
 *  slug, precio?, importe} descifrada, o null. */
function adr_pagos_cfg(array $ADR): ?array {
    if (array_key_exists('adr_pagos_cfg', $GLOBALS)) return $GLOBALS['adr_pagos_cfg'];
    $f = adr_db($ADR)->query("SELECT valor FROM ajustes WHERE clave = 'pagos_cfg'")->fetch();
    $claro = $f ? adr_descifra_ajuste($ADR, (string)$f['valor']) : null;
    return $GLOBALS['adr_pagos_cfg'] = ($claro ? json_decode($claro, true) : null);
}

function adr_pagos_guarda(array $ADR, array $cfg): void {
    $GLOBALS['adr_pagos_cfg'] = $cfg;
    adr_db($ADR)->prepare("INSERT INTO ajustes (clave, valor, puesto) VALUES ('pagos_cfg', ?, ?)
                           ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor, puesto = excluded.puesto")
        ->execute([adr_cifra_ajuste($ADR, json_encode($cfg)), date('c')]);
}

function adr_pagos_apunta_error(array $ADR, string $que, string $detalle = ''): void {
    error_log("adr pagos: $que $detalle");
    adr_db($ADR)->prepare("INSERT INTO ajustes (clave, valor, puesto) VALUES ('pagos_ultimo_error', ?, ?)
                           ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor, puesto = excluded.puesto")
        ->execute([json_encode(['fecha' => date('c'), 'error' => $que, 'detalle' => mb_substr($detalle, 0, 300)],
                               JSON_UNESCAPED_UNICODE), date('c')]);
}

function adr_pagos_url(array $ADR, string $token): string {
    return rtrim($ADR['sitio'], '/') . '/pagar.php?t=' . $token;
}

function adr_pagos_token(): string {
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

/** «miércoles 22 de octubre» y «18:00», en hora de Madrid. */
function adr_dia_hora(string $iso): array {
    $d = new DateTime($iso);
    $d->setTimezone(new DateTimeZone('Europe/Madrid'));
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
              'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return [$dias[(int)$d->format('w')] . ' ' . (int)$d->format('j') . ' de ' . $meses[(int)$d->format('n') - 1],
            $d->format('H:i')];
}

function adr_inicio_madrid(string $iso): string {
    $d = new DateTime($iso);
    $d->setTimezone(new DateTimeZone('Europe/Madrid'));
    return $d->format('c');
}

/** Crea la cita (o devuelve la que ya hay con ese uid). */
function adr_cita_crea(PDO $db, string $uid, ?string $cod, string $correo, string $inicio, int $importe,
                       string $origen, ?string $nombre = null): array {
    $q = $db->prepare('SELECT * FROM citas WHERE uid = ?');
    $q->execute([$uid]);
    if ($f = $q->fetch()) return $f;
    $db->prepare('INSERT INTO citas (uid, cod, correo, inicio, importe, token, origen, creado, nombre)
                  VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$uid, $cod, $correo, $inicio, $importe, adr_pagos_token(), $origen, date('c'),
                  $nombre !== null ? mb_substr(trim($nombre), 0, 80) : null]);
    $q->execute([$uid]);
    return $q->fetch();
}

/** Llamada a la API de Stripe (formulario). */
function adr_stripe(array $ADR, string $metodo, string $ruta, array $campos = []): array {
    $cfg = adr_pagos_cfg($ADR);
    if (!$cfg || empty($cfg['stripe_clave'])) return [0, ['error' => ['message' => 'sin clave de Stripe']]];
    $base = $ADR['stripe_api'] ?? 'https://api.stripe.com';
    $url = $base . $ruta . ($metodo === 'GET' && $campos ? '?' . http_build_query($campos) : '');
    $h = curl_init($url);
    curl_setopt_array($h, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['stripe_clave'], 'Stripe-Version: 2024-06-20'],
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20]);
    if ($metodo === 'POST') curl_setopt($h, CURLOPT_POSTFIELDS, http_build_query($campos));
    $r = curl_exec($h);
    $c = (int)curl_getinfo($h, CURLINFO_HTTP_CODE);
    $e = curl_error($h);
    curl_close($h);
    return [$c, $r === false ? ['error' => ['message' => $e]] : (json_decode((string)$r, true) ?: [])];
}

/** El precio «Sesión individual» (30 €, pago único) por su lookup_key.
 *  Si no existe, lo crea (y el producto, o reutiliza uno con ese nombre).
 *  Idempotente. Devuelve [price_id, error]. */
function adr_stripe_precio(array $ADR, int $importe = 30): array {
    [$c, $j] = adr_stripe($ADR, 'GET', '/v1/prices', ['lookup_keys' => ['sesion_individual'], 'active' => 'true', 'limit' => 1]);
    if ($c === 200 && !empty($j['data'][0]['id'])) return [$j['data'][0]['id'], null];
    if ($c !== 200) return [null, $j['error']['message'] ?? ('HTTP ' . $c)];
    $prod = null;
    [$c, $j] = adr_stripe($ADR, 'GET', '/v1/products', ['active' => 'true', 'limit' => 100]);
    foreach (($c === 200 ? ($j['data'] ?? []) : []) as $p) {
        if (trim((string)($p['name'] ?? '')) === 'Sesión individual') { $prod = $p['id']; break; }
    }
    if (!$prod) {
        [$c, $j] = adr_stripe($ADR, 'POST', '/v1/products', ['name' => 'Sesión individual']);
        if ($c !== 200 || empty($j['id'])) return [null, $j['error']['message'] ?? ('HTTP ' . $c)];
        $prod = $j['id'];
    }
    [$c, $j] = adr_stripe($ADR, 'POST', '/v1/prices', ['product' => $prod, 'currency' => 'eur',
        'unit_amount' => $importe * 100, 'lookup_key' => 'sesion_individual', 'transfer_lookup_key' => 'true']);
    if ($c !== 200 || empty($j['id'])) return [null, $j['error']['message'] ?? ('HTTP ' . $c)];
    return [$j['id'], null];
}

/** Qué permisos le faltan a la clave restringida. Se pregunta a Stripe
 *  con peticiones que no crean ni cambian nada: una sesión de pago sin
 *  artículos, o actualizar un objeto que no existe. Si la clave tiene el
 *  permiso, Stripe contesta 400 o 404 (petición mala); si no, 403
 *  «does not have the required permissions». Devuelve la lista de lo
 *  que falta, en palabras del panel de Stripe. */
function adr_stripe_permisos(array $ADR): array {
    $pruebas = [
        ['Checkout Sessions (Escritura)', 'POST', '/v1/checkout/sessions', ['mode' => 'payment']],
        ['Customers (Escritura)', 'POST', '/v1/customers/cus_adr_prueba_permisos', ['name' => 'x']],
        ['Products (Escritura)', 'POST', '/v1/products/prod_adr_prueba_permisos', ['name' => 'x']],
        ['Prices (Escritura)', 'POST', '/v1/prices/price_adr_prueba_permisos', ['nickname' => 'x']],
        ['PaymentIntents (Lectura)', 'GET', '/v1/payment_intents', ['limit' => 1]],
        ['Charges (Lectura)', 'GET', '/v1/charges', ['limit' => 1]],
        ['Balance (Lectura)', 'GET', '/v1/balance_transactions', ['limit' => 1]],
    ];
    $faltan = []; $raro = null;
    foreach ($pruebas as [$nombre, $metodo, $ruta, $campos]) {
        [$c, $j] = adr_stripe($ADR, $metodo, $ruta, $campos);
        $m = (string)($j['error']['message'] ?? '');
        if ($c === 403 || stripos($m, 'required permissions') !== false) $faltan[] = $nombre;
        elseif ($c === 401) return ['clave_valida' => false, 'faltan' => [], 'detalle' => $m ?: 'Stripe no acepta la clave'];
        elseif ($c === 0 && !$raro) $raro = $m ?: 'sin respuesta de Stripe';
    }
    return ['clave_valida' => true, 'faltan' => $faltan, 'detalle' => $raro];
}

/** El cliente de Stripe con el nombre y el correo del invitado: la
 *  directora ve quién pagó, sin ningún código. Se reutiliza por correo. */
function adr_stripe_cliente(array $ADR, PDO $db, array $cita): ?string {
    if (!empty($cita['cliente'])) return $cita['cliente'];
    [$c, $j] = adr_stripe($ADR, 'GET', '/v1/customers', ['email' => $cita['correo'], 'limit' => 1]);
    $id = ($c === 200 && !empty($j['data'][0]['id'])) ? $j['data'][0]['id'] : null;
    if ($id && !empty($cita['nombre']) && empty($j['data'][0]['name'])) {
        adr_stripe($ADR, 'POST', '/v1/customers/' . $id, ['name' => $cita['nombre']]);
    }
    if (!$id) {
        [$c, $j] = adr_stripe($ADR, 'POST', '/v1/customers', array_filter(['email' => $cita['correo'],
            'name' => $cita['nombre'] ?? null]));
        $id = ($c === 200 && !empty($j['id'])) ? $j['id'] : null;
    }
    if ($id) $db->prepare('UPDATE citas SET cliente = ? WHERE uid = ?')->execute([$id, $cita['uid']]);
    return $id;
}

/** «Sesión individual · 15/10/2026 18:00», en hora de Madrid. */
function adr_cita_descripcion(array $cita): string {
    $d = new DateTime($cita['inicio']);
    $d->setTimezone(new DateTimeZone('Europe/Madrid'));
    return 'Sesión individual · ' . $d->format('d/m/Y H:i');
}

/** La comisión de Stripe de un pago (en €), de su balance transaction. */
function adr_stripe_comision(array $ADR, string $pi): ?float {
    if (!str_starts_with($pi, 'pi_')) return null;
    [$c, $j] = adr_stripe($ADR, 'GET', '/v1/payment_intents/' . $pi, ['expand' => ['latest_charge.balance_transaction']]);
    $fee = $j['latest_charge']['balance_transaction']['fee'] ?? null;
    return ($c === 200 && $fee !== null) ? round($fee / 100, 2) : null;
}

/** La sesión de pago de Stripe de una cita: la que hay si sigue viva, o
 *  una nueva. Un pago = una cita: nunca se juntan sesiones. Devuelve
 *  [url, error]. */
function adr_cita_checkout(array $ADR, PDO $db, array $cita): array {
    if ($cita['checkout_url'] && (int)$cita['checkout_caduca'] > time() + 300) return [$cita['checkout_url'], null];
    $cfg = adr_pagos_cfg($ADR) ?: [];
    $vuelta = adr_pagos_url($ADR, $cita['token']);
    $meta = ['cod_web' => (string)($cita['cod'] ?? ''), 'reserva_uid' => $cita['uid'], 'inicio' => $cita['inicio']];
    $desc = adr_cita_descripcion($cita);
    $campos = ['mode' => 'payment', 'locale' => 'es',
               'success_url' => $vuelta . '&ok={CHECKOUT_SESSION_ID}', 'cancel_url' => $vuelta,
               'expires_at' => time() + 23 * 3600, 'metadata' => $meta,
               'payment_intent_data' => ['metadata' => $meta, 'description' => $desc],
               'client_reference_id' => $cita['uid']];
    $cliente = adr_stripe_cliente($ADR, $db, $cita);
    if ($cliente) $campos['customer'] = $cliente; else $campos['customer_email'] = $cita['correo'];
    $precio = $cfg['precio'] ?? null;
    if (!$precio && (int)$cita['importe'] === (int)($cfg['importe'] ?? 30)) {
        [$precio, $e] = adr_stripe_precio($ADR, (int)($cfg['importe'] ?? 30));
        if ($precio) { $cfg['precio'] = $precio; adr_pagos_guarda($ADR, $cfg); }
    }
    if ($precio && (int)$cita['importe'] === (int)($cfg['importe'] ?? 30)) {
        $campos['line_items'] = [['price' => $precio, 'quantity' => 1]];
    } else {
        $campos['line_items'] = [['quantity' => 1, 'price_data' => ['currency' => 'eur',
            'unit_amount' => (int)$cita['importe'] * 100, 'product_data' => ['name' => 'Sesión individual']]]];
    }
    [$c, $j] = adr_stripe($ADR, 'POST', '/v1/checkout/sessions', $campos);
    if ($c !== 200 || empty($j['url'])) {
        $m = $j['error']['message'] ?? ('HTTP ' . $c);
        adr_pagos_apunta_error($ADR, 'stripe_checkout', $m);
        return [null, $m];
    }
    $db->prepare('UPDATE citas SET checkout_id = ?, checkout_url = ?, checkout_caduca = ? WHERE uid = ?')
       ->execute([$j['id'], $j['url'], (int)($j['expires_at'] ?? time() + 23 * 3600), $cita['uid']]);
    return [$j['url'], null];
}

function adr_cita_pagada(PDO $db, string $uid, string $ref, ?string $cuando = null): void {
    $db->prepare('UPDATE citas SET pagado_en = COALESCE(pagado_en, ?), pago_ref = COALESCE(pago_ref, ?) WHERE uid = ?')
       ->execute([$cuando ?: date('c'), $ref, $uid]);
}

/** «online» o «presencial», de lo que manda Cal.com en la reserva. */
function adr_cita_lugar(array $p): ?string {
    $l = strtolower(is_string($p['location'] ?? null) ? $p['location'] : '');
    if (!empty($p['videoCallData']['url']) || preg_match('/meet|video|zoom|daily|integrations:|https?:/', $l)) return 'online';
    return $l !== '' ? 'presencial' : null;
}

/** El correo de «tu sesión está reservada · falta el pago», al momento.
 *  Tiene que parecer lo que es: un correo de una consulta de verdad. Con
 *  el detalle de la cita, quién atiende, por qué Stripe, qué pasa si hay
 *  que cambiarla y a quién escribir. */
function adr_cita_avisa(array $ADR, PDO $db, array $cita): array {
    [$dia, $hora] = adr_dia_hora($cita['inicio']);
    $anio = (new DateTime($cita['inicio']))->setTimezone(new DateTimeZone('Europe/Madrid'))->format('Y');
    $url = adr_pagos_url($ADR, $cita['token']);
    $imp = (int)$cita['importe'];
    $pila = trim(explode(' ', trim((string)($cita['nombre'] ?? '')))[0] ?? '');
    $pila = $pila !== '' ? mb_convert_case(mb_strtolower($pila), MB_CASE_TITLE) : '';
    $lugar = match ($cita['lugar'] ?? null) {
        'online' => 'Videollamada (Google Meet). El enlace está en la invitación de calendario.',
        'presencial' => 'Presencial, en el centro sanitario Neurococos (Gandia).',
        default => null,
    };
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
    $fila = fn($k, $v) => '<tr><td style="padding:9px 0;color:#6C6963;width:92px;vertical-align:top;border-top:1px solid #EEECE7">' . $k
        . '</td><td style="padding:9px 0;vertical-align:top;border-top:1px solid #EEECE7;font-weight:600">' . $v . '</td></tr>';
    $asunto = 'Tu sesión del ' . $dia . ' a las ' . $hora . ': falta el pago';

    $html = '<p style="margin:0 0 14px;font-size:22px;line-height:1.25;font-weight:700;color:#173F58">Tu sesión está reservada</p>'
        . '<p style="margin:0 0 18px">' . ($pila !== '' ? 'Hola, ' . $h($pila) . ':<br>' : '')
        . 'Para dejarla confirmada solo falta el pago. Puedes hacerlo ahora o en cualquier momento antes de la sesión.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:15.5px;line-height:1.4">'
        . $fila('Sesión', 'Sesión individual · 60 minutos')
        . $fila('Fecha', $h(mb_strtoupper(mb_substr($dia, 0, 1)) . mb_substr($dia, 1)) . ' de ' . $anio)
        . $fila('Hora', $hora . ' <span style="font-weight:400;color:#6C6963">(hora peninsular)</span>')
        . ($lugar ? $fila('Dónde', $h($lugar)) : '')
        . $fila('Importe', $imp . ',00 € <span style="font-weight:400;color:#6C6963">(exento de IVA)</span>')
        . '</table>'
        . adr_correo_boton($url, 'Pagar ' . $imp . ' € de forma segura')
        . '<p style="margin:0 0 20px;font-size:14px;color:#6C6963">Con tarjeta, Apple Pay o Google Pay, en la página de pago de Stripe. '
        . 'Nadie aquí ve los datos de tu tarjeta. El recibo te llega por correo.</p>'
        . '<p style="margin:0 0 6px;font-weight:700">Si necesitas cambiarla o cancelarla</p>'
        . '<p style="margin:0 0 18px">Hazlo desde el correo de confirmación de la reserva, o contesta a este correo. '
        . 'Hasta 24 horas antes, sin coste (si ya habías pagado, se te devuelve). Con menos de 24 horas, o si no te presentas, la sesión se cobra, salvo causa justificada.</p>'
        . '<p style="margin:0 0 18px">Si ya la has pagado, no tienes que hacer nada más. Si no has reservado tú esta sesión, contesta a este correo y lo miro.</p>'
        . '<p style="margin:0">Un saludo,</p>'
        . '<p style="margin:4px 0 0"><strong>Adrián Cuñat Andrés</strong><br>'
        . '<span style="color:#6C6963;font-size:14.5px">Psicólogo colegiado nº CM03836<br>'
        . '<a href="mailto:hola@adricamente.com" style="color:#1F5474">hola@adricamente.com</a> · '
        . '<a href="https://adricamente.com" style="color:#1F5474">adricamente.com</a></span></p>';

    $texto = ($pila !== '' ? "Hola, $pila:\n\n" : '')
        . "Tu sesión está reservada. Para dejarla confirmada solo falta el pago. Puedes hacerlo ahora o en cualquier momento antes de la sesión.\n\n"
        . "Sesión: Sesión individual · 60 minutos\n"
        . "Fecha: $dia de $anio\nHora: $hora (hora peninsular)\n"
        . ($lugar ? "Dónde: $lugar\n" : '')
        . "Importe: $imp,00 € (exento de IVA)\n\n"
        . "Pagar $imp € de forma segura (tarjeta, Apple Pay o Google Pay, en Stripe):\n$url\n\n"
        . "Si necesitas cambiarla o cancelarla, hazlo desde el correo de confirmación de la reserva o contesta a este correo. "
        . "Hasta 24 horas antes, sin coste (si ya habías pagado, se te devuelve). Con menos de 24 horas, o si no te presentas, la sesión se cobra, salvo causa justificada.\n\n"
        . "Si ya la has pagado, no tienes que hacer nada más.\n\n"
        . "Un saludo,\nAdrián Cuñat Andrés\nPsicólogo colegiado nº CM03836\nhola@adricamente.com · adricamente.com\n\n"
        . "--\nAdrián Cuñat Andrés, psicólogo colegiado nº CM03836, cursando el Máster en Psicología General Sanitaria, en prácticas supervisadas en el centro sanitario Neurococos (Gandia). "
        . "Los pagos y las facturas son de Clínica Neurococos. Este correo no es un canal de urgencias: si estás en peligro, llama al 024 o al 112.\n";

    $r = adr_manda($ADR, $cita['correo'], $asunto, $texto,
                   adr_correo_marco($asunto, $html, 'Para dejarla confirmada solo falta el pago: ' . $imp . ' €, con tarjeta, Apple Pay o Google Pay.'),
                   'cita', $cita['cod'], 'Adrián Cuñat · Psicólogo');
    if ($r['enviado'] || ($r['error'] ?? '') === 'en_cola') {
        $db->prepare('UPDATE citas SET avisado = ? WHERE uid = ?')->execute([date('c'), $cita['uid']]);
    }
    return $r;
}

/** Si no se puede abrir el pago, Adrián se entera al momento por correo,
 *  con el motivo que da Stripe (nada clínico: ni nombre ni correo del
 *  paciente). Como mucho uno cada media hora. */
function adr_pagos_alerta(array $ADR, PDO $db, string $motivo): void {
    if (!adr_freno($db, 'alerta_pago', 1, 1800)) return;
    $a = (string)((adr_google_cred($ADR) ?: [])['remite'] ?? ($ADR['remite'] ?? 'hola@adricamente.com'));
    $t = "Un paciente ha abierto su enlace de pago y Stripe no ha dejado crear el pago.\n\n"
       . "Motivo que da Stripe:\n$motivo\n\n"
       . "Lo más habitual: a la clave restringida le falta un permiso (en Stripe, Desarrolladores → Claves de API → la clave → Editar). "
       . "Necesita, en Escritura: Checkout Sessions, Customers, Products y Prices; en Lectura: PaymentIntents, Charges y Balance. "
       . "Al guardar, la clave no cambia: no hay que volver a pegarla. En la consola, «Comprobar permisos» dice si falta alguno.\n\n"
       . "Lo verás también en la consola, en «Cobro con Stripe».\n";
    adr_manda($ADR, $a, 'Aviso del portal: un pago no se ha podido abrir', $t,
              '<p>' . nl2br(htmlspecialchars($t)) . '</p>', 'alerta');
}

/** Firma de Stripe: «t=…,v1=…», HMAC-SHA256 de «t.cuerpo», 5 minutos. */
function adr_stripe_firma_ok(string $cabecera, string $cuerpo, string $secreto, int $tolerancia = 300): bool {
    $t = null; $v1 = [];
    foreach (explode(',', $cabecera) as $p) {
        [$k, $v] = array_pad(explode('=', trim($p), 2), 2, '');
        if ($k === 't') $t = (int)$v;
        if ($k === 'v1') $v1[] = $v;
    }
    if (!$t || !$v1 || abs(time() - $t) > $tolerancia) return false;
    $esperado = hash_hmac('sha256', $t . '.' . $cuerpo, $secreto);
    foreach ($v1 as $v) if (hash_equals($esperado, $v)) return true;
    return false;
}
