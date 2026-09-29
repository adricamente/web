<?php
/* El portal, entero, en una sola página.
   -------------------------------------------------------------------
   Y esto no es pereza de no hacer cuatro ficheros: es la decisión que
   mantiene la promesa.

   La clave privada del paciente se recupera al entrar y vive EN
   MEMORIA. Si el portal fueran cuatro páginas, al pasar de una a otra
   el navegador tira el contexto entero y la clave se pierde — así que
   habría que guardarla en sessionStorage, o sea escribirla en el
   disco de un aparato que a veces no es solo suyo. Una sola página no
   navega, cambia de vista, y la clave no se escribe en ningún sitio.

   La consecuencia de eso se nota al recargar: la sesión del servidor
   sigue abierta, pero la llave ya no está en memoria y hay que
   escribir la contraseña otra vez. La primera versión no lo explicaba
   y parecía que te echaba. Ahora lo dice, y además el panel tiene un
   botón de actualizar para que recargar no haga falta casi nunca.

   Aquí NO hay «crear cuenta», y tampoco es un olvido. Quien entra,
   entra porque Adrián lo ha dado de alta. Un registro abierto en un
   portal de salud es una lista de correos de gente en tratamiento
   esperando a que alguien la pruebe.
   ------------------------------------------------------------------- */
declare(strict_types=1);

/* La llave que abre lib/. Ver el comentario de lib/arranque.php. */
const ADR_DENTRO = true;

/* Si falta la configuración, la portada es la pantalla de
   instalación. Se mira antes de cargar nada, porque arranque.php se
   para en seco —y con razón— cuando no hay secreto. */
$_hay = false;
foreach ([dirname(__DIR__) . '/adr-config.php', dirname(__DIR__, 2) . '/adr-config.php'] as $c) {
    if (is_file($c)) { $_hay = true; break; }
}
if (!$_hay) { require __DIR__ . '/lib/instalacion.php'; exit; }

require __DIR__ . '/lib/arranque.php';
require __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/pagina.php';

/* ¿Hay sesión viva? Entonces esto no es «entrar», es «seguir». La
   galleta demuestra que este navegador ya se autenticó, así que se
   puede rellenar el correo sin contárselo a nadie que no lo supiera
   ya. Lo que no se puede rellenar es la contraseña: no está aquí, no
   está en el servidor, y ése es justo el punto. */
$sigue = null;
if ($cod = adr_sesion($ADR['secreto'])) {
    $q = adr_db($ADR)->prepare('SELECT correo FROM pacientes WHERE cod = ?');
    $q->execute([$cod]);
    $f = $q->fetch();
    if ($f) $sigue = $f['correo'];
}
$correo_puesto = $sigue ? htmlspecialchars($sigue, ENT_QUOTES) : '';
$aviso_sigue = $sigue ? '
    <div class="nota ojo">
      <p><strong>Tu sesión sigue abierta.</strong> Escribe otra vez tu
      contraseña: tu llave no se guarda en este dispositivo, así que al
      recargar hay que volver a construirla. Es a propósito — si se
      guardara, quien cogiera este móvil entraría sin saberla.</p>
    </div>' : '';

$cuerpo = <<<HTML
<!-- Las vistas. Solo una visible cada vez; el guion las cambia sin
     navegar, que es lo que mantiene la clave en memoria. -->

<section id="v-entrar" class="vista">
  <div class="centrado">
    <h1>Entrar</h1>
    <p class="apunte">Tu cuenta de adricamente: lo que tienes pendiente,
    lo que nos llevamos de cada sesión y tus documentos.</p>

    {$aviso_sigue}

    <div class="nota mal" id="mal" hidden role="alert"><p id="mal-t"></p></div>

    <form id="f-entrar">
      <div class="campo">
        <label for="correo">Tu correo</label>
        <input type="email" id="correo" required autocomplete="username"
               autocapitalize="off" spellcheck="false" value="{$correo_puesto}">
      </div>
      <div class="campo">
        <label for="clave">Tu contraseña</label>
        <input type="password" id="clave" required autocomplete="current-password">
      </div>
      <p><button class="boton ancho" id="b-entrar" type="submit">Entrar</button></p>
      <p class="apunte" id="estado" role="status"></p>
    </form>

    <p class="apunte"><a href="clave.php?olvide=1">No me acuerdo de mi contraseña</a></p>

    <div class="caja plana" style="margin-top:22px">
      <p class="apunte" style="margin:0">Tu contraseña no sale de este
      dispositivo. Se usa aquí mismo para abrir lo que te he mandado, y
      lo que viaja al servidor no sirve para leerlo.</p>
    </div>
  </div>
