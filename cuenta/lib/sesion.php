<?php
/* La sesión y la puerta del Mac. Viven aparte de api.php porque las
   páginas también necesitan saber si hay sesión, y cargar api.php
   para preguntarlo ejecutaría su `switch` entero. */

/* La sesión es una galleta firmada, no una fila en una tabla. Para
   cinco pacientes, una tabla de sesiones es una tabla más que
   respaldar y que purgar, y no compra nada. Ocho horas y caduca. */
function adr_sesion_pon(string $cod, string $secreto): void {
    $hasta = time() + 8 * 3600;
    $cuerpo = $cod . '|' . $hasta;
    $firma = hash_hmac('sha256', 'ses:' . $cuerpo, $secreto);
    setcookie('adr_s', $cuerpo . '|' . $firma, [
        'expires'  => $hasta,
        'path'     => '/',
        /* `secure` se pone si la petición viene por https, que en
           producción es siempre —el .htaccess de esta carpeta redirige
           y además manda HSTS—. Se deja condicional para poder probar
           todo esto en local sin https, que si no la galleta no viaja
           y las pruebas mienten diciendo que la sesión no funciona. */
        'secure'   => adr_es_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function adr_es_https(): bool {
    return !empty($_SERVER['HTTPS'])
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function adr_sesion(string $secreto): ?string {
    $c = $_COOKIE['adr_s'] ?? '';
    $p = explode('|', $c);
    if (count($p) !== 3) return null;
    [$cod, $hasta, $firma] = $p;
    if (!ctype_digit($hasta) || (int)$hasta < time()) return null;
    $bien = hash_hmac('sha256', 'ses:' . $cod . '|' . $hasta, $secreto);
    return adr_iguales($bien, $firma) ? $cod : null;
}

/* El Mac se identifica con una clave larga del fichero de
   configuración, no con una contraseña. No es una persona. */
function adr_es_el_mac(array $ADR): bool {
    $t = $_SERVER['HTTP_X_ADR_MAC'] ?? '';
    return $t !== '' && !empty($ADR['mac']) && adr_iguales($ADR['mac'], $t);
}
