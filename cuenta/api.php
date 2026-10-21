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

/* Un fallo interno nunca más mudo (13-10: «correo_google» dio un 500 sin
   cuerpo y no había forma de saber qué era). Siempre JSON; al Mac,
   además, qué ha fallado y dónde (nunca datos: el mensaje del error y
   la línea). Al navegador, solo «error interno». */
function adr_fallo_interno(string $tipo, string $msg, string $fichero, int $linea): void {
    error_log("adr: $tipo: $msg en $fichero:$linea");
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    $mac = ($_SERVER['HTTP_X_ADR_MAC'] ?? '') !== '' && isset($GLOBALS['ADR'])
        && !empty($GLOBALS['ADR']['mac']) && hash_equals((string)$GLOBALS['ADR']['mac'], (string)$_SERVER['HTTP_X_ADR_MAC']);
    echo json_encode(['error' => 'error interno del servidor'] + ($mac ? [
        'tipo' => $tipo, 'detalle' => mb_substr($msg, 0, 300), 'donde' => basename($fichero) . ':' . $linea] : []),
        JSON_UNESCAPED_UNICODE);
}
set_exception_handler(function (Throwable $e) {
    adr_fallo_interno(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        adr_fallo_interno('fatal', $e['message'], $e['file'], $e['line']);
    }
});

$db = adr_db($ADR);

/** Avisa por correo de que hay algo pendiente, como mucho una vez cada
 *  N horas. Sin esto, asignar un cuestionario es hablarle a una pared:
 *  el paciente no entra «por si acaso».
 *  17-10: eran 20 horas y se tragaba avisos de verdad (mandar algo por la
 *  tarde cuando ya hubo un aviso por la mañana no avisaba). Ahora 2 horas:
 *  tres cosas mandadas seguidas siguen siendo UN correo. */
function adr_avisa(PDO $db, array $ADR, string $cod, int $horas = 2): bool {
    $q = $db->prepare('SELECT correo, ultimo_aviso FROM pacientes
                       WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cod]);
    $p = $q->fetch();
    if (!$p) return false;
    if ($p['ultimo_aviso'] && strtotime($p['ultimo_aviso']) > time() - $horas * 3600) return false;
    require_once __DIR__ . '/lib/correo.php';
    /* Solo cuenta como avisado si el correo ha salido de verdad: si no,
       la próxima vez se vuelve a intentar en vez de darlo por hecho. */
    $r = adr_correo_aviso($ADR, $p['correo'], $cod);
    /* En la cola cuenta como avisado: lo manda el Mac en su pasada. Si
       no, cada pasada encolaría otro igual. */
    if (!$r['enviado'] && ($r['error'] ?? '') !== 'en_cola') return false;
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
require_once __DIR__ . '/lib/correo.php';
require_once __DIR__ . '/lib/herramientas.php';

/* Todo lo que hace el Mac queda apuntado (acción y hora). */
/* Todo lo que el Mac CAMBIA queda apuntado. Lo que solo mira (recoger
   cada 5 minutos, las claves, el panel) no: con el recolector de fondo
   eran unas 1.500 filas al día que no decían nada y tapaban lo que sí. */
