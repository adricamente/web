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

  <!-- Herramientas: lo que se rellena o se lee y se GUARDA (plan de
       seguridad, autorregistros, guías). No son pruebas: no puntúan ni
       salen en la gráfica, y lo enviado se queda aquí para volver a
       abrirlo, que es justo cuando hace falta un plan de seguridad. -->
  <div class="caja" id="c-herramientas" hidden>
    <h2 style="margin-top:0">Mis herramientas</h2>
    <div id="herramientas"></div>
  </div>

  <div class="caja">
    <h2 style="margin-top:0">Nuestras sesiones</h2>
    <div id="sesiones"></div>
  </div>

  <div class="caja">
    <h2 style="margin-top:0">Mis documentos</h2>
    <div id="documentos"></div>
  </div>

  <!-- Los mensajes van los ÚLTIMOS a propósito. Es lo que más engancha
       y lo que menos urge; arriba haría que lo primero de cada visita
       fuera mirar si hay respuesta, y esto no es una aplicación de
       mensajería. -->
  <div class="caja">
    <h2 style="margin-top:0">Escribirme</h2>
    <div class="nota ojo" style="margin-bottom:14px">
      <p><strong>Esto no es un canal de urgencias.</strong> Lo leo cuando
      reviso, y puede que no sea hoy. Si estás en peligro ahora mismo,
      llama al <strong>024</strong> (atención a la conducta suicida, 24
      horas, gratuito) o al <strong>112</strong>.</p>
    </div>
    <div id="hilo"></div>
    <div class="campo" style="margin-top:14px">
      <label for="m-texto">Lo que quieras contarme</label>
      <textarea id="m-texto" rows="4"></textarea>
    </div>
    <p><button class="boton" id="m-enviar" type="button">Enviar</button>
    <span class="apunte" id="m-estado" role="status"></span></p>
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

<section id="v-deber" class="vista" hidden>
  <p class="etiqueta">Tarea</p>
  <h1 id="d-titulo">…</h1>
  <div class="caja"><div id="d-cuerpo"></div></div>
  <p>
    <button class="boton" id="d-hecho" type="button">Marcar como hecha</button>
    <button class="enlace-boton" id="d-volver" type="button">← Volver</button>
  </p>
</section>

<section id="v-firmar" class="vista" hidden>
  <p class="etiqueta">Documento para firmar</p>
  <h1 id="s-titulo">…</h1>
  <div class="caja"><div id="s-cuerpo"></div></div>
  <div class="caja">
    <p class="apunte">Para firmarlo, escribe tu nombre y apellidos tal
    como aparecen en tu DNI. Queda registrada la fecha y la hora.</p>
    <div class="campo">
      <label for="s-nombre">Nombre y apellidos</label>
      <input id="s-nombre" autocomplete="name">
    </div>
    <p><button class="boton" id="s-firmar" type="button">Firmar</button>
    <button class="enlace-boton" id="s-volver" type="button">← Volver</button></p>
    <p class="apunte" id="s-estado" role="status"></p>
  </div>
</section>

<section id="v-tarea" class="vista" hidden>
  <p class="etiqueta">Cuestionario</p>
  <h1 id="t-titulo">…</h1>
  <p class="apunte" id="t-instrucciones" hidden></p>
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
    <div id="t-extra"></div>
    <p style="margin-top:22px">
      <button class="boton" id="t-enviar" type="submit">Enviar</button>
      <button class="enlace-boton" id="t-luego" type="button">Seguir en otro momento</button>
    </p>
    <p class="apunte" id="t-estado" role="status">Lo que vas marcando se
    queda en este dispositivo hasta que envíes. No sale de aquí sin cifrar.</p>
  </form>
</section>

