<?php
/* ===================================================================
   «Prefiero que me llames», el formulario de /empieza/ (03-11).
   -------------------------------------------------------------------
   Recibe nombre, teléfono, si prefiere llamada o WhatsApp (opcional) y
   de qué historia viene (la UTM de la visita, que pone el guion de la
   página; nunca nada de salud), y lo manda en un correo
   a hola@, por el mismo Gmail de hola@ (solo enviar) que usa el portal:
   reutiliza cuenta/lib/correo.php, como h/enviar.php reutiliza el
   arranque. No crea tablas, ni guarda el formulario, ni lo escribe en
   ningún registro. Lo único que queda en el servidor:
     · la huella de la IP (HMAC con el secreto, no la IP) en la tabla del
       freno, que se borra sola en un día;
     · solo si Gmail falla, el correo sellado a la clave del Mac en la
       cola de siempre, que el servidor no puede abrir.

   Antispam sin CAPTCHA de terceros, en tres capas:
     1. Un campo trampa («web») oculto con CSS. Si llega relleno se
        contesta «hecho» y no se manda nada: el robot no aprende.
     2. Tiempo mínimo: una «ficha» firmada con la hora. La página es HTML
        estático, así que no puede llevarla dentro: con guion la pide al
        abrir el panel (GET ?ficha=1); sin guion, el primer envío vuelve
        con la ficha puesta y pide confirmar. Menos de LL_MIN segundos
        desde la ficha, o una ficha que no es nuestra, no pasa.
     3. Freno por IP (3 a la hora) y total (30 a la hora), con el mismo
        adr_freno del portal.

   Dos maneras de contestar:
     · Accept: application/json (el guion de la página): JSON.
     · Sin guion: la propia /empieza/index.html, con el panel abierto y
       el estado puesto (errores, confirmar o «hecho»). Las cadenas que se
       buscan aquí las escribe rueda/empieza.py; si cambian allí, cambian
       aquí. El «hecho» va por 303 a ?hecho=1, para que recargar no
       vuelva a mandar el formulario.

   No se pregunta el motivo de la consulta ni nada de salud. No es un
   olvido: lo que se cuente aquí iría a un correo, y el sitio de eso es
   la sesión, con el centro como responsable.
   =================================================================== */
declare(strict_types=1);
const ADR_DENTRO = true;

const LL_MIN = 3;             // segundos entre la ficha y el envío
const LL_MAX = 4 * 3600;      // una ficha vale cuatro horas: hay quien abre, se va y vuelve
const LL_A = 'hola@adricamente.com';
const LL_WA = '34611702119';  // el mismo de /contacto/
const LL_WA_VER = '611 70 21 19';
const LL_CAL = 'https://cal.com/adricamente/sesion-informativa-gratuita?utm_source=instagram&amp;utm_medium=historia&amp;utm_campaign=empieza&amp;utm_content=';
const LL_VIAS = ['' => 'le da igual', 'llamada' => 'llamada', 'whatsapp' => 'WhatsApp'];

ini_set('display_errors', '0');
$LL_JSON = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

/* --- La configuración -------------------------------------------
   La misma que el portal y en los mismos sitios. Se mira antes de
   cargar el arranque porque, si falta, el arranque se para con un 503
   en texto plano, y aquí hay que contestar con la página y con otra
   forma de reservar. */
$ADR = null;
foreach ([__DIR__ . '/../adr-config.php', __DIR__ . '/../../adr-config.php', __DIR__ . '/../cuenta/config.php'] as $c) {
    if (is_file($c)) { $ADR = true; break; }
}
if ($ADR) {
    require __DIR__ . '/../cuenta/lib/arranque.php';   // define $ADR y carga db.php
    require __DIR__ . '/../cuenta/lib/correo.php';
} else {
    $ADR = null;
}

/* --- Textos -------------------------------------------------------
   Un solo sitio para todos: el guion de la página enseña los que le
   llegan en el JSON. */
function ll_alternativas(): string {
    return ' Mientras tanto, <a href="' . LL_CAL . 'fallo">elige tú el día y la hora</a>'
         . ' o <a href="https://wa.me/' . LL_WA . '" target="_blank" rel="noopener">escríbeme por WhatsApp al ' . LL_WA_VER . '</a>.';
}
function ll_texto(string $que): string {
    return match ($que) {
        'confirma' => '<p><strong>Un paso más.</strong> Comprueba que el teléfono está bien y pulsa «Enviar mi teléfono» otra vez.</p>',
        'freno'    => '<p><strong>No lo he podido enviar:</strong> han llegado muchos avisos seguidos desde esta conexión.' . ll_alternativas() . '</p>',
        'envio'    => '<p><strong>No lo he podido enviar</strong>, por un fallo de la web y no tuyo.' . ll_alternativas() . '</p>',
        default    => '',
    };
}