</section>
HTML;

$cuerpo .= <<<'HTML'
<section id="v-panel" class="vista" hidden>
  <p class="etiqueta">Tu cuenta</p>
  <h1>Tu espacio</h1>
  <!-- Salir. No estaba, y se vio mirando la captura: la barra de
       navegación de las maquetas no se pinta en el portal de una sola
       página, así que `salir.php` existía sin que nada llevara a él.
       Alguien en un portátil compartido tiene que poder cerrar su
       cuenta sin buscar la URL. -->
  <p class="apunte" style="margin:-6px 0 18px">
    <a href="salir.php">Salir de mi cuenta</a>
  </p>

  <div class="nota ojo" id="reenviando" hidden>
    <p><strong>Tus documentos están volviendo.</strong> Cambiaste la
    contraseña, así que lo que había estaba cerrado con la llave
    anterior. No se ha perdido nada: los vuelvo a mandar y van
    apareciendo aquí.</p>
  </div>

  <!-- Tres estantes, no una lista.
       Lo pendiente arriba del todo porque es lo único que pide algo de
       quien entra; lo demás está para cuando lo busque. -->
  <!-- La gráfica. Va antes que los estantes porque es la respuesta a
       la pregunta con la que entra la mayoría: «¿voy mejor?». -->
  <div class="caja" id="c-progreso" hidden>
    <h2 style="margin-top:0">Mi progreso</h2>
    <div id="progreso"></div>
  </div>

  <div class="caja" id="c-tareas" hidden>
    <h2 style="margin-top:0">Pendiente de hacer</h2>
    <div id="tareas"></div>
  </div>

  <div class="caja">
    <h2 style="margin-top:0">Nuestras sesiones</h2>
    <div id="sesiones"></div>
  </div>

  <div class="caja">
    <h2 style="margin-top:0">Mis documentos</h2>
    <div id="documentos"></div>
  </div>

  <p>
    <button class="enlace-boton" id="refrescar" type="button">Actualizar</button>
    <span class="apunte" id="panel-estado" role="status"></span>
  </p>
</section>

<section id="v-doc" class="vista" hidden>
  <p class="etiqueta" id="doc-clase">Documento</p>
  <h1 id="doc-titulo">…</h1>
  <div class="caja"><div id="doc-cuerpo"></div></div>
  <p><button class="enlace-boton" id="doc-volver" type="button">← Volver</button></p>
</section>

<section id="v-tarea" class="vista" hidden>
  <p class="etiqueta">Cuestionario</p>
  <h1 id="t-titulo">…</h1>
  <p class="apunte" id="t-cabecera"></p>

  <div class="progreso-barra" role="progressbar" aria-label="Preguntas contestadas"
       aria-valuemin="0" aria-valuemax="1" aria-valuenow="0" id="t-barra">
    <i style="width:0%" id="t-barra-i"></i>
  </div>
  <p class="apunte" id="t-cuenta"></p>

  <!-- El bloque de crisis. Vive aquí en el marcado pero NO se enseña
       aquí: el guion lo mueve debajo del ítem que lo ha disparado.

       La primera versión de esto, en las maquetas, lo dejaba arriba del
       todo. La prueba automática decía que aparecía, y aparecía. Solo
       que la persona estaba abajo, en la pregunta nueve, y el aviso
       salía fuera de la pantalla. Una red de seguridad que no se ve no
       es una red de seguridad, y eso no lo dice ninguna aserción: se
       vio mirando la captura. -->
  <div class="auxilio" id="t-auxilio" role="status" hidden>
    <p><strong>Antes de seguir.</strong> Has marcado algo que quiero que
    sepas que voy a leer. Pero esto no es un canal de urgencias: lo leo
    cuando reviso, y puede que no sea hoy.</p>
    <p><strong>Si ahora mismo estás en peligro, llama al 024</strong>
    (atención a la conducta suicida, 24 horas, gratuito) <strong>o al
    112</strong>. Si puedes, díselo a alguien que tengas cerca.</p>
  </div>

  <form id="t-form">
    <div id="t-items"></div>
    <p style="margin-top:22px">
      <button class="boton" id="t-enviar" type="submit">Enviar</button>
      <button class="enlace-boton" id="t-luego" type="button">Seguir en otro momento</button>
    </p>
    <p class="apunte" id="t-estado" role="status">Lo que vas marcando se
    queda en este dispositivo hasta que envíes. No sale de aquí sin cifrar.</p>
  </form>
