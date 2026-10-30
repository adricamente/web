/* La rueda de las emociones, para la cuenta. Generado por
   genera_rueda_web.py desde rueda-componente.js y rueda.json: no se edita a
   mano. Se carga solo cuando hace falta. Los datos son la clave `rueda` de
   catalogo_herramientas.json (Offline), los mismos que la web pública.
   Psicoeducación, sin datos de nadie, y nada de lo que se pulsa sale del
   navegador. */
/* La rueda de las emociones: el dibujo, compartido por la web y la cuenta.
   Diseño y texto propios (Offline, src/docs_paciente/rueda_emociones.py);
   los datos son la clave `rueda` de catalogo_herramientas.json.
   pintaRueda(svg, D, alElegir): dibuja y llama alElegir(familia, palabra|null,
   nivel) al pulsar (ratón, toque, Enter o espacio). No guarda ni envía nada. */
function pintaRueda(svg, D, alElegir) {
  var NS = 'http://www.w3.org/2000/svg', R = [0, 58, 109, 158, 206];
  while (svg.firstChild) svg.removeChild(svg.firstChild);
  svg.setAttribute('viewBox', '-210 -210 420 420');
  function pol(r, a) { return [r * Math.cos(a), r * Math.sin(a)]; }
  function sector(r0, r1, a0, a1) {
    var g = a1 - a0 > Math.PI ? 1 : 0, p0 = pol(r1, a0), p1 = pol(r1, a1), p2 = pol(r0, a1), p3 = pol(r0, a0);
    return r0 === 0 ? 'M0 0L' + p0[0] + ' ' + p0[1] + 'A' + r1 + ' ' + r1 + ' 0 ' + g + ' 1 ' + p1[0] + ' ' + p1[1] + 'Z'
      : 'M' + p0[0] + ' ' + p0[1] + 'A' + r1 + ' ' + r1 + ' 0 ' + g + ' 1 ' + p1[0] + ' ' + p1[1] +
        'L' + p2[0] + ' ' + p2[1] + 'A' + r0 + ' ' + r0 + ' 0 ' + g + ' 0 ' + p3[0] + ' ' + p3[1] + 'Z';
  }
  function rgb(hex) { var n = parseInt(hex.slice(1), 16); return [n >> 16, (n >> 8) & 255, n & 255]; }
  function aclara(c, f) { return c.map(function (v) { return Math.round(v + (255 - v) * f); }); }
  function lum(c) { var l = c.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2]; }
  /* El texto, oscuro o blanco: el que más contraste dé sobre ese color. */
  var OSC = [31, 42, 55];
  function tinta(c) { var L = lum(c), o = (L + 0.05) / (lum(OSC) + 0.05), b = 1.05 / (L + 0.05); return o >= b ? '#1f2a37' : '#ffffff'; }
  function css(c) { return 'rgb(' + c.join(',') + ')'; }
  function texto(x, y, t, cls, rot, color) {
    var e = document.createElementNS(NS, 'text');
    e.setAttribute('x', x); e.setAttribute('y', y); e.setAttribute('text-anchor', 'middle');
    e.setAttribute('dominant-baseline', 'central'); e.setAttribute('class', cls); e.setAttribute('fill', color);
    e.setAttribute('aria-hidden', 'true');
    e.setAttribute('transform', 'rotate(' + rot + ' ' + x + ' ' + y + ')');
    e.textContent = t; svg.appendChild(e);
  }
  function giro(a) { var r = a * 180 / Math.PI; if (r > 90 && r < 270) r -= 180; if (r < -90) r += 180; return r; }
  var marcada = null, botones = [];
  /* Una sola parada de Tab para toda la rueda (28-10): con un tabindex="0"
     por sector, salir de la rueda costaba 59 pulsaciones. Dentro, las
     flechas recorren los sectores en el orden en que se dibujan.
     aria-pressed dice cuál está elegida; la clase .sel solo se veía. */
  function boton(p, etiqueta, fn) {
    p.setAttribute('tabindex', botones.length ? '-1' : '0'); p.setAttribute('role', 'button');
    p.setAttribute('aria-label', etiqueta); p.setAttribute('aria-pressed', 'false');
    botones.push(p);
    p.addEventListener('click', function () {
      if (marcada) { marcada.classList.remove('sel'); marcada.setAttribute('aria-pressed', 'false'); }
      marcada = p; p.classList.add('sel'); p.setAttribute('aria-pressed', 'true');
      botones.forEach(function (b) { b.setAttribute('tabindex', b === p ? '0' : '-1'); });
      fn(true);
    });
    p.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); p.dispatchEvent(new MouseEvent('click')); return; }
      var i = botones.indexOf(p), n = botones.length, j = -1;
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') j = (i + 1) % n;
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') j = (i - 1 + n) % n;
      else if (e.key === 'Home') j = 0;
      else if (e.key === 'End') j = n - 1;
      if (j < 0) return;
      e.preventDefault();
      p.setAttribute('tabindex', '-1'); botones[j].setAttribute('tabindex', '0'); botones[j].focus();
    });
  }
  var n = D.familias.length, paso = 2 * Math.PI / n, ini = -Math.PI / 2 - paso / 2, etiquetas = [];
  D.familias.forEach(function (f, i) {
    var a0 = ini + i * paso, a1 = a0 + paso, am = (a0 + a1) / 2, base = rgb(f.color);
    var pc = document.createElementNS(NS, 'path');
    pc.setAttribute('d', sector(0, R[1], a0, a1)); pc.setAttribute('fill', f.color); pc.setAttribute('class', 'sector fam');
    boton(pc, f.nombre + ': familia', function (d) { alElegir(f, null, null, d); });
    svg.appendChild(pc);
    var pf = pol(R[1] * 0.62, am);
    var nf = f.nombre.split(' ')[0];
    etiquetas.push([pf[0], pf[1], nf, 'et-fam' + (nf.length > 8 ? ' larga' : ''), giro(am), tinta(base)]);
    f.niveles.forEach(function (nv, k) {
      var sub = paso / nv.length, fondo = aclara(base, [0.62, 0.38, 0.12][k]), color = tinta(fondo);
      nv.forEach(function (pal, j) {
        var b0 = a0 + j * sub, b1 = b0 + sub, bm = (b0 + b1) / 2;
        var p = document.createElementNS(NS, 'path');
        p.setAttribute('d', sector(R[k + 1], R[k + 2], b0, b1)); p.setAttribute('fill', css(fondo)); p.setAttribute('class', 'sector');
        boton(p, pal.palabra + ', ' + f.nombre + ', intensidad ' + D.niveles[k], function (d) { alElegir(f, pal, k, d); });
        svg.appendChild(p);
        var rm = (R[k + 1] + R[k + 2]) / 2, q = pol(rm, bm);
        var et = pal.palabra.replace(/\s*\/.*$/, '').replace('Sentirme abandonada', 'Abandono');
        etiquetas.push([q[0], q[1], et, 'et-anillo' + (et.length > 10 ? ' larga' : ''), giro(bm), color]);
      });
    });
  });
  /* Las etiquetas, encima de todos los sectores y sin recibir el toque. */
  etiquetas.forEach(function (e) { texto.apply(null, e); });
  /* En el móvil la rueda entera cabe, pero las palabras salen pequeñas:
     «Ampliar la rueda» la dibuja al doble y se recorre con el dedo. */
  var caja = svg.parentNode, amp = null;
  if (caja && caja.classList && caja.classList.contains('rueda-caja')) {
    amp = document.createElement('button');
    amp.type = 'button'; amp.className = 'rueda-ampliar'; amp.textContent = 'Ampliar la rueda';
    amp.setAttribute('aria-pressed', 'false');
    amp.addEventListener('click', function () {
      var si = !caja.classList.contains('ampliada');
      caja.classList.toggle('ampliada', si);
      amp.textContent = si ? 'Ver la rueda entera' : 'Ampliar la rueda';
      amp.setAttribute('aria-pressed', String(si));
      if (si) { caja.scrollLeft = (caja.scrollWidth - caja.clientWidth) / 2; caja.scrollTop = (caja.scrollHeight - caja.clientHeight) / 2; }
    });
    caja.insertAdjacentElement('afterend', amp);
  }
  function mide() {
    var w = (caja && caja.clientWidth) || svg.getBoundingClientRect().width;
    if (amp) amp.hidden = !(w > 0 && w < 560) && !caja.classList.contains('ampliada');
  }
  mide();
  if (window.ResizeObserver) new ResizeObserver(mide).observe(caja || svg); else window.addEventListener('resize', mide);
}

