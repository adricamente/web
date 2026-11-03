/* ===================================================================
   sw.js — el service worker de la cuenta (03-11)
   -------------------------------------------------------------------
   Existe por una sola razón: Chrome solo lanza `beforeinstallprompt`
   (el botón «Añadir a mi pantalla de inicio» de un toque) si la página
   tiene un service worker con manejador de `fetch`. Lo dice
   developer.chrome.com/blog/update-install-criteria: el menú ya no lo
   exige desde Chrome 108, el aviso de instalar todavía sí.

   Lo que NO hace, a propósito: guardar nada de la cuenta. Ni la API, ni
   el HTML del panel (lleva el correo de quien tiene la sesión abierta),
   ni libsodium, ni las hojas de estilo. Lo único que guarda es
   offline.html, una página genérica sin datos.

   - Solo toca las navegaciones (abrir una página). Todo lo demás —la
     API, los guiones, las imágenes— ni pasa por aquí: el navegador lo
     pide como si no existiera.
   - Una navegación va siempre a la red. Solo si la red falla se enseña
     offline.html, y lo que llega de la red no se guarda.
   =================================================================== */
const CAJA = 'adr-sin-conexion-v1';

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CAJA)
    .then((c) => c.add(new Request('offline.html', { cache: 'reload' })))
    .then(() => self.skipWaiting()));
});

/* Las cajas de versiones anteriores, fuera: si un día esto guardó algo
   más, que no se quede ahí. */
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((ks) => Promise.all(ks.filter((k) => k !== CAJA).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  if (e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(() => caches.match('offline.html')));
});