</section>
HTML;

$guion = adr_sodio() . <<<'HTML'
<script type="module">
import { listo, entrarConServidor, abrirDelMac, sellarHaciaElMac, vigilarSesion }
  from './cripto.js';

/* --- Lo que se queda aquí dentro y no sale ---------------------------
   `privada` es la clave del paciente. No se escribe en localStorage ni
   en sessionStorage ni en una cookie: vive en esta variable mientras
   la pestaña esté abierta y se borra al salir, al cerrarla y tras un
   rato sin tocar nada.

   Dicho lo que es, sin venderlo de más: no es una garantía fuerte.
   JavaScript puede haber dejado copias que no controlamos, y quien
   tenga el aparato desbloqueado tiene problemas mayores que éste.
   Reduce la ventana; no la cierra. */
let privada = null;
let miPublica = null;
let macPublica = null;
let miCod = null;

const $ = (id) => document.getElementById(id);
const VISTAS = ['v-entrar', 'v-panel', 'v-doc', 'v-tarea'];
function ver(cual) {
  VISTAS.forEach(v => { $(v).hidden = (v !== cual); });
  window.scrollTo(0, 0);
}
function falla(t) { $('mal-t').textContent = t; $('mal').hidden = false; }

async function api(a, d) {
  const o = d ? { method: 'POST', headers: { 'content-type': 'application/json' },
                  body: JSON.stringify(d) }
              : { method: 'GET' };
  const r = await fetch('api.php?a=' + a, o);
  const j = await r.json().catch(() => ({}));
  return { ok: r.ok, j };
}

/* --- Entrar ---------------------------------------------------------- */
$('f-entrar').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  $('mal').hidden = true;
  $('b-entrar').disabled = true;
  const correo = $('correo').value.trim();
  const clave = $('clave').value;

  try {
    await listo();

    /* La sal. El servidor SIEMPRE devuelve una, exista la cuenta o no:
       si contestara «ese correo no existe» estaría diciendo quién está
       en tratamiento. Cuando no existe es una sal falsa pero estable, y
       esto falla más abajo igual que una contraseña mala. Desde aquí no
       se distingue, que es justo el objetivo. */
    const s = await api('sal', { correo });
    if (!s.j.sal) { falla('No hemos podido conectar. Inténtalo otra vez.'); return; }

    /* Argon2id tarda: en un móvil de gama baja, medio segundo largo. Un
       botón que no dice nada durante medio segundo se pulsa otra vez. */
    $('estado').textContent = 'Comprobando…';

    /* Derivar, preguntar al servidor y desenvolver, en una llamada. La
       clave de envoltura no sale del módulo entre medias: tenerla aquí
       al lado de la de autenticación sería juntar lo que el servidor
       guarda con lo que abre el sobre, que es la trampa que este
       diseño existe para evitar. */
    privada = await entrarConServidor(clave, s.j.sal, async (auth) => {
      const e = await api('entrar', { correo, auth });
      if (!e.ok) throw new Error(e.j.error || 'no');
      return e.j.envuelta;
    });

    $('clave').value = '';

    /* vigilarSesion() borra la clave al salir, al cerrar la pestaña y
       tras un rato sin tocar nada. Recibe un GETTER, y el getter solo
       se llama en el momento de borrar — así que sirve también de
       aviso de que se ha borrado. Se aprovecha para recargar: una
       pantalla que sigue enseñando el panel con la clave ya borrada
       está mintiendo, y el siguiente clic fallaría sin explicación. */
    vigilarSesion(() => {
      const p = privada;
      privada = null;
      if (p) setTimeout(() => location.reload(), 0);
      return p;
    }, 15);

    await panel();

  } catch (e) {
    const m = String((e && e.message) || e);
    if (m === 'ENVOLTURA_NO_ABRE') {
      /* Es raro llegar aquí: el servidor ya dijo que la contraseña era
         buena. Si además falla la envoltura, no es culpa suya, y
         mandarla a «revisa tu contraseña» sería mandarla a arreglar lo
         que no está roto. */
      falla('Has entrado, pero no hemos podido abrir tu llave. Escríbeme y lo miro: no es culpa tuya y no has perdido nada.');
    } else {
      falla('No hemos podido entrar. Revisa el correo y la contraseña.');
    }
  } finally {
    $('estado').textContent = '';
    $('b-entrar').disabled = false;
  }
});