/* --- Utilidades ---------------------------------------------------- */
function ll_limpia(string $s, int $max): string {
    $s = preg_replace('/[\p{C}]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

/** El teléfono, o null. Español de 9 cifras (con o sin +34/0034) o
 *  internacional con prefijo: se atiende a residentes en España, pero no
 *  todos tienen un número de aquí. */
function ll_telefono(string $s): ?array {
    $d = preg_replace('/[\s.\-\/()]+/', '', $s) ?? '';
    if (str_starts_with($d, '00')) $d = '+' . substr($d, 2);
    if (preg_match('/^(?:\+34)?([6789]\d{8})$/', $d, $m)) {
        $n = $m[1];
        return ['ver' => substr($n, 0, 3) . ' ' . substr($n, 3, 2) . ' ' . substr($n, 5, 2) . ' ' . substr($n, 7, 2),
                'tel' => '+34' . $n, 'wa' => '34' . $n];
    }
    if (preg_match('/^\+([1-9]\d{7,14})$/', $d, $m) && !str_starts_with($m[1], '34')) {
        return ['ver' => '+' . $m[1], 'tel' => '+' . $m[1], 'wa' => $m[1]];
    }
    return null;
}

function ll_ficha(array $ADR, ?int $t = null): string {
    $t ??= time();
    return $t . '.' . substr(hash_hmac('sha256', 'empieza-ficha:' . $t, (string)$ADR['secreto']), 0, 32);
}
/** Segundos desde que se dio la ficha, o null si no es nuestra o ha caducado. */
function ll_edad(array $ADR, string $f): ?int {
    if (!preg_match('/^(\d{9,11})\.([0-9a-f]{32})$/', $f, $m)) return null;
    $bien = substr(hash_hmac('sha256', 'empieza-ficha:' . $m[1], (string)$ADR['secreto']), 0, 32);
    if (!hash_equals($bien, $m[2])) return null;
    $e = time() - (int)$m[1];
    return ($e < 0 || $e > LL_MAX) ? null : $e;
}

/* --- Respuestas ---------------------------------------------------- */
function ll_json(array $d, int $codigo = 200): never {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** La página de siempre, con el panel abierto y el estado puesto. */
function ll_pagina(string $modo, array $v = [], array $err = [], string $aviso = '', string $ficha = '', int $codigo = 200): never {
    http_response_code($codigo);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $h = @file_get_contents(__DIR__ . '/index.html');
    if ($h === false) {
        echo '<!doctype html><meta charset="utf-8"><title>adricamente</title><p>' . ($modo === 'hecho'
            ? 'Recibido. Te llamo o te escribo en uno o dos días laborables desde el ' . LL_WA_VER . '.'
            : 'No lo he podido enviar. Escríbeme por WhatsApp al ' . LL_WA_VER . '.') . '</p>';
        exit;
    }
    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $h = str_replace('<details class="ll" id="te-llamo">', '<details class="ll" id="te-llamo" open>', $h);
    if ($aviso !== '') {
        $clase = $modo === 'confirma' ? 'mensaje info' : 'mensaje error';
        $h = str_replace('<div class="ll-aviso" id="ll-aviso" role="alert"></div>',
                         '<div class="ll-aviso" id="ll-aviso" role="alert"><div class="' . $clase . '">' . $aviso . '</div></div>', $h);
    }
    if ($modo === 'hecho') {
        $h = str_replace('<form class="ll-form" id="ll-form"', '<form class="ll-form" id="ll-form" hidden', $h);
        $h = str_replace('<div class="ll-hecho" id="ll-hecho" tabindex="-1" hidden>', '<div class="ll-hecho" id="ll-hecho" tabindex="-1">', $h);
    } else {
        foreach (['nombre', 'telefono'] as $k) {
            if (($v[$k] ?? '') !== '') $h = str_replace('id="ll-' . $k . '"', 'id="ll-' . $k . '" value="' . $e($v[$k]) . '"', $h);
        }
        if (($v['via'] ?? '') !== '') {
            $h = str_replace('name="via" value="' . $e($v['via']) . '"', 'name="via" value="' . $e($v['via']) . '" checked', $h);
        }
        if (($v['origen'] ?? '') !== '') $h = str_replace('name="origen" value=""', 'name="origen" value="' . $e($v['origen']) . '"', $h);
        if (!empty($v['privacidad'])) $h = str_replace('name="privacidad" value="1"', 'name="privacidad" value="1" checked', $h);
        if ($ficha !== '') $h = str_replace('name="ficha" value=""', 'name="ficha" value="' . $e($ficha) . '"', $h);
        foreach ($err as $k => $m) {
            $h = str_replace('<p class="ll-err" id="ll-' . $k . '-err" hidden></p>', '<p class="ll-err" id="ll-' . $k . '-err">' . $e($m) . '</p>', $h);
            $h = str_replace('id="ll-' . $k . '"', 'id="ll-' . $k . '" aria-invalid="true"', $h);
        }
    }
    echo $h;
    exit;
}

function ll_responde(string $estado, array $extra = [], array $v = [], int $codigo = 200): never {
    global $LL_JSON;
    if ($LL_JSON) {
        ll_json(['ok' => $estado === 'hecho', 'error' => $estado === 'hecho' ? null : $estado,
                 'mensaje' => ll_texto($estado)] + $extra, $codigo);
    }
    if ($estado === 'hecho') {
        header('Location: /empieza/enviar.php?hecho=1#te-llamo', true, 303);
        header('Cache-Control: no-store');
        exit;
    }
    ll_pagina($estado, $v, $extra['campos'] ?? [], ll_texto($estado), (string)($extra['ficha'] ?? ''), $codigo);
}

/* --- El correo a hola@ ---------------------------------------------
   Dice lo justo para devolver la llamada en un toque. El asunto lleva
   el nombre para encontrarlo en la bandeja; el teléfono va dentro. */
function ll_manda(array $ADR, string $nombre, array $tel, string $via, string $origen): bool {
    $a = (string)($ADR['empieza_a'] ?? LL_A);
    $cuando = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('d/m/Y H:i');
    $f = LL_VIAS[$via] ?? LL_VIAS[''];
    $o = $origen !== '' ? str_replace('&', ' · ', $origen) : 'sin UTM propia (instagram · historia · empieza)';
    $asunto = 'Llamar para la media hora: ' . $nombre;
    $texto = "$nombre ha dejado su teléfono en adricamente.com/empieza/ para que le llames y reservar la media hora gratis.\n\n"
           . "Teléfono: {$tel['ver']}\nPrefiere: $f\nDe dónde viene: $o\nEnviado: $cuando\n\n"
           . "Llamar: tel:{$tel['tel']}\nWhatsApp: https://wa.me/{$tel['wa']}\n\n"
           . "En la web se le dice que le llamas o le escribes en uno o dos días laborables desde el " . LL_WA_VER . ".\n"
           . "No se le ha preguntado el motivo.\n\n"
           . "Privacidad: borra este correo cuando la cita esté reservada o, si no llegáis a hablar, a los 30 días.\n";
    $e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $html = adr_correo_t('Quiere que le llames')
          . adr_correo_p('<strong>' . $e($nombre) . '</strong> ha dejado su teléfono en /empieza/ para reservar la media hora gratis.')
          . adr_correo_p('Teléfono: <strong>' . $e($tel['ver']) . '</strong><br>Prefiere: ' . $e($f) . '<br>De dónde viene: ' . $e($o) . '<br>Enviado: ' . $e($cuando))
          . adr_correo_boton('tel:' . $tel['tel'], 'Llamar')
          . adr_correo_p('<a href="https://wa.me/' . $e($tel['wa']) . '">Escribirle por WhatsApp</a>')
          . adr_correo_p('En la web se le dice que le llamas o le escribes en uno o dos días laborables desde el ' . LL_WA_VER
                         . '. No se le ha preguntado el motivo.', true)
          . adr_correo_p('Privacidad: borra este correo cuando la cita esté reservada o, si no llegáis a hablar, a los 30 días.', true);

    /* El mismo camino que adr_manda, sin su freno de seis por hora a la
       misma dirección: aquí la dirección es siempre hola@, y una tarde
       con siete personas sería una persona perdida. El freno de esto es
       el de la IP y el total, arriba. */
    $via = adr_via($ADR);
    if ($via === 'google') {
        $r = adr_google_envia($ADR, $a, $asunto, $texto, $html, 'adricamente.com/empieza');
        adr_correo_apunta($ADR, $r, (int)($r['codigo'] ?? 0), (string)($r['detalle'] ?? ''));
        if (!$r['enviado']) $r = adr_correo_encola($ADR, $a, $asunto, $texto, $html, 'empieza', null);
    } elseif ($via === 'cola') {
        $r = adr_correo_encola($ADR, $a, $asunto, $texto, $html, 'empieza', null);
    } else {
        $r = ['enviado' => true, 'message_id' => null, 'error' => null, 'via' => 'prueba'];
    }
    if (!empty($ADR['correo_carpeta'])) {   // solo en las pruebas
        @mkdir($ADR['correo_carpeta'], 0700, true);
        file_put_contents($ADR['correo_carpeta'] . '/' . microtime(true) . '.json',
            json_encode(['a' => $a, 'asunto' => $asunto, 'texto' => $texto, 'html' => $html, 'tipo' => 'empieza', 'cola' => $r],
                        JSON_UNESCAPED_UNICODE));
    }
    $ok = !empty($r['enviado']) || ($r['error'] ?? '') === 'en_cola';
    if (!$ok) error_log('empieza: no sale el aviso (' . ($r['error'] ?? '?') . ')');
    return $ok;
}

/* --- La petición ---------------------------------------------------- */
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET') {
    if (isset($_GET['ficha'])) ll_json(['ficha' => $ADR ? ll_ficha($ADR) : '']);
    if (isset($_GET['hecho'])) ll_pagina('hecho');
    header('Location: /empieza/', true, 303);
    exit;
}
if ($metodo !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

$via = (string)($_POST['via'] ?? '');
/* La UTM de la visita, tal como la deja el guion (utm_x=valor&…). Lo que
   no tenga esa forma se tira sin avisar: no es un dato de la persona. */
$origen = (string)($_POST['origen'] ?? '');
if (!preg_match('/^utm_(?:source|medium|campaign)=[a-z0-9_-]{1,40}(?:&utm_(?:source|medium|campaign)=[a-z0-9_-]{1,40}){0,2}$/', $origen)) $origen = '';
$v = [
    'nombre'     => ll_limpia((string)($_POST['nombre'] ?? ''), 60),
    'telefono'   => ll_limpia((string)($_POST['telefono'] ?? ''), 20),
    'via'        => array_key_exists($via, LL_VIAS) ? $via : '',
    'origen'     => $origen,
    'privacidad' => !empty($_POST['privacidad']),
];

// 1. La trampa: se contesta como si hubiera ido bien, y no sale nada.
if (trim((string)($_POST['web'] ?? '')) !== '') {
    error_log('empieza: trampa');
    ll_responde('hecho');
}

// Los campos.
$err = [];
if ($v['nombre'] === '' || !preg_match('/\p{L}/u', $v['nombre'])) {
    $err['nombre'] = 'Escribe tu nombre (con el de pila basta).';
} elseif (preg_match('~https?:|www\.|[<>@]|\.(?:com|net|org|ru|xyz)\b~i', $v['nombre'])) {
    $err['nombre'] = 'Escribe solo tu nombre, sin enlaces ni direcciones.';
}
$tel = ll_telefono($v['telefono']);
if (!$tel) $err['telefono'] = 'Revisa el teléfono: son 9 cifras, o con el prefijo delante si no es español.';
if (!$v['privacidad']) $err['privacidad'] = 'Marca la casilla para confirmar que has leído cómo uso tu teléfono.';
if ($err) ll_responde('campos', ['campos' => $err], $v, 422);

if (!$ADR) {
    error_log('empieza: falta adr-config.php');
    ll_responde('envio', [], $v, 503);
}

// 2. El tiempo mínimo.
$edad = ll_edad($ADR, (string)($_POST['ficha'] ?? ''));
if ($edad === null || $edad < LL_MIN) {
    $espera = $edad === null ? LL_MIN * 1000 : (LL_MIN - $edad) * 1000 + 300;
    $ficha = $edad === null ? ll_ficha($ADR) : (string)$_POST['ficha'];
    if ($LL_JSON) ll_json(['ok' => false, 'error' => 'espera', 'espera' => $espera, 'ficha' => $ficha], 425);
    ll_responde('confirma', ['ficha' => $ficha], $v);
}

// 3. El freno.
try {
    $db = adr_db($ADR);
    $huella = hash_hmac('sha256', 'empieza:' . (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)$ADR['secreto']);
    if (!adr_freno($db, 'empieza:' . $huella, 3, 3600) || !adr_freno($db, 'empieza:todas', 30, 3600)) {
        error_log('empieza: frenado');
        ll_responde('freno', [], $v, 429);
    }
    $ok = ll_manda($ADR, $v['nombre'], $tel, $v['via'], $v['origen']);
} catch (Throwable $t) {
    error_log('empieza: ' . get_class($t));
    $ok = false;
}
if (!$ok) ll_responde('envio', [], $v, 503);
ll_responde('hecho');
