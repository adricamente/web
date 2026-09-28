<?php
/* ===================================================================
   La puerta. Todo lo que el navegador y el Mac le piden al servidor.
   -------------------------------------------------------------------
   Regla que no se rompe: aquí no entra ni sale un dato clínico en
   claro. Lo que viaja son bloques que este servidor no puede abrir.
   Si algún día alguien añade un endpoint que reciba una puntuación,
   ha roto el diseño entero, no una función.
   =================================================================== */

declare(strict_types=1);
require __DIR__ . '/lib/arranque.php';

$db = adr_db($ADR);
$a  = $_GET['a'] ?? '';
$in = adr_entrada();

/* Un verificador de mentira, con el mismo coste que uno de verdad.
   Sirve para que «este correo no existe» tarde lo mismo que «esta
   contraseña no es», que si no el reloj cuenta lo que la respuesta
   calla.

   Y tiene que ser un hash VÁLIDO, no uno inventado: password_verify()
   con un hash mal formado vuelve enseguida, que es justo lo que se
   quería evitar. Éste está generado de verdad, con los mismos
   parámetros, sobre una contraseña aleatoria que nadie conoce ni
   necesita conocer. */
const ADR_SOMBRA = '$argon2id$v=19$m=65536,t=4,p=1$TEJQYmppQXlTQVhjN2JHTg'
                 . '$RP3OLKQyBAojzMUGMWQfp2yJ+VUxJxN7f8vEL1wA/IM';

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

switch ($a) {

/* --- La sal ---------------------------------------------------------
   SIEMPRE contesta, exista o no la cuenta. Ver el comentario largo en
   arranque.php: decir «ese correo no existe» aquí es decir quién está
   en tratamiento. */
case 'sal': {
    $correo = adr_correo_normal((string)($in['correo'] ?? ''));
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        adr_json(['sal' => adr_b64(adr_sal_falsa('-', $ADR['secreto']))]);
    }
    if (!adr_freno($db, 'sal:' . adr_huella(), 60)) adr_json(['error' => 'despacio'], 429);

    $q = $db->prepare('SELECT sal FROM pacientes WHERE correo = ? AND activado IS NOT NULL');
    $q->execute([$correo]);
    $f = $q->fetch();
    $sal = ($f && $f['sal']) ? $f['sal'] : adr_sal_falsa($correo, $ADR['secreto']);
    adr_json(['sal' => adr_b64($sal)]);
}