/* --- El panel, en tres estantes --------------------------------------- */
function fecha(iso) {
  return (iso || '').slice(0, 10).split('-').reverse().join('/');
}
function vacio(txt) {
  const d = document.createElement('div');
  d.className = 'vacio';
  const p = document.createElement('p');
  p.textContent = txt;
  d.appendChild(p);
  return d;
}
function fila(titulo, pie) {
  const f = document.createElement('div');
  f.className = 'fila';
  const t = document.createElement('div');
  t.className = 'texto';
  const s = document.createElement('strong');
  s.textContent = titulo;
  t.appendChild(s);
  if (pie) { const p = document.createElement('p'); p.textContent = pie; t.appendChild(p); }
  f.appendChild(t);
  return f;
}

async function panel() {
  /* Se piden los datos ANTES de cambiar de vista. Al revés —que es
     como estaba— el panel aparece un instante con el contenido de la
     vez anterior y luego se redibuja: se ve el parpadeo, y peor, un
     dedo rápido puede pulsar un botón que ya no existe. */
  $('panel-estado').textContent = '';
  const r = await api('mios');
  if (!r.ok) { location.reload(); return; }
  ver('v-panel');
  miPublica = r.j.publica || null;
  macPublica = r.j.mac_publica || null;
  miCod = r.j.cod || null;
  pintaProgreso(r.j.progreso, r.j.v);

  /* Pendiente */
  const ts = r.j.tareas || [];
  $('c-tareas').hidden = (ts.length === 0);
  const ct = $('tareas'); ct.innerHTML = '';
  ts.forEach(t => {
    const f = fila(t.titulo, t.caduca ? ('antes del ' + fecha(t.caduca)) : null);
    const b = document.createElement('button');
    b.className = 'boton'; b.type = 'button'; b.textContent = 'Rellenar';
    b.addEventListener('click', () => abrirTarea(t.id));
    f.appendChild(b);
    ct.appendChild(f);
  });

  /* Sesiones y documentos */
  const docs = r.j.documentos || [];
  let viejos = 0;
  [['sesion', 'sesiones', 'Aquí irá apareciendo lo que nos llevamos de cada sesión.'],
   ['documento', 'documentos', 'Todavía no hay documentos. Cuando te mande alguno, aparece aquí.']]
  .forEach(([clase, caja, cuandoNoHay]) => {
    const c = $(caja); c.innerHTML = '';
    const mios = docs.filter(d => (d.clase || 'documento') === clase);
    if (!mios.length) { c.appendChild(vacio(cuandoNoHay)); return; }
    mios.forEach(d => {
      const caduco = Number(d.para_v) !== Number(r.j.v);
      if (caduco) viejos++;
      const f = fila(d.titulo || 'Documento', fecha(d.creado));
      if (caduco) {
        /* Un botón que no abre es peor que no tener botón: la persona
           piensa que está roto y que ha perdido algo. */
        const e = document.createElement('span');
        e.className = 'sello caduca'; e.textContent = 'volviendo';
        f.appendChild(e);
      } else {
        const b = document.createElement('button');
        b.className = 'boton suave'; b.type = 'button'; b.textContent = 'Abrir';
        b.addEventListener('click', () => abrir(d.id, d.titulo, clase));
        f.appendChild(b);
      }
      c.appendChild(f);
    });
  });
  $('reenviando').hidden = (viejos === 0);
}

/* Actualizar SIN recargar. Recargar tiraría la clave de memoria y
   obligaría a escribir la contraseña otra vez — que es correcto, pero
   no por querer ver si ha llegado algo. */
$('refrescar').addEventListener('click', async () => {
  $('panel-estado').textContent = 'Mirando…';
  await panel();
  $('panel-estado').textContent = 'Al día.';
});