const ADR_RUTINA = ['recoger', 'recogido', 'claves', 'panel_mac', 'registro',
                    'firmas', 'pendientes', 'estado', 'por_reenviar', 'estado_pagos', 'estado_correo',
                    'correos_pendientes'];
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
        /* Lo manda el Mac en su pasada (5 minutos, o lo que tarde en
           despertarse): el enlace vale 24 horas y no 2, para no llegar ya
           caducado. */
        $horas = 24;
        $papel = adr_ficha($db, $f['cod'], 'reset', $horas);
        adr_correo_reset($ADR, $correo, $ADR['sitio'] . '/clave.php?p=' . $papel, $horas);
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
    $q = $db->prepare("SELECT s.id, s.titulo, s.creado, s.para_v, s.clase, s.requiere_firma, s.firmado, s.firma_v,
                              (SELECT c.id FROM sobres c WHERE c.copia_de = s.id AND c.cod = s.cod
                                 AND c.direccion = 2 ORDER BY c.id DESC LIMIT 1) AS copia_id,
                              (SELECT c.para_v FROM sobres c WHERE c.copia_de = s.id AND c.cod = s.cod
                                 AND c.direccion = 2 ORDER BY c.id DESC LIMIT 1) AS copia_v
                       FROM sobres s
                       WHERE s.cod = ? AND s.direccion = 2
                         AND s.clase NOT IN ('progreso','mensaje','agenda','copia','historial','objetivos','pago')
                       ORDER BY s.creado DESC, s.id DESC LIMIT 200");
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
    /* Lo que hizo antes de tener cuenta (en papel, por correo…): una
       lista que publica el Mac, sellada y sin puntuaciones. Una y la
       última, como el progreso. Se mezcla en «Lo que has completado». */
    $hi = $db->prepare("SELECT id, para_v FROM sobres
                        WHERE cod = ? AND direccion = 2 AND clase = 'historial'
                        ORDER BY id DESC LIMIT 1");
    $hi->execute([$cod]);
    $historial = $hi->fetch() ?: null;
    /* «Mis objetivos»: uno y el último, sellado, sin aviso. */
    $ob = $db->prepare("SELECT id, para_v FROM sobres
                        WHERE cod = ? AND direccion = 2 AND clase = 'objetivos'
                        ORDER BY id DESC LIMIT 1");
    $ob->execute([$cod]);
    $objetivos = $ob->fetch() ?: null;
    /* Sus justificantes de pago. El servidor sabe CUÁNDO subió uno, no
       qué hay dentro: el justificante va sellado al Mac. Para que ella
       vea a qué sesión corresponde cada uno, se guarda además una copia
       mínima sellada a SU clave (sin el fichero), como en los mensajes. */
    $ju = $db->prepare("SELECT s.id, s.creado,
                               (SELECT c.id FROM sobres c WHERE c.copia_de = s.id AND c.cod = s.cod
                                  AND c.clase = 'copia' ORDER BY c.id DESC LIMIT 1) AS copia_id,
                               (SELECT c.para_v FROM sobres c WHERE c.copia_de = s.id AND c.cod = s.cod
                                  AND c.clase = 'copia' ORDER BY c.id DESC LIMIT 1) AS copia_v
                        FROM sobres s
                        WHERE s.cod = ? AND s.direccion = 1 AND s.clase = 'justificante'
                        ORDER BY s.id DESC LIMIT 24");
    $ju->execute([$cod]);
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
    $t = $db->prepare("SELECT id, titulo, creado, caduca, tipo, para_v, plantilla, modo, hecho, actualizado FROM tareas
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
        $x['modo'] = adr_modo_fila($x);
        $x['herr_id'] = $x['tipo'] === 'herramienta' ? adr_herr_id($x) : null;
        unset($x['plantilla']);
        $tareas[] = $x;
    }
    /* 17-10: las herramientas que viven después de empezarlas (un
       documento que se edita siempre, un registro de entradas), con lo
       justo para pintar la lista: cuántas entradas, de qué días, y su
       recordatorio si se lo ha puesto. */
    $vv = $db->prepare("SELECT id, titulo, creado, caduca, tipo, para_v, plantilla, modo, hecho, actualizado FROM tareas
                        WHERE cod = ? AND tipo = 'herramienta' AND hecho IS NOT NULL AND hecho <> 'retirada'
                        ORDER BY id");
    $vv->execute([$cod]);
    $recs = $db->prepare('SELECT tarea, hora FROM recordatorios WHERE cod = ?');
    $recs->execute([$cod]);
    $rec = array_column($recs->fetchAll(), 'hora', 'tarea');
    $vivas = [];
    foreach ($vv->fetchAll() as $x) {
        $x['modo'] = adr_modo_fila($x);
        $x['herr_id'] = adr_herr_id($x);
        unset($x['plantilla']);
        if ($x['modo'] === 'una') continue;
        $vivas[] = $x;
    }
    $marca_h = function (array &$x) use ($db, $rec) {
        if (($x['tipo'] ?? '') !== 'herramienta') return;
        $x['recordar'] = $rec[$x['id']] ?? null;
        if ($x['modo'] === 'registro') {
            $e = $db->prepare("SELECT COUNT(*) AS n, MAX(fecha) AS ultima FROM entradas WHERE tarea = ? AND borrada = 0");
            $e->execute([$x['id']]);
            $f = $e->fetch();
            $x['entradas'] = (int)$f['n']; $x['ultima'] = $f['ultima'];
            $h = $db->prepare("SELECT 1 FROM entradas WHERE tarea = ? AND borrada = 0 AND fecha = ?");
            $h->execute([$x['id'], adr_hoy()]);
            $x['hoy'] = (bool)$h->fetch();
        }
    };
    foreach ($tareas as &$x) $marca_h($x);
    unset($x);
    foreach ($vivas as &$x) $marca_h($x);
    unset($x);
    /* Y lo que ya ha hecho: qué y cuándo, sin puntuación (la puntuación
       vive en la gráfica). Sin esto, lo que entrega cae en un pozo. */
    $h = $db->prepare("SELECT titulo, tipo, hecho, plantilla, modo FROM tareas
                       WHERE cod = ? AND hecho IS NOT NULL AND hecho <> 'retirada'
                       ORDER BY hecho DESC LIMIT 80");
    $h->execute([$cod]);
    /* Lo vivo y los registros no «se completan»: siguen en «Tus herramientas». */
    $hechas = array_slice(array_values(array_map(fn($x) => ['titulo' => $x['titulo'], 'tipo' => $x['tipo'], 'hecho' => $x['hecho']],
        array_filter($h->fetchAll(), fn($x) => adr_modo_fila($x) === 'una'))), 0, 40);

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
              'historial' => $historial ? ['id' => (int)$historial['id'],
                                           'para_v' => (int)$historial['para_v']] : null,
              'objetivos' => $objetivos ? ['id' => (int)$objetivos['id'],
                                           'para_v' => (int)$objetivos['para_v']] : null,
              'justificantes' => $ju->fetchAll(),
              'pagos' => (function () use ($db, $ADR, $cod) {
                  /* Sus citas de pago (de los últimos 90 días y los próximos 60),
                     con el enlace para pagarlas. Sin datos clínicos: fecha,
                     importe y si está pagada. */
                  require_once __DIR__ . '/lib/pagos.php';
                  adr_pagos_tabla($db);
                  $q = $db->prepare("SELECT inicio, importe, pagado_en, token, reembolsado FROM citas
                                     WHERE cod = ? AND estado = 'activa' AND inicio >= ? AND inicio <= ?
                                     ORDER BY inicio");
                  $q->execute([$cod, date('c', time() - 90 * 86400), date('c', time() + 60 * 86400)]);
                  return array_map(fn($c) => ['inicio' => $c['inicio'], 'importe' => (int)$c['importe'],
                      'pagado' => $c['pagado_en'] !== null, 'reembolsado' => (bool)$c['reembolsado'],
                      'url_pago' => $c['pagado_en'] === null ? adr_pagos_url($ADR, $c['token']) : null], $q->fetchAll());
              })(),
              'documentos' => (function () use ($q, $db, $cod) {
                  /* Un documento para firmar sin firmar con clave vieja que ya
                     tiene su gemelo con la clave nueva no se enseña: sobra. */
                  $v = (int)($db->query('SELECT publica_v FROM pacientes WHERE cod = ' . $db->quote($cod))->fetch()['publica_v'] ?? 0);
                  $filas = $q->fetchAll();
                  $nuevos = [];
                  foreach ($filas as $f) if ((int)$f['requiere_firma'] && (int)$f['para_v'] === $v) $nuevos[$f['titulo']] = true;
                  return array_values(array_filter($filas, fn($f) => !((int)$f['requiere_firma'] && !$f['firmado']
                      && (int)$f['para_v'] !== $v && isset($nuevos[$f['titulo']]))));
              })(), 'tareas' => $tareas, 'herramientas_vivas' => $vivas,
              'hechas' => $hechas,
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

/* --- El justificante de pago ----------------------------------------
   Una foto o un PDF de la transferencia, sellado en su navegador a la
   clave del Mac: el servidor guarda algo que no puede abrir, como todo
   lo demás. Va aparte de `subir` por el tamaño: un cuestionario son
   500 bytes y una foto, aunque el navegador la reduzca antes, puede
   llegar a un par de megas; un PDF escaneado, a más.

   La copia que acompaña es mínima —a qué sesión es, cómo se llamaba el
   fichero, cuándo— y va sellada a SU clave, para que en su cuenta vea
   «enviado el…» sin que el servidor sepa de qué sesión se trata. */
case 'justificante': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $cifrado = adr_deb64($in['cifrado'] ?? null);
    if (!$cifrado) adr_json(['error' => 'justificante no válido'], 400);
    /* 15 MB de archivo son unos 20 MB sellados (va en base64 dentro). */
    if (strlen($cifrado) > 21 * 1024 * 1024) {
        adr_json(['error' => 'el archivo es demasiado grande (máximo 15 MB)'], 413);
    }
    $copia = !empty($in['copia']) ? adr_deb64($in['copia']) : null;
    if ($copia !== null && (!$copia || strlen($copia) > 4096)) adr_json(['error' => 'copia no válida'], 400);
    if (!adr_freno($db, 'just:' . $cod, 12, 86400)) adr_json(['error' => 'despacio'], 429);
    $db->prepare("INSERT INTO sobres (cod, direccion, cifrado, creado, clase)
                  VALUES (?,1,?,?,'justificante')")
       ->execute([$cod, $cifrado, adr_ahora()]);
    $jid = (int)$db->lastInsertId();
    if ($copia) {
        $v = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ?');
        $v->execute([$cod]);
        $db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, para_v, creado, clase, copia_de)
                      VALUES (?,2,'Mi justificante',?,?,?,'copia',?)")
           ->execute([$cod, $copia, (int)$v->fetch()['publica_v'], adr_ahora(), $jid]);
    }
    adr_apunta($db, $cod, 'justificante de pago');
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
    /* Con archivo adjunto (17-10): hasta 15 MB de archivo, unos 21 MB sellados. */
    $adj = !empty($in['adjunto']);
    if (!$cifrado || strlen($cifrado) > ($adj ? 21 * 1024 * 1024 : 65536)) {
        adr_json(['error' => $adj ? 'el archivo es demasiado grande (máximo 15 MB)' : 'mensaje no válido'], $adj ? 413 : 400);
    }
    if (!adr_freno($db, 'msg:' . $cod, 30, 3600)) adr_json(['error' => 'despacio'], 429);
    if ($adj && !adr_freno($db, 'adj:' . $cod, 20, 86400)) adr_json(['error' => 'demasiados archivos hoy'], 429);
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
    adr_apunta($db, $cod, $adj ? 'mensaje con archivo' : 'mensaje');
    /* A Adrián le llega un correo (17-10: si alguien escribe, puede ser
       algo). Sin contenido: el servidor no lo puede leer. Como mucho uno
       cada 10 minutos por cuenta. */
    require_once __DIR__ . '/lib/correo.php';
    if (adr_freno($db, 'avisoadr:' . $cod, 1, 600)) {
        $a = (string)((adr_google_cred($ADR) ?: [])['remite'] ?? ($ADR['remite'] ?? 'hola@adricamente.com'));
        $t = "Te han escrito en el portal" . ($adj ? ", con un archivo adjunto" : "") . ".\n\nCuenta: $cod\n"
           . "Hora: " . (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('d/m/Y H:i') . "\n\n"
           . "Ábrelo en la consola (Hoy → Mensajes). Por seguridad, el correo no lleva el contenido.\n";
        adr_manda($ADR, $a, 'Mensaje nuevo en el portal' . ($adj ? ' (con archivo)' : ''), $t,
                  adr_correo_marco('Mensaje nuevo', adr_correo_t('Te han escrito en el portal')
                      . adr_correo_p(nl2br(htmlspecialchars($t))), 'Ábrelo en la consola.', true), 'aviso_adrian');
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
/* Antes de firmar el consentimiento, la contraseña otra vez: se
   comprueba como al entrar y se devuelve un permiso de diez minutos para
   firmar, más lo que el servidor ve en ese momento (su hora, la IP y el
   navegador), que el navegador mete DENTRO del sobre sellado al Mac. */
case 'confirmar_firma': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    if (!adr_freno($db, 'firma:' . $cod, 10, 900)) adr_json(['error' => 'demasiados intentos'], 429);
    $q = $db->prepare('SELECT envuelta, verificador FROM pacientes WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cod]);
    $f = $q->fetch();
    if (!$f || !password_verify((string)($in['auth'] ?? ''), $f['verificador'])) {
        adr_apunta($db, $cod, 'contraseña mal al firmar', false);
        adr_json(['error' => 'la contraseña no es correcta'], 401);
    }
    $hasta = time() + 600;
    $ip = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    adr_json(['ok' => true, 'envuelta' => adr_b64($f['envuelta']),
              'permiso' => $hasta . '.' . hash_hmac('sha256', "firma:$cod|$hasta", $ADR['secreto']),
              'servidor' => ['hora' => adr_ahora(), 'ip' => mb_substr($ip, 0, 45),
                             'navegador' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]]);
}

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
    /* 1 = solo el nombre; 2 = con datos y la casilla antigua («no se
       graban», que contradecía el apartado 4); 3 = la buena (14-10). */
    $version = in_array((int)($in['version'] ?? 1), [1, 2, 3], true) ? (int)$in['version'] : 1;
    $huella = preg_match('/^[0-9a-f]{64}$/', (string)($in['huella'] ?? '')) ? (string)$in['huella'] : null;

    $q = $db->prepare("SELECT titulo, requiere_firma, firmado, firma_v FROM sobres
                       WHERE id = ? AND cod = ? AND direccion = 2");
    $q->execute([$id, $cod]);
    $f = $q->fetch();
    if (!$f || !(int)$f['requiere_firma']) adr_json(['error' => 'ese documento no se firma'], 400);
    $es_ci = (bool)preg_match('/consentimiento/iu', (string)$f['titulo']);
    /* El consentimiento solo se firma con el formulario completo y la
       contraseña recién comprobada. */
    if ($es_ci) {
        if ($version !== 3) adr_json(['error' => 'Actualiza la página (hemos corregido el formulario) y vuelve a firmar.'], 409);
        [$hasta, $mac] = array_pad(explode('.', (string)($in['permiso'] ?? ''), 2), 2, '');
        if ((int)$hasta < time() || !hash_equals(hash_hmac('sha256', "firma:$cod|" . (int)$hasta, $ADR['secreto']), $mac)) {
            adr_json(['error' => 'Vuelve a escribir tu contraseña y firma otra vez.'], 403);
        }
    }
    /* Volver a firmar solo vale para completar los datos de un
       consentimiento firmado con el formulario antiguo. */
    if ($f['firmado'] && !($es_ci && (int)($f['firma_v'] ?? 1) < 2)) adr_json(['error' => 'ya estaba firmado'], 409);
    $refirma = (bool)$f['firmado'];

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE sobres SET firmado = ?, firma_v = ?, firma_huella = ? WHERE id = ?')
           ->execute([adr_ahora(), $version, $huella, $id]);
        $db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, creado, clase)
                      VALUES (?,1,?,?,?,'firma')")
           ->execute([$cod, ($refirma ? 'Firma con datos del documento ' : 'Firma del documento ') . $id, $cifrado, adr_ahora()]);
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
    /* El acuse por correo: un registro independiente y con fecha (queda
       en Gmail). Sin título ni nada clínico, como todos los del portal. */
    if ($es_ci) {
        require_once __DIR__ . '/lib/correo.php';
        $c = $db->prepare('SELECT correo FROM pacientes WHERE cod = ?');
        $c->execute([$cod]);
        if ($a = ($c->fetch()['correo'] ?? null)) {
            $cuando = (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('d/m/Y \a \l\a\s H:i');
            $t = "Has firmado un documento en tu cuenta de adricamente el $cuando.\n\n"
               . ($huella ? "Huella del texto firmado (SHA-256): $huella\n\n" : '')
               . "Tienes la copia firmada en tu cuenta, en «Documentos»: " . $ADR['sitio'] . "\n\n"
               . "Si no has sido tú, contesta a este correo cuanto antes.\n";
            $hh = adr_correo_t('Documento firmado')
                . adr_correo_p('Has firmado un documento en tu cuenta de adricamente el <strong>' . $cuando . '</strong>.')
                . adr_correo_p('Tienes la copia firmada en tu cuenta, en «Documentos».')
                . adr_correo_boton($ADR['sitio'], 'Ver mi copia firmada')
                . ($huella ? adr_correo_p('Huella del texto firmado (SHA-256):<br><span style="word-break:break-all">' . $huella . '</span>', true) : '')
                . adr_correo_p('Si no has sido tú, contesta a este correo cuanto antes.');
            adr_manda($ADR, $a, 'Has firmado un documento en tu cuenta', $t,
                adr_correo_marco('Has firmado un documento', $hh, 'Firmado el ' . $cuando . '. Tienes la copia en tu cuenta.', true),
                'firma', $cod);
        }
    }
    adr_json(['ok' => true, 'firmado' => adr_ahora(), 'version' => $version]);
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
    /* 17-10: los recordatorios que se ponen los pacientes y el correo de
       la mañana a Adrián. Si fallan, la recogida sigue. */
    try { adr_recordatorios($db, $ADR); adr_resumen_dia($db, $ADR); } catch (Throwable $e) {}
    try { require_once __DIR__ . '/lib/pagos.php'; adr_visperas($db, $ADR); } catch (Throwable $e) {}
    /* Con los justificantes (fotos y PDF de varios megas) cincuenta
       sobres pueden ser cientos de megas en una sola respuesta. Se corta
       por tamaño: lo que no quepa sale en la pasada siguiente, cinco
       minutos después. Siempre sale al menos uno. */
    $q = $db->query('SELECT id, cod, cifrado, creado, clase FROM sobres
                     WHERE direccion = 1 AND recogido IS NULL ORDER BY id LIMIT 50');
    $out = []; $bytes = 0;
    foreach ($q->fetchAll() as $s) {
        $bytes += strlen($s['cifrado']);
        if ($out && $bytes > 20 * 1024 * 1024) break;
        $out[] = ['id' => (int)$s['id'], 'cod' => $s['cod'], 'clase' => $s['clase'],
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
    /* La copia firmada, vuelta a sellar por el Mac tras un cambio de
       contraseña (la original la selló el navegador del paciente con su
       clave vieja). Va colgada del documento firmado y no avisa. */
    if ($clase === 'copia') {
        $de = (int)($in['copia_de'] ?? 0);
        $q = $db->prepare('SELECT id FROM sobres WHERE id = ? AND cod = ? AND direccion = 2 AND firmado IS NOT NULL');
        $q->execute([$de, $cod]);
        if (!$q->fetch()) adr_json(['error' => 'copia_de no es un documento firmado de esa cuenta'], 404);
        $db->prepare("DELETE FROM sobres WHERE cod = ? AND direccion = 2 AND clase = 'copia' AND copia_de = ?")->execute([$cod, $de]);
        $db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, para_v, creado, clase, copia_de)
                      VALUES (?,2,'Copia firmada',?,?,?,'copia',?)")->execute([$cod, $cifrado, $para_v, adr_ahora(), $de]);
        adr_json(['ok' => true, 'copia_de' => $de]);
    }
    if (!in_array($clase, ['sesion', 'documento', 'progreso', 'agenda', 'historial', 'objetivos', 'pago'], true)) $clase = 'documento';

    /* Lo de antes del portal (pacientes que llevan meses): el Mac lo
       publica con `atrasado` y la fecha en que pasó de verdad. Así sale
       ordenado donde le toca y NO avisa: diecinueve correos de golpe a
       quien acaba de activar la cuenta serían diecinueve sustos.
       `firmado` (AAAA-MM-DD) es para el consentimiento firmado en papel:
       sale en «Documentos» como firmado, igual que si lo hubiera firmado
       aquí, y no se le pide firmarlo otra vez. */
    $atrasado = !empty($in['atrasado']);
    $fecha = (string)($in['fecha'] ?? '');
    $creado = ($atrasado && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) ? $fecha . 'T12:00:00' : adr_ahora();
    $firmado = (string)($in['firmado'] ?? '');
    $firmado = preg_match('/^\d{4}-\d{2}-\d{2}$/', $firmado) ? $firmado . 'T12:00:00' : null;

    /* Sustituir un resumen o documento ya publicado por su versión
       corregida: se borra el anterior (mismo título, misma clase, sin
       firma de por medio), el nuevo queda en su misma fecha y NO avisa,
       que no es nada nuevo. Si no hay ninguno que sustituir, error: no
       se publica un duplicado sin querer. */
    $sustituye = trim(preg_replace('/\s+/u', ' ', (string)($in['sustituye'] ?? '')));
    $sustituidos = 0;
    if ($sustituye !== '') {
        if (!in_array($clase, ['sesion', 'documento'], true)) adr_json(['error' => 'solo se sustituyen sesiones o documentos'], 400);
        /* También un documento para firmar que aún NO se ha firmado (15-10:
           tras un cambio de contraseña el Mac vuelve a sellar el
           consentimiento pendiente). Lo firmado no se sustituye nunca. */
        $v = $db->prepare("SELECT id, creado FROM sobres WHERE cod = ? AND direccion = 2 AND titulo = ?
                           AND clase IN ('sesion','documento') AND firmado IS NULL
                           ORDER BY creado ASC");
        $v->execute([$cod, mb_substr($sustituye, 0, 80, 'UTF-8')]);
        $viejos = $v->fetchAll();
        if (!$viejos) adr_json(['error' => 'no encuentro ningún documento publicado con ese título para sustituir'], 404);
        $creado = $viejos[0]['creado'];
        $atrasado = true;
        foreach ($viejos as $x) {
            $db->prepare('DELETE FROM sobres WHERE copia_de = ? AND cod = ?')->execute([$x['id'], $cod]);
            $db->prepare('DELETE FROM sobres WHERE id = ?')->execute([$x['id']]);
            $sustituidos++;
        }
    }

    /* El progreso es UNO y el último. No es un documento que se
       colecciona: es una foto de cómo va, y tener cinco fotos viejas en
       la lista no ayuda a nadie. Se borra el anterior al publicar el
       nuevo — y se borra de verdad, porque el contenido que sustituye
       es el mismo dato desactualizado. */
    /* La agenda igual: la próxima sesión es una sola. */
    if (in_array($clase, ['progreso', 'agenda', 'historial', 'objetivos', 'pago'], true)) {
        $db->prepare("DELETE FROM sobres WHERE cod = ? AND direccion = 2 AND clase = ?")
           ->execute([$cod, $clase]);
    }

    /* Un documento para firmar con el mismo título que otro sin firmar
       sellado con una clave ANTERIOR: el viejo ya no lo puede abrir
       nadie y solo confunde («volviendo» al lado del nuevo). Fuera. */
    if (!empty($in['requiere_firma']) && $sustituye === '') {
        $db->prepare('DELETE FROM sobres WHERE cod = ? AND direccion = 2 AND titulo = ? AND requiere_firma = 1
                      AND firmado IS NULL AND para_v <> ?')->execute([$cod, $titulo, $para_v]);
    }
    $db->prepare('INSERT INTO sobres (cod, direccion, titulo, cifrado, para_v, creado, clase,
                                    requiere_firma, firmado)
                  VALUES (?,2,?,?,?,?,?,?,?)')
       ->execute([$cod, $titulo, $cifrado, $para_v, $creado, $clase,
                  (int)(!empty($in['requiere_firma']) && !$firmado), $firmado]);
    /* La gráfica no se avisa: se republica cada vez que él recoge algo
       y avisaría cada dos por tres de algo que el paciente no ha
       pedido. Lo demás sí. */
    if (!in_array($clase, ['progreso', 'agenda', 'historial', 'objetivos', 'pago'], true) && !$atrasado
        && (($in['avisar'] ?? true) !== false)) adr_avisa($db, $ADR, $cod);
    adr_json(['ok' => true, 'sustituidos' => $sustituidos]);
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
    $enlace = $ADR['sitio'] . '/activar.php?p=' . $papel;
    /* El portal NO manda la bienvenida (09-10): la manda el Mac por el
       Gmail de hola@ con este enlace. Si alguien pide `enviar`, se le
       contesta que no ha salido, para que nadie lo dé por enviado. */
    adr_json(['ok' => true, 'enlace' => $enlace,
              'enviado' => !empty($in['enviar']) ? false : null,
              'error' => !empty($in['enviar']) ? 'el_portal_no_envia' : null,
              'caduca' => date('c', time() + 24 * 7 * 3600)]);
}

/* Un enlace para poner contraseña nueva, para que Adrián se lo dé en
   mano (WhatsApp) a quien ya activó su cuenta y no puede entrar. Es el
   mismo papel que «He olvidado mi contraseña», pero no depende de que
   el correo llegue. Vale 72 horas y una sola vez. */
case 'enlace_clave': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $q = $db->prepare('SELECT cod FROM pacientes WHERE cod = ? AND activado IS NOT NULL');
    $q->execute([$cod]);
    if (!$q->fetch()) adr_json(['error' => 'esa cuenta no está activada: usa su enlace de activación'], 404);
    $horas = 72;
    $papel = adr_ficha($db, $cod, 'reset', $horas);
    adr_apunta($db, 'mac', 'enlace_clave');
    adr_json(['ok' => true, 'enlace' => $ADR['sitio'] . '/clave.php?p=' . $papel,
              'caduca' => date('c', time() + $horas * 3600)]);
}

/* Cerrar en la cuenta lo que se ha hecho en sesión.
   -------------------------------------------------------------------
   Adrián le lee el PHQ-9 en la sesión y apunta sus respuestas en la
   consola: la medida va directa al Mac, sin pasar por aquí. Lo único
   que hace falta aquí es que el PHQ-9 que tenía pendiente en su cuenta
   deje de estarlo — si no, lo rellenaría otra vez esa noche. Queda como
   hecho, con la fecha de hoy, en «Lo que has completado». */
case 'cerrar_tarea': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $titulo = trim((string)($in['titulo'] ?? ''));
    if ($cod === '' || $titulo === '') adr_json(['error' => 'faltan cod o titulo'], 400);
    $q = $db->prepare("UPDATE tareas SET hecho = ? WHERE cod = ? AND titulo = ? AND hecho IS NULL
                       AND tipo = 'cuestionario'");
    $q->execute([adr_ahora(), $cod, $titulo]);
    adr_json(['ok' => true, 'cerradas' => $q->rowCount()]);
}


/* Los títulos de lo que ya tiene publicado (sesiones y documentos), para
   elegir cuál sustituye una versión mejorada. Títulos y fechas: lo que
   el servidor ya ve en claro, nada más. */
case 'publicados': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $q = $db->prepare("SELECT titulo, clase, creado FROM sobres WHERE cod = ? AND direccion = 2
                       AND clase IN ('sesion','documento') AND requiere_firma = 0 AND firmado IS NULL
                       ORDER BY creado DESC LIMIT 200");
    $q->execute([(string)($in['cod'] ?? '')]);
    adr_json(['publicados' => $q->fetchAll()]);
}

/* Los consentimientos firmados y con qué formulario: quién lo firmó solo
   con el nombre (firma_v < 2) y le falta completar los datos. Con
   {avisar: true}, a esos les manda el aviso de siempre («tienes algo en
   tu cuenta»), como mucho uno al día por persona. */
case 'consentimientos': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/correo.php';
    $q = $db->query("SELECT s.cod, s.titulo, s.creado, s.firmado, COALESCE(s.firma_v, CASE WHEN s.firmado IS NULL THEN NULL ELSE 1 END) AS firma_v,
                            p.correo
                     FROM sobres s JOIN pacientes p ON p.cod = s.cod
                     WHERE s.direccion = 2 AND s.requiere_firma = 1 AND s.titulo LIKE '%onsentimiento%'
                       AND p.activado IS NOT NULL
                     ORDER BY s.cod, s.creado DESC");
    $filas = []; $avisados = 0;
    foreach ($q->fetchAll() as $f) {
        $falta = $f['firmado'] && (int)$f['firma_v'] < 2;
        $f['motivo'] = $falta ? 'datos' : null;
        if ($falta && !empty($in['avisar']) && adr_freno($db, 'aviso_ci:' . $f['cod'], 1, 86400)) {
            $r = adr_correo_aviso($ADR, (string)$f['correo'], (string)$f['cod']);
            if ($r['enviado'] || ($r['error'] ?? '') === 'en_cola') $avisados++;
        }
        unset($f['correo']);
        $f['faltan_datos'] = $falta;
        $filas[] = $f;
    }
    adr_json(['consentimientos' => $filas, 'faltan_datos' => count(array_filter($filas, fn($x) => $x['faltan_datos'])),
              'avisados' => $avisados]);
}

/* Anular una prueba pendiente (Adrián decide que ya no hace falta).
   Desaparece de sus «Tareas». Solo lo que aún no ha hecho. */
/* Quitar de su cuenta algo pendiente que se mandó por error (17-10).
   Cualquier tipo, solo si sigue sin hacer, y no se borra: queda como
   «retirada» (no sale ni en pendientes ni en «Lo que has completado»). */
case 'quitar_tarea': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $q = $db->prepare("UPDATE tareas SET hecho = 'retirada' WHERE id = ? AND cod = ? AND hecho IS NULL");
    $q->execute([(int)($in['id'] ?? 0), (string)($in['cod'] ?? '')]);
    adr_json(['ok' => true, 'quitadas' => $q->rowCount()]);
}

case 'anular_tarea': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $titulo = trim((string)($in['titulo'] ?? ''));
    if ($cod === '' || $titulo === '') adr_json(['error' => 'faltan cod o titulo'], 400);
    $q = $db->prepare("DELETE FROM tareas WHERE cod = ? AND titulo = ? AND hecho IS NULL
                       AND tipo = 'cuestionario'");
    $q->execute([$cod, $titulo]);
    adr_json(['ok' => true, 'anuladas' => $q->rowCount()]);
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
    $ps = $db->query("SELECT cod, correo, alta, activado, publica_v, ultimo, aviso_privacidad, ultimo_aviso
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
            'ultimo_aviso' => $p['ultimo_aviso'],
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
                         AND s.clase NOT IN ('herramienta', 'copia') AND s.firmado IS NULL
                       ORDER BY s.id");
    $cod_r = (string)($_GET['cod'] ?? '');
    /* Un documento para firmar sin firmar, con clave vieja, que ya tiene
       su gemelo con la clave nueva: sobra (se reenvió) y se quita. */
    $db->prepare("DELETE FROM sobres WHERE id IN (
                    SELECT s.id FROM sobres s JOIN pacientes p ON p.cod = s.cod
                    WHERE s.cod = ? AND s.direccion = 2 AND s.requiere_firma = 1 AND s.firmado IS NULL
                      AND s.para_v <> p.publica_v
                      AND EXISTS (SELECT 1 FROM sobres n WHERE n.cod = s.cod AND n.direccion = 2 AND n.titulo = s.titulo
                                  AND n.requiere_firma = 1 AND n.para_v = p.publica_v))")->execute([$cod_r]);
    $q->execute([$cod_r]);
    $sobres = $q->fetchAll();
    /* Lo firmado no se vuelve a mandar para firmar: lo que hay que volver
       a sellar es su COPIA firmada (clase 'copia', copia_de = id). */
    $copias = $db->prepare("SELECT s.id, s.titulo, s.firmado, s.firma_huella FROM sobres s JOIN pacientes p ON p.cod = s.cod
                            WHERE s.cod = ? AND s.direccion = 2 AND s.firmado IS NOT NULL AND s.requiere_firma = 1
                              AND NOT EXISTS (SELECT 1 FROM sobres c WHERE c.copia_de = s.id AND c.cod = s.cod
                                              AND c.clase = 'copia' AND c.para_v = p.publica_v)
                              AND s.para_v <> p.publica_v");
    $copias->execute([$cod_r]);
    /* Las herramientas selladas (17-10) que siguen pendientes con la
       clave anterior: hay que volver a sellarlas (resellar_tarea). */
    $ts = $db->prepare("SELECT t.id, t.titulo, t.plantilla, t.tipo, t.modo, t.hecho, t.cifrado IS NOT NULL AS sellada,
                               t.borrador IS NOT NULL AS con_contenido
                        FROM tareas t JOIN pacientes p ON p.cod = t.cod
                        WHERE t.cod = ? AND t.tipo = 'herramienta' AND (t.hecho IS NULL OR t.hecho <> 'retirada')
                          AND ((t.cifrado IS NOT NULL AND t.para_v <> p.publica_v)
                               OR (t.borrador IS NOT NULL AND t.borrador_v <> p.publica_v)
                               OR EXISTS (SELECT 1 FROM entradas e WHERE e.tarea = t.id AND e.borrada = 0
                                          AND e.para_v <> p.publica_v))");
    $ts->execute([$cod_r]);
    /* 17-10: también los documentos vivos y los registros. `modo` dice
       qué hay que rehacer: la definición (si iba sellada), lo que tenía
       escrito (`con_contenido`: resellar_tarea con `borrador`) y sus
       entradas (`resellar_entradas`). */
    $tareas_r = [];
    foreach ($ts->fetchAll() as $t) {
        $modo = adr_modo_fila($t);
        if ($modo === 'una' && $t['hecho'] !== null) continue;
        $e = $db->prepare('SELECT e.uid, e.fecha FROM entradas e JOIN pacientes p ON p.cod = e.cod
                           WHERE e.tarea = ? AND e.borrada = 0 AND e.para_v <> p.publica_v');
        $e->execute([$t['id']]);
        $tareas_r[] = ['id' => (int)$t['id'], 'titulo' => $t['titulo'], 'herramienta' => adr_herr_id($t),
                       'modo' => $modo, 'sellada' => (bool)$t['sellada'], 'con_contenido' => (bool)$t['con_contenido'],
                       'entradas' => $e->fetchAll()];
    }
    adr_json(['sobres' => $sobres, 'copias' => $copias->fetchAll(), 'tareas' => $tareas_r]);
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
    /* `avisar: false`: el Mac ya le manda su propio recordatorio de la
       víspera (desde hola@, con la hora y «tienes algo en tu cuenta»).
       Sin esto, el CORE-10 previo a la sesión llegaba con dos correos
       el mismo día. Por defecto se sigue avisando. */
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
    if (($in['tipo'] ?? '') === 'herramienta' && !empty($in['cifrado'])) {
        /* 17-10: la herramienta SELLADA a la clave del paciente. Las tareas
           para casa llevan «Lo que acordamos en sesión», y eso dice algo de
           alguien: no puede guardarse en claro. En claro solo queda un
           resguardo sin contenido (id, título y tipo) para la lista. */
        $cif = adr_deb64($in['cifrado']);
        if (!$cif || strlen($cif) > 1024 * 1024) adr_json(['error' => 'herramienta sellada no válida'], 400);
        $pv = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ?');
        $pv->execute([$cod]);
        if ((int)($in['para_v'] ?? -1) !== (int)$pv->fetch()['publica_v']) adr_json(['error' => 'clave caducada'], 409);
        $res = is_array($plantilla) ? $plantilla : [];
        if (!preg_match('/^[a-z0-9-]{2,60}$/', (string)($res['id'] ?? ''))
            || !in_array($res['tipo'] ?? '', ['rellenable', 'lectura'], true)) {
            adr_json(['error' => 'falta el resguardo de la herramienta (id y tipo)'], 400);
        }
        $modo = adr_modo_para($res, $in['modo'] ?? null);
        $res = ['id' => $res['id'], 'tipo' => $res['tipo'], 'titulo' => mb_substr((string)($res['titulo'] ?? ''), 0, 80),
                'riesgo' => !empty($res['riesgo']), 'sellada' => true, 'modo' => $modo];
        $db->prepare("INSERT INTO tareas (cod, titulo, plantilla, creado, caduca, tipo, cifrado, para_v, modo)
                      VALUES (?,?,?,?,?,'herramienta',?,?,?)")
           ->execute([$cod, mb_substr($titulo ?: 'Herramienta', 0, 80, 'UTF-8'), json_encode($res, JSON_UNESCAPED_UNICODE),
                      adr_ahora(), $in['caduca'] ?? null, $cif, (int)$in['para_v'], $modo]);
        $id = (int)$db->lastInsertId();
        if (($in['avisar'] ?? true) !== false) adr_avisa($db, $ADR, $cod);
        adr_json(['ok' => true, 'id' => $id, 'sellada' => true]);
    }
    if (($in['tipo'] ?? '') === 'herramienta') {
        if (!is_array($plantilla) || empty($plantilla['bloques']) || !is_array($plantilla['bloques'])
            || !preg_match('/^[a-z0-9-]{2,60}$/', (string)($plantilla['id'] ?? ''))
            || !in_array($plantilla['tipo'] ?? '', ['rellenable', 'lectura'], true)) {
            adr_json(['error' => 'la herramienta no tiene una forma válida'], 400);
        }
        $db->prepare("INSERT INTO tareas (cod, titulo, plantilla, creado, caduca, tipo, modo)
                      VALUES (?,?,?,?,?,'herramienta',?)")
           ->execute([$cod, mb_substr($titulo ?: 'Herramienta', 0, 80, 'UTF-8'),
                      json_encode($plantilla, JSON_UNESCAPED_UNICODE),
                      adr_ahora(), $in['caduca'] ?? null, adr_modo_para($plantilla, $in['modo'] ?? null)]);
        $id = (int)$db->lastInsertId();
        if (($in['avisar'] ?? true) !== false) adr_avisa($db, $ADR, $cod);
        adr_json(['ok' => true, 'id' => $id]);
    }

    /* La valoración de objetivos («¿cuánto has avanzado hacia cada uno?»,
       de 0 a 10). En claro solo van los identificadores (o1, o2…): los
       títulos de los objetivos dicen algo de alguien y viajan sellados
       en el documento «objetivos», que la cuenta abre con su llave. */
    if (($in['tipo'] ?? '') === 'objetivos') {
        $ids = is_array($plantilla) ? ($plantilla['ids'] ?? null) : null;
        if (!is_array($ids) || !$ids || count($ids) > 12
            || array_filter($ids, fn($x) => !is_string($x) || !preg_match('/^[A-Za-z0-9_-]{1,24}$/', $x))) {
            adr_json(['error' => 'la valoración necesita «ids» de los objetivos'], 400);
        }
        $db->prepare("INSERT INTO tareas (cod, titulo, plantilla, creado, caduca, tipo)
                      VALUES (?,?,?,?,?,'objetivos')")
           ->execute([$cod, mb_substr($titulo ?: '¿Cuánto has avanzado?', 0, 80, 'UTF-8'),
                      json_encode(['ids' => array_values($ids)]), adr_ahora(), $in['caduca'] ?? null]);
        $id = (int)$db->lastInsertId();
        if (($in['avisar'] ?? true) !== false) adr_avisa($db, $ADR, $cod);
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
    if (($in['avisar'] ?? true) !== false) adr_avisa($db, $ADR, $cod);
    adr_json(['ok' => true, 'id' => $id]);
}

/* El paciente pide la plantilla de UNA tarea suya. */
case 'tarea': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $q = $db->prepare('SELECT id, titulo, plantilla, hecho, tipo, cifrado, para_v, borrador, borrador_v, modo, actualizado
                       FROM tareas WHERE id = ? AND cod = ?');
    $q->execute([(int)($_GET['id'] ?? 0), $cod]);
    $t = $q->fetch();
    if (!$t) adr_json(['error' => 'no existe'], 404);
    $modo = adr_modo_fila($t);
    /* Un documento vivo o un registro se siguen abriendo después de
       empezarlos: es su gracia. Lo de una vez, no. */
    if ($t['hecho'] === 'retirada' || ($t['hecho'] !== null && $modo === 'una')) adr_json(['error' => 'ya la habías entregado'], 409);

    /* Un deber lleva su texto CIFRADO; un cuestionario, su plantilla en
       claro. No es incoherencia: el PHQ-9 es público y el mismo para
       todo el mundo, y «registra tres domingos seguidos qué hiciste»
       dice algo de alguien. Se cifra lo que dice algo. */
    if ($t['tipo'] === 'deber') {
        adr_json(['id' => (int)$t['id'], 'titulo' => $t['titulo'], 'tipo' => 'deber',
                  'cifrado' => adr_b64($t['cifrado']), 'para_v' => (int)$t['para_v']]);
    }
    adr_json(['id' => (int)$t['id'], 'titulo' => $t['titulo'],
              'tipo' => in_array($t['tipo'], ['herramienta', 'objetivos'], true) ? $t['tipo'] : 'cuestionario',
              'plantilla' => json_decode($t['plantilla'], true),
              /* La herramienta sellada y lo que lleve guardado a medias
                 (sellado a SU clave): el servidor no lee ninguno de los dos. */
              'cifrado' => $t['tipo'] === 'herramienta' && $t['cifrado'] ? adr_b64($t['cifrado']) : null,
              'para_v' => (int)$t['para_v'], 'modo' => $modo, 'empezada' => $t['hecho'] !== null,
              'actualizado' => $t['actualizado'],
              'borrador' => $t['borrador'] ? adr_b64($t['borrador']) : null, 'borrador_v' => (int)($t['borrador_v'] ?? 0)]);
}

/* Guardar a medias una herramienta (tareas de la semana: un día cada
   noche). Va sellado a la clave del propio paciente: el servidor no lo
   lee, y así sigue desde cualquier dispositivo. */
case 'guardar_borrador': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $cif = adr_deb64($in['cifrado'] ?? null);
    if (!$cif || strlen($cif) > 512 * 1024) adr_json(['error' => 'borrador no válido'], 400);
    if (!adr_freno($db, 'borr:' . $cod, 120, 3600)) adr_json(['error' => 'despacio'], 429);
    $v = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ?');
    $v->execute([$cod]);
    $q = $db->prepare('UPDATE tareas SET borrador = ?, borrador_v = ? WHERE id = ? AND cod = ? AND hecho IS NULL');
    $q->execute([$cif, (int)$v->fetch()['publica_v'], (int)($in['id'] ?? 0), $cod]);
    if (!$q->rowCount()) adr_json(['error' => 'no existe'], 404);
    adr_json(['ok' => true, 'guardado' => adr_ahora()]);
}

/* Y la entrega. El sobre y la marca de hecho van en la MISMA
   transacción: si se guardara el sobre y fallara la marca, la persona
   volvería a ver la tarea pendiente y la contestaría dos veces; al
   revés, se perdería lo que acaba de escribir. */
/* --- Herramientas vivas y registros (17-10, tarde) ------------------- */

/* Un documento vivo (o la parte fija de un registro) se guarda: lo que
   tiene ahora, sellado a SU clave (lo vuelve a abrir y lo sigue
   cambiando), y una versión sellada al Mac, que así guarda el historial
   de versiones. Nada de «enviar»: guardar ES enviar. */
case 'guardar_vivo': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $id = (int)($in['id'] ?? 0);
    $mac = adr_deb64($in['cifrado'] ?? null);
    $mio = adr_deb64($in['copia'] ?? null);
    if (!$mac || !$mio || strlen($mac) > 512 * 1024 || strlen($mio) > 512 * 1024) adr_json(['error' => 'no válido'], 400);
    if (!adr_freno($db, 'viv:' . $cod, 120, 3600)) adr_json(['error' => 'despacio'], 429);
    $q = $db->prepare('SELECT * FROM tareas WHERE id = ? AND cod = ?');
    $q->execute([$id, $cod]);
    $t = $q->fetch();
    if (!$t || $t['hecho'] === 'retirada' || adr_modo_fila($t) === 'una') adr_json(['error' => 'no existe'], 404);
    $v = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ?');
    $v->execute([$cod]);
    $pv = (int)$v->fetch()['publica_v'];
    $ahora = adr_ahora();
    $db->beginTransaction();
    $db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, creado, clase) VALUES (?,1,?,?,?,'ejercicio')")
       ->execute([$cod, $t['titulo'], $mac, $ahora]);
    $db->prepare('UPDATE tareas SET borrador = ?, borrador_v = ?, actualizado = ?, hecho = COALESCE(hecho, ?) WHERE id = ?')
       ->execute([$mio, $pv, $ahora, $ahora, $id]);
    $db->commit();
    adr_json(['ok' => true, 'guardado' => $ahora]);
}

/* Una entrada de un registro: nueva o corregida (mismo `uid`), o
   quitada (`borrar`). Siempre con su sobre al Mac, que se entera de la
   corrección o de que se ha quitado. */
case 'entrada': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $id = (int)($in['id'] ?? 0);
    $uid = (string)($in['uid'] ?? '');
    $fecha = (string)($in['fecha'] ?? '');
    $mac = adr_deb64($in['cifrado'] ?? null);
    $mio = adr_deb64($in['copia'] ?? null);
    $borrar = !empty($in['borrar']);
    if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uid) || !$mac || strlen($mac) > 256 * 1024
        || (!$borrar && (!$mio || strlen($mio) > 256 * 1024))) adr_json(['error' => 'no válido'], 400);
    $hoy = adr_hoy();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > $hoy
        || $fecha < adr_madrid()->modify('-62 days')->format('Y-m-d')) adr_json(['error' => 'esa fecha no vale'], 400);
    if (!adr_freno($db, 'entr:' . $cod, 200, 3600)) adr_json(['error' => 'despacio'], 429);
    $q = $db->prepare('SELECT * FROM tareas WHERE id = ? AND cod = ?');
    $q->execute([$id, $cod]);
    $t = $q->fetch();
    if (!$t || $t['hecho'] === 'retirada' || adr_modo_fila($t) !== 'registro') adr_json(['error' => 'no existe'], 404);
    $v = $db->prepare('SELECT publica_v FROM pacientes WHERE cod = ?');
    $v->execute([$cod]);
    $pv = (int)$v->fetch()['publica_v'];
    $ahora = adr_ahora();
    $db->beginTransaction();
    $db->prepare("INSERT INTO sobres (cod, direccion, titulo, cifrado, creado, clase) VALUES (?,1,?,?,?,'ejercicio')")
       ->execute([$cod, $t['titulo'], $mac, $ahora]);
    if ($borrar) {
        $db->prepare('UPDATE entradas SET borrada = 1, modificado = ? WHERE tarea = ? AND uid = ? AND cod = ?')
           ->execute([$ahora, $id, $uid, $cod]);
    } else {
        $db->prepare('INSERT INTO entradas (cod, tarea, uid, fecha, cifrado, para_v, creado, modificado)
                      VALUES (?,?,?,?,?,?,?,?)
                      ON CONFLICT(tarea, uid) DO UPDATE SET fecha = excluded.fecha, cifrado = excluded.cifrado,
                        para_v = excluded.para_v, modificado = excluded.modificado, borrada = 0')
           ->execute([$cod, $id, $uid, $fecha, $mio, $pv, $ahora, $ahora]);
        $db->prepare('UPDATE tareas SET actualizado = ?, hecho = COALESCE(hecho, ?) WHERE id = ?')
           ->execute([$ahora, $ahora, $id]);
    }
    $db->commit();
    adr_json(['ok' => true]);
}

case 'entradas': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $q = $db->prepare('SELECT uid, fecha, cifrado, para_v, creado, modificado FROM entradas
                       WHERE tarea = ? AND cod = ? AND borrada = 0 ORDER BY fecha DESC, creado DESC LIMIT 500');
    $q->execute([(int)($_GET['id'] ?? 0), $cod]);
    adr_json(['entradas' => array_map(fn($e) => ['uid' => $e['uid'], 'fecha' => $e['fecha'],
        'cifrado' => adr_b64($e['cifrado']), 'para_v' => (int)$e['para_v'],
        'creado' => $e['creado'], 'modificado' => $e['modificado']], $q->fetchAll())]);
}

/* Su recordatorio: la hora («21:00») o nada para quitarlo. */
case 'recordatorio': {
    $cod = adr_sesion($ADR['secreto']);
    if (!$cod) adr_json(['error' => 'entra primero'], 401);
    $id = (int)($in['id'] ?? 0);
    $hora = (string)($in['hora'] ?? '');
    $q = $db->prepare('SELECT 1 FROM tareas WHERE id = ? AND cod = ?');
    $q->execute([$id, $cod]);
    if (!$q->fetch()) adr_json(['error' => 'no existe'], 404);
    if ($hora === '') {
        $db->prepare('DELETE FROM recordatorios WHERE cod = ? AND tarea = ?')->execute([$cod, $id]);
        adr_json(['ok' => true, 'hora' => null]);
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hora)) adr_json(['error' => 'hora no válida'], 400);
    /* Si la pone para una hora que ya ha pasado hoy, empieza mañana. */
    $ultimo = $hora <= adr_madrid()->format('H:i') ? adr_hoy() : null;
    $db->prepare('INSERT INTO recordatorios (cod, tarea, hora, ultimo) VALUES (?,?,?,?)
                  ON CONFLICT(cod, tarea) DO UPDATE SET hora = excluded.hora, ultimo = excluded.ultimo')
       ->execute([$cod, $id, $hora, $ultimo]);
    adr_json(['ok' => true, 'hora' => $hora]);
}

/* Para el Mac: «Antes de la sesión» de las citas entre dos momentos
   (por defecto, hoy y mañana). */
case 'antes_sesion': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $hoy = adr_madrid()->setTime(0, 0);
    try {
        $desde = isset($in['desde']) ? adr_madrid((string)$in['desde']) : $hoy;
        $hasta = isset($in['hasta']) ? adr_madrid((string)$in['hasta']) : $hoy->modify('+1 day')->setTime(23, 59, 59);
    } catch (Throwable $e) { adr_json(['error' => 'fechas no válidas'], 400); }
    adr_json(['citas' => adr_antes_sesion($db, $desde, $hasta)]);
}

/* Para el Mac: mandar YA el correo de la mañana (para probarlo). */
case 'resumen_dia': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    adr_json(adr_resumen_dia($db, $ADR, true) ?? ['citas' => 0]);
}

/* El latido: lo mismo que hace cada pasada del Mac con los
   recordatorios y el correo de la mañana, para que un cron del
   alojamiento (si lo hay) lo dispare aunque el Mac esté apagado. No
   devuelve nada que diga nada. */
case 'latido': {
    if (!adr_freno($db, 'latido', 30, 600)) adr_json(['ok' => true]);
    /* 17-10, noche: también las medidas periódicas que ya tocan, el
       recordatorio de las 48 horas y la limpieza de altas sin activar.
       Antes solo pasaban cuando el Mac recogía; con el cron, aunque el Mac
       esté apagado. Nada de esto lee contenido: no necesita la clave. */
    try { adr_vencen($db, $ADR); adr_recuerda($db, $ADR); adr_limpia_altas($db); } catch (Throwable $e) {}
    try { adr_recordatorios($db, $ADR); adr_resumen_dia($db, $ADR); } catch (Throwable $e) {}
    try { require_once __DIR__ . '/lib/pagos.php'; adr_visperas($db, $ADR); } catch (Throwable $e) {}
    adr_json(['ok' => true]);
}

/* Tras un cambio de contraseña, el Mac vuelve a sellar las entradas de
   un registro a la clave nueva (las tiene todas: le llegó cada una). */
case 'resellar_entradas': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $id = (int)($in['tarea'] ?? 0);
    $q = $db->prepare("SELECT t.cod, p.publica_v FROM tareas t JOIN pacientes p ON p.cod = t.cod WHERE t.id = ?");
    $q->execute([$id]);
    $f = $q->fetch();
    if (!$f) adr_json(['error' => 'no existe'], 404);
    if ((int)($in['para_v'] ?? -1) !== (int)$f['publica_v']) adr_json(['error' => 'clave caducada'], 409);
    $n = 0;
    foreach ((array)($in['entradas'] ?? []) as $e) {
        $c = adr_deb64($e['cifrado'] ?? null);
        if (!$c || strlen($c) > 256 * 1024) continue;
        $u = $db->prepare('UPDATE entradas SET cifrado = ?, para_v = ? WHERE tarea = ? AND uid = ?');
        $u->execute([$c, (int)$in['para_v'], $id, (string)($e['uid'] ?? '')]);
        $n += $u->rowCount();
    }
    adr_json(['ok' => true, 'reselladas' => $n]);
}

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
/* La cola de correos, para el Mac: lo pendiente, sellado a su clave.
   El Mac lo abre, lo manda por el Gmail de hola@ y contesta con
   `correo_enviado`. Lo enviado se borra a los 30 días y lo que lleve
   7 días sin poder salir, también (y queda en el registro del Mac). */
case 'correos_pendientes':
case 'cola_correo': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $db->exec("DELETE FROM correos_cola WHERE (enviado IS NOT NULL AND enviado < datetime('now','-30 days'))
               OR (enviado IS NULL AND creado < datetime('now','-7 days'))");
    $q = $db->query('SELECT id, cod, tipo, cifrado, creado, intentos FROM correos_cola
                     WHERE enviado IS NULL ORDER BY id LIMIT 50');
    $out = [];
    foreach ($q->fetchAll() as $f) {
        $out[] = ['id' => (int)$f['id'], 'cod' => $f['cod'], 'tipo' => $f['tipo'], 'creado' => $f['creado'],
                  'intentos' => (int)$f['intentos'], 'cifrado' => adr_b64($f['cifrado'])];
    }
    adr_json(['correos' => $out]);
}

case 'correo_fallido':
case 'correo_enviado': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $id = (int)($in['id'] ?? 0);
    $mid = $a === 'correo_enviado' ? trim((string)($in['message_id'] ?? '')) : '';
    if ($mid !== '') {
        $db->prepare('UPDATE correos_cola SET enviado = ?, message_id = ?, error = NULL WHERE id = ?')
           ->execute([adr_ahora(), mb_substr($mid, 0, 200), $id]);
        require_once __DIR__ . '/lib/correo.php';
        adr_correo_apunta($ADR, ['enviado' => true, 'message_id' => $mid, 'error' => null]);
    } else {
        $err = mb_substr((string)($in['error'] ?? 'sin message_id'), 0, 300);
        $db->prepare('UPDATE correos_cola SET intentos = intentos + 1, error = ? WHERE id = ?')
           ->execute([$err, $id]);
        require_once __DIR__ . '/lib/correo.php';
        adr_correo_apunta($ADR, ['enviado' => false, 'message_id' => null, 'error' => 'gmail'], 0, $err);
    }
    adr_json(['ok' => true]);
}

/* ¿Salen los correos del portal? Lo que pregunta el Mac cada hora. */
/* --- El cobro con Stripe (14-10) ----------------------------------- */

/* Cal.com avisa de una reserva, un cambio de hora o una cancelación.
   Firma: X-Cal-Signature-256 = HMAC-SHA256 (hex) del cuerpo con el
   secreto que se puso al dar de alta el webhook. Solo el tipo de cita
   de pago (por su slug); la informativa, nunca. */
case 'cal_webhook': {
    require_once __DIR__ . '/lib/pagos.php';
    adr_pagos_tabla($db);
    $cfg = adr_pagos_cfg($ADR);
    $crudo = (string)file_get_contents('php://input');
    if (!$cfg || empty($cfg['cal_secreto'])) { adr_pagos_apunta_error($ADR, 'cal_sin_configurar'); adr_json(['error' => 'sin configurar'], 503); }
    $firma = strtolower(trim((string)($_SERVER['HTTP_X_CAL_SIGNATURE_256'] ?? '')));
    if ($firma === '' || !hash_equals(hash_hmac('sha256', $crudo, $cfg['cal_secreto']), $firma)) {
        adr_pagos_apunta_error($ADR, 'cal_firma', 'la firma del webhook de Cal.com no casa');
        adr_json(['error' => 'firma'], 401);
    }
    $j = json_decode($crudo, true) ?: [];
    $ev = (string)($j['triggerEvent'] ?? '');
    $p = $j['payload'] ?? [];
    $slug = (string)($p['type'] ?? ($p['eventType']['slug'] ?? ''));
    /* Registro de lo que llega (15-10: una cita salía un día distinto
       del de Cal.com). Solo lo que hace falta para entenderlo: evento,
       uids, hora y tipo. Sin nombres ni correos. Se guardan los 300
       últimos. */
    $db->exec('CREATE TABLE IF NOT EXISTS cal_eventos (id INTEGER PRIMARY KEY, llegado TEXT, evento TEXT, slug TEXT,
               uid TEXT, uid_anterior TEXT, inicio TEXT, inicio_anterior TEXT)');
    $db->prepare('INSERT INTO cal_eventos (llegado, evento, slug, uid, uid_anterior, inicio, inicio_anterior) VALUES (?,?,?,?,?,?,?)')
       ->execute([adr_ahora(), $ev, $slug, (string)($p['uid'] ?? ''),
                  (string)($p['rescheduleUid'] ?? ($p['fromReschedule'] ?? ($p['originalRescheduledBooking']['uid'] ?? ''))),
                  (string)($p['startTime'] ?? ''), (string)($p['rescheduleStartTime'] ?? '')]);
    $db->exec('DELETE FROM cal_eventos WHERE id NOT IN (SELECT id FROM cal_eventos ORDER BY id DESC LIMIT 300)');
    if ($ev === 'PING') adr_json(['ok' => true, 'ping' => true]);
    if (empty($cfg['slug']) || $slug !== $cfg['slug']) adr_json(['ok' => true, 'ignorada' => 'otro tipo de cita']);
    $uid = (string)($p['uid'] ?? '');
    if ($uid === '') adr_json(['ok' => true, 'ignorada' => 'sin uid']);
    $inicio = !empty($p['startTime']) ? adr_inicio_madrid((string)$p['startTime']) : null;
    $correo = adr_correo_normal((string)($p['attendees'][0]['email'] ?? ''));
    if ($ev === 'BOOKING_CANCELLED') {
        $db->prepare("UPDATE citas SET estado = 'cancelada' WHERE uid = ?")->execute([$uid]);
        adr_json(['ok' => true, 'cancelada' => $uid]);
    }
    if ($ev === 'BOOKING_RESCHEDULED') {
        $viejo = (string)($p['rescheduleUid'] ?? ($p['fromReschedule'] ?? ($p['originalRescheduledBooking']['uid'] ?? '')));
        $q = $db->prepare('SELECT uid FROM citas WHERE uid = ?');
        $q->execute([$viejo]);
        if ($viejo !== '' && $q->fetch()) {
            $q->execute([$uid]);
            if (!$q->fetch()) {
                /* El pago o el enlace se conservan: la misma fila pasa al uid nuevo y a la hora nueva. */
                $db->prepare("UPDATE citas SET uid = ?, inicio = COALESCE(?, inicio), estado = 'activa' WHERE uid = ?")
                   ->execute([$uid, $inicio, $viejo]);
            }
            adr_json(['ok' => true, 'cambiada' => $uid]);
        }
        /* Cal.com a veces reprograma conservando el mismo uid: entonces
           lo que cambia es solo la hora de esa misma fila. */
        $q->execute([$uid]);
        if ($q->fetch() && $inicio) {
            $db->prepare("UPDATE citas SET inicio = ?, estado = 'activa' WHERE uid = ?")->execute([$inicio, $uid]);
            adr_json(['ok' => true, 'cambiada' => $uid, 'misma' => true]);
        }
        /* Sin la anterior: se trata como una reserva nueva. */
    }
    if (!in_array($ev, ['BOOKING_CREATED', 'BOOKING_RESCHEDULED'], true)) adr_json(['ok' => true, 'ignorada' => $ev]);
    if (!$inicio || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        adr_pagos_apunta_error($ADR, 'cal_datos', 'reserva sin hora o sin correo del invitado');
        adr_json(['ok' => true, 'ignorada' => 'sin hora o sin correo']);
    }
    $q = $db->prepare('SELECT cod FROM pacientes WHERE correo = ?');
    $q->execute([$correo]);
    $cod = ($q->fetch()['cod'] ?? null);
    $cita = adr_cita_crea($db, $uid, $cod, $correo, $inicio, (int)($cfg['importe'] ?? 30), 'cal',
                          (string)($p['attendees'][0]['name'] ?? '') ?: null);
    if (empty($cita['lugar']) && ($lugar = adr_cita_lugar($p))) {
        $db->prepare('UPDATE citas SET lugar = ? WHERE uid = ?')->execute([$lugar, $uid]);
        $cita['lugar'] = $lugar;
    }
    $r = null;
    if (!$cita['avisado'] && $cita['pagado_en'] === null) $r = adr_cita_avisa($ADR, $db, $cita);
    adr_json(['ok' => true, 'cita' => $uid, 'avisado' => $r ? ($r['enviado'] || ($r['error'] ?? '') === 'en_cola') : null]);
}

/* Stripe avisa de un pago o de una devolución. Firma comprobada con el
   secreto del webhook; si no casa, no se toca nada y queda apuntado. */
case 'stripe_webhook': {
    require_once __DIR__ . '/lib/pagos.php';
    adr_pagos_tabla($db);
    $cfg = adr_pagos_cfg($ADR);
    $crudo = (string)file_get_contents('php://input');
    if (!$cfg || empty($cfg['stripe_webhook'])) { adr_pagos_apunta_error($ADR, 'stripe_sin_configurar'); adr_json(['error' => 'sin configurar'], 503); }
    if (!adr_stripe_firma_ok((string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $crudo, $cfg['stripe_webhook'])) {
        adr_pagos_apunta_error($ADR, 'stripe_firma', 'la firma del webhook de Stripe no casa');
        adr_json(['error' => 'firma'], 400);
    }
    $e = json_decode($crudo, true) ?: [];
    $o = $e['data']['object'] ?? [];
    $tipo = (string)($e['type'] ?? '');
    if (in_array($tipo, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
        if (($o['payment_status'] ?? '') === 'paid') {
            $uid = (string)($o['metadata']['reserva_uid'] ?? ($o['client_reference_id'] ?? ''));
            if ($uid !== '') {
                $pi = (string)($o['payment_intent'] ?? $o['id'] ?? '');
                adr_cita_pagada($db, $uid, $pi, date('c', (int)($e['created'] ?? time())));
                /* La comisión, para cuadrar el mes con la directora. Si Stripe
                   no la da (permisos de la clave), queda vacía. */
                $fee = adr_stripe_comision($ADR, $pi);
                if ($fee !== null) $db->prepare('UPDATE citas SET comision = ? WHERE uid = ?')->execute([$fee, $uid]);
            }
        }
    } elseif ($tipo === 'charge.refunded') {
        $pi = (string)($o['payment_intent'] ?? '');
        if ($pi !== '') $db->prepare('UPDATE citas SET reembolsado = ? WHERE pago_ref = ?')
                           ->execute([!empty($o['refunded']) ? 1 : 0, $pi]);
    }
    adr_json(['ok' => true]);
}

/* El Mac: sus citas de pago, para Cobros, recordatorios y WhatsApp. */
case 'estado_pagos': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/pagos.php';
    adr_pagos_tabla($db);
    $cfg = adr_pagos_cfg($ADR);
    $q = $db->prepare("SELECT * FROM citas WHERE inicio >= ? AND inicio <= ? AND estado != 'prueba' ORDER BY inicio");
    $q->execute([date('c', time() - 90 * 86400), date('c', time() + 60 * 86400)]);
    $out = [];
    foreach ($q->fetchAll() as $c) {
        $vivo = $c['estado'] === 'activa' && $c['pagado_en'] === null;
        $out[] = ['cod' => $c['cod'], 'uid' => $c['uid'], 'inicio' => $c['inicio'], 'importe' => (int)$c['importe'],
                  'pagado_en' => $c['pagado_en'], 'url_pago' => $vivo ? adr_pagos_url($ADR, $c['token']) : null,
                  'reembolsado' => (bool)$c['reembolsado'], 'cancelada' => $c['estado'] !== 'activa',
                  'stripe_id' => $c['pago_ref'] ?: null,
                  'comision' => $c['comision'] !== null ? (float)$c['comision'] : null,
                  'avisado' => $c['avisado'], 'vispera' => $c['vispera'] ?? null, 'correo' => $c['correo'], 'nombre' => $c['nombre'] ?? null,
                  'origen' => $c['origen']];
    }
    $er = $db->query("SELECT valor FROM ajustes WHERE clave = 'pagos_ultimo_error'")->fetch();
    adr_json(['pagos' => $out, 'configurado' => (bool)($cfg['stripe_clave'] ?? null),
              'modo' => str_contains((string)($cfg['stripe_clave'] ?? ''), '_test_') ? 'prueba' : (($cfg['stripe_clave'] ?? null) ? 'real' : null),
              'ultimo_error' => $er ? json_decode($er['valor'], true) : null]);
}

/* El Mac: enlace de pago para una sesión que no pasó por Cal.com (o de
   antes del cambio). Idempotente: la misma cita, el mismo enlace. */
case 'crear_pago': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/pagos.php';
    adr_pagos_tabla($db);
    /* Con cuenta (cod) o, para quien no la tiene, solo con su correo. Con
       {avisar: true} le manda el mismo correo que al reservar en Cal.com
       (con el botón de pago); sin él, solo devuelve el enlace, para
       mandarlo por WhatsApp. */
    $cod = trim((string)($in['cod'] ?? '')) ?: null;
    $inicio = (string)($in['inicio'] ?? '');
    if (strtotime($inicio) === false || $inicio === '') adr_json(['error' => 'falta la fecha y la hora de la sesión'], 400);
    $correo = null;
    if ($cod) {
        $q = $db->prepare('SELECT correo FROM pacientes WHERE cod = ?');
        $q->execute([$cod]);
        $correo = $q->fetch()['correo'] ?? null;
    }
    $correo = $correo ?: adr_correo_normal((string)($in['correo'] ?? ''));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) adr_json(['error' => 'esa cuenta no existe y no me has dado su correo'], 404);
    $inicio = adr_inicio_madrid($inicio);
    $uid = 'mac-' . substr(hash('sha256', ($cod ?: $correo) . '|' . $inicio), 0, 24);
    $cfg = adr_pagos_cfg($ADR) ?: [];
    $nombre = trim((string)($in['nombre'] ?? '')) ?: null;
    $cita = adr_cita_crea($db, $uid, $cod, $correo, $inicio, max(1, (int)($in['importe'] ?? ($cfg['importe'] ?? 30))), 'mac', $nombre);
    if (!empty($in['lugar']) && in_array($in['lugar'], ['online', 'presencial'], true) && empty($cita['lugar'])) {
        $db->prepare('UPDATE citas SET lugar = ? WHERE uid = ?')->execute([$in['lugar'], $uid]);
        $cita['lugar'] = $in['lugar'];
    }
    if ($cita['estado'] !== 'activa') {
        $db->prepare("UPDATE citas SET estado = 'activa' WHERE uid = ?")->execute([$uid]);
        $cita['estado'] = 'activa';
    }
    $aviso = null;
    if (!empty($in['avisar']) && $cita['pagado_en'] === null) {
        $r = adr_cita_avisa($ADR, $db, $cita);
        $aviso = ($r['enviado'] || ($r['error'] ?? '') === 'en_cola') ? 'enviado' : ($r['error'] ?? 'no ha salido');
    }
    adr_json(['ok' => true, 'uid' => $uid, 'url_pago' => $cita['pagado_en'] === null ? adr_pagos_url($ADR, $cita['token']) : null,
              'pagado_en' => $cita['pagado_en'], 'correo' => $correo, 'aviso' => $aviso,
              'modo' => str_contains((string)($cfg['stripe_clave'] ?? ''), '_live_') ? 'real' : 'prueba']);
}

/* Unir una cita de pago a una cuenta (15-10): Adrián reservó con un
   correo mal escrito y la fila quedó sin cuenta. Idempotente; si ya es de
   otra cuenta, no. Al unirla, el correo de pago va (una vez) al correo de
   la cuenta, si no está pagada. */
case 'vincular_pago': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/pagos.php';
    adr_pagos_tabla($db);
    $uid = (string)($in['uid'] ?? '');
    $cod = (string)($in['cod'] ?? '');
    $q = $db->prepare('SELECT * FROM citas WHERE uid = ?');
    $q->execute([$uid]);
    $cita = $q->fetch();
    if (!$cita) adr_json(['error' => 'no existe esa cita'], 404);
    $q = $db->prepare('SELECT correo FROM pacientes WHERE cod = ?');
    $q->execute([$cod]);
    $correo = $q->fetch()['correo'] ?? null;
    if (!$correo) adr_json(['error' => 'no existe esa cuenta'], 404);
    if ($cita['cod'] !== null && $cita['cod'] !== $cod) adr_json(['error' => 'esa cita ya es de otra cuenta', 'cod' => $cita['cod']], 409);
    if ($cita['cod'] === $cod) adr_json(['ok' => true, 'ya' => true, 'reenviado' => false]);
    $antes = $cita['correo'];
    $db->prepare('UPDATE citas SET cod = ?, correo = ?, cliente = NULL WHERE uid = ?')->execute([$cod, $correo, $uid]);
    $cita['cod'] = $cod; $cita['correo'] = $correo;
    $re = false;
    if ($cita['pagado_en'] === null && $cita['estado'] === 'activa' && strcasecmp($antes, $correo) !== 0
        && strtotime($cita['inicio']) > time()) {
        $r = adr_cita_avisa($ADR, $db, $cita);
        $re = $r['enviado'] || ($r['error'] ?? '') === 'en_cola';
    }
    adr_json(['ok' => true, 'ya' => false, 'reenviado' => $re]);
}

/* Volver a sellar una herramienta pendiente tras un cambio de contraseña.
   Conserva la tarea (mismo id). Lo guardado a medias, sellado con la
   clave vieja, ya no se puede abrir: se quita. */
/* 21-10: pasar a la cuenta lo que una persona hizo en papel o en PDF, para
   que siga en la cuenta con todo guardado. Solo el Mac, y sellado a la
   clave del paciente (el portal no ve nada): `borrador` = {campos,
   guardado} y cada entrada de un registro = {fecha, fila, guardado}. Busca
   la herramienta por su id en la cuenta de esa persona; si no la tiene,
   contesta `no_asignada` y el Mac la asigna primero. */
case 'precargar': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cod = (string)($in['cod'] ?? '');
    $hid = (string)($in['herramienta'] ?? '');
    $q = $db->prepare("SELECT t.*, p.publica_v AS pv FROM tareas t JOIN pacientes p ON p.cod = t.cod
                       WHERE t.cod = ? AND t.tipo = 'herramienta' AND json_extract(t.plantilla, '$.id') = ?
                         AND (t.hecho IS NULL OR t.hecho <> 'retirada')
                       ORDER BY t.id DESC LIMIT 1");
    $q->execute([$cod, $hid]);
    $t = $q->fetch();
    if (!$t) adr_json(['error' => 'no_asignada'], 404);
    if ((int)($in['para_v'] ?? -1) !== (int)$t['pv']) adr_json(['error' => 'clave caducada'], 409);
    $modo = adr_modo_fila($t);
    if ($modo === 'una' && $t['hecho'] !== null) adr_json(['error' => 'esa ya la había entregado'], 409);
    $bor = isset($in['borrador']) ? adr_deb64($in['borrador']) : null;
    if (isset($in['borrador']) && (!$bor || strlen($bor) > 512 * 1024)) adr_json(['error' => 'borrador no válido'], 400);
    $ents = (array)($in['entradas'] ?? []);
    if ($ents && $modo !== 'registro') adr_json(['error' => 'esa herramienta no es un registro'], 400);
    $hoy = adr_hoy();
    $ahora = adr_ahora();
    $db->beginTransaction();
    $n = 0;
    foreach ($ents as $e) {
        $uid = (string)($e['uid'] ?? '');
        $fecha = (string)($e['fecha'] ?? '');
        $c = adr_deb64($e['cifrado'] ?? null);
        if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $uid) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)
            || $fecha > $hoy || !$c || strlen($c) > 256 * 1024) {
            $db->rollBack();
            adr_json(['error' => 'entrada no válida: ' . $fecha], 400);
        }
        $db->prepare('INSERT INTO entradas (cod, tarea, uid, fecha, cifrado, para_v, creado, modificado)
                      VALUES (?,?,?,?,?,?,?,?)
                      ON CONFLICT(tarea, uid) DO UPDATE SET fecha = excluded.fecha, cifrado = excluded.cifrado,
                        para_v = excluded.para_v, modificado = excluded.modificado, borrada = 0')
           ->execute([$cod, (int)$t['id'], $uid, $fecha, $c, (int)$t['pv'], $ahora, $ahora]);
        $n++;
    }
    if ($bor) {
        $db->prepare('UPDATE tareas SET borrador = ?, borrador_v = ? WHERE id = ?')
           ->execute([$bor, (int)$t['pv'], (int)$t['id']]);
    }
    if ($modo !== 'una' && ($bor || $n)) {
        $db->prepare('UPDATE tareas SET actualizado = ?, hecho = COALESCE(hecho, ?) WHERE id = ?')
           ->execute([$ahora, $ahora, (int)$t['id']]);
    }
    $db->commit();
    adr_json(['ok' => true, 'tarea' => (int)$t['id'], 'modo' => $modo, 'entradas' => $n, 'borrador' => (bool)$bor]);
}

case 'resellar_tarea': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $cif = isset($in['cifrado']) ? adr_deb64($in['cifrado']) : null;
    if (isset($in['cifrado']) && (!$cif || strlen($cif) > 1024 * 1024)) adr_json(['error' => 'sobre no válido'], 400);
    /* 17-10: un documento vivo o un registro también, aunque ya esté
       empezado; y con `borrador` (lo que tenía escrito, sellado a la
       clave nueva) para que no lo pierda. */
    $q = $db->prepare("SELECT t.*, p.publica_v AS pv FROM tareas t JOIN pacientes p ON p.cod = t.cod
                       WHERE t.id = ? AND t.tipo = 'herramienta' AND (t.hecho IS NULL OR t.hecho <> 'retirada')");
    $q->execute([(int)($in['id'] ?? 0)]);
    $f = $q->fetch();
    if (!$f || ($f['hecho'] !== null && adr_modo_fila($f) === 'una')) adr_json(['error' => 'no existe o ya está hecha'], 404);
    if ((int)($in['para_v'] ?? -1) !== (int)$f['pv']) adr_json(['error' => 'clave caducada'], 409);
    if (!$cif && $f['cifrado'] !== null) adr_json(['error' => 'falta la definición sellada'], 400);
    $bor = isset($in['borrador']) ? adr_deb64($in['borrador']) : null;
    $db->prepare('UPDATE tareas SET cifrado = COALESCE(?, cifrado), para_v = ?, borrador = ?, borrador_v = ? WHERE id = ?')
       ->execute([$cif, (int)$in['para_v'], $bor, $bor ? (int)$in['para_v'] : null, (int)$in['id']]);
    adr_json(['ok' => true]);
}

/* El Mac ha mandado SU aviso a esta cuenta: que el portal lo cuente para
   no mandar otro «tienes algo» en las próximas 2 horas. */
case 'aviso_enviado': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $q = $db->prepare('UPDATE pacientes SET ultimo_aviso = ? WHERE cod = ?');
    $q->execute([adr_ahora(), (string)($in['cod'] ?? '')]);
    adr_json(['ok' => (bool)$q->rowCount()]);
}

/* Corregir la hora de una cita de pago (si Cal.com no avisó del cambio). */
/* El Mac ya ha mandado el recordatorio de la víspera de esta cita: que el
   portal no mande otro. */
case 'vispera_enviada': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/pagos.php';
    adr_pagos_tabla($db);
    $q = $db->prepare("UPDATE citas SET vispera = COALESCE(vispera, ?) WHERE uid = ?");
    $q->execute(['mac:' . date('c'), (string)($in['uid'] ?? '')]);
    adr_json(['ok' => true, 'marcadas' => $q->rowCount()]);
}

case 'mover_pago': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/pagos.php';
    adr_pagos_tabla($db);
    $inicio = (string)($in['inicio'] ?? '');
    if ($inicio === '' || strtotime($inicio) === false) adr_json(['error' => 'falta la hora nueva'], 400);
    $q = $db->prepare('UPDATE citas SET inicio = ? WHERE uid = ?');
    $q->execute([adr_inicio_madrid($inicio), (string)($in['uid'] ?? '')]);
    adr_json(['ok' => (bool)$q->rowCount(), 'movidas' => $q->rowCount()]);
}

/* Lo que ha llegado de Cal.com (para entender un desajuste). */
case 'eventos_cal': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    $db->exec('CREATE TABLE IF NOT EXISTS cal_eventos (id INTEGER PRIMARY KEY, llegado TEXT, evento TEXT, slug TEXT,
               uid TEXT, uid_anterior TEXT, inicio TEXT, inicio_anterior TEXT)');
    $u = (string)($in['uid'] ?? '');
    $q = $u !== '' ? $db->prepare('SELECT * FROM cal_eventos WHERE uid = ? OR uid_anterior = ? ORDER BY id DESC LIMIT 50')
                   : $db->prepare('SELECT * FROM cal_eventos ORDER BY id DESC LIMIT 100');
    $q->execute($u !== '' ? [$u, $u] : []);
    adr_json(['eventos' => $q->fetchAll()]);
}

/* Anular una cita de pago (Adrián la quita: no se cobra). */
case 'anular_pago': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/pagos.php';
    adr_pagos_tabla($db);
    $q = $db->prepare("UPDATE citas SET estado = 'cancelada' WHERE uid = ? AND pagado_en IS NULL");
    $q->execute([(string)($in['uid'] ?? '')]);
    adr_json(['ok' => true, 'anuladas' => $q->rowCount()]);
}

/* El Mac: poner o ver la configuración de pagos. Las claves se guardan
   cifradas y nunca se devuelven: solo si están puestas. La clave de
   Stripe tiene que ser RESTRINGIDA (rk_…), nunca la secreta completa. */
case 'config_pagos': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/pagos.php';
    adr_pagos_tabla($db);
    $cfg = adr_pagos_cfg($ADR) ?: ['importe' => 30];
    $nuevo = $cfg;
    foreach (['stripe_clave', 'stripe_webhook', 'cal_secreto', 'slug', 'precio'] as $k) {
        if (isset($in[$k]) && trim((string)$in[$k]) !== '') $nuevo[$k] = trim((string)$in[$k]);
    }
    if (isset($in['importe'])) $nuevo['importe'] = max(1, (int)$in['importe']);
    if (!empty($in['stripe_clave'])) {
        if (!preg_match('/^rk_(test|live)_[A-Za-z0-9]+$/', $nuevo['stripe_clave'])) {
            adr_json(['error' => 'la clave de Stripe tiene que ser una clave RESTRINGIDA (empieza por rk_test_ o rk_live_), nunca la secreta completa'], 400);
        }
    }
    if (!empty($in['stripe_webhook']) && !str_starts_with($nuevo['stripe_webhook'], 'whsec_')) {
        adr_json(['error' => 'el secreto del webhook de Stripe empieza por whsec_'], 400);
    }
    /* Clave nueva (sobre todo, de prueba a real): lo que se creó con la
       anterior no existe con la nueva. Fuera el precio y los clientes de
       Stripe guardados y las sesiones de pago abiertas (un enlace viejo
       llevaría a la página de pago de prueba). Y al pasar de prueba a
       real, lo «pagado» en prueba no es dinero: esas citas quedan como
       'prueba' y desaparecen de Cobros y de la cuenta del paciente. */
    $antes = (string)($cfg['stripe_clave'] ?? '');
    if (!empty($in['stripe_clave']) && $nuevo['stripe_clave'] !== $antes) {
        unset($nuevo['precio']);
        $db->exec('UPDATE citas SET cliente = NULL');
        $db->exec('UPDATE citas SET checkout_id = NULL, checkout_url = NULL, checkout_caduca = NULL WHERE pagado_en IS NULL');
        if (str_contains($antes, '_test_') && str_contains($nuevo['stripe_clave'], '_live_')) {
            $db->exec("UPDATE citas SET estado = 'prueba' WHERE pagado_en IS NOT NULL");
            adr_apunta($db, 'mac', 'pagos: de prueba a real');
        }
    }
    if ($nuevo !== $cfg) {
        adr_pagos_guarda($ADR, $nuevo);
        // Que la caché estática no devuelva la de antes en esta misma petición.
        $cfg = $nuevo;
    }
    $prueba = null;
    if (!empty($in['stripe_clave']) || !empty($in['probar'])) {
        // Se comprueba la clave contra Stripe, permiso por permiso (sin crear nada).
        require_once __DIR__ . '/lib/pagos.php';
        $perm = adr_stripe_permisos($ADR);
        $prueba = ['ok' => $perm['clave_valida'] && !$perm['faltan'] && !$perm['detalle'],
                   'clave_valida' => $perm['clave_valida'], 'faltan' => $perm['faltan'],
                   'detalle' => !$perm['clave_valida'] ? $perm['detalle']
                       : ($perm['faltan'] ? 'a la clave le faltan permisos: ' . implode(', ', $perm['faltan']) : $perm['detalle'])];
    }
    if (!empty($nuevo['stripe_clave'])) {
        /* Con Stripe, fuera los datos bancarios que se publicaban a cada cuenta. */
        $db->exec("DELETE FROM sobres WHERE clase = 'pago'");
    }
    $precio_err = null;
    if ($prueba && $prueba['clave_valida'] && !array_intersect(['Products (Escritura)', 'Prices (Escritura)'], $prueba['faltan']) && empty($cfg['precio'])) {
        /* El producto y el precio los crea el servidor (lookup_key «sesion_individual»). */
        [$pid, $precio_err] = adr_stripe_precio($ADR, (int)($cfg['importe'] ?? 30));
        if ($pid) { $cfg['precio'] = $pid; adr_pagos_guarda($ADR, $cfg); }
    }
    $base = rtrim($ADR['sitio'], '/');
    adr_json(['ok' => true, 'configurado' => !empty($cfg['stripe_clave']),
              'modo' => str_contains((string)($cfg['stripe_clave'] ?? ''), '_test_') ? 'prueba' : (!empty($cfg['stripe_clave']) ? 'real' : null),
              'slug' => $cfg['slug'] ?? null, 'importe' => (int)($cfg['importe'] ?? 30), 'precio' => $cfg['precio'] ?? null,
              'tiene' => ['stripe_clave' => !empty($cfg['stripe_clave']), 'stripe_webhook' => !empty($cfg['stripe_webhook']),
                          'cal_secreto' => !empty($cfg['cal_secreto'])],
              'webhooks' => ['cal' => $base . '/api.php?a=cal_webhook', 'stripe' => $base . '/api.php?a=stripe_webhook'],
              'prueba_clave' => $prueba, 'precio_error' => $precio_err,
              'ultimo_error' => (function () use ($db) { $e = $db->query("SELECT valor FROM ajustes WHERE clave = 'pagos_ultimo_error'")->fetch();
                                                          return $e ? json_decode($e['valor'], true) : null; })()]);
}