<section id="v-herr" class="vista" hidden>
  <p class="etiqueta" id="h-etiqueta">Herramienta</p>
  <h1 id="h-titulo">…</h1>
  <p class="apunte" id="h-subtitulo"></p>
  <!-- Con riesgo (el plan de seguridad), el 024 y el 112 van arriba y
       abajo de TODA la pantalla, y también en el PDF. -->
  <div class="auxilio h-riesgo" hidden>
    <p><strong>Si ahora mismo no puedes mantenerte a salvo, llama al
    024</strong> (atención a la conducta suicida, 24 horas, gratuito)
    <strong>o al 112</strong>, o ve a urgencias.</p>
  </div>
  <form id="h-form" autocomplete="off">
    <div id="h-cuerpo"></div>
    <p style="margin-top:22px" id="h-botones">
      <button class="boton" id="h-enviar" type="submit">Enviar</button>
      <button class="boton suave" id="h-leido" type="button" hidden>Ya lo he leído</button>
      <button class="boton suave" id="h-pdf" type="button">Descargar en PDF</button>
      <button class="enlace-boton" id="h-volver" type="button">← Volver</button>
    </p>
    <p class="apunte" id="h-estado" role="status"></p>
  </form>
  <div class="auxilio h-riesgo" hidden>
    <p><strong>024</strong> · atención a la conducta suicida, 24 horas,
    gratuito. <strong>112</strong> · emergencias.</p>
  </div>
</section>

<!-- Lo que se imprime: una copia estática, con lo escrito como texto.
     Las casillas de un formulario se imprimen mal (se cortan, salen
     vacías en algunos navegadores); esto no. -->
<div id="h-imprimir" class="solo-impresion" aria-hidden="true"></div>
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
const VISTAS = ['v-entrar', 'v-panel', 'v-doc', 'v-tarea', 'v-deber', 'v-firmar', 'v-herr'];
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

  /* Pendiente (las herramientas van en su propia caja) */
  const todas = r.j.tareas || [];
  const ts = todas.filter(t => t.tipo !== 'herramienta');
  pintaHerramientas(todas.filter(t => t.tipo === 'herramienta'),
                    (r.j.documentos || []).filter(d => d.clase === 'herramienta'), r.j.v);
  $('c-tareas').hidden = (ts.length === 0);
  const ct = $('tareas'); ct.innerHTML = '';
  ts.forEach(t => {
    const esDeber = (t.tipo === 'deber');
    const f = fila(t.titulo, t.caduca ? ('antes del ' + fecha(t.caduca)) : null);
    const b = document.createElement('button');
    b.className = 'boton'; b.type = 'button';
    b.textContent = esDeber ? 'Ver' : 'Rellenar';
    b.addEventListener('click', () => esDeber ? abrirDeber(t) : abrirTarea(t.id));
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
      } else if (Number(d.requiere_firma) && !d.firmado) {
        /* Pendiente de firma: el botón lo dice y lleva a la pantalla de
           firmar, no a la de leer. Un documento que hay que firmar y se
           abre como cualquier otro se lee y se cierra. */
        const e = document.createElement('span');
        e.className = 'sello ojo'; e.textContent = 'sin firmar';
        f.appendChild(e);
        const b = document.createElement('button');
        b.className = 'boton'; b.type = 'button'; b.textContent = 'Leer y firmar';
        b.addEventListener('click', () => abrirFirma(d.id, d.titulo));
        f.appendChild(b);
      } else {
        if (d.firmado) {
          const e = document.createElement('span');
          e.className = 'sello ok'; e.textContent = 'firmado';
          e.title = 'Firmado el ' + fecha(d.firmado);
          f.appendChild(e);
        }
        const b = document.createElement('button');
        b.className = 'boton suave'; b.type = 'button'; b.textContent = 'Abrir';
        b.addEventListener('click', () => abrir(d.id, d.titulo, clase));
        f.appendChild(b);
      }
      c.appendChild(f);
    });
  });
  $('reenviando').hidden = (viejos === 0);
  pintaHilo(r.j.mensajes || [], r.j.v);
}

/* --- Los mensajes ----------------------------------------------------
   Los suyos se descifran aquí; los que él ha escrito no se pueden
   volver a leer —fueron sellados a la clave del Mac y esa privada no
   está aquí—, así que se marcan como enviados y punto. Eso es una
   consecuencia del diseño, no una carencia: si su navegador pudiera
   releerlos, el sobre no estaría sellado de verdad. */
