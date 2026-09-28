/* ===================================================================
   cripto.js — la caja fuerte del portal del paciente
   -------------------------------------------------------------------
   Todo lo que hace este fichero ocurre en el navegador del paciente.
   El servidor nunca ve la contraseña, nunca ve la clave privada sin
   envolver y nunca ve el contenido de un sobre.

   Aquí viven las tres trampas del contrato de integración (§8), y las
   tres rompen la garantía EN SILENCIO: no lanzan un error, no dejan
   rastro y no se notan hasta que alguien se lleva la base de datos.
   Por eso van explicadas donde se resuelven y no en un documento
   aparte que nadie vuelve a abrir.

   Trampa 1 · Olvidar la contraseña deja ilegible lo recibido.
       No hay depósito de claves ni puerta de atrás, a propósito. La
       recuperación es que el Mac tiene el original de todo y reenvía a
       la clave nueva. El portal es la puerta, no la casa.

   Trampa 2 · Cambiar la contraseña NO es restablecerla.
       cambiarContrasena() reenvuelve LA MISMA privada y todo lo
       recibido se sigue leyendo. Restablecer genera una pareja NUEVA
       y lo anterior queda ilegible: eso no está en este fichero a
       propósito, porque es un alta nueva (crearIdentidad), no una
       operación sobre la identidad existente. Si algún día alguien
       implementa el restablecimiento llamando a cambiarContrasena(),
       se pierde todo sin que salte nada.

   Trampa 3 · La derivación doble, que es la que mata.
       El servidor guarda DOS cosas que salen de la misma contraseña:
       con qué autenticar y con qué se envolvió la privada. Si las dos
       salieran de la misma derivación, el propio valor que el servidor
       guarda para dejar entrar sería la llave del ciphertext, y quien
       se llevara la base de datos se lo llevaría todo descifrado.

       Aquí: una sola pasada de Argon2id da una clave maestra, y de
       ella salen DOS subclaves independientes por KDF, con contextos
       distintos. De la de autenticación no se puede volver a la
       maestra, así que no se puede llegar a la de envoltura.

   Y una cosa más que no es una trampa sino una consecuencia: la
   privada envuelta vive en el servidor, así que quien se lleve la base
   de datos puede atacar la contraseña SIN PRISA y sin límite de
   intentos. Por eso Argon2id con parámetros interactivos y un mínimo
   de fuerza exigido arriba, en el formulario.
   =================================================================== */

const CONTEXTO_AUTH = 'adr-auth';   // 8 bytes exactos: lo exige crypto_kdf
const CONTEXTO_ENVO = 'adr-envo';
const SUBCLAVE_AUTH = 1;
const SUBCLAVE_ENVO = 2;

let sodio = null;

/** Carga libsodium una sola vez. Todo lo demás lo da por cargado. */
export async function listo(mod) {
  if (sodio) return sodio;
  const s = mod || (typeof window !== 'undefined' ? window.sodium : null);
  if (!s) throw new Error('Falta libsodium');
  await s.ready;
  sodio = s;
  return sodio;
}

const b64 = (u8) => sodio.to_base64(u8, sodio.base64_variants.ORIGINAL);
const deB64 = (s) => sodio.from_base64(s, sodio.base64_variants.ORIGINAL);

/* -------------------------------------------------------------------
   La derivación doble (trampa 3)
   ------------------------------------------------------------------- */

/**
 * De la contraseña salen dos claves que NO se pueden deducir una de la
 * otra:
 *   - `auth`   se manda al servidor. Es lo único que viaja.
 *   - `envoltura` no sale nunca del navegador.
 *
 * Una sola pasada de Argon2id (que es la cara) y dos derivaciones
 * baratas encima. Hacer dos pasadas de Argon2id con sales distintas
 * también valdría, pero cuesta el doble en el móvil de alguien y no
 * compra nada.
 */
async function derivar(contrasena, sal) {
  const maestra = sodio.crypto_pwhash(
    sodio.crypto_kdf_KEYBYTES,
    contrasena,
    sal,
    sodio.crypto_pwhash_OPSLIMIT_INTERACTIVE,
    sodio.crypto_pwhash_MEMLIMIT_INTERACTIVE,
    sodio.crypto_pwhash_ALG_ARGON2ID13
  );
  const auth = sodio.crypto_kdf_derive_from_key(
    32, SUBCLAVE_AUTH, CONTEXTO_AUTH, maestra);
  const envoltura = sodio.crypto_kdf_derive_from_key(
    sodio.crypto_secretbox_KEYBYTES, SUBCLAVE_ENVO, CONTEXTO_ENVO, maestra);
  sodio.memzero(maestra);
  return { auth, envoltura };
}

