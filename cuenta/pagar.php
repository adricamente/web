<?php
/* Pagar una sesión: el enlace del correo, del WhatsApp o del panel.
   -------------------------------------------------------------------
   No pide entrar: el enlace es largo, opaco, de una sola cita, y no dice
   nada de quién es. Al abrirlo se crea (o se reutiliza, si sigue viva)
   la sesión de pago de Stripe y se va allí. Así el enlace no caduca
   aunque la sesión de Stripe dure 24 horas. */
declare(strict_types=1);

const ADR_DENTRO = true;
require __DIR__ . '/lib/arranque.php';
require __DIR__ . '/lib/pagina.php';
require __DIR__ . '/lib/pagos.php';

$db = adr_db($ADR);
adr_pagos_tabla($db);

/* Si la cita es de alguien con cuenta, un botón para entrar en ella
   (si ya tiene la sesión abierta, entra directo; si no, le pide la
   contraseña). A quien no tiene cuenta no se le ofrece. */
$GLOBALS['pagar_cuenta'] = false;
function pagar_pagina(string $titulo, string $texto, int $codigo = 200): never {
    http_response_code($codigo);
    $boton = $GLOBALS['pagar_cuenta'] ? '<p><a class="boton" href="index.php">Ir a mi cuenta</a></p>' : '';
    adr_pagina('Pago | adricamente',
        '<div class="centrado"><h1>' . htmlspecialchars($titulo) . '</h1><p>' . $texto . '</p>' . $boton
        . '<p class="apunte">Adrián Cuñat Andrés · Psicólogo colegiado nº CM03836 · '
        . '<a href="mailto:hola@adricamente.com">hola@adricamente.com</a></p></div>');
}

$t = (string)($_GET['t'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', $t) || !adr_freno($db, 'pagar:' . adr_huella(), 60, 3600)) {
    pagar_pagina('Este enlace no es válido', 'Revisa que lo hayas copiado entero, o escríbeme y te mando otro.', 404);
}
$q = $db->prepare('SELECT * FROM citas WHERE token = ?');
$q->execute([$t]);
$cita = $q->fetch();
if (!$cita) pagar_pagina('Este enlace no es válido', 'Revisa que lo hayas copiado entero, o escríbeme y te mando otro.', 404);
[$dia, $hora] = adr_dia_hora($cita['inicio']);
if (!empty($cita['cod'])) {
    $q = $db->prepare('SELECT 1 FROM pacientes WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cita['cod']]);
    $GLOBALS['pagar_cuenta'] = (bool)$q->fetch();
}

/* Vuelta desde Stripe: se comprueba allí mismo, sin esperar al webhook. */
$ok = (string)($_GET['ok'] ?? '');
if ($ok !== '' && $cita['pagado_en'] === null && preg_match('/^cs_[A-Za-z0-9_]+$/', $ok) && $ok === $cita['checkout_id']) {
    [$c, $j] = adr_stripe($ADR, 'GET', '/v1/checkout/sessions/' . $ok);
    if ($c === 200 && ($j['payment_status'] ?? '') === 'paid') {
        $pi = (string)($j['payment_intent'] ?? $ok);
        adr_cita_pagada($db, $cita['uid'], $pi);
        $fee = adr_stripe_comision($ADR, $pi);
        if ($fee !== null) $db->prepare('UPDATE citas SET comision = ? WHERE uid = ?')->execute([$fee, $cita['uid']]);
        $cita['pagado_en'] = date('c');
    }
}

if ($cita['pagado_en'] !== null) {
    pagar_pagina('Pago recibido. ¡Gracias!',
        'Tu sesión del ' . htmlspecialchars($dia) . ' a las ' . $hora . ' queda confirmada. Stripe te manda el recibo por correo.');
}
if ($cita['estado'] !== 'activa') {
    pagar_pagina('Esta cita ya no está activa', 'Se canceló o se cambió de hora. Si crees que es un error, escribe a <a href="mailto:hola@adricamente.com">hola@adricamente.com</a>.');
}
if ($ok !== '') {
    pagar_pagina('Estamos confirmando el pago', 'En unos segundos quedará marcado y Stripe te mandará el recibo. Puedes cerrar esta página.');
}
[$url, $err] = adr_cita_checkout($ADR, $db, $cita);
if (!$url) {
    adr_pagos_alerta($ADR, $db, (string)$err);
    pagar_pagina('No hemos podido abrir la página de pago',
        'Tu sesión del ' . htmlspecialchars($dia) . ' a las ' . $hora . ' sigue reservada: no pierdes la cita. '
        . 'Me acaba de llegar un aviso y lo estoy mirando. Prueba de nuevo con este mismo enlace dentro de un rato; '
        . 'si prefieres, contesta al correo de la reserva o escribe a <a href="mailto:hola@adricamente.com">hola@adricamente.com</a>.', 503);
}
header('Location: ' . $url, true, 303);
exit;
