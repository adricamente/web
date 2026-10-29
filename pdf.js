/* ================================================================
   adricamente.com/pdf.js — los descargables en PDF (28-10)
   ----------------------------------------------------------------
   Hace el PDF en el propio navegador y lo descarga. Nada sale del
   dispositivo: no hay servidor, ni librería de terceros, ni fuentes
   externas. Escrito a mano para esta web: Helvetica (las fuentes
   estándar del PDF, que lleva cualquier lector), los colores de la
   web y la misma firma que el resto de documentos.

   Uso:
     adriPDF.descarga({
       archivo: 'mi-mapa-2026-10-28.pdf',
       titulo: 'Mi mapa de lo que evitas',
       subtitulo: 'Lo que escribiste el 28 de octubre de 2026',
       bloques: [
         {tipo:'caja',  titulo:'Tu patrón', texto:'…'},
         {tipo:'campo', etiqueta:'Lo que llevas tiempo esquivando', texto:'…'},
         {tipo:'h', texto:'…'}, {tipo:'p', texto:'…'},
         {tipo:'lista', items:['…','…'], numerada:true},
         {tipo:'linea', etiqueta:'Día y hora'},
         {tipo:'tabla', columnas:[{t:'Fecha', w:1}, …], filas:[['…', …]]}
       ]
     });
   ================================================================ */
