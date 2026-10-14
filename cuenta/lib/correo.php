<?php
/* ===================================================================
   Los correos que el portal necesita escribir por su cuenta: recuperar
   la contraseña y el aviso de «tienes algo». Salen al momento por el
   Gmail de hola@ (API de Gmail, solo enviar); si eso falla, van a la
   cola y los manda el Mac (ver adr_manda).

   Lo que este correo NO dice, y es deliberado: no nombra a nadie, no
   dice «tu terapeuta», no menciona cuestionarios ni sesiones. Un
   correo se previsualiza en la pantalla bloqueada de un móvil que a
   veces mira otra persona. Dice lo justo para que quien lo espera
   sepa qué es, y nada para quien no lo espera.
   =================================================================== */

/** El aviso de «tienes algo pendiente».
 *
 *  Es la pieza más aburrida y la que más cambia si las cosas se hacen
 *  o no. Un cuestionario asignado del que nadie se entera es un
 *  cuestionario que no existe: hasta hoy, el portal avisaba a nadie.
 *
 *  Y NO DICE QUÉ. Ni el instrumento, ni el título del documento, ni
 *  una palabra de clínica. Un correo se previsualiza en la pantalla
 *  bloqueada de un móvil que a veces mira otra persona. Dice que hay
 *  algo y dónde verlo; el qué está detrás de su contraseña.
 */
function adr_correo_aviso(array $ADR, string $a, ?string $cod = null): array {
    $texto = "Tienes algo esperándote en tu cuenta de adricamente.\n\n"
        . $ADR['sitio'] . "\n\n"
        . "Si te viene mal ahora, no pasa nada: se queda ahí.\n\n"
        . "Esto no es un canal de urgencias. Si estás en peligro ahora "
        . "mismo, llama al 024 o al 112.\n";
    $html = adr_correo_t('Tienes algo en tu cuenta')
        . adr_correo_p('Te espera algo nuevo en tu cuenta de adricamente. Entra cuando te venga bien.')
        . adr_correo_boton($ADR['sitio'], 'Entrar en mi cuenta')
        . adr_correo_p('Si te viene mal ahora, no pasa nada: se queda ahí.', true);
    return adr_manda($ADR, $a, 'Tienes algo en tu cuenta', $texto,
        adr_correo_marco('Tienes algo en tu cuenta', $html, 'Te espera algo nuevo en tu cuenta. Entra cuando te venga bien.', true),
        'aviso', $cod);
}

function adr_correo_reset(array $ADR, string $a, string $enlace, int $horas = 2): array {
    $vale = $horas >= 24 ? 'un día' : ($horas === 2 ? 'dos horas' : "$horas horas");
    $texto = "Has pedido una contraseña nueva para tu cuenta en "
        . "adricamente.com.\n\n$enlace\n\n"
        . "El enlace vale $vale y una sola vez.\n\n"
        . "Si no has sido tú, no hace falta que hagas nada: sin abrir "
        . "ese enlace no cambia nada.\n\n"
        . "Un aviso importante: al poner una contraseña nueva, los "
        . "documentos que ya tuvieras dentro tardan un rato en volver "
        . "a aparecer. No se pierden.\n";

    $html = adr_correo_t('Tu contraseña nueva')
        . adr_correo_p('Has pedido una contraseña nueva para tu cuenta en adricamente.com.')
        . adr_correo_boton($enlace, 'Poner una contraseña nueva')
        . adr_correo_p('El enlace vale ' . $vale . ' y una sola vez.', true)
        . adr_correo_p('Si no has sido tú, no hace falta que hagas nada: sin abrir ese enlace no cambia nada.')
        . adr_correo_p('<strong>Un aviso importante:</strong> al poner una contraseña nueva, los documentos que ya '
                       . 'tuvieras dentro tardan un rato en volver a aparecer. No se pierden.');
    $html = adr_correo_marco('Tu contraseña de adricamente', $html, 'El enlace para poner una contraseña nueva vale ' . $vale . '.', true);

    return adr_manda($ADR, $a, 'Tu contraseña de adricamente', $texto, $html, 'reset');
}

