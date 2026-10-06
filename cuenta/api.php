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

/** Avisa por correo de que hay algo pendiente, como mucho una vez cada
 *  N horas. Sin esto, asignar un cuestionario es hablarle a una pared:
 *  el paciente no entra «por si acaso». */
function adr_avisa(PDO $db, array $ADR, string $cod, int $horas = 6): bool {
    $q = $db->prepare('SELECT correo, ultimo_aviso FROM pacientes
                       WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cod]);
    $p = $q->fetch();
    if (!$p) return false;
    if ($p['ultimo_aviso'] && strtotime($p['ultimo_aviso']) > time() - $horas * 3600) return false;
    require_once __DIR__ . '/lib/correo.php';
    adr_correo_aviso($ADR, $p['correo']);
    $db->prepare('UPDATE pacientes SET ultimo_aviso = ? WHERE cod = ?')
       ->execute([adr_ahora(), $cod]);
    return true;
}

/** El recordatorio de las 48 horas.
 *
 *  Una medida programada que sigue sin contestar a las 48 horas recibe
 *  UN recordatorio, con el mismo texto neutro del aviso: ni el
 *  instrumento ni nada clínico. Uno solo por tarea; si a los cuatro
 *  días sigue igual, es Adrián quien escribe, no una máquina (el Mac lo
 *  saca de `pendientes`). Se llama en cada pasada del Mac. */
