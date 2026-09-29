<?php
/* ===================================================================
   La base de datos. SQLite, un fichero, fuera de la carpeta pública.
   -------------------------------------------------------------------
   SQLite y no MySQL por una razón concreta: aquí hay cinco pacientes,
   no cinco mil, y un fichero se copia, se cifra y se lleva a otro
   sitio con `cp`. Una base de datos que no sabes respaldar no está
   respaldada. Si algún día esto crece, el cambio es de una clase.

   Lo que hay dentro, y lo que NO hay dentro:

     pacientes   correo, sal, la privada ENVUELTA, el verificador de
                 la contraseña y la clave pública del navegador.
                 Ni un dato clínico.
     sobres      bloques cifrados que el servidor no puede abrir, en
                 las dos direcciones.
     fichas      papeles de un solo uso: activar cuenta, recuperar
                 contraseña. Guardados por su huella, no enteros.
     intentos    el freno, para que nadie pruebe contraseñas a mansalva.

   No hay tabla de respuestas, ni de puntuaciones, ni de sesiones
   clínicas. Eso vive en el Mac y no sube nunca.
   =================================================================== */

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


function adr_db(array $ADR): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $ruta = $ADR['datos'];
    $dir = dirname($ruta);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);

    $pdo = new PDO('sqlite:' . $ruta, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    @chmod($ruta, 0600);

    /* WAL para que una lectura no bloquee una escritura, y claves
       foráneas de verdad, que SQLite no activa por su cuenta. */
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 4000');

    $pdo->exec('
      CREATE TABLE IF NOT EXISTS pacientes (
        cod         TEXT PRIMARY KEY,
        correo      TEXT NOT NULL UNIQUE,
        sal         BLOB,
        envuelta    BLOB,
        verificador TEXT,
        publica     BLOB,
        publica_v   INTEGER NOT NULL DEFAULT 0,
        alta        TEXT NOT NULL,
        activado    TEXT,
        ultimo      TEXT
      )');

    /* direccion: 1 = del paciente al Mac, 2 = del Mac al paciente.
       `recogido` lo marca el Mac cuando se lo ha llevado; no se borra
       en el acto para que un fallo de red no pierda un cuestionario. */
    $pdo->exec('
      CREATE TABLE IF NOT EXISTS sobres (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        cod        TEXT NOT NULL REFERENCES pacientes(cod),
        direccion  INTEGER NOT NULL,
        titulo     TEXT,
        cifrado    BLOB NOT NULL,
        para_v     INTEGER NOT NULL DEFAULT 0,
        creado     TEXT NOT NULL,
        recogido   TEXT
      )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS i_sobres ON sobres(cod, direccion, recogido)');

    /* `clase` separa lo que el paciente ve en sitios distintos:

         sesion      la hoja de «lo que nos llevamos» de cada sesión
         documento   consentimiento, material, cosas que se guardan
         ejercicio   lo que ha entregado él

       Va en claro, y es lo ÚNICO que el servidor sabe de un sobre
       además de su tamaño. No es contenido clínico: es en qué estante
       lo pone. Sin esto todo cae en una lista sola y la persona tiene
       que leer doce títulos para encontrar el consentimiento. */
    foreach (['clase' => "TEXT NOT NULL DEFAULT 'documento'"] as $col => $tipo) {
        $hay = false;
        foreach ($pdo->query('PRAGMA table_info(sobres)')->fetchAll() as $c) {
            if ($c['name'] === $col) { $hay = true; break; }
        }
        if (!$hay) $pdo->exec("ALTER TABLE sobres ADD COLUMN $col $tipo");
    }

    /* Las tareas: lo que el paciente tiene PENDIENTE de hacer.
       -------------------------------------------------------------
       La plantilla —los ítems del cuestionario, las opciones y cuál es
       el ítem de riesgo— se guarda EN CLARO, y es correcto: el PHQ-9
       es el mismo para todo el mundo y está publicado. Lo que va
       sellado son las RESPUESTAS, que es lo único que dice algo de
       alguien.

       Confundir las dos cosas llevaría a cifrar la plantilla, que no
       protege nada, y a sentirse a salvo por ello. */
    $pdo->exec('
      CREATE TABLE IF NOT EXISTS tareas (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        cod       TEXT NOT NULL REFERENCES pacientes(cod),
        titulo    TEXT NOT NULL,
        plantilla TEXT NOT NULL,
        creado    TEXT NOT NULL,
        caduca    TEXT,
        hecho     TEXT
      )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS i_tareas ON tareas(cod, hecho)');

    /* --- Lo que se fue añadiendo después ---------------------------
       Columnas nuevas sobre tablas que ya tienen datos. Se añaden una a
       una comprobando si están, que es la forma de que actualizar el
       portal no sea nunca «borra la base de datos y empieza». */
    $columnas = [
        'sobres' => [
            /* Cuándo lo firmó el paciente. El servidor no sabe QUÉ
               firmó —va cifrado— pero sí que lo firmó y cuándo, que es
               justo lo que un consentimiento necesita poder demostrar. */
            'requiere_firma' => 'INTEGER NOT NULL DEFAULT 0',
            'firmado'        => 'TEXT',
        ],
        'tareas' => [
            /* cuestionario | deber. El cuestionario lleva su plantilla
               en claro (el PHQ-9 es público). El deber lleva su texto
               CIFRADO: «registra tres domingos seguidos qué hiciste»
               sí dice algo de alguien. */
            'tipo'    => "TEXT NOT NULL DEFAULT 'cuestionario'",
            'cifrado' => 'BLOB',
            'para_v'  => 'INTEGER NOT NULL DEFAULT 0',
        ],
        'pacientes' => [
            /* Cuándo se le avisó por última vez de que tiene algo
               pendiente. Sirve para no mandarle cuatro correos seguidos
               cuando se le asignan cuatro cosas en un minuto. */
            'ultimo_aviso' => 'TEXT',
        ],
    ];
    foreach ($columnas as $tabla => $cols) {
        $hay = [];
        foreach ($pdo->query("PRAGMA table_info($tabla)")->fetchAll() as $c) $hay[] = $c['name'];
        foreach ($cols as $col => $tipo) {
            if (!in_array($col, $hay, true)) $pdo->exec("ALTER TABLE $tabla ADD COLUMN $col $tipo");
        }
    }

    /* Las medidas que se repiten solas.
       -------------------------------------------------------------
       Ésta es la pieza que hace que medir ocurra de verdad. La
       evidencia sobre seguimiento de resultados es modesta —d≈0,15, y
       llega a 0,29 en quien va mal— pero toda esa evidencia asume que
       la medida SE TOMA. Un cuestionario que hay que acordarse de
       mandar cada dos semanas se manda tres veces y se deja.

       No hay tarea programada en el alojamiento, así que esto no se
       dispara solo: se materializa cuando el Mac sincroniza, que es
       varias veces al día. Un día de retraso en un cuestionario
       quincenal no cambia nada; depender de un cron que el alojamiento
       compartido no garantiza, sí. */
    $pdo->exec('
      CREATE TABLE IF NOT EXISTS recurrencias (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        cod       TEXT NOT NULL REFERENCES pacientes(cod),
        titulo    TEXT NOT NULL,
        plantilla TEXT NOT NULL,
        cada_dias INTEGER NOT NULL,
        proxima   TEXT NOT NULL,
        creada    TEXT NOT NULL,
        activa    INTEGER NOT NULL DEFAULT 1
      )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS i_recu ON recurrencias(activa, proxima)');

    /* Cuatro cosas sueltas. Ahora mismo una: la clave PÚBLICA del Mac,
       que es a la que el navegador sella los cuestionarios.

       La sube el propio Mac con `portal_mac.py conectar` en vez de ir
       en el fichero de configuración, y no es un capricho: obliga a
       que la clave que el portal usa salga DEL MISMO sitio donde está
       la privada. Copiarla a mano a un fichero es la clase de paso en
       el que se pega la clave de otro par y nadie se entera hasta que
       un cuestionario no se abre. */
    $pdo->exec('
      CREATE TABLE IF NOT EXISTS ajustes (
        clave TEXT PRIMARY KEY,
        valor TEXT NOT NULL,
        puesto TEXT NOT NULL
      )');

    /* tipo: alta | reset. Se guarda la HUELLA del papel, no el papel:
       si alguien se lleva la base de datos, no puede usar los enlaces
       de recuperación que estén vivos en ese momento. */
    $pdo->exec('
      CREATE TABLE IF NOT EXISTS fichas (
        huella  TEXT PRIMARY KEY,
        cod     TEXT NOT NULL REFERENCES pacientes(cod),
        tipo    TEXT NOT NULL,
        caduca  INTEGER NOT NULL,
        usada   TEXT
      )');

    $pdo->exec('
      CREATE TABLE IF NOT EXISTS intentos (
        clave  TEXT NOT NULL,
        cuando INTEGER NOT NULL
      )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS i_intentos ON intentos(clave, cuando)');

    return $pdo;
}

function adr_ahora(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))
        ->format('Y-m-d\TH:i:sP');
}

/**
 * El freno.
 *
 * La privada envuelta vive aquí, así que quien se lleve la base de
 * datos puede atacar la contraseña sin prisa y sin límite. Contra eso
 * defiende Argon2id, no esto. Esto defiende de lo otro: de que se
 * prueben contraseñas contra el portal vivo, que es más tonto y más
 * frecuente.
 *
 * Cuenta por clave —correo, o huella de quien llama— y no distingue
 * si la cuenta existe: frenar solo a los correos reales sería otra
 * forma de decir cuáles lo son.
 */
function adr_freno(PDO $db, string $clave, int $tope = 10, int $ventana = 900): bool {
    $t = time();
    $db->prepare('DELETE FROM intentos WHERE cuando < ?')->execute([$t - $ventana]);
    $q = $db->prepare('SELECT COUNT(*) c FROM intentos WHERE clave = ? AND cuando >= ?');
    $q->execute([$clave, $t - $ventana]);
    if ((int)$q->fetch()['c'] >= $tope) return false;
    $db->prepare('INSERT INTO intentos (clave, cuando) VALUES (?, ?)')->execute([$clave, $t]);
    return true;
}

function adr_freno_limpia(PDO $db, string $clave): void {
    $db->prepare('DELETE FROM intentos WHERE clave = ?')->execute([$clave]);
}

/** Un papel de un solo uso. Devuelve el papel; guarda su huella. */
function adr_ficha(PDO $db, string $cod, string $tipo, int $horas): string {
    $papel = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $db->prepare('INSERT INTO fichas (huella, cod, tipo, caduca) VALUES (?,?,?,?)')
       ->execute([hash('sha256', $papel), $cod, $tipo, time() + $horas * 3600]);
    return $papel;
}

/** Gasta el papel. Devuelve el cod, o null. Un papel vale UNA vez. */
function adr_ficha_gasta(PDO $db, string $papel, string $tipo): ?string {
    $q = $db->prepare('SELECT cod, caduca, usada FROM fichas WHERE huella = ? AND tipo = ?');
    $q->execute([hash('sha256', $papel), $tipo]);
    $f = $q->fetch();
    if (!$f || $f['usada'] !== null || $f['caduca'] < time()) return null;
    $db->prepare('UPDATE fichas SET usada = ? WHERE huella = ?')
       ->execute([adr_ahora(), hash('sha256', $papel)]);
    return $f['cod'];
}
