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
<!-- La ayuda urgente, siempre a mano en cualquier pantalla de la
     cuenta. Si tiene plan de seguridad enviado, el plan va delante. -->
<p class="plan-fijo" id="plan-fijo" hidden>
  <button type="button" id="b-plan" hidden>Mi plan de seguridad</button>
  <span class="urgente"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>¿En peligro ahora? <a href="tel:024">024</a> o <a href="tel:112">112</a></span>
</p>

<!-- El inicio, de arriba abajo en el orden en que se usa: la próxima
     sesión, lo que hay que hacer antes, cómo va, las herramientas, lo
     trabajado, y al final mensajes, documentos y ajustes. Móvil
     primero: en una pantalla de 390 px, lo de arriba es lo que se ve. -->
<section id="v-panel" class="vista" hidden>
  <!-- Cuatro apartados, uno a la vez, como una aplicación: Inicio,
       Tareas, Evolución y Mensajes. No son páginas distintas (una
       página nueva tiraría la llave de la memoria): son pestañas, con
       su dirección propia (#tareas…), así que el botón de atrás del
       móvil funciona entre ellas. En el móvil van abajo; en el
       ordenador, arriba. -->
  <nav class="pestanas" id="pestanas" aria-label="Apartados de tu cuenta">
    <a href="#inicio" data-p="inicio"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg><span>Inicio</span></a>
    <a href="#tareas" data-p="tareas"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11M4 6l1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2"/></svg><span>Tareas</span><b class="globo" id="globo-tareas" hidden></b></a>
    <a href="#evolucion" data-p="evolucion"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16M5 15l4-4 3 3 6-7"/></svg><span>Evolución</span></a>
    <a href="#mensajes" data-p="mensajes"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H8l-4 4z"/></svg><span>Mensajes</span></a>
  </nav>

  <div class="nota ojo" id="reenviando" hidden>
    <p><strong>Tus documentos están volviendo.</strong> Cambiaste la
    contraseña, así que lo que había estaba cerrado con la llave
    anterior. No se ha perdido nada: los vuelvo a mandar y van
    apareciendo aquí.</p>
  </div>
  <div class="nota bien" id="recibido" role="status" hidden>
    <p><strong>Recibido.</strong> Lo tendré delante en tu próxima sesión.</p>
  </div>

  <!-- ============================ INICIO ============================ -->
  <div class="pestana" id="p-inicio" data-p="inicio">
    <h1 id="hola">Tu espacio</h1>

    <!-- La próxima sesión. La publica el Mac (clase «agenda»): día, hora
         y el enlace de la videollamada, que se activa 10 minutos antes. -->
    <div class="tarjeta-sesion" id="c-agenda" hidden>
      <p class="etiqueta">Tu próxima sesión</p>
      <p class="cuando" id="ag-cuando"></p>
      <p class="hora" id="ag-hora"></p>
      <p class="botones">
        <a class="boton" id="ag-entrar" target="_blank" rel="noopener">Entrar a la videollamada</a>
        <a class="enlace-boton" id="ag-cambiar" target="_blank" rel="noopener" hidden>Cambiar o cancelar</a>
      </p>
      <p class="apunte" id="ag-nota"></p>
    </div>

    <a class="tarjeta-ir" href="#tareas" id="ir-tareas">
      <span class="t">Lo que te toca</span>
      <span class="resumen" id="resumen" role="status"></span>
      <svg class="flecha" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
    </a>

    <a class="tarjeta-ir" href="#evolucion" id="ir-evolucion">
      <span class="t">Tu evolución</span>
      <span class="resumen" id="resumen-evolucion">Aquí verás cómo vas cuando hayas rellenado tus cuestionarios.</span>
      <svg class="flecha" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
    </a>

    <section class="caja bloque" aria-labelledby="t-sesiones">
      <h2 id="t-sesiones">Lo que hemos trabajado</h2>
      <div id="sesiones" class="linea-tiempo"></div>
    </section>

    <section class="caja bloque" aria-labelledby="t-docs">
      <h2 id="t-docs">Documentos</h2>
      <div id="documentos"></div>
    </section>

    <details class="caja" id="c-accesos">
      <summary><strong>Ajustes y seguridad</strong></summary>
      <p class="apunte">Las últimas entradas en tu cuenta. Si ves una que no
      reconoces, cambia la contraseña y escríbeme.</p>
      <ul id="accesos" class="accesos"></ul>
      <p style="margin-top:14px"><a href="clave.php?olvide=1">Cambiar la contraseña</a></p>
    </details>
  </div>

  <!-- ============================ TAREAS ============================ -->
  <div class="pestana" id="p-tareas" data-p="tareas" hidden>
    <h1>Tareas</h1>
    <section class="caja bloque" id="c-tareas" aria-labelledby="t-tareas">
      <h2 id="t-tareas">Para antes de tu sesión</h2>
      <p class="apunte" id="resumen-tareas"></p>
      <div class="progreso-barra" id="hecho-barra" hidden role="progressbar"
           aria-label="Lo que has hecho de lo que te he mandado estas semanas">
        <i id="hecho-barra-i" style="width:0%"></i>
      </div>
      <div id="tareas"></div>
    </section>

    <!-- Herramientas: lo que se rellena o se lee y se GUARDA (plan de
         seguridad, autorregistros, guías). No son pruebas: no puntúan ni
         salen en la gráfica, y lo enviado se queda aquí para volver a
         abrirlo, que es justo cuando hace falta un plan de seguridad. -->
    <section class="caja bloque" id="c-herramientas" aria-labelledby="t-herr">
      <h2 id="t-herr">Tus herramientas</h2>
      <div id="herramientas"></div>
    </section>

    <section class="caja bloque" id="c-hechas" aria-labelledby="t-hechas">
      <h2 id="t-hechas">Lo que has completado</h2>
      <div id="hechas"></div>
    </section>
  </div>

  <!-- =========================== EVOLUCIÓN ========================== -->
  <div class="pestana" id="p-evolucion" data-p="evolucion" hidden>
    <h1>Tu evolución</h1>
    <p class="apunte">Lo que dicen tus cuestionarios con el tiempo. Cada
    gráfica es uno, con su escala; no se comparan entre sí.</p>
    <section class="caja bloque" id="c-progreso" aria-label="Tus gráficas">
      <div id="progreso"></div>
    </section>
  </div>

  <!-- =========================== MENSAJES =========================== -->
  <div class="pestana" id="p-mensajes" data-p="mensajes" hidden>
    <h1>Mensajes</h1>
    <section class="caja bloque" id="c-mensajes" aria-label="Escribirme">
      <div id="hilo" class="hilo" aria-live="polite"></div>
      <div class="campo escribir">
        <label for="m-texto">Lo que quieras contarme</label>
        <p class="pista" id="m-pista"><strong>Este canal no es para urgencias.</strong> Lo leo en mi
        horario de consulta, no al momento. Si estás en peligro ahora:
        <strong>024</strong> (atención a la conducta suicida, 24 horas,
        gratuito) o <strong>112</strong>.</p>
        <textarea id="m-texto" rows="4" aria-describedby="m-pista"></textarea>
      </div>
      <!-- Si lo que escribe suena a riesgo, esto aparece ANTES de enviar.
           No bloquea: se puede enviar igual. -->
      <div class="auxilio" id="m-auxilio" role="status" hidden>
        <p><strong>Antes de enviarlo.</strong> Lo voy a leer, pero no al
        momento: puede que no sea hoy.</p>
        <p><strong>Si ahora mismo estás en peligro, llama al 024</strong>
        (atención a la conducta suicida, 24 horas, gratuito) <strong>o al
        112</strong>. Si puedes, díselo a alguien que tengas cerca.</p>
      </div>
      <p><button class="boton" id="m-enviar" type="button">Enviar</button>
      <span class="apunte" id="m-estado" role="status"></span></p>
    </section>
  </div>

  <!-- Actualizar y salir, en todos los apartados: alguien en un
       portátil compartido tiene que poder cerrar su cuenta desde donde
       esté. -->
  <p class="pie-panel">
    <button class="enlace-boton" id="refrescar" type="button">Actualizar</button>
    <span class="apunte" id="panel-estado" role="status"></span>
    <a class="salir" href="salir.php">Salir de mi cuenta</a>
  </p>
</section>

<section id="v-doc" class="vista" hidden>
  <p class="etiqueta" id="doc-clase">Documento</p>
  <h1 id="doc-titulo">…</h1>
  <div class="caja doc-caja"><div id="doc-cuerpo"></div>
    <div class="doc-firma" id="doc-firma" hidden>
      <p id="doc-firma-t"></p>
      <p class="apunte" id="doc-firma-h"></p>
    </div>
  </div>
  <p><button class="boton suave" id="doc-imprimir" type="button" hidden>Guardar o imprimir una copia</button></p>
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
      <input type="text" id="s-nombre" autocomplete="name">
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
    <!-- El aviso de derechos que exige la licencia de algunos
         instrumentos (el CORE-10: CC BY-NC-ND). Lo trae la plantilla;
         sin él, ese cuestionario no se puede usar. -->
    <p class="apunte copyright" id="t-copyright" hidden></p>
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
let miV = null;
let macPublica = null;
let miCod = null;

const $ = (id) => document.getElementById(id);
const VISTAS = ['v-entrar', 'v-panel', 'v-doc', 'v-tarea', 'v-deber', 'v-firmar', 'v-herr'];
let PLAN = null;   // id de su plan de seguridad enviado, si tiene
function ver(cual) {
  VISTAS.forEach(v => { $(v).hidden = (v !== cual); });
  /* La ayuda urgente está en todas las pantallas de la cuenta; el
     botón del plan, solo si hay plan. */
  $('plan-fijo').hidden = (cual === 'v-entrar');
  $('b-plan').hidden = !PLAN;
  window.scrollTo(0, 0);
}
function falla(t) { $('mal-t').textContent = t; $('mal').hidden = false; }

/* --- Los cuatro apartados ----------------------------------------------
   Pestañas con dirección propia (#inicio, #tareas, #evolucion,
   #mensajes). Cambiar de una a otra no recarga nada —la llave sigue en
   memoria— y el «atrás» del móvil vuelve a la anterior. */
const PESTANAS = ['inicio', 'tareas', 'evolucion', 'mensajes'];
function pestana(p) {
  if (!PESTANAS.includes(p)) p = 'inicio';
  document.querySelectorAll('.pestana').forEach(x => { x.hidden = (x.dataset.p !== p); });
  document.querySelectorAll('#pestanas a').forEach(a => {
    if (a.dataset.p === p) a.setAttribute('aria-current', 'page');
    else a.removeAttribute('aria-current');
  });
}
window.addEventListener('hashchange', () => {
  if ($('v-panel').hidden) return;
  pestana(location.hash.slice(1));
  window.scrollTo(0, 0);
});

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
/* Las fechas, como se dicen: «6 oct», y el año solo si no es este.
   «06/10/2026» en un sitio y «6 oct» en la gráfica de al lado eran dos
   idiomas para lo mismo. */
function fecha(iso) {
  const p = (iso || '').slice(0, 10).split('-').map(Number);
  if (p.length !== 3 || !p[0] || !p[1] || !p[2]) return iso || '';
  const M = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
  return p[2] + ' ' + M[p[1] - 1] + (p[0] !== new Date().getFullYear() ? ' ' + p[0] : '');
}
function hora(iso) { const m = /T(\d{2}:\d{2})/.exec(iso || ''); return m ? m[1] : ''; }
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
  pestana(location.hash.slice(1));
  miPublica = r.j.publica || null;
  macPublica = r.j.mac_publica || null;
  miCod = r.j.cod || null;
  miV = r.j.v;
  pintaAgenda(r.j.agenda, r.j.v);
  pintaProgreso(r.j.progreso, r.j.v);

  /* Lo caducado no se enseña ni se cuenta: un número que no baja
     solo genera culpa. El servidor ya no lo manda; esto es la red. */
  const hoy = new Date().toLocaleDateString('sv', {timeZone: 'Europe/Madrid'});
  const todas = (r.j.tareas || []).filter(t => !t.caduca || String(t.caduca).slice(0, 10) >= hoy);
  const docs = r.j.documentos || [];
  buscaPlan(docs.filter(d => d.clase === 'herramienta'), r.j.v);
  pintaHerramientas(todas.filter(t => t.tipo === 'herramienta'),
                    docs.filter(d => d.clase === 'herramienta'), r.j.v);

  /* «Para antes de tu sesión»: lo pendiente, con cuánto lleva, y el
     documento por firmar si lo hay. Lo de las herramientas va en su
     caja; aquí se cuenta igual. */
  const ts = todas.filter(t => t.tipo !== 'herramienta');
  const porFirmar = docs.filter(d => Number(d.requiere_firma) && !d.firmado
                                     && Number(d.para_v) === Number(r.j.v));
  const n = todas.length + porFirmar.length;
  $('resumen').textContent = n
    ? (n === 1 ? 'Tienes una cosa pendiente.' : 'Tienes ' + n + ' cosas pendientes.')
    : 'No tienes nada pendiente.';
  $('resumen-tareas').textContent = n
    ? 'Empieza por arriba. Cada cosa dice cuánto lleva.'
    : 'No tienes nada pendiente. Cuando te mande algo, aparecerá aquí.';
  $('globo-tareas').hidden = !n;
  $('globo-tareas').textContent = n ? String(n) : '';
  const ct = $('tareas'); ct.innerHTML = '';
  porFirmar.forEach(d => {
    const f = fila(d.titulo || 'Documento', 'Para leer y firmar');
    f.classList.add('firmar');
    const b = document.createElement('button');
    b.className = 'boton'; b.type = 'button'; b.textContent = 'Leer y firmar';
    b.addEventListener('click', () => abrirFirma(d.id, d.titulo));
    f.appendChild(b);
    ct.appendChild(f);
  });
  ts.forEach(t => {
    const esDeber = (t.tipo === 'deber');
    const pie = [t.preguntas ? 'unos ' + Math.max(1, Math.round(t.preguntas * 10 / 60)) + ' min' : null,
                 t.caduca ? 'antes del ' + fecha(t.caduca) : null].filter(Boolean).join(' · ');
    const f = fila(t.titulo, pie || null);
    const b = document.createElement('button');
    b.className = 'boton'; b.type = 'button';
    b.textContent = esDeber ? 'Ver' : 'Rellenar';
    b.addEventListener('click', () => esDeber ? abrirDeber(t) : abrirTarea(t.id));
    f.appendChild(b);
    ct.appendChild(f);
  });
  /* La barra: lo hecho de lo que se ha mandado en las dos últimas
     semanas. Sin rachas ni medallas: una barra y ya. */
  const hechas = r.j.hechas || [];
  const hace14 = new Date(Date.now() - 14 * 864e5).toISOString().slice(0, 10);
  const recientes = hechas.filter(h => (h.hecho || '').slice(0, 10) >= hace14).length;
  const total = recientes + todas.length;
  $('hecho-barra').hidden = !(recientes && total);
  if (recientes && total) {
    $('hecho-barra-i').style.width = Math.round(100 * recientes / total) + '%';
    $('hecho-barra').setAttribute('aria-valuenow', String(recientes));
    $('hecho-barra').setAttribute('aria-valuemax', String(total));
  }
  pintaHechas(hechas);

  /* Lo trabajado (sesiones) y los documentos */
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
          /* Junto a la fecha, no entre el título y el botón: en un móvil
             empujaba «Abrir» a otra línea y cada fila salía distinta. */
          const pie = f.querySelector('.texto p');
          if (pie) pie.append(' ', e); else f.appendChild(e);
        }
        const b = document.createElement('button');
        b.className = 'boton suave'; b.type = 'button'; b.textContent = 'Abrir';
        b.addEventListener('click', () => abrir(d.id, d.titulo, clase, d));
        f.appendChild(b);
      }
      c.appendChild(f);
    });
  });
  $('reenviando').hidden = (viejos === 0);
  pintaHilo(r.j.mensajes || [], r.j.v);
}

