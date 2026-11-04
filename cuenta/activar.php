<?php
/* Activar la cuenta: la primera vez.
   -------------------------------------------------------------------
   Se llega aquí con un enlace de un solo uso que manda Adrián. No hay
   otra forma de tener cuenta, y es a propósito.

   Al terminar NO se entra directamente: se manda a la pantalla de
   entrar para que la persona use la contraseña que acaba de elegir,
   ahora, mientras la recuerda. Un portal que te mete dentro sin
   comprobarlo te deja descubrir dentro de dos semanas que no sabes
   cuál pusiste — y aquí eso no es un fastidio, es perder la llave.
   ------------------------------------------------------------------- */
declare(strict_types=1);

/* La llave que abre lib/. Ver el comentario de lib/arranque.php. */
const ADR_DENTRO = true;
require __DIR__ . '/lib/arranque.php';
require __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/pagina.php';

$cuerpo = <<<HTML
<div class="centrado">
  <!-- 04-11: «Crea tu cuenta», no «Tu cuenta»: una persona invitada leyó
       esta página como la de entrar. -->
  <h1 id="titulo">Crea tu cuenta</h1>

  <!-- Si el enlace no vale, se dice ANTES de pedir la contraseña (la
       página lo pregunta al abrirse, sin gastarlo: api.php?a=ficha), y
       con lo que hay que hacer en cada caso. Sin guion, la página se ve
       como siempre y el aviso sale al pulsar «Crear mi cuenta». -->
  <div id="no-vale" hidden>
    <div class="nota ojo" id="nv-caja"><p id="nv-t"></p></div>
    <p id="nv-entrar" hidden><a class="boton ancho" href="index.php">Entrar</a></p>
    <p id="nv-olvide" class="apunte" hidden><a href="clave.php?olvide=1">No me acuerdo de mi contraseña</a></p>
  </div>

  <div id="crear">
  <p class="apunte">Es la primera vez: aquí eliges la contraseña de tu
  cuenta. Con ella se abre lo que te mando, así que hay dos cosas que
  conviene saber antes de teclearla.</p>

  <div class="nota ojo">
    <p><strong>Esta contraseña no se puede recuperar, se cambia.</strong>
    Si la olvidas, pides una nueva desde la pantalla de entrar y entras
    igual — pero los documentos que ya tuvieras dentro tardan un rato en
    volver a aparecer, porque hay que mandarlos otra vez. No se pierde
    nada; solo tarda.</p>
    <p>Por eso: apúntala donde apuntes las demás, y que no sea una que
    uses en otro sitio.</p>
  </div>

  <div class="nota mal" id="mal" hidden role="alert"><p id="mal-t"></p></div>

  <form id="f">
    <div class="campo">
      <label for="c1">Tu contraseña</label>
      <input type="password" id="c1" required autocomplete="new-password" minlength="10">
      <p class="pista">Diez caracteres como mínimo. Tres palabras que
      no vayan juntas en ninguna frase valen más que una palabra con
      símbolos.</p>
    </div>
    <div class="campo">
      <label for="c2">Otra vez, para estar seguros</label>
      <input type="password" id="c2" required autocomplete="new-password">
    </div>
    <!-- Información de protección de datos, primera capa (art. 13
         RGPD). El consentimiento v2 no habla del portal: esto lo cubre.
         Si cambia, se sube ADR_AVISO_V en lib/arranque.php. -->
    <div class="nota privacidad">
      <p><strong>Antes de crear tu cuenta, lo que pasa con tus datos aquí:</strong></p>
      <ul>
        <li><strong>Responsable:</strong> Clínica Neurococos, el centro
        sanitario donde te atiendo.</li>
        <li><strong>Para qué:</strong> para que recibas y me mandes los
        documentos y cuestionarios de tu tratamiento.</li>
        <li><strong>Qué se guarda en el servidor:</strong> tu correo, una
        versión transformada de tu contraseña que no permite recuperarla,
        la fecha de cada entrada y tus documentos <strong>cifrados de
        extremo a extremo</strong>: el servidor no puede leerlos.</li>
        <li><strong>Quién más interviene:</strong> Hostinger, que aloja el
        portal, y Google (Google Workspace), desde cuya cuenta
        hola@adricamente.com salen los correos de aviso, como encargados
        del tratamiento.</li>
        <li><strong>Tus derechos:</strong> acceso, rectificación,
        supresión, oposición, limitación y portabilidad, escribiendo a
        <a href="mailto:clinicaneurococos@gmail.com">clinicaneurococos@gmail.com</a>.
        Si crees que no se han respetado, puedes reclamar ante la Agencia
        Española de Protección de Datos.</li>
      </ul>
      <p><a href="https://adricamente.com/privacidad.html#cuenta" target="_blank" rel="noopener">La información completa</a></p>
      <label class="acepto"><input type="checkbox" id="aviso" required>
      He leído esta información sobre mis datos en el portal.</label>
    </div>
    <!-- 04-11: el orden, dicho antes. Al entrar sale el consentimiento
         informado (lo deja el portal al activar, o el Mac en unos
         minutos si aún no ha subido el texto vigente) y, hasta firmarlo,
         no se abre nada que se rellene. -->
    <p class="apunte" id="despues">Después, al entrar, lo primero será leer y
    firmar el consentimiento informado de la atención. Hasta entonces no
    tendrás que rellenar nada.</p>
    <p><button class="boton ancho" id="b" type="submit">Crear mi cuenta</button></p>
    <p class="apunte" id="estado" role="status"></p>
  </form>
  </div>