/* --- Entrar -------------------------------------------------------- */
case 'entrar': {
    $correo = adr_correo_normal((string)($in['correo'] ?? ''));
    $auth   = (string)($in['auth'] ?? '');
    if (!adr_freno($db, 'ent:' . $correo) || !adr_freno($db, 'ent:' . adr_huella(), 30)) {
        adr_json(['error' => 'demasiados intentos'], 429);
    }
    $q = $db->prepare('SELECT cod, envuelta, verificador FROM pacientes
                       WHERE correo = ? AND activado IS NOT NULL');
    $q->execute([$correo]);
    $f = $q->fetch();

    /* Si no hay cuenta se verifica igual contra la sombra, para que
       tarde lo mismo. Y el mensaje de error es el MISMO en los dos
       casos: «no hemos podido entrar». */
    $bien = $f
        ? password_verify($auth, $f['verificador'])
        : (password_verify($auth, ADR_SOMBRA) && false);

    if (!$bien) adr_json(['error' => 'no hemos podido entrar'], 401);

    adr_freno_limpia($db, 'ent:' . $correo);
    $db->prepare('UPDATE pacientes SET ultimo = ? WHERE cod = ?')
       ->execute([adr_ahora(), $f['cod']]);
    adr_sesion_pon($f['cod'], $ADR['secreto']);
    adr_json(['ok' => true, 'envuelta' => adr_b64($f['envuelta'])]);
}

/* --- Activar (primera vez) y restablecer -----------------------------
   Los dos hacen lo mismo: el navegador ya ha generado una pareja de
   claves nueva y una privada envuelta, y aquí solo se guardan.

   La diferencia entre activar y restablecer es de significado, no de
   código: al restablecer, la pareja es NUEVA, así que lo que el Mac
   ya había mandado deja de poder leerse. Por eso `publica_v` sube:
   es la señal de que el Mac tiene que reenviar. Sin ese número, el
   paciente entraría y vería sus documentos en gris para siempre sin
   que nadie se enterase. */
case 'activar':
case 'reset': {
    if (!adr_freno($db, 'act:' . adr_huella(), 20)) adr_json(['error' => 'despacio'], 429);

    $cod = adr_ficha_gasta($db, (string)($in['papel'] ?? ''), $a === 'activar' ? 'alta' : 'reset');
    if ($cod === null) adr_json(['error' => 'ese enlace ya no vale'], 400);

    $sal      = adr_deb64($in['sal'] ?? null, 16);
    $envuelta = adr_deb64($in['envuelta'] ?? null);
    $publica  = adr_deb64($in['publica'] ?? null, 32);
    $auth     = (string)($in['auth'] ?? '');
    if (!$sal || !$envuelta || !$publica || strlen($auth) < 20) {
        adr_json(['error' => 'faltan datos'], 400);
    }

    $db->prepare('UPDATE pacientes SET sal = ?, envuelta = ?, publica = ?,
                    publica_v = publica_v + 1, verificador = ?, activado = ?
                  WHERE cod = ?')
       ->execute([$sal, $envuelta, $publica,
                  password_hash($auth, PASSWORD_ARGON2ID), adr_ahora(), $cod]);

    /* Al restablecer, lo que el Mac ya había dejado no se puede abrir
       con la clave nueva. Se marca para que el panel lo diga en vez
       de enseñar documentos que no abren, y para que el Mac sepa qué
       reenviar. No se borra: el Mac tiene el original, pero borrar
       aquí sería decidir por él. */
    if ($a === 'reset') {
        $db->prepare('UPDATE sobres SET recogido = NULL WHERE cod = ? AND direccion = 2')
           ->execute([$cod]);
    }

    adr_sesion_pon($cod, $ADR['secreto']);
    adr_json(['ok' => true]);
}

/* --- He olvidado la contraseña ---------------------------------------
   Contesta lo mismo exista o no la cuenta. Lo que cambia es si sale
   un correo, y eso no se ve desde fuera. */
case 'olvide': {
    $correo = adr_correo_normal((string)($in['correo'] ?? ''));
    if (!adr_freno($db, 'olv:' . $correo, 5, 3600) || !adr_freno($db, 'olv:' . adr_huella(), 20)) {
        adr_json(['ok' => true]);   // ni siquiera el freno se nota
    }
    $q = $db->prepare('SELECT cod FROM pacientes WHERE correo = ? AND activado IS NOT NULL');
    $q->execute([$correo]);
    if ($f = $q->fetch()) {
        require_once __DIR__ . '/lib/correo.php';
        $papel = adr_ficha($db, $f['cod'], 'reset', 2);
        adr_correo_reset($ADR, $correo, $ADR['sitio'] . '/clave.php?p=' . $papel);
    }
    adr_json(['ok' => true]);
}

case 'salir': {
    setcookie('adr_s', '', ['expires' => 1, 'path' => '/', 'secure' => adr_es_https(),
                            'httponly' => true, 'samesite' => 'Strict']);
    adr_json(['ok' => true]);
}

/* --- Lo que el paciente ve y manda ----------------------------------- */

case 'mios': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $q = $db->prepare('SELECT id, titulo, creado, para_v FROM sobres
                       WHERE cod = ? AND direccion = 2 ORDER BY id DESC LIMIT 100');
    $q->execute([$cod]);
    $p = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ?');
    $p->execute([$cod]);
    adr_json(['v' => (int)$p->fetch()['publica_v'], 'documentos' => $q->fetchAll()]);
}

case 'abrir': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $q = $db->prepare('SELECT cifrado, para_v FROM sobres WHERE id = ? AND cod = ? AND direccion = 2');
    $q->execute([(int)($_GET['id'] ?? 0), $cod]);
    $f = $q->fetch();
    if (!$f) adr_json(['error' => 'no existe'], 404);
    adr_json(['cifrado' => adr_b64($f['cifrado']), 'para_v' => (int)$f['para_v']]);
}

case 'subir': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $cifrado = adr_deb64($in['cifrado'] ?? null);
    /* Tope de tamaño: un cuestionario sellado son ~500 bytes. 64 kB es
       generosísimo y a la vez impide que esto sea un disco duro. */
    if (!$cifrado || strlen($cifrado) > 65536) adr_json(['error' => 'sobre no válido'], 400);
    if (!adr_freno($db, 'sub:' . $cod, 40, 3600)) adr_json(['error' => 'despacio'], 429);
    $db->prepare('INSERT INTO sobres (cod, direccion, cifrado, creado) VALUES (?,1,?,?)')
       ->execute([$cod, $cifrado, adr_ahora()]);
    adr_json(['ok' => true]);
}

