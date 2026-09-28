<?php
/* La pantalla de instalación. Sale SOLO cuando falta algo: si está
   todo puesto, la portada es la de entrar y esto no se ve nunca.

   Existe porque hay un paso que no puede dar el código —crear
   adr-config.php fuera de la carpeta pública— y un paso manual sin
   forma de comprobarlo es un paso que se da mal y no se sabe. */
declare(strict_types=1);
ini_set('display_errors', '0');

$problemas = [];
$config = null;
foreach ([dirname(__DIR__, 2) . '/adr-config.php',
          dirname(__DIR__, 3) . '/adr-config.php'] as $c) {
    if (is_file($c)) { $config = $c; break; }
}
$ADR = $config ? require $config : null;

if (!is_array($ADR)) {
    $problemas[] = ['No está <code>adr-config.php</code>',
     'why' => 'Vale en cualquiera de estos dos sitios, y el segundo es mejor:'
     . '<br><code>' . htmlspecialchars(dirname(__DIR__, 3)) . '/adr-config.php</code>'
     . '<br><code>' . htmlspecialchars(dirname(__DIR__, 2)) . '/adr-config.php</code>'
     . '<br>Copia ahí <code>config-ejemplo.php</code> y rellénalo.'];
} else {
    foreach (['secreto' => 'el secreto del servidor',
              'mac'     => 'la clave del Mac',
              'datos'   => 'la ruta de la base de datos',
              'sitio'   => 'la dirección del portal'] as $k => $q) {
        if (empty($ADR[$k])) $problemas[] = ["Falta $q", 'why' => "La clave <code>$k</code> está vacía."];
        elseif (in_array($k, ['secreto', 'mac'], true) && str_contains((string)$ADR[$k], 'PEGA_AQUI'))
            $problemas[] = ["$q sigue sin rellenar",
                'why' => 'Genera uno con <code>php -r \'echo bin2hex(random_bytes(32)), "\\n";\'</code>'];
        elseif (in_array($k, ['secreto', 'mac'], true) && strlen((string)$ADR[$k]) < 40)
            $problemas[] = ["$q es demasiado corto",
                'why' => 'Tiene que ser una cadena larga y aleatoria, no una contraseña pensada.'];
    }
    if (empty($problemas)) {
        $dir = dirname($ADR['datos']);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (!is_dir($dir) || !is_writable($dir)) {
            $problemas[] = ['No se puede escribir la base de datos',
                'why' => 'No hay permiso en <code>' . htmlspecialchars($dir) . '</code>.'];
        }
        if (str_starts_with(realpath($dir) ?: '', realpath(dirname(__DIR__)) ?: 'x')) {
            $problemas[] = ['La base de datos está dentro de la carpeta pública',
                'why' => 'Muévela a un nivel por encima. Si un día PHP se cae, '
                   . 'un fichero aquí se sirve como descarga.'];
        }
    }
}
foreach (['pdo_sqlite' => 'SQLite', 'curl' => 'cURL', 'mbstring' => 'mbstring'] as $e => $n) {
    if (!extension_loaded($e)) $problemas[] = ["Falta la extensión $n", 'why' => "El alojamiento no tiene <code>$e</code>."];
}
if (!defined('PASSWORD_ARGON2ID')) $problemas[] = ['No hay Argon2id', 'why' => 'Este PHP no lo trae.'];

header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');
http_response_code($problemas ? 503 : 200);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preparando</title>
<style>
:root{color-scheme:light}
body{margin:0;padding:40px 20px;background:#FAF9F5;color:#25231F;
  font:17px/1.55 -apple-system,system-ui,"Segoe UI",sans-serif}
main{max-width:620px;margin:0 auto}
h1{font-size:26px;letter-spacing:-.02em;margin:0 0 6px}
.marca{font-weight:800;color:#1F5474;margin-bottom:26px}
p{margin:0 0 13px;max-width:66ch}
.bien{background:#E2F0E0;border-left:4px solid #2C632B;padding:14px 17px}
.mal{background:#FCEAD2;border-left:4px solid #7B4D13;padding:14px 17px;margin:0 0 13px}
.mal b{display:block;color:#5C390E;margin-bottom:4px}
code{background:#F5F3EF;padding:2px 5px;border-radius:4px;font-size:14.5px;
  word-break:break-all}
.apunte{color:#6C6963;font-size:15px}
</style>
</head>
<body>
<main>
<div class="marca">adricamente</div>
<?php if ($problemas): ?>
  <h1>Falta un paso</h1>
  <p class="apunte">Esta página desaparece en cuanto esté todo puesto.
  Nadie más que tú debería estar viéndola.</p>
  <?php foreach ($problemas as $p): ?>
    <div class="mal"><b><?= $p[0] ?></b><?= $p['why'] ?></div>
  <?php endforeach; ?>
<?php else: ?>
  <h1>Todo listo</h1>
  <div class="bien"><p style="margin:0">La configuración está puesta, la base
  de datos se puede escribir y está fuera de la carpeta pública. Ya se pueden
  subir las pantallas.</p></div>
<?php endif; ?>
<p class="apunte" style="margin-top:26px">PHP <?= PHP_VERSION ?> ·
<?= adr_https_texto() ?></p>
</main>
</body>
</html>
<?php
function adr_https_texto(): string {
    return (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'conexión segura' : 'ATENCIÓN: esto no ha llegado por https';
}