window.pintaRueda = pintaRueda;
window.RUEDA_DATOS = {"version":"1","niveles":["suave","media","intensa"],"familias":[{"id":"alegria","nombre":"Alegría","color":"#F2B705","avisa":"Algo que te importa va bien o te acerca a lo que quieres.","cuerpo":"Ligereza, energía, sonrisa, ganas de moverte.","impulso":"Acercarte, compartirlo, repetirlo.","niveles":[[{"palabra":"Satisfacción","que_es":"Notas que algo ha salido como querías, aunque sea pequeño."},{"palabra":"Agrado","que_es":"Algo te resulta agradable y te apetece que siga."}],[{"palabra":"Alegría","que_es":"Te sientes bien por algo que ha pasado o que estás viviendo."},{"palabra":"Ilusión","que_es":"Esperas con ganas algo que va a pasar."},{"palabra":"Orgullo","que_es":"Valoras algo que has hecho tú o alguien cercano, con esfuerzo."}],[{"palabra":"Euforia","que_es":"Una alegría tan fuerte que cuesta frenar o pensar con calma."},{"palabra":"Entusiasmo","que_es":"Mucha energía y ganas de lanzarte a algo."}]]},{"id":"calma","nombre":"Calma","color":"#7FB77E","avisa":"Ahora mismo no hay amenaza: puedes descansar y recuperarte.","cuerpo":"Respiración lenta, músculos sueltos, cuerpo pesado y cómodo.","impulso":"Parar, descansar, disfrutar sin prisa.","niveles":[[{"palabra":"Tranquilidad","que_es":"No hay nada urgente; estás a gusto donde estás."},{"palabra":"Confianza","que_es":"Sientes que puedes contar con alguien o contigo."}],[{"palabra":"Alivio","que_es":"Se ha ido algo que te preocupaba o te pesaba."},{"palabra":"Gratitud","que_es":"Reconoces algo bueno que alguien ha hecho por ti."}],[{"palabra":"Paz","que_es":"Una calma profunda, sin nada pendiente que te tire."},{"palabra":"Plenitud","que_es":"Sientes que tu vida, en este momento, está completa."}]]},{"id":"sorpresa","nombre":"Sorpresa","color":"#4FB0C6","avisa":"Ha pasado algo que no esperabas: presta atención.","cuerpo":"Ojos abiertos, respiración cortada un instante, cuerpo quieto.","impulso":"Parar y mirar qué ha pasado.","niveles":[[{"palabra":"Extrañeza","que_es":"Algo no encaja con lo que esperabas, pero sin alarma."}],[{"palabra":"Sorpresa","que_es":"Te pilla algo de nuevas y necesitas un momento para entenderlo."}],[{"palabra":"Asombro","que_es":"Algo te impresiona mucho, para bien o para mal."},{"palabra":"Sobresalto","que_es":"Un susto repentino que te hace dar un respingo."}]]},{"id":"miedo","nombre":"Miedo","color":"#8E7CC3","avisa":"Puede haber un peligro o algo que se te escapa de las manos.","cuerpo":"Corazón rápido, tensión, nudo en el estómago, respiración corta.","impulso":"Huir, evitar, protegerte o prepararte de más.","niveles":[[{"palabra":"Inquietud","que_es":"Una intranquilidad de fondo, sin saber bien por qué."},{"palabra":"Preocupación","que_es":"Le das vueltas a algo que podría salir mal."},{"palabra":"Inseguridad","que_es":"Dudas de si sabrás hacerlo o de si estarás a la altura."}],[{"palabra":"Nervios","que_es":"Activación antes de algo que te importa."},{"palabra":"Agobio","que_es":"Sientes que hay más cosas de las que puedes llevar a la vez."},{"palabra":"Ansiedad","que_es":"Miedo a algo que todavía no ha pasado y que tu cuerpo vive como si fuera ya."}],[{"palabra":"Pánico","que_es":"Un miedo muy intenso y repentino, con mucho cuerpo: parece que algo grave va a pasar."},{"palabra":"Terror","que_es":"Miedo extremo ante algo que vives como una amenaza seria."}]]},{"id":"tristeza","nombre":"Tristeza","color":"#5B7DB1","avisa":"Has perdido algo que te importa, o no lo tienes.","cuerpo":"Cansancio, pesadez, nudo en la garganta, ganas de llorar.","impulso":"Recogerte, bajar el ritmo, buscar consuelo.","niveles":[[{"palabra":"Desánimo","que_es":"Te faltan ganas; las cosas cuestan más."},{"palabra":"Decepción","que_es":"Algo o alguien no ha sido como esperabas."}],[{"palabra":"Tristeza","que_es":"Pena por algo que has perdido o que echas de menos."},{"palabra":"Soledad","que_es":"Echas en falta cercanía o compañía, aunque haya gente cerca."},{"palabra":"Sentirme abandonada/o","que_es":"Notas que alguien importante no está cuando lo necesitas."}],[{"palabra":"Desolación","que_es":"Una tristeza muy honda, como si no quedara nada que te sostenga."},{"palabra":"Desesperanza","que_es":"Sientes que las cosas no van a mejorar."}]]},{"id":"rabia","nombre":"Rabia","color":"#D9534F","avisa":"Algo te parece injusto, te bloquea o cruza un límite tuyo.","cuerpo":"Calor, mandíbula y puños apretados, energía para actuar.","impulso":"Atacar, quejarte, defenderte o poner un límite.","niveles":[[{"palabra":"Molestia","que_es":"Algo te incomoda o te fastidia un poco."},{"palabra":"Impaciencia","que_es":"Las cosas van más lentas de lo que necesitas."}],[{"palabra":"Enfado","que_es":"Te sientes mal tratada/o o algo no es como debería."},{"palabra":"Frustración","que_es":"Quieres algo y algo o alguien te lo impide."},{"palabra":"Impotencia","que_es":"Hay algo injusto y sientes que no puedes hacer nada."}],[{"palabra":"Indignación","que_es":"Rabia por algo que te parece muy injusto."},{"palabra":"Furia","que_es":"Una rabia tan fuerte que cuesta controlar lo que dices o haces."}]]},{"id":"asco","nombre":"Asco y rechazo","color":"#6B8E23","avisa":"Algo te parece dañino, sucio o contrario a tus valores.","cuerpo":"Náusea, arrugar la nariz, apartar la cara o el cuerpo.","impulso":"Alejarte, rechazar, apartarlo de ti.","niveles":[[{"palabra":"Desagrado","que_es":"Algo no te gusta y prefieres que no esté cerca."}],[{"palabra":"Rechazo","que_es":"No quieres tener nada que ver con algo o con alguien."},{"palabra":"Asco","que_es":"Algo te provoca repulsión, físicamente o por lo que significa."}],[{"palabra":"Desprecio","que_es":"Ves a alguien por debajo, como si no mereciera respeto."},{"palabra":"Repugnancia","que_es":"Un asco tan fuerte que te cuesta incluso pensarlo."}]]},{"id":"verguenza","nombre":"Vergüenza y culpa","color":"#C27BA0","avisa":"Algo pone en juego cómo te ven, o has hecho algo contra lo que valoras.","cuerpo":"Calor en la cara, mirada baja, ganas de hacerte pequeña/o.","impulso":"Esconderte (vergüenza) o reparar el daño (culpa).","niveles":[[{"palabra":"Apuro","que_es":"Te incomoda que te vean en una situación concreta."},{"palabra":"Timidez","que_es":"Te cuesta mostrarte ante otros, sobre todo si no los conoces."}],[{"palabra":"Vergüenza","que_es":"Sientes que hay algo en ti que está mal y temes que los demás lo vean."},{"palabra":"Culpa","que_es":"Sientes que has hecho algo mal y te pesa el daño causado."},{"palabra":"Arrepentimiento","que_es":"Te gustaría no haber hecho (o haber hecho) algo."}],[{"palabra":"Humillación","que_es":"Te sientes rebajada/o delante de otros."},{"palabra":"Autodesprecio","que_es":"Te tratas como si no valieras nada."}]]}],"mezclas":[{"palabra":"Celos","que_es":"Miedo a perder a alguien + rabia + tristeza."},{"palabra":"Sentirme traicionada/o","que_es":"Rabia + tristeza: se ha roto la confianza."},{"palabra":"Envidia","que_es":"Tristeza o rabia porque otra persona tiene lo que tú quieres."},{"palabra":"Estrés","que_es":"Agobio + nervios: muchas demandas y poco margen. A menudo con rabia o cansancio detrás."},{"palabra":"Nostalgia","que_es":"Tristeza + alegría al recordar algo bueno que ya pasó."}],"no_son_emociones":[{"palabra":"Cansada/o","que_es":"Es el cuerpo. Pregúntate qué hay además: ¿tristeza, agobio, rabia?"},{"palabra":"Procrastinación","que_es":"Es lo que haces (dejarlo para luego). ¿Qué sentías antes de dejarlo: agobio, miedo, aburrimiento?"},{"palabra":"Vengativa/o","que_es":"Es un impulso: las ganas de devolverla. Suele venir de la rabia o de sentirte traicionada/o."},{"palabra":"Pacifista","que_es":"Es una postura o una decisión. ¿Qué sentías: calma, cansancio, ganas de evitar el conflicto?"},{"palabra":"Bloqueada/o","que_es":"Es lo que te pasa (no puedes actuar). Suele venir del miedo o del agobio."},{"palabra":"Ignorada/o","que_es":"Es lo que crees que hacen contigo. ¿Qué sientes con eso: tristeza, rabia, soledad?"}],"como_usarla":["Empieza por el centro: ¿a qué familia se parece lo que sientes?","Sal hacia fuera: elige la palabra que mejor encaja y mira su intensidad (suave, media, intensa).","Puede haber varias a la vez. Apunta todas, cada una con su número de 0 a 100.","Si todo te sale al 100, prueba con las palabras suaves: ahí están las señales tempranas.","Ninguna emoción es mala: todas te avisan de algo. Lo que cuenta es qué haces después."]};