/* Lo completado: qué y cuándo, sin puntuación. La puntuación está en
   «Tu evolución»; aquí se ve que lo que mandas llega a algún sitio. */
function pintaHechas(hs) {
  const c = $('hechas'); c.innerHTML = '';
  if (!hs.length) {
    c.appendChild(vacio('Aquí irá quedando lo que vayas haciendo, con su fecha.'));
    return;
  }
  hs.slice(0, 12).forEach(h => c.appendChild(fila(h.titulo, 'Hecho el ' + fecha(h.hecho))));
}

/* --- La próxima sesión ------------------------------------------------
   La publica el Mac sellada: {proxima: ISO, meet: url, cambiar?: url,
   nombre?: «Marta»}. El botón de la videollamada se enciende 10 minutos
   antes y se apaga una hora después de empezar. Solo se aceptan
   enlaces https: lo que se pinta como botón no puede ser otra cosa. */
const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
const MESES_L = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
let AGENDA = null, RELOJ_AG = null;
function esHttps(u) { try { return new URL(u).protocol === 'https:'; } catch (_) { return false; } }
async function pintaAgenda(meta, v) {
  const caja = $('c-agenda');
  if (RELOJ_AG) { clearInterval(RELOJ_AG); RELOJ_AG = null; }
  AGENDA = null;
  caja.hidden = true;
  $('hola').textContent = 'Tu espacio';
  if (!meta || Number(meta.para_v) !== Number(v)) return;
  const r = await api('abrir&id=' + encodeURIComponent(meta.id));
  if (!r.ok || !r.j.cifrado) return;
  let d;
  try { d = abrirDelMac(r.j.cifrado, miPublica, privada); } catch (_) { return; }
  if (d && typeof d.nombre === 'string' && d.nombre.trim()) {
    $('hola').textContent = 'Hola, ' + d.nombre.trim().split(/\s+/)[0];
  }
  const t = d && d.proxima ? new Date(d.proxima) : null;
  if (!t || isNaN(t) || t.getTime() < Date.now() - 60 * 60000) return;
  AGENDA = d;
  /* El día, en grande; la hora, debajo. «jueves» con mayúscula porque
     abre la línea. */
  const diaS = DIAS[t.getDay()];
  $('ag-cuando').textContent = diaS.charAt(0).toUpperCase() + diaS.slice(1) + ' ' + t.getDate() + ' de ' + MESES_L[t.getMonth()];
  $('ag-hora').textContent = 'A las ' + String(t.getHours()).padStart(2, '0') + ':' +
    String(t.getMinutes()).padStart(2, '0') + ', por videollamada.';
  const cambiar = $('ag-cambiar');
  cambiar.hidden = !esHttps(d.cambiar);
  if (!cambiar.hidden) cambiar.href = d.cambiar;
  const reloj = () => {
    const ahora = Date.now(), abre = t.getTime() - 10 * 60000, cierra = t.getTime() + 60 * 60000;
    const b = $('ag-entrar');
    const listo = esHttps(d.meet) && ahora >= abre && ahora <= cierra;
    if (listo) { b.href = d.meet; b.removeAttribute('aria-disabled'); b.classList.remove('apagado'); }
    else { b.removeAttribute('href'); b.setAttribute('aria-disabled', 'true'); b.classList.add('apagado'); }
    $('ag-nota').textContent = listo ? 'Ya puedes entrar. Te espero.'
      : (ahora < abre ? 'El botón se activa 10 minutos antes.' : '');
  };
  reloj();
  RELOJ_AG = setInterval(reloj, 30000);
  caja.hidden = false;
}

