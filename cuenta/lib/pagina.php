<?php
/* El armazón de las páginas del portal. Una función, no una
   plantilla: hay cuatro pantallas y media, y un motor de plantillas
   aquí sería más código del que ahorra. */

/**
 * @param string $titulo   Lo que va en la pestaña. NUNCA nada clínico:
 *                         una pestaña abierta en un portátil
 *                         compartido no tiene por qué contar que
 *                         alguien está en terapia.
 * @param bool   $dentro   Si hay que pintar la barra de navegación.
 */
function adr_pagina(string $titulo, string $cuerpo, string $guion = '',
                    bool $dentro = false, string $aqui = ''): never {
    adr_cabeceras();
    header('Content-Type: text/html; charset=utf-8');
    $nav = '';
    if ($dentro) {
        $enlaces = ['panel.php' => 'Inicio', 'clave.php' => 'Contraseña',
                    'salir.php' => 'Salir'];
        foreach ($enlaces as $u => $t) {
            $act = ($u === $aqui) ? ' aria-current="page"' : '';
            $nav .= '<a href="' . $u . '"' . $act . '>' . $t . '</a>';
        }
        $nav = '<header class="barra"><div class="dentro">'
             . '<a class="marca" href="panel.php">adricamente</a>'
             . '<nav aria-label="Tu cuenta">' . $nav . '</nav></div></header>';
    } else {
        $nav = '<header class="barra"><div class="dentro">'
             . '<span class="marca">adricamente</span></div></header>';
    }
    echo '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>', htmlspecialchars($titulo), '</title>
<link rel="stylesheet" href="portal.css">
</head>
<body>
<a class="saltar" href="#principal">Saltar al contenido</a>
', $nav, '
<main id="principal">', $cuerpo, '</main>
', $guion, '
</body>
</html>';
    exit;
}

/** libsodium, en dos guiones clásicos y por separado.
 *
 *  Por separado porque concatenados chocan: los dos ficheros
 *  minificados declaran los mismos identificadores de una letra y el
 *  navegador se para con «Identifier 'A' has already been declared».
 *  Eso costó un rato en /h/ y no se vuelve a pagar.
 *
 *  Y son la versión SUMO, no la normal, que es lo que gasta el
 *  megabyte de más. El motivo se encontró probando, no leyendo: la
 *  compilación estándar trae crypto_box_seal —que es todo lo que
 *  necesitan las hojas de /h/— pero NO trae crypto_pwhash ni
 *  crypto_kdf, o sea ni Argon2id ni la derivación de subclaves, que
 *  son justo las dos piezas sobre las que se sostiene este portal.
 *
 *  Y no fallaba con «esto no existe»: `crypto_pwhash_SALTBYTES` salía
 *  `undefined`, se lo tragaba randombytes_buf y reventaba tres
 *  llamadas más abajo con «length cannot be null or undefined». Por
 *  eso /h/ se queda con la normal, que pesa la mitad, y aquí va la
 *  sumo. No es el mismo fichero con otro nombre.
 */
function adr_sodio(): string {
    return '<script src="sodio-sumo.js"></script>'
         . '<script src="sodio-sumo-env.js"></script>';
}
