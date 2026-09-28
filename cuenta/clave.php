<?php
/* La contraseña: pedirla nueva y ponerla.
   -------------------------------------------------------------------
   Dos pantallas en un fichero porque son las dos mitades de lo mismo:

     ?olvide=1   pide el correo y manda el enlace
     ?p=PAPEL    pone la contraseña nueva

   Lo que pasa por debajo, y que conviene no perder de vista: poner una
   contraseña nueva aquí es crear una identidad NUEVA, no reabrir la
   anterior. La llave vieja se fue con la contraseña vieja; nadie la
   tiene, tampoco el servidor. Lo que hace que esto no sea una pérdida
   es que el Mac de Adrián guarda el original de todo y lo vuelve a
   mandar sellado a la llave nueva.

   Por eso el número de versión de la clave sube al hacer esto. Sin
   ese número, la persona entraría y vería sus documentos en gris para
   siempre sin que saltara un solo error.
   ------------------------------------------------------------------- */
declare(strict_types=1);
require __DIR__ . '/lib/arranque.php';
require __DIR__ . '/lib/sesion.php';
require __DIR__ . '/lib/pagina.php';

$papel = (string)($_GET['p'] ?? '');

if ($papel === '') {
    /* --- Pedir el enlace ------------------------------------------- */
    $cuerpo = <<<'HTML'
<div class="centrado">
  <h1>Una contraseña nueva</h1>
  <p class="apunte">Dime tu correo y te mando un enlace para poner otra.</p>

  <div class="nota bien" id="hecho" hidden role="status">
    <p><strong>Mira tu correo.</strong> Si esa dirección tiene cuenta
    aquí, acabas de recibir un enlace. Vale dos horas y una sola vez.</p>
    <p>Si no lo ves, mira en la carpeta de spam: a veces cae ahí.</p>
  </div>

  <form id="f">
    <div class="campo">
      <label for="correo">Tu correo</label>
      <input type="email" id="correo" required autocomplete="username"
             autocapitalize="off" spellcheck="false">
    </div>
    <p><button class="boton ancho" id="b" type="submit">Mandarme el enlace</button></p>
  </form>

  <div class="caja plana" style="margin-top:20px">
    <p class="apunte" style="margin:0"><strong>Antes de hacerlo:</strong>
    entrarás igual, pero los documentos que ya tuvieras dentro tardan un
    rato en volver a aparecer. Van cerrados con tu contraseña anterior,
    así que hay que mandarlos otra vez. No se pierde nada.</p>
  </div>

  <p class="apunte"><a href="index.php">← Volver a entrar</a></p>
</div>
HTML;

    $guion = <<<'HTML'
<script type="module">
const $ = (id) => document.getElementById(id);
$('f').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  $('b').disabled = true;
  /* Se conteste lo que se conteste, aquí se enseña lo mismo. El
     servidor ya devuelve idéntica respuesta exista o no la cuenta —si
     dijera «ese correo no existe» estaría diciendo quién está en
     tratamiento— y la pantalla no lo va a estropear. */
  try {
    await fetch('api.php?a=olvide', {
      method: 'POST', headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ correo: $('correo').value.trim() }),
    });
  } catch (_) {}
  $('f').hidden = true;
  $('hecho').hidden = false;
});
</script>
HTML;
    adr_pagina('Contraseña | adricamente', $cuerpo, $guion);
}

/* --- Poner la contraseña nueva ------------------------------------- */
$cuerpo = <<<'HTML'
<div class="centrado">
  <h1>Pon tu contraseña nueva</h1>

  <div class="nota ojo">
    <p><strong>Esto crea una llave nueva.</strong> Los documentos que ya
    tuvieras dentro están cerrados con la anterior, así que los tengo
    que mandar otra vez. Aparecerán solos; no hace falta que pidas nada.</p>
  </div>

  <div class="nota mal" id="mal" hidden role="alert"><p id="mal-t"></p></div>

  <form id="f">
    <div class="campo">
      <label for="c1">Tu contraseña nueva</label>
      <input type="password" id="c1" required autocomplete="new-password" minlength="10">
      <p class="pista">Diez caracteres como mínimo. Tres palabras que no
      vayan juntas en ninguna frase valen más que una palabra con símbolos.</p>
    </div>
    <div class="campo">
      <label for="c2">Otra vez, para estar seguros</label>
      <input type="password" id="c2" required autocomplete="new-password">
    </div>
    <p><button class="boton ancho" id="b" type="submit">Guardar</button></p>
    <p class="apunte" id="estado" role="status"></p>
  </form>
</div>
HTML;

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
  $('estado').textContent = 'Creando tu llave nueva…';
  try {
    await listo();
    /* crearIdentidad(), no cambiarContrasena(). La diferencia es todo:
       cambiar reenvuelve la MISMA llave y conserva lo recibido, pero
       para eso hace falta tener la llave vieja, y quien ha olvidado la
       contraseña no la tiene. Aquí nace una pareja nueva, y por eso el
       servidor sube el número de versión y el Mac reenvía.

       Si algún día alguien "simplifica" esto llamando a
       cambiarContrasena(), no saltará ningún error: simplemente nada
       volverá a abrirse nunca. */
    const id = await crearIdentidad(c1);
    const papel = new URLSearchParams(location.search).get('p') || '';
    const r = await fetch('api.php?a=reset', {
      method: 'POST', headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        papel, sal: id.sal, auth: id.auth,
        publica: id.publica, envuelta: id.privadaEnvuelta,
      }),
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) {
      falla(j.error === 'ese enlace ya no vale'
        ? 'Este enlace ya se ha usado o ha caducado. Pide otro desde la pantalla de entrar.'
        : 'No hemos podido guardarla. Inténtalo otra vez.');
      return;
    }
    location.href = 'index.php?nueva=1';
  } catch (_) {
    falla('No hemos podido guardarla. Inténtalo otra vez.');
  } finally {
    $('b').disabled = false;
    $('estado').textContent = '';
  }
});
</script>
HTML;

adr_pagina('Contraseña | adricamente', $cuerpo, $guion);