async function abrir(id, titulo, clase) {
  $('doc-clase').textContent = (clase === 'sesion') ? 'Sesión' : 'Documento';
  $('doc-titulo').textContent = titulo || 'Documento';
  $('doc-cuerpo').textContent = 'Abriendo…';
  ver('v-doc');
  const r = await api('abrir&id=' + encodeURIComponent(id));
  if (!r.ok || !r.j.cifrado) { $('doc-cuerpo').textContent = 'No se ha podido traer.'; return; }
  try {
    const d = abrirDelMac(r.j.cifrado, miPublica, privada);
    /* Se pinta como TEXTO, nunca como HTML. Lo que hay dentro lo ha
       escrito el Mac, pero «viene de mi propio sistema» es exactamente
       la frase con la que entran los agujeros. */
    $('doc-cuerpo').textContent =
      (typeof d === 'string') ? d : (d.texto || JSON.stringify(d, null, 1));
  } catch (_) {
    $('doc-cuerpo').textContent =
      'Este documento está cerrado con una llave anterior a tu contraseña actual. ' +
      'Lo vuelvo a mandar y reaparece aquí; no se ha perdido.';
  }
}
$('doc-volver').addEventListener('click', panel);

/* --- La gráfica -------------------------------------------------------
   La dibuja el navegador con lo que el Mac ha sellado. El servidor
   transporta un bloque que no puede leer: no sabe ni qué instrumento es
   ni qué puntuación tiene nadie.

   Decisiones, y cada una con su motivo:

   · UNA serie por gráfica, nunca dos instrumentos en los mismos ejes.
     Tienen escalas distintas y superponerlos es el error de gráfica más
     común que hay.
   · El eje empieza en CERO siempre. Recortarlo haría que dos puntos
     parecidos se vieran como un desplome o un milagro, y esto lo mira
     alguien que está mal.
   · Etiqueta en el primero y el último, y nada más. Un número encima de
     cada punto convierte la gráfica en una tabla fea.
   · Las cifras van también en texto debajo: para quien la lea con un
     lector de pantalla, para quien no distinga la línea, y porque los
     números exactos importan.
   · Y la frase de debajo NO RESTA: usa el cambio fiable del
     instrumento. Decir «has bajado dos» como si fuera un logro es
     prometer algo que el cuestionario no sostiene, y obliga a explicar
     un retroceso falso la semana que sube dos. */

const MESES = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun',
               'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
function dia(iso) {
  const p = (iso || '').slice(0, 10).split('-');
  return p.length === 3 ? (Number(p[2]) + ' ' + MESES[Number(p[1])]) : iso;
}

function svgSerie(m) {
  const NS = 'http://www.w3.org/2000/svg';
  const pts = m.puntos || [];
  if (!pts.length) return null;
  const An = 640, Al = 158, izq = 34, der = 58, arr = 18, aba = 28;
  const w = An - izq - der, h = Al - arr - aba, maxv = m.maximo || 27;
  const x = (i) => izq + (pts.length === 1 ? w : w * i / (pts.length - 1));
  const y = (v) => arr + h - (v / maxv) * h;

  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 ' + An + ' ' + Al);
  svg.setAttribute('width', '100%');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', m.instrumento + ': ' +
    pts.map(p => dia(p.fecha) + ' ' + p.valor).join(', '));

  const linea = (x1, y1, x2, y2, color, ancho, guion) => {
    const l = document.createElementNS(NS, 'line');
    l.setAttribute('x1', x1); l.setAttribute('y1', y1);
    l.setAttribute('x2', x2); l.setAttribute('y2', y2);
    l.setAttribute('stroke', color); l.setAttribute('stroke-width', ancho);
    if (guion) l.setAttribute('stroke-dasharray', guion);
    return l;
  };
  const texto = (tx, ty, t, tam, color, anclaje, peso) => {
    const e = document.createElementNS(NS, 'text');
    e.setAttribute('x', tx); e.setAttribute('y', ty);
    e.setAttribute('font-size', tam); e.setAttribute('fill', color);
    if (anclaje) e.setAttribute('text-anchor', anclaje);
    if (peso) e.setAttribute('font-weight', peso);
    e.textContent = t;
    return e;
  };

  svg.appendChild(linea(izq, arr + h, izq + w, arr + h, '#CECCC6', 1));
  svg.appendChild(texto(izq - 8, y(0) + 4, '0', 10, '#6C6963', 'end'));
  svg.appendChild(texto(izq - 8, y(maxv) + 4, String(maxv), 10, '#6C6963', 'end'));

  if (m.corte != null) {
    const yc = y(m.corte);
    svg.appendChild(linea(izq, yc, izq + w, yc, '#7B4D13', 1.5, '5 4'));
    svg.appendChild(texto(izq + w + 6, yc + 3.5, 'corte (' + m.corte + ')', 9.5, '#7B4D13'));
  }

  const d = pts.map((p, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p.valor).toFixed(1)).join(' ');
  const path = document.createElementNS(NS, 'path');
  path.setAttribute('d', d); path.setAttribute('fill', 'none');
  path.setAttribute('stroke', '#1F5474'); path.setAttribute('stroke-width', '2');
  path.setAttribute('stroke-linecap', 'round');
  path.setAttribute('stroke-linejoin', 'round');
  svg.appendChild(path);

  pts.forEach((p, i) => {
    const c = document.createElementNS(NS, 'circle');
    c.setAttribute('cx', x(i).toFixed(1)); c.setAttribute('cy', y(p.valor).toFixed(1));
    c.setAttribute('r', '4.5'); c.setAttribute('fill', '#1F5474');
    /* Anillo blanco para que el punto no se pegue a la línea de corte
       cuando la cruza. */
    c.setAttribute('stroke', '#FFFFFF'); c.setAttribute('stroke-width', '2');
    svg.appendChild(c);
    svg.appendChild(texto(x(i).toFixed(1), Al - 10, dia(p.fecha), 9.5, '#6C6963', 'middle'));
  });
  [0, pts.length - 1].forEach(i => {
    const p = pts[i], cy = y(p.valor);
    svg.appendChild(texto(x(i).toFixed(1), (cy > arr + 22 ? cy - 12 : cy + 19).toFixed(1),
                          String(p.valor), 12, '#25231F', 'middle', '700'));
  });
  return svg;
}