</div>
HTML;

/* El guion va en un heredoc con comillas simples: sin interpolación
   de PHP, así que las llaves de JavaScript no hay que escaparlas ni
   una sola vez. El papel no se inyecta aquí, se lee de la URL en el
   navegador — un valor menos que colar en una plantilla. */
$guion = adr_sodio() . <<<'HTML'
<script type="module">
import { listo, crearIdentidad } from './cripto.js';

const $ = (id) => document.getElementById(id);
function falla(t) { $('mal-t').textContent = t; $('mal').hidden = false; }

/* Lo que se dice en cada caso (04-11). Caducado no invita a entrar: no
   tiene cuenta. Ya usado sí, con el botón. «No existe» también cubre el
   enlace de una invitación que caducó hace días: a los 7 días sin
   activar se borra todo, enlace incluido, y ya no se puede distinguir. */
const NO_VALE = {
  caducado: ['Este enlace ha caducado',
    'Este enlace ha caducado: dura 7 días. Escríbeme a hola@adricamente.com o contesta al correo y te mando otro.'],
  usado: ['Tu cuenta ya está creada',
    'Esta cuenta ya está creada: entra con tu correo y la contraseña que elegiste.'],
  no_existe: ['Este enlace no es válido',
    'Este enlace no es válido. Comprueba que lo has abierto entero desde el correo de invitación. Si es de hace más de una semana, puede haber caducado: escríbeme a hola@adricamente.com y te mando otro.'],
};
function noVale(estado) {
  const [t, x] = NO_VALE[estado] || NO_VALE.no_existe;
  $('titulo').textContent = t;
  $('nv-t').textContent = x;
  $('nv-entrar').hidden = $('nv-olvide').hidden = estado !== 'usado';
  /* Ya creada no es un problema: el aviso, en azul y no en naranja. */
  $('nv-caja').className = 'nota ' + (estado === 'usado' ? 'info' : 'ojo');
  $('crear').hidden = true;
  $('no-vale').hidden = false;
  document.title = t + ' | adricamente';
}
const papelDeAqui = new URLSearchParams(location.search).get('p') || '';
/* Antes de pedir nada: ¿vale el enlace? La consulta no lo gasta. Si no
   se puede preguntar (sin red, o muchas consultas seguidas), se deja la
   página como está y lo dirá al crear la cuenta. */
(async () => {
  if (!papelDeAqui) { noVale('no_existe'); return; }
  try {
    const r = await fetch('api.php?a=ficha', { method: 'POST', headers: { 'content-type': 'application/json' },
                                                body: JSON.stringify({ papel: papelDeAqui, tipo: 'alta' }) });
    const j = await r.json().catch(() => ({}));
    if (r.ok && j.estado && j.estado !== 'vale') noVale(j.estado);
  } catch (_) {}
})();

$('f').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  $('mal').hidden = true;
  const c1 = $('c1').value, c2 = $('c2').value;
  if (c1 !== c2) { falla('Las dos contraseñas no son la misma.'); return; }
  if (c1.length < 10) { falla('Hacen falta al menos diez caracteres.'); return; }
  if (!$('aviso').checked) { falla('Falta marcar que has leído la información sobre tus datos.'); return; }

  $('b').disabled = true;
  $('estado').textContent = 'Creando tu llave…';
  try {
    await listo();
    /* Aquí se genera la pareja de claves. La privada se envuelve con
       la contraseña y lo que sube al servidor es la envuelta: el
       servidor nunca ve ni la contraseña ni la privada. */
    const id = await crearIdentidad(c1);
    const papel = new URLSearchParams(location.search).get('p') || '';
    const r = await fetch('api.php?a=activar', {
      method: 'POST', headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        papel, sal: id.sal, auth: id.auth,
        publica: id.publica, envuelta: id.privadaEnvuelta,
        aviso: 'portal-v2',
      }),
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) {
      const m = /^enlace_(caducado|usado|no_existe)$/.exec(j.error || '');
      if (m) { noVale(m[1]); return; }
      falla('No hemos podido crear la cuenta. Inténtalo otra vez.'); return;
    }

    /* A entrar con la contraseña recién elegida. Ver el comentario de
       arriba: es la única forma de que la compruebe hoy y no dentro de
       dos semanas. */
    location.href = 'index.php?nueva=1';
  } catch (_) {
    falla('No hemos podido crear la cuenta. Inténtalo otra vez.');
  } finally {
    $('b').disabled = false;
    $('estado').textContent = '';
  }
});
</script>
HTML;

adr_pagina('Tu cuenta | adricamente', $cuerpo, $guion);