/** Por dónde sale un correo del portal.
 *  'google': lo manda el propio servidor, al momento, por la API de Gmail
 *            de hola@ (solo permiso de ENVIAR). Si falla, va a la cola.
 *  'cola':   lo deja en la cola y lo manda el Mac en su pasada.
 *  'prueba': en las pruebas (además se escribe a un fichero). */
function adr_via(array $ADR): string {
    if (!empty($ADR['correo_carpeta']) && empty($ADR['google_prueba'])) return 'prueba';
    return adr_google_cred($ADR) ? 'google' : 'cola';
}

/** Manda un correo del portal.
 *
 *  Historia: hasta el 08-10 había Brevo y, sin Brevo, el `mail()` del
 *  alojamiento, que decía «ok» y no salía nada. El 09-10 se quitó todo
 *  envío del servidor y se dejó una cola que vaciaba el Mac. Pero un
 *  «he olvidado mi contraseña» tiene que llegar AL MOMENTO, esté el Mac
 *  encendido o no (13-10: una paciente no podía entrar).
 *
 *  Ahora, si hay credencial de Google (de hola@, solo `gmail.send`), lo
 *  manda el servidor por la API de Gmail y solo dice «enviado» cuando
 *  Gmail devuelve el identificador del mensaje. Queda en «Enviados» de
 *  hola@ y las respuestas llegan a hola@, como si lo mandara el Mac. Si
 *  Gmail falla, no se pierde: va a la cola y lo manda el Mac.
 *
 *  Devuelve ['enviado' => true, 'message_id' => …, 'via' => 'google']
 *  o ['enviado' => false, 'error' => 'en_cola', 'cola_id' => n, …]. */
function adr_manda(array $ADR, string $a, string $asunto, string $texto, string $html,
                   string $tipo = 'aviso', ?string $cod = null, string $nombre = 'adricamente'): array {
    /* Freno: que un bucle o una cuenta robada no use hola@ para mandar en
       masa. Como mucho 6 por hora a la misma dirección y 60 por hora en
       total. Lo frenado no se manda ni se encola, y queda apuntado. */
    $db = adr_db($ADR);
    if (!adr_freno($db, 'correo:' . strtolower($a), 6, 3600) || !adr_freno($db, 'correo:todo', 60, 3600)) {
        error_log('adr: correo frenado (' . $tipo . ')');
        return adr_correo_apunta($ADR, ['enviado' => false, 'message_id' => null, 'error' => 'freno'], 429, $tipo);
    }
    $r = null;
    if (adr_via($ADR) === 'google') {
        $r = adr_google_envia($ADR, $a, $asunto, $texto, $html, $nombre);
        adr_correo_apunta($ADR, $r, (int)($r['codigo'] ?? 0), (string)($r['detalle'] ?? ''));
        if (!$r['enviado']) {
            $c = adr_correo_encola($ADR, $a, $asunto, $texto, $html, $tipo, $cod);
            $r = $c + ['fallo_google' => $r['error']];
        }
    } else {
        $r = adr_correo_encola($ADR, $a, $asunto, $texto, $html, $tipo, $cod);
    }
    if (!empty($ADR['correo_carpeta'])) {
        @mkdir($ADR['correo_carpeta'], 0700, true);
        file_put_contents($ADR['correo_carpeta'] . '/' . microtime(true) . '.json',
            json_encode(['a' => $a, 'asunto' => $asunto, 'texto' => $texto, 'html' => $html,
                         'tipo' => $tipo, 'cola' => $r], JSON_UNESCAPED_UNICODE));
    }
    return $r;
}

/* --- Google (Gmail API, solo enviar) ----------------------------------
   La credencial puede venir de dos sitios:
   · adr-config.php: 'google' => ['client_id','client_secret','refresh_token','remite']
     o 'google' => ['cuenta_servicio' => '/ruta/fuera/de/public_html.json', 'remite']
   · la que sube el Mac con `correo_google` (refresh token de hola@ con
     permiso gmail.send), guardada CIFRADA en la base de datos con una
     llave que sale del secreto de adr-config.php. */