async function pintaHilo(ms, v) {
  const caja = $('hilo');
  caja.innerHTML = '';
  if (!ms.length) {
    caja.appendChild(vacio('Todavía no hay mensajes.'));
    return;
  }
  for (const m of ms) {
    const d = document.createElement('div');
    d.className = 'fila';
    const t = document.createElement('div');
    t.className = 'texto';
    const quien = document.createElement('strong');
    quien.textContent = (Number(m.direccion) === 2) ? 'Adrián' : 'Tú';
    const cuerpo = document.createElement('p');
    cuerpo.style.whiteSpace = 'pre-wrap';
    cuerpo.style.color = 'var(--ink)';
    cuerpo.style.fontSize = '15.5px';
    if (Number(m.direccion) === 1) {
      cuerpo.textContent = '(enviado el ' + fecha(m.creado) + ')';
      cuerpo.style.color = 'var(--muted)';
    } else if (Number(m.para_v) !== Number(v)) {
      cuerpo.textContent = 'Este mensaje está cerrado con una llave anterior. Te lo reenvío.';
      cuerpo.style.color = 'var(--muted)';
    } else {
      const r = await api('abrir&id=' + encodeURIComponent(m.id));
      try { const x = abrirDelMac(r.j.cifrado, miPublica, privada);
            cuerpo.textContent = (typeof x === 'string') ? x : (x.texto || ''); }
      catch (_) { cuerpo.textContent = 'No se ha podido abrir.'; }
    }
    const f = document.createElement('p');
    f.textContent = fecha(m.creado);
    t.appendChild(quien); t.appendChild(cuerpo); t.appendChild(f);
    d.appendChild(t);
    caja.appendChild(d);
  }
}

$('m-enviar').addEventListener('click', async () => {
  const texto = $('m-texto').value.trim();
  if (!texto) return;
  $('m-enviar').disabled = true;
  $('m-estado').textContent = '';
  try {
    const cifrado = sellarHaciaElMac({ v: 1, tipo: 'mensaje', texto,
                                       escrito_en: ahoraLocal(), origen: 'cuenta',
                                       cod_web: miCod },
                                     macPublica);
    const r = await api('escribir', { cifrado });
    if (!r.ok) { $('m-estado').textContent = 'No se ha podido enviar.'; return; }
    $('m-texto').value = '';
    $('m-estado').textContent = 'Enviado.';
    await panel();
  } finally { $('m-enviar').disabled = false; }
});

/* --- Los deberes ------------------------------------------------------ */
let DEBER = null;
async function abrirDeber(t) {
  DEBER = t;
  $('d-titulo').textContent = t.titulo;
  $('d-cuerpo').textContent = 'Abriendo…';
  ver('v-deber');
  const r = await api('tarea&id=' + encodeURIComponent(t.id));
  if (!r.ok) { $('d-cuerpo').textContent = 'No se ha podido traer.'; return; }
  try {
    const x = abrirDelMac(r.j.cifrado, miPublica, privada);
    $('d-cuerpo').textContent = (typeof x === 'string') ? x : (x.texto || '');
  } catch (_) {
    $('d-cuerpo').textContent = 'Esta tarea está cerrada con una llave anterior a tu ' +
      'contraseña actual. Te la vuelvo a mandar.';
  }
}
$('d-volver').addEventListener('click', panel);
$('d-hecho').addEventListener('click', async () => {
  $('d-hecho').disabled = true;
  try { await api('hecho', { id: DEBER.id }); await panel(); }
  finally { $('d-hecho').disabled = false; }
});

/* --- Firmar ---------------------------------------------------------- */
let FIRMA = null;
async function abrirFirma(id, titulo) {
  FIRMA = { id, titulo };
  $('s-titulo').textContent = titulo || 'Documento';
  $('s-cuerpo').textContent = 'Abriendo…';
  $('s-estado').textContent = '';
  $('s-nombre').value = '';
  ver('v-firmar');
  const r = await api('abrir&id=' + encodeURIComponent(id));
  if (!r.ok || !r.j.cifrado) { $('s-cuerpo').textContent = 'No se ha podido traer.'; return; }
  try {
    const d = abrirDelMac(r.j.cifrado, miPublica, privada);
    $('s-cuerpo').textContent = (typeof d === 'string') ? d : (d.texto || '');
  } catch (_) { $('s-cuerpo').textContent = 'No se ha podido abrir.'; }
}
$('s-volver').addEventListener('click', panel);
$('s-firmar').addEventListener('click', async () => {
  const nombre = $('s-nombre').value.trim();
  if (nombre.length < 5) {
    $('s-estado').textContent = 'Escribe tu nombre y apellidos completos.';
    return;
  }
  $('s-firmar').disabled = true;
  try {
    /* La firma va sellada al Mac: él tiene la prueba completa de qué
       nombre se escribió y cuándo. El servidor solo apunta que se
       firmó y la hora, que es lo que puede saber sin leer nada. */
    const cifrado = sellarHaciaElMac({ v: 1, tipo: 'firma', documento: FIRMA.id,
                                       titulo: FIRMA.titulo, nombre,
                                       firmado_en: ahoraLocal(), origen: 'cuenta',
                                       cod_web: miCod },
                                     macPublica);
    const r = await api('firmar', { id: FIRMA.id, cifrado });
    if (!r.ok) { $('s-estado').textContent = r.j.error || 'No se ha podido firmar.'; return; }
    await panel();
    $('panel-estado').textContent = 'Firmado. Gracias.';
  } finally { $('s-firmar').disabled = false; }
});