function lectura(m) {
  const pts = m.puntos || [];
  if (pts.length < 2) {
    return 'Es la primera medida, así que todavía no hay nada que comparar. ' +
           'Sirve como punto de partida.';
  }
  const a = pts[0].valor, z = pts[pts.length - 1].valor, dif = a - z;
  const cf = m.cambio_fiable;
  if (cf == null) {
    return 'De ' + a + ' a ' + z + '. Lo que dice una sola medida es poco; ' +
           'lo que importa es hacia dónde va la línea.';
  }
  if (Math.abs(dif) < cf) {
    return 'De ' + a + ' a ' + z + '. Es un cambio más pequeño que lo que este ' +
           'cuestionario puede medir con seguridad (hacen falta ' + cf + ' puntos), ' +
           'así que de momento se lee como «parecido», ni mejor ni peor.';
  }
  if (dif > 0) {
    return 'De ' + a + ' a ' + z + ': ' + dif + ' puntos menos, y eso ya pasa de lo ' +
           'que el cuestionario puede confundir con ruido (' + cf + ' puntos). ' +
           'Es un cambio real en lo que mide.';
  }
  return 'De ' + a + ' a ' + z + ': ha subido ' + Math.abs(dif) + ', y es más de lo ' +
         'que se explica por el propio cuestionario (' + cf + ' puntos). Lo hablamos.';
}

async function pintaProgreso(meta, v) {
  const caja = $('c-progreso');
  if (!meta) { caja.hidden = true; return; }
  caja.hidden = false;
  const dentro = $('progreso');
  dentro.textContent = 'Abriendo…';

  if (Number(meta.para_v) !== Number(v)) {
    dentro.textContent = 'Tu gráfica está cerrada con una llave anterior a tu ' +
      'contraseña actual. La vuelvo a mandar y reaparece aquí.';
    return;
  }
  const r = await api('abrir&id=' + encodeURIComponent(meta.id));
  if (!r.ok || !r.j.cifrado) { dentro.textContent = 'No se ha podido traer.'; return; }
  let d;
  try { d = abrirDelMac(r.j.cifrado, miPublica, privada); }
  catch (_) { dentro.textContent = 'No se ha podido abrir.'; return; }

  dentro.innerHTML = '';
  (d.series || []).forEach(m => {
    const fig = document.createElement('figure');
    fig.className = 'grafica';
    fig.style.margin = '0 0 18px';
    const cap = document.createElement('figcaption');
    cap.style.fontWeight = '700';
    cap.textContent = m.instrumento;
    fig.appendChild(cap);
    const g = svgSerie(m);
    if (g) fig.appendChild(g);
    const cifras = document.createElement('p');
    cifras.className = 'apunte';
    cifras.style.borderTop = '1px solid var(--line)';
    cifras.style.paddingTop = '6px';
    cifras.textContent = (m.puntos || []).map(p => dia(p.fecha) + ' ' + p.valor).join(' · ');
    fig.appendChild(cifras);
    const l = document.createElement('p');
    l.className = 'apunte';
    l.textContent = lectura(m);
    fig.appendChild(l);
    dentro.appendChild(fig);
  });

  const nota = document.createElement('p');
  nota.className = 'apunte';
  nota.textContent = 'Un cuestionario mide una parte de lo que te pasa, no todo, ' +
    'y no es una nota ni un diagnóstico. Está aquí porque ver la línea entre los ' +
    'dos ayuda a decidir qué hacemos a continuación.';
  dentro.appendChild(nota);
}

