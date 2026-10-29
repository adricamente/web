/* «La cuenta, por dentro»: el teléfono con las cinco pantallas de la
   cuenta. Lo genera rueda/portada_cuenta.py; no se edita aquí.

   Lo que hace y por qué:
   · Pestañas con el patrón de WAI-ARIA: flechas, Inicio y Fin, y
     activación al enfocar, porque cambiar de pantalla no cuesta nada.
   · Pasa solo, despacio (7 s), UNA vuelta y se para en la primera: dar
     vueltas para siempre es un anuncio, no una explicación. Se pausa
     mientras el ratón está encima, mientras hay foco dentro y cuando no
     se ve; se para del todo en cuanto alguien toca, desliza o usa el
     teclado. Con movimiento reducido no arranca, no hay dedo y el cambio
     es instantáneo.
   · Sin JavaScript las cinco capturas se ven en fila y quietas: lo
     resuelve el CSS con la clase tc-js, que pone una línea en el HTML
     antes de pintar el componente, para que no salte al cargar. */
(function(){
  var raiz = document.querySelector('.tc.tc-js');
  if(!raiz || raiz.dataset.listo) return;
  raiz.dataset.listo = '1';

  var tabs = [].slice.call(raiz.querySelectorAll('[role="tab"]'));
  var paneles = tabs.map(function(t){ return document.getElementById(t.getAttribute('aria-controls')); });
  var zona = raiz.querySelector('.tc-paneles');
  var pausa = raiz.querySelector('.tc-pausa');
  var dedo = raiz.querySelector('.tc-dedo');
  var quieto = !window.matchMedia || matchMedia('(prefers-reduced-motion: reduce)').matches;
  var actual = 0, parado = quieto, visto = false, contado = false;
  var motivos = { raton: false, foco: false, fuera: true, oculta: false };

  // La fila desplazable solo hace falta sin guion: aquí deja de ser una
  // región enfocable para no meter una parada de tabulador vacía.
  zona.removeAttribute('tabindex'); zona.removeAttribute('role'); zona.removeAttribute('aria-label');

  function anima(el, marcos, opciones){
    if(quieto || !el || !el.animate) return null;
    return el.animate(marcos, opciones);
  }

  function elige(n, dir){
    if(n === actual) return;
    var viejo = paneles[actual], nuevo = paneles[n];
    tabs.forEach(function(t, i){
      t.setAttribute('aria-selected', i === n ? 'true' : 'false');
      t.tabIndex = i === n ? 0 : -1;
    });
    viejo.hidden = true; viejo.removeAttribute('tabindex');
    nuevo.hidden = false; nuevo.tabIndex = 0;
    actual = n;
    if(quieto) return;

    // Empuje de lado, como en el propio móvil: la nueva entra entera y la
    // vieja se aparta un tercio y se apaga. Cada pantalla recorta lo
    // suyo con su esquina redonda, así que las dos parecen una.
    var sale = viejo.querySelector('.tc-lamina'), entra = nuevo.querySelector('.tc-lamina');
    var curva = 'cubic-bezier(.22,.8,.24,1)';
    // Con flechas seguidas se pisan los cambios: lo que quedaba a medias
    // se cancela antes de poner las clases, porque al cancelarse limpia.
    paneles.forEach(function(p){
      p.querySelectorAll('.tc-lamina,.tc-frase').forEach(function(x){ if(x.getAnimations) x.getAnimations().forEach(function(k){ k.cancel(); }); });
      if(p !== viejo && p !== nuevo) p.classList.remove('tc-sale', 'tc-entra');
    });
    viejo.classList.add('tc-sale'); nuevo.classList.add('tc-entra');
    var a = anima(sale, [{ transform: 'translateX(0)', opacity: 1 }, { transform: 'translateX(' + (-32 * dir) + '%)', opacity: .35 }], { duration: 520, easing: curva });
    anima(entra, [{ transform: 'translateX(' + (100 * dir) + '%)' }, { transform: 'translateX(0)' }], { duration: 520, easing: curva });
    // La frase vieja se apaga pronto pero sin fill:'forwards': un relleno
    // que se queda la dejaba invisible la siguiente vez que volvía. Dura
    // lo mismo que el empuje y acaba cuando el panel se oculta.
    anima(viejo.querySelector('.tc-frase'), [{ opacity: 1 }, { opacity: 0, offset: .35 }, { opacity: 0 }], { duration: 520 });
    anima(nuevo.querySelector('.tc-frase'), [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 380, delay: 160, easing: 'ease-out', fill: 'backwards' });
    var limpia = function(){ viejo.classList.remove('tc-sale'); nuevo.classList.remove('tc-entra'); };
    if(a) a.onfinish = a.oncancel = limpia; else limpia();

    // La gráfica se dibuja: la tapa blanca sale hacia la derecha.
    var traza = nuevo.querySelector('.tc-traza span');
    anima(traza, [{ transform: 'translateX(0)' }, { transform: 'translateX(100%)' }], { duration: 1500, delay: 380, easing: 'cubic-bezier(.45,0,.25,1)', fill: 'backwards' });
  }

  // ---- El pase solo ----
  function refresca(){
    var p = motivos.raton || motivos.foco || motivos.fuera || motivos.oculta;
    raiz.classList.toggle('tc-quieta', p);
  }
  function arranca(){
    if(quieto) return;
    parado = false;
    raiz.classList.add('tc-auto');
    pausa.classList.remove('tc-sigue'); pausa.lastChild.textContent = 'Pausar';
  }
  function para(){
    parado = true;
    raiz.classList.remove('tc-auto');
    pausa.classList.add('tc-sigue'); pausa.lastChild.textContent = 'Reanudar';
  }
  function toque(){
    if(!contado){ contado = true; if(window.medir) window.medir('cuenta_telefono_toque'); }
    if(!parado) para();
    if(dedoVuela){ dedoVuela.cancel(); dedoVuela = null; }
  }
  raiz.addEventListener('animationend', function(e){
    if(e.animationName !== 'tc-avance' || parado) return;
    var n = (actual + 1) % tabs.length;
    elige(n, 1);
    if(n === 0) para();
  });
  pausa.addEventListener('click', function(){
    if(parado){ arranca(); } else { toque(); }
  });

  raiz.addEventListener('pointerenter', function(e){ if(e.pointerType === 'mouse'){ motivos.raton = true; refresca(); } });
  raiz.addEventListener('pointerleave', function(e){ if(e.pointerType === 'mouse'){ motivos.raton = false; refresca(); } });
  raiz.addEventListener('focusin', function(){ motivos.foco = true; refresca(); });
  raiz.addEventListener('focusout', function(e){ if(!raiz.contains(e.relatedTarget)){ motivos.foco = false; refresca(); } });
  document.addEventListener('visibilitychange', function(){ motivos.oculta = document.hidden; refresca(); });

  // ---- Pestañas: clic y teclado ----
  tabs.forEach(function(t, i){
    t.addEventListener('click', function(){ toque(); elige(i, i > actual ? 1 : -1); });
  });
  raiz.querySelector('[role="tablist"]').addEventListener('keydown', function(e){
    var n = { ArrowRight: actual + 1, ArrowDown: actual + 1, ArrowLeft: actual - 1, ArrowUp: actual - 1, Home: 0, End: tabs.length - 1 }[e.key];
    if(n === undefined) return;
    e.preventDefault(); toque();
    n = (n + tabs.length) % tabs.length;
    var dir = (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === 'End') ? 1 : -1;
    elige(n, dir); tabs[n].focus();
  });

  // ---- Deslizar con el dedo ----
  // touch-action:pan-y deja el desplazamiento vertical al navegador y nos
  // da el horizontal. La pantalla sigue al dedo a un tercio de la
  // distancia, para que se note que se puede arrastrar, y en los
  // extremos no da la vuelta: frena.
  var x0 = null, y0 = 0, lado = false, arrastre = null;
  zona.style.touchAction = 'pan-y';
  zona.addEventListener('pointerdown', function(e){
    if(e.pointerType === 'mouse' && e.button !== 0) return;
    x0 = e.clientX; y0 = e.clientY; lado = false;
    arrastre = paneles[actual].querySelector('.tc-lamina');
  });
  zona.addEventListener('pointermove', function(e){
    if(x0 === null) return;
    var dx = e.clientX - x0, dy = e.clientY - y0;
    if(!lado && Math.abs(dx) > 10 && Math.abs(dx) > Math.abs(dy)){ lado = true; toque(); }
    if(lado && !quieto && arrastre){
      var fin = (dx > 0 && actual === 0) || (dx < 0 && actual === tabs.length - 1);
      arrastre.style.transform = 'translateX(' + (dx * (fin ? .12 : .33)) + 'px)';
    }
  });
  function suelta(e){
    if(x0 === null) return;
    var dx = e.clientX - x0, lam = arrastre;
    x0 = null; arrastre = null;
    if(lam){ lam.style.transform = ''; }
    if(!lado || e.type === 'pointercancel') return;
    var n = dx < -40 ? actual + 1 : dx > 40 ? actual - 1 : actual;
    if(n >= 0 && n < tabs.length && n !== actual){ elige(n, dx < 0 ? 1 : -1); }
    else if(lam){ anima(lam, [{ transform: 'translateX(' + (dx * .2) + 'px)' }, { transform: 'none' }], { duration: 260, easing: 'ease-out' }); }
  }
  zona.addEventListener('pointerup', suelta);
  zona.addEventListener('pointercancel', suelta);

  // ---- El dedo dibujado ----
  // La primera vez que el teléfono se ve entero, un dedo va hasta la
  // segunda pestaña y la «toca»: enseña que esto se toca sin un cartel
  // que lo diga. Una sola vez, y nunca si ya se ha tocado algo.
  var dedoVuela = null;
  function dedoHint(){
    if(quieto || parado || !dedo.animate) return;
    // Fuera de su vuelo no existe (display:none): quieto y transparente
    // seguía tapando la pestaña para axe (target-size). Se enseña antes
    // de medir, porque oculto no tiene ancho ni caja de referencia.
    dedo.style.display = 'block';
    // El dedo vive dentro de .tc-escena, que es su caja de referencia.
    var objetivo = tabs[1], r = dedo.offsetParent.getBoundingClientRect();
    var t = objetivo.getBoundingClientRect(), p = zona.getBoundingClientRect();
    var ancho = dedo.offsetWidth, yema = { x: ancho * 18.5 / 40, y: ancho * 4 / 40 };
    var tx = t.left + t.width / 2 - r.left - yema.x, ty = t.top + t.height / 2 - r.top - yema.y;
    var sx = p.left + p.width * .62 - r.left, sy = p.top + p.height * .48 - r.top;
    var en = function(x, y, s){ return 'translate(' + x + 'px,' + y + 'px) scale(' + s + ')'; };
    dedoVuela = dedo.animate([
      { opacity: 0, transform: en(sx, sy + 24, 1) },
      { opacity: 1, transform: en(sx, sy, 1), offset: .16 },
      { opacity: 1, transform: en(tx, ty, 1), offset: .62, easing: 'ease-in' },
      { opacity: 1, transform: en(tx, ty + 3, .86), offset: .72 },
      { opacity: 1, transform: en(tx, ty, 1), offset: .82 },
      { opacity: 0, transform: en(tx + 16, ty + 26, 1) }
    ], { duration: 2300, easing: 'cubic-bezier(.4,0,.2,1)' });
    // El toque cae en el 72 %: ahí sale la onda y cambia la pantalla.
    setTimeout(function(){
      if(!dedoVuela || parado) return;
      var onda = document.createElement('span');
      onda.className = 'tc-onda';
      onda.style.left = (t.width / 2) + 'px'; onda.style.top = (t.height / 2) + 'px';
      objetivo.appendChild(onda);
      var o = onda.animate([{ opacity: .28, transform: 'scale(.2)' }, { opacity: 0, transform: 'scale(1.6)' }], { duration: 600, easing: 'ease-out' });
      o.onfinish = function(){ onda.remove(); };
      elige(1, 1);
    }, 2300 * .72);
    dedoVuela.onfinish = dedoVuela.oncancel = function(){ dedoVuela = null; dedo.style.display = ''; };
  }

  // ---- Cuándo se ve ----
  if('IntersectionObserver' in window){
    new IntersectionObserver(function(es){
      var e = es[0];
      motivos.fuera = e.intersectionRatio < .5; refresca();
      if(!visto && e.intersectionRatio >= .5){
        visto = true;
        if(!quieto){ arranca(); setTimeout(dedoHint, 900); }
      }
    }, { threshold: [0, .5, .8] }).observe(raiz.querySelector('.tc-escena'));
  }
})();
