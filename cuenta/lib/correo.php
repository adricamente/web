<?php
/* ===================================================================
   El único correo que manda el portal: «recuperar contraseña».
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
    if (empty($ADR['brevo'])) {
        $cabeceras = "From: adricamente <{$ADR['remite']}>\r\n"
                   . "Reply-To: {$ADR['remite']}\r\n"
                   . "Content-Type: text/plain; charset=UTF-8\r\n"
                   . "MIME-Version: 1.0\r\n";
        $ok = @mail($a, '=?UTF-8?B?' . base64_encode('Tu contraseña de adricamente') . '?=',
                    $texto, $cabeceras);
        if (!$ok) error_log('adr: mail() ha fallado y no hay Brevo configurado');
        return $ok;
    }

    $cuerpo = json_encode([
        'sender'      => ['name' => 'adricamente', 'email' => $ADR['remite']],
        'to'          => [['email' => $a]],
        'subject'     => 'Tu contraseña de adricamente',
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
