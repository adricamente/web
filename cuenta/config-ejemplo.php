<?php
/* ===================================================================
   Copia este fichero a UN NIVEL POR ENCIMA de la carpeta del portal y
   llámalo `adr-config.php`. Es decir: si el subdominio apunta a
   /public_html/cuenta/, esto va en /public_html/adr-config.php.

   ¿Por qué arriba y no aquí? Porque aquí es una carpeta pública. Un
   día alguien desactiva PHP por un error de configuración y todos los
   .php del sitio se sirven como texto plano — pasa, y ese día el
   secreto del servidor y la clave de Brevo se leen desde el
   navegador. Un nivel más arriba no hay URL que apunte.

   Y no está en el repositorio, a propósito. Un secreto en git está
   quemado para siempre aunque lo borres al minuto siguiente.
   =================================================================== */

return [

  /* El secreto del servidor. Firma las sesiones y genera las sales
     falsas. Si cambia, todo el mundo tiene que volver a entrar.
     Genéralo así, y no lo escribas a mano:

         php -r 'echo bin2hex(random_bytes(32)), "\n";'
  */
  'secreto' => 'PEGA_AQUI_LOS_64_CARACTERES',

  /* Dónde vive la base de datos. Fuera de la carpeta pública también.
     Si el subdominio apunta a /public_html/cuenta/, esto está bien. */
  'datos' => __DIR__ . '/adr-datos/portal.sqlite',

  /* La dirección del portal, sin barra al final. Se usa para armar
     los enlaces de los correos. */
  'sitio' => 'https://cuenta.adricamente.com',

  /* La clave con la que se identifica el Mac. No es una contraseña de
     persona: es una cadena larga que solo está en dos sitios, aquí y
     en el Mac. Mismo comando que arriba. */
  'mac' => 'PEGA_AQUI_OTROS_64_CARACTERES',

  /* Brevo, para el correo de «he olvidado la contraseña».
     Panel de Brevo -> SMTP & API -> API Keys.

     DÉJALO VACÍO Y EL PORTAL FUNCIONA IGUAL: sale por el correo del
     propio alojamiento. La diferencia no es si llega, es dónde: sin
     Brevo hay bastantes papeletas de caer en spam, y un correo de
     recuperar contraseña en spam es una función rota, porque quien lo
     necesita es justo quien no va a ir a buscarlo.

     O sea: empieza vacío, que no bloquea nada, y rellénalo cuando
     tengas dos minutos. */
  'brevo'  => '',
  'remite' => 'hola@adricamente.com',

  /* Cuántos días se guarda en el servidor un cuestionario DESPUÉS de
     que el Mac se lo haya llevado. Pasado ese plazo se borra solo.

     No es limpieza: es el plazo de conservación, y un tratamiento de
     datos de salud necesita tener uno escrito. Cuando el Mac ya lo
     tiene, la copia cifrada del servidor deja de tener función.

     No es cero a propósito: un disco que se estropea el martes por la
     tarde no puede llevarse por delante lo que se recogió el martes
     por la mañana. Treinta días es margen de sobra para enterarse.

     Los documentos que van HACIA el paciente no caducan: ésos son su
     copia y su derecho a tenerla. */
  'dias_sobres' => 30,
];
