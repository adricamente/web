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

  <div class="nota info" id="reenviando" role="status" hidden>
    <p><strong>Actualizando tus documentos</strong> tras el cambio de contraseña. En unos
    minutos vuelven a estar aquí. No se ha perdido nada.</p>
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


    <button class="tarjeta-ir aviso-pago" type="button" id="ir-pago" hidden>
      <span class="t">Sesión sin pagar</span>
      <span class="resumen" id="ir-pago-txt"></span>
      <svg class="flecha" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
    </button>

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

    <!-- Pagos (14-10): cada sesión de pago con su botón «Pagar la sesión»
         (Stripe: tarjeta, Apple Pay, Google Pay) o «Pagada ✓». El enlace
         es el mismo que le llega por correo; no hay datos bancarios aquí. -->
    <section class="caja bloque pago" id="c-pago" aria-labelledby="t-pago" hidden>
      <h2 id="t-pago">Pagos</h2>
      <p id="pago-estado" class="apunte">Con tarjeta, Apple Pay o Google Pay. El recibo te llega por correo.</p>
      <div id="pago-lista"></div>
    </section>

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
    <!-- Mis objetivos: los que elegimos juntos, con su línea de 0 a 10
         (lo que tú valoras cada semana) y lo que ha ido cambiando. Lo
         publica el Mac, sellado («objetivos»); sin él no se enseña. -->
    <section class="caja bloque" id="c-objetivos" aria-labelledby="t-obj" hidden>
      <h2 id="t-obj">Mis objetivos</h2>
      <p class="foco" id="ob-foco" hidden></p>
      <div id="objetivos"></div>
    </section>
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
      <div class="adjuntar">
        <label class="boton suave" for="m-archivo">Adjuntar un archivo</label>
        <input type="file" id="m-archivo" accept="image/*,application/pdf,.doc,.docx,.txt">
        <span class="apunte" id="m-archivo-nombre">Foto, PDF o documento, hasta 15 MB. Va cifrado.</span>
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
      <div id="doc-firma-d" hidden></div>
      <div id="doc-firma-t"></div>
      <p class="apunte" id="doc-firma-h"></p>
    </div>
  </div>
  <p><button class="boton suave" id="doc-imprimir" type="button" hidden>Guardar o imprimir una copia</button></p>
  <p><button class="enlace-boton" id="doc-volver" type="button">← Volver</button></p>
</section>

<section id="v-valorar" class="vista" hidden>
  <p class="etiqueta">Tus objetivos</p>
  <h1 id="vo-titulo">¿Cuánto has avanzado?</h1>
  <p class="apunte">Para cada objetivo, de 0 (nada todavía) a 10 (conseguido). No hay
  respuestas buenas ni malas: es tu valoración de esta semana.</p>
  <div class="caja"><div id="vo-items"></div></div>
  <p>
    <button class="boton" id="vo-enviar" type="button">Enviar</button>
    <button class="enlace-boton" id="vo-volver" type="button">← Volver</button>
    <span class="apunte" id="vo-estado" role="status"></span>
  </p>
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
  <div class="nota bien" id="s-refirma" hidden><p id="s-refirma-t">Ya lo firmaste, pero faltaban tus datos
  de identificación. Revisa el texto, completa el formulario y vuelve a firmarlo. Tu firma
  anterior se conserva.</p></div>
  <div class="caja"><div id="s-cuerpo"></div></div>

  <form class="caja ci" id="s-datos" hidden novalidate autocomplete="on">
    <h2 class="ci-t">Tus datos</h2>
    <p class="apunte">Los necesita el centro para identificarte en tu historia clínica. Van
    cifrados desde este dispositivo hasta el centro: el servidor de esta web no los puede leer.
    Los campos con * son obligatorios.</p>
    <div class="ci-fila">
      <div class="campo"><label for="ci-nombre">Nombre *</label>
        <input type="text" id="ci-nombre" autocomplete="given-name" maxlength="60" required></div>
      <div class="campo"><label for="ci-apellidos">Apellidos *</label>
        <input type="text" id="ci-apellidos" autocomplete="family-name" maxlength="80" required></div>
    </div>
    <div class="ci-fila">
      <div class="campo"><label for="ci-doc-tipo">Documento *</label>
        <select id="ci-doc-tipo"><option value="DNI">DNI</option><option value="NIE">NIE</option>
        <option value="Pasaporte">Pasaporte</option></select></div>
      <div class="campo"><label for="ci-doc">Número *</label>
        <input type="text" id="ci-doc" autocomplete="off" maxlength="20" spellcheck="false"
               autocapitalize="characters" required aria-describedby="ci-doc-p">
        <p class="pista" id="ci-doc-p">Con la letra. Por ejemplo, 12345678Z.</p></div>
    </div>
    <div class="ci-fila">
      <div class="campo"><label for="ci-nac">Fecha de nacimiento *</label>
        <input type="date" id="ci-nac" autocomplete="bday" required></div>
      <div class="campo"><label for="ci-tel">Teléfono *</label>
        <input type="tel" id="ci-tel" autocomplete="tel" maxlength="20" required aria-describedby="ci-tel-p">
        <p class="pista" id="ci-tel-p">Por si se corta la videollamada.</p></div>
    </div>
    <h3 class="ci-st">Domicilio</h3>
    <div class="campo"><label for="ci-dir">Dirección *</label>
      <input type="text" id="ci-dir" autocomplete="street-address" maxlength="120" required
             placeholder="Calle, número, piso"></div>
    <div class="ci-fila ci-tres">
      <div class="campo"><label for="ci-cp">Código postal *</label>
        <input type="text" id="ci-cp" autocomplete="postal-code" inputmode="numeric" maxlength="5" required></div>
      <div class="campo"><label for="ci-loc">Localidad *</label>
        <input type="text" id="ci-loc" autocomplete="address-level2" maxlength="60" required></div>
      <div class="campo"><label for="ci-prov">Provincia *</label>
        <input type="text" id="ci-prov" autocomplete="address-level1" maxlength="40" required></div>
    </div>
    <p class="pista ci-pista">Este servicio es solo para personas que residen en España.</p>

    <h3 class="ci-st">Persona de contacto en una emergencia <span class="ci-op">(opcional)</span></h3>
    <p class="apunte">Solo se usaría si hubiera un riesgo grave para tu vida. Si pones a alguien,
    díselo.</p>
    <div class="ci-fila ci-tres">
      <div class="campo"><label for="ci-em-nombre">Nombre</label>
        <input type="text" id="ci-em-nombre" maxlength="80" autocomplete="off"></div>
      <div class="campo"><label for="ci-em-rel">Relación</label>
        <input type="text" id="ci-em-rel" maxlength="40" autocomplete="off" placeholder="Pareja, madre…"></div>
      <div class="campo"><label for="ci-em-tel">Teléfono</label>
        <input type="tel" id="ci-em-tel" maxlength="20" autocomplete="off"></div>
    </div>

    <h3 class="ci-st">Al firmar, declaras que</h3>
    <ul class="ci-declara" id="ci-declara">
      <li data-k="atencion">Has leído este documento, has podido preguntar tus dudas y consientes la atención psicológica.</li>
      <li data-k="online">Sabes que las sesiones pueden ser por videollamada (Google Meet), en las condiciones que explica
      el documento, y que no puedes grabarlas por tu cuenta.</li>
      <li data-k="practicas">Sabes que te atiende Adrián Cuñat Andrés, psicólogo colegiado nº CM03836, cursando el Máster en
      Psicología General Sanitaria, en prácticas supervisadas en el centro sanitario Neurococos; que tu
      caso se revisa con un profesional colegiado del centro, sujeto a secreto profesional; y que puedes
      pedir que te atienda otro profesional del centro.</li>
      <li data-k="mayor_y_residente">Eres mayor de 18 años y resides en España.</li>
    </ul>
  </form>

  <div class="caja">
    <h2 class="ci-t" id="s-firma-t">Firma</h2>
    <p class="apunte" id="s-firma-a">Para firmarlo, escribe tu nombre y apellidos tal
    como aparecen en tu DNI. Queda registrada la fecha y la hora.</p>
    <div class="campo">
      <label for="s-nombre">Nombre y apellidos</label>
      <input type="text" id="s-nombre" autocomplete="name">
    </div>
    <div class="campo" id="s-clave-c" hidden>
      <label for="s-clave">Tu contraseña de esta cuenta</label>
      <input type="password" id="s-clave" autocomplete="current-password">
      <p class="pista">Para confirmar que eres tú quien firma.</p>
    </div>
    <p><button class="boton" id="s-firmar" type="button">Firmar</button>
    <button class="enlace-boton" id="s-volver" type="button">← Volver</button></p>
    <p class="apunte" id="s-estado" role="status" aria-live="polite"></p>
  </div>
</section>

<section id="v-tarea" class="vista" hidden>
  <p class="etiqueta">Cuestionario</p>
  <h1 id="t-titulo">…</h1>
  <!-- El enunciado (20-10). Iba en gris, como un apunte, y una persona
       nos contó que se lo saltó en sus tres primeros cuestionarios: leyó
       el título y fue directa a la pregunta 1, que era lo primero en
       negrita. Lo que dice cómo contestar (el periodo, la escala) tiene
       que ser lo más visible después del título, no lo menos. -->
  <div class="enunciado" id="t-enunciado" hidden>
    <p class="enunciado-t">Antes de empezar, lee esto</p>
    <p id="t-instrucciones" hidden></p>
    <p id="t-cabecera" hidden></p>
  </div>
  <p class="apunte" id="t-pista" hidden></p>

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
      <button class="boton suave" id="h-guardar" type="button" hidden>Guardar y seguir otro día</button>
      <button class="boton suave" id="h-leido" type="button" hidden>Ya lo he leído</button>
      <button class="boton suave" id="h-pdf" type="button">Descargar en PDF</button>
      <button class="boton suave" id="h-todo" type="button" hidden>Todo en PDF</button>
      <button class="enlace-boton" id="h-volver" type="button">← Volver</button>
    </p>
    <p class="apunte" id="h-estado" role="status"></p>
  </form>
  <div class="auxilio h-riesgo" hidden>
    <p><strong>024</strong> · atención a la conducta suicida, 24 horas,
    gratuito. <strong>112</strong> · emergencias.</p>
  </div>
</section>

<!-- La rueda de las emociones, a mano desde cualquier casilla donde se
     escribe una emoción (25-10). «Usar» pone la palabra en esa casilla. -->
<dialog id="rueda-dialogo" class="rueda-dialogo" aria-labelledby="rd-t">
  <div class="rd-cab">
    <h2 id="rd-t">La rueda de las emociones</h2>
    <button class="enlace-boton" type="button" id="rd-cerrar">Cerrar</button>
  </div>
  <div id="rd-cuerpo"></div>
</dialog>

<!-- Lo que se imprime: una copia estática, con lo escrito como texto.
     Las casillas de un formulario se imprimen mal (se cortan, salen
     vacías en algunos navegadores); esto no. -->
<div id="h-imprimir" class="solo-impresion" aria-hidden="true"></div>
HTML;

$guion = adr_sodio() . <<<'HTML'
<script type="module">
import { listo, entrarConServidor, abrirDelMac, sellarHaciaElMac, vigilarSesion, olvidarPrivada }
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
let miCorreo = null;   /* para volver a pedir la contraseña al firmar el consentimiento */
let DOCS = [];        /* los documentos de la última vuelta del panel */

