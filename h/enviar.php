<?php
/* ===================================================================
   El buzón de las hojas sueltas de /h/.
   -------------------------------------------------------------------
   Hasta ahora la hoja cifraba y el paciente se descargaba un fichero
   que luego tenía que hacerme llegar. Funcionaba, pero pedía un paso
   que mucha gente no da. Ahora la hoja sella en el navegador y deja el
   sobre aquí; el Mac lo recoge solo.

   Lo que este fichero NO puede hacer, igual que el portal:
     · Leer nada. Recibe un bloque sellado a la clave del Mac.
     · Saber de quién es. El código va DENTRO del sobre; aquí se guarda
       con un código genérico, «hoja».
     · Guardar la IP. Para frenar abusos se usa su huella con el
       secreto del servidor, y se borra sola en un día.
   =================================================================== */
declare(strict_types=1);
const ADR_DENTRO = true;
require __DIR__ . '/../cuenta/lib/arranque.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '{"error":"no"}';
    exit;
}

$db = adr_db($ADR);
$in = adr_entrada();
$cifrado = adr_deb64($in['cifrado'] ?? null);

/* Un sobre de crypto_box_seal lleva 48 bytes fijos más el contenido.
   Un cuestionario sellado ronda el medio kilobyte; 16 kB es de sobra y
   hace imposible usar esto como disco. */
if (!$cifrado || strlen($cifrado) < 60 || strlen($cifrado) > 16384) {
    http_response_code(400);
    echo '{"error":"sobre no válido"}';
    exit;
}

/* Freno: diez por hora desde un mismo sitio, y trescientos al día en
   total. Una consulta de una persona no se acerca a eso; un robot que
   rellene la base de datos de basura, sí. */
$huella = hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''), $ADR['secreto']);
if (!adr_freno($db, 'hoja:' . $huella, 10, 3600) || !adr_freno($db, 'hoja:todas', 300, 86400)) {
    http_response_code(429);
    echo '{"error":"demasiados envíos seguidos; prueba en un rato o descarga el archivo"}';
    exit;
}

/* Los sobres cuelgan de un paciente (clave ajena). Las hojas no saben
   de quién son, así que cuelgan de un buzón: una fila sin activar, sin
   claves y con un correo que no existe, que no puede entrar ni recibir
   nada. La consola no la enseña como paciente. */
$db->prepare("INSERT OR IGNORE INTO pacientes (cod, correo, alta)
              VALUES ('hoja', 'buzon@hoja.invalid', ?)")
   ->execute([adr_ahora()]);
$db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, creado, clase)
              VALUES ('hoja', 1, 'Hoja', ?, ?, 'hoja')")
   ->execute([$cifrado, adr_ahora()]);
echo '{"ok":true}';