/* La credencial se guarda cifrada con AES-256-GCM (openssl), con una
   llave que sale del secreto de adr-config.php. No usa la extensión
   sodium: el 13-10 «correo_google» dio un 500 mudo y es posible que el
   PHP de Hostinger no la tenga. */
function adr_google_llave(array $ADR): string {
    return hash_hmac('sha256', 'adr-correo-google', (string)($ADR['secreto'] ?? ''), true);
}
function adr_cifra_ajuste(array $ADR, string $claro): string {
    $iv = random_bytes(12);
    $c = openssl_encrypt($claro, 'aes-256-gcm', adr_google_llave($ADR), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) throw new RuntimeException('openssl_encrypt ha fallado');
    return base64_encode($iv . $tag . $c);
}
function adr_descifra_ajuste(array $ADR, string $b64): ?string {
    $b = base64_decode($b64, true);
    if ($b === false || strlen($b) < 29) return null;
    $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', adr_google_llave($ADR), OPENSSL_RAW_DATA,
                         substr($b, 0, 12), substr($b, 12, 16));
    return $r === false ? null : $r;
}

function adr_google_cred(array $ADR): ?array {
    static $cache = [];
    $k = $ADR['datos'] ?? '';
    if (array_key_exists($k, $cache)) return $cache[$k];
    $c = null;
    if (!empty($ADR['google']) && is_array($ADR['google'])) {
        $c = $ADR['google'];
    } else {
        try {
            $f = adr_db($ADR)->query("SELECT valor FROM ajustes WHERE clave = 'google_cred'")->fetch();
            if ($f) {
                $claro = adr_descifra_ajuste($ADR, (string)$f['valor']);
                $c = $claro !== null ? json_decode($claro, true) : null;
            }
        } catch (Throwable $e) { $c = null; }
    }
    return $cache[$k] = ($c ?: null);
}

function adr_google_guarda(array $ADR, ?array $cred): void {
    $db = adr_db($ADR);
    if ($cred === null) {
        $db->exec("DELETE FROM ajustes WHERE clave IN ('google_cred','google_token')");
        return;
    }
    $v = adr_cifra_ajuste($ADR, json_encode($cred));
    $db->prepare("INSERT INTO ajustes (clave, valor, puesto) VALUES ('google_cred', ?, ?)
                  ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor, puesto = excluded.puesto")
       ->execute([$v, date('c')]);
    $db->exec("DELETE FROM ajustes WHERE clave = 'google_token'");
}

function adr_http(string $url, array $cabeceras, string $cuerpo): array {
    if (function_exists('curl_init')) {
        $h = curl_init($url);
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo,
            CURLOPT_HTTPHEADER => $cabeceras, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15]);
        $r = curl_exec($h);
        $c = (int)curl_getinfo($h, CURLINFO_HTTP_CODE);
        $e = curl_error($h);
        curl_close($h);
        return [$c, $r === false ? $e : (string)$r];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $cabeceras),
        'content' => $cuerpo, 'timeout' => 15, 'ignore_errors' => true]]);
    $r = @file_get_contents($url, false, $ctx);
    $c = 0;
    foreach ($http_response_header ?? [] as $l) if (preg_match('#^HTTP/\S+ (\d{3})#', $l, $m)) $c = (int)$m[1];
    return [$c, (string)$r];
}