/* --- Lo que hace el Mac ---------------------------------------------- */

case 'recoger': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $q = $db->query('SELECT id, cod, cifrado, creado FROM sobres
                     WHERE direccion = 1 AND recogido IS NULL ORDER BY id LIMIT 50');
    $out = [];
    foreach ($q->fetchAll() as $s) {
        $out[] = ['id' => (int)$s['id'], 'cod' => $s['cod'],
                  'creado' => $s['creado'], 'cifrado' => adr_b64($s['cifrado'])];
    }
    adr_json(['sobres' => $out]);
}

case 'recogido': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
    if (!$ids) adr_json(['ok' => true]);
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE sobres SET recogido = ? WHERE id IN ($marcas) AND direccion = 1")
       ->execute([adr_ahora(), ...$ids]);
    adr_json(['ok' => true, 'marcados' => count($ids)]);
}

/* Las claves públicas vigentes, con su versión. El Mac mira esto para
   saber a qué clave sellar y a quién le ha cambiado desde la última
   vez — que es justo quien necesita que le reenvíen. */
case 'claves': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $q = $db->query('SELECT cod, publica, publica_v, activado FROM pacientes
                     WHERE activado IS NOT NULL');
    $out = [];
    foreach ($q->fetchAll() as $p) {
        $out[] = ['cod' => $p['cod'], 'v' => (int)$p['publica_v'],
                  'publica' => adr_b64($p['publica'])];
    }
    adr_json(['claves' => $out]);
}

case 'publicar': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $cifrado = adr_deb64($in['cifrado'] ?? null);
    $para_v = (int)($in['para_v'] ?? 0);
    if (!$cifrado || strlen($cifrado) > 2 * 1024 * 1024) adr_json(['error' => 'sobre no válido'], 400);

    /* El título se ve en la lista SIN abrir nada, así que es lo único
       que el servidor puede leer de un documento. Por eso lo escribe
       el Mac a mano y no sale del contenido: «Sesión 4» sí,
       «Reestructuración del episodio depresivo» no. Se recorta y se
       le quitan los saltos, que si no la lista se rompe. */
    $titulo = trim(preg_replace('/\s+/u', ' ', (string)($in['titulo'] ?? '')));
    $titulo = mb_substr($titulo, 0, 80, 'UTF-8');

    $q = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cod]);
    $p = $q->fetch();
    if (!$p) adr_json(['error' => 'ese paciente no tiene cuenta activa'], 404);

    /* Si el Mac sella contra una clave vieja, el paciente no podría
       abrirlo y no habría ningún error: vería un documento en gris.
       Así que se comprueba aquí, que es donde se sabe. */
    if ($para_v !== (int)$p['publica_v']) {
        adr_json(['error' => 'clave caducada', 'v_actual' => (int)$p['publica_v']], 409);
    }

    $db->prepare('INSERT INTO sobres (cod, direccion, titulo, cifrado, para_v, creado)
                  VALUES (?,2,?,?,?,?)')
       ->execute([$cod, $titulo, $cifrado, $para_v, adr_ahora()]);
    adr_json(['ok' => true]);
}

/* Dar de alta a alguien: crea la fila y devuelve el enlace de
   activación. Lo manda el Mac, no una página: aquí no hay registro
   abierto y no lo va a haber. Quien entra, entra porque Adrián lo ha
   dado de alta. */
case 'alta': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $correo = adr_correo_normal((string)($in['correo'] ?? ''));
    if (!preg_match('/^[a-z0-9-]{3,24}$/i', $cod) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        adr_json(['error' => 'cod o correo no válidos'], 400);
    }
    $db->prepare('INSERT OR IGNORE INTO pacientes (cod, correo, alta) VALUES (?,?,?)')
       ->execute([$cod, $correo, adr_ahora()]);
    $papel = adr_ficha($db, $cod, 'alta', 24 * 7);
    adr_json(['ok' => true, 'enlace' => $ADR['sitio'] . '/activar.php?p=' . $papel]);
}

default:
    adr_json(['error' => 'no'], 404);
}
