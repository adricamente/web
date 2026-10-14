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
    foreach (['nombre' => 'TEXT', 'cliente' => 'TEXT', 'comision' => 'REAL'] as $col => $tipo) {
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

/** El correo de «sesión reservada · pagar la sesión», al momento. */
function adr_cita_avisa(array $ADR, PDO $db, array $cita): array {
    [$dia, $hora] = adr_dia_hora($cita['inicio']);
    $url = adr_pagos_url($ADR, $cita['token']);
    $texto = "Sesión reservada el $dia a las $hora.\n\n"
        . "Pagar la sesión (" . $cita['importe'] . " €, con tarjeta, Apple Pay o Google Pay):\n$url\n\n"
        . "Si ya la has pagado, no hace falta que hagas nada.\n\n"
        . "Adrián Cuñat · adricamente\n";
    $html = '<p>Sesión reservada el <strong>' . htmlspecialchars($dia) . '</strong> a las <strong>' . $hora . '</strong>.</p>'
        . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;padding:12px 20px;'
        . 'background:#1F5474;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">Pagar la sesión</a></p>'
        . '<p>' . $cita['importe'] . ' €, con tarjeta, Apple Pay o Google Pay.</p>'
        . '<p>Si ya la has pagado, no hace falta que hagas nada.</p>'
        . '<p>Adrián Cuñat · adricamente</p>';
    $r = adr_manda($ADR, $cita['correo'], 'Sesión reservada el ' . $dia, $texto, $html, 'cita', $cita['cod']);
    if ($r['enviado'] || ($r['error'] ?? '') === 'en_cola') {
        $db->prepare('UPDATE citas SET avisado = ? WHERE uid = ?')->execute([date('c'), $cita['uid']]);
    }
    return $r;
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
