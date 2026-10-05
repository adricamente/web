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
  <h1>Tu cuenta</h1>
  <p class="apunte">Elige una contraseña. Con ella se abre lo que te
  mando, así que hay dos cosas que conviene saber antes de teclearla.</p>

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
        portal, y Brevo, que envía los correos de aviso, como encargados
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
    <p><button class="boton ancho" id="b" type="submit">Crear mi cuenta</button></p>
    <p class="apunte" id="estado" role="status"></p>
  </form>
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
        aviso: 'portal-v1',
      }),
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) { falla(j.error === 'ese enlace ya no vale'
        ? 'Este enlace ya se ha usado o ha caducado. Escríbeme y te mando otro.'
        : 'No hemos podido crear la cuenta. Inténtalo otra vez.'); return; }

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