case 'estado_correo': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/correo.php';
    $ok = adr_correo_lee($ADR, 'correo_ultimo_ok');
    $er = adr_correo_lee($ADR, 'correo_ultimo_error');
    $c = $db->query("SELECT COUNT(*) n, MIN(creado) m FROM correos_cola WHERE enviado IS NULL")->fetch();
    $tiene_mac = (bool)$db->query("SELECT 1 FROM ajustes WHERE clave = 'mac_publica'")->fetch();
    $google = adr_google_cred($ADR);
    /* Con Google, el portal manda al momento y la cola es solo el plan B
       (lo que Gmail no aceptó). Funciona si el último intento salió bien
       y no hay nada atascado en la cola. Sin Google, como antes: la cola
       la vacía el Mac cada 5 minutos. */
    $atascada = (int)$c['n'] > 0 && strtotime($c['m']) < time() - 1800;
    $cuando = fn($x) => (float)($x['ts'] ?? strtotime($x['fecha'] ?? '') ?: 0);
    $ultimo_falla = $er && (!$ok || $cuando($er) > $cuando($ok));
    adr_json(['ok' => true, 'modo' => $google ? 'google' : 'cola', 'envia_el_portal' => (bool)$google,
              'remite' => $google['remite'] ?? null,
              'configurado' => $google ? true : $tiene_mac,
              'operativo' => $google ? (!$ultimo_falla && !$atascada) : ($tiene_mac && !$atascada),
              'cola' => ['pendientes' => (int)$c['n'], 'mas_antiguo' => $c['m']],
              'ultimo_ok' => $ok, 'ultimo_error' => $er]);
}

