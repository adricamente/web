<?php
/* Salir. Borra la galleta y vuelve a la puerta.
   La clave privada no se toca aquí porque no está aquí: vive en la
   memoria de la pestaña y se borra sola al recargar. */
declare(strict_types=1);

/* La llave que abre lib/. Ver el comentario de lib/arranque.php. */
const ADR_DENTRO = true;
require __DIR__ . '/lib/arranque.php';
require __DIR__ . '/lib/sesion.php';

setcookie('adr_s', '', ['expires' => 1, 'path' => '/', 'secure' => adr_es_https(),
                        'httponly' => true, 'samesite' => 'Strict']);
adr_cabeceras();
header('Location: index.php');