const $ = (id) => document.getElementById(id);
const VISTAS = ['v-entrar', 'v-panel', 'v-doc', 'v-tarea', 'v-deber', 'v-firmar', 'v-herr', 'v-valorar'];
let PLAN = null;   // id de su plan de seguridad enviado, si tiene
let HERR_FILAS = {};      // id de tarea → su fila del panel (modo, recordatorio…)
let PLAN_TAREA = null;    // su plan de seguridad vivo, si lo tiene
function ver(cual) {
  VISTAS.forEach(v => { $(v).hidden = (v !== cual); });
  /* La ayuda urgente está en todas las pantallas de la cuenta; el
     botón del plan, solo si hay plan. */
  $('plan-fijo').hidden = (cual === 'v-entrar');
  $('b-plan').hidden = !(PLAN || PLAN_TAREA);
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
    miCorreo = correo;

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
  pintaPagos(r.j.pagos || []);
  pintaProgreso(r.j.progreso, r.j.v);

  /* Lo caducado no se enseña ni se cuenta: un número que no baja
     solo genera culpa. El servidor ya no lo manda; esto es la red. */
  const hoy = new Date().toLocaleDateString('sv', {timeZone: 'Europe/Madrid'});
  const todas = (r.j.tareas || []).filter(t => !t.caduca || String(t.caduca).slice(0, 10) >= hoy);
  const docs = r.j.documentos || [];
  const vivas = r.j.herramientas_vivas || [];
  HERR_FILAS = {};
  todas.concat(vivas).forEach(t => { HERR_FILAS[t.id] = t; });
  /* El plan de seguridad: el vivo si lo tiene; si no, el que mandó antes. */
  const planT = todas.concat(vivas).find(t => t.herr_id === 'plan-seguridad');
  PLAN_TAREA = planT ? planT.id : null;
  if (PLAN_TAREA) { PLAN = null; $('b-plan').hidden = false; }
  else buscaPlan(docs.filter(d => d.clase === 'herramienta'), r.j.v);
  pintaHerramientas(todas.filter(t => t.tipo === 'herramienta'), vivas,
                    docs.filter(d => d.clase === 'herramienta'), r.j.v);

  /* «Para antes de tu sesión»: lo pendiente, con cuánto lleva, y el
     documento por firmar si lo hay. Lo de las herramientas va en su
     caja; aquí se cuenta igual. */
  const ts = todas.filter(t => t.tipo !== 'herramienta');
  DOCS = docs;
  const porFirmar = docs.filter(d => Number(d.requiere_firma) && Number(d.para_v) === Number(r.j.v)
                                     && (!d.firmado || faltanDatos(d)));
  /* Lo que es para consultar (la rueda) está siempre a mano, no pendiente. */
  const n = todas.filter(t => !(t.tipo === 'herramienta' && t.lectura && t.modo === 'vivo')).length + porFirmar.length;
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
    const re = !!d.firmado;
    const f = fila(d.titulo || 'Documento', !re ? 'Para leer y firmar'
      : soloFrase(d) ? 'Hemos corregido una frase del formulario: revísalo y vuelve a firmar (tus datos ya están puestos)'
      : 'Faltan tus datos: complétalos y vuelve a firmar');
    f.classList.add('firmar');
    const b = document.createElement('button');
    b.className = 'boton'; b.type = 'button'; b.textContent = !re ? 'Leer y firmar' : soloFrase(d) ? 'Revisar y firmar' : 'Completar y firmar';
    b.addEventListener('click', () => abrirFirma(d.id, d.titulo, re, soloFrase(d)));
    f.appendChild(b);
    ct.appendChild(f);
  });
  ts.forEach(t => {
    const esDeber = (t.tipo === 'deber');
    const esValorar = (t.tipo === 'objetivos');
    const pie = [t.preguntas ? 'unos ' + Math.max(1, Math.round(t.preguntas * 10 / 60)) + ' min' : null,
                 t.caduca ? 'antes del ' + fecha(t.caduca) : null].filter(Boolean).join(' · ');
    const f = fila(t.titulo, pie || null);
    const b = document.createElement('button');
    b.className = 'boton'; b.type = 'button';
    b.textContent = esDeber ? 'Ver' : esValorar ? 'Valorar' : 'Rellenar';
    b.addEventListener('click', () => esDeber ? abrirDeber(t) : esValorar ? abrirValorar(t) : abrirTarea(t.id));
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
  pintaHechas(hechas, r.j.historial, r.j.v);
  pintaObjetivos(r.j.objetivos, r.j.v);

  /* Lo trabajado (sesiones) y los documentos */
  let viejos = 0;
  [['sesion', 'sesiones', 'Aquí irá apareciendo lo que nos llevamos de cada sesión.'],
   ['documento', 'documentos', 'Todavía no hay documentos. Cuando te mande alguno, aparece aquí.']]
  .forEach(([clase, caja, cuandoNoHay]) => {
    const c = $(caja); c.innerHTML = '';
    const mios = docs.filter(d => (d.clase || 'documento') === clase);
    if (!mios.length) { c.appendChild(vacio(cuandoNoHay)); return; }
    mios.forEach(d => {
      /* Firmado con la copia ya vuelta a sellar: se abre por la copia
         aunque el documento original tenga la clave vieja. */
      const copiaOk = d.firmado && d.copia_id && Number(d.copia_v) === Number(r.j.v);
      const caduco = Number(d.para_v) !== Number(r.j.v) && !copiaOk;
      if (caduco) viejos++;
      const f = fila(d.titulo || 'Documento', fecha(d.creado));
      if (caduco) {
        /* Un botón que no abre es peor que no tener botón: la persona
           piensa que está roto y que ha perdido algo. Lo firmado sigue
           constando como firmado, con su fecha. */
        if (d.firmado) {
          const ok = document.createElement('span');
          ok.className = 'sello ok'; ok.textContent = 'firmado el ' + fecha(d.firmado);
          const pie = f.querySelector('.texto p');
          if (pie) pie.append(' ', ok); else f.appendChild(ok);
        }
        const e = document.createElement('span');
        e.className = 'sello'; e.textContent = 'actualizando';
        e.title = 'Tras el cambio de contraseña, en unos minutos vuelve a estar aquí.';
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
        if (faltanDatos(d)) {
          const b2 = document.createElement('button');
          b2.className = 'boton'; b2.type = 'button'; b2.textContent = soloFrase(d) ? 'Revisar y firmar' : 'Completar datos';
          b2.addEventListener('click', () => abrirFirma(d.id, d.titulo, true, soloFrase(d)));
          f.appendChild(b2);
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
/* Lo de antes de la cuenta: si llevaba meses viniendo, el Mac publica
   la lista de lo que hizo en papel o por correo (clase «historial»,
   sellada, sin puntuaciones) y aquí se mezcla con lo hecho en la
   cuenta, por fecha. Como si la cuenta hubiera existido desde el
   primer día. Formato: {completado: [{fecha, titulo, via?, tipo?}]},
   con tipo «firma» para lo firmado. */
async function pintaHechas(hs, meta, v) {
  const c = $('hechas');
  let todo = hs.map(h => ({ titulo: h.titulo, fecha: h.hecho, firma: false }));
  if (meta && Number(meta.para_v) === Number(v)) {
    try {
      const r = await api('abrir&id=' + encodeURIComponent(meta.id));
      const d = r.ok && r.j.cifrado ? abrirDelMac(r.j.cifrado, miPublica, privada) : null;
      (d && Array.isArray(d.completado) ? d.completado : []).forEach(x => {
        if (x && typeof x.titulo === 'string' && /^\d{4}-\d{2}-\d{2}/.test(String(x.fecha || ''))) {
          todo.push({ titulo: x.titulo.slice(0, 80), fecha: String(x.fecha), firma: x.tipo === 'firma' });
        }
      });
    } catch (_) {}
  }
  todo.sort((a, b) => String(b.fecha).localeCompare(String(a.fecha)));
  c.innerHTML = '';
  if (!todo.length) {
    c.appendChild(vacio('Aquí irá quedando lo que vayas haciendo, con su fecha.'));
    return;
  }
  todo.slice(0, 40).forEach(h => c.appendChild(
    fila(h.titulo, (h.firma ? 'Firmado el ' : 'Hecho el ') + fecha(h.fecha))));
}

/* --- Mis objetivos -----------------------------------------------------
   El Mac publica {actualizado, foco_actual, objetivos: [{id, titulo,
   para_que, como_sabremos, estado, desde, valoraciones: [{fecha, valor}],
   historial: [{fecha, texto}]}]}. Los activos, abiertos y con su línea
   de 0 a 10; los demás (conseguido, en pausa, sustituido), plegados y
   con su historia. Nada se borra: así se ven los cambios de foco. Sin
   etiquetas de «bien» o «mal». */
let OBJETIVOS = null;
const ESTADO_OB = { conseguido: 'Conseguido', en_pausa: 'En pausa', sustituido: 'Lo cambiamos por otro' };
async function pintaObjetivos(meta, v) {
  const caja = $('c-objetivos'), c = $('objetivos');
  OBJETIVOS = null;
  if (!meta || Number(meta.para_v) !== Number(v)) { caja.hidden = true; return; }
  let d = null;
  try {
    const r = await api('abrir&id=' + encodeURIComponent(meta.id));
    d = r.ok && r.j.cifrado ? abrirDelMac(r.j.cifrado, miPublica, privada) : null;
  } catch (_) {}
  const obs = d && Array.isArray(d.objetivos) ? d.objetivos.filter(o => o && o.id && o.titulo) : [];
  if (!obs.length) { caja.hidden = true; return; }
  OBJETIVOS = obs;
  c.innerHTML = '';
  $('ob-foco').hidden = !d.foco_actual;
  $('ob-foco').textContent = d.foco_actual ? 'Ahora estamos con: ' + d.foco_actual : '';
  const linea = (o) => {
    const vs = (o.valoraciones || []).filter(x => x && x.valor != null);
    const w = document.createElement('div'); w.className = 'ob-linea';
    w.appendChild(chispa({ puntos: vs.map(x => ({ valor: Number(x.valor) })), minimo: 0, maximo: 10 }));
    const u = vs[vs.length - 1];
    const t = document.createElement('span'); t.className = 'apunte';
    t.textContent = u ? 'Última: ' + u.valor + ' de 10 (' + fecha(u.fecha) + ')' : 'Todavía sin valorar';
    w.appendChild(t);
    return w;
  };
  const historia = (o) => {
    const ul = document.createElement('ul'); ul.className = 'ob-historia';
    (o.historial || []).forEach(h => { const li = document.createElement('li'); li.textContent = fecha(h.fecha) + ' · ' + h.texto; ul.appendChild(li); });
    return ul;
  };
  obs.filter(o => (o.estado || 'activo') === 'activo').forEach(o => {
    const a = document.createElement('article'); a.className = 'objetivo';
    const h = document.createElement('h3'); h.textContent = o.titulo; a.appendChild(h);
    if (o.para_que) { const p = document.createElement('p'); p.innerHTML = '<strong>Para qué:</strong> '; p.append(o.para_que); a.appendChild(p); }
    if (o.como_sabremos) { const p = document.createElement('p'); p.innerHTML = '<strong>Cómo lo sabremos:</strong> '; p.append(o.como_sabremos); a.appendChild(p); }
    a.appendChild(linea(o));
    if ((o.historial || []).length) a.appendChild(historia(o));
    c.appendChild(a);
  });
  const otros = obs.filter(o => (o.estado || 'activo') !== 'activo');
  if (otros.length) {
    const det = document.createElement('details'); det.className = 'ob-otros';
    const sm = document.createElement('summary'); sm.textContent = 'Otros objetivos (' + otros.length + ')'; det.appendChild(sm);
    otros.forEach(o => {
      const a = document.createElement('article'); a.className = 'objetivo plegado';
      const h = document.createElement('h3'); h.textContent = o.titulo + ' · ' + (ESTADO_OB[o.estado] || o.estado); a.appendChild(h);
      a.appendChild(linea(o));
      if ((o.historial || []).length) a.appendChild(historia(o));
      det.appendChild(a);
    });
    c.appendChild(det);
  }
  caja.hidden = false;
}

/* La valoración de la semana: un deslizador de 0 a 10 por objetivo. Los
   títulos salen del documento sellado; la tarea solo trae los ids. */
let VALORAR = null;
async function abrirValorar(t) {
  VALORAR = t;
  $('vo-titulo').textContent = t.titulo || '¿Cuánto has avanzado?';
  $('vo-estado').textContent = '';
  const c = $('vo-items'); c.innerHTML = 'Abriendo…';
  ver('v-valorar');
  const r = await api('tarea&id=' + encodeURIComponent(t.id));
  if (!r.ok) { c.textContent = 'No se ha podido traer.'; return; }
  const ids = (r.j.plantilla && r.j.plantilla.ids) || [];
  const por = {}; (OBJETIVOS || []).forEach(o => { por[o.id] = o; });
  c.innerHTML = '';
  ids.forEach(id => {
    const o = por[id];
    if (!o) return;
    const f = document.createElement('div'); f.className = 'campo valorar';
    const lb = document.createElement('label'); lb.setAttribute('for', 'vo-' + id); lb.textContent = o.titulo;
    const fila = document.createElement('div'); fila.className = 'deslizador';
    const inp = document.createElement('input'); inp.type = 'range'; inp.min = '0'; inp.max = '10'; inp.step = '1';
    inp.id = 'vo-' + id; inp.dataset.id = id;
    const prev = (o.valoraciones || []).filter(x => x && x.valor != null).pop();
    inp.value = prev ? String(prev.valor) : '5';
    const out = document.createElement('output'); out.htmlFor = inp.id; out.textContent = inp.value;
    inp.setAttribute('aria-valuetext', inp.value + ' de 10');
    inp.addEventListener('input', () => { out.textContent = inp.value; inp.setAttribute('aria-valuetext', inp.value + ' de 10'); inp.dataset.tocado = '1'; });
    fila.append(inp, out); f.append(lb, fila); c.appendChild(f);
  });
  if (!c.children.length) c.textContent = 'Todavía no tengo tus objetivos en esta cuenta. Lo vemos en sesión.';
}
$('vo-volver').addEventListener('click', panel);
$('vo-enviar').addEventListener('click', async () => {
  const valores = {};
  document.querySelectorAll('#vo-items input[type=range]').forEach(i => { valores[i.dataset.id] = Number(i.value); });
  if (!Object.keys(valores).length) { await panel(); return; }
  $('vo-enviar').disabled = true;
  try {
    const sobre = { v: 1, tipo: 'objetivos_valoracion', valores, completado_en: ahoraLocal(),
                    tarea: VALORAR.id, cod_web: miCod, origen: 'cuenta' };
    const r = await api('entregar', { id: VALORAR.id, cifrado: sellarHaciaElMac(sobre, macPublica) });
    if (!r.ok) { $('vo-estado').textContent = 'No se ha podido enviar. Inténtalo otra vez.'; return; }
    await panel();
    $('recibido').hidden = false; setTimeout(() => { $('recibido').hidden = true; }, 12000);
  } finally { $('vo-enviar').disabled = false; }
});

/* --- Pagos ------------------------------------------------------------
   Del servidor: [{inicio, importe, pagado, reembolsado, url_pago}]. Cada
   sesión con su botón «Pagar la sesión» (va a Stripe; no pide entrar) o
   «Pagada ✓». Si una sesión de las próximas 48 horas sigue sin pagar,
   aviso arriba con el mismo botón. */
function fechaSesion(iso) {
  const t = new Date(iso);
  const hora = t.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Madrid' });
  return DIAS[t.getDay()] + ' ' + t.getDate() + ' de ' + MESES_L[t.getMonth()] + ', ' + hora;
}
function pintaPagos(lista) {
  const caja = $('c-pago'), c = $('pago-lista'), ir = $('ir-pago');
  c.innerHTML = ''; ir.hidden = true; ir.onclick = null;
  const ahora = Date.now();
  /* Lo que importa: lo pendiente (de la más cercana a la más lejana) y lo pagado reciente. */
  const pend = lista.filter(p => !p.pagado && p.url_pago);
  const pag = lista.filter(p => p.pagado).slice(-4).reverse();
  caja.hidden = !(pend.length || pag.length);
  pend.concat(pag).forEach(p => {
    const f = document.createElement('div'); f.className = 'fila pago-fila';
    const t = document.createElement('div'); t.className = 'texto';
    const n = document.createElement('strong'); n.textContent = 'Sesión del ' + fechaSesion(p.inicio);
    const q = document.createElement('p'); q.className = 'apunte'; q.textContent = p.importe + ' €' + (p.reembolsado ? ' · devuelto' : '');
    t.append(n, q); f.appendChild(t);
    if (p.pagado) {
      const e = document.createElement('span'); e.className = 'sello ok'; e.textContent = p.reembolsado ? 'Devuelta' : 'Pagada ✓';
      f.appendChild(e);
    } else {
      const a = document.createElement('button'); a.type = 'button'; a.className = 'boton'; a.textContent = 'Pagar la sesión';
      a.addEventListener('click', () => { location.href = p.url_pago; });
      f.appendChild(a);
    }
    c.appendChild(f);
  });
  const cerca = pend.find(p => { const d = new Date(p.inicio).getTime() - ahora; return d < 48 * 3600e3 && d > -3600e3; });
  if (cerca) {
    $('ir-pago-txt').textContent = 'De tu sesión del ' + fechaSesion(cerca.inicio) + '. Págala en un minuto.';
    ir.hidden = false;
    ir.onclick = () => { location.href = cerca.url_pago; };
    caja.classList.add('falta');
  } else caja.classList.remove('falta');
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
  try { const x = abrirDelMac(r.j.cifrado, miPublica, privada);
        if (!x) return null;
        return (x.texto || '') + (x.adjunto ? (x.texto ? '\n' : '') + '📎 ' + x.adjunto : '') || null; }
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

/* Adjuntar un archivo al mensaje (17-10). Va sellado a la clave del Mac
   como el texto: el servidor transporta y no lee. Las fotos se reducen
   antes (una foto de móvil son 4 MB y para leerla sobran 2000 píxeles). */
async function reducirFoto(f) {
  const bm = await createImageBitmap(f);
  const k = Math.min(1, 2000 / Math.max(bm.width, bm.height));
  const cv = document.createElement('canvas');
  cv.width = Math.round(bm.width * k); cv.height = Math.round(bm.height * k);
  cv.getContext('2d').drawImage(bm, 0, 0, cv.width, cv.height);
  return await new Promise((ok, mal) => cv.toBlob(b => b ? ok(b) : mal(new Error('no')), 'image/jpeg', 0.85));
}
function aBase64(blob) {
  return new Promise((ok, mal) => {
    const r = new FileReader();
    r.onload = () => ok(String(r.result).split(',')[1] || '');
    r.onerror = () => mal(new Error('No he podido leer el archivo.'));
    r.readAsDataURL(blob);
  });
}
$('m-archivo').addEventListener('change', () => {
  const f = $('m-archivo').files && $('m-archivo').files[0];
  $('m-archivo-nombre').textContent = f ? '📎 ' + f.name : 'Foto, PDF o documento, hasta 15 MB. Va cifrado.';
});
$('m-enviar').addEventListener('click', async () => {
  const texto = $('m-texto').value.trim();
  const f = $('m-archivo').files && $('m-archivo').files[0];
  if (!texto && !f) return;
  if (suenaARiesgo(texto)) $('m-auxilio').hidden = false;
  $('m-enviar').disabled = true;
  $('m-estado').textContent = f ? 'Preparando el archivo…' : '';
  try {
    let adjunto = null;
    if (f) {
      let blob = f, mime = f.type || 'application/octet-stream', nombre = (f.name || 'archivo').slice(0, 100);
      if (/^image\//.test(mime) && !/hei[cf]/i.test(mime)) {
        try { blob = await reducirFoto(f); mime = 'image/jpeg'; nombre = nombre.replace(/\.[^.]+$/, '') + '.jpg'; } catch (_) { blob = f; }
      }
      if (blob.size > 15 * 1024 * 1024) { $('m-estado').textContent = 'El archivo pasa de 15 MB. Prueba con una foto o una captura.'; return; }
      adjunto = { nombre, mime, tamano: blob.size, datos: await aBase64(blob) };
      $('m-estado').textContent = 'Enviando…';
    }
    const escrito_en = ahoraLocal();
    const cifrado = sellarHaciaElMac(Object.assign({ v: 1, tipo: 'mensaje', texto,
                                       escrito_en, origen: 'cuenta', cod_web: miCod },
                                       adjunto ? { adjuntos: [adjunto] } : {}),
                                     macPublica);
    /* Y una copia a su propia clave, para poder releerlo (del archivo,
       solo el nombre). */
    const copia = sellarHaciaElMac({ v: 1, tipo: 'mi_mensaje', texto, escrito_en,
                                     adjunto: adjunto ? adjunto.nombre : null }, miPublica);
    const r = await api('escribir', { cifrado, copia, adjunto: !!adjunto });
    if (!r.ok) { $('m-estado').textContent = (r.j && r.j.error) ? 'No se ha podido enviar: ' + r.j.error + '.' : 'No se ha podido enviar.'; return; }
    $('m-texto').value = '';
    $('m-archivo').value = '';
    $('m-archivo-nombre').textContent = 'Foto, PDF o documento, hasta 15 MB. Va cifrado.';
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

/* --- Firmar ----------------------------------------------------------
   Dos maneras. Un documento cualquiera se firma con el nombre, como
   siempre. El CONSENTIMIENTO INFORMADO (lo dice su título) pide además
   los datos de identificación (14-10: el centro los necesita en la
   historia clínica), las casillas de lo que se confirma y la contraseña
   otra vez. Todo va sellado al Mac: el servidor no ve ni el DNI ni la
   dirección. Quien firmó el consentimiento antes de esto, solo con el
   nombre, lo ve otra vez en pendientes para completarlo (firma_v < 2). */
let FIRMA = null;
const esConsentimiento = (titulo) => /consentimiento/i.test(String(titulo || ''));
/* firma_v: 1 = solo el nombre; 2 = con datos, pero con la casilla de la
   videollamada que decía «no se graban» (contradecía el apartado 4 del
   texto, que autoriza el audio: corregida el 14-10); 3 = la buena. */
const FIRMA_V = 3;
/* Volver a firmar solo si faltan los datos (v1). Las v2 valen: Adrián no
   quiere molestar a nadie por la frase corregida (14-10). */
const faltanDatos = (d) => !!d.firmado && esConsentimiento(d.titulo) && Number(d.firma_v || 1) < 2;
const soloFrase = (d) => false;

/* Las dos primeras cifras del código postal → provincia. */
const PROVINCIAS = ['Álava','Albacete','Alicante','Almería','Ávila','Badajoz','Illes Balears','Barcelona','Burgos',
  'Cáceres','Cádiz','Castellón','Ciudad Real','Córdoba','A Coruña','Cuenca','Girona','Granada','Guadalajara',
  'Gipuzkoa','Huelva','Huesca','Jaén','León','Lleida','La Rioja','Lugo','Madrid','Málaga','Murcia','Navarra',
  'Ourense','Asturias','Palencia','Las Palmas','Pontevedra','Salamanca','Santa Cruz de Tenerife','Cantabria',
  'Segovia','Sevilla','Soria','Tarragona','Teruel','Toledo','Valencia','Valladolid','Bizkaia','Zamora',
  'Zaragoza','Ceuta','Melilla'];

const CI = ['ci-nombre','ci-apellidos','ci-doc-tipo','ci-doc','ci-nac','ci-tel','ci-dir','ci-cp','ci-loc','ci-prov',
            'ci-em-nombre','ci-em-rel','ci-em-tel'];
/* Sin casillas que marcar (14-10, Adrián): las declaraciones van en una
   lista encima de la firma y las hace la propia firma. Al Mac le llegan
   igual, todas a true, con su texto en primera persona y
   casillas_modo: 'declaracion_firmada'. Sin opcionales: un
   consentimiento RGPD no puede venir marcado de antemano (RGPD,
   considerando 32; TJUE C-673/17), así que esos usos se quitan. */
const DECLARA_1P = {
  atencion: 'He leído este documento, he podido preguntar mis dudas y consiento la atención psicológica.',
  online: 'Sé que las sesiones pueden ser por videollamada (Google Meet), en las condiciones que explica el documento, y que no puedo grabarlas por mi cuenta.',
  practicas: 'Sé que me atiende Adrián Cuñat Andrés, psicólogo colegiado nº CM03836, cursando el Máster en Psicología General Sanitaria, en prácticas supervisadas en el centro sanitario Neurococos; que mi caso se revisa con un profesional colegiado del centro, sujeto a secreto profesional; y que puedo pedir que me atienda otro profesional del centro.',
  mayor_y_residente: 'Soy mayor de 18 años y resido en España.'
};

function documentoValido(tipo, n) {
  const L = 'TRWAGMYFPDXBNJZSQVHLCKE';
  if (tipo === 'DNI') { const m = /^(\d{8})([A-Z])$/.exec(n); return !!m && L[Number(m[1]) % 23] === m[2]; }
  if (tipo === 'NIE') { const m = /^([XYZ])(\d{7})([A-Z])$/.exec(n);
    return !!m && L[Number('XYZ'.indexOf(m[1]) + m[2]) % 23] === m[3]; }
  return /^[A-Z0-9]{5,20}$/.test(n);
}
const sinMarcas = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-zñ ]/g, ' ').replace(/\s+/g, ' ').trim();
function edad(iso) {
  const n = new Date(iso + 'T12:00:00'), h = new Date();
  let e = h.getFullYear() - n.getFullYear();
  if (h.getMonth() < n.getMonth() || (h.getMonth() === n.getMonth() && h.getDate() < n.getDate())) e--;
  return e;
}
function marcaMal(id, msg) {
  const e = $(id);
  e.setAttribute('aria-invalid', 'true');
  let p = e.parentElement.querySelector('.ci-mal');
  if (!p) { p = document.createElement('p'); p.className = 'ci-mal'; e.parentElement.appendChild(p); }
  p.textContent = msg;
  return id;
}
function limpiaMal() {
  document.querySelectorAll('#s-datos [aria-invalid]').forEach(e => e.removeAttribute('aria-invalid'));
  document.querySelectorAll('#s-datos .ci-mal').forEach(e => e.remove());
}

/* Lee y comprueba el formulario. Devuelve {datos, casillas, opcionales}
   o null (y deja marcado lo que falla, con el foco en el primero). */
function leeDatos() {
  limpiaMal();
  const v = (id) => $(id).value.trim();
  const malos = [];
  if (v('ci-nombre').length < 2) malos.push(marcaMal('ci-nombre', 'Escribe tu nombre.'));
  if (v('ci-apellidos').length < 2) malos.push(marcaMal('ci-apellidos', 'Escribe tus apellidos.'));
  const tipo = $('ci-doc-tipo').value, num = v('ci-doc').toUpperCase().replace(/[\s.-]/g, '');
  if (!documentoValido(tipo, num)) malos.push(marcaMal('ci-doc', tipo === 'Pasaporte'
    ? 'Revisa el número del pasaporte.' : 'Revisa el número: la letra no cuadra con los números.'));
  const nac = $('ci-nac').value;
  if (!/^\d{4}-\d{2}-\d{2}$/.test(nac) || edad(nac) > 110 || edad(nac) < 0) malos.push(marcaMal('ci-nac', 'Pon tu fecha de nacimiento.'));
  else if (edad(nac) < 18) malos.push(marcaMal('ci-nac', 'Este servicio es para mayores de 18 años. Escríbeme a hola@adricamente.com y te digo a quién acudir.'));
  const tel = v('ci-tel');
  if ((tel.match(/\d/g) || []).length < 9 || !/^[+\d\s().-]+$/.test(tel)) malos.push(marcaMal('ci-tel', 'Revisa el teléfono.'));
  if (v('ci-dir').length < 5) malos.push(marcaMal('ci-dir', 'Escribe la calle y el número.'));
  const cp = v('ci-cp');
  if (!/^(0[1-9]|[1-4]\d|5[0-2])\d{3}$/.test(cp)) malos.push(marcaMal('ci-cp', 'Un código postal de España, de 5 cifras.'));
  if (v('ci-loc').length < 2) malos.push(marcaMal('ci-loc', 'Escribe la localidad.'));
  if (v('ci-prov').length < 2) malos.push(marcaMal('ci-prov', 'Escribe la provincia.'));
  const em = { nombre: v('ci-em-nombre'), relacion: v('ci-em-rel'), telefono: v('ci-em-tel') };
  const hayEm = em.nombre || em.relacion || em.telefono;
  if (hayEm && (!em.nombre || (em.telefono.match(/\d/g) || []).length < 9))
    malos.push(marcaMal(em.nombre ? 'ci-em-tel' : 'ci-em-nombre', 'Si pones a alguien, con su nombre y su teléfono.'));
  const casillas = {};
  Object.keys(DECLARA_1P).forEach(k => { casillas[k] = true; });
  const opcionales = null;
  if (malos.length) { $(malos[0]).focus(); return null; }
  return {
    datos: { nombre: v('ci-nombre'), apellidos: v('ci-apellidos'), documento_tipo: tipo, documento_numero: num,
             fecha_nacimiento: nac, telefono: tel,
             domicilio: { direccion: v('ci-dir'), codigo_postal: cp, localidad: v('ci-loc'), provincia: v('ci-prov'), pais: 'España' },
             correo: miCorreo || null, contacto_emergencia: hayEm ? em : null },
    casillas, opcionales };
}

/* Si ya firmó antes con datos (otra versión, otro documento), se le
   rellenan desde SU copia firmada, que solo abre su llave. */
async function rellenaDeAntes() {
  for (const d of DOCS) {
    if (!d.copia_id || Number(d.copia_v) !== Number(miV)) continue;
    try {
      const r = await api('abrir&id=' + encodeURIComponent(d.copia_id));
      const c = r.ok && r.j.cifrado ? abrirDelMac(r.j.cifrado, miPublica, privada) : null;
      const x = c && c.firma && c.firma.datos;
      if (!x) continue;
      const pon = (id, val) => { if (val && !$(id).value) $(id).value = val; };
      pon('ci-nombre', x.nombre); pon('ci-apellidos', x.apellidos); pon('ci-doc', x.documento_numero);
      if (x.documento_tipo) $('ci-doc-tipo').value = x.documento_tipo;
      pon('ci-nac', x.fecha_nacimiento); pon('ci-tel', x.telefono);
      const dm = x.domicilio || {};
      pon('ci-dir', dm.direccion); pon('ci-cp', dm.codigo_postal); pon('ci-loc', dm.localidad); pon('ci-prov', dm.provincia);
      const e = x.contacto_emergencia || {};
      pon('ci-em-nombre', e.nombre); pon('ci-em-rel', e.relacion); pon('ci-em-tel', e.telefono);
      return true;
    } catch (_) { /* una copia con una llave anterior: se sigue buscando */ }
  }
  return false;
}

$('ci-cp').addEventListener('input', () => {
  const cp = $('ci-cp').value.trim();
  const n = Number(cp.slice(0, 2));
  if (/^\d{5}$/.test(cp) && n >= 1 && n <= 52) $('ci-prov').value = PROVINCIAS[n - 1];
});
$('ci-doc-tipo').addEventListener('change', () => {
  $('ci-doc-p').textContent = { DNI: 'Con la letra. Por ejemplo, 12345678Z.', NIE: 'Con las dos letras. Por ejemplo, X1234567L.',
                                Pasaporte: 'Solo si no tienes DNI ni NIE.' }[$('ci-doc-tipo').value];
});
['ci-nombre', 'ci-apellidos'].forEach(id => $(id).addEventListener('input', () => {
  if (!FIRMA || !FIRMA.consentimiento || FIRMA.nombreTocado) return;
  $('s-nombre').value = ($('ci-nombre').value.trim() + ' ' + $('ci-apellidos').value.trim()).trim();
}));
$('s-nombre').addEventListener('input', () => { if (FIRMA) FIRMA.nombreTocado = true; });

async function abrirFirma(id, titulo, refirma = false, frase = false) {
  const consentimiento = esConsentimiento(titulo);
  FIRMA = { id, titulo, texto: null, consentimiento, refirma, nombreTocado: false };
  $('s-firmar').disabled = true;
  $('s-titulo').textContent = titulo || 'Documento';
  $('s-cuerpo').textContent = 'Abriendo…';
  $('s-estado').textContent = '';
  $('s-nombre').value = '';
  $('s-clave').value = '';
  $('s-refirma').hidden = !refirma;
  $('s-refirma-t').textContent = frase
    ? 'Ya lo firmaste con tus datos, pero una frase del formulario estaba mal: la de la videollamada decía que las sesiones no se graban, y el documento explica en qué condiciones se registra el audio. Ya está corregida. Revisa el texto y las casillas y vuelve a firmar: tus datos ya están puestos. Tu firma anterior se conserva.'
    : 'Ya lo firmaste, pero faltaban tus datos de identificación. Revisa el texto, completa el formulario y vuelve a firmarlo. Tu firma anterior se conserva.';
  $('s-datos').hidden = !consentimiento;
  $('s-clave-c').hidden = !consentimiento;
  $('s-firma-a').textContent = consentimiento
    ? 'Al firmar haces las declaraciones de arriba. Escribe tu nombre y apellidos como en tu documento de identidad y tu contraseña. Queda registrada la fecha y la hora, y te llega un correo de confirmación.'
    : 'Para firmarlo, escribe tu nombre y apellidos tal como aparecen en tu DNI. Queda registrada la fecha y la hora.';
  if (consentimiento) {
    $('s-datos').reset(); limpiaMal();
    $('s-firmar').textContent = refirma ? 'Firmar otra vez con mis datos' : 'Firmar el consentimiento';
  } else {
    $('s-firmar').textContent = 'Firmar';
  }
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
  if (consentimiento && await rellenaDeAntes() && !FIRMA.nombreTocado) {
    $('s-nombre').value = ($('ci-nombre').value.trim() + ' ' + $('ci-apellidos').value.trim()).trim();
  }
}
$('s-volver').addEventListener('click', panel);
$('s-firmar').addEventListener('click', async () => {
  const nombre = $('s-nombre').value.trim().replace(/\s+/g, ' ');
  let extra = null;
  if (FIRMA.consentimiento) {
    extra = leeDatos();
    if (!extra) { $('s-estado').textContent = 'Revisa lo que está marcado en rojo.'; return; }
    if (sinMarcas(nombre) !== sinMarcas(extra.datos.nombre + ' ' + extra.datos.apellidos)) {
      $('s-estado').textContent = 'La firma tiene que ser tu nombre y tus apellidos, igual que arriba.';
      $('s-nombre').focus(); return;
    }
    if (!$('s-clave').value) { $('s-estado').textContent = 'Escribe tu contraseña para confirmar que eres tú.'; $('s-clave').focus(); return; }
  } else if (nombre.length < 5) {
    $('s-estado').textContent = 'Escribe tu nombre y apellidos completos.';
    return;
  }
  if (!FIRMA.texto) return;
  $('s-firmar').disabled = true;
  try {
    /* La huella del texto EXACTO que tenía delante: así la firma dice
       qué se firmó, no solo cuándo. */
    const h = await huellaDe(FIRMA.texto);
    /* El consentimiento: la contraseña otra vez. El servidor la comprueba
       como al entrar y devuelve un permiso de diez minutos para esta
       firma, con su hora, la IP y el navegador que ve, que van DENTRO
       del sobre sellado. */
    let servidor = null, permiso = null;
    if (FIRMA.consentimiento) {
      $('s-estado').textContent = 'Comprobando…';
      try {
        const s = await api('sal', { correo: miCorreo });
        const otra = await entrarConServidor($('s-clave').value, s.j.sal, async (auth) => {
          const c = await api('confirmar_firma', { auth });
          if (!c.ok) throw new Error(c.j.error || 'no');
          servidor = c.j.servidor; permiso = c.j.permiso;
          return c.j.envuelta;
        });
        olvidarPrivada(otra);
      } catch (_) {
        $('s-estado').textContent = 'La contraseña no es correcta.'; $('s-clave').focus(); return;
      } finally { $('s-clave').value = ''; }
    }
    const firmado_en = ahoraLocal();
    const firma = { nombre, firmado_en, documento_sha256: h };
    if (extra) Object.assign(firma, { datos: extra.datos, casillas: extra.casillas, opcionales: extra.opcionales });
    /* La firma va sellada al Mac: él tiene la prueba completa de qué
       se firmó, con qué datos y cuándo. El servidor solo apunta que se
       firmó, la hora y la huella, que es lo que puede saber sin leer nada. */
    /* Lo que decía cada casilla, tal cual: la prueba es lo que leyó, no
       lo que pone hoy en el código. */
    const casillas_texto = extra ? Object.assign({}, DECLARA_1P) : {};
    if (extra) { firma.casillas_texto = casillas_texto; firma.casillas_modo = 'declaracion_firmada'; }
    const cifrado = sellarHaciaElMac(Object.assign({ v: extra ? FIRMA_V : 1, tipo: 'firma', documento: FIRMA.id,
                                       titulo: FIRMA.titulo, documento_sha256: h, nombre,
                                       firmado_en, origen: 'cuenta', cod_web: miCod },
                                     extra ? { datos: extra.datos, casillas: extra.casillas, opcionales: extra.opcionales, casillas_texto,
                                               casillas_modo: 'declaracion_firmada',
                                               verificacion: 'contraseña', servidor, refirma: FIRMA.refirma } : {}),
                                     macPublica);
    /* Y su copia: el texto, su firma y sus datos, sellados a SU clave.
       Se queda en «Documentos», legible y para imprimir. */
    const copia = sellarHaciaElMac({ v: 1, tipo: 'copia_firmada', titulo: FIRMA.titulo,
                                     texto: FIRMA.texto, firma },
                                   miPublica);
    const r = await api('firmar', { id: FIRMA.id, cifrado, copia, huella: h,
                                    version: extra ? FIRMA_V : 1, permiso, refirma: FIRMA.refirma });
    if (!r.ok) { $('s-estado').textContent = r.j.error || 'No se ha podido firmar.'; return; }
    await panel();
    $('panel-estado').textContent = FIRMA.consentimiento ? 'Firmado. Te llega un correo de confirmación.' : 'Firmado. Gracias.';
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
      const cuando = f.firmado_en || doc.firmado;
      const x = f.datos;
      $('doc-firma-t').replaceChildren();
      /* Como en papel: los datos de quien firma en una tabla, lo que
         marcó, y la firma en su recuadro con la fecha y la hora. */
      const caja = $('doc-firma-d');
      caja.replaceChildren();
      caja.hidden = !x;
      const nuevo = (tag, txt, cls) => { const e = document.createElement(tag); if (txt != null) e.textContent = txt; if (cls) e.className = cls; return e; };
      if (x) {
        const dm = x.domicilio || {}, em = x.contacto_emergencia;
        caja.appendChild(nuevo('h3', 'Datos de la persona firmante', 'firma-st'));
        const t = nuevo('table', null, 'firma-datos');
        [['Nombre y apellidos', (x.nombre || '') + ' ' + (x.apellidos || '')],
         [x.documento_tipo || 'Documento', x.documento_numero],
         ['Fecha de nacimiento', String(x.fecha_nacimiento || '').split('-').reverse().join('/')],
         ['Domicilio', [dm.direccion, [dm.codigo_postal, dm.localidad].filter(Boolean).join(' '), dm.provincia].filter(Boolean).join(', ')],
         ['Teléfono', x.telefono], ['Correo electrónico', x.correo],
         ['Contacto en una emergencia', em ? em.nombre + (em.relacion ? ' (' + em.relacion + ')' : '') + ' · ' + em.telefono : 'No indicado']
        ].forEach(([k, v]) => { const tr = nuevo('tr'); tr.append(nuevo('th', k), nuevo('td', v || '—')); t.appendChild(tr); });
        caja.appendChild(t);
        const ETQ = { atencion: 'Consiento la atención psicológica.', online: 'Sesiones por videollamada; no puedo grabarlas por mi cuenta.',
          practicas: 'Me atiende un psicólogo en prácticas supervisadas en Neurococos.', mayor_y_residente: 'Soy mayor de 18 años y resido en España.',
          resultados_anonimos: 'Uso anónimo de mis puntuaciones', memoria_practicas: 'Mi caso, anonimizado, en la memoria de prácticas' };
        const decl = f.casillas_modo === 'declaracion_firmada';
        if (decl) caja.appendChild(nuevo('p', 'Al firmar declaró que:', 'firma-et'));
        const ul = nuevo('ul', null, 'firma-casillas');
        Object.entries(f.casillas || {}).forEach(([k, v]) => ul.appendChild(nuevo('li', decl ? '• ' + ((f.casillas_texto || {})[k] || ETQ[k] || k)
                                                                                             : (v ? '☑ ' : '☐ ') + (ETQ[k] || k))));
        Object.entries(f.opcionales || {}).forEach(([k, v]) => ul.appendChild(nuevo('li', (v ? '☑ ' : '☐ ') + (ETQ[k] || k) + (v ? ': sí' : ': no'))));
        caja.appendChild(ul);
      }
      const recuadro = nuevo('div', null, 'firma-recuadro');
      recuadro.appendChild(nuevo('p', 'Firmado electrónicamente por', 'firma-et'));
      recuadro.appendChild(nuevo('p', f.nombre || '—', 'firma-nombre'));
      recuadro.appendChild(nuevo('p', 'Firmado' + (f.nombre ? ' por ' + f.nombre : '') + ' el ' + fecha(cuando) + ' a las ' +
        String(cuando).slice(11, 16) + '.' + (x ? ' ' + x.documento_tipo + ': ' + x.documento_numero + '.' : '')));
      $('doc-firma-t').appendChild(recuadro);
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
/* El logo de arriba lleva a Inicio (17-10), desde cualquier pantalla y
   sin recargar: recargar obligaría a volver a poner la contraseña,
   porque la llave solo vive en la memoria de esta pestaña. */
document.querySelectorAll('.barra .marca').forEach(m => m.addEventListener('click', ev => {
  ev.preventDefault();
  if (!privada || $('v-entrar').hidden === false) return;
  if (URL_PDF) { URL.revokeObjectURL(URL_PDF); URL_PDF = null; }
  if (location.hash !== '#inicio') history.replaceState(null, '', '#inicio');
  panel();
}));
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
  /* 21-10: una cabecera que es solo el nombre de la prueba («Cuestionario
     de Preocupación PSWQ-11») no es una consigna: no ocupa su sitio. */
  const esTitulo = (P.instrumento && cab.includes(P.instrumento)) || cab === TAREA.titulo;
  const cabVale = cab.split(/\s+/).length >= 3 && !repetida && !esTitulo;
  $('t-cabecera').textContent = cabVale ? cab : '';
  $('t-cabecera').hidden = !cabVale;
  $('t-enunciado').hidden = !(cabVale || P.instrucciones);
  $('t-pista').textContent = 'Si alguna no sabes contestarla, déjala en blanco y ya está.';
  $('t-pista').hidden = !$('t-enunciado').hidden;
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
  const NUM = new Set(P.items_numericos || []);
  const OPC = new Set(P.items_opcionales || []);
  const COND = P.items_condicionales || {};
  P.items.forEach((texto, i) => {
    const n = i + 1;
    if (inicioBloque[n]) {
      /* Un bloque puede traer una explicación larga con ejemplos (DOCS):
         la primera línea es el título; el resto va debajo, entero y con
         sus saltos. Sin ella, las preguntas de ese bloque no se
         entienden. */
      const [primera, ...resto] = String(inicioBloque[n]).split('\n');
      const h = document.createElement('h2');
      h.className = 'bloque-titulo';
      h.textContent = primera;
      cont.appendChild(h);
      if (resto.join('').trim()) {
        const d = document.createElement('p');
        d.className = 'bloque-texto';
        d.textContent = resto.join('\n').trim();
        cont.appendChild(d);
      }
    }
    /* Cada siete preguntas, el enunciado otra vez, en pequeño: en un
       cuestionario largo, a la pregunta quince ya no se ve el de arriba. */
    if (cabVale && !(P.bloques || []).length && n > 1 && (n - 1) % 7 === 0 && cab.length <= 220) {
      const rc = document.createElement('p');
      rc.className = 'recuerda';
      rc.textContent = cab;
      cont.appendChild(rc);
    }
    /* Rótulos que van antes de un ítem (ITQ: 7, 10 y 16). */
    if (P.secciones && P.secciones[String(n)]) {
      const h = document.createElement('p');
      h.className = 'seccion-titulo';
      h.textContent = P.secciones[String(n)];
      cont.appendChild(h);
    }
    const caja = document.createElement('div');
    caja.className = 'item';
    /* «En caso afirmativo…»: solo se pregunta si la anterior lo abre. */
    if (COND[String(n)]) {
      caja.dataset.cond = COND[String(n)].item + ':' + COND[String(n)].valor;
      caja.hidden = true;
    }
    const fs = document.createElement('fieldset');
    const lg = document.createElement('legend');
    lg.textContent = n + '. ' + texto + (OPC.has(n) ? ' (opcional)' : '');
    fs.appendChild(lg);
    const ops = document.createElement('div');
    ops.className = 'opciones';
    if (NUM.has(n)) {
      /* Respuesta abierta: un número entero (¿cuántas veces?). */
      const inp = document.createElement('input');
      inp.type = 'number'; inp.min = '0'; inp.step = '1'; inp.inputMode = 'numeric';
      inp.name = 'i' + n; inp.className = 'numero';
      inp.setAttribute('aria-label', 'Número de veces');
      ops.appendChild(inp);
      fs.appendChild(ops); caja.appendChild(fs); cont.appendChild(caja);
      return;
    }
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

/* Un ítem condicional está cerrado si la pregunta que lo abre no tiene
   la respuesta que lo abre. Cerrado no se pregunta, no falta y no va
   en el sobre. */
function cerrado(n) {
  const c = (TAREA.plantilla.items_condicionales || {})[String(n)];
  if (!c) return false;
  const m = document.querySelector('input[name=i' + c.item + ']:checked');
  return !(m && Number(m.value) === Number(c.valor));
}
function respuestas() {
  const out = [];
  for (let n = 1; n <= TAREA.plantilla.items.length; n++) {
    if (cerrado(n)) continue;
    const m = document.querySelector('input[name=i' + n + ']:checked');
    if (m) { out.push({ item: n, valor: Number(m.value) }); continue; }
    const num = document.querySelector('input.numero[name=i' + n + ']');
    if (num && num.value !== '' && Number(num.value) >= 0) out.push({ item: n, valor: Math.round(Number(num.value)) });
  }
  return out;
}
function noContestados() {
  const hechas = new Set(respuestas().map(r => r.item));
  const opc = new Set(TAREA.plantilla.items_opcionales || []);
  const out = [];
  for (let n = 1; n <= TAREA.plantilla.items.length; n++) {
    if (!hechas.has(n) && !cerrado(n) && !opc.has(n)) out.push(n);
  }
  return out;
}
function noAplica() {
  const out = [];
  for (let n = 1; n <= TAREA.plantilla.items.length; n++) if (cerrado(n)) out.push(n);
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
  /* Abrir o cerrar lo condicional según lo que se va contestando. */
  document.querySelectorAll('#t-items .item[data-cond]').forEach(c => {
    const n = Number(c.querySelector('[name]').name.slice(1));
    c.hidden = cerrado(n);
  });
  const hechas = respuestas().length;
  const total = hechas + noContestados().length;
  $('t-barra').setAttribute('aria-valuenow', String(hechas));
  $('t-barra').setAttribute('aria-valuemax', String(total));
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
      if (el) { el.checked = true; return; }
      const num = document.querySelector('input.numero[name=i' + r.item + ']');
      if (num) num.value = String(r.valor);
    });
  } catch (_) {}
}

$('t-items').addEventListener('change', () => { guardar(); repintar(); });
$('t-items').addEventListener('input', (ev) => { if (ev.target.classList.contains('numero')) { guardar(); repintar(); } });
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
    no_aplica: noAplica(),
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

function pintaHerramientas(pendientes, vivas, copias, v) {
  const c = $('herramientas'); c.innerHTML = '';
  /* Las copias enviadas de algo que ahora vive (el plan de seguridad
     de antes del 17-10) no salen aparte: están dentro. */
  const vivos = new Set(pendientes.concat(vivas).filter(t => t.modo !== 'una').map(t => t.titulo));
  copias = copias.filter(d => !vivos.has(d.titulo));
  $('c-herramientas').hidden = !(pendientes.length || vivas.length || copias.length);
  const pie = (t) => {
    if (t.modo === 'registro') return t.hoy ? 'Registro · hoy: apuntado ✓'
      : (t.entradas ? 'Registro · último apunte: ' + fecha(t.ultima) : 'Registro · aún sin apuntes');
    if (t.modo === 'vivo' && t.lectura) return 'Siempre a mano · para consultar';
    if (t.modo === 'vivo') return t.actualizado ? 'Siempre a mano · cambiado el ' + fecha(t.actualizado) : 'Siempre a mano · por empezar';
    return t.caduca ? ('antes del ' + fecha(t.caduca)) : 'Pendiente';
  };
  pendientes.concat(vivas).forEach(t => {
    const f = fila(t.titulo, pie(t));
    const b = document.createElement('button');
    b.className = 'boton'; b.type = 'button';
    b.textContent = (t.modo === 'registro' && !t.hoy) ? 'Apuntar' : 'Abrir';
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
  /* «Lo que acordamos en sesión» va el primero y destacado: es lo que
     más se lee (tareas para casa, 14-10). */
  const esAcordado = (b) => b.t === 'nota' && /^\s*lo que acordamos/i.test(b.negrita || '');
  const bloques = (def.bloques || []).filter(esAcordado).concat((def.bloques || []).filter(b => !esAcordado(b)));
  bloques.forEach(b => {
    if (esAcordado(b)) {
      raiz.appendChild(parrafo(b, 'acordado-h'));
    } else if (b.t === 'campos') {
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
      if (!lectura && b.id) envoltura.dataset.tabla = b.id;
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
  if (HERR.modo !== 'registro') (HERR.def.campos || []).forEach(c => { out[c.id] = ''; });
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
  const pintado = pintaBloques(def, valores, soloLeer ? 'texto' : 'form', false);
  if (def.rueda) {
    /* La rueda interactiva; los bloques, como versión en texto debajo. */
    const zona = el('div', 'rueda-zona'); cuerpo.appendChild(zona);
    cargaRueda().then(() => montaRueda(zona, def.rueda, null)).catch(() => {});
    const d = el('details', 'rueda-mas'); d.appendChild(el('summary', null, 'La rueda en texto'));
    d.appendChild(pintado); cuerpo.appendChild(d);
  } else {
    cuerpo.appendChild(pintado);
    if (!soloLeer) ponAyudaRueda(def, cuerpo);
  }
  const lectura = def.tipo === 'lectura';
  $('h-enviar').textContent = 'Enviar';
  $('h-pdf').textContent = 'Descargar en PDF'; $('h-pdf').hidden = false; $('h-todo').hidden = true;
  $('h-enviar').hidden = soloLeer || lectura;
  $('h-guardar').hidden = soloLeer || lectura || !HERR || !HERR.tarea;
  $('h-leido').hidden = !(lectura && !soloLeer) || !!def.rueda || (HERR && HERR.modo === 'vivo');
  $('h-estado').textContent = (soloLeer || lectura) ? '' :
    'Puedes rellenarla poco a poco: «Guardar y seguir otro día» la guarda cifrada y la retomas desde cualquier dispositivo. Cuando esté completa, «Enviar».';
  ver('v-herr');
}

async function abrirHerramienta(id) {
  const r = await api('tarea&id=' + encodeURIComponent(id));
  if (!r.ok || r.j.tipo !== 'herramienta') { await panel(); return; }
  /* Sellada (17-10): la definición viaja cifrada a SU clave. */
  let def = r.j.plantilla;
  if (r.j.cifrado) {
    if (Number(r.j.para_v) !== Number(miV)) {
      $('panel-estado').textContent = 'Esta tarea se está actualizando tras el cambio de contraseña. En unos minutos la tienes.';
      return;
    }
    try { def = abrirDelMac(r.j.cifrado, miPublica, privada); }
    catch (_) { $('panel-estado').textContent = 'No se ha podido abrir esta tarea. Te la vuelvo a mandar.'; return; }
  }
  /* 17-10: lo que vive (un documento que se edita siempre, un registro). */
  if (r.j.modo === 'vivo' || r.j.modo === 'registro') { await abrirViva(r, def); return; }
  HERR = { tarea: r.j.id, def, soloLeer: false };
  /* Lo guardado a medias: lo más reciente entre este dispositivo y lo
     guardado en la cuenta (sellado a su clave). */
  let local = null, remoto = null;
  try { local = JSON.parse(localStorage.getItem(claveHerr()) || 'null'); } catch (_) {}
  if (r.j.borrador && Number(r.j.borrador_v) === Number(miV)) {
    try { remoto = abrirDelMac(r.j.borrador, miPublica, privada); } catch (_) {}
  }
  const campos = (x) => x ? (x.campos || x) : null;
  const cuando = (x) => (x && x.guardado) || '';
  const elegido = (remoto && (!local || cuando(remoto) > cuando(local))) ? remoto : local;
  montaHerramienta(HERR.def, campos(elegido) || {}, false, 'Herramienta');
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
  if (!HERR || HERR.soloLeer || !HERR.tarea || HERR.modo === 'registro') return;
  try { localStorage.setItem(claveHerr(), JSON.stringify({ campos: camposDelForm(), guardado: new Date().toISOString() })); } catch (_) {}
});
$('h-guardar').addEventListener('click', async () => {
  if (!HERR || HERR.soloLeer || !HERR.tarea) return;
  $('h-guardar').disabled = true;
  try {
    const x = { campos: camposDelForm(), guardado: new Date().toISOString() };
    try { localStorage.setItem(claveHerr(), JSON.stringify(x)); } catch (_) {}
    const r = await api('guardar_borrador', { id: HERR.tarea, cifrado: sellarHaciaElMac(x, miPublica) });
    if (!r.ok) { $('h-estado').textContent = 'No se ha podido guardar en tu cuenta; se queda en este dispositivo.'; return; }
    await panel();
    $('panel-estado').textContent = 'Guardada. La retomas cuando quieras desde «Tareas».';
  } finally { $('h-guardar').disabled = false; }
});

$('h-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  if (!HERR || HERR.soloLeer || HERR.modo === 'registro') return;
  $('h-enviar').disabled = true;
  if (HERR.modo === 'vivo') {
    try {
      const x = await guardarVivo();
      if (!x) { $('h-estado').textContent = 'No se ha podido guardar. Inténtalo otra vez.'; return; }
      montaVivo(x.campos, x.guardado);
      $('h-estado').textContent = '✓ Guardado. Puedes volver a cambiarlo cuando quieras.';
    } finally { $('h-enviar').disabled = false; }
    return;
  }
  try {
    const campos = camposDelForm();
    const momento = ahoraLocal();
    /* El sobre, exactamente con la forma acordada con el sistema
       clínico: sin `instrumento` ni `respuestas`. */
    const sobre = { herramienta: HERR.def.id, tarea: HERR.tarea, campos, completado_en: momento,
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
  if (HERR.modo === 'registro') { imprimeHojas([hojaSemana(lunesDe(hoyISO()))]); return; }
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


/* =====================================================================
   Herramientas que viven (17-10, tarde)
   ---------------------------------------------------------------------
   · VIVO («Siempre a mano»): un documento que se edita siempre (el plan
     de seguridad). Guardar es enviar: lo que tiene ahora queda sellado a
     SU clave y cada versión llega sellada al Mac. Las tablas crecen
     («+ Añadir otra fila»: una persona más a la que llamar).
   · REGISTRO: entradas sueltas, una por día (la tarjeta diaria) o una
     por vez que pasa algo (el autorregistro). La tabla de la ficha de
     papel se convierte en un formulario corto; lo anterior se pliega por
     semanas; y el PDF de una semana es la ficha de papel rellena.
   ===================================================================== */
const DIAS_L = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
const CABECERA = ['nombre', 'semana', 'fecha'];

function hoyISO() { return new Date().toLocaleDateString('sv', { timeZone: 'Europe/Madrid' }); }
function deISO(s) { const [y, m, d] = String(s).slice(0, 10).split('-').map(Number); return new Date(y, m - 1, d, 12); }
function aISO(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function sumaDias(s, n) { const d = deISO(s); d.setDate(d.getDate() + n); return aISO(d); }
function diaSemana(s) { return (deISO(s).getDay() + 6) % 7; }          // 0 = lunes
function lunesDe(s) { return sumaDias(s, -diaSemana(s)); }
function diaLargo(s) {
  const d = deISO(s);
  const t = DIAS_L[diaSemana(s)] + ' ' + d.getDate() + ' de ' + d.toLocaleDateString('es-ES', { month: 'long' });
  return s === hoyISO() ? 'Hoy, ' + t : (s === sumaDias(hoyISO(), -1) ? 'Ayer, ' + t : t.charAt(0).toUpperCase() + t.slice(1));
}
function uidNuevo() { const a = new Uint8Array(12); crypto.getRandomValues(a); return Array.from(a, b => b.toString(16).padStart(2, '0')).join(''); }
/* --- La rueda de las emociones (25-10) -------------------------------- */
let RUEDA_CARGA = null;
function cargaRueda() {
  if (window.RUEDA_DATOS) return Promise.resolve();
  if (!RUEDA_CARGA) RUEDA_CARGA = new Promise((ok, mal) => {
    const sc = document.createElement('script'); sc.src = 'rueda.js?v=1'; sc.onload = ok; sc.onerror = mal;
    document.head.appendChild(sc);
  });
  return RUEDA_CARGA;
}
/* La rueda con su ficha. `usar(palabra)`, si viene, pone el botón «Usar». */
function montaRueda(cont, D, usar) {
  cont.innerHTML = '';
  const caja = el('div', 'rueda-caja');
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('class', 'rueda'); svg.setAttribute('role', 'group');
  svg.setAttribute('aria-label', 'Rueda de las emociones: ocho familias en el centro y, hacia fuera, sus palabras de suave a intensa');
  caja.appendChild(svg); cont.appendChild(caja);
  cont.appendChild(el('p', 'apunte rueda-ley', 'En el centro, la familia. Hacia fuera, cada vez más intensa.'));
  const fams = el('ul', 'rueda-familias'); fams.setAttribute('aria-label', 'Las ocho familias'); cont.appendChild(fams);
  const ficha = el('section', 'caja rueda-ficha'); ficha.setAttribute('aria-live', 'polite');
  ficha.appendChild(el('h3', null, 'Elige una emoción'));
  ficha.appendChild(el('p', 'nivel', 'Empieza por la familia que más se parece a lo que sientes.'));
  cont.appendChild(ficha);
  const muestra = (f, pal, k) => {
    ficha.style.setProperty('--fam', f.color);
    ficha.innerHTML = '';
    const h = el('h3', null, pal ? pal.palabra : f.nombre); h.tabIndex = -1; ficha.appendChild(h);
    ficha.appendChild(el('p', 'nivel', pal ? f.nombre + ' · intensidad ' + D.niveles[k] : 'Familia de emociones'));
    const dl = el('dl');
    const fila = (a, b) => { dl.appendChild(el('dt', null, a)); dl.appendChild(el('dd', null, b)); };
    if (pal) fila('Qué es', pal.que_es);
    fila('Te avisa de', f.avisa); fila('En el cuerpo', f.cuerpo); fila('Te empuja a', f.impulso);
    ficha.appendChild(dl);
    if (pal && usar) ficha.appendChild(boton('Usar «' + pal.palabra + '»', '', () => usar(pal.palabra)));
    const w = el('div', 'rueda-palabras');
    f.niveles.forEach((nv, j) => {
      w.appendChild(el('p', null, (j === 0 ? 'Palabras de esta familia · ' : '') + D.niveles[j]));
      const ul = el('ul');
      nv.forEach(p => {
        const b = el('button', null, p.palabra); b.type = 'button';
        if (pal && p.palabra === pal.palabra) b.setAttribute('aria-current', 'true');
        b.addEventListener('click', () => { muestra(f, p, j); ficha.querySelector('h3').focus(); });
        const l = el('li'); l.appendChild(b); ul.appendChild(l);
      });
      w.appendChild(ul);
    });
    ficha.appendChild(w);
  };
  window.pintaRueda(svg, D, muestra);
  D.familias.forEach(f => {
    const b = el('button', null, f.nombre); b.type = 'button'; b.style.setProperty('--fam', f.color);
    b.setAttribute('aria-pressed', 'false');
    b.addEventListener('click', () => {
      fams.querySelectorAll('button').forEach(x => x.setAttribute('aria-pressed', String(x === b)));
      muestra(f, null, null);
    });
    const l = el('li'); l.appendChild(b); fams.appendChild(l);
  });
  const mas = (titulo, lista) => {
    const d = el('details', 'rueda-mas'); d.appendChild(el('summary', null, titulo));
    const ul = el('ul');
    lista.forEach(x => { const li = el('li'); if (x.palabra) { li.appendChild(el('strong', null, x.palabra)); li.append(': ' + x.que_es); } else li.textContent = x; ul.appendChild(li); });
    d.appendChild(ul); cont.appendChild(d);
  };
  mas('Cómo usarla', D.como_usarla || []);
  mas('Mezclas frecuentes', D.mezclas || []);
  mas('Palabras que parecen emociones pero son otra cosa', D.no_son_emociones || []);
}
/* La rueda en un diálogo, desde una casilla. */
async function abreRuedaPara(campo) {
  try { await cargaRueda(); } catch (_) { return; }
  const dlg = $('rueda-dialogo');
  montaRueda($('rd-cuerpo'), window.RUEDA_DATOS, (palabra) => {
    const v = campo.value.replace(/\s+$/, '');
    campo.value = (v ? v.replace(/,$/, '') + ', ' : '') + palabra + ' ';
    campo.dispatchEvent(new Event('input', { bubbles: true }));
    dlg.close();
    campo.focus();
    try { campo.setSelectionRange(campo.value.length, campo.value.length); } catch (_) {}
  });
  dlg.showModal();
}
$('rd-cerrar').addEventListener('click', () => $('rueda-dialogo').close());
$('rueda-dialogo').addEventListener('click', (ev) => { if (ev.target === $('rueda-dialogo')) $('rueda-dialogo').close(); });
/* Junto a cada casilla con «ayuda: rueda-emociones» (o «ayuda_campo» en
   un apartado), un botón que abre la rueda. Vale para cualquier
   herramienta, tenga o no la rueda asignada. */
function ponAyudaRueda(def, raiz) {
  const ids = new Set(), cols = new Set();
  (def.bloques || []).forEach(b => {
    (b.campos || []).forEach(c => { if (c && c.ayuda === 'rueda-emociones') ids.add(c.id); });
    (b.columnas || []).forEach(c => { if (c && c.ayuda === 'rueda-emociones') cols.add(c.id); });
    if (b.t === 'apartado' && b.ayuda_campo === 'rueda-emociones' && b.id) ids.add(b.id);
  });
  if (!ids.size && !cols.size) return;
  raiz.querySelectorAll('input[data-campo], textarea[data-campo], input[data-col], textarea[data-col]').forEach(c => {
    if (c.type === 'hidden' || c.disabled) return;
    const k = c.dataset.campo || '';
    const col = c.dataset.col || (k.split('.').length === 3 ? k.split('.')[2] : null);
    if (!(ids.has(k) || (col && cols.has(col)))) return;
    const b = el('button', 'rueda-ayuda', 'Rueda de las emociones'); b.type = 'button';
    b.setAttribute('aria-label', 'Abrir la rueda de las emociones para esta casilla');
    b.addEventListener('click', (ev) => { ev.preventDefault(); abreRuedaPara(c); });
    c.insertAdjacentElement('afterend', b);
  });
}

function boton(texto, clase, accion) {
  const b = document.createElement('button');
  b.type = 'button'; b.className = clase === 'enlace-boton' ? 'enlace-boton' : ('boton' + (clase ? ' ' + clase : ''));
  b.textContent = texto; b.addEventListener('click', accion);
  return b;
}
function esAcordadoB(b) { return b.t === 'nota' && /^\s*lo que acordamos/i.test(b.negrita || ''); }
function esCabecera(b) { return b.t === 'campos' && (b.campos || []).every(k => CABECERA.includes(k.id)); }
function tieneCampos(bloques) { return bloques.some(b => b.t === 'campos' || (b.t === 'apartado' && b.id) || b.t === 'tabla' || b.t === 'firma'); }
function tablaPrincipal(def) { return (def.bloques || []).find(b => b.t === 'tabla') || null; }
function colsFijas(b) {
  const f = b.fijas || [];
  if (!f.length) return [];
  return b.columnas.map((c, j) => j).filter(j => f.every(r => r && r[j] != null && r[j] !== ''));
}
function colsEntrada(b) { const fx = colsFijas(b); return b.columnas.filter((c, j) => !fx.includes(j)); }
function esDiario(b) { return !!b && (b.fijas || []).length === 7; }

/* Más filas en las tablas si lo guardado tiene más. */
function ampliaDef(def, valores) {
  const d = JSON.parse(JSON.stringify(def));
  (d.bloques || []).forEach(b => {
    if (b.t !== 'tabla') return;
    const pre = b.id + '.'; let max = 0;
    Object.keys(valores || {}).forEach(k => {
      if (k.startsWith(pre) && valores[k]) { const n = parseInt(k.slice(pre.length), 10); if (n > max) max = n; }
    });
    b.filas = Math.max(b.filas || 0, max - (b.fijas || []).length);
  });
  return d;
}

/* «+ Añadir otra fila» debajo de cada tabla rellenable. */
function ponFilasExtra(raiz, defDeTablas, rehacer) {
  raiz.querySelectorAll('.tabla-h[data-tabla]').forEach(t => {
    const b = (defDeTablas.bloques || []).find(x => x.t === 'tabla' && x.id === t.dataset.tabla);
    if (!b) return;
    const bt = boton('+ Añadir otra fila', 'enlace-boton', () => {
      const v = camposDelForm(); const y = window.scrollY;
      b.filas = (b.filas || 0) + 1;
      rehacer(v);
      window.scrollTo(0, y);
    });
    bt.classList.add('mas-fila');
    t.after(bt);
  });
}

/* La copia que mandó antes del 17-10 (cuando se enviaba una vez): de ahí
   sale lo que ya tenía escrito, para que no empiece de cero. */
async function copiaAntigua(titulo) {
  const d = (DOCS || []).find(x => x.clase === 'herramienta' && x.titulo === titulo && Number(x.para_v) === Number(miV));
  if (!d) return null;
  const r = await api('abrir&id=' + encodeURIComponent(d.id));
  if (!r.ok || !r.j.cifrado) return null;
  try { const c = abrirDelMac(r.j.cifrado, miPublica, privada); return { campos: c.campos || {}, guardado: c.completado_en || '' }; }
  catch (_) { return null; }
}

async function abrirViva(r, def) {
  HERR = { tarea: r.j.id, def, soloLeer: false, modo: r.j.modo, recordar: (HERR_FILAS[r.j.id] || {}).recordar || null };
  let guardado = null;
  if (r.j.borrador) {
    if (Number(r.j.borrador_v) !== Number(miV)) {
      $('panel-estado').textContent = 'Esta herramienta se está actualizando tras el cambio de contraseña. En unos minutos la tienes, con todo lo que habías escrito.';
      HERR = null; return;
    }
    try { guardado = abrirDelMac(r.j.borrador, miPublica, privada); } catch (_) {}
  }
  if (!guardado && r.j.empezada === false) guardado = null;
  if (!guardado) guardado = await copiaAntigua(r.j.titulo);
  let valores = (guardado && (guardado.campos || {})) || {};
  if (def.tipo === 'lectura') {
    /* Para consultar (la rueda): sin guardar ni enviar nada. */
    montaHerramienta(def, {}, false, 'Siempre a mano');
    $('h-estado').textContent = '';
    return;
  }
  if (HERR.modo === 'vivo') {
    let local = null;
    try { local = JSON.parse(localStorage.getItem(claveHerr()) || 'null'); } catch (_) {}
    if (local && (!guardado || (local.guardado || '') > (guardado.guardado || ''))) valores = local.campos || {};
    montaVivo(valores, r.j.actualizado);
  } else {
    HERR.resto = valores;
    await montaRegistro();
  }
}

/* --- Vivo ------------------------------------------------------------- */
function montaVivo(valores, cuando) {
  HERR.def = ampliaDef(HERR.def, valores);
  HERR.actualizado = cuando || HERR.actualizado || null;
  montaHerramienta(HERR.def, valores, false, 'Siempre a mano');
  $('h-enviar').textContent = 'Guardar';
  $('h-guardar').hidden = true;
  $('h-estado').textContent = HERR.actualizado
    ? 'Guardado el ' + fecha(HERR.actualizado) + ' a las ' + hora(HERR.actualizado) + '. Puedes cambiarlo siempre que quieras: al guardar, Adrián tiene la versión nueva.'
    : 'Rellénalo con calma. Lo podrás cambiar siempre que quieras: añadir a alguien, quitar lo que ya no sirve…';
  ponFilasExtra($('h-cuerpo'), HERR.def, (v) => montaVivo(v, HERR.actualizado));
  HERR.visto = HERR.actualizado;
  HERR.base = JSON.stringify(camposDelForm());
  vigila();
}

/* --- Lo que cambia Adrián en sesión, a la vista (26-10) ------------------
   Mientras tiene abierto un documento vivo o un registro, la cuenta mira
   cada 25 segundos si ha cambiado por otro lado (Adrián lo ha editado con
   ella en la sesión). Si no ha tocado nada, se carga solo; si tiene algo
   sin guardar, se le pregunta. Nunca se pisa nada en silencio. */
let VIGIA = null;
function firmaEntradas(lista) { return lista.map(e => e.uid + '@' + (e.modificado || e.creado || '')).sort().join('|'); }
function vigila() {
  if (VIGIA) clearInterval(VIGIA);
  VIGIA = setInterval(() => { compruebaCambios().catch(() => {}); }, 25000);
}
async function compruebaCambios() {
  if (!HERR || !HERR.tarea || $('v-herr').hidden || document.hidden) { if (!HERR || $('v-herr').hidden) { clearInterval(VIGIA); VIGIA = null; } return; }
  if (HERR.modo === 'vivo' && HERR.def.tipo !== 'lectura') {
    const r = await api('tarea&id=' + encodeURIComponent(HERR.tarea));
    if (!r.ok || !r.j.actualizado || r.j.actualizado === HERR.visto || !r.j.borrador) return;
    if (Number(r.j.borrador_v) !== Number(miV)) return;
    let nuevo = null;
    try { nuevo = abrirDelMac(r.j.borrador, miPublica, privada); } catch (_) { return; }
    const cuando = r.j.actualizado;
    const carga = () => { montaVivo((nuevo && nuevo.campos) || {}, cuando); $('h-estado').textContent = 'Adrián ha actualizado este documento: ya tienes su versión.'; };
    if (JSON.stringify(camposDelForm()) === HERR.base) { carga(); return; }
    HERR.visto = cuando;
    const av = el('div', 'nota info'); av.setAttribute('role', 'alert');
    av.appendChild(el('p', null, 'Adrián ha actualizado este documento. ¿Cargar su versión o mantener la tuya?'));
    const bs = el('p');
    bs.append(boton('Cargar su versión', '', () => { av.remove(); carga(); }), ' ',
              boton('Mantener la mía', 'suave', () => { av.remove(); }));
    av.appendChild(bs);
    $('h-cuerpo').prepend(av);
  } else if (HERR.modo === 'registro') {
    const r = await api('entradas&id=' + encodeURIComponent(HERR.tarea));
    if (!r.ok) return;
    const f = firmaEntradas(r.j.entradas || []);
    if (f === HERR.firmaEntradas) return;
    if (document.getElementById('reg-fecha')) {
      HERR.firmaEntradas = f;
      const est = document.getElementById('reg-estado');
      if (est) est.textContent = 'Adrián ha añadido o corregido apuntes. Los verás al guardar o cancelar.';
      return;
    }
    await montaRegistro('Adrián ha actualizado tus apuntes.');
  }
}

async function guardarVivo() {
  const campos = camposDelForm();
  const momento = ahoraLocal();
  const sobre = { herramienta: HERR.def.id, tarea: HERR.tarea, modo: HERR.modo, parte: HERR.modo === 'registro' ? 'resto' : 'documento',
                  campos, completado_en: momento, origen: 'cuenta', cod_web: miCod };
  const copia = { campos, guardado: new Date().toISOString() };
  const r = await api('guardar_vivo', { id: HERR.tarea, cifrado: sellarHaciaElMac(sobre, macPublica),
                                       copia: sellarHaciaElMac(copia, miPublica) });
  if (!r.ok) return null;
  try { localStorage.removeItem(claveHerr()); } catch (_) {}
  return { guardado: r.j.guardado, campos };
}

/* --- Registro ---------------------------------------------------------- */
async function montaRegistro(msg) {
  HERR.principal = tablaPrincipal(HERR.def);
  if (!HERR.principal) { montaVivo(HERR.resto || {}, null); return; }
  const r = await api('entradas&id=' + encodeURIComponent(HERR.tarea));
  const ents = []; let viejas = 0;
  ((r.ok && r.j.entradas) || []).forEach(e => {
    if (Number(e.para_v) !== Number(miV)) { viejas++; return; }
    try {
      const c = abrirDelMac(e.cifrado, miPublica, privada);
      ents.push({ uid: e.uid, fecha: e.fecha, fila: c.fila || {}, creado: e.creado });
    } catch (_) { viejas++; }
  });
  HERR.entradas = ents; HERR.viejas = viejas;
  HERR.firmaEntradas = firmaEntradas((r.ok && r.j.entradas) || []);
  pintaRegistro(msg);
  vigila();
}

function partesRegistro() {
  const def = HERR.def, b = HERR.principal;
  const i = def.bloques.indexOf(b);
  const antes = def.bloques.slice(0, i).filter(x => !esCabecera(x));
  const despues = def.bloques.slice(i + 1).filter(x => x.t !== 'fuente');
  const camposDespues = tieneCampos(despues);
  return {
    acordado: antes.filter(esAcordadoB),
    como: antes.filter(x => !esAcordadoB(x) && !tieneCampos([x])).concat(camposDespues ? [] : despues),
    resto: antes.filter(x => tieneCampos([x])).concat(camposDespues ? despues : []),
    fuente: def.bloques.filter(x => x.t === 'fuente'),
  };
}

function resumenFila(e) {
  const cols = colsEntrada(HERR.principal);
  const trozos = cols.map(c => [c.rotulo, (e.fila[c.id] || '').trim()]).filter(x => x[1]);
  if (!trozos.length) return 'Sin nada escrito';
  return trozos.slice(0, 2).map(([r, v]) => r.replace(/\s*\(.*?\)\s*$/, '') + ': ' + (v.length > 70 ? v.slice(0, 68) + '…' : v)).join(' · ');
}

function tarjetaEntrada(e) {
  const t = boton('', '', () => editaEntrada(e));
  t.className = 'reg-entrada';
  t.append(el('strong', null, diaLargo(e.fecha)), el('span', null, resumenFila(e)));
  return t;
}

function tiraSemana(l, es) {
  const t = el('div', 'reg-tira');
  const hoy = hoyISO();
  for (let k = 0; k < 7; k++) {
    const f = sumaDias(l, k);
    const hecho = es.some(e => e.fecha === f);
    const c = el('button', 'reg-dia' + (hecho ? ' hecho' : '') + (f === hoy ? ' hoy' : ''));
    c.type = 'button';
    c.append(el('span', null, 'LMXJVSD'[k]), el('b', null, hecho ? '✓' : String(deISO(f).getDate())));
    c.setAttribute('aria-label', diaLargo(f) + (hecho ? ': apuntado' : ': sin apuntar'));
    if (f > hoy) c.disabled = true;
    else c.addEventListener('click', () => editaEntrada(es.find(e => e.fecha === f) || { fecha: f }));
    t.appendChild(c);
  }
  return t;
}

function cajaRecordatorio() {
  const d = el('div', 'reg-aviso');
  const pinta = (editando) => {
    d.innerHTML = '';
    if (HERR.recordar && !editando) {
      d.appendChild(el('p', null, '🔔 Te lo recuerdo por correo cada día a las ' + HERR.recordar + ', solo si aún no lo has apuntado.'));
      const p = el('p', 'reg-aviso-b');
      p.append(boton('Cambiar la hora', 'enlace-boton', () => pinta(true)), ' · ',
               boton('Quitar el recordatorio', 'enlace-boton', () => pon('')));
      d.appendChild(p);
      return;
    }
    d.appendChild(el('p', null, '¿Quieres que te lo recuerde? Un correo discreto cada día a la hora que elijas, solo si aún no lo has apuntado.'));
    const f = el('p', 'reg-aviso-b');
    const h = document.createElement('input'); h.type = 'time'; h.value = HERR.recordar || '21:00'; h.id = 'reg-hora';
    h.setAttribute('aria-label', 'Hora del recordatorio');
    f.append(h, ' ', boton(HERR.recordar ? 'Guardar' : 'Recordármelo', 'suave', () => pon(h.value)));
    if (editando) f.append(' ', boton('Cancelar', 'enlace-boton', () => pinta(false)));
    d.appendChild(f);
  };
  const pon = async (hora) => {
    const r = await api('recordatorio', { id: HERR.tarea, hora });
    if (!r.ok) { d.appendChild(el('p', 'apunte', 'No se ha podido guardar. Inténtalo otra vez.')); return; }
    HERR.recordar = r.j.hora;
    if (HERR_FILAS[HERR.tarea]) HERR_FILAS[HERR.tarea].recordar = r.j.hora;
    pinta(false);
  };
  pinta(false);
  return d;
}

function pintaRegistro(msg) {
  const def = HERR.def, b = HERR.principal, diario = esDiario(b);
  const P = partesRegistro();
  $('h-etiqueta').textContent = 'Registro';
  $('h-titulo').textContent = def.titulo;
  $('h-subtitulo').textContent = def.subtitulo || '';
  document.querySelectorAll('.h-riesgo').forEach(x => { x.hidden = !def.riesgo; });
  $('h-enviar').hidden = true; $('h-guardar').hidden = true; $('h-leido').hidden = true;
  $('h-pdf').hidden = false; $('h-pdf').textContent = 'Descargar esta semana en PDF';
  $('h-todo').hidden = !HERR.entradas.length;
  $('h-estado').textContent = '';
  const c = $('h-cuerpo'); c.innerHTML = '';
  const raiz = el('div', 'herr registro');
  if (P.acordado.length) raiz.appendChild(pintaBloques(Object.assign({}, def, { bloques: P.acordado }), {}, 'texto', true));

  /* Hoy */
  const hoy = hoyISO();
  const caja = el('div', 'reg-hoy');
  const deHoy = HERR.entradas.filter(e => e.fecha === hoy);
  if (msg) caja.appendChild(el('p', 'reg-msg', msg));
  if (diario && deHoy.length) {
    caja.appendChild(el('p', 'reg-hecho', '✓ Hoy ya lo has apuntado'));
    caja.appendChild(boton('Ver o corregir lo de hoy', 'suave', () => editaEntrada(deHoy[0])));
  } else {
    caja.appendChild(boton(diario ? '+ Apuntar hoy' : '+ Añadir un apunte', '', () => editaEntrada({ fecha: hoy })));
  }
  caja.appendChild(boton(diario ? 'Apuntar otro día' : 'Es de otro día', 'enlace-boton',
    () => editaEntrada({ fecha: sumaDias(hoy, -1), otroDia: true })));
  raiz.appendChild(caja);
  raiz.appendChild(cajaRecordatorio());

  if (HERR.viejas) raiz.appendChild(el('p', 'nota info', HERR.viejas + (HERR.viejas === 1 ? ' apunte anterior' : ' apuntes anteriores') +
    ' al cambio de contraseña se están actualizando. No se ha perdido nada.'));

  /* Cómo se rellena: abierto mientras no haya ningún apunte. */
  if (P.como.length) {
    const d = document.createElement('details'); d.className = 'reg-como'; d.open = !HERR.entradas.length;
    const s = document.createElement('summary'); s.textContent = 'Cómo se rellena';
    d.append(s, pintaBloques(Object.assign({}, def, { bloques: P.como }), {}, 'texto', true));
    raiz.appendChild(d);
  }

  /* Por semanas: la de ahora abierta, las demás plegadas. */
  const porSemana = {};
  HERR.entradas.forEach(e => { (porSemana[lunesDe(e.fecha)] = porSemana[lunesDe(e.fecha)] || []).push(e); });
  const esta = lunesDe(hoy);
  porSemana[esta] = porSemana[esta] || [];
  raiz.appendChild(el('h2', 'titulo-h', 'Tus apuntes'));
  Object.keys(porSemana).sort().reverse().forEach(l => {
    const es = porSemana[l].slice().sort((a, x) => (a.fecha < x.fecha ? 1 : a.fecha > x.fecha ? -1 : (a.creado < x.creado ? 1 : -1)));
    const dias = new Set(es.map(e => e.fecha)).size;
    const d = document.createElement('details'); d.className = 'reg-semana'; d.open = (l === esta);
    const s = document.createElement('summary');
    s.append(el('strong', null, l === esta ? 'Esta semana' : 'Semana del ' + fecha(l) + ' al ' + fecha(sumaDias(l, 6))),
             el('span', null, diario ? dias + ' de 7 días' : es.length + (es.length === 1 ? ' apunte' : ' apuntes')));
    d.appendChild(s);
    if (diario) d.appendChild(tiraSemana(l, es));
    if (!es.length) d.appendChild(el('p', 'apunte', 'Aún nada esta semana.'));
    es.forEach(e => d.appendChild(tarjetaEntrada(e)));
    if (es.length) d.appendChild(boton('Esta semana en PDF', 'enlace-boton', () => imprimeHojas([hojaSemana(l)])));
    raiz.appendChild(d);
  });

  /* Lo de la ficha que no es un apunte («Esta semana usé mi plan…»,
     «Mi frase»): se guarda como un documento vivo. */
  if (P.resto.length) {
    const sec = el('section', 'reg-resto');
    sec.appendChild(el('h2', 'titulo-h', 'Lo demás de la ficha'));
    const dr = ampliaDef(Object.assign({}, def, { bloques: P.resto }), HERR.resto || {});
    sec.appendChild(pintaBloques(dr, HERR.resto || {}, 'form', false));
    ponAyudaRueda(dr, sec);
    const est = el('p', 'apunte'); est.setAttribute('role', 'status');
    const g = boton('Guardar', 'suave', async () => {
      g.disabled = true;
      try {
        const x = await guardarVivo();
        if (!x) { est.textContent = 'No se ha podido guardar. Inténtalo otra vez.'; return; }
        HERR.resto = x.campos;
        est.textContent = 'Guardado.';
      } finally { g.disabled = false; }
    });
    sec.append(g, est);
    raiz.appendChild(sec);
    ponFilasExtra(sec, def, (v) => { HERR.resto = v; pintaRegistro(); });
  }
  if (P.fuente.length) raiz.appendChild(pintaBloques(Object.assign({}, def, { bloques: P.fuente }), {}, 'texto', true));
  c.appendChild(raiz);
  ver('v-herr');
}

function editaEntrada(e) {
  const b = HERR.principal, diario = esDiario(b), cols = colsEntrada(b);
  /* Un día, un apunte: si ese día ya tiene, se abre ése. */
  if (!e.uid && diario) { const ex = HERR.entradas.find(x => x.fecha === e.fecha); if (ex) e = Object.assign({}, ex, { otroDia: e.otroDia }); }
  $('h-pdf').hidden = true; $('h-todo').hidden = true;
  const c = $('h-cuerpo'); c.innerHTML = '';
  const f = el('div', 'herr reg-editor');
  f.appendChild(el('h2', 'titulo-h', e.uid ? 'Corregir un apunte' : (diario ? 'Apuntar el día' : 'Nuevo apunte')));
  const lf = el('label', 'campo-h');
  lf.appendChild(el('span', 'rotulo', 'Día'));
  const fi = document.createElement('input'); fi.type = 'date'; fi.id = 'reg-fecha';
  fi.max = hoyISO(); fi.min = sumaDias(hoyISO(), -60); fi.value = e.fecha || hoyISO();
  lf.appendChild(fi);
  if (!e.otroDia && !e.uid) lf.hidden = true;
  f.appendChild(el('p', 'reg-dia-t', diaLargo(fi.value)));
  f.appendChild(lf);
  fi.addEventListener('change', () => {
    if (!fi.value) return;
    f.querySelector('.reg-dia-t').textContent = diaLargo(fi.value);
    if (diario && !e.uid) { const ex = HERR.entradas.find(x => x.fecha === fi.value); if (ex) editaEntrada(Object.assign({}, ex, { otroDia: true })); }
  });
  if (b.ejemplo) {
    const fx = colsFijas(b);
    const ej = b.columnas.map((col, j) => fx.includes(j) || !b.ejemplo[j] ? null : col.rotulo.replace(/\s*\(.*?\)\s*$/, '') + ': ' + b.ejemplo[j]).filter(Boolean);
    if (ej.length) f.appendChild(el('p', 'reg-ejemplo', 'Ejemplo · ' + ej.join(' · ')));
  }
  cols.forEach(col => {
    /* Una escala corta («(0-5)», «(sí/no)»): botones, dos toques. */
    const m = /\((\d+)\s*[-–]\s*(\d+)\)\s*$/.exec(col.rotulo);
    const sino = /\(sí\/no\)\s*$/i.test(col.rotulo);
    if ((m && +m[2] > +m[1] && +m[2] - +m[1] <= 10) || sino) {
      const g = el('div', 'campo-h');
      const rid = 'reg-r-' + col.id;
      g.appendChild(el('span', 'rotulo', col.rotulo)).id = rid;
      const h = document.createElement('input'); h.type = 'hidden'; h.dataset.col = col.id;
      h.value = (e.fila || {})[col.id] || '';
      const fila = el('div', 'reg-chips'); fila.setAttribute('role', 'group'); fila.setAttribute('aria-labelledby', rid);
      const ops = sino ? ['sí', 'no'] : Array.from({ length: +m[2] - +m[1] + 1 }, (_, k) => String(+m[1] + k));
      ops.forEach(o => {
        const bt = el('button', 'reg-chip', o); bt.type = 'button';
        bt.setAttribute('aria-pressed', String(h.value.toLowerCase() === o));
        bt.addEventListener('click', () => {
          h.value = h.value.toLowerCase() === o ? '' : o;
          fila.querySelectorAll('.reg-chip').forEach(x => x.setAttribute('aria-pressed', String(x.textContent === h.value)));
        });
        fila.appendChild(bt);
      });
      g.append(fila, h); f.appendChild(g);
      return;
    }
    const lab = el('label', 'campo-h');
    lab.appendChild(el('span', 'rotulo', col.rotulo));
    const largo = (b.alto || 0) > 1.2 || (col.ancho || 4) >= 8 || col.rotulo.length > 40;
    const inp = largo ? document.createElement('textarea') : document.createElement('input');
    if (largo) inp.rows = 3; else inp.type = 'text';
    inp.dataset.col = col.id; inp.value = (e.fila || {})[col.id] || ''; inp.maxLength = 4000;
    lab.appendChild(inp); f.appendChild(lab);
  });
  ponAyudaRueda(HERR.def, f);
  const est = el('p', 'apunte'); est.id = 'reg-estado'; est.setAttribute('role', 'status');
  const bs = el('p', 'reg-botones');
  const g = boton('Guardar', '', async () => { g.disabled = true; try { await guardaEntrada(e); } finally { g.disabled = false; } });
  bs.appendChild(g);
  if (e.uid) {
    let seguro = false;
    const q = boton('Quitar este apunte', 'suave', async () => {
      if (!seguro) { seguro = true; q.textContent = '¿Seguro? Pulsa otra vez para quitarlo'; return; }
      await quitaEntrada(e);
    });
    bs.appendChild(q);
  }
  bs.appendChild(boton('Cancelar', 'enlace-boton', () => pintaRegistro()));
  f.append(bs, est);
  c.appendChild(f);
  window.scrollTo(0, 0);
}

function sobreEntrada(uid, fecha, fila, borrada) {
  const b = HERR.principal;
  const n = esDiario(b) ? diaSemana(fecha) + 1 : 1;
  const campos = {};
  Object.keys(fila || {}).forEach(k => { campos[b.id + '.' + n + '.' + k] = fila[k]; });
  return { herramienta: HERR.def.id, tarea: HERR.tarea, modo: 'registro', entrada: { uid, fecha, borrada: !!borrada },
           tabla: b.id, fila: fila || {}, campos, semana: lunesDe(fecha),
           completado_en: ahoraLocal(), origen: 'cuenta', cod_web: miCod };
}

async function guardaEntrada(e) {
  const est = $('reg-estado');
  const fecha = $('reg-fecha').value;
  if (!fecha || fecha > hoyISO()) { est.textContent = 'Elige un día de hoy hacia atrás.'; return; }
  const fila = {};
  document.querySelectorAll('#h-cuerpo [data-col]').forEach(i => { fila[i.dataset.col] = i.value; });
  if (!Object.values(fila).some(v => v.trim())) { est.textContent = 'Escribe algo antes de guardar.'; return; }
  let uid = e.uid;
  if (!uid && esDiario(HERR.principal)) { const ex = HERR.entradas.find(x => x.fecha === fecha); if (ex) uid = ex.uid; }
  uid = uid || uidNuevo();
  const copia = { fecha, fila, guardado: new Date().toISOString() };
  const r = await api('entrada', { id: HERR.tarea, uid, fecha,
    cifrado: sellarHaciaElMac(sobreEntrada(uid, fecha, fila, false), macPublica),
    copia: sellarHaciaElMac(copia, miPublica) });
  if (!r.ok) { est.textContent = r.j.error === 'despacio' ? 'Demasiados cambios seguidos: espera un poco.' : 'No se ha podido guardar. Inténtalo otra vez.'; return; }
  HERR.entradas = HERR.entradas.filter(x => x.uid !== uid).concat([{ uid, fecha, fila, creado: new Date().toISOString() }]);
  pintaRegistro(fecha === hoyISO() ? '✓ Guardado. Lo de hoy, apuntado.' : '✓ Guardado: ' + diaLargo(fecha).toLowerCase() + '.');
}

async function quitaEntrada(e) {
  const r = await api('entrada', { id: HERR.tarea, uid: e.uid, fecha: e.fecha, borrar: true,
    cifrado: sellarHaciaElMac(sobreEntrada(e.uid, e.fecha, null, true), macPublica) });
  if (!r.ok) { $('reg-estado').textContent = 'No se ha podido quitar. Inténtalo otra vez.'; return; }
  HERR.entradas = HERR.entradas.filter(x => x.uid !== e.uid);
  pintaRegistro('Apunte quitado.');
}

/* --- PDF ----------------------------------------------------------------
   Una semana de un registro = la ficha de papel rellena con sus apuntes. */
function hojaSemana(l) {
  const def = HERR.def, b = HERR.principal;
  const es = HERR.entradas.filter(e => lunesDe(e.fecha) === l).sort((a, x) => (a.fecha > x.fecha ? 1 : -1));
  const valores = Object.assign({}, HERR.resto || {});
  valores.semana = fecha(l);
  const primera = colsEntrada(b)[0];
  es.forEach((e, i) => {
    const n = esDiario(b) ? diaSemana(e.fecha) + 1 : i + 1;
    Object.keys(e.fila).forEach(k => { valores[b.id + '.' + n + '.' + k] = e.fila[k]; });
    /* En una tabla sin días, que se sepa de qué día es cada fila. */
    if (!esDiario(b) && primera && !e.fila[primera.id]) valores[b.id + '.' + n + '.' + primera.id] = diaLargo(e.fecha);
  });
  return { def: ampliaDef(def, valores), valores,
           sub: 'Semana del ' + fecha(l) + ' al ' + fecha(sumaDias(l, 6)) };
}

function imprimeHojas(hojas) {
  if (!hojas.length) return;
  const cont = $('h-imprimir'); cont.innerHTML = '';
  if (cont.parentNode !== document.body) document.body.appendChild(cont);
  hojas.forEach((h, i) => {
    const s = el('div', 'hoja' + (i ? ' salto' : ''));
    s.appendChild(el('h1', null, h.def.titulo));
    if (h.sub || h.def.subtitulo) s.appendChild(el('p', 'apunte', h.sub || h.def.subtitulo));
    if (h.def.riesgo) {
      const a = el('div', 'auxilio');
      a.appendChild(el('p', null, 'Si no puedes mantenerte a salvo: 024 (24 horas, gratuito) o 112.'));
      s.appendChild(a);
    }
    s.appendChild(pintaBloques(h.def, h.valores, 'texto', true));
    cont.appendChild(s);
  });
  let estilo = document.getElementById('h-pagina');
  if (!estilo) { estilo = document.createElement('style'); estilo.id = 'h-pagina'; document.head.appendChild(estilo); }
  estilo.textContent = '@page { size: A4 ' + (hojas[0].def.apaisado ? 'landscape' : 'portrait') + '; margin: 14mm; }';
  window.print();
}
$('h-todo').addEventListener('click', () => {
  if (!HERR || HERR.modo !== 'registro') return;
  const semanas = [...new Set(HERR.entradas.map(e => lunesDe(e.fecha)))].sort();
  imprimeHojas(semanas.map(hojaSemana));
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
        if (c.def && (c.def.id === 'plan-seguridad' || (c.def.riesgo && c.def.id !== 'tarjeta-diaria')) && !PLAN) { PLAN = d.id; $('b-plan').hidden = false; }
      } catch (_) {}
    });
  }
  $('b-plan').hidden = true;
}
$('b-plan').addEventListener('click', () => { if (PLAN_TAREA) abrirHerramienta(PLAN_TAREA); else if (PLAN) abrirCopia(PLAN); });

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