/* El Mac le da al portal la credencial de Google de hola@ (solo
   gmail.send) para que «he olvidado mi contraseña» y los avisos salgan al
   momento, sin depender de que el Mac esté encendido. Se comprueba
   pidiendo un token antes de guardarla, y se guarda cifrada.
   {client_id, client_secret, refresh_token, remite} o {quitar: true}. */
case 'correo_google': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/correo.php';
    if (!empty($in['quitar'])) {
        adr_google_guarda($ADR, null);
        adr_apunta($db, 'mac', 'correo_google_quitado');
        adr_json(['ok' => true, 'modo' => 'cola']);
    }
    $cred = ['client_id' => (string)($in['client_id'] ?? ''), 'client_secret' => (string)($in['client_secret'] ?? ''),
             'refresh_token' => (string)($in['refresh_token'] ?? ''),
             'remite' => adr_correo_normal((string)($in['remite'] ?? ($ADR['remite'] ?? 'hola@adricamente.com')))];
    if (!$cred['client_id'] || !$cred['refresh_token'] || !filter_var($cred['remite'], FILTER_VALIDATE_EMAIL)) {
        adr_json(['error' => 'faltan client_id, refresh_token o remite'], 400);
    }
    if (!function_exists('openssl_encrypt')) adr_json(['error' => 'el PHP del servidor no tiene openssl: no puedo guardarla cifrada'], 500);
    $t = adr_google_token($ADR, $cred, true);
    if (empty($t['token'])) {
        adr_json(['error' => ($t['codigo'] ?? 0) === 0
                      ? 'el servidor no ha podido conectar con Google (' . ($t['detalle'] ?? '') . ')'
                      : 'Google no ha aceptado la credencial', 'detalle' => $t], ($t['codigo'] ?? 0) === 0 ? 502 : 400);
    }
    adr_google_guarda($ADR, $cred);
    adr_apunta($db, 'mac', 'correo_google_puesto');
    adr_json(['ok' => true, 'modo' => 'google', 'remite' => $cred['remite']]);
}

