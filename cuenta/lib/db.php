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
