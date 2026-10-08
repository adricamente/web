<?php
/* ===================================================================
   Los correos que manda el portal: recuperar la contraseña, el aviso
   de «tienes algo», la bienvenida con su cuenta y el recordatorio del
   justificante.
   -------------------------------------------------------------------
   Por la API de Brevo y no por SMTP: en alojamiento compartido los
   puertos de salida suelen estar cerrados, y una petición HTTPS no.
   Además así el fallo se ve —código y cuerpo— en vez de quedarse un
   socket colgado veinte segundos.

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
function adr_correo_aviso(array $ADR, string $a): bool {
    $texto = "Tienes algo esperándote en tu cuenta de adricamente.\n\n"
        . $ADR['sitio'] . "\n\n"
        . "Si te viene mal ahora, no pasa nada: se queda ahí.\n\n"
        . "Esto no es un canal de urgencias. Si estás en peligro ahora "
        . "mismo, llama al 024 o al 112.\n";
    return adr_manda($ADR, $a, 'Tienes algo en tu cuenta', $texto,
        '<p>Tienes algo esperándote en tu cuenta de adricamente.</p>'
        . '<p><a href="' . htmlspecialchars($ADR['sitio'], ENT_QUOTES) . '">Entrar en mi cuenta</a></p>'
        . '<p>Si te viene mal ahora, no pasa nada: se queda ahí.</p>'
        . '<p>Esto no es un canal de urgencias. Si estás en peligro ahora '
        . 'mismo, llama al <strong>024</strong> o al <strong>112</strong>.</p>');
}

function adr_correo_reset(array $ADR, string $a, string $enlace): bool {
    $texto = "Has pedido una contraseña nueva para tu cuenta en "
        . "adricamente.com.\n\n$enlace\n\n"
        . "El enlace vale dos horas y una sola vez.\n\n"
        . "Si no has sido tú, no hace falta que hagas nada: sin abrir "
        . "ese enlace no cambia nada.\n\n"
        . "Un aviso importante: al poner una contraseña nueva, los "
        . "documentos que ya tuvieras dentro tardan un rato en volver "
        . "a aparecer. No se pierden.\n";

    $html = '<p>Has pedido una contraseña nueva para tu cuenta en '
        . 'adricamente.com.</p>'
        . '<p><a href="' . htmlspecialchars($enlace, ENT_QUOTES) . '">'
        . 'Poner una contraseña nueva</a></p>'
        . '<p>El enlace vale dos horas y una sola vez.</p>'
        . '<p>Si no has sido tú, no hace falta que hagas nada: sin abrir '
        . 'ese enlace no cambia nada.</p>'
        . '<p><strong>Un aviso importante:</strong> al poner una contraseña '
        . 'nueva, los documentos que ya tuvieras dentro tardan un rato en '
        . 'volver a aparecer. No se pierden.</p>';

    return adr_manda($ADR, $a, 'Tu contraseña de adricamente', $texto, $html);
}

/* --- La bienvenida: su cuenta y cómo se usa -------------------------
   Sale cuando la persona ha decidido empezar (lo decide el Mac, o
   Adrián desde la consola), desde la dirección de `remite`
   (hola@adricamente.com). Explica lo justo para que entre sola: el
   enlace, la contraseña, qué hay dentro y dónde se sube el
   justificante.

   Las primeras líneas son las que se ven en la pantalla bloqueada, así
   que no dicen nada que no pueda leer cualquiera: «tu cuenta en
   adricamente». Ni diagnósticos, ni cuestionarios por su nombre. */
