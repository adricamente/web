<?php
if (!defined('ADR_DENTRO')) { http_response_code(404); exit; }
/* Herramientas que viven (17-10, tarde).
   ---------------------------------------------------------------------
   Hasta hoy, una herramienta se rellenaba y se enviaba UNA vez, y lo
   enviado quedaba de solo lectura. Eso vale para una ficha de una
   sesión, no para cómo se usan de verdad:

     · el plan de seguridad crece con la terapia (una persona nueva a la
       que llamar, una estrategia que ya no sirve): es un documento VIVO,
       que se edita siempre y se tiene a un toque;
     · la tarjeta diaria, el autorregistro o el diario de pánico son
       REGISTROS: una entrada cada día (o cada vez que pasa algo), con lo
       anterior plegado por semanas.

   Y a Adrián no le sirve un aviso por cada entrada: le sirve saber,
   antes de cada sesión, qué se ha hecho. Eso es `adr_antes_sesion`,
   que alimenta la consola y el correo de la mañana.

   El servidor sigue sin leer nada: sabe qué herramienta es (el título y
   el id, como antes), de qué día es una entrada y cuándo se cambió un
   documento. Lo escrito va sellado. */

/* El modo por defecto de cada herramienta del catálogo. El catálogo
   puede traer su propio `modo`, que manda; esto cubre lo que ya estaba
   asignado y el catálogo de hoy. */
const ADR_MODOS = [
    'plan-seguridad' => 'vivo', 'pu-4-1' => 'vivo', 'pu-4-2' => 'vivo', 'pu-9-1' => 'vivo',
    'pu-11-1' => 'vivo', 'pu-13-2' => 'vivo',
    'tarjeta-diaria' => 'registro', 'autorregistro' => 'registro', 'reestructuracion' => 'registro',
    'diario-panico' => 'registro', 'reparto-merito' => 'registro', 'tarde-y-sueno' => 'registro',
    'dormir-mejor' => 'registro', 'pu-3-5' => 'registro', 'pu-5-1' => 'registro', 'pu-6-1' => 'registro',
    'pu-7-1' => 'registro', 'pu-8-1' => 'registro', 'pu-8-2' => 'registro', 'pu-9-2' => 'registro',
    'pu-10-2' => 'registro', 'pu-11-2' => 'registro',
];

function adr_modo_valido($m): ?string {
    return in_array($m, ['una', 'vivo', 'registro'], true) ? $m : null;
}

/** El modo de una herramienta: el que diga el catálogo o, si no, el de
 *  siempre para ese id. Una lectura (guía) es siempre de una vez. */
function adr_modo_para(?array $def, $pedido = null): string {
    if (($def['tipo'] ?? '') === 'lectura') return 'una';
    return adr_modo_valido($pedido) ?? adr_modo_valido($def['modo'] ?? null)
        ?? (ADR_MODOS[(string)($def['id'] ?? '')] ?? 'una');
}

/** El modo de una fila de `tareas`, también de las de antes del 17-10. */
function adr_modo_fila(array $t): string {
    if (($t['tipo'] ?? '') !== 'herramienta') return 'una';
    if ($m = adr_modo_valido($t['modo'] ?? null)) return $m;
    $def = json_decode((string)($t['plantilla'] ?? ''), true);
    return adr_modo_para(is_array($def) ? $def : null);
}

function adr_herr_id(array $t): ?string {
    $def = json_decode((string)($t['plantilla'] ?? ''), true);
    return is_array($def) && isset($def['id']) ? (string)$def['id'] : null;
}

/** Madrid, siempre: «hoy» es el de Adrián y sus pacientes. */
function adr_madrid(string $cuando = 'now'): DateTimeImmutable {
    return new DateTimeImmutable($cuando, new DateTimeZone('Europe/Madrid'));
}

/* --- El recordatorio que se pone el paciente ---------------------------
   Discreto: no dice qué registro ni de qué va (un correo se lee en una
   pantalla bloqueada). Uno al día como mucho, solo si ese día aún no ha
   apuntado nada, y solo en las tres horas siguientes a la hora que
   eligió: si el servidor no ha tenido ninguna pasada en ese rato, mejor
   no mandarlo a las tres de la mañana. */
