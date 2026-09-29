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

/* La llave que abre lib/. Ver el comentario de lib/arranque.php. */
const ADR_DENTRO = true;
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

require __DIR__ . '/lib/sesion.php';

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
    $q = $db->prepare('SELECT id, titulo, creado, para_v, clase FROM sobres
                       WHERE cod = ? AND direccion = 2 ORDER BY id DESC LIMIT 200');
    $q->execute([$cod]);
    /* Se devuelve también su propia clave pública: crypto_box_seal_open
       la necesita, y el navegador no la tiene guardada en ningún sitio
       —lo que guarda es la privada envuelta—. No es un secreto: es
       pública, y sale de la misma cuenta que acaba de autenticarse. */
    $p = $db->prepare('SELECT publica, publica_v FROM pacientes WHERE cod = ?');
    $p->execute([$cod]);
    $f = $p->fetch();
    /* Y lo que tiene pendiente. Va aquí y no en otra llamada porque
       el panel las enseña juntas: dos peticiones para pintar una
       pantalla es una pantalla que se dibuja a trozos. */
    $t = $db->prepare('SELECT id, titulo, creado, caduca FROM tareas
                       WHERE cod = ? AND hecho IS NULL ORDER BY id');
    $t->execute([$cod]);

    /* Y la pública del Mac, que es a la que el navegador sella lo que
       entrega. Es pública: va aquí y no en una petición aparte. */
    $m = $db->query("SELECT valor FROM ajustes WHERE clave = 'mac_publica'")->fetch();

    /* Y su propio código de cuenta. No es un secreto —es suyo y ya está
       autenticado— y hace falta para meterlo DENTRO del sobre sellado:
       el servidor sabe de quién es cada entrega, pero el Mac que lo
       descifra no, y sin el código no sabría en qué historia archivarlo.

       Con esto los dos caminos son idénticos: la hoja suelta de /h/ lo
       saca del fragmento de la URL, y la cuenta de aquí. El Mac abre
       los dos igual. */
    adr_json(['v' => (int)$f['publica_v'], 'publica' => adr_b64($f['publica']),
              'cod' => $cod,
              'mac_publica' => $m ? $m['valor'] : null,
              'documentos' => $q->fetchAll(), 'tareas' => $t->fetchAll()]);
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
    if ($ids) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE sobres SET recogido = ? WHERE id IN ($marcas) AND direccion = 1")
           ->execute([adr_ahora(), ...$ids]);
    }

    /* Y aquí se borra lo que el Mac ya se llevó hace tiempo.
       -------------------------------------------------------------------
       Ojo al orden: esto va DESPUÉS de la lista vacía, no antes. La
       primera versión salía por la puerta de atrás cuando no había nada
       que marcar —`if (!$ids) adr_json(...)`— y entonces un Mac que
       sincroniza a diario sin recoger nada nuevo no purgaba NUNCA. El
       plazo de conservación existía en el código y no ocurría en la
       base de datos. Lo encontró la prueba.

       Esto faltaba, y no es una optimización: es minimización de datos.
       Un cuestionario recogido ya vive en el Mac, que es su sitio. La
       copia cifrada del servidor deja de tener función el día que se
       recoge, y a partir de ahí solo es un montón que crece.

       «Está cifrado» no es una respuesta a «por qué lo sigues
       guardando». Lo es a «qué pasa si te lo roban», que es otra
       pregunta. Un tratamiento de datos de salud necesita un plazo
       escrito, y el plazo es éste.

       No se borra en el acto sino pasados unos días a propósito: un
       disco que se estropea el martes por la tarde no puede llevarse
       por delante lo que se recogió el martes por la mañana. El margen
       es la ventana para darse cuenta.

       Los documentos que van HACIA el paciente (direccion = 2) no se
       tocan: ésos son su copia y su derecho a tenerla. */
    $dias = max(1, (int)($ADR['dias_sobres'] ?? 30));
    $db->prepare("DELETE FROM sobres
                  WHERE direccion = 1 AND recogido IS NOT NULL
                    AND recogido < datetime('now', ?)")
       ->execute(['-' . $dias . ' days']);

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

    $clase = (string)($in['clase'] ?? 'documento');
    if (!in_array($clase, ['sesion', 'documento'], true)) $clase = 'documento';

    $db->prepare('INSERT INTO sobres (cod, direccion, titulo, cifrado, para_v, creado, clase)
                  VALUES (?,2,?,?,?,?,?)')
       ->execute([$cod, $titulo, $cifrado, $para_v, adr_ahora(), $clase]);
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
    /* base64url: letras, cifras, guion y guion BAJO.
       -------------------------------------------------------------------
       El patrón de antes era `[a-z0-9-]{3,24}` y habría rechazado en
       silencio la mitad de los códigos que genera el sistema clínico,
       porque su invitación opaca es base64url y ahí el `_` es una
       letra más. No habría fallado con «carácter no válido»: habría
       fallado con «cod no válido» en un alta suelta, cada varias
       altas, sin patrón aparente. */
    if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $cod) || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        adr_json(['error' => 'cod o correo no válidos'], 400);
    }
    $db->prepare('INSERT OR IGNORE INTO pacientes (cod, correo, alta) VALUES (?,?,?)')
       ->execute([$cod, $correo, adr_ahora()]);
    $papel = adr_ficha($db, $cod, 'alta', 24 * 7);
    adr_json(['ok' => true, 'enlace' => $ADR['sitio'] . '/activar.php?p=' . $papel]);
}