/* --- Los mensajes ----------------------------------------------------
   Como una conversación: en orden, lo último abajo, junto a donde se
   escribe; lo de Adrián a la izquierda y lo tuyo a la derecha.

   Lo que escribe la persona va sellado a la clave del Mac, y eso su
   navegador no lo puede volver a abrir. Por eso, al enviar, se sella
   además una copia a SU clave (como la del consentimiento firmado): el
   servidor guarda dos bloques que no puede leer, y ella puede releer
   lo que escribió. Los mensajes de antes de esta copia salen como
   «enviado», sin texto. */
async function abreMio(m, v) {
  if (!m.copia_id || Number(m.copia_v) !== Number(v)) return null;
  const r = await api('abrir&id=' + encodeURIComponent(m.copia_id));
  if (!r.ok || !r.j.cifrado) return null;
  try { const x = abrirDelMac(r.j.cifrado, miPublica, privada); return (x && x.texto) || null; }
  catch (_) { return null; }
}
async function pintaHilo(ms, v) {
  const caja = $('hilo');
  caja.innerHTML = '';
  if (!ms.length) {
    caja.appendChild(vacio('Todavía no hay mensajes. Lo que me escribas aquí lo leo antes de tu próxima sesión.'));
    return;
  }
  /* El servidor ya los da en orden (del primero al último). */
  for (const m of ms) {
    const mio = Number(m.direccion) === 1;
    const d = document.createElement('div');
    d.className = 'burbuja' + (mio ? ' mia' : '');
    const quien = document.createElement('p');
    quien.className = 'quien';
    quien.textContent = mio ? 'Tú' : 'Adrián';
    const cuerpo = document.createElement('p');
    cuerpo.className = 'cuerpo';
    if (mio) {
      const t = await abreMio(m, v);
      if (t) cuerpo.textContent = t;
      else {
        cuerpo.textContent = m.copia_id
          ? 'Mensaje enviado. Se guardó con tu contraseña anterior y ya no se puede releer aquí.'
          : 'Mensaje enviado. Este no se guardó para releerlo aquí.';
        d.classList.add('cerrada');
      }
    } else if (Number(m.para_v) !== Number(v)) {
      cuerpo.textContent = 'Este mensaje está cerrado con una llave anterior. Te lo reenvío.';
      d.classList.add('cerrada');
    } else {
      const r = await api('abrir&id=' + encodeURIComponent(m.id));
      try { const x = abrirDelMac(r.j.cifrado, miPublica, privada);
            cuerpo.textContent = (typeof x === 'string') ? x : (x.texto || ''); }
      catch (_) { cuerpo.textContent = 'No se ha podido abrir.'; d.classList.add('cerrada'); }
    }
    const f = document.createElement('p');
    f.className = 'cuando';
    f.textContent = fecha(m.creado) + (hora(m.creado) ? ', ' + hora(m.creado) : '');
    d.append(quien, cuerpo, f);
    caja.appendChild(d);
  }
}