function envolver(privada, clave) {
  const nonce = sodio.randombytes_buf(sodio.crypto_secretbox_NONCEBYTES);
  const caja = sodio.crypto_secretbox_easy(privada, nonce, clave);
  const junto = new Uint8Array(nonce.length + caja.length);
  junto.set(nonce, 0);
  junto.set(caja, nonce.length);
  return junto;
}

function desenvolver(envuelta, clave) {
  const n = sodio.crypto_secretbox_NONCEBYTES;
  const nonce = envuelta.slice(0, n);
  const caja = envuelta.slice(n);
  // Lanza si la contraseña es otra o si alguien tocó el ciphertext.
  // Que lance es lo correcto: no hay "quizá".
  return sodio.crypto_secretbox_open_easy(caja, nonce, clave);
}

/* -------------------------------------------------------------------
   Alta, entrada y cambio de contraseña
   ------------------------------------------------------------------- */

/**
 * Alta: genera la pareja del paciente y envuelve la privada.
 * Devuelve lo que sube al servidor y la privada en memoria.
 *
 * Esto mismo es lo que se usa al RESTABLECER una contraseña olvidada:
 * identidad nueva, clave nueva, y lo recibido antes queda ilegible.
 * Es la trampa 2 y por eso no hay una función "restablecer" que
 * parezca inofensiva.
 */
export async function crearIdentidad(contrasena) {
  const sal = sodio.randombytes_buf(sodio.crypto_pwhash_SALTBYTES);
  const { auth, envoltura } = await derivar(contrasena, sal);
  const par = sodio.crypto_box_keypair();
  const envuelta = envolver(par.privateKey, envoltura);
  sodio.memzero(envoltura);
  return {
    // --- al servidor ---
    sal: b64(sal),
    auth: b64(auth),
    publica: b64(par.publicKey),
    privadaEnvuelta: b64(envuelta),
    // --- se queda aquí ---
    privada: par.privateKey,
  };
}

/**
 * Entrada: con la contraseña, la sal y la privada envuelta que da el
 * servidor, recupera la privada.
 *
 * Funciona igual en un dispositivo nuevo, que es justo por qué la
 * privada se guarda envuelta en el servidor y no en el navegador: si
 * viviera solo aquí, cambiar de móvil sería perderlo todo.
 */
export async function abrirIdentidad(contrasena, salB64, envueltaB64) {
  const { auth, envoltura } = await derivar(contrasena, deB64(salB64));
  let privada;
  try {
    privada = desenvolver(deB64(envueltaB64), envoltura);
  } catch (_) {
    sodio.memzero(envoltura);
    throw new Error('CONTRASENA_INCORRECTA');
  }
  sodio.memzero(envoltura);
  return { auth: b64(auth), privada };
}

/**
 * Entrada en DOS tiempos, que es como funciona de verdad.
 *
 * abrirIdentidad() pide la privada envuelta de entrada, y eso no se
 * puede: el servidor no la suelta hasta haber comprobado la clave de
 * autenticacion, y esa clave sale de la misma derivacion. O sea que
 * el orden real es: derivar -> preguntar al servidor -> desenvolver.
 *
 * Asi que aqui va la version con esa forma. `pedirEnvuelta` es una
 * funcion que recibe la clave de autenticacion en base64 y devuelve
 * la privada envuelta, tambien en base64.
 *
 * Lo importante es lo que NO sale de este modulo: la clave de
 * envoltura. Se queda aqui dentro entre los dos pasos y se borra al
 * terminar. Si se devolviera junto con la de autenticacion "para que
 * la pagina haga los dos pasos", quien leyera esa pagina tendria a la
 * vez lo que el servidor guarda y lo que abre el ciphertext, que es
 * exactamente la trampa 3 servida en bandeja.
 */
export async function entrarConServidor(contrasena, salB64, pedirEnvuelta) {
  const { auth, envoltura } = await derivar(contrasena, deB64(salB64));
  let envueltaB64;
  try {
    envueltaB64 = await pedirEnvuelta(b64(auth));
  } catch (e) {
    sodio.memzero(envoltura);
    throw e;
  }
  if (!envueltaB64) { sodio.memzero(envoltura); throw new Error('SIN_ENVUELTA'); }
  let privada;
  try {
    privada = desenvolver(deB64(envueltaB64), envoltura);
  } catch (_) {
    sodio.memzero(envoltura);
    /* Que llegue aqui es raro: el servidor ya dijo que la clave de
       autenticacion era buena. Si la envoltura falla despues, o el
       ciphertext esta tocado o hay dos derivaciones distintas. En
       cualquier caso no es "contrasena incorrecta" y decirlo asi
       mandaria a la persona a cambiar algo que no falla. */
    throw new Error('ENVOLTURA_NO_ABRE');
  }
  sodio.memzero(envoltura);
  return privada;
}