/* Todo lo que la consola del Mac necesita para pintar su pantalla, en
   una sola llamada.
   -------------------------------------------------------------------
   Una consola que pide cinco cosas para dibujarse se dibuja a trozos y
   parece rota en una conexión mala. Y aquí no sale ni un dato clínico:
   quién tiene cuenta, si la ha activado, con qué versión de clave, y
   CUÁNTOS sobres y tareas hay pendientes. Cuántos, no qué. */
case 'panel_mac': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);

    $ps = $db->query('SELECT cod, correo, alta, activado, publica_v, ultimo
                      FROM pacientes ORDER BY alta')->fetchAll();
    $pend = [];
    foreach ($db->query('SELECT cod, COUNT(*) n FROM sobres
                         WHERE direccion = 1 AND recogido IS NULL
                         GROUP BY cod')->fetchAll() as $r) $pend[$r['cod']] = (int)$r['n'];
    $tar = [];
    foreach ($db->query('SELECT cod, COUNT(*) n FROM tareas
                         WHERE hecho IS NULL GROUP BY cod')->fetchAll() as $r) $tar[$r['cod']] = (int)$r['n'];
    $viejos = [];
    foreach ($db->query('SELECT s.cod, COUNT(*) n FROM sobres s
                         JOIN pacientes p ON p.cod = s.cod
                         WHERE s.direccion = 2 AND s.para_v <> p.publica_v
                         GROUP BY s.cod')->fetchAll() as $r) $viejos[$r['cod']] = (int)$r['n'];

    $out = [];
    foreach ($ps as $p) {
        $out[] = [
            'cod' => $p['cod'], 'correo' => $p['correo'],
            'alta' => $p['alta'], 'activado' => $p['activado'],
            'v' => (int)$p['publica_v'], 'ultimo' => $p['ultimo'],
            'por_recoger' => $pend[$p['cod']] ?? 0,
            'tareas' => $tar[$p['cod']] ?? 0,
            /* Cuántos documentos suyos quedaron sellados a una clave
               anterior. Es lo que hay que reenviar después de que
               alguien restablezca la contraseña, y si no se enseña en
               algún sitio no se entera nadie. */
            'por_reenviar' => $viejos[$p['cod']] ?? 0,
        ];
    }
    $m = $db->query("SELECT valor FROM ajustes WHERE clave = 'mac_publica'")->fetch();
    adr_json(['pacientes' => $out, 'mac_publica' => $m ? $m['valor'] : null]);
}

/* Los documentos de un paciente que hay que volver a sellar, con su
   texto NO: solo el id y el título. El texto está cifrado a una clave
   que ya nadie tiene — por eso hay que reenviarlo desde el original
   del Mac. */
case 'por_reenviar': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $q = $db->prepare('SELECT s.id, s.titulo, s.clase, s.creado, s.para_v
                       FROM sobres s JOIN pacientes p ON p.cod = s.cod
                       WHERE s.cod = ? AND s.direccion = 2 AND s.para_v <> p.publica_v
                       ORDER BY s.id');
    $q->execute([(string)($_GET['cod'] ?? '')]);
    adr_json(['sobres' => $q->fetchAll()]);
}

/* El Mac deja aquí su clave PÚBLICA. La sube él, no se copia a mano a
   un fichero de configuración: así la clave que usa el portal sale
   forzosamente del mismo sitio donde está la privada. Pegar a mano la
   pública de otro par no da ningún error — simplemente los
   cuestionarios dejan de poder abrirse, y se descubre tarde. */
case 'mac_publica': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $pub = adr_deb64($in['publica'] ?? null, 32);
    if (!$pub) adr_json(['error' => 'eso no es una X25519 de 32 bytes'], 400);
    $db->prepare('INSERT INTO ajustes (clave, valor, puesto) VALUES (?,?,?)
                  ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor,
                                                   puesto = excluded.puesto')
       ->execute(['mac_publica', adr_b64($pub), adr_ahora()]);
    adr_json(['ok' => true]);
}

/* --- Las tareas ------------------------------------------------------ */

/* El Mac asigna un cuestionario. La plantilla viaja y se guarda EN
   CLARO: el PHQ-9 es público y el mismo para todo el mundo. Lo que se
   sella son las respuestas. */
case 'asignar': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $titulo = trim(preg_replace('/\s+/u', ' ', (string)($in['titulo'] ?? '')));
    $plantilla = $in['plantilla'] ?? null;

    $q = $db->prepare('SELECT 1 FROM pacientes WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cod]);
    if (!$q->fetch()) adr_json(['error' => 'ese paciente no tiene cuenta activa'], 404);

    /* Se valida la forma aquí, no en el navegador. Una plantilla sin
       `items` pinta una pantalla vacía, y una con el bloque `riesgo`
       apuntando al ítem que no es vigila la pregunta equivocada — y
       eso no falla con un error, falla en silencio. */
    if (!is_array($plantilla) || empty($plantilla['items']) || empty($plantilla['opciones'])) {
        adr_json(['error' => 'la plantilla no tiene items u opciones'], 400);
    }
    $r = $plantilla['riesgo'] ?? null;
    if ($r !== null) {
        if (!isset($r['item'], $r['umbral'], $r['bandera'])
            || (int)$r['item'] < 1 || (int)$r['item'] > count($plantilla['items'])) {
            adr_json(['error' => 'el bloque riesgo apunta fuera de los items'], 400);
        }
    }

    $db->prepare('INSERT INTO tareas (cod, titulo, plantilla, creado, caduca)
                  VALUES (?,?,?,?,?)')
       ->execute([$cod, mb_substr($titulo ?: 'Cuestionario', 0, 80, 'UTF-8'),
                  json_encode($plantilla, JSON_UNESCAPED_UNICODE),
                  adr_ahora(), $in['caduca'] ?? null]);
    adr_json(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

/* El paciente pide la plantilla de UNA tarea suya. */
case 'tarea': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $q = $db->prepare('SELECT id, titulo, plantilla, hecho FROM tareas
                       WHERE id = ? AND cod = ?');
    $q->execute([(int)($_GET['id'] ?? 0), $cod]);
    $t = $q->fetch();
    if (!$t) adr_json(['error' => 'no existe'], 404);
    if ($t['hecho'] !== null) adr_json(['error' => 'ya la habías entregado'], 409);
    adr_json(['id' => (int)$t['id'], 'titulo' => $t['titulo'],
              'plantilla' => json_decode($t['plantilla'], true)]);
}

/* Y la entrega. El sobre y la marca de hecho van en la MISMA
   transacción: si se guardara el sobre y fallara la marca, la persona
   volvería a ver la tarea pendiente y la contestaría dos veces; al
   revés, se perdería lo que acaba de escribir. */
case 'entregar': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $id = (int)($in['id'] ?? 0);
    $cifrado = adr_deb64($in['cifrado'] ?? null);
    if (!$cifrado || strlen($cifrado) > 65536) adr_json(['error' => 'sobre no válido'], 400);
    if (!adr_freno($db, 'ent:' . $cod, 60, 3600)) adr_json(['error' => 'despacio'], 429);

    $q = $db->prepare('SELECT titulo, hecho FROM tareas WHERE id = ? AND cod = ?');
    $q->execute([$id, $cod]);
    $t = $q->fetch();
    if (!$t) adr_json(['error' => 'no existe'], 404);
    if ($t['hecho'] !== null) adr_json(['error' => 'ya la habías entregado'], 409);

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO sobres (cod, direccion, titulo, cifrado, creado, clase)
                      VALUES (?,1,?,?,?,\'ejercicio\')')
           ->execute([$cod, $t['titulo'], $cifrado, adr_ahora()]);
        $db->prepare('UPDATE tareas SET hecho = ? WHERE id = ? AND cod = ?')
           ->execute([adr_ahora(), $id, $cod]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        adr_json(['error' => 'no se ha podido guardar'], 500);
    }
    adr_json(['ok' => true]);
}

default:
    adr_json(['error' => 'no'], 404);
}