/* Un correo de prueba, para comprobar de verdad que sale (con su id de
   Gmail). Solo el Mac, y a la dirección que diga. */
case 'probar_correo': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/correo.php';
    $a = adr_correo_normal((string)($in['a'] ?? ''));
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) adr_json(['error' => 'correo no válido'], 400);
    if (adr_via($ADR) !== 'google') adr_json(['error' => 'el portal no tiene Google configurado', 'modo' => adr_via($ADR)], 409);
    $r = adr_google_envia($ADR, $a, 'Prueba del portal de adricamente',
        "Esto es una prueba: si lo lees, los correos del portal salen bien.\n",
        adr_correo_marco('Prueba del portal', adr_correo_t('Correo de prueba')
            . adr_correo_p('Esto es una prueba: si lo lees, los correos del portal salen bien.'), 'Los correos del portal salen bien.', true));
    adr_correo_apunta($ADR, $r, (int)($r['codigo'] ?? 0), (string)($r['detalle'] ?? ''));
    adr_json($r, $r['enviado'] ? 200 : 502);
}

case 'estado': {
    if (!adr_es_el_mac($ADR)) adr_json(['error' => 'no'], 403);
    require_once __DIR__ . '/lib/correo.php';
    adr_json(['ok' => true,
              'correo' => adr_via($ADR),
              /* Lo que el portal necesita del PHP del alojamiento, para que
                 un fallo se vea aquí y no como un 500. */
              'extensiones' => ['sodium' => function_exists('sodium_crypto_box_seal'),
                                'openssl' => function_exists('openssl_encrypt'),
                                'curl' => function_exists('curl_init'),
                                'allow_url_fopen' => (bool)ini_get('allow_url_fopen')],
              'remite' => $ADR['remite'] ?? null,
              'php' => PHP_VERSION,
              'pacientes' => (int)$db->query("SELECT COUNT(*) FROM pacientes WHERE cod <> 'hoja'")->fetchColumn(),
              'activados' => (int)$db->query("SELECT COUNT(*) FROM pacientes WHERE activado IS NOT NULL AND cod <> 'hoja'")->fetchColumn()]);
}

default:
    adr_json(['error' => 'no'], 404);
}