/* --- Rellenar un cuestionario ----------------------------------------
   Esta vista no sabe nada de ningún instrumento. Los ítems, las
   opciones y el bloque `riesgo {item, umbral, bandera}` vienen de la
   plantilla que sirve el servidor. Por eso el CORE-10 o cualquier
   instrumento nuevo entran añadiendo una plantilla, sin tocar esto. */
let TAREA = null;

async function abrirTarea(id) {
  const r = await api('tarea&id=' + encodeURIComponent(id));
  if (!r.ok) { await panel(); return; }
  TAREA = r.j;
  const P = TAREA.plantilla;
  $('t-titulo').textContent = TAREA.titulo;
  $('t-cabecera').textContent = P.cabecera ||
    'Si alguna no sabes contestarla, déjala en blanco y ya está.';
  /* Antes de nada, devolver el bloque de crisis a su sitio.
     -------------------------------------------------------------------
     Esto no es limpieza opcional: es un fallo que se comió la pantalla.
     Cuando salta, el bloque se MUEVE dentro de #t-items para quedar
     pegado al ítem que lo dispara. Si luego se vuelve a abrir el
     cuestionario, el `innerHTML = ''` de más abajo lo borraba con el
     resto — y a partir de ahí $('t-auxilio') era null y la vista
     reventaba en silencio: la persona pulsaba «Rellenar» y no pasaba
     nada.

     Lo encontró la prueba de «seguir en otro momento» y volver, que es
     exactamente lo que va a hacer alguien que deja un cuestionario a
     medias. */
  const aux = $('t-auxilio');
  aux.hidden = true;
  $('v-tarea').insertBefore(aux, $('t-form'));

  $('t-form').hidden = false;
  $('t-estado').textContent = 'Lo que vas marcando se queda en este ' +
    'dispositivo hasta que envíes. No sale de aquí sin cifrar.';

  const cont = $('t-items'); cont.innerHTML = '';
  P.items.forEach((texto, i) => {
    const n = i + 1;
    const caja = document.createElement('div');
    caja.className = 'item';
    const fs = document.createElement('fieldset');
    const lg = document.createElement('legend');
    lg.textContent = n + '. ' + texto;
    fs.appendChild(lg);
    const ops = document.createElement('div');
    ops.className = 'opciones';
    P.opciones.forEach(o => {
      const lb = document.createElement('label');
      lb.className = 'opcion';
      const inp = document.createElement('input');
      inp.type = 'radio'; inp.name = 'i' + n; inp.value = String(o[0]);
      const sp = document.createElement('span');
      sp.textContent = o[1];
      lb.appendChild(inp); lb.appendChild(sp);
      ops.appendChild(lb);
    });
    fs.appendChild(ops);
    caja.appendChild(fs);
    cont.appendChild(caja);
  });
  $('t-barra').setAttribute('aria-valuemax', String(P.items.length));
  recuperar();
  repintar();
  ver('v-tarea');
}

function respuestas() {
  const out = [];
  for (let n = 1; n <= TAREA.plantilla.items.length; n++) {
    const m = document.querySelector('input[name=i' + n + ']:checked');
    if (m) out.push({ item: n, valor: Number(m.value) });
  }
  return out;
}
function noContestados() {
  const hechas = new Set(respuestas().map(r => r.item));
  const out = [];
  for (let n = 1; n <= TAREA.plantilla.items.length; n++) if (!hechas.has(n)) out.push(n);
  return out;
}
function bloqueRiesgo() {
  const r = TAREA.plantilla.riesgo;
  if (!r) return null;
  const m = document.querySelector('input[name=i' + r.item + ']:checked');
  if (!m) return null;
  const v = Number(m.value);
  return { item: r.item, valor: v, umbral: r.umbral,
           superado: v >= r.umbral, bandera: r.bandera };
}

