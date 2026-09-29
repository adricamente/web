<?php
/* ===================================================================
   El arranque del portal. Lo primero que carga todo lo demás.
   -------------------------------------------------------------------
   Este portal vive en el alojamiento que ya existe, no en un servidor
   alquilado. La decisión se tomó midiendo, no opinando: PHP 8.3 con
   argon2id, SQLite y escritura, que es TODO lo que hace falta porque
   el servidor de este diseño no cifra nada.

   Y no es la opción barata, es la segura: un servidor que nadie
   parchea es peor que un alojamiento que parchea otro. Para una
   consulta de una persona, menos máquina es menos riesgo.

   Lo que el servidor NO puede hacer, y es a propósito:

     · No puede leer una sola respuesta. Lo que guarda son bloques
       cifrados en el navegador del paciente contra la clave del Mac.
     · No puede deducir la contraseña de nadie. Guarda un verificador
       derivado de ella, no ella.
     · No puede descifrar lo que el Mac manda al paciente. La clave
       privada del paciente vive envuelta con su contraseña, y se
       desenvuelve en su navegador.

   Si alguien se lleva esta base de datos entera, se lleva ruido.
   =================================================================== */

declare(strict_types=1);

/* Este fichero es código, no una página. Sale 404 si alguien lo pide
   por URL.
   -------------------------------------------------------------------
   Y no sobra por tener `Require all denied` en lib/.htaccess: se probó
   contra el servidor de verdad y LiteSpeed NO lo estaba aplicando —
   /lib/arranque.php contestaba 503, que es PHP ejecutándose, no el
   servidor negando el paso. Una defensa que depende de que el
   alojamiento respete una directiva es una defensa que se cae el día
   que cambian de alojamiento, y no avisa. Ésta está dentro del propio
   fichero y viaja con él. */
if (!defined('ADR_DENTRO')) { http_response_code(404); exit; }


/* Nada de avisos a pantalla. Un aviso de PHP dentro de una respuesta
   JSON la rompe, y dentro de una página cuenta la ruta del servidor. */
ini_set('display_errors', '0');
error_reporting(E_ALL);

const ADR_RAIZ = __DIR__ . '/..';

/* --- La configuración ---------------------------------------------
   Vive FUERA del repositorio y fuera de la carpeta pública, porque
   lleva el secreto del servidor y la clave SMTP. Si falta, esto no
   arranca a medias: se para y lo dice. Un portal que arranca sin
   secreto arranca inseguro. */
$_candidatos = [
    ADR_RAIZ . '/../adr-config.php',      // public_html/adr-config.php
    ADR_RAIZ . '/../../adr-config.php',   // mejor aun: fuera de public_html
    ADR_RAIZ . '/config.php',             // solo para pruebas locales
];
$ADR = null;
foreach ($_candidatos as $c) {
    if (is_file($c)) { $ADR = require $c; break; }
}
if (!is_array($ADR)) {
    http_response_code(503);
    exit('El portal no está configurado todavía.');
}
foreach (['secreto', 'datos', 'sitio'] as $k) {
    if (empty($ADR[$k])) { http_response_code(503); exit('Configuración incompleta.'); }
}

/* --- Cabeceras ----------------------------------------------------
   Más estrictas que las de la web de marketing, igual que en /h/.
   Aquí la CSP no es decoración: es lo que impide que una inyección
   se lleve a otro sitio lo que el paciente escribe.

   'wasm-unsafe-eval' porque libsodium compila WebAssembly. Eso ya
   costó una noche en /h/: sin ello la página carga perfecta y vacía,
   y el único aviso es una línea en la consola. */
function adr_cabeceras(): void {
    header('Content-Security-Policy:'
        . " default-src 'none';"
        . " script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval';"
        . " style-src 'self' 'unsafe-inline';"
        . " img-src 'self' data:;"
        . " connect-src 'self';"
        . " form-action 'self';"
        . " frame-ancestors 'none';"
        . " base-uri 'none';"
        . " object-src 'none';"
        . ' upgrade-insecure-requests');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Cache-Control: no-store');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

/* --- Utilidades ---------------------------------------------------- */

function adr_b64(string $b): string { return base64_encode($b); }

/** Base64 estricto. Devuelve null si no lo es, en vez de tragar. */
function adr_deb64(?string $s, int $bytes = 0): ?string {
    if ($s === null) return null;
    $d = base64_decode($s, true);
    if ($d === false) return null;
    if ($bytes && strlen($d) !== $bytes) return null;
    return $d;
}

function adr_correo_normal(string $c): string {
    return mb_strtolower(trim($c), 'UTF-8');
}

/**
 * La sal FALSA pero ESTABLE.
 *
 * Este es el detalle que separa un portal de salud de un portal
 * cualquiera. La sal se pide antes de saber si la contraseña es
 * buena. Si el servidor contestara «ese correo no existe», acabaría
 * de decir quién tiene cuenta aquí — y aquí eso es quién está en
 * tratamiento.
 *
 * Así que SIEMPRE devuelve una sal: la suya si existe, y si no una
 * derivada del correo con el secreto del servidor. Estable importa:
 * una sal aleatoria en cada intento delataría igual al comparar dos
 * respuestas seguidas. El intento falla después, al desenvolver, con
 * el mismo error que una contraseña mala.
 */
function adr_sal_falsa(string $correo, string $secreto): string {
    return hash_hmac('sha256', 'sal:' . adr_correo_normal($correo), $secreto, true);
}

/** Igualdad en tiempo constante para cualquier cosa que sea secreta. */
function adr_iguales(string $a, string $b): bool {
    return hash_equals($a, $b);
}

/** Un identificador del que llama, para el freno de intentos. */
function adr_huella(): string {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?';
    $ip = trim(explode(',', $ip)[0]);
    return substr(hash('sha256', $ip), 0, 32);
}

/** Respuesta JSON, siempre con las cabeceras puestas. */
function adr_json(array $d, int $codigo = 200): never {
    adr_cabeceras();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($codigo);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** El cuerpo de una petición JSON, o array vacío. */
function adr_entrada(): array {
    $c = file_get_contents('php://input');
    if ($c === false || $c === '') return [];
    $d = json_decode($c, true);
    return is_array($d) ? $d : [];
}

require __DIR__ . '/db.php';