/* La red de seguridad del mensaje. Si lo que escribe suena a riesgo, la
   misma caja que en el ítem 9 aparece ANTES de enviar, sin bloquearlo.
   Es, carácter por carácter, `_RIESGO_MENSAJE` de ingesta_web.py en el
   Mac, que la vuelve a pasar al recibir y avisa con «⚠️ RIESGO». Si se
   cambia, se cambia en los dos sitios a la vez.
   - Banderas `iu`: sin `u`, `i` no siempre iguala «Á» con «á».
   - NFC antes de mirar: un iPhone puede mandar la tilde como carácter
     aparte (NFD) y entonces `[aá]` no casa.
   - En JS `\b` solo conoce letras ASCII: no ponerlo pegado a una vocal
     con tilde ni a la ñ si se añaden palabras. */
const RIESGO_MENSAJE = new RegExp(String.raw`suicid|mat[aá]rme|\bmorir(me|se)?\b|\bmuerte\b|quitarme la vida|acabar con (todo|mi vida|esto)|no quiero (vivir|seguir|estar aqu[ií]|despertar)|no despertar(me)?\b|desaparecer|(hacerme|me hago|me hice) da[ñn]o|autolesi|cortarme|me corto|lesionarme|despedirme|no aguanto m[aá]s|ser una carga|mejor sin m[ií]|tirarme (por|de|a)\b|tomarme (todas )?las pastillas|\b024\b|\b112\b`, 'iu');
function suenaARiesgo(t) { return RIESGO_MENSAJE.test(String(t).normalize('NFC')); }
$('m-texto').addEventListener('input', () => {
  $('m-auxilio').hidden = !suenaARiesgo($('m-texto').value);
});