function adr_recuerda(PDO $db, array $ADR): int {
    $q = $db->query("SELECT DISTINCT t.cod FROM tareas t
                     JOIN pacientes p ON p.cod = t.cod AND p.activado IS NOT NULL
                     WHERE t.hecho IS NULL AND t.recordada IS NULL
                       AND t.origen = 'programada'
                       AND t.creado < datetime('now', '-48 hours')");
    $n = 0;
    foreach ($q->fetchAll() as $r) {
        /* Si el aviso de las 6 horas no deja mandarlo ahora, no se marca:
           se intenta en la pasada siguiente. */
        if (!adr_avisa($db, $ADR, $r['cod'])) continue;
        $db->prepare("UPDATE tareas SET recordada = ?
                      WHERE cod = ? AND hecho IS NULL AND recordada IS NULL
                        AND origen = 'programada'")
           ->execute([adr_ahora(), $r['cod']]);
        $n++;
    }
    return $n;
}

/** Las altas que nadie activó, fuera.
 *
 *  La informativa genera una cuenta por si la persona sigue. Si no
 *  sigue, en el servidor se queda su correo y nada más, pero se queda.
 *  Cuando ya no le queda ningún enlace de activación vivo (caducan a
 *  los 7 días), la fila se borra con todo lo suyo: minimización, no
 *  limpieza estética. Si después hay cita, el Mac la vuelve a dar de
 *  alta con el mismo código y no se nota. 'hoja' no es una persona. */
function adr_limpia_altas(PDO $db): int {
    $q = $db->prepare("SELECT cod FROM pacientes p
                       WHERE p.activado IS NULL AND p.cod <> 'hoja'
                         AND NOT EXISTS (SELECT 1 FROM fichas f
                                         WHERE f.cod = p.cod AND f.tipo = 'alta'
                                           AND f.usada IS NULL AND f.caduca >= ?)");
    $q->execute([time()]);
    $n = 0;
    foreach ($q->fetchAll() as $r) {
        $db->beginTransaction();
        foreach (['fichas', 'tareas', 'recurrencias', 'sobres'] as $t) {
            $db->prepare("DELETE FROM $t WHERE cod = ?")->execute([$r['cod']]);
        }
        $db->prepare('DELETE FROM accesos WHERE quien = ?')->execute([$r['cod']]);
        $db->prepare('DELETE FROM pacientes WHERE cod = ? AND activado IS NULL')->execute([$r['cod']]);
        $db->commit();
        $n++;
    }
    return $n;
}

/** Materializa las medidas periódicas que ya tocan.
 *
 *  No hay tarea programada en el alojamiento compartido, así que esto
 *  se llama cuando el Mac sincroniza. Un día de retraso en algo
 *  quincenal no cambia nada; depender de un cron que el alojamiento no
 *  garantiza, sí. */
function adr_vencen(PDO $db, array $ADR): int {
    $q = $db->query("SELECT * FROM recurrencias
                     WHERE activa = 1 AND proxima <= date('now')");
    $n = 0;
    foreach ($q->fetchAll() as $r) {
        /* Si la anterior sigue sin hacerse, NO se manda otra. Tres
           cuestionarios pendientes del mismo instrumento no miden mejor:
           agobian y se abandonan los tres. */
        $y = $db->prepare("SELECT 1 FROM tareas WHERE cod = ? AND titulo = ? AND hecho IS NULL
                             AND (caduca IS NULL OR caduca = '' OR substr(caduca, 1, 10) >= ?)");
        $y->execute([$r['cod'], $r['titulo'], adr_hoy()]);
        if (!$y->fetch()) {
            $db->prepare("INSERT INTO tareas (cod, titulo, plantilla, creado, tipo, origen)
                          VALUES (?,?,?,?,'cuestionario','programada')")
               ->execute([$r['cod'], $r['titulo'], $r['plantilla'], adr_ahora()]);
            adr_avisa($db, $ADR, $r['cod']);
            $n++;
        }
        $db->prepare("UPDATE recurrencias SET proxima = date(proxima, ?) WHERE id = ?")
           ->execute(['+' . (int)$r['cada_dias'] . ' days', $r['id']]);
    }
    return $n;
}
/* --- Plantillas retiradas -------------------------------------------
   El «CORE-10» que llevaba el portal NO era el CORE-10: sus ítems 4 a
   10 no son los del instrumento y el de riesgo apuntaba a otra
   pregunta. Se escribió sin fuente, que es justo lo que no se hace.
   Se reconoce por su bandera, que solo tenía esa versión.

   Aquí se cortan las recurrencias que lo siguen mandando y se retiran
   las entregas pendientes. No se borra nada: se marcan. */
/* Cada marca es un trozo de texto que solo tiene esa plantilla:
     · la bandera del CORE-10 falso;
     · un ítem del WSAS parafraseado de la misma sesión (dominios y
       escala bien, redacción sin fuente). */
const ADR_RETIRADAS = ['"core10_item9_autolesion"', 'Las aficiones que hago yo solo'];
function adr_retirada(string $plantilla_json): bool {
    foreach (ADR_RETIRADAS as $b) {
        if (strpos($plantilla_json, $b) !== false) return true;
    }
    return false;
}
function adr_retira(PDO $db): void {
    foreach (ADR_RETIRADAS as $b) {
        $como = '%' . $b . '%';
        $db->prepare('UPDATE recurrencias SET activa = 0 WHERE activa = 1 AND plantilla LIKE ?')
           ->execute([$como]);
        $db->prepare("UPDATE tareas SET hecho = 'retirada' WHERE hecho IS NULL AND plantilla LIKE ?")
           ->execute([$como]);
    }
}
adr_retira($db);

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

/* Todo lo que hace el Mac queda apuntado (acción y hora). */
/* Todo lo que el Mac CAMBIA queda apuntado. Lo que solo mira (recoger
   cada 5 minutos, las claves, el panel) no: con el recolector de fondo
   eran unas 1.500 filas al día que no decían nada y tapaban lo que sí. */
const ADR_RUTINA = ['recoger', 'recogido', 'claves', 'panel_mac', 'registro',
                    'firmas', 'pendientes', 'estado', 'por_reenviar'];
if (adr_es_el_mac($ADR) && $a !== '' && !in_array($a, ADR_RUTINA, true)) {
    adr_apunta($db, 'mac', $a);
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

    if (!$bien) {
        if ($f) adr_apunta($db, $f['cod'], 'entrada fallida', false);
        adr_json(['error' => 'no hemos podido entrar'], 401);
    }
    adr_apunta($db, $f['cod'], 'entrada');

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

    /* Al activar, la información de protección de datos se tiene que
       haber visto (la casilla). Se comprueba ANTES de gastar el enlace:
       si no, un fallo aquí dejaría a la persona sin enlace válido. */
    if ($a === 'activar' && ($in['aviso'] ?? '') !== ADR_AVISO_V) {
        adr_json(['error' => 'falta aceptar la información de protección de datos'], 400);
    }
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

    if ($a === 'activar') {
        /* Qué versión de la información leyó, y cuándo: lo que hay que
           poder enseñar si alguien lo pregunta. */
        $db->prepare('UPDATE pacientes SET aviso_privacidad = ? WHERE cod = ?')
           ->execute([ADR_AVISO_V . ' ' . adr_ahora(), $cod]);
    }
    adr_apunta($db, $cod, $a === 'activar' ? 'activación de la cuenta' : 'contraseña nueva');
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
    /* La copia firmada no sale como fila propia: va colgada del documento
       que firmó (copia_id), que es donde la persona la va a buscar. */
    $q = $db->prepare("SELECT s.id, s.titulo, s.creado, s.para_v, s.clase, s.requiere_firma, s.firmado,
                              (SELECT c.id FROM sobres c WHERE c.copia_de = s.id AND c.cod = s.cod
                                 AND c.direccion = 2 ORDER BY c.id DESC LIMIT 1) AS copia_id,
                              (SELECT c.para_v FROM sobres c WHERE c.copia_de = s.id AND c.cod = s.cod
                                 AND c.direccion = 2 ORDER BY c.id DESC LIMIT 1) AS copia_v
                       FROM sobres s
                       WHERE s.cod = ? AND s.direccion = 2
                         AND s.clase NOT IN ('progreso','mensaje','agenda','copia')
                       ORDER BY s.id DESC LIMIT 200");
    $q->execute([$cod]);

    /* El progreso va aparte: no es una fila de una lista, es una
       gráfica en su propia caja. */
    $g = $db->prepare("SELECT id, para_v FROM sobres
                       WHERE cod = ? AND direccion = 2 AND clase = 'progreso'
                       ORDER BY id DESC LIMIT 1");
    $g->execute([$cod]);
    $grafica = $g->fetch() ?: null;
    /* La próxima sesión: la publica el Mac sellada (día, hora, enlace de
       la videollamada). Como el progreso, una y la última. */
    $ag = $db->prepare("SELECT id, para_v FROM sobres
                        WHERE cod = ? AND direccion = 2 AND clase = 'agenda'
                        ORDER BY id DESC LIMIT 1");
    $ag->execute([$cod]);
    $agenda = $ag->fetch() ?: null;
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
    /* Lo caducado no sale: ni se puede hacer a tiempo ni debe quedarse
       en el contador para siempre. «antes del 10» incluye el día 10. */
    $t = $db->prepare("SELECT id, titulo, creado, caduca, tipo, para_v, plantilla FROM tareas
                       WHERE cod = ? AND hecho IS NULL
                         AND (caduca IS NULL OR caduca = '' OR substr(caduca, 1, 10) >= ?)
                       ORDER BY id");
    $t->execute([$cod, adr_hoy()]);
    /* Cuántas preguntas tiene cada cuestionario, para decirle cuánto
       le va a llevar («2 min»). La plantilla no sale entera aquí. */
    $tareas = [];
    foreach ($t->fetchAll() as $x) {
        $pl = json_decode((string)$x['plantilla'], true);
        $x['preguntas'] = ($x['tipo'] === 'cuestionario' && is_array($pl)) ? count($pl['items'] ?? []) : null;
        unset($x['plantilla']);
        $tareas[] = $x;
    }
    /* Y lo que ya ha hecho: qué y cuándo, sin puntuación (la puntuación
       vive en la gráfica). Sin esto, lo que entrega cae en un pozo. */
    $h = $db->prepare("SELECT titulo, tipo, hecho FROM tareas
                       WHERE cod = ? AND hecho IS NOT NULL AND hecho <> 'retirada'
                       ORDER BY hecho DESC LIMIT 40");
    $h->execute([$cod]);

    /* Los mensajes, en su propio hilo y en las dos direcciones. */
    $ms = $db->prepare("SELECT s.id, s.direccion, s.para_v, s.creado,
                               (SELECT c.id FROM sobres c WHERE c.copia_de = s.id AND c.cod = s.cod
                                  AND c.clase = 'copia' ORDER BY c.id DESC LIMIT 1) AS copia_id,
                               (SELECT c.para_v FROM sobres c WHERE c.copia_de = s.id AND c.cod = s.cod
                                  AND c.clase = 'copia' ORDER BY c.id DESC LIMIT 1) AS copia_v
                        FROM sobres s
                        WHERE s.cod = ? AND s.clase = 'mensaje'
                        ORDER BY s.id DESC LIMIT 60");
    $ms->execute([$cod]);

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
              'progreso' => $grafica ? ['id' => (int)$grafica['id'],
                                        'para_v' => (int)$grafica['para_v']] : null,
              'agenda' => $agenda ? ['id' => (int)$agenda['id'],
                                     'para_v' => (int)$agenda['para_v']] : null,
              'documentos' => $q->fetchAll(), 'tareas' => $tareas,
              'hechas' => $h->fetchAll(),
              'mensajes' => array_reverse($ms->fetchAll())]);
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

/* --- Mensajes y firmas, del lado del paciente ------------------------ */

/* Escribirle a Adrián. El mensaje va sellado a la clave del Mac, igual
   que un cuestionario: el servidor transporta y no lee.

   Y la pantalla dice lo que esto NO es. Un canal de mensajes dentro de
   una consulta es la cosa que más fácil se confunde con un timbre de
   urgencias, y la confusión aquí no se paga con una queja. */
case 'escribir': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $cifrado = adr_deb64($in['cifrado'] ?? null);
    if (!$cifrado || strlen($cifrado) > 65536) adr_json(['error' => 'mensaje no válido'], 400);
    if (!adr_freno($db, 'msg:' . $cod, 30, 3600)) adr_json(['error' => 'despacio'], 429);
    /* La copia para releerlo, sellada en su navegador a SU clave. Va
       colgada del mensaje (copia_de) y se borra con él. */
    $copia = !empty($in['copia']) ? adr_deb64($in['copia']) : null;
    if ($copia !== null && (!$copia || strlen($copia) > 65536)) adr_json(['error' => 'copia no válida'], 400);
    $db->prepare("INSERT INTO sobres (cod, direccion, cifrado, creado, clase)
                  VALUES (?,1,?,?,'mensaje')")
       ->execute([$cod, $cifrado, adr_ahora()]);
    $mid = (int)$db->lastInsertId();
    if ($copia) {
        $v = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ?');
        $v->execute([$cod]);
        $db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, para_v, creado, clase, copia_de)
                      VALUES (?,2,'Mi mensaje',?,?,?,'copia',?)")
           ->execute([$cod, $copia, (int)$v->fetch()['publica_v'], adr_ahora(), $mid]);
    }
    adr_json(['ok' => true]);
}

/* Firmar un documento.
   -------------------------------------------------------------------
   El servidor no sabe QUÉ se ha firmado —va cifrado— pero sí que se
   firmó y cuándo. Eso es exactamente lo que un consentimiento
   informado necesita poder demostrar, y lo único que puede guardar sin
   romper la promesa.

   Y se guarda además un sobre sellado con lo que el paciente teclea
   como firma, para que el Mac tenga la prueba completa. */
case 'firmar': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $id = (int)($in['id'] ?? 0);
    $cifrado = adr_deb64($in['cifrado'] ?? null);
    if (!$cifrado) adr_json(['error' => 'falta la firma'], 400);
    /* La copia para el paciente, sellada en su navegador a su clave: el
       texto que firmó, su nombre, la hora y la huella. El servidor
       guarda otro bloque que no puede leer. */
    $copia = !empty($in['copia']) ? adr_deb64($in['copia']) : null;
    if ($copia !== null && (!$copia || strlen($copia) > 512 * 1024)) adr_json(['error' => 'copia no válida'], 400);

    $q = $db->prepare("SELECT requiere_firma, firmado FROM sobres
                       WHERE id = ? AND cod = ? AND direccion = 2");
    $q->execute([$id, $cod]);
    $f = $q->fetch();
    if (!$f || !(int)$f['requiere_firma']) adr_json(['error' => 'ese documento no se firma'], 400);
    if ($f['firmado']) adr_json(['error' => 'ya estaba firmado'], 409);

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE sobres SET firmado = ? WHERE id = ?')
           ->execute([adr_ahora(), $id]);
        $db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, creado, clase)
                      VALUES (?,1,?,?,?,'firma')")
           ->execute([$cod, 'Firma del documento ' . $id, $cifrado, adr_ahora()]);
        if ($copia) {
            $v = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ?');
            $v->execute([$cod]);
            $db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, para_v, creado, clase, copia_de)
                          VALUES (?,2,?,?,?,?,'copia',?)")
               ->execute([$cod, 'Copia firmada', $copia, (int)$v->fetch()['publica_v'], adr_ahora(), $id]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        adr_json(['error' => 'no se ha podido guardar'], 500);
    }
    adr_json(['ok' => true, 'firmado' => adr_ahora()]);
}

/* Marcar un deber como hecho. Sin nota: la nota, si la hay, se manda
   como mensaje, que ya va sellado. */
case 'hecho': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $q = $db->prepare("SELECT tipo, hecho, plantilla FROM tareas WHERE id = ? AND cod = ?");
    $q->execute([(int)($in['id'] ?? 0), $cod]);
    $t = $q->fetch();
    /* Se marca a mano lo que no se entrega: un deber, o una herramienta
       que es solo para leer. Una herramienta para rellenar se cierra
       entregándola, no con un clic. */
    $es_lectura = $t && $t['tipo'] === 'herramienta'
        && ((json_decode((string)$t['plantilla'], true)['tipo'] ?? '') === 'lectura');
    if (!$t || ($t['tipo'] !== 'deber' && !$es_lectura)) adr_json(['error' => 'no existe'], 404);
    if ($t['hecho']) adr_json(['error' => 'ya estaba'], 409);
    $db->prepare('UPDATE tareas SET hecho = ? WHERE id = ? AND cod = ?')
       ->execute([adr_ahora(), (int)$in['id'], $cod]);
    adr_json(['ok' => true]);
}

/* --- Lo que hace el Mac ---------------------------------------------- */

case 'recoger': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    /* Cada vez que el Mac pasa por aquí se materializan las medidas que
       ya tocaban. Es el sustituto honesto de un cron que este
       alojamiento no garantiza. */
    adr_vencen($db, $ADR);
    adr_recuerda($db, $ADR);
    adr_limpia_altas($db);
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
    /* Y las copias de releer de los mensajes que se acaban de purgar:
       una copia sin su mensaje no la enseña nadie y solo ocupa. Las de
       los documentos firmados cuelgan de un sobre hacia el paciente,
       que no se purga, así que no les toca. */
    $db->exec("DELETE FROM sobres WHERE clase = 'copia' AND copia_de IS NOT NULL
               AND copia_de NOT IN (SELECT id FROM sobres)");

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
    /* 8 MB: cabe un PDF de unos 6 (va en base64 dentro del sobre). Un
       documento de texto no pasa de unas decenas de kilobytes. */
    if (!$cifrado || strlen($cifrado) > 8 * 1024 * 1024) adr_json(['error' => 'sobre no válido o demasiado grande'], 400);

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
    if (!in_array($clase, ['sesion', 'documento', 'progreso', 'agenda'], true)) $clase = 'documento';

    /* El progreso es UNO y el último. No es un documento que se
       colecciona: es una foto de cómo va, y tener cinco fotos viejas en
       la lista no ayuda a nadie. Se borra el anterior al publicar el
       nuevo — y se borra de verdad, porque el contenido que sustituye
       es el mismo dato desactualizado. */
    /* La agenda igual: la próxima sesión es una sola. */
    if ($clase === 'progreso' || $clase === 'agenda') {
        $db->prepare("DELETE FROM sobres WHERE cod = ? AND direccion = 2 AND clase = ?")
           ->execute([$cod, $clase]);
    }

    $db->prepare('INSERT INTO sobres (cod, direccion, titulo, cifrado, para_v, creado, clase,
                                    requiere_firma)
                  VALUES (?,2,?,?,?,?,?,?)')
       ->execute([$cod, $titulo, $cifrado, $para_v, adr_ahora(), $clase,
                  (int)!empty($in['requiere_firma'])]);
    /* La gráfica no se avisa: se republica cada vez que él recoge algo
       y avisaría cada dos por tres de algo que el paciente no ha
       pedido. Lo demás sí. */
    if ($clase !== 'progreso' && $clase !== 'agenda') adr_avisa($db, $ADR, $cod);
    adr_json(['ok' => true]);
}

/* Adrián le escribe al paciente. Sellado a la clave del paciente, con
   su versión: si ha cambiado la contraseña, el portal lo rechaza en vez
   de dejarle un mensaje que no puede abrir. */
case 'mensaje': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $cifrado = adr_deb64($in['cifrado'] ?? null);
    $para_v = (int)($in['para_v'] ?? 0);
    if (!$cifrado || strlen($cifrado) > 65536) adr_json(['error' => 'mensaje no válido'], 400);
    $q = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cod]);
    $p = $q->fetch();
    if (!$p) adr_json(['error' => 'ese paciente no tiene cuenta activa'], 404);
    if ($para_v !== (int)$p['publica_v']) {
        adr_json(['error' => 'clave caducada', 'v_actual' => (int)$p['publica_v']], 409);
    }
    $db->prepare("INSERT INTO sobres (cod, direccion, cifrado, para_v, creado, clase)
                  VALUES (?,2,?,?,?,'mensaje')")
       ->execute([$cod, $cifrado, $para_v, adr_ahora()]);
    adr_avisa($db, $ADR, $cod);
    adr_json(['ok' => true]);
}

/* Un deber: texto CIFRADO con lo que hay que hacer. «Registra tres
   domingos seguidos qué hiciste» dice algo de alguien, así que va
   sellado como todo lo demás. */
case 'deber': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $cifrado = adr_deb64($in['cifrado'] ?? null);
    $para_v = (int)($in['para_v'] ?? 0);
    $titulo = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['titulo'] ?? 'Tarea'))), 0, 80, 'UTF-8');
    if (!$cifrado) adr_json(['error' => 'falta el contenido'], 400);
    $q = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cod]);
    $p = $q->fetch();
    if (!$p) adr_json(['error' => 'ese paciente no tiene cuenta activa'], 404);
    if ($para_v !== (int)$p['publica_v']) {
        adr_json(['error' => 'clave caducada', 'v_actual' => (int)$p['publica_v']], 409);
    }
    $db->prepare("INSERT INTO tareas (cod, titulo, plantilla, creado, caduca, tipo, cifrado, para_v)
                  VALUES (?,?,'',?,?,'deber',?,?)")
       ->execute([$cod, $titulo ?: 'Tarea', adr_ahora(), $in['caduca'] ?? null, $cifrado, $para_v]);
    adr_avisa($db, $ADR, $cod);
    adr_json(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

/* Programar una medida para que se repita sola. */
case 'programar': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $cada = max(1, min(365, (int)($in['cada_dias'] ?? 14)));
    $plantilla = $in['plantilla'] ?? null;
    if (!is_array($plantilla) || empty($plantilla['items'])) {
        adr_json(['error' => 'la plantilla no tiene items'], 400);
    }
    if (adr_retirada(json_encode($plantilla, JSON_UNESCAPED_UNICODE))) {
        adr_json(['error' => 'esa plantilla está retirada: no es el instrumento que dice ser'], 409);
    }
    $titulo = mb_substr((string)($in['titulo'] ?? ($plantilla['instrumento'] ?? 'Cuestionario')), 0, 80, 'UTF-8');
    $q = $db->prepare('SELECT 1 FROM pacientes WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cod]);
    if (!$q->fetch()) adr_json(['error' => 'ese paciente no tiene cuenta activa'], 404);

    /* Una recurrencia por instrumento y paciente. Dos iguales mandarían
       el mismo cuestionario dos veces cada quincena. */
    $db->prepare('DELETE FROM recurrencias WHERE cod = ? AND titulo = ?')->execute([$cod, $titulo]);
    $db->prepare("INSERT INTO recurrencias (cod, titulo, plantilla, cada_dias, proxima, creada)
                  VALUES (?,?,?,?,date('now'),?)")
       ->execute([$cod, $titulo, json_encode($plantilla, JSON_UNESCAPED_UNICODE), $cada, adr_ahora()]);
    adr_vencen($db, $ADR);
    adr_json(['ok' => true]);
}

case 'desprogramar': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $db->prepare('UPDATE recurrencias SET activa = 0 WHERE cod = ? AND titulo = ?')
       ->execute([(string)($in['cod'] ?? ''), (string)($in['titulo'] ?? '')]);
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
    if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $cod) || $cod === 'hoja'
        || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        adr_json(['error' => 'cod o correo no válidos'], 400);
    }

    /* Aquí NO sale ningún correo, y es a propósito: el enlace lo da
       Adrián en persona. Lo que sí se comprueba antes de crear nada:

       · Ese código ya tiene cuenta ACTIVADA. Un enlace de alta nuevo
         serviría para ponerle otra contraseña y otra clave encima, y
         todo lo que el Mac le hubiera mandado dejaría de abrirse. No.
       · Ese correo ya es de OTRA cuenta (el correo es único). Antes
         esto acababa en un error 500 por la clave foránea de la ficha.
         Ahora se dice, con el código que ya tiene, para que el Mac
         vincule esa en vez de crear otra. */
    $q = $db->prepare('SELECT cod, correo, activado FROM pacientes WHERE cod = ? OR correo = ?');
    $q->execute([$cod, $correo]);
    $propia = null;
    foreach ($q->fetchAll() as $f) {
        if ($f['cod'] === $cod) { $propia = $f; continue; }
        adr_json(['error' => 'ese correo ya tiene cuenta', 'cod' => $f['cod'],
                  'activada' => $f['activado'] !== null], 409);
    }
    if ($propia && $propia['activado'] !== null) {
        adr_json(['error' => 'esa cuenta ya está activada', 'activada' => true], 409);
    }
    if (!$propia) {
        $db->prepare('INSERT INTO pacientes (cod, correo, alta) VALUES (?,?,?)')
           ->execute([$cod, $correo, adr_ahora()]);
    } elseif ($propia['correo'] !== $correo) {
        /* Sin activar todavía: se corrige el correo, que es lo único que hay. */
        $db->prepare('UPDATE pacientes SET correo = ? WHERE cod = ? AND activado IS NULL')
           ->execute([$correo, $cod]);
    }
    /* Ficha nueva siempre, también si la anterior había caducado: el
       enlace que devuelve es válido 7 días desde ahora. */
    $papel = adr_ficha($db, $cod, 'alta', 24 * 7);
    adr_json(['ok' => true, 'enlace' => $ADR['sitio'] . '/activar.php?p=' . $papel,
              'caduca' => date('c', time() + 24 * 7 * 3600)]);
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

    /* 'hoja' es el buzón de las hojas sueltas, no un paciente. */
    $ps = $db->query("SELECT cod, correo, alta, activado, publica_v, ultimo, aviso_privacidad
                      FROM pacientes WHERE cod <> 'hoja' ORDER BY alta")->fetchAll();
    $pend = [];
    foreach ($db->query('SELECT cod, COUNT(*) n FROM sobres
                         WHERE direccion = 1 AND recogido IS NULL
                         GROUP BY cod')->fetchAll() as $r) $pend[$r['cod']] = (int)$r['n'];
    $tar = []; $desde = [];
    foreach ($db->query('SELECT cod, COUNT(*) n, MIN(creado) d FROM tareas
                         WHERE hecho IS NULL GROUP BY cod')->fetchAll() as $r) {
        $tar[$r['cod']] = (int)$r['n'];
        $desde[$r['cod']] = $r['d'];
    }
    $viejos = [];
    foreach ($db->query('SELECT s.cod, COUNT(*) n FROM sobres s
                         JOIN pacientes p ON p.cod = s.cod
                         WHERE s.direccion = 2 AND s.para_v <> p.publica_v
                           AND s.clase NOT IN (\'herramienta\', \'copia\')
                         GROUP BY s.cod')->fetchAll() as $r) $viejos[$r['cod']] = (int)$r['n'];

    /* Lo que tiene publicado cada cuenta en las dos cajas que no son
       lista (gráfica y próxima sesión), con su versión de clave: si no
       coincide con la de la cuenta, el paciente no puede abrirlo. */
    $caja = [];
    foreach ($db->query("SELECT cod, clase, MAX(id) id FROM sobres
                         WHERE direccion = 2 AND clase IN ('progreso','agenda')
                         GROUP BY cod, clase")->fetchAll() as $r) {
        $x = $db->prepare('SELECT id, para_v, creado FROM sobres WHERE id = ?');
        $x->execute([$r['id']]);
        $caja[$r['cod']][$r['clase']] = $x->fetch();
    }
    $out = [];
    foreach ($ps as $p) {
        $out[] = [
            'cod' => $p['cod'], 'correo' => $p['correo'],
            'alta' => $p['alta'], 'activado' => $p['activado'],
            'v' => (int)$p['publica_v'], 'ultimo' => $p['ultimo'],
            'por_recoger' => $pend[$p['cod']] ?? 0,
            'tareas' => $tar[$p['cod']] ?? 0,
            /* Desde cuándo tiene algo sin hacer: lo más antiguo. */
            'tareas_desde' => $desde[$p['cod']] ?? null,
            'aviso_privacidad' => $p['aviso_privacidad'],
            'progreso' => $caja[$p['cod']]['progreso'] ?? null,
            'agenda' => $caja[$p['cod']]['agenda'] ?? null,
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
    $q = $db->prepare("SELECT s.id, s.titulo, s.clase, s.creado, s.para_v
                       FROM sobres s JOIN pacientes p ON p.cod = s.cod
                       WHERE s.cod = ? AND s.direccion = 2 AND s.para_v <> p.publica_v
                         AND s.clase NOT IN ('herramienta', 'copia')
                       ORDER BY s.id");
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

    /* Una HERRAMIENTA (plan de seguridad, autorregistro, guía) no es un
       cuestionario: no tiene ítems ni opciones, tiene bloques y campos.
       La valida a fondo la consola; aquí lo justo para no guardar algo
       que el navegador no pueda pintar. */
    if (($in['tipo'] ?? '') === 'herramienta') {
        if (!is_array($plantilla) || empty($plantilla['bloques']) || !is_array($plantilla['bloques'])
            || !preg_match('/^[a-z0-9-]{2,60}$/', (string)($plantilla['id'] ?? ''))
            || !in_array($plantilla['tipo'] ?? '', ['rellenable', 'lectura'], true)) {
            adr_json(['error' => 'la herramienta no tiene una forma válida'], 400);
        }
        $db->prepare("INSERT INTO tareas (cod, titulo, plantilla, creado, caduca, tipo)
                      VALUES (?,?,?,?,?,'herramienta')")
           ->execute([$cod, mb_substr($titulo ?: 'Herramienta', 0, 80, 'UTF-8'),
                      json_encode($plantilla, JSON_UNESCAPED_UNICODE),
                      adr_ahora(), $in['caduca'] ?? null]);
        $id = (int)$db->lastInsertId();
        adr_avisa($db, $ADR, $cod);
        adr_json(['ok' => true, 'id' => $id]);
    }

    /* Se valida la forma aquí, no en el navegador. Una plantilla sin
       `items` pinta una pantalla vacía, y una con el bloque `riesgo`
       apuntando al ítem que no es vigila la pregunta equivocada — y
       eso no falla con un error, falla en silencio. */
    if (!is_array($plantilla) || empty($plantilla['items']) || empty($plantilla['opciones'])) {
        adr_json(['error' => 'la plantilla no tiene items u opciones'], 400);
    }
    if (adr_retirada(json_encode($plantilla, JSON_UNESCAPED_UNICODE))) {
        adr_json(['error' => 'esa plantilla está retirada: no es el instrumento que dice ser'], 409);
    }
    /* Respuestas distintas por ítem (AUDIT): tantas listas como ítems.
       Una de menos desplaza todas las respuestas un ítem. */
    $pi = $plantilla['opciones_por_item'] ?? null;
    if ($pi !== null && (!is_array($pi) || count($pi) !== count($plantilla['items']))) {
        adr_json(['error' => 'opciones_por_item no cuadra con los items'], 400);
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
    $id = (int)$db->lastInsertId();
    adr_avisa($db, $ADR, $cod);
    adr_json(['ok' => true, 'id' => $id]);
}

/* El paciente pide la plantilla de UNA tarea suya. */
case 'tarea': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $q = $db->prepare('SELECT id, titulo, plantilla, hecho, tipo, cifrado, para_v
                       FROM tareas WHERE id = ? AND cod = ?');
    $q->execute([(int)($_GET['id'] ?? 0), $cod]);
    $t = $q->fetch();
    if (!$t) adr_json(['error' => 'no existe'], 404);
    if ($t['hecho'] !== null) adr_json(['error' => 'ya la habías entregado'], 409);

    /* Un deber lleva su texto CIFRADO; un cuestionario, su plantilla en
       claro. No es incoherencia: el PHQ-9 es público y el mismo para
       todo el mundo, y «registra tres domingos seguidos qué hiciste»
       dice algo de alguien. Se cifra lo que dice algo. */
    if ($t['tipo'] === 'deber') {
        adr_json(['id' => (int)$t['id'], 'titulo' => $t['titulo'], 'tipo' => 'deber',
                  'cifrado' => adr_b64($t['cifrado']), 'para_v' => (int)$t['para_v']]);
    }
    adr_json(['id' => (int)$t['id'], 'titulo' => $t['titulo'],
              'tipo' => $t['tipo'] === 'herramienta' ? 'herramienta' : 'cuestionario',
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

    $copia = null; $copia_v = null;
    if (!empty($in['copia'])) {
        $copia = adr_deb64($in['copia']);
        if (!$copia || strlen($copia) > 65536) adr_json(['error' => 'copia no válida'], 400);
        $p = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ?');
        $p->execute([$cod]);
        $copia_v = (int)$p->fetch()['publica_v'];
    }

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO sobres (cod, direccion, titulo, cifrado, creado, clase)
                      VALUES (?,1,?,?,?,\'ejercicio\')')
           ->execute([$cod, $t['titulo'], $cifrado, adr_ahora()]);
        $db->prepare('UPDATE tareas SET hecho = ? WHERE id = ? AND cod = ?')
           ->execute([adr_ahora(), $id, $cod]);
        /* La copia propia de una herramienta: lo que ha escrito, sellado
           en su navegador a SU clave. Así su plan de seguridad sigue en
           «Mis herramientas» después de enviarlo, que es justo cuando
           hace falta. El servidor guarda otro bloque que no puede leer. */
        if ($copia) {
            $db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, para_v, creado, clase)
                          VALUES (?,2,?,?,?,?,'herramienta')")
               ->execute([$cod, $t['titulo'], $copia, $copia_v, adr_ahora()]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        adr_json(['error' => 'no se ha podido guardar'], 500);
    }
    adr_json(['ok' => true]);
}

/* --- Catálogos -------------------------------------------------------
   El sistema clínico sube aquí su catálogo cada vez que lo exporta.

   Lo que hace el servidor con él es poco, y a propósito: comprueba que
   ha llegado entero (sha256), lo guarda fuera de la carpeta pública y
   dice si las hojas sueltas de /h/ siguen al día. NO lo valida ni lo
   «aplica»: eso lo hace la consola del Mac con el mismo importador de
   siempre, que es donde viven las plantillas. Un segundo validador en
   PHP sería una segunda copia de las mismas reglas, y dos copias acaban
   diciendo cosas distintas. */
case 'catalogo': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $tipo = (string)($in['tipo'] ?? '');
    $contenido = (string)($in['contenido'] ?? '');
    $sha = strtolower((string)($in['sha256'] ?? ''));
    if (!in_array($tipo, ['instrumentos', 'herramientas'], true)) adr_json(['error' => 'tipo no válido'], 400);
    if ($contenido === '' || strlen($contenido) > 2 * 1024 * 1024) adr_json(['error' => 'contenido vacío o demasiado grande'], 400);
    if (!hash_equals(hash('sha256', $contenido), $sha)) adr_json(['error' => 'la sha256 no coincide: no ha llegado entero'], 400);
    $lista = json_decode($contenido, true);
    if (!is_array($lista) || !array_is_list($lista) || !$lista) adr_json(['error' => 'no es una lista JSON'], 400);
    $clave = $tipo === 'instrumentos' ? 'instrumento' : 'id';
    foreach ($lista as $d) {
        if (!is_array($d) || !is_string($d[$clave] ?? null)) adr_json(['error' => "cada entrada necesita «{$clave}»"], 400);
    }

    $dir = dirname($ADR['datos']) . '/catalogos';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents("$dir/$tipo.json", $contenido, LOCK_EX);
    $db->prepare('INSERT INTO ajustes (clave, valor, puesto) VALUES (?,?,?)
                  ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor, puesto = excluded.puesto')
       ->execute(["catalogo_$tipo", $sha, adr_ahora()]);

    /* ¿Las hojas de /h/ dicen lo mismo que el catálogo? Se compara el
       texto (cabecera, ítems, opciones) y el bloque de riesgo con lo que
       lleva cada hoja dentro. Si no coincide se dice, y se rehacen en el
       siguiente despliegue: una hoja pública no se reescribe desde aquí,
       porque lleva dentro la clave del Mac y no debe depender de nada
       que llegue por la red. */
    $hojas = [];
    if ($tipo === 'instrumentos') {
        $por = [];
        foreach ($lista as $d) $por[$d['instrumento']] = $d;
        foreach (['phq9' => 'PHQ-9', 'gad7' => 'GAD-7'] as $f => $nombre) {
            $html = @file_get_contents(ADR_RAIZ . "/../h/$f.html");
            if (!$html || !preg_match('/const PLANTILLA = (\{.*\});/', $html, $m) || !isset($por[$nombre])) {
                $hojas[$f] = 'sin_comparar';
                continue;
            }
            $h = json_decode($m[1], true);
            $c = $por[$nombre];
            $ops = array_map(fn($o) => [(int)$o[0], (string)$o[1]], $c['opciones'] ?? []);
            $hops = array_map(fn($o) => [(int)$o[0], (string)$o[1]], $h['opciones'] ?? []);
            $igual = ($h['items'] ?? null) === ($c['items'] ?? null) && $hops === $ops
                && ($h['riesgo'] ?? null) == ($c['riesgo'] ?? null)
                && ($h['version_items'] ?? null) === ($c['version_items'] ?? null);
            $hojas[$f] = $igual ? 'al_dia' : 'desfasada';
        }
    }
    adr_json(['ok' => true, 'sha256' => $sha, 'hojas' => $hojas]);
}

/* Las plantillas vigentes, desde la consola, después de importar.
   Las medidas programadas y los cuestionarios aún sin contestar llevan
   una COPIA de la plantilla de cuando se mandaron. Sin esto, cambiar un
   texto en el catálogo no cambiaba lo que siguen recibiendo: una
   recurrencia de hace un mes seguía mandando la redacción vieja.
   Solo se tocan las pendientes con el mismo número de ítems, para no
   descuadrar lo que alguien tenga a medias. */
case 'refrescar_plantillas': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $ps = $in['plantillas'] ?? [];
    if (!is_array($ps)) adr_json(['error' => 'plantillas no válidas'], 400);
    $rec = 0; $tar = 0;
    foreach ($ps as $titulo => $p) {
        if (!is_array($p) || empty($p['items']) || empty($p['opciones'])) continue;
        $j = json_encode($p, JSON_UNESCAPED_UNICODE);
        if (adr_retirada($j)) continue;
        $u = $db->prepare('UPDATE recurrencias SET plantilla = ? WHERE titulo = ? AND activa = 1 AND plantilla <> ?');
        $u->execute([$j, (string)$titulo, $j]);
        $rec += $u->rowCount();
        $q = $db->prepare("SELECT id, plantilla FROM tareas
                           WHERE titulo = ? AND hecho IS NULL AND tipo = 'cuestionario'");
        $q->execute([(string)$titulo]);
        foreach ($q->fetchAll() as $t) {
            $vieja = json_decode((string)$t['plantilla'], true);
            if ($t['plantilla'] === $j || count($vieja['items'] ?? []) !== count($p['items'])) continue;
            $db->prepare('UPDATE tareas SET plantilla = ? WHERE id = ?')->execute([$j, $t['id']]);
            $tar++;
        }
    }
    adr_json(['ok' => true, 'recurrencias' => $rec, 'pendientes' => $tar]);
}

/* El paciente ve sus propios accesos: si alguien ha entrado sin que él
   lo sepa, aquí se ve. */
case 'accesos': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $q = $db->prepare('SELECT cuando, que, ok FROM accesos WHERE quien = ? ORDER BY id DESC LIMIT 15');
    $q->execute([$cod]);
    adr_json(['accesos' => $q->fetchAll()]);
}

/* Y el Mac, el registro entero (sin el ruido de sus propias recogidas). */
case 'registro': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $q = $db->query("SELECT cuando, quien, que, ok FROM accesos
                     WHERE NOT (quien = 'mac' AND que IN ('recoger','recogido','panel_mac','claves','registro'))
                     ORDER BY id DESC LIMIT 300");
    adr_json(['accesos' => $q->fetchAll()]);
}

/* Qué documentos para firmar tiene cada uno y si los ha firmado. */
case 'firmas': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $q = $db->query('SELECT cod, titulo, creado, firmado FROM sobres
                     WHERE direccion = 2 AND requiere_firma = 1 ORDER BY id');
    adr_json(['firmas' => $q->fetchAll()]);
}

/* Lo pendiente, tarea a tarea, con su fecha. Para que el Mac le diga a
   Adrián quién lleva cuatro días sin contestar y le escriba él. El
   título es lo que ya está en claro en el servidor («PHQ-9»). */
case 'pendientes': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $q = $db->query("SELECT t.cod, t.id, t.titulo, t.tipo, t.origen, t.creado, t.recordada
                     FROM tareas t JOIN pacientes p ON p.cod = t.cod
                     WHERE t.hecho IS NULL AND p.activado IS NOT NULL
                     ORDER BY t.cod, t.creado");
    adr_json(['pendientes' => $q->fetchAll()]);
}

/* El estado de la instalación, para que el Mac pueda comprobarlo sin
   que nadie abra el panel de Hostinger. Ningún secreto: si hay clave
   de Brevo, no cuál es. */
case 'estado': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    adr_json(['ok' => true,
              'correo' => empty($ADR['brevo']) ? 'mail' : 'brevo',
              'remite' => $ADR['remite'] ?? null,
              'php' => PHP_VERSION,
              'pacientes' => (int)$db->query("SELECT COUNT(*) FROM pacientes WHERE cod <> 'hoja'")->fetchColumn(),
              'activados' => (int)$db->query("SELECT COUNT(*) FROM pacientes WHERE activado IS NOT NULL AND cod <> 'hoja'")->fetchColumn()]);
}

default:
    adr_json(['error' => 'no'], 404);
}