function adr_correo_bienvenida(array $ADR, string $a, string $enlace, string $nombre = ''): bool {
    $hola = $nombre !== '' ? "Hola, $nombre:" : 'Hola:';
    $volver = rtrim($ADR['sitio'], '/');
    $texto = "$hola\n\n"
        . "Ya tienes tu cuenta en adricamente: un espacio privado para lo que "
        . "necesitamos entre una sesión y la siguiente.\n\n"
        . "Para activarla, abre este enlace y elige tu contraseña:\n$enlace\n\n"
        . "El enlace vale 7 días y una sola vez. Si caduca, pídeme otro.\n\n"
        . "CÓMO EMPEZAR\n"
        . "1. Abre el enlace y elige una contraseña. Mejor una frase de tres o "
        . "cuatro palabras que solo sepas tú que una palabra con símbolos.\n"
        . "2. Lee la información sobre tus datos y acéptala.\n"
        . "3. Ya estás dentro. Para volver otro día: adricamente.com, «Área de "
        . "pacientes», con tu correo y tu contraseña ($volver).\n\n"
        . "QUÉ VAS A ENCONTRAR\n"
        . "- Tareas: lo que te pida antes de cada sesión (documentos para leer y "
        . "firmar, formularios breves). Se rellena ahí mismo y se envía con un "
        . "botón. No hace falta mandarme nada por correo.\n"
        . "- Tu próxima sesión: el día, la hora y el botón de la videollamada, "
        . "que se activa 10 minutos antes.\n"
        . "- Justificante de pago: si pagas por transferencia, sube ahí la foto o "
        . "el PDF del justificante antes de la sesión (en Inicio, «Adjuntar "
        . "transferencia»).\n"
        . "- Tu evolución y lo que has completado, con sus fechas.\n"
        . "- Mensajes: para lo que quieras contarme entre sesiones. Los leo en mi "
        . "horario de consulta, no al momento.\n\n"
        . "SOBRE TU CONTRASEÑA\n"
        . "Lo que hay en tu cuenta va cifrado con ella: el servidor guarda solo "
        . "cosas que no puede leer. Por eso no te la puedo recordar. Si la "
        . "olvidas, puedes poner otra desde «He olvidado mi contraseña»; lo que "
        . "ya tenías dentro tardará un poco en volver a aparecer, pero no se "
        . "pierde.\n\n"
        . "Esto no es un canal de urgencias. Si en algún momento estás en "
        . "peligro, llama al 024 (24 horas, gratuito) o al 112.\n\n"
        . "Si no esperabas este correo, puedes ignorarlo: sin abrir el enlace "
        . "no se activa nada.\n\n"
        . "Un saludo,\nAdrián\nadricamente.com\n";

    $e = fn($x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    $boton = 'display:inline-block;background:#1F5474;color:#ffffff;text-decoration:none;'
           . 'padding:12px 20px;border-radius:8px;font-weight:bold';
    $h3 = 'font-size:15px;margin:22px 0 6px;color:#1F5474';
    $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
        . 'font-size:15px;line-height:1.55;color:#25231F;max-width:560px">'
        . '<p>' . $e($hola) . '</p>'
        . '<p>Ya tienes tu cuenta en adricamente: un espacio privado para lo que '
        . 'necesitamos entre una sesión y la siguiente.</p>'
        . '<p style="margin:22px 0"><a href="' . $e($enlace) . '" style="' . $boton . '">Activar mi cuenta</a></p>'
        . '<p style="font-size:13.5px;color:#5c5a55">El enlace vale 7 días y una sola vez. Si caduca, pídeme otro.</p>'
        . '<h3 style="' . $h3 . '">Cómo empezar</h3>'
        . '<ol style="padding-left:20px;margin:0">'
        . '<li>Abre el enlace y elige una contraseña. Mejor una frase de tres o cuatro palabras que solo sepas tú que una palabra con símbolos.</li>'
        . '<li>Lee la información sobre tus datos y acéptala.</li>'
        . '<li>Ya estás dentro. Para volver otro día: <a href="https://adricamente.com">adricamente.com</a>, «Área de pacientes», con tu correo y tu contraseña.</li>'
        . '</ol>'
        . '<h3 style="' . $h3 . '">Qué vas a encontrar</h3>'
        . '<ul style="padding-left:20px;margin:0">'
        . '<li><strong>Tareas:</strong> lo que te pida antes de cada sesión (documentos para leer y firmar, formularios breves). Se rellena ahí mismo y se envía con un botón. No hace falta mandarme nada por correo.</li>'
        . '<li><strong>Tu próxima sesión:</strong> el día, la hora y el botón de la videollamada, que se activa 10 minutos antes.</li>'
        . '<li><strong>Justificante de pago:</strong> si pagas por transferencia, sube ahí la foto o el PDF del justificante antes de la sesión (en Inicio, «Adjuntar transferencia»).</li>'
        . '<li><strong>Tu evolución</strong> y lo que has completado, con sus fechas.</li>'
        . '<li><strong>Mensajes:</strong> para lo que quieras contarme entre sesiones. Los leo en mi horario de consulta, no al momento.</li>'
        . '</ul>'
        . '<h3 style="' . $h3 . '">Sobre tu contraseña</h3>'
        . '<p>Lo que hay en tu cuenta va cifrado con ella: el servidor guarda solo cosas que no puede leer. Por eso no te la puedo recordar. Si la olvidas, puedes poner otra desde «He olvidado mi contraseña»; lo que ya tenías dentro tardará un poco en volver a aparecer, pero no se pierde.</p>'
        . '<p style="background:#F4F2EC;padding:12px 14px;border-radius:8px">Esto no es un canal de urgencias. Si en algún momento estás en peligro, llama al <strong>024</strong> (24 horas, gratuito) o al <strong>112</strong>.</p>'
        . '<p style="font-size:13.5px;color:#5c5a55">Si no esperabas este correo, puedes ignorarlo: sin abrir el enlace no se activa nada.</p>'
        . '<p>Un saludo,<br>Adrián<br><a href="https://adricamente.com">adricamente.com</a></p>'
        . '</div>';
    return adr_manda($ADR, $a, 'Tu cuenta en adricamente', $texto, $html);
}

/* --- El recordatorio del justificante --------------------------------
   Lo pide el Mac la víspera de una sesión sin justificante. Explica
   dónde se sube, porque quien no lo ha subido a menudo es quien no
   sabe que se puede. Sin fecha ni hora: eso está en su cuenta. */
function adr_correo_justificante(array $ADR, string $a, bool $hay_pendiente = false,
                                 string $limite = ''): bool {
    $sitio = $ADR['sitio'];
    /* Con `limite` (lo calcula el Mac: 24 h antes de la cita), el correo
       dice hasta cuándo: «como muy tarde el jueves 14 de octubre a las
       18:00». Sin él, el texto de siempre. */
    $plazo = '';
    if ($limite !== '' && ($ts = strtotime($limite)) !== false) {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
                  'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone('Europe/Madrid'));
        $plazo = $dias[(int)$d->format('w')] . ' ' . $d->format('j') . ' de '
               . $meses[(int)$d->format('n') - 1] . ' a las ' . $d->format('H:i');
    }
    $cuerpo_t = $plazo
        ? "Te recuerdo que la transferencia de tu próxima sesión hay que hacerla como muy tarde el $plazo, y subir el justificante en tu cuenta.\n\n"
        : "Todavía no me ha llegado el justificante de la transferencia de tu próxima sesión.\n\n";
    $cuerpo_h = '<p>' . htmlspecialchars(trim($cuerpo_t), ENT_QUOTES, 'UTF-8') . '</p>';
    /* Si además tiene algo por hacer en su cuenta, se dice aquí: un
       correo en vez de dos el mismo día. */
    $extra_t = $hay_pendiente ? "Ya que entras: tienes también algo pendiente en tu cuenta, en «Tareas».\n\n" : '';
    $extra_h = $hay_pendiente ? '<p>Ya que entras: tienes también algo pendiente en tu cuenta, en «Tareas».</p>' : '';
    $texto = "Hola:\n\n"
        . $cuerpo_t
        . "Puedes subirlo desde tu cuenta, en un minuto:\n"
        . "1. Entra en $sitio con tu correo y tu contraseña.\n"
        . "2. En Inicio, busca «Adjuntar transferencia».\n"
        . "3. Pulsa «Elegir archivo», elige la foto o el PDF (vale una captura "
        . "de pantalla de la app del banco) y pulsa «Enviar justificante».\n\n"
        . $extra_t
        . "Si ya lo has hecho o has pagado de otra forma, no hagas caso a este "
        . "correo.\n\n"
        . "Un saludo,\nAdrián\n";
    $e = fn($x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
        . 'font-size:15px;line-height:1.55;color:#25231F;max-width:560px">'
        . '<p>Hola:</p>'
        . $cuerpo_h
        . '<p>Puedes subirlo desde tu cuenta, en un minuto:</p>'
        . '<ol style="padding-left:20px">'
        . '<li>Entra en <a href="' . $e($sitio) . '">tu cuenta</a> con tu correo y tu contraseña.</li>'
        . '<li>En Inicio, busca «Adjuntar transferencia».</li>'
        . '<li>Pulsa «Elegir archivo», elige la foto o el PDF (vale una captura de pantalla de la app del banco) y pulsa «Enviar justificante».</li>'
        . '</ol>'
        . $extra_h
        . '<p style="font-size:13.5px;color:#5c5a55">Si ya lo has hecho o has pagado de otra forma, no hagas caso a este correo.</p>'
        . '<p>Un saludo,<br>Adrián</p></div>';
    return adr_manda($ADR, $a, 'Tu justificante de pago', $texto, $html);
}

/** Por dónde saldría un correo: 'prueba' (a un fichero, solo en las
 *  pruebas), 'brevo' o 'mail' (el del alojamiento, sin firma propia:
 *  con un SPF que solo autoriza a Google, lo más probable es que acabe
 *  en spam o ni llegue). */
function adr_via(array $ADR): string {
    if (!empty($ADR['correo_carpeta'])) return 'prueba';
    return empty($ADR['brevo']) ? 'mail' : 'brevo';
}

function adr_manda(array $ADR, string $a, string $asunto,
                   string $texto, string $html): bool {
    /* --- Sin Brevo también funciona ---------------------------------
       Brevo NO es obligatorio. Si no hay clave, esto sale por el
       `mail()` del propio alojamiento y el portal funciona igual.

       La diferencia no es si llega, es DÓNDE. Un correo que sale de
       un servidor compartido, sin firma propia, tiene bastantes
       papeletas de caer en la bandeja de spam de Gmail. Y un correo
       de «recuperar contraseña» en spam es una función rota: la
       persona que lo necesita es justo la que no va a ir a buscarlo.

       Así que se empieza sin Brevo para no bloquear nada, y se pone
       Brevo cuando se pueda, que son dos minutos. Mientras tanto,
       dile a quien lo pida que mire en spam. */
    /* Solo en las pruebas: con `correo_carpeta` en la configuración,
       el correo se escribe en un fichero en vez de salir. En Hostinger
       no se pone nunca. */
    if (!empty($ADR['correo_carpeta'])) {
        @mkdir($ADR['correo_carpeta'], 0700, true);
        file_put_contents($ADR['correo_carpeta'] . '/' . microtime(true) . '.json',
            json_encode(['a' => $a, 'asunto' => $asunto, 'texto' => $texto, 'html' => $html,
                         'de' => $ADR['remite']], JSON_UNESCAPED_UNICODE));
        return true;
    }
    if (empty($ADR['brevo'])) {
        $cabeceras = "From: adricamente <{$ADR['remite']}>\r\n"
                   . "Reply-To: {$ADR['remite']}\r\n"
                   . "Content-Type: text/plain; charset=UTF-8\r\n"
                   . "MIME-Version: 1.0\r\n";
        $ok = @mail($a, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $texto, $cabeceras);
        if (!$ok) error_log('adr: mail() ha fallado y no hay Brevo configurado');
        return $ok;
    }

    $cuerpo = json_encode([
        'sender'      => ['name' => 'adricamente', 'email' => $ADR['remite']],
        'to'          => [['email' => $a]],
        'subject'     => $asunto,
        'textContent' => $texto,
        'htmlContent' => $html,
    ], JSON_UNESCAPED_UNICODE);

    $c = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($c, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cuerpo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $ADR['brevo'],
        ],
    ]);
    $r = curl_exec($c);
    $codigo = curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);

    if ($codigo < 200 || $codigo >= 300) {
        /* Al registro del servidor, no a la pantalla: el que pide el
           correo no tiene por qué enterarse de si existe la cuenta ni
           de si Brevo ha fallado. */
        error_log('adr: Brevo devolvió ' . $codigo . ' ' . substr((string)$r, 0, 300));
        return false;
    }
    return true;
}