$('m-enviar').addEventListener('click', async () => {
  const texto = $('m-texto').value.trim();
  if (!texto) return;
  if (suenaARiesgo(texto)) $('m-auxilio').hidden = false;
  $('m-enviar').disabled = true;
  $('m-estado').textContent = '';
  try {
    const cifrado = sellarHaciaElMac({ v: 1, tipo: 'mensaje', texto,
                                       escrito_en: ahoraLocal(), origen: 'cuenta',
                                       cod_web: miCod },
                                     macPublica);
    /* Y una copia a su propia clave, para poder releerlo. */
    const copia = sellarHaciaElMac({ v: 1, tipo: 'mi_mensaje', texto, escrito_en: ahoraLocal() }, miPublica);
    const r = await api('escribir', { cifrado, copia });
    if (!r.ok) { $('m-estado').textContent = 'No se ha podido enviar.'; return; }
    $('m-texto').value = '';
    $('m-estado').textContent = 'Enviado. Lo leeré en mi horario de consulta.';
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
    pintaTexto($('d-cuerpo'), (typeof x === 'string') ? x : (x.texto || ''));
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
  FIRMA = { id, titulo, texto: null };
  $('s-firmar').disabled = true;
  $('s-titulo').textContent = titulo || 'Documento';
  $('s-cuerpo').textContent = 'Abriendo…';
  $('s-estado').textContent = '';
  $('s-nombre').value = '';
  ver('v-firmar');
  const r = await api('abrir&id=' + encodeURIComponent(id));
  if (!r.ok || !r.j.cifrado) { $('s-cuerpo').textContent = 'No se ha podido traer.'; return; }
  try {
    const d = abrirDelMac(r.j.cifrado, miPublica, privada);
    FIRMA.texto = (typeof d === 'string') ? d : (d.texto || '');
    /* Se PINTA estructurado; FIRMA.texto, que es lo que se firma y se
       hashea, se queda exactamente como llegó. */
    pintaTexto($('s-cuerpo'), FIRMA.texto);
    /* Solo se puede firmar lo que se ha podido leer. */
    $('s-firmar').disabled = !FIRMA.texto;
  } catch (_) { $('s-cuerpo').textContent = 'No se ha podido abrir.'; }
}
$('s-volver').addEventListener('click', panel);
$('s-firmar').addEventListener('click', async () => {
  const nombre = $('s-nombre').value.trim();
  if (nombre.length < 5) {
    $('s-estado').textContent = 'Escribe tu nombre y apellidos completos.';
    return;
  }
  if (!FIRMA.texto) return;
  $('s-firmar').disabled = true;
  try {
    /* La huella del texto EXACTO que tenía delante: así la firma dice
       qué se firmó, no solo cuándo. */
    const bytes = new TextEncoder().encode(FIRMA.texto);
    const h = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)))
      .map(b => b.toString(16).padStart(2, '0')).join('');
    /* La firma va sellada al Mac: él tiene la prueba completa de qué
       nombre se escribió y cuándo. El servidor solo apunta que se
       firmó y la hora, que es lo que puede saber sin leer nada. */
    const cifrado = sellarHaciaElMac({ v: 1, tipo: 'firma', documento: FIRMA.id,
                                       titulo: FIRMA.titulo, documento_sha256: h, nombre,
                                       firmado_en: ahoraLocal(), origen: 'cuenta',
                                       cod_web: miCod },
                                     macPublica);
    /* Y su copia: el texto, su nombre, la hora y la huella, sellados a
       SU clave. Se queda en «Documentos», legible y para imprimir. */
    const copia = sellarHaciaElMac({ v: 1, tipo: 'copia_firmada', titulo: FIRMA.titulo,
                                     texto: FIRMA.texto,
                                     firma: { nombre, firmado_en: ahoraLocal(), documento_sha256: h } },
                                   miPublica);
    const r = await api('firmar', { id: FIRMA.id, cifrado, copia });
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

async function abrir(id, titulo, clase, doc) {
  $('doc-clase').textContent = (clase === 'sesion') ? 'Sesión' : 'Documento';
  $('doc-titulo').textContent = titulo || 'Documento';
  $('doc-cuerpo').textContent = 'Abriendo…';
  $('doc-firma').hidden = true;
  $('doc-imprimir').hidden = true;
  ver('v-doc');
  /* Un documento firmado se abre por su copia firmada, si la hay: el
     mismo texto, y debajo quién firmó, cuándo y la huella. */
  const usaCopia = doc && doc.copia_id && Number(doc.copia_v) === Number(miV);
  const r = await api('abrir&id=' + encodeURIComponent(usaCopia ? doc.copia_id : id));
  if (!r.ok || !r.j.cifrado) { $('doc-cuerpo').textContent = 'No se ha podido traer.'; return; }
  try {
    const d = abrirDelMac(r.j.cifrado, miPublica, privada);
    if (d && typeof d.pdf === 'string') { pintaPdf(d); return; }
    /* Se pinta como TEXTO, nunca como HTML. Lo que hay dentro lo ha
       escrito el Mac, pero «viene de mi propio sistema» es exactamente
       la frase con la que entran los agujeros. */
    const texto = (typeof d === 'string') ? d : (d.texto || JSON.stringify(d, null, 1));
    pintaTexto($('doc-cuerpo'), texto);
    if (doc && doc.firmado) {
      const f = (usaCopia && d.firma) || {};
      $('doc-firma-t').textContent =
        'Firmado' + (f.nombre ? ' por ' + f.nombre : '') + ' el ' +
        fecha(f.firmado_en || doc.firmado) + ' a las ' + String(f.firmado_en || doc.firmado).slice(11, 16) + '.';
      $('doc-firma-h').textContent = 'Huella del texto firmado (SHA-256): ' +
        (f.documento_sha256 || await huellaDe(texto));
      $('doc-firma').hidden = false;
      $('doc-imprimir').hidden = false;
    }
  } catch (_) {
    $('doc-cuerpo').textContent =
      'Este documento está cerrado con una llave anterior a tu contraseña actual. ' +
      'Lo vuelvo a mandar y reaparece aquí; no se ha perdido.';
  }
}
async function huellaDe(texto) {
  const bytes = new TextEncoder().encode(texto);
  return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)))
    .map(b => b.toString(16).padStart(2, '0')).join('');
}
/* La copia firmada, en papel o en PDF: lo que se imprime es la vista
   del documento y nada más (ni el panel, ni los botones). */
$('doc-imprimir').addEventListener('click', () => {
  document.body.classList.add('imprime-doc');
  window.print();
  setTimeout(() => document.body.classList.remove('imprime-doc'), 500);
});

/* --- Un texto largo, legible -----------------------------------------
   El consentimiento llega con su estructura en saltos de línea —título,
   secciones numeradas, apartados a), b)— y pintado tal cual en un
   bloque, HTML junta todos los saltos y sale un solo párrafo de dos
   pantallas. Esto lo parte en piezas SOLO para verlo: la cadena no se
   toca, y la huella de la firma se calcula sobre ella, no sobre lo
   pintado. Todo con textContent: nada de lo que llega se pega como HTML. */
function pintaTexto(caja, texto) {
  caja.replaceChildren();
  caja.classList.add('texto-largo');
  const lineas = String(texto).replace(/\r\n?/g, '\n').split('\n');
  let previaVacia = true, cabecera = 0, lista = null, parrafo = null;
  const cierra = () => { lista = null; parrafo = null; };
  const nuevo = (tag, txt, clase) => {
    const e = document.createElement(tag);
    e.textContent = txt;
    if (clase) e.className = clase;
    caja.append(e);
    return e;
  };
  lineas.forEach(l => {
    const t = l.trim();
    if (!t) { previaVacia = true; cierra(); return; }
    if (cabecera < 2 && !caja.querySelector('h3,p')) {
      /* Las dos primeras líneas: título y subtítulo. */
      nuevo(cabecera === 0 ? 'h2' : 'p', t, cabecera === 0 ? 'doc-t' : 'doc-sub');
      cabecera++; previaVacia = false; return;
    }
    if (/^#{1,3}\s/.test(t)) { nuevo('h3', t.replace(/^#+\s*/, '')); cierra(); previaVacia = false; return; }
    if ((previaVacia && /^\d+\.\s/.test(t)) || /^Cláusula de protección de datos/i.test(t)) {
      nuevo('h3', t); cierra(); previaVacia = false; return;
    }
    if (/^([a-z]\)|[-•]|\d+\.)\s/.test(t)) {
      if (!lista) lista = nuevo('ul', '', 'apartados');
      const li = document.createElement('li');
      li.textContent = t.replace(/^[-•]\s/, '');
      lista.append(li);
      parrafo = null; previaVacia = false; return;
    }
    if (parrafo && !previaVacia) { parrafo.textContent += '\n' + t; }
    else { lista = null; parrafo = nuevo('p', t); }
    previaVacia = false;
  });
  /* El último párrafo —en el consentimiento, «La persona firmante
     consiente…»— destacado: es lo que se firma. */
  const ps = caja.querySelectorAll(':scope > p:not(.doc-sub)');
  if (ps.length > 2) ps[ps.length - 1].classList.add('destacado');
}