function repintar() {
  const hechas = respuestas().length;
  const total = TAREA.plantilla.items.length;
  $('t-barra').setAttribute('aria-valuenow', String(hechas));
  $('t-barra-i').style.width = (hechas / total * 100) + '%';
  $('t-cuenta').textContent = hechas + ' de ' + total + ' contestadas';

  /* La aparición del bloque de crisis ocurre entera aquí dentro. No
     hay petición, no hay baliza, no se entera nadie más. La señal le
     llega a Adrián dentro del sobre, cifrada, como todo lo demás. */
  const r = bloqueRiesgo();
  const toca = !!(r && r.superado);
  const aux = $('t-auxilio');
  if (toca && aux.hidden) {
    const suyo = document.querySelector(
      'input[name=i' + TAREA.plantilla.riesgo.item + ']').closest('.item');
    suyo.insertAdjacentElement('afterend', aux);
  }
  aux.hidden = !toca;
}

/* Lo contestado a medias se queda en ESTE dispositivo y sin cifrar,
   porque no sale. Mandar un borrador al servidor sería contenido
   clínico en claro, que es justo lo que este diseño existe para
   evitar. Se borra al entregar. */
function laClave() { return 'adr.parcial.' + TAREA.id; }
function guardar() {
  try { localStorage.setItem(laClave(), JSON.stringify(respuestas())); } catch (_) {}
}
function recuperar() {
  try {
    const s = localStorage.getItem(laClave());
    if (!s) return;
    JSON.parse(s).forEach(r => {
      const el = document.querySelector(
        'input[name=i' + r.item + '][value="' + r.valor + '"]');
      if (el) el.checked = true;
    });
  } catch (_) {}
}

$('t-items').addEventListener('change', () => { guardar(); repintar(); });
$('t-luego').addEventListener('click', () => { guardar(); panel(); });

$('t-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  $('t-enviar').disabled = true;
  const P = TAREA.plantilla;
  const d = new Date(), dd = (n) => (n < 10 ? '0' : '') + n;
  let off = -d.getTimezoneOffset();
  const signo = off >= 0 ? '+' : '-';
  off = Math.abs(off);
  /* Hora local con el desfase escrito, no UTC: «las once de la noche»
     y «las nueve de la mañana» dicen cosas distintas de una persona, y
     convertir a UTC las confunde según el mes. */
  const momento = d.getFullYear() + '-' + dd(d.getMonth() + 1) + '-' + dd(d.getDate()) +
    'T' + dd(d.getHours()) + ':' + dd(d.getMinutes()) + ':' + dd(d.getSeconds()) +
    signo + dd(Math.floor(off / 60)) + ':' + dd(off % 60);

  const sobre = {
    v: 1,
    tipo: 'instrumento_estandarizado',
    instrumento: P.instrumento || TAREA.titulo,
    version_items: P.version_items || null,
    completado_en: momento,
    respuestas: respuestas(),
    no_contestados: noContestados(),
    riesgo: bloqueRiesgo(),
    tarea: TAREA.id,
    /* El código va DENTRO del sobre. El servidor sabe de quién es esta
       entrega, pero el Mac que la descifra no — y sin esto no sabría en
       qué historia archivarla. Es lo mismo que hace la hoja suelta de
       /h/ con el fragmento de la URL: un solo formato de sobre para los
       dos caminos. */
    cod: miCod,
    origen: 'cuenta',
  };

  try {
    /* Sellado a la clave del Mac. El servidor transporta un bloque que
       no puede abrir: lo que guarda es ruido para él. */
    const cifrado = sellarHaciaElMac(sobre, macPublica);
    const r = await api('entregar', { id: TAREA.id, cifrado });
    if (!r.ok) { $('t-estado').textContent = 'No se ha podido enviar. Inténtalo otra vez.'; return; }
    try { localStorage.removeItem(laClave()); } catch (_) {}
    $('t-form').hidden = true;
    $('t-estado').textContent = '';
    await panel();
    $('panel-estado').textContent = 'Enviado. Gracias.';
  } finally {
    $('t-enviar').disabled = false;
  }
});

/* La pública del Mac llega dentro de `mios`, que ya se pide para
   pintar el panel. Una petición menos, y no hay forma de estar en la
   pantalla de un cuestionario sin haber pasado por el panel. */
</script>
HTML;

adr_pagina('Entrar | adricamente', $cuerpo, $guion);