/* Hora local con el desfase escrito, no UTC: «las once de la noche» y
   «las nueve de la mañana» dicen cosas distintas de una persona, y
   convertir a UTC las confunde según el mes. */
function ahoraLocal() {
  const d = new Date(), dd = (n) => (n < 10 ? '0' : '') + n;
  let off = -d.getTimezoneOffset();
  const signo = off >= 0 ? '+' : '-';
  off = Math.abs(off);
  return d.getFullYear() + '-' + dd(d.getMonth() + 1) + '-' + dd(d.getDate()) +
    'T' + dd(d.getHours()) + ':' + dd(d.getMinutes()) + ':' + dd(d.getSeconds()) +
    signo + dd(Math.floor(off / 60)) + ':' + dd(off % 60);
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
   · El eje empieza en el MÍNIMO DE LA ESCALA (0 casi siempre; 10 en
     el Rosenberg, 28 en el DERS-28), nunca en el mínimo de los datos.
     Recortarlo a los datos haría que dos puntos parecidos se vieran
     como un desplome o un milagro, y esto lo mira alguien que está mal.
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
  /* El DERS-28 va de 28 a 140 y el Rosenberg de 10 a 40. Dibujarlos
     desde 0 aplasta la línea arriba y deja media gráfica vacía. */
  const minv = m.minimo || 0, rango = (maxv - minv) || 1;
  const x = (i) => izq + (pts.length === 1 ? w : w * i / (pts.length - 1));
  const y = (v) => arr + h - ((v - minv) / rango) * h;

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
  svg.appendChild(texto(izq - 8, y(minv) + 4, String(minv), 10, '#6C6963', 'end'));
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
  const a = pts[0].valor, z = pts[pts.length - 1].valor;
  /* En casi todos, bajar es mejorar. En el Rosenberg o el WHO-5 es al
     revés, y leerlos con la misma frase le diría a alguien que ha
     empeorado justo la semana que mejora. */
  const alReves = m.direccion === 'mas_es_mejor';
  const dif = alReves ? z - a : a - z;
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
  const cuanto = Math.abs(dif) + ' puntos ' + (z < a ? 'menos' : 'más');
  if (dif > 0) {
    return 'De ' + a + ' a ' + z + ': ' + cuanto + ', y eso ya pasa de lo ' +
           'que el cuestionario puede confundir con ruido (' + cf + ' puntos). ' +
           'Es un cambio real, y en la buena dirección.';
  }
  return 'De ' + a + ' a ' + z + ': ' + cuanto + ', y es más de lo ' +
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
  /* Las instrucciones del instrumento (cuando las trae) y la frase que
     encabeza los ítems. Una cabecera de una o dos palabras («Ítem») es
     el rótulo de una columna del papel, no una frase: no se enseña. Y
     si el primer bloque la repite, tampoco. */
  $('t-instrucciones').hidden = !P.instrucciones;
  $('t-instrucciones').textContent = P.instrucciones || '';
  const cab = (P.cabecera || '').trim();
  const repetida = P.bloques && P.bloques[0] && P.bloques[0].titulo === cab;
  $('t-cabecera').textContent = (cab.split(/\s+/).length >= 3 && !repetida) ? cab
    : (P.instrucciones ? '' : 'Si alguna no sabes contestarla, déjala en blanco y ya está.');
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
  const inicioBloque = {};
  (P.bloques || []).forEach(b => { inicioBloque[Math.min(...b.items)] = b.titulo; });
  P.items.forEach((texto, i) => {
    const n = i + 1;
    if (inicioBloque[n]) {
      const h = document.createElement('h2');
      h.className = 'bloque-titulo';
      h.textContent = inicioBloque[n];
      cont.appendChild(h);
    }
    const caja = document.createElement('div');
    caja.className = 'item';
    const fs = document.createElement('fieldset');
    const lg = document.createElement('legend');
    lg.textContent = n + '. ' + texto;
    fs.appendChild(lg);
    const ops = document.createElement('div');
    ops.className = 'opciones';
    /* AUDIT, OASIS, ODSIS: cada pregunta tiene sus propias respuestas.
       Si el ítem trae las suyas, mandan; si no, las comunes. */
    const suyas = (P.opciones_por_item && P.opciones_por_item[i]) || P.opciones;
    suyas.forEach(o => {
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
  /* Lo que acompaña y NO puntúa: la pregunta de dificultad del PHQ-9,
     la experiencia de referencia del PCL-5. Va en el sobre en `extra`,
     nunca en `respuestas`, y no cuenta en la barra. */
  const ex = $('t-extra'); ex.innerHTML = '';
  const X = P.extra || {};
  if (X.pregunta_funcional) {
    const caja = document.createElement('div');
    caja.className = 'item';
    const fs = document.createElement('fieldset');
    const lg = document.createElement('legend');
    lg.textContent = X.pregunta_funcional.texto;
    fs.appendChild(lg);
    const ops = document.createElement('div');
    ops.className = 'opciones';
    X.pregunta_funcional.opciones.forEach((t, k) => {
      const lb = document.createElement('label');
      lb.className = 'opcion';
      const inp = document.createElement('input');
      inp.type = 'radio'; inp.name = 'pf'; inp.value = String(k);
      const sp = document.createElement('span'); sp.textContent = t;
      lb.appendChild(inp); lb.appendChild(sp); ops.appendChild(lb);
    });
    fs.appendChild(ops); caja.appendChild(fs); ex.appendChild(caja);
  }
  if (X.experiencia_referencia) {
    const caja = document.createElement('div');
    caja.className = 'campo';
    const lb = document.createElement('label');
    lb.setAttribute('for', 't-er'); lb.textContent = X.experiencia_referencia.texto;
    const ta = document.createElement('textarea');
    ta.id = 't-er'; ta.rows = 3; ta.maxLength = 1000;
    caja.appendChild(lb); caja.appendChild(ta); ex.appendChild(caja);
  }

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
  const P = TAREA.plantilla;
  /* Hay pruebas que solo se pueden corregir enteras (el DES-II es una
     media; el PSST, un algoritmo). Ahí no se deja enviar con huecos, y
     se dice cuáles faltan. */
  const faltan = noContestados();
  if (P.obligatorio && faltan.length) {
    $('t-estado').textContent = 'En este cuestionario hacen falta todas las respuestas. ' +
      'Te falta' + (faltan.length > 1 ? 'n las preguntas ' : ' la pregunta ') + faltan.join(', ') + '.';
    const primera = document.querySelector('input[name=i' + faltan[0] + ']');
    if (primera) primera.closest('.item').scrollIntoView({ block: 'center' });
    return;
  }
  $('t-enviar').disabled = true;
  const momento = ahoraLocal();

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
    cod_web: miCod,
    origen: 'cuenta',
  };
  if (P.extra) {
    const pf = document.querySelector('input[name=pf]:checked');
    const er = $('t-er');
    sobre.extra = {
      pregunta_funcional: pf ? Number(pf.value) : null,
      experiencia_referencia: er && er.value.trim() ? er.value.trim() : null,
    };
  }

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

/* --- Herramientas ------------------------------------------------------
   Plan de seguridad, autorregistros, hojas de trabajo, guías. Las
   define el sistema clínico como BLOQUES y CAMPOS; esto solo las pinta.
   Los `id` de los campos son el contrato con el Mac: se calculan aquí
   exactamente igual que en la consola, que comprueba que cuadran antes
   de mandar nada.

   Lo que se envía va sellado al Mac. Y una copia sellada a TU clave se
   queda en «Mis herramientas»: un plan de seguridad que desaparece al
   enviarlo no sirve el día que hace falta. */
let HERR = null;   // {tarea, def, soloLeer}

function pintaHerramientas(pendientes, copias, v) {
  const c = $('herramientas'); c.innerHTML = '';
  $('c-herramientas').hidden = !(pendientes.length || copias.length);
  pendientes.forEach(t => {
    const f = fila(t.titulo, t.caduca ? ('antes del ' + fecha(t.caduca)) : 'Pendiente');
    const b = document.createElement('button');
    b.className = 'boton'; b.type = 'button'; b.textContent = 'Abrir';
    b.addEventListener('click', () => abrirHerramienta(t.id));
    f.appendChild(b); c.appendChild(f);
  });
  copias.forEach(d => {
    const f = fila(d.titulo || 'Herramienta', 'Enviada el ' + fecha(d.creado));
    if (Number(d.para_v) !== Number(v)) {
      /* Se cerró con la llave de la contraseña anterior. Esta copia no
         se puede reabrir; el Mac tiene la suya y me la puedes pedir. */
      const e = document.createElement('span');
      e.className = 'sello caduca'; e.textContent = 'pídemela';
      e.title = 'Se guardó con tu contraseña anterior. Escríbeme y te la vuelvo a mandar.';
      f.appendChild(e);
    } else {
      const b = document.createElement('button');
      b.className = 'boton suave'; b.type = 'button'; b.textContent = 'Abrir';
      b.addEventListener('click', () => abrirCopia(d.id));
      f.appendChild(b);
    }
    c.appendChild(f);
  });
}

/* Los id de campo de una tabla: `{tabla}.{fila}.{columna}`. Las filas
   fijas van primero (1, 2…) y las libres siguen la numeración. */
function idsTabla(b) {
  const fijas = b.fijas || [], filas = [];
  fijas.forEach((fila, i) => filas.push({ n: i + 1, fija: fila }));
  for (let i = 0; i < (b.filas || 0); i++) filas.push({ n: fijas.length + i + 1, fija: null });
  return filas;
}

function el(tag, clase, texto) {
  const e = document.createElement(tag);
  if (clase) e.className = clase;
  if (texto != null) e.textContent = texto;
  return e;
}
function parrafo(b, clase) {
  const p = el('p', clase);
  if (b.negrita) p.appendChild(el('strong', null, b.negrita));
  p.appendChild(document.createTextNode(b.texto || ''));
  return p;
}

/* Pinta los bloques. modo 'form' = casillas; modo 'texto' = lo escrito,
   como texto (para leer una copia enviada y para imprimir). */
function pintaBloques(def, valores, modo, desactivar) {
  const raiz = el('div', 'herr' + (def.apaisado ? ' apaisado' : ''));
  const casilla = (id, rotulo, multilinea, lineas, bloqueada) => {
    const v = valores[id] || '';
    if (modo === 'texto') {
      const d = el('div', 'valor' + (multilinea ? ' multi' : ''), v);
      if (multilinea) d.style.minHeight = (1.6 * (lineas || 2)) + 'em';
      return d;
    }
    const c = multilinea ? document.createElement('textarea') : document.createElement('input');
    if (multilinea) c.rows = lineas || 3; else c.type = 'text';
    c.dataset.campo = id;
    c.value = v;
    c.maxLength = multilinea ? 4000 : 300;
    if (rotulo) c.setAttribute('aria-label', rotulo);
    if (desactivar || bloqueada) c.disabled = true;
    return c;
  };
  (def.bloques || []).forEach(b => {
    if (b.t === 'campos') {
      const fila = el('div', 'campos-fila');
      b.campos.forEach(k => {
        const caja = el('label', 'campo-h');
        caja.style.flex = (k.ancho || 6) + ' 1 ' + Math.max(8, (k.ancho || 6) * 1.6) + 'rem';
        caja.appendChild(el('span', 'rotulo', k.rotulo));
        caja.appendChild(casilla(k.id, k.rotulo, false));
        fila.appendChild(caja);
      });
      raiz.appendChild(fila);
    } else if (b.t === 'nota') {
      raiz.appendChild(parrafo(b, 'nota-h'));
    } else if (b.t === 'advertencia') {
      const d = el('div', 'auxilio'); d.appendChild(parrafo(b)); raiz.appendChild(d);
    } else if (b.t === 'texto') {
      raiz.appendChild(parrafo(b));
    } else if (b.t === 'titulo') {
      const h = el('h2', 'titulo-h' + (b.salto ? ' salto' : ''), b.texto);
      raiz.appendChild(h);
      if (b.sub) raiz.appendChild(el('p', 'apunte', b.sub));
    } else if (b.t === 'apartado') {
      raiz.appendChild(el('h3', 'apartado-h', b.titulo));
      if (b.ayuda) raiz.appendChild(el('p', 'apunte', b.ayuda));
      if (b.id) raiz.appendChild(casilla(b.id, b.titulo, true, b.lineas));
    } else if (b.t === 'lista') {
      if (b.titulo) raiz.appendChild(el('h3', 'apartado-h', b.titulo));
      const ul = el('ul');
      (b.items || []).forEach(t => ul.appendChild(el('li', null, t)));
      raiz.appendChild(ul);
    } else if (b.t === 'tabla' || b.t === 'tabla_lectura') {
      const lectura = b.t === 'tabla_lectura';
      if (b.titulo) raiz.appendChild(el('h3', 'apartado-h', b.titulo));
      const envoltura = el('div', 'tabla-h');
      const tab = el('table');
      const thead = el('thead'), trh = el('tr');
      const cols = lectura ? b.columnas.map(c => ({ rotulo: c[0], ancho: c[1] })) : b.columnas;
      cols.forEach(c => { const th = el('th', null, c.rotulo); th.style.width = (c.ancho || 4) + 'em'; trh.appendChild(th); });
      thead.appendChild(trh); tab.appendChild(thead);
      const tb = el('tbody');
      if (lectura) {
        (b.filas || []).forEach(f => {
          const tr = el('tr');
          f.forEach((x, j) => { const td = el('td', null, x); td.dataset.rotulo = cols[j].rotulo; tr.appendChild(td); });
          tb.appendChild(tr);
        });
      } else {
        if (b.ejemplo) {
          const tr = el('tr', 'ejemplo');
          b.ejemplo.forEach((x, i) => {
            const td = el('td', null, (i === 0 ? 'Ejemplo: ' : '') + x);
            td.dataset.rotulo = b.columnas[i].rotulo; tr.appendChild(td);
          });
          tb.appendChild(tr);
        }
        idsTabla(b).forEach(({ n, fija }) => {
          const tr = el('tr');
          b.columnas.forEach((c, j) => {
            const td = el('td');
            td.dataset.rotulo = c.rotulo;
            if (fija && fija[j] != null) td.textContent = fija[j];
            else td.appendChild(casilla(b.id + '.' + n + '.' + c.id, c.rotulo, (b.alto || 0) > 1.2, 2));
            tr.appendChild(td);
          });
          tb.appendChild(tr);
        });
      }
      tab.appendChild(tb); envoltura.appendChild(tab); raiz.appendChild(envoltura);
    } else if (b.t === 'firma') {
      const fila = el('div', 'firma-h');
      [['paciente', 'Paciente'], ['profesional', 'Psicólogo']].forEach(([w, nombre]) => {
        const col = el('div', 'firma-col');
        col.appendChild(el('h3', 'apartado-h', nombre));
        [['nombre', 'Nombre'], ['fecha', 'Fecha'], ['firma', 'Firma']].forEach(([q, r]) => {
          const caja = el('label', 'campo-h');
          caja.appendChild(el('span', 'rotulo', r));
          /* Lo del psicólogo no lo rellena el paciente: lo firmo yo. */
          caja.appendChild(casilla('firma.' + q + '.' + w, r + ' (' + nombre + ')', false, 0, w === 'profesional'));
          col.appendChild(caja);
        });
        fila.appendChild(col);
      });
      raiz.appendChild(fila);
    } else if (b.t === 'fuente') {
      raiz.appendChild(el('p', 'fuente-h', b.texto));
    }
  });
  return raiz;
}

function camposDelForm() {
  const out = {};
  (HERR.def.campos || []).forEach(c => { out[c.id] = ''; });
  document.querySelectorAll('#h-cuerpo [data-campo]').forEach(c => { out[c.dataset.campo] = c.value; });
  return out;
}
function claveHerr() { return 'adr.herr.' + HERR.tarea; }

function montaHerramienta(def, valores, soloLeer, etiqueta) {
  $('h-etiqueta').textContent = etiqueta;
  $('h-titulo').textContent = def.titulo;
  $('h-subtitulo').textContent = def.subtitulo || '';
  document.querySelectorAll('.h-riesgo').forEach(x => { x.hidden = !def.riesgo; });
  const cuerpo = $('h-cuerpo'); cuerpo.innerHTML = '';
  cuerpo.appendChild(pintaBloques(def, valores, soloLeer ? 'texto' : 'form', false));
  const lectura = def.tipo === 'lectura';
  $('h-enviar').hidden = soloLeer || lectura;
  $('h-leido').hidden = !(lectura && !soloLeer);
  $('h-estado').textContent = (soloLeer || lectura) ? '' :
    'Lo que escribes se queda en este dispositivo hasta que envíes. No sale de aquí sin cifrar.';
  ver('v-herr');
}

async function abrirHerramienta(id) {
  const r = await api('tarea&id=' + encodeURIComponent(id));
  if (!r.ok || r.j.tipo !== 'herramienta') { await panel(); return; }
  HERR = { tarea: r.j.id, def: r.j.plantilla, soloLeer: false };
  let borrador = {};
  try { borrador = JSON.parse(localStorage.getItem(claveHerr()) || '{}'); } catch (_) {}
  montaHerramienta(HERR.def, borrador, false, 'Herramienta');
}

async function abrirCopia(id) {
  const r = await api('abrir&id=' + encodeURIComponent(id));
  if (!r.ok || !r.j.cifrado) return;
  let d;
  try { d = abrirDelMac(r.j.cifrado, miPublica, privada); } catch (_) { return; }
  HERR = { tarea: null, def: d.def, soloLeer: true, valores: d.campos || {} };
  montaHerramienta(d.def, d.campos || {}, true,
    'Enviada el ' + fecha(d.completado_en));
}

$('h-cuerpo').addEventListener('input', () => {
  if (!HERR || HERR.soloLeer || !HERR.tarea) return;
  try { localStorage.setItem(claveHerr(), JSON.stringify(camposDelForm())); } catch (_) {}
});

$('h-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  if (!HERR || HERR.soloLeer) return;
  $('h-enviar').disabled = true;
  try {
    const campos = camposDelForm();
    const momento = ahoraLocal();
    /* El sobre, exactamente con la forma acordada con el sistema
       clínico: sin `instrumento` ni `respuestas`. */
    const sobre = { herramienta: HERR.def.id, campos, completado_en: momento,
                    origen: 'cuenta', cod_web: miCod };
    const cifrado = sellarHaciaElMac(sobre, macPublica);
    const copia = sellarHaciaElMac({ def: HERR.def, campos, completado_en: momento }, miPublica);
    const r = await api('entregar', { id: HERR.tarea, cifrado, copia });
    if (!r.ok) { $('h-estado').textContent = 'No se ha podido enviar. Inténtalo otra vez.'; return; }
    try { localStorage.removeItem(claveHerr()); } catch (_) {}
    const def = HERR.def;
    HERR = { tarea: null, def, soloLeer: true, valores: campos };
    montaHerramienta(def, campos, true, 'Enviada');
    $('h-estado').textContent = 'Enviada. Te queda una copia en «Mis herramientas». ' +
      'Si quieres tenerla también en papel o en el móvil, descárgala en PDF.';
  } finally {
    $('h-enviar').disabled = false;
  }
});

$('h-leido').addEventListener('click', async () => {
  if (!HERR || !HERR.tarea) return;
  await api('hecho', { id: HERR.tarea });
  await panel();
});

/* PDF: se imprime una copia estática con lo escrito, y el navegador la
   guarda como PDF («Guardar como PDF» en el diálogo de imprimir). Sin
   librerías de fuera, que esta página no carga ninguna. */
$('h-pdf').addEventListener('click', () => {
  if (!HERR) return;
  const valores = HERR.soloLeer ? (HERR.valores || {}) : camposDelForm();
  const cont = $('h-imprimir'); cont.innerHTML = '';
  /* Hijo directo de <body>, para que al imprimir se pueda esconder todo
     lo demás sin esconderla a ella. */
  if (cont.parentNode !== document.body) document.body.appendChild(cont);
  cont.appendChild(el('h1', null, HERR.def.titulo));
  if (HERR.def.subtitulo) cont.appendChild(el('p', 'apunte', HERR.def.subtitulo));
  if (HERR.def.riesgo) {
    const a = el('div', 'auxilio');
    a.appendChild(el('p', null, 'Si no puedes mantenerte a salvo: 024 (24 horas, gratuito) o 112.'));
    cont.appendChild(a);
  }
  cont.appendChild(pintaBloques(HERR.def, valores, 'texto', true));
  let estilo = document.getElementById('h-pagina');
  if (!estilo) { estilo = document.createElement('style'); estilo.id = 'h-pagina'; document.head.appendChild(estilo); }
  estilo.textContent = '@page { size: A4 ' + (HERR.def.apaisado ? 'landscape' : 'portrait') + '; margin: 14mm; }';
  window.print();
});

$('h-volver').addEventListener('click', panel);
</script>
HTML;

adr_pagina('Entrar | adricamente', $cuerpo, $guion);