function adr_recordatorios(PDO $db, array $ADR): int {
    $ahora = adr_madrid();
    $hoy = $ahora->format('Y-m-d');
    $hm = $ahora->format('H:i');
    $q = $db->prepare("SELECT r.cod, r.tarea, r.hora, p.correo FROM recordatorios r
                       JOIN pacientes p ON p.cod = r.cod AND p.activado IS NOT NULL
                       JOIN tareas t ON t.id = r.tarea AND t.cod = r.cod
                       WHERE (r.ultimo IS NULL OR r.ultimo < ?) AND r.hora <= ?
                         AND (t.hecho IS NULL OR t.hecho <> 'retirada')");
    $q->execute([$hoy, $hm]);
    $n = 0;
    foreach ($q->fetchAll() as $r) {
        $limite = adr_madrid($hoy . 'T' . $r['hora'] . ':00')->modify('+3 hours');
        $ya = $db->prepare('SELECT 1 FROM entradas WHERE tarea = ? AND fecha = ? AND borrada = 0');
        $ya->execute([$r['tarea'], $hoy]);
        $marca = $db->prepare('UPDATE recordatorios SET ultimo = ? WHERE cod = ? AND tarea = ?');
        if ($ya->fetch() || $ahora > $limite) { $marca->execute([$hoy, $r['cod'], $r['tarea']]); continue; }
        if (!$r['correo']) continue;
        $texto = "Es la hora que elegiste para tu apunte de hoy. Te lleva un minuto.\n\n"
               . $ADR['sitio'] . "\n\n"
               . "Si hoy no puedes, no pasa nada: mañana sigue ahí. Lo quitas o cambias la hora en tu cuenta.\n";
        $html = adr_correo_t('Tu apunte de hoy')
              . adr_correo_p('Es la hora que elegiste para tu apunte de hoy. Te lleva un minuto.')
              . adr_correo_boton($ADR['sitio'], 'Abrir mi cuenta')
              . adr_correo_p('Si hoy no puedes, no pasa nada: mañana sigue ahí. Lo quitas o cambias la hora en tu cuenta.', true);
        $res = adr_manda($ADR, $r['correo'], 'Un recordatorio de tu cuenta', $texto,
            adr_correo_marco('Un recordatorio de tu cuenta', $html, 'Es la hora que elegiste para tu apunte de hoy.', true),
            'recordatorio', $r['cod']);
        if (!empty($res['enviado']) || ($res['error'] ?? '') === 'en_cola') {
            $marca->execute([$hoy, $r['cod'], $r['tarea']]);
            $n++;
        }
    }
    return $n;
}

/* --- Antes de la sesión ------------------------------------------------
   Para cada cita entre $desde y $hasta: si está pagada y, de su cuenta,
   lo que ha hecho DESDE LA SESIÓN ANTERIOR (o los últimos 7 días si no
   hay anterior) y lo que sigue pendiente. Títulos, fechas y recuentos:
   lo que el servidor ya ve. Las puntuaciones las pone la consola, que
   es quien las tiene. */
function adr_antes_sesion(PDO $db, DateTimeImmutable $desde, DateTimeImmutable $hasta): array {
    require_once __DIR__ . '/pagos.php';
    adr_pagos_tabla($db);
    $q = $db->prepare("SELECT uid, cod, inicio, pagado_en, nombre, lugar FROM citas
                       WHERE estado = 'activa' AND inicio >= ? AND inicio <= ?");
    $q->execute([$desde->modify('-1 day')->format('Y-m-d'), $hasta->modify('+1 day')->format('Y-m-d') . 'T99']);
    $out = [];
    foreach ($q->fetchAll() as $c) {
        try { $ini = adr_madrid($c['inicio'])->setTimezone(new DateTimeZone('Europe/Madrid')); }
        catch (Throwable $e) { continue; }
        if ($ini < $desde || $ini > $hasta) continue;
        $fila = ['uid' => $c['uid'], 'cod' => $c['cod'], 'inicio' => $ini->format('c'),
                 'hora' => $ini->format('H:i'), 'pagada' => $c['pagado_en'] !== null,
                 'nombre' => $c['nombre'], 'lugar' => $c['lugar'], 'cuenta' => null];
        if ($c['cod']) $fila['cuenta'] = adr_actividad($db, (string)$c['cod'], $ini);
        $out[] = $fila;
    }
    usort($out, fn($a, $b) => strcmp($a['inicio'], $b['inicio']));
    return $out;
}

/** Lo hecho y lo pendiente de una cuenta, desde la sesión anterior a $cita. */
function adr_actividad(PDO $db, string $cod, DateTimeImmutable $cita): array {
    /* La sesión anterior: la última cita activa de esta cuenta antes de ésta. */
    $p = $db->prepare("SELECT inicio FROM citas WHERE cod = ? AND estado = 'activa' AND inicio < ?
                       ORDER BY inicio DESC LIMIT 5");
    $p->execute([$cod, $cita->format('Y-m-d\TH:i')]);
    $desde = null;
    foreach ($p->fetchAll() as $x) {
        try { $t = adr_madrid($x['inicio']); } catch (Throwable $e) { continue; }
        if ($t < $cita->modify('-1 hour')) { $desde = $t; break; }
    }
    $anterior = $desde ? $desde->format('c') : null;
    if (!$desde || $desde < $cita->modify('-60 days')) $desde = $cita->modify('-7 days')->setTime(23, 59, 59);
    /* Los días del periodo: del siguiente a la sesión anterior al de esta
       (incluido: lo apuntado esa mañana cuenta). «5 de 7 días». */
    $d0 = $desde->modify('+1 day')->format('Y-m-d');
    $d1 = $cita->format('Y-m-d');
    $dias = max(1, (int)$desde->setTime(0, 0)->diff($cita->setTime(0, 0))->days);

    $t = $db->prepare("SELECT id, titulo, tipo, plantilla, modo, creado, caduca, hecho, actualizado
                       FROM tareas WHERE cod = ? AND (hecho IS NULL OR hecho <> 'retirada')");
    $t->execute([$cod]);
    $pendientes = []; $pruebas = []; $herr = []; $registros = []; $vivos = [];
    foreach ($t->fetchAll() as $x) {
        $modo = adr_modo_fila($x);
        $caducada = $x['caduca'] && substr((string)$x['caduca'], 0, 10) < adr_hoy();
        if ($x['hecho'] === null) {
            if (!$caducada) $pendientes[] = ['titulo' => $x['titulo'], 'tipo' => $x['tipo'], 'modo' => $modo,
                                             'desde' => $x['creado']];
            if ($modo === 'una') continue;
        }
        if ($modo === 'registro') {
            $e = $db->prepare("SELECT DISTINCT fecha FROM entradas WHERE tarea = ? AND borrada = 0
                               AND fecha >= ? AND fecha <= ? ORDER BY fecha");
            $e->execute([$x['id'], $d0, $d1]);
            $f = array_column($e->fetchAll(), 'fecha');
            $n = $db->prepare("SELECT COUNT(*) AS n FROM entradas WHERE tarea = ? AND borrada = 0 AND fecha >= ? AND fecha <= ?");
            $n->execute([$x['id'], $d0, $d1]);
            $registros[] = ['titulo' => $x['titulo'], 'dias' => count($f), 'de' => $dias, 'fechas' => $f,
                            'entradas' => (int)$n->fetch()['n']];
        } elseif ($modo === 'vivo') {
            $vivos[] = ['titulo' => $x['titulo'], 'actualizado' => $x['actualizado'],
                        'cambiado' => $x['actualizado'] !== null && $x['actualizado'] >= $desde->format('c')];
        } elseif ($x['hecho'] !== null && $x['hecho'] >= $desde->format('c')) {
            if ($x['tipo'] === 'herramienta') $herr[] = ['titulo' => $x['titulo'], 'hecho' => $x['hecho']];
            else $pruebas[] = ['titulo' => $x['titulo'], 'hecho' => $x['hecho']];
        }
    }
    return ['desde' => $desde->format('c'), 'sesion_anterior' => $anterior, 'dias' => $dias,
            'pendientes' => $pendientes, 'pruebas' => $pruebas, 'herramientas' => $herr,
            'registros' => $registros, 'vivos' => $vivos];
}

/* --- El correo de la mañana a Adrián ------------------------------------
   Uno al día, a partir de las 7:00, solo si hay sesiones. Sin nombres
   (se lee en una pantalla bloqueada) y sin nada clínico: la hora, si
   está pagada y cuánto falta. El detalle, en la consola. */
function adr_resumen_dia(PDO $db, array $ADR, bool $forzar = false): ?array {
    $ahora = adr_madrid();
    $hoy = $ahora->format('Y-m-d');
    if (!$forzar && (int)$ahora->format('G') < 7) return null;
    $ya = $db->query("SELECT valor FROM ajustes WHERE clave = 'resumen_dia'")->fetch();
    if (!$forzar && $ya && $ya['valor'] === $hoy) return null;
    $marca = function () use ($db, $hoy) {
        $db->prepare("INSERT INTO ajustes (clave, valor, puesto) VALUES ('resumen_dia', ?, ?)
                      ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor, puesto = excluded.puesto")
           ->execute([$hoy, adr_ahora()]);
    };
    $citas = adr_antes_sesion($db, $ahora->setTime(0, 0), $ahora->setTime(23, 59, 59));
    if (!$citas) { $marca(); return ['citas' => 0]; }

    $lineas = []; $html = '';
    foreach ($citas as $c) {
        $partes = [$c['pagada'] ? 'pagada' : 'sin pagar con tarjeta'];
        $a = $c['cuenta'];
        if (!$a) {
            $partes[] = 'sin cuenta vinculada';
        } else {
            $hechas = count($a['pruebas']) + count($a['herramientas']);
            $falta = count($a['pendientes']);
            if ($hechas) $partes[] = $hechas === 1 ? '1 cosa hecha' : "$hechas cosas hechas";
            if ($a['registros']) {
                $d = array_map(fn($r) => $r['dias'], $a['registros']);
                $partes[] = count($d) === 1 ? "registro {$d[0]} de {$a['dias']} días"
                    : count($d) . ' registros (' . implode(', ', array_slice($d, 0, -1)) . ' y ' . end($d) . " de {$a['dias']} días)";
            }
            foreach ($a['vivos'] as $v) if ($v['cambiado']) { $partes[] = 'ha actualizado un documento'; break; }
            $partes[] = $falta ? ($falta === 1 ? 'falta 1' : "faltan $falta") : 'nada pendiente';
        }
        $l = $c['hora'] . ' · ' . implode(' · ', $partes);
        $lineas[] = $l;
        $html .= '<li style="margin:0 0 8px">' . htmlspecialchars($l) . '</li>';
    }
    $n = count($citas);
    $asunto = $n === 1 ? 'Tu sesión de hoy' : "Tus $n sesiones de hoy";
    $texto = "Hoy tienes $n " . ($n === 1 ? 'sesión' : 'sesiones') . ":\n\n" . implode("\n", $lineas)
           . "\n\nEl detalle (quién, qué pruebas y cómo han salido) está en la consola, en Hoy → «Antes de la sesión».\n";
    $cuerpo = adr_correo_t($asunto)
            . '<ul style="padding-left:18px;margin:0 0 16px">' . $html . '</ul>'
            . adr_correo_p('El detalle (quién, qué pruebas y cómo han salido) está en la consola, en Hoy → «Antes de la sesión».', true);
    $a = (string)((adr_google_cred($ADR) ?: [])['remite'] ?? ($ADR['remite'] ?? 'hola@adricamente.com'));
    $r = adr_manda($ADR, $a, $asunto, $texto, adr_correo_marco($asunto, $cuerpo, $lineas[0], true), 'resumen_adrian');
    if (!empty($r['enviado']) || ($r['error'] ?? '') === 'en_cola') $marca();
    return ['citas' => $n, 'enviado' => !empty($r['enviado']), 'lineas' => $lineas];
}