/**
 * Cambio de contraseña: LA MISMA privada, envuelta con otra clave.
 * Todo lo recibido se sigue leyendo. Esta es la mitad buena de la
 * trampa 2; la otra mitad es no llamar aquí cuando alguien ha
 * olvidado la contraseña.
 */
export async function cambiarContrasena(privada, contrasenaNueva) {
  const sal = sodio.randombytes_buf(sodio.crypto_pwhash_SALTBYTES);
  const { auth, envoltura } = await derivar(contrasenaNueva, sal);
  const envuelta = envolver(privada, envoltura);
  sodio.memzero(envoltura);
  return { sal: b64(sal), auth: b64(auth), privadaEnvuelta: b64(envuelta) };
}

/* -------------------------------------------------------------------
   Los sobres, en los dos sentidos
   ------------------------------------------------------------------- */

/** Paciente → Mac. Sellado a la pública del Mac; el servidor transporta. */
export function sellarHaciaElMac(objeto, publicaMacB64) {
  const crudo = sodio.from_string(JSON.stringify(objeto));
  return b64(sodio.crypto_box_seal(crudo, deB64(publicaMacB64)));
}

/** Mac → paciente. Solo se abre con la privada que salió de la contraseña. */
export function abrirDelMac(cifradoB64, publicaB64, privada) {
  const claro = sodio.crypto_box_seal_open(
    deB64(cifradoB64), deB64(publicaB64), privada);
  return JSON.parse(sodio.to_string(claro));
}

/* -------------------------------------------------------------------
   La sal y la enumeración de cuentas
   ------------------------------------------------------------------- */

/**
 * Para entrar hace falta la sal, y la sal se pide por correo ANTES de
 * saber si la contraseña es buena. Si el servidor contesta "no existe"
 * a un correo desconocido, acaba de decirle a cualquiera quién tiene
 * cuenta aquí — y "quién tiene cuenta aquí" es, en este sitio, quién
 * está en tratamiento psicológico.
 *
 * Esto no se arregla en el navegador; se arregla en el servidor, y va
 * escrito aquí porque es donde se mira:
 *
 *   El servidor SIEMPRE devuelve una sal. Para un correo que existe,
 *   la suya. Para uno que no, una falsa pero ESTABLE, derivada con
 *   HMAC(secreto_del_servidor, correo). Estable importa: si fuera
 *   aleatoria en cada intento, comparar dos respuestas delataría igual.
 *
 * El intento falla después, al desenvolver, con el mismo error que una
 * contraseña mala. Desde fuera, una cuenta que no existe y una
 * contraseña equivocada son indistinguibles.
 */
export const NOTA_SAL_FALSA = 'ver comentario arriba: el servidor nunca dice que un correo no existe';

/* -------------------------------------------------------------------
   Higiene de memoria
   ------------------------------------------------------------------- */

/**
 * Borra la privada de la memoria. Se llama al salir, al cerrar la
 * pestaña y tras un rato de inactividad — las tres, no solo la
 * primera: nadie pulsa "Salir", cierra la pestaña.
 *
 * Dicho lo que es: esto no es una garantía fuerte. JavaScript puede
 * haber dejado copias que no controlamos, y quien tenga el dispositivo
 * desbloqueado tiene más problemas que este. Reduce la ventana, no la
 * cierra, y conviene no venderlo como más de lo que es.
 */
export function olvidarPrivada(privada) {
  try { sodio.memzero(privada); return true; } catch (_) { return false; }
}

/** Engancha el borrado a los tres momentos. Devuelve cómo desengancharlo. */
export function vigilarSesion(dameLaPrivada, minutosInactividad = 15) {
  let reloj;
  const borrar = () => { const p = dameLaPrivada(); if (p) olvidarPrivada(p); };
  const rearmar = () => {
    clearTimeout(reloj);
    reloj = setTimeout(borrar, minutosInactividad * 60 * 1000);
  };
  const eventos = ['mousedown', 'keydown', 'touchstart', 'scroll'];
  eventos.forEach((e) => document.addEventListener(e, rearmar, { passive: true }));
  window.addEventListener('pagehide', borrar);
  rearmar();
  return () => {
    clearTimeout(reloj);
    eventos.forEach((e) => document.removeEventListener(e, rearmar));
    window.removeEventListener('pagehide', borrar);
  };
}