function adr_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** Un token de acceso de Gmail (vale una hora; se guarda hasta entonces). */
function adr_google_token(array $ADR, array $cred, bool $nuevo = false): array {
    $db = adr_db($ADR);
    if (!$nuevo) {
        $f = $db->query("SELECT valor FROM ajustes WHERE clave = 'google_token'")->fetch();
        $t = $f ? json_decode($f['valor'], true) : null;
        if ($t && ($t['caduca'] ?? 0) > time() + 60) return ['token' => $t['token']];
    }
    $uri = $ADR['google_token_uri'] ?? 'https://oauth2.googleapis.com/token';
    if (!empty($cred['cuenta_servicio'])) {
        $sa = json_decode((string)@file_get_contents($cred['cuenta_servicio']), true);
        if (!$sa || empty($sa['private_key'])) return ['error' => 'cuenta_servicio_ilegible'];
        $ahora = time();
        $jwt = adr_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.'
             . adr_b64url(json_encode(['iss' => $sa['client_email'], 'sub' => $cred['remite'],
                 'scope' => 'https://www.googleapis.com/auth/gmail.send', 'aud' => $uri,
                 'iat' => $ahora, 'exp' => $ahora + 3600]));
        if (!openssl_sign($jwt, $firma, $sa['private_key'], OPENSSL_ALGO_SHA256)) return ['error' => 'firma_jwt'];
        $cuerpo = http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                                    'assertion' => $jwt . '.' . adr_b64url($firma)]);
    } else {
        $cuerpo = http_build_query(['grant_type' => 'refresh_token', 'client_id' => $cred['client_id'] ?? '',
                                    'client_secret' => $cred['client_secret'] ?? '',
                                    'refresh_token' => $cred['refresh_token'] ?? '']);
    }
    [$c, $r] = adr_http($uri, ['Content-Type: application/x-www-form-urlencoded'], $cuerpo);
    $j = json_decode($r, true);
    if ($c !== 200 || empty($j['access_token'])) {
        return ['error' => 'token', 'codigo' => $c, 'detalle' => substr((string)($j['error'] ?? $r), 0, 160)];
    }
    $db->prepare("INSERT INTO ajustes (clave, valor, puesto) VALUES ('google_token', ?, ?)
                  ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor, puesto = excluded.puesto")
       ->execute([json_encode(['token' => $j['access_token'], 'caduca' => time() + (int)($j['expires_in'] ?? 3600)]), date('c')]);
    return ['token' => $j['access_token']];
}

function adr_mime(string $remite, string $a, string $asunto, string $texto, string $html,
                  string $nombre = 'adricamente'): string {
    $frontera = 'adr-' . bin2hex(random_bytes(8));
    $cab = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
    /* Un correo con marco propio (empieza por <!doctype) va tal cual; el
       resto, con la letra de siempre. */
    $cuerpo = stripos(ltrim($html), '<!doctype') === 0 ? $html
        : '<div style="font-family:Arial,sans-serif;font-size:16px;line-height:1.5;color:#25231F">' . $html . '</div>';
    return "From: " . $cab($nombre) . " <$remite>\r\n"
         . "To: $a\r\n"
         . "Reply-To: $remite\r\n"
         . "Subject: " . $cab($asunto) . "\r\n"
         . "Date: " . date('r') . "\r\n"
         . "Message-ID: <" . bin2hex(random_bytes(12)) . '@' . (explode('@', $remite)[1] ?? 'adricamente.com') . ">\r\n"
         . "MIME-Version: 1.0\r\n"
         . "Content-Type: multipart/alternative; boundary=\"$frontera\"\r\n\r\n"
         . "--$frontera\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($texto)) . "\r\n"
         . "--$frontera\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($cuerpo)) . "\r\n"
         . "--$frontera--\r\n";
}

/** El marco de los correos «de consulta» (la reserva y el pago): cabecera
 *  con el nombre, tarjeta blanca, firma profesional y pie legal. Tablas y
 *  estilos en línea, que es lo único que respetan Gmail, Outlook y el
 *  correo del iPhone. $previa es la línea que se ve en la bandeja. */
