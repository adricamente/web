<?php
/* ===================================================================
   Los correos que el portal necesita escribir por su cuenta: recuperar
   la contraseña y el aviso de «tienes algo». No los envía: los deja en
   la cola y los manda el Mac por el Gmail de hola@ (ver adr_manda).

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
    return adr_manda($ADR, $a, 'Tienes algo en tu cuenta', $texto,
        '<p>Tienes algo esperándote en tu cuenta de adricamente.</p>'
        . '<p><a href="' . htmlspecialchars($ADR['sitio'], ENT_QUOTES) . '">Entrar en mi cuenta</a></p>'
        . '<p>Si te viene mal ahora, no pasa nada: se queda ahí.</p>'
        . '<p>Esto no es un canal de urgencias. Si estás en peligro ahora '
        . 'mismo, llama al <strong>024</strong> o al <strong>112</strong>.</p>', 'aviso', $cod);
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

    $html = '<p>Has pedido una contraseña nueva para tu cuenta en '
        . 'adricamente.com.</p>'
        . '<p><a href="' . htmlspecialchars($enlace, ENT_QUOTES) . '">'
        . 'Poner una contraseña nueva</a></p>'
        . '<p>El enlace vale ' . $vale . ' y una sola vez.</p>'
        . '<p>Si no has sido tú, no hace falta que hagas nada: sin abrir '
        . 'ese enlace no cambia nada.</p>'
        . '<p><strong>Un aviso importante:</strong> al poner una contraseña '
        . 'nueva, los documentos que ya tuvieras dentro tardan un rato en '
        . 'volver a aparecer. No se pierden.</p>';

    return adr_manda($ADR, $a, 'Tu contraseña de adricamente', $texto, $html, 'reset');
}

/** Por dónde sale un correo del portal: siempre por la cola, que vacía
 *  el Mac por el Gmail de hola@. 'prueba' en las pruebas (además se
 *  escribe a un fichero para poder leerlo). */
function adr_via(array $ADR): string {
    return !empty($ADR['correo_carpeta']) ? 'prueba' : 'cola';
}

/** El portal NO envía correos (decisión del 09-10).
 *
 *  Los correos a pacientes salen solo por el Gmail de hola@ (Google
 *  Workspace), desde el Mac: así quedan en «Enviados», las respuestas
 *  llegan a hola@ y en Hostinger no hay ninguna credencial de correo.
 *  Lo que el servidor tiene que escribir por su cuenta («he olvidado mi
 *  contraseña», «tienes algo» de lo que no decide el Mac) se deja en la
 *  cola, sellado a la clave del Mac, y el Mac lo manda en su pasada.
 *
 *  Antes había aquí Brevo y, sin Brevo, el `mail()` del alojamiento.
 *  Fue el error del 08-10: `mail()` decía «ok» y no salía nada. Ahora
 *  nadie dice «enviado» salvo el Mac, con el identificador de Gmail.
 *
 *  Devuelve ['enviado' => false, 'error' => 'en_cola', 'cola_id' => n]
 *  o un error si no se ha podido encolar. */
function adr_manda(array $ADR, string $a, string $asunto, string $texto, string $html,
                   string $tipo = 'aviso', ?string $cod = null): array {
    $r = adr_correo_encola($ADR, $a, $asunto, $texto, $html, $tipo, $cod);
    if (!empty($ADR['correo_carpeta'])) {
        @mkdir($ADR['correo_carpeta'], 0700, true);
        file_put_contents($ADR['correo_carpeta'] . '/' . microtime(true) . '.json',
            json_encode(['a' => $a, 'asunto' => $asunto, 'texto' => $texto, 'html' => $html,
                         'tipo' => $tipo, 'cola' => $r], JSON_UNESCAPED_UNICODE));
    }
    return $r;
}

/** Deja un correo en la cola, sellado a la clave pública del Mac. */
function adr_correo_encola(array $ADR, string $a, string $asunto, string $texto, string $html,
                           string $tipo, ?string $cod): array {
    $db = adr_db($ADR);
    $m = $db->query("SELECT valor FROM ajustes WHERE clave = 'mac_publica'")->fetch();
    $pub = $m ? base64_decode($m['valor'], true) : false;
    if (!$pub || strlen($pub) !== 32 || !function_exists('sodium_crypto_box_seal')) {
        error_log('adr: no se puede encolar un correo: sin clave pública del Mac o sin sodium');
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
        ? ['fecha' => date('c'), 'message_id' => $res['message_id']]
        : ['fecha' => date('c'), 'codigo' => $codigo, 'error' => $res['error'], 'texto' => $texto];
    adr_db($ADR)->prepare('INSERT INTO ajustes (clave, valor, puesto) VALUES (?,?,?)
                           ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor,
                                                            puesto = excluded.puesto')
        ->execute([$clave, json_encode($valor, JSON_UNESCAPED_UNICODE), date('c')]);
    return $res;
}