/* Un PDF sellado (una herramienta, un resumen ya maquetado). Se abre
   aquí mismo, en el navegador: los bytes se descifran en memoria y se
   ofrecen como un enlace local (blob:), que no sale a ningún sitio.
   Cuando se vuelve atrás, se suelta. */
let URL_PDF = null;
function pintaPdf(d) {
  const bin = atob(d.pdf), bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  if (URL_PDF) URL.revokeObjectURL(URL_PDF);
  URL_PDF = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
  const caja = $('doc-cuerpo');
  caja.replaceChildren();
  const p = document.createElement('p');
  p.textContent = 'Es un PDF. Se abre en tu dispositivo, sin pasar por ningún otro sitio.';
  const abrirA = document.createElement('a');
  abrirA.className = 'boton'; abrirA.href = URL_PDF; abrirA.target = '_blank'; abrirA.rel = 'noopener';
  abrirA.textContent = 'Abrir el PDF';
  const bajar = document.createElement('a');
  bajar.className = 'enlace-boton'; bajar.href = URL_PDF;
  bajar.download = (String(d.nombre || 'documento.pdf').replace(/[\\/:*?"<>|]/g, '') || 'documento.pdf');
  bajar.textContent = 'Guardarlo';
  const fila = document.createElement('p');
  fila.append(abrirA, ' ', bajar);
  caja.append(p, fila);
}
$('doc-volver').addEventListener('click', () => {
  if (URL_PDF) { URL.revokeObjectURL(URL_PDF); URL_PDF = null; }
  panel();
});

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
/* Las cifras en castellano: 48 y no 48.0; 2,3 y no 2.3. El corte del
   WHO-5 llega reescalado (48.0) y la media del DES-II con decimales. */
function cifra(v) {
  const n = Number(v);
  if (!Number.isFinite(n)) return String(v);
  return Number.isInteger(n) ? String(n)
    : n.toLocaleString('es-ES', { maximumFractionDigits: 1 });
}
function dia(iso) {
  const p = (iso || '').slice(0, 10).split('-');
  return p.length === 3 ? (Number(p[2]) + ' ' + MESES[Number(p[1])]) : iso;
}

function svgSerie(m, ancho) {
  const NS = 'http://www.w3.org/2000/svg';
  const pts = m.puntos || [];
  if (!pts.length) return null;
  /* El lienzo mide lo que mide la caja en pantalla, no 640 fijos: con
     640 encogidos a un móvil de 360, los rótulos salían a 5 px y no se
     leían. Así un 11 del SVG es un 11 de verdad. */
  const An = Math.max(280, Math.min(640, Math.round(ancho || 640))), Al = 170,
        izq = 30, der = 72, arr = 20, aba = 30;
  const w = An - izq - der, h = Al - arr - aba, maxv = m.maximo || 27;
  /* El DERS-28 va de 28 a 140 y el Rosenberg de 10 a 40. Dibujarlos
     desde 0 aplasta la línea arriba y deja media gráfica vacía. */
  const minv = m.minimo || 0, rango = (maxv - minv) || 1;
  /* Con una sola medida, el punto va en medio: pegado a la derecha
     parecía el final de una línea que no se ve. */
  const x = (i) => izq + (pts.length === 1 ? w / 2 : w * i / (pts.length - 1));
  const y = (v) => arr + h - ((v - minv) / rango) * h;

  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 ' + An + ' ' + Al);
  svg.setAttribute('width', '100%');
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label', m.instrumento + ': ' +
    pts.map(p => dia(p.fecha) + ' ' + cifra(p.valor)).join(', '));

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

  /* La zona amarilla: la primera medida, más y menos lo que el propio
     cuestionario puede variar por ruido (su cambio fiable). Si el último
     punto cae dentro, todavía no se puede decir que haya cambiado; si
     sale, sí. Se ve sin leer el párrafo. */
  if (pts.length > 1 && m.cambio_fiable != null && Number(m.cambio_fiable) > 0) {
    const a0 = Number(pts[0].valor), cf = Number(m.cambio_fiable);
    const arriba = y(Math.min(maxv, a0 + cf)), abajo = y(Math.max(minv, a0 - cf));
    const z = document.createElementNS(NS, 'rect');
    z.setAttribute('x', izq); z.setAttribute('y', arriba.toFixed(1));
    z.setAttribute('width', w); z.setAttribute('height', Math.max(0, abajo - arriba).toFixed(1));
    z.setAttribute('fill', 'rgba(247,223,114,.5)'); z.setAttribute('class', 'zona-ruido');
    svg.appendChild(z);
  }
  svg.appendChild(linea(izq, arr + h, izq + w, arr + h, '#CECCC6', 1));
  svg.appendChild(texto(izq - 8, y(minv) + 4, cifra(minv), 12, '#6C6963', 'end'));
  svg.appendChild(texto(izq - 8, y(maxv) + 4, cifra(maxv), 12, '#6C6963', 'end'));

  if (m.corte != null) {
    const yc = y(m.corte);
    svg.appendChild(linea(izq, yc, izq + w, yc, '#7B4D13', 1.5, '5 4'));
    /* A 14 px del final y no a 6: el último punto (radio 6,5 con su
       anillo) cae justo ahí y se comía la «c» de «corte». */
    svg.appendChild(texto(izq + w + 14, yc + 4, 'corte ' + cifra(m.corte), 12, '#7B4D13'));
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
    /* Con muchas medidas, la fecha solo en la primera y la última: en
       un móvil no caben doce fechas sin pisarse. */
    if (pts.length <= 5 || i === 0 || i === pts.length - 1) {
      svg.appendChild(texto(x(i).toFixed(1), Al - 10, dia(p.fecha), 12, '#6C6963',
                            pts.length === 1 ? 'middle' : (i === 0 ? 'start' : (i === pts.length - 1 ? 'end' : 'middle'))));
    }
  });
  [...new Set([0, pts.length - 1])].forEach(i => {
    const p = pts[i], cy = y(p.valor);
    svg.appendChild(texto(x(i).toFixed(1), (cy > arr + 22 ? cy - 12 : cy + 19).toFixed(1),
                          cifra(p.valor), 14, '#25231F', 'middle', '700'));
  });
  return svg;
}

function lectura(m) {
  const pts = m.puntos || [];
  if (pts.length < 2) {
    return 'Aún no hay suficientes medidas para ver una tendencia. Esta primera ' +
           'sirve como punto de partida.';
  }
  const a = cifra(pts[0].valor), z = cifra(pts[pts.length - 1].valor);
  const A = Number(pts[0].valor), Z = Number(pts[pts.length - 1].valor);
  /* En casi todos, bajar es mejorar. En el Rosenberg o el WHO-5 es al
     revés, y leerlos con la misma frase le diría a alguien que ha
     empeorado justo la semana que mejora. */
  const alReves = m.direccion === 'mas_es_mejor';
  const dif = alReves ? Z - A : A - Z;
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
  const cuanto = cifra(Math.abs(dif)) + ' puntos ' + (Z < A ? 'menos' : 'más');
  if (dif > 0) {
    return 'De ' + a + ' a ' + z + ': ' + cuanto + ', y eso ya pasa de lo ' +
           'que el cuestionario puede confundir con ruido (' + cf + ' puntos). ' +
           'Es un cambio real, y en la buena dirección.';
  }
  return 'De ' + a + ' a ' + z + ': ' + cuanto + ', y es más de lo ' +
         'que se explica por el propio cuestionario (' + cf + ' puntos). Lo hablamos.';
}

/* El cambio desde la primera medida, en palabras y sin juzgar: «5 puntos
   menos que en junio». Si es mejor o peor lo dice `lectura`, con el
   cambio fiable delante; aquí solo el hecho. */
function desdeElPrincipio(m) {
  const pts = m.puntos || [];
  if (pts.length < 2) return 'Primera medida: ' + dia(pts[0] && pts[0].fecha) + '.';
  const a = Number(pts[0].valor), z = Number(pts[pts.length - 1].valor);
  const mes = MESES_L[Number((pts[0].fecha || '').slice(5, 7)) - 1] || 'la primera';
  if (a === z) return 'Igual que en ' + mes + '.';
  return cifra(Math.abs(z - a)) + ' puntos ' + (z < a ? 'menos' : 'más') + ' que en ' + mes + '.';
}

/* Qué mide cada uno, en dos palabras, para la cabecera de su tarjeta.
   Los que no estén aquí salen solo con su nombre. */
const QUE_MIDE = {
  'PHQ-9': 'Estado de ánimo', 'GAD-7': 'Ansiedad', 'CORE-10': 'Malestar general',
  'CORE-10 (F)': 'Malestar general', 'WHO-5': 'Bienestar', 'Rosenberg': 'Autoestima',
  'WSAS': 'Cómo afecta a tu día a día', 'AUDIT': 'Consumo de alcohol', 'PSS': 'Estrés',
  'ISI': 'Sueño', 'DERS-28': 'Regulación emocional', 'PCL-5': 'Estrés postraumático',
};
/* La línea pequeña de cada cuestionario en el inicio: solo la forma,
   sin ejes; los números están al lado y en su hoja. */
function chispa(m) {
  const NS = 'http://www.w3.org/2000/svg';
  const pts = (m.puntos || []).filter(p => p && p.valor != null && p.valor !== '');
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 100 26'); svg.setAttribute('preserveAspectRatio', 'none');
  svg.setAttribute('aria-hidden', 'true'); svg.setAttribute('class', 'chispa');
  if (!pts.length) return svg;
  const minv = m.minimo || 0, maxv = m.maximo || 27, r = (maxv - minv) || 1;
  const xy = pts.map((p, i) => [pts.length === 1 ? 50 : 4 + 92 * i / (pts.length - 1),
                                23 - (Number(p.valor) - minv) / r * 20]);
  const l = document.createElementNS(NS, 'polyline');
  l.setAttribute('points', xy.map(q => q[0].toFixed(1) + ',' + q[1].toFixed(1)).join(' '));
  l.setAttribute('fill', 'none'); l.setAttribute('stroke', '#1F5474'); l.setAttribute('stroke-width', '2');
  l.setAttribute('vector-effect', 'non-scaling-stroke');
  svg.appendChild(l);
  const u = xy[xy.length - 1], c = document.createElementNS(NS, 'circle');
  c.setAttribute('cx', u[0]); c.setAttribute('cy', u[1]); c.setAttribute('r', '2.6'); c.setAttribute('fill', '#1F5474');
  svg.appendChild(c);
  return svg;
}
function ultimaDe(m) {
  const pts = (m.puntos || []).filter(p => p && p.valor != null && p.valor !== '');
  return pts.length ? pts[pts.length - 1] : null;
}

async function pintaProgreso(meta, v) {
  SERIES = null;
  const dentro = $('progreso');
  const resumenEv = $('resumen-evolucion');
  resumenEv.textContent = 'Aquí verás cómo vas cuando hayas rellenado tus cuestionarios.';
  /* Nunca en blanco: sin gráfica todavía, se dice qué va a haber. */
  if (!meta) {
    dentro.replaceChildren(vacio('Aquí verás tu evolución cuando hayas rellenado tus cuestionarios.'));
    return;
  }
  dentro.textContent = 'Abriendo…';

  if (Number(meta.para_v) !== Number(v)) {
    dentro.textContent = 'Tu gráfica está cerrada con una llave anterior a tu ' +
      'contraseña actual. La vuelvo a mandar y reaparece aquí.';
    return;
  }
  const r = await api('abrir&id=' + encodeURIComponent(meta.id));
  if (!r.ok || !r.j.cifrado) {
    dentro.textContent = 'No se ha podido traer tu gráfica. Prueba con «Actualizar» y, si sigue, avísame.';
    return;
  }
  let d;
  try { d = abrirDelMac(r.j.cifrado, miPublica, privada); }
  catch (_) { dentro.textContent = 'No se ha podido abrir tu gráfica. Avísame y lo miro.'; return; }

  /* Lo que llega es {series: [...]}; si alguna vez llega la lista sola,
     se acepta igual en vez de quedarse en blanco. */
  const series = Array.isArray(d) ? d : ((d && d.series) || []);
  SERIES = series;
  dibujaSeries();
}

/* Se dibujan aparte para poder redibujarlas al girar el móvil o cambiar
   el ancho de la ventana: el lienzo se ajusta al ancho real. */
let SERIES = null, RESIZE = null;
window.addEventListener('resize', () => {
  clearTimeout(RESIZE);
  RESIZE = setTimeout(() => { if (SERIES && !$('v-panel').hidden) dibujaSeries(); }, 250);
});
function dibujaSeries() {
  const dentro = $('progreso');
  const resumenEv = $('resumen-evolucion');
  const series = SERIES || [];
  dentro.innerHTML = '';
  /* En el inicio, una línea: la última medida de cada uno, dicha de
     forma que no se pueda leer «GAD-72»: «GAD-7: 2 sobre 21». */
  const lineas = series.map(m => { const u = ultimaDe(m);
    return u ? (m.instrumento || 'Cuestionario') + ': ' + cifra(u.valor) + ' sobre ' + cifra(m.maximo || 27) : null; })
    .filter(Boolean);
  if (lineas.length) {
    const minis = document.createElement('span');
    minis.className = 'minis';
    series.forEach(m => { const u = ultimaDe(m); if (!u) return;
      const e = document.createElement('span'); e.className = 'mini';
      const n = document.createElement('span'); n.className = 'n'; n.textContent = m.instrumento || 'Cuestionario';
      const v = document.createElement('span'); v.className = 'v'; v.textContent = cifra(u.valor);
      const de = document.createElement('small'); de.textContent = 'de ' + cifra(m.maximo || 27);
      v.appendChild(de); e.append(n, v, chispa(m)); minis.appendChild(e); });
    resumenEv.replaceChildren(minis);
    resumenEv.setAttribute('aria-label', 'Última medida: ' + lineas.join('; '));
  }
  if (!series.length) {
    dentro.appendChild(vacio('Todavía no hay medidas que dibujar. Aparecerán cuando rellenes tus cuestionarios.'));
    return;
  }
  series.forEach(m => {
    const fig = document.createElement('figure');
    fig.className = 'grafica';
    try {
      const pts = (m.puntos || []).filter(p => p && p.valor != null && p.valor !== '');
      m = Object.assign({}, m, { puntos: pts });
      /* La cabecera, en tres líneas para que no se mezcle el nombre con
         la cifra: qué es, qué mide y hacia dónde es mejor; y debajo la
         última medida con su escala y su fecha. */
      const cab = document.createElement('figcaption');
      const nombre = document.createElement('h3');
      nombre.className = 'g-nombre'; nombre.textContent = m.instrumento || 'Cuestionario';
      const sub = document.createElement('p');
      sub.className = 'g-sub';
      sub.textContent = [QUE_MIDE[m.instrumento],
        m.direccion === 'mas_es_mejor' ? 'aquí, más alto es mejor' : 'aquí, más bajo es mejor']
        .filter(Boolean).join(' · ');
      cab.append(nombre, sub);
      if (pts.length) {
        const u = pts[pts.length - 1];
        const ultimo = document.createElement('p');
        ultimo.className = 'g-ultimo';
        const n = document.createElement('strong'); n.textContent = cifra(u.valor);
        const de = document.createElement('span');
        de.textContent = ' sobre ' + cifra(m.maximo || 27) + ' · última medida, ' + dia(u.fecha);
        ultimo.append(n, de);
        cab.appendChild(ultimo);
      }
      fig.appendChild(cab);
      if (!pts.length) {
        fig.appendChild(vacio('Sin medidas todavía.'));
        dentro.appendChild(fig);
        return;
      }
      /* Con una sola medida no hay «cambio» que contar: lo dice la
         lectura de abajo. */
      if (pts.length > 1) {
        const cambio = document.createElement('p');
        cambio.className = 'g-cambio';
        cambio.textContent = desdeElPrincipio(m);
        fig.appendChild(cambio);
      }
      const g = svgSerie(m, $('v-panel').clientWidth - 82);
      if (g) fig.appendChild(g);
      /* La leyenda, solo de lo que está dibujado. */
      const ley = [];
      if (m.puntos.length > 1 && m.cambio_fiable != null && Number(m.cambio_fiable) > 0)
        ley.push(['ruido', 'Zona en la que un cambio aún puede ser ruido del cuestionario']);
      if (m.corte != null) ley.push(['corte', 'Corte orientativo']);
      if (g && ley.length) {
        const le = document.createElement('p'); le.className = 'g-leyenda';
        ley.forEach(([c, t]) => { const sp = document.createElement('span'); const i = document.createElement('i');
          i.className = c; sp.append(i, t); le.appendChild(sp); });
        fig.appendChild(le);
      }
      const cifras = document.createElement('p');
      cifras.className = 'apunte g-cifras';
      /* La escala, siempre: un 12 no dice nada sin saber si es de 27 o de 140. */
      cifras.textContent = pts.map(p => dia(p.fecha) + ': ' + cifra(p.valor)).join(' · ')
        + ' · escala de ' + cifra(m.minimo || 0) + ' a ' + cifra(m.maximo || 27)
        + (m.corte != null ? ' · corte en ' + cifra(m.corte) : '');
      fig.appendChild(cifras);
      const l = document.createElement('p');
      l.className = 'apunte';
      l.textContent = lectura(m);
      fig.appendChild(l);
    } catch (e) {
      /* Una serie que no se puede dibujar no se lleva por delante a las
         demás, y no se queda en blanco: lo dice. */
      fig.replaceChildren(vacio('No se ha podido dibujar esta gráfica. Avísame y lo miro.'));
      console.error('progreso', e);
    }
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
  /* El aviso de copyright, con su enlace pulsable. Se monta con nodos y
     no con innerHTML: viene del catálogo, y lo que viene de fuera no se
     pega como HTML. */
  const cp = $('t-copyright');
  cp.replaceChildren();
  cp.hidden = !P.aviso_copyright;
  String(P.aviso_copyright || '').split(/(https:\/\/[^\s]+)/).forEach((trozo, i) => {
    if (!trozo) return;
    if (i % 2) {
      const a = document.createElement('a');
      a.href = trozo; a.textContent = trozo; a.target = '_blank'; a.rel = 'noopener';
      cp.append(a);
    } else cp.append(document.createTextNode(trozo));
  });
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
    /* Arriba, donde se ve: que lo que acaba de hacer ha llegado. */
    $('recibido').hidden = false;
    setTimeout(() => { $('recibido').hidden = true; }, 12000);
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

/* El plan de seguridad: se busca entre sus copias enviadas (se abren
   aquí, con su clave) la más reciente que sea de riesgo. */
function buscaPlan(copias, v) {
  PLAN = null;
  for (const d of copias) {               // vienen de la más nueva a la más vieja
    if (Number(d.para_v) !== Number(v)) continue;
    api('abrir&id=' + encodeURIComponent(d.id)).then(r => {
      if (PLAN || !r.ok || !r.j.cifrado) return;
      try {
        const c = abrirDelMac(r.j.cifrado, miPublica, privada);
        if (c.def && c.def.riesgo && !PLAN) { PLAN = d.id; $('b-plan').hidden = false; }
      } catch (_) {}
    });
  }
  $('b-plan').hidden = true;
}
$('b-plan').addEventListener('click', () => { if (PLAN) abrirCopia(PLAN); });

$('c-accesos').addEventListener('toggle', async () => {
  if (!$('c-accesos').open) return;
  const r = await api('accesos');
  const ul = $('accesos'); ul.innerHTML = '';
  ((r.j && r.j.accesos) || []).forEach(a => {
    const li = document.createElement('li');
    li.textContent = a.cuando.slice(0, 16).replace('T', ' ') + ' · ' + a.que;
    if (!Number(a.ok)) li.className = 'fallo';
    ul.appendChild(li);
  });
  if (!ul.children.length) ul.appendChild(Object.assign(document.createElement('li'), { textContent: 'Nada todavía.' }));
});
</script>
HTML;

adr_pagina('Entrar | adricamente', $cuerpo, $guion);