(function () {
  'use strict';
  // Anchos de Helvetica y Helvetica-Bold en WinAnsi (32..255), en milésimas de em.
  var WR = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,0,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,552,400,549,333,333,333,576,537,333,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,549,611,556,556,556,556,500,556,500];
  var WB = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,0,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,552,400,549,333,333,333,576,556,333,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,549,611,611,611,611,611,556,611,556];
  // Unicode -> WinAnsi (lo que no está en Latin-1).
  var W1252 = {0x20AC:128,0x201A:130,0x0192:131,0x201E:132,0x2026:133,0x2020:134,0x2021:135,0x02C6:136,0x2030:137,0x0160:138,0x2039:139,0x0152:140,0x017D:142,0x2018:145,0x2019:146,0x201C:147,0x201D:148,0x2022:149,0x2013:150,0x2014:151,0x02DC:152,0x2122:153,0x0161:154,0x203A:155,0x0153:156,0x017E:158,0x0178:159};
  var OTROS = {0x2713:'v', 0x2192:'->', 0x2190:'<-', 0x00A0:' ', 0x2009:' ', 0x202F:' ', 0x2212:'-', 0x2264:'<=', 0x2265:'>='};

  function a1252(s) {
    s = String(s == null ? '' : s).replace(/\r/g, '');
    var out = '';
    for (var i = 0; i < s.length; i++) {
      var c = s.charCodeAt(i);
      if (c === 10) { out += '\n'; continue; }
      if (c >= 32 && c < 127) out += s[i];
      else if (c >= 160 && c <= 255) out += String.fromCharCode(c === 160 ? 32 : c);
      else if (W1252[c]) out += String.fromCharCode(W1252[c]);
      else if (OTROS[c]) out += OTROS[c];
      else if (c >= 0xD800 && c <= 0xDBFF) { i++; }           // emojis: fuera
      else { var n = s[i].normalize ? s[i].normalize('NFD')[0] : '?'; out += (n.charCodeAt(0) < 256 ? n : '?'); }
    }
    return out;
  }
  function ancho(s, bold, tam) {
    var w = bold ? WB : WR, t = 0;
    for (var i = 0; i < s.length; i++) { var c = s.charCodeAt(i); t += (c >= 32 && c <= 255) ? (w[c - 32] || 500) : 500; }
    return t * tam / 1000;
  }
  function parte(s, bold, tam, max) {
    var lineas = [];
    a1252(s).split('\n').forEach(function (par) {
      var pal = par.split(' '), l = '';
      pal.forEach(function (p) {
        var prueba = l ? l + ' ' + p : p;
        if (ancho(prueba, bold, tam) <= max || !l) {
          while (ancho(prueba, bold, tam) > max && prueba.length > 1) {   // palabra más larga que la línea
            var k = prueba.length;
            while (k > 1 && ancho(prueba.slice(0, k), bold, tam) > max) k--;
            lineas.push(prueba.slice(0, k)); prueba = prueba.slice(k);
          }
          l = prueba;
        } else { lineas.push(l); l = p; }
      });
      lineas.push(l);
    });
    return lineas;
  }
  function esc(s) { return s.replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)'); }
  function rgb(h) { return [1, 3, 5].map(function (i) { return (parseInt(h.substr(i, 2), 16) / 255).toFixed(3); }).join(' '); }

  // Colores y medidas del estilo de documentos de paciente de adricamente.
  var C = { tinta: '#25231F', tinta2: '#52504A', azul: '#1F5474', gris: '#6C6963', azulSuave: '#E5EDF5', linea: '#E6E4DF', fuerte: '#CECCC6', borde: '#8E8B85' };
  var A4 = [595.28, 841.89], MI = 70.9, MD = 70.9, ARR = 70.9, ABA = 84, ANCHO = A4[0] - MI - MD;

  function Doc(meta) {
    this.meta = meta; this.paginas = []; this.nueva();
  }
  Doc.prototype.nueva = function () { this.s = []; this.paginas.push(this.s); this.y = A4[1] - ARR; this.cabecera(this.paginas.length === 1); };
  Doc.prototype.op = function (t) { this.s.push(t); };
  Doc.prototype.texto = function (x, y, t, bold, tam, color) {
    this.op('BT /' + (bold ? 'F2' : 'F1') + ' ' + tam + ' Tf ' + rgb(color || C.tinta) + ' rg ' + x.toFixed(2) + ' ' + y.toFixed(2) + ' Td (' + esc(t) + ') Tj ET');
  };
  Doc.prototype.rect = function (x, y, w, h, color) { this.op(rgb(color) + ' rg ' + [x, y, w, h].map(function (v) { return v.toFixed(2); }).join(' ') + ' re f'); };
  Doc.prototype.raya = function (x1, y1, x2, y2, color, grosor) { this.op(rgb(color) + ' RG ' + (grosor || 0.6) + ' w ' + x1.toFixed(2) + ' ' + y1.toFixed(2) + ' m ' + x2.toFixed(2) + ' ' + y2.toFixed(2) + ' l S'); };
  Doc.prototype.sitio = function (h) { if (this.y - h < ABA) { this.nueva(); return true; } return false; };
  // Cabecera solo en la primera página: la marca en minúscula y una línea fina.
  Doc.prototype.cabecera = function (primera) {
    if (!primera) { this.y = A4[1] - ARR; return; }
    var y = A4[1] - 52;
    this.texto(MI, y, 'adricamente', true, 13, C.azul);
    this.raya(MI, y - 9, A4[0] - MD, y - 9, C.fuerte, 0.5);
    this.y = y - 30;
  };
  Doc.prototype.lineas = function (txt, bold, tam, color, x, max, inter) {
    var ls = parte(txt, bold, tam, max), self = this;
    ls.forEach(function (l) { self.sitio(inter); self.texto(x, self.y - tam, l, bold, tam, color); self.y -= inter; });
  };
  Doc.prototype.titulo = function (t, sub) {
    this.lineas(t, true, 22, C.tinta, MI, ANCHO, 27);
    if (sub) { this.y -= 4; this.lineas(sub, false, 12.5, C.tinta2, MI, ANCHO, 17); }
    this.y -= 14;
  };
  Doc.prototype.bloque = function (b) {
    var self = this;
    if (b.tipo === 'h') { this.y -= 12; this.sitio(40); this.lineas(b.texto, true, 14, C.tinta, MI, ANCHO, 18); this.y -= 6; }
    else if (b.tipo === 'p') { this.lineas(b.texto, false, 11, C.tinta, MI, ANCHO, 15); this.y -= 8; }
    else if (b.tipo === 'nota') { this.lineas(b.texto, false, 9.5, C.gris, MI, ANCHO, 13); this.y -= 6; }
    else if (b.tipo === 'campo') {
      this.sitio(42);
      this.lineas(b.etiqueta, true, 9, C.tinta2, MI, ANCHO, 12.5);
      this.y -= 2;
      this.lineas(b.texto && String(b.texto).trim() ? b.texto : '—', false, 11, C.tinta, MI, ANCHO, 15);
      this.y -= 4; this.raya(MI, this.y, A4[0] - MD, this.y, C.linea, 0.5); this.y -= 12;
    }
    else if (b.tipo === 'caja') {
      var pad = 12, tam = 11.5, inter = 16.5, max = ANCHO - pad * 2 - 4;
      var lt = b.titulo ? parte(b.titulo, true, 10, max) : [], lx = parte(b.texto, !!b.negrita, tam, max);
      var h = pad * 2 + lt.length * 14 + (lt.length ? 4 : 0) + lx.length * inter;
      if (h > A4[1] - ARR - ABA - 60) { // demasiado larga para una caja: como párrafos
        if (b.titulo) this.bloque({ tipo: 'h', texto: b.titulo });
        this.bloque({ tipo: 'p', texto: b.texto }); return;
      }
      this.sitio(h + 6);
      var top = this.y;
      if (b.color === 'azul') this.rect(MI, top - h, ANCHO, h, C.azulSuave);   // solo para destacar lo que hay que hacer
      this.rect(MI, top - h, 3, h, C.azul);
      var yy = top - pad;
      lt.forEach(function (l) { self.texto(MI + pad + 4, yy - 10, l, true, 10, C.tinta2); yy -= 14; });
      if (lt.length) yy -= 4;
      lx.forEach(function (l) { self.texto(MI + pad + 4, yy - tam, l, !!b.negrita, tam, C.tinta); yy -= inter; });
      this.y = top - h - 14;
    }
    else if (b.tipo === 'lista') {
      (b.items || []).forEach(function (it, i) {
        var marca = b.numerada ? (i + 1) + '.' : '·', sang = 18;
        var ls = parte(it, false, 11, ANCHO - sang);
        ls.forEach(function (l, k) {
          self.sitio(15.5);
          if (k === 0) self.texto(MI, self.y - 11, marca, true, 11, C.tinta);
          self.texto(MI + sang, self.y - 11, l, false, 11, C.tinta); self.y -= 15.5;
        });
        self.y -= 3;
      });
      this.y -= 6;
    }
    else if (b.tipo === 'linea') {   // rótulo encima y línea de escritura debajo, nunca guiones bajos
      var n = b.lineas || 1;
      this.sitio(16 + n * 26); this.y -= 4;
      this.texto(MI, this.y - 9, a1252(b.etiqueta), true, 9, C.tinta2); this.y -= 14;
      for (var k = 0; k < n; k++) { this.y -= 24; this.raya(MI, this.y, A4[0] - MD, this.y, C.borde, 0.5); this.y -= 2; }
      this.y -= 12;
    }
    else if (b.tipo === 'tabla') { this.tabla(b); }
  };
  Doc.prototype.tabla = function (b) {
    var self = this, cols = b.columnas, total = cols.reduce(function (s, c) { return s + (c.w || 1); }, 0);
    var pad = 5, tam = b.tam || 9.5, inter = tam + 3.5;
    // Que ninguna palabra se parta: cada columna mide al menos su palabra más larga,
    // y lo que sobra se reparte según el peso de cada una.
    var minimo = cols.map(function (c, i) {
      var m = 0;
      [[c.t, true]].concat((b.filas || []).map(function (r) { return [r[i], false]; })).forEach(function (par) {
        a1252(par[0] == null ? '' : String(par[0])).split(/[\s\n]+/).forEach(function (w) { m = Math.max(m, ancho(w, par[1], tam)); });
      });
      return Math.min(m + pad * 2 + 1, ANCHO * 0.45);
    });
    var anchos = cols.map(function (c) { return ANCHO * (c.w || 1) / total; });
    var falta = anchos.some(function (w, i) { return w < minimo[i]; });
    if (falta) {
      var fijo = minimo.reduce(function (x, y) { return x + y; }, 0), resto = Math.max(0, ANCHO - fijo);
      anchos = minimo.map(function (m, i) { return m + resto * (cols[i].w || 1) / total; });
      var suma = anchos.reduce(function (x, y) { return x + y; }, 0);
      if (suma > ANCHO) anchos = anchos.map(function (w) { return w * ANCHO / suma; });
    }
    function fila(celdas, cab) {
      var ls = celdas.map(function (t, i) { return parte(t == null || t === '' ? (cab ? '' : '—') : String(t), cab, tam, anchos[i] - pad * 2); });
      var n = Math.max.apply(null, ls.map(function (l) { return l.length; })), h = n * inter + pad * 2;
      return { ls: ls, h: h, cab: cab };
    }
    function pinta(f) {
      var top = self.y, x = MI;
      if (f.cab) self.rect(MI, top - f.h, ANCHO, f.h, C.azulSuave);
      f.ls.forEach(function (l, i) {
        l.forEach(function (t, k) { self.texto(x + pad, top - pad - tam - k * inter + 1.5, t, f.cab, tam, C.tinta); });
        x += anchos[i];
      });
      self.raya(MI, top - f.h, A4[0] - MD, top - f.h, C.linea, 0.5);
      self.y = top - f.h;
    }
    var cab = fila(cols.map(function (c) { return c.t; }), true);
    this.sitio(cab.h + 20); pinta(cab);
    (b.filas || []).forEach(function (r) {
      var f = fila(r, false);
      if (self.sitio(f.h)) pinta(cab);
      pinta(f);
    });
    this.y -= 16;
  };
  Doc.prototype.pies = function () {
    var self = this, n = this.paginas.length, f = this.meta.pie || [];
    this.paginas.forEach(function (s, i) {
      self.s = s;
      var y = 52;
      self.raya(MI, y + 8, A4[0] - MD, y + 8, C.linea, 0.5);
      y = 52;
      f.forEach(function (t, k) {
        var ls = parte(t, false, 8, ANCHO - 70);
        ls.forEach(function (l) { self.texto(MI, y - 1, l, false, 8, C.gris); y -= 10.5; });
      });
      var p = 'Página ' + (i + 1) + ' de ' + n; self.texto(A4[0] - MD - ancho(p, false, 7.5), 51, p, false, 7.5, C.gris);
    });
  };
  Doc.prototype.bytes = function () {
    var objs = [], self = this;
    function obj(t) { objs.push(t); return objs.length; }
    var cat = obj(''), arbol = obj('');
    var f1 = obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
    var f2 = obj('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
    var hijos = [];
    this.paginas.forEach(function (s) {
      var cont = s.join('\n');
      var c = obj('<< /Length ' + cont.length + ' >>\nstream\n' + cont + '\nendstream');
      hijos.push(obj('<< /Type /Page /Parent ' + arbol + ' 0 R /MediaBox [0 0 ' + A4[0] + ' ' + A4[1] + '] /Resources << /Font << /F1 ' + f1 + ' 0 R /F2 ' + f2 + ' 0 R >> >> /Contents ' + c + ' 0 R >>'));
    });
    objs[arbol - 1] = '<< /Type /Pages /Kids [' + hijos.map(function (h) { return h + ' 0 R'; }).join(' ') + '] /Count ' + hijos.length + ' >>';
    objs[cat - 1] = '<< /Type /Catalog /Pages ' + arbol + ' 0 R /Lang (es-ES) >>';
    var info = obj('<< /Title (' + esc(a1252(this.meta.titulo || '')) + ') /Author (adricamente) /Creator (adricamente.com) /Producer (adricamente.com, en tu navegador) >>');
    var out = '%PDF-1.4\n%\xE2\xE3\xCF\xD3\n', offs = [];
    objs.forEach(function (o, i) { offs.push(out.length); out += (i + 1) + ' 0 obj\n' + o + '\nendobj\n'; });
    var xref = out.length;
    out += 'xref\n0 ' + (objs.length + 1) + '\n0000000000 65535 f \n';
    offs.forEach(function (o) { out += ('0000000000' + o).slice(-10) + ' 00000 n \n'; });
    out += 'trailer\n<< /Size ' + (objs.length + 1) + ' /Root ' + cat + ' 0 R /Info ' + info + ' 0 R >>\nstartxref\n' + xref + '\n%%EOF';
    var u = new Uint8Array(out.length);
    for (var i = 0; i < out.length; i++) u[i] = out.charCodeAt(i) & 255;
    return u;
  };

  function hoy() {
    var M = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'], d = new Date();
    return d.getDate() + ' de ' + M[d.getMonth()] + ' de ' + d.getFullYear();
  }
  function fecha() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }

  function crea(op) {
    var pie = op.pie || [
      'Adrián Cuñat Andrés · Psicólogo colegiado nº CM03836, cursando el Máster en Psicología General Sanitaria, en prácticas supervisadas en el centro sanitario Neurococos · adricamente.com',
      'Hecho en tu navegador el ' + hoy() + ': no se ha enviado a ningún sitio. No es un test ni un diagnóstico. Si estás en peligro ahora mismo, llama al 024 o al 112.'
    ];
    var d = new Doc({ titulo: op.titulo, pie: pie });
    d.titulo(op.titulo, op.subtitulo);
    (op.bloques || []).forEach(function (b) { d.bloque(b); });
    d.pies();
    return d.bytes();
  }
  function descarga(op) {
    try {
      var blob = new Blob([crea(op)], { type: 'application/pdf' });
      var url = URL.createObjectURL(blob), a = document.createElement('a');
      a.href = url; a.download = op.archivo || 'adricamente-' + fecha() + '.pdf';
      document.body.appendChild(a); a.click(); document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
      // Aviso para la caja «tras el PDF» de las herramientas (newsletter.py):
      // la descarga ya ha ocurrido. No lleva nada de lo escrito, solo que ha
      // pasado. Va después del clic a propósito: el PDF nunca espera a nada.
      try { document.dispatchEvent(new CustomEvent('adri:pdf')); } catch (e) {}
      return true;
    } catch (e) { return false; }
  }
  window.adriPDF = { descarga: descarga, crea: crea, hoy: hoy, fecha: fecha };
})();
