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

$cuerpo = <<<'HTML'
<!-- Las vistas. Solo una visible cada vez; el guion las cambia sin
     navegar, que es lo que mantiene la clave en memoria. -->

<section id="v-entrar" class="vista">
  <div class="centrado">
    <h1>Entrar</h1>
    <p class="apunte">Tu cuenta de adricamente: los documentos que te
    mando y los cuestionarios que te toque rellenar.</p>

    <div class="nota mal" id="mal" hidden role="alert"><p id="mal-t"></p></div>

    <form id="f-entrar">
      <div class="campo">
        <label for="correo">Tu correo</label>
        <input type="email" id="correo" required autocomplete="username"
               autocapitalize="off" spellcheck="false">
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

<section id="v-panel" class="vista" hidden>
  <p class="etiqueta">Tu cuenta</p>
  <h1>Hola</h1>

  <div class="nota ojo" id="reenviando" hidden>
    <p><strong>Tus documentos están volviendo.</strong> Cambiaste la
    contraseña, así que lo que había estaba cerrado con la llave
    anterior. No se ha perdido nada: los vuelvo a mandar y van
    apareciendo aquí.</p>
  </div>

  <div class="caja">
    <h2 style="margin-top:0">Mis documentos</h2>
    <div id="docs"></div>
  </div>

  <p class="apunte" id="panel-estado" role="status"></p>
</section>

<section id="v-doc" class="vista" hidden>
  <p class="etiqueta">Documento</p>
  <h1 id="doc-titulo">…</h1>
  <div class="caja"><div id="doc-cuerpo"></div></div>
  <p><button class="enlace-boton" id="doc-volver" type="button">← Volver</button></p>
</section>
HTML;

$guion = adr_sodio() . <<<'HTML'
<script type="module">
import { listo, entrarConServidor, abrirDelMac, vigilarSesion } from './cripto.js';

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

const $ = (id) => document.getElementById(id);
const vistas = ['v-entrar', 'v-panel', 'v-doc'];
function ver(cual) {
  vistas.forEach(v => { $(v).hidden = (v !== cual); });
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

/* --- El panel -------------------------------------------------------- */
async function panel() {
  ver('v-panel');
  const r = await api('mios');
  if (!r.ok) { location.reload(); return; }
  miPublica = r.j.publica || null;
  const docs = r.j.documentos || [];
  const caja = $('docs');
  caja.innerHTML = '';

  if (!docs.length) {
    caja.innerHTML = '<div class="vacio"><p>Todavía no hay nada aquí. ' +
      'Cuando te mande algo, aparece en esta lista.</p></div>';
    return;
  }

  /* Un documento sellado a una llave anterior no se puede abrir, y sin
     avisar parecería que está roto. Se marca, y el panel explica que
     están volviendo. */
  let viejos = 0;
  docs.forEach(d => {
    const caduco = Number(d.para_v) !== Number(r.j.v);
    if (caduco) viejos++;
    const fila = document.createElement('div');
    fila.className = 'fila';
    const t = document.createElement('div');
    t.className = 'texto';
    const s = document.createElement('strong');
    s.textContent = d.titulo || 'Documento';
    const f = document.createElement('p');
    f.textContent = (d.creado || '').slice(0, 10).split('-').reverse().join('/');
    t.appendChild(s); t.appendChild(f);
    fila.appendChild(t);
    if (caduco) {
      const e = document.createElement('span');
      e.className = 'sello caduca';
      e.textContent = 'volviendo';
      fila.appendChild(e);
    } else {
      const b = document.createElement('button');
      b.className = 'boton suave';
      b.type = 'button';
      b.textContent = 'Abrir';
      b.addEventListener('click', () => abrir(d.id, d.titulo));
      fila.appendChild(b);
    }
    caja.appendChild(fila);
  });
  $('reenviando').hidden = (viejos === 0);
}

async function abrir(id, titulo) {
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
    $('doc-cuerpo').textContent = (typeof d === 'string') ? d : (d.texto || JSON.stringify(d, null, 1));
  } catch (_) {
    $('doc-cuerpo').textContent =
      'Este documento está cerrado con una llave anterior a tu contraseña actual. ' +
      'Lo vuelvo a mandar y reaparece aquí; no se ha perdido.';
  }
}

$('doc-volver').addEventListener('click', panel);
/* El borrado al cerrar la pestaña ya lo pone vigilarSesion(). No se
   repite aquí: dos sitios que hacen lo mismo es uno que algún día
   deja de hacerlo sin que se note. */
</script>
HTML;

adr_pagina('Entrar | adricamente', $cuerpo, $guion);