function adr_correo_marco(string $titulo, string $cuerpo, string $previa, bool $discreto = false): string {
    $f = "font-family:'Source Sans 3','Segoe UI',Helvetica,Arial,sans-serif";
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES);
    return '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">'
        . '<title>' . $h($titulo) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#F2F0EB">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $h($previa) . str_repeat('&#847;&zwnj;&nbsp;', 40) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F2F0EB"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">'
        . '<tr><td style="padding:0 4px 16px;' . $f . '">'
        .   ($discreto
              ? '<div style="font-size:19px;font-weight:700;color:#173F58;letter-spacing:-.2px">adricamente</div>'
              : '<div style="font-size:19px;font-weight:700;color:#173F58;letter-spacing:-.2px">Adrián Cuñat</div>'
              . '<div style="font-size:14px;color:#6C6963">Psicólogo · adricamente.com</div>')
        .   '</td></tr>'
        . '<tr><td style="background:#FFFFFF;border:1px solid #E6E4DF;border-radius:14px;padding:30px 28px;' . $f . ';font-size:16px;line-height:1.55;color:#25231F">'
        .   $cuerpo
        . '</td></tr>'
        . '<tr><td style="padding:18px 6px 0;' . $f . ';font-size:12.5px;line-height:1.5;color:#6C6963">'
        .   ($discreto
              ? 'Correo automático de adricamente.com. Si no esperabas este correo, puedes ignorarlo o contestar y lo miro.<br><br>'
              . 'Este correo no es un canal de urgencias. Si estás en peligro ahora mismo, llama al 024 o al 112.<br>'
              . '<a href="https://adricamente.com/privacidad.html" style="color:#6C6963">Privacidad</a>'
              : 'Adrián Cuñat Andrés, psicólogo colegiado nº CM03836, cursando el Máster en Psicología General Sanitaria, '
              . 'en prácticas supervisadas en el centro sanitario Neurococos (Gandia). Los pagos y las facturas son de Clínica Neurococos.<br><br>'
              . 'Este correo no es un canal de urgencias. Si estás en peligro ahora mismo, llama al 024 o al 112.<br>'
              . 'Datos tratados por Clínica Neurococos para gestionar tu cita. Más información en '
              . '<a href="https://adricamente.com/privacidad.html" style="color:#6C6963">adricamente.com/privacidad</a>.')
        . '</td></tr></table></td></tr></table></body></html>';
}

/** Piezas de los correos con marco: título grande, párrafo y nota pequeña. */
function adr_correo_t(string $t): string {
    return '<p style="margin:0 0 14px;font-size:22px;line-height:1.25;font-weight:700;color:#173F58">' . htmlspecialchars($t) . '</p>';
}
function adr_correo_p(string $html, bool $pequeno = false): string {
    return '<p style="margin:0 0 16px' . ($pequeno ? ';font-size:14px;color:#6C6963' : '') . '">' . $html . '</p>';
}

/** Un botón que se ve como botón también en Outlook. */
function adr_correo_boton(string $url, string $texto): string {
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 10px"><tr>'
        . '<td style="border-radius:10px;background:#1F5474">'
        . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;padding:14px 28px;'
        . "font-family:'Source Sans 3','Segoe UI',Helvetica,Arial,sans-serif;font-size:17px;font-weight:700;"
        . 'color:#FFFFFF;text-decoration:none;border-radius:10px">' . htmlspecialchars($texto) . '</a></td></tr></table>';
}

