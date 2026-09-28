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
