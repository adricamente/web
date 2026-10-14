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

function pagar_pagina(string $titulo, string $texto, int $codigo = 200): never {
    http_response_code($codigo);
    adr_pagina('Pago | adricamente',
        '<div class="centrado"><h1>' . htmlspecialchars($titulo) . '</h1><p>' . $texto . '</p>'
        . '<p class="apunte"><a href="index.php">Ir a mi cuenta</a></p></div>');
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
    pagar_pagina('Esta sesión ya está pagada. ¡Gracias!',
        'Sesión del ' . htmlspecialchars($dia) . ' a las ' . $hora . '. El recibo te llega por correo desde Stripe.');
}
if ($cita['estado'] !== 'activa') {
    pagar_pagina('Esta cita ya no está activa', 'Si crees que es un error, escríbeme.');
}
if ($ok !== '') {
    pagar_pagina('Estamos confirmando el pago', 'En un momento quedará marcado. Puedes cerrar esta página.');
}
[$url, $err] = adr_cita_checkout($ADR, $db, $cita);
if (!$url) {
    pagar_pagina('Ahora mismo no se puede pagar', 'Prueba otra vez en unos minutos. Si sigue igual, escríbeme y lo miro.', 503);
}
header('Location: ' . $url, true, 303);
exit;