/** Lo manda por la API de Gmail. «Enviado» solo con el id que da Gmail. */
function adr_google_envia(array $ADR, string $a, string $asunto, string $texto, string $html,
                          string $nombre = 'adricamente'): array {
    $cred = adr_google_cred($ADR);
    if (!$cred) return ['enviado' => false, 'message_id' => null, 'error' => 'sin_google'];
    if (!filter_var($a, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $a)) {
        return ['enviado' => false, 'message_id' => null, 'error' => 'destinatario_no_valido'];
    }
    $remite = $cred['remite'] ?? ($ADR['remite'] ?? 'hola@adricamente.com');
    $api = $ADR['google_api'] ?? 'https://gmail.googleapis.com';
    $raw = adr_b64url(adr_mime($remite, $a, $asunto, $texto, $html, $nombre));
    foreach ([false, true] as $nuevo) {
        $t = adr_google_token($ADR, $cred, $nuevo);
        if (empty($t['token'])) {
            return ['enviado' => false, 'message_id' => null, 'error' => 'google_' . $t['error'],
                    'codigo' => $t['codigo'] ?? 0, 'detalle' => $t['detalle'] ?? ''];
        }
        [$c, $r] = adr_http($api . '/gmail/v1/users/me/messages/send',
            ['Authorization: Bearer ' . $t['token'], 'Content-Type: application/json'],
            json_encode(['raw' => $raw]));
        $j = json_decode($r, true);
        if ($c === 200 && !empty($j['id'])) {
            return ['enviado' => true, 'message_id' => $j['id'], 'error' => null, 'via' => 'google'];
        }
        if ($c !== 401) {
            return ['enviado' => false, 'message_id' => null, 'error' => 'google_envio', 'codigo' => $c,
                    'detalle' => substr((string)($j['error']['message'] ?? $r), 0, 160)];
        }
        // 401: el token guardado ya no vale; se pide otro una vez.
    }
    return ['enviado' => false, 'message_id' => null, 'error' => 'google_401', 'codigo' => 401];
}

/** Deja un correo en la cola, sellado a la clave pública del Mac. */
function adr_correo_encola(array $ADR, string $a, string $asunto, string $texto, string $html,
                           string $tipo, ?string $cod): array {
    $db = adr_db($ADR);
    $m = $db->query("SELECT valor FROM ajustes WHERE clave = 'mac_publica'")->fetch();
    $pub = $m ? base64_decode($m['valor'], true) : false;
    if (!function_exists('sodium_crypto_box_seal')) {
        /* Sin la extensión sodium no se puede sellar para el Mac. Se dice
           (estado y estado_correo lo enseñan) en vez de fallar callado. */
        error_log('adr: no se puede encolar un correo: el PHP del servidor no tiene sodium');
        return adr_correo_apunta($ADR, ['enviado' => false, 'message_id' => null, 'error' => 'cola_sin_sodium'], 0,
                                 'el PHP del servidor no tiene la extensión sodium');
    }
    if (!$pub || strlen($pub) !== 32) {
        error_log('adr: no se puede encolar un correo: sin clave pública del Mac');
        return ['enviado' => false, 'message_id' => null, 'error' => 'cola_sin_clave_mac'];
    }
    $claro = json_encode(['v' => 1, 'para' => $a, 'remite' => $ADR['remite'] ?? null, 'asunto' => $asunto,
                          'texto' => $texto, 'html' => $html, 'tipo' => $tipo, 'creado' => date('c')],
                         JSON_UNESCAPED_UNICODE);
    $db->prepare('INSERT INTO correos_cola (cod, tipo, cifrado, creado) VALUES (?,?,?,?)')
       ->execute([$cod, $tipo, sodium_crypto_box_seal($claro, $pub), date('c')]);
    return ['enviado' => false, 'message_id' => null, 'error' => 'en_cola',
            'cola_id' => (int)$db->lastInsertId()];
}

function adr_correo_lee(array $ADR, string $clave): ?array {
    $q = adr_db($ADR)->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $q->execute([$clave]);
    $f = $q->fetch();
    return $f ? (json_decode($f['valor'], true) ?: null) : null;
}

function adr_correo_apunta(array $ADR, array $res, int $codigo = 0, string $texto = ''): array {
    $clave = $res['enviado'] ? 'correo_ultimo_ok' : 'correo_ultimo_error';
    $valor = $res['enviado']
        ? ['fecha' => date('c'), 'ts' => microtime(true), 'message_id' => $res['message_id']]
        : ['fecha' => date('c'), 'ts' => microtime(true), 'codigo' => $codigo, 'error' => $res['error'], 'texto' => $texto];
    adr_db($ADR)->prepare('INSERT INTO ajustes (clave, valor, puesto) VALUES (?,?,?)
                           ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor,
                                                            puesto = excluded.puesto')
        ->execute([$clave, json_encode($valor, JSON_UNESCAPED_UNICODE), date('c')]);
    return $res;
}
