/* 27.11.2026: Auftraege mit Druck-Ref (Druck-Designer ab 26.11.2026 abends) – Druckangaben kommen aus der Druckliste im Plugin S39Staffel (druck.json) */
/* Work39/Sport39 Druckblatt (15.11.2026, Stufe 1: 14.11.2026 – CSV, Namen als Druckdatei, ZIP, QR-Code)
   Aufruf im Plenty-Backend per Lesezeichen. Liest einen Auftrag über die REST-API und zeigt alles,
   was die Druckerei braucht: Vorschau je Shirt und Seite, Schrift, Farbe, Position, Namensliste je Größe,
   Logo-Dateien zum Öffnen. Drucken bzw. als PDF speichern über den Knopf oben. */
(function () {
  if (window.__w39db) { window.__w39db(); return; }
  var TOK = localStorage.accessToken;
  var H = { Authorization: 'Bearer ' + TOK };
  var P = { artikel: 127, position: 128, text: 129, logo: 130, logoFile: 131, extra: [132, 133, 134] };
  var POS = { 'Brust links': ['front', 62, 30], 'Brust rechts': ['front', 38, 30], 'Brust Mitte': ['front', 50, 33], 'Bauch': ['front', 50, 55], 'Ärmel links': ['front', 86, 40], 'Ärmel rechts': ['front', 14, 40], 'Rücken oben': ['back', 50, 24], 'Rücken Mitte': ['back', 50, 42], 'Rücken unten': ['back', 50, 62], 'Hose vorne links': ['pants', 64, 30], 'Hose vorne rechts': ['pants', 36, 30] };
  var FONTS = { 'Oswald': ['Oswald', 600], 'Bebas Neue': ['Bebas Neue', 400], 'Anton': ['Anton', 400], 'Teko': ['Teko', 600], 'Saira Condensed': ['Saira Condensed', 700], 'Barlow Condensed': ['Barlow Condensed', 700], 'Roboto Condensed': ['Roboto Condensed', 700], 'Big Shoulders': ['Big Shoulders Display', 800], 'Staatliches': ['Staatliches', 400], 'Graduate (College)': ['Graduate', 400], 'Alfa Slab One': ['Alfa Slab One', 400], 'Archivo Black': ['Archivo Black', 400], 'Russo One': ['Russo One', 400], 'Black Ops One': ['Black Ops One', 400], 'Bungee': ['Bungee', 400] };
  var COL = { 'Weiß': '#ffffff', 'Schwarz': '#1d1d1b', 'Rot': '#d0021b', 'Gelb': '#ffd400', 'Grün': '#1f8a3b', 'Marine': '#1b2a4a', 'Royalblau': '#1f4fb4', 'Orange': '#f07d00', 'Grau': '#8a8d91', 'Bordeaux': '#7a1f2b', 'Silber': '#c0c0c0', 'Gold': '#c9a227' };
  var SIZEORD = ['3XS', 'XXS', '2XS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', '3XL', 'XXXL', '4XL', '5XL', '6XL'];
  var SIL = '<svg viewBox="0 0 200 200" fill="#e9e9e9" stroke="#bdbdbd" stroke-width="3" stroke-linejoin="round"><path d="M70 30l-45 25 12 45 20-8v88h86V92l20 8 12-45-45-25c-5 10-15 16-30 16s-25-6-30-16z"/></svg>';
  var STAGE = 340;
  var CDN = 'https://cdn02.plentyone.com/wp3tnj77y4cz/frontend/content/w39tools/';
  var FFILE = { 'Oswald': 'oswald-600', 'Bebas Neue': 'bebas-neue-400', 'Anton': 'anton-400', 'Teko': 'teko-600', 'Saira Condensed': 'saira-condensed-700', 'Barlow Condensed': 'barlow-condensed-700', 'Roboto Condensed': 'roboto-condensed-700', 'Big Shoulders Display': 'big-shoulders-display-800', 'Staatliches': 'staatliches-400', 'Graduate': 'graduate-400', 'Alfa Slab One': 'alfa-slab-one-400', 'Archivo Black': 'archivo-black-400', 'Russo One': 'russo-one-400', 'Black Ops One': 'black-ops-one-400', 'Bungee': 'bungee-400' };
  var libs = {}, fontCache = {}, D = null;
  function lib(name, glob) {
    if (window[glob]) return Promise.resolve(window[glob]);
    return libs[name] = libs[name] || new Promise(function (ok, no) { var s = document.createElement('script'); s.src = CDN + 'lib/' + name; s.onload = function () { window[glob] ? ok(window[glob]) : no(new Error(name)); }; s.onerror = function () { no(new Error(name + ' nicht ladbar')); }; document.head.appendChild(s); });
  }
  function fontOf(fam) {
    var f = FFILE[fam]; if (!f) return Promise.reject(new Error('Schrift „' + fam + '“ liegt nicht als Datei vor'));
    return fontCache[f] = fontCache[f] || lib('opentype.min.js', 'opentype').then(function (ot) { return fetch(CDN + 'fonts/' + f + '.woff').then(function (r) { if (!r.ok) throw new Error('Schriftdatei ' + f + ' → ' + r.status); return r.arrayBuffer(); }).then(function (b) { return ot.parse(b); }); });
  }
  function slug(t) { return String(t || '').replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/Ä/g, 'Ae').replace(/Ö/g, 'Oe').replace(/Ü/g, 'Ue').replace(/ß/g, 'ss').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40); }
  function save(name, data, type) { var b = data instanceof Blob ? data : new Blob([data], { type: type || 'application/octet-stream' }); var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = name; document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000); }
  // kleine ZIP-Datei ohne Kompression
  var CRCT = (function () { var t = [], c, n, k; for (n = 0; n < 256; n++) { c = n; for (k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
  function crc(u) { var c = 0xFFFFFFFF; for (var i = 0; i < u.length; i++) c = CRCT[(c ^ u[i]) & 255] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; }
  function zip(files) {
    var enc = new TextEncoder(), parts = [], cen = [], off = 0, d = new Date();
    var dt = ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate(), tm = (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1);
    files.forEach(function (f) {
      var nm = enc.encode(f.name), u = typeof f.data === 'string' ? enc.encode(f.data) : new Uint8Array(f.data), c = crc(u);
      var h = new DataView(new ArrayBuffer(30)); h.setUint32(0, 0x04034b50, true); h.setUint16(4, 20, true); h.setUint16(6, 0x800, true); h.setUint16(8, 0, true); h.setUint16(10, tm, true); h.setUint16(12, dt, true); h.setUint32(14, c, true); h.setUint32(18, u.length, true); h.setUint32(22, u.length, true); h.setUint16(26, nm.length, true); h.setUint16(28, 0, true);
      var e = new DataView(new ArrayBuffer(46)); e.setUint32(0, 0x02014b50, true); e.setUint16(4, 20, true); e.setUint16(6, 20, true); e.setUint16(8, 0x800, true); e.setUint16(10, 0, true); e.setUint16(12, tm, true); e.setUint16(14, dt, true); e.setUint32(16, c, true); e.setUint32(20, u.length, true); e.setUint32(24, u.length, true); e.setUint16(28, nm.length, true); e.setUint32(42, off, true);
      parts.push(h.buffer, nm, u); cen.push(e.buffer, nm); off += 30 + nm.length + u.length;
    });
    var cs = 0; cen.forEach(function (x) { cs += x.byteLength; });
    var end = new DataView(new ArrayBuffer(22)); end.setUint32(0, 0x06054b50, true); end.setUint16(8, files.length, true); end.setUint16(10, files.length, true); end.setUint32(12, cs, true); end.setUint32(16, off, true);
    return new Blob(parts.concat(cen, [end.buffer]), { type: 'application/zip' });
  }
  function cm(t) { var m = String(t || '').match(/([\d.,]+)\s*cm\s*(hoch|breit)/); return m ? { mm: parseFloat(m[1].replace(',', '.')) * 10, dir: m[2] } : null; }
  function isKid(s) { var n = parseFloat(s); return !isNaN(n) && n >= 92 && n <= 140; }
  function textGroups() { return (D ? D.groups : []).filter(function (g) { return !g.isLogo && (g.same || g.list); }); }
  // Namensliste als CSV (Excel, Semikolon)
  function csv() {
    var rows = [['Auftrag', 'Druck Nr.', 'Druck', 'Artikel', 'Position', 'Schrift', 'Farbe', 'Druckgröße', 'Größe', 'Anzahl', 'Name / Text']];
    D.groups.forEach(function (g, n) {
      if (g.isLogo || !(g.same || g.list)) return;
      var base = [D.o.id, n + 1, g.name, g.key, g.posName, g.font || '', g.color || '', (g.gr || '') + (g.grK ? ' / Kinder bis 140: ' + g.grK : '')];
      if (g.list) sortSizes(Object.keys(g.list)).forEach(function (s) { g.list[s].forEach(function (nm) { rows.push(base.concat([s, 1, nm])); }); });
      else { var ss = sortSizes(Object.keys(g.sizes)); if (ss.length) ss.forEach(function (s) { rows.push(base.concat([s, g.sizes[s], g.same])); }); else rows.push(base.concat(['', g.qty, g.same])); }
    });
    return '\ufeff' + rows.map(function (r) { return r.map(function (c) { c = String(c == null ? '' : c); return /[";\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(';'); }).join('\r\n');
  }
  // Namen eines Drucks als Vektordatei in Originalgröße (Text in Pfade umgewandelt)
  function nameSvg(g, n) {
    var a = cm(g.gr), k = cm(g.grK);
    if (!a) return Promise.reject(new Error('Druck ' + (n + 1) + ': keine Druckgröße in cm am Auftrag (älterer Auftrag)'));
    var fam = (FONTS[g.font] || [String(g.font || '').replace(/\s*\(.*\)/, '')])[0];
    return fontOf(fam).then(function (font) {
      var items = [];
      if (g.list) sortSizes(Object.keys(g.list)).forEach(function (s) { g.list[s].forEach(function (nm) { if (nm && nm !== 'ohne') items.push({ t: nm, s: s, d: k && isKid(s) ? k : a }); }); });
      else { items.push({ t: g.same, s: '', d: a }); if (k && Object.keys(g.sizes).some(isKid)) items.push({ t: g.same, s: 'Kinder', d: k }); }
      if (!items.length) throw new Error('Druck ' + (n + 1) + ': keine Namen');
      var capU = (font.tables.os2 && font.tables.os2.sCapHeight) || (function () { var b = font.getPath('H', 0, 0, font.unitsPerEm).getBoundingBox(); return -b.y1; })();
      var y = 5, W = 0, GAP = 8, out = [];
      items.forEach(function (it) {
        var fs = it.d.dir === 'hoch' ? it.d.mm / (capU / font.unitsPerEm) : 100;
        var bb = font.getPath(it.t, 0, 0, fs).getBoundingBox();
        if (it.d.dir === 'breit') { fs = fs * it.d.mm / (bb.x2 - bb.x1); bb = font.getPath(it.t, 0, 0, fs).getBoundingBox(); }
        var p = font.getPath(it.t, 5 - bb.x1, y - bb.y1, fs);
        out.push('<path data-name="' + esc(it.t) + '"' + (it.s ? ' data-groesse="' + esc(it.s) + '"' : '') + ' d="' + p.toPathData(3) + '"/>');
        W = Math.max(W, bb.x2 - bb.x1); y += (bb.y2 - bb.y1) + GAP;
      });
      var w = (W + 10).toFixed(1), h = (y - GAP + 5).toFixed(1), col = COL[g.color] || '#000000';
      return { name: 'A' + D.o.id + '_Druck' + (n + 1) + '_' + slug(g.posName || g.name) + '_' + slug(g.color || 'Farbe') + (g.list ? '' : '_je-' + g.qty + 'x') + '.svg', count: items.length,
        data: '<?xml version="1.0" encoding="UTF-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" width="' + w + 'mm" height="' + h + 'mm" viewBox="0 0 ' + w + ' ' + h + '">\n<title>Auftrag ' + D.o.id + ' · ' + esc(g.name) + ' · ' + esc(g.posName) + ' · ' + esc(g.font || '') + ' · ' + esc(g.color || '') + ' · ' + esc(g.gr || '') + '</title>\n<g fill="' + col + '">\n' + out.join('\n') + '\n</g>\n</svg>\n' };
    });
  }
  function nameFiles() {
    var files = [], errs = [], gs = D.groups;
    return gs.reduce(function (pr, g, n) { return pr.then(function () { if (g.isLogo || !(g.same || g.list)) return; return nameSvg(g, n).then(function (f) { files.push(f); }).catch(function (e) { errs.push(e.message); }); }); }, Promise.resolve()).then(function () { return { files: files, errs: errs }; });
  }
  function busy(btn, on) { if (!btn) return; if (on) { btn.__t = btn.textContent; btn.textContent = 'Moment …'; btn.disabled = true; } else { btn.textContent = btn.__t; btn.disabled = false; } }
  function doCsv() { if (!textGroups().length) { alert('In diesem Auftrag gibt es keine Namen oder Texte.'); return; } save('A' + D.o.id + '_Namensliste.csv', csv(), 'text/csv;charset=utf-8'); }
  function doNames(btn) {
    if (!textGroups().length) { alert('In diesem Auftrag gibt es keine Namen oder Texte.'); return; }
    busy(btn, 1);
    nameFiles().then(function (r) {
      busy(btn, 0);
      if (r.files.length === 1) save(r.files[0].name, r.files[0].data, 'image/svg+xml');
      else if (r.files.length) save('A' + D.o.id + '_Namen-Druckdateien.zip', zip(r.files));
      if (r.errs.length) alert('Nicht erzeugt:\n' + r.errs.join('\n'));
    }).catch(function (e) { busy(btn, 0); alert('Fehler: ' + e.message); });
  }
  function doAll(btn) {
    busy(btn, 1);
    var files = [], notes = ['Auftrag ' + D.o.id + ' – Druckpaket, erstellt ' + new Date().toLocaleString('de-DE'), ''];
    (textGroups().length ? nameFiles() : Promise.resolve({ files: [], errs: [] })).then(function (r) {
      files = files.concat(r.files);
      if (textGroups().length) files.push({ name: 'A' + D.o.id + '_Namensliste.csv', data: csv() });
      r.errs.forEach(function (e) { notes.push('Nicht erzeugt: ' + e); });
      files.push({ name: 'A' + D.o.id + '_Druckblatt.html', data: pageHtml(lastHtml.replace(/<img[^>]*data-pfimg[^>]*>/g, ''), false) });
      var lf = []; D.groups.forEach(function (g, n) { g.files.forEach(function (f, i) { if (f.url) lf.push({ g: n + 1, i: i + 1, f: f }); }); });
      return Promise.all(lf.map(function (x) {
        return signed(x.f.id).then(function (u) { return fetch(u).then(function (resp) { if (!resp.ok) throw 0; var ext = (u.split('?')[0].match(/\.([a-z0-9]{2,5})$/i) || [0, 'dat'])[1]; return resp.arrayBuffer().then(function (b) { files.push({ name: 'A' + D.o.id + '_Druck' + x.g + '_Logo' + (x.i > 1 ? x.i : '') + '.' + ext, data: b }); }); }); })
          .catch(function () { notes.push('Logo-Datei zu Druck ' + x.g + ' ließ sich nicht automatisch einpacken. Bitte im Druckblatt über „Logo-Datei öffnen“ speichern.'); });
      }));
    }).then(function () {
      notes.push('', 'Druckdateien (SVG): Namen in Originalgröße, Text in Pfade umgewandelt, Maße in mm. Bei „hoch“ ist die Höhe der Großbuchstaben gemeint.', 'Druckblatt.html im Browser öffnen; Logo-Vorschauen fehlen dort, weil die Links nur kurz gültig sind.');
      files.push({ name: 'LIESMICH.txt', data: notes.join('\r\n') });
      save('A' + D.o.id + '_Druckpaket.zip', zip(files)); busy(btn, 0);
    }).catch(function (e) { busy(btn, 0); alert('Fehler: ' + e.message); });
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function api(u, opt) { return fetch(u, Object.assign({ headers: H }, opt || {})).then(function (r) { if (!r.ok) throw new Error(u + ' → ' + r.status); return r.json(); }); }
  function sizeRank(s) { var i = SIZEORD.indexOf(String(s).toUpperCase()); if (i >= 0) return i; var n = parseFloat(s); return isNaN(n) ? 999 : 100 + n; }
  function sortSizes(a) { return a.slice().sort(function (x, y) { return sizeRank(x) - sizeRank(y) || String(x).localeCompare(String(y)); }); }

  var box = document.createElement('div');
  box.id = 'w39db';
  var css = '#w39db{position:fixed;inset:0;z-index:2147483000;background:rgba(20,20,20,.6);overflow:auto;font:14px/1.45 system-ui,Segoe UI,Arial,sans-serif;color:#1d1d1b}' +
    '#w39db .w39db-bar{position:sticky;top:0;z-index:2;display:flex;gap:10px;align-items:center;justify-content:space-between;background:#1d1d1b;color:#fff;padding:10px 18px}' +
    '#w39db .w39db-bar button{border:0;border-radius:6px;padding:8px 14px;font:600 14px system-ui,sans-serif;cursor:pointer;margin-left:8px}' +
    '#w39db .w39db-pr{background:#e30613;color:#fff}#w39db .w39db-x{background:#efeee9;color:#1d1d1b}' +
    '#w39db .w39db-sheet{max-width:1000px;margin:20px auto 60px;background:#fff;border-radius:10px;padding:28px 32px;box-shadow:0 10px 40px rgba(0,0,0,.3)}';
  var SHEETCSS = '.db h1{font-size:24px;margin:0 0 4px}.db h2{font-size:18px;margin:26px 0 10px;padding-bottom:6px;border-bottom:2px solid #1d1d1b}' +
    '.db .meta{display:flex;flex-wrap:wrap;gap:6px 26px;color:#444;margin-bottom:6px}.db .meta b{color:#1d1d1b}' +
    '.db table{width:100%;border-collapse:collapse;margin:6px 0}.db th,.db td{text-align:left;border-bottom:1px solid #e3e1dc;padding:6px 8px;vertical-align:top}.db th{background:#f5f4f0;font-size:12px;text-transform:uppercase;letter-spacing:.03em}' +
    '.db .card{border:1px solid #d9d6cf;border-radius:10px;padding:14px 16px;margin:12px 0;break-inside:avoid;page-break-inside:avoid}' +
    '.db .card h3{margin:0 0 8px;font-size:17px}.db .tag{display:inline-block;background:#1d1d1b;color:#fff;border-radius:4px;padding:1px 8px;font-size:12px;margin-left:6px;vertical-align:middle}' +
    '.db .grid{display:grid;grid-template-columns:150px 1fr;gap:4px 14px}.db .grid dt{color:#666}.db .grid dd{margin:0;font-weight:600}' +
    '.db .sw{display:inline-block;width:14px;height:14px;border-radius:50%;border:1px solid #999;vertical-align:-2px;margin-right:6px}' +
    '.db .names{margin:10px 0 0;font-size:16px}.db .names li{margin:3px 0}.db .names b{display:inline-block;min-width:70px}' +
    '.db .warn{background:#fdecec;color:#b8050f;border:1px solid #f3b7b7;border-radius:6px;padding:6px 10px;margin:8px 0;font-weight:600}' +
    '.db .ok{color:#1f8a3b;font-weight:600}' +
    '.db .files a{display:inline-block;margin:4px 8px 4px 0;padding:6px 10px;border:1px solid #1d1d1b;border-radius:6px;color:#1d1d1b;text-decoration:none;font-weight:600}' +
    '.db .files img{max-height:90px;max-width:200px;border:1px solid #e3e1dc;border-radius:4px;vertical-align:middle;margin-right:8px;background:repeating-conic-gradient(#eee 0 25%,#fff 0 50%) 0 0/12px 12px}' +
    '.db .views{display:flex;flex-wrap:wrap;gap:16px}.db .view{break-inside:avoid;page-break-inside:avoid}.db .view p{margin:4px 0 0;font-size:12px;color:#555;text-align:center}' +
    '.db .stage{position:relative;width:' + STAGE + 'px;height:' + STAGE + 'px;border:1px solid #e3e1dc;border-radius:8px;overflow:hidden;background:#fff}' +
    '.db .stage>img{width:100%;height:100%;object-fit:contain;display:block}.db .stage .sil{position:absolute;inset:0;display:flex;justify-content:center}.db .stage .sil svg{width:84%;margin-top:4%}' +
    '.db .el{position:absolute;transform:translate(-50%,-50%);white-space:nowrap;line-height:1}.db .el img{width:100%;display:block}' +
    '.db .el.lg{border:1.5px dashed #3d9427;background:rgba(238,247,234,.85);display:flex;align-items:center;justify-content:center;font:700 11px system-ui,sans-serif;color:#3d9427;aspect-ratio:1/1}' +
    '.db .foot{margin-top:28px;color:#777;font-size:12px}.db .qr{float:right;text-align:center;margin:0 0 8px 16px}.db .qr svg{display:block;width:84px;height:84px}.db .qr small{font-size:10px;color:#666}' +
    '@media print{.db .stage{-webkit-print-color-adjust:exact;print-color-adjust:exact}.db .card{border-color:#999}}';
  var st = document.createElement('style'); st.textContent = css + SHEETCSS.replace(/\.db /g, '#w39db .db '); document.head.appendChild(st);
  document.body.appendChild(box);
  window.__w39db = function () { box.style.display = 'block'; start(); };
  box.addEventListener('click', function (e) {
    if (e.target.closest('.w39db-x')) { box.style.display = 'none'; }
    if (e.target.closest('.w39db-pr')) { printIt(); }
    if (e.target.closest('.w39db-new')) { start(true); }
    var bt;
    if ((bt = e.target.closest('.w39db-csv')) && D) doCsv();
    if ((bt = e.target.closest('.w39db-nm')) && D) doNames(bt);
    if ((bt = e.target.closest('.w39db-all')) && D) doAll(bt);
    var pf = e.target.closest('[data-pf]');
    if (pf) {
      e.preventDefault();
      var w = window.open('', '_blank');
      if (!w) { alert('Bitte Pop-ups für diese Seite erlauben.'); return; }
      w.document.write('<p style="font:15px system-ui,sans-serif;margin:30px">Datei wird geladen …</p>');
      signed(pf.getAttribute('data-pf')).then(function (u) { w.location.href = u; })
        .catch(function () { w.document.body.innerHTML = '<p style="font:15px system-ui,sans-serif;margin:30px;color:#b8050f">Datei nicht abrufbar. Ist die Sitzung im Backend noch aktiv?</p>'; });
    }
  });
  function signed(id) {
    return api('/rest/orders/items/' + id + '/order_property_file/generate_signed_url', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H), body: '{}' }).then(function (r) { return r.url; });
  }

  var lastHtml = '', lastTitle = '', lastFonts = '';
  function bar(t) { return '<div class="w39db-bar"><b>' + esc(t) + '</b><span><button class="w39db-new w39db-x" type="button">Anderer Auftrag</button><button class="w39db-csv w39db-x" type="button" title="Namensliste für Excel">Namensliste CSV</button><button class="w39db-nm w39db-x" type="button" title="Namen in Originalgröße als Vektordatei (SVG)">Namen als Druckdatei</button><button class="w39db-all w39db-x" type="button" title="Druckdateien, Namensliste, Druckblatt und Logos in einer ZIP">Alles (ZIP)</button><button class="w39db-pr" type="button">Drucken / PDF</button><button class="w39db-x" type="button">Schließen</button></span></div>'; }
  function printIt() {
    var w = window.open('', '_blank');
    if (!w) { alert('Bitte Pop-ups für diese Seite erlauben.'); return; }
    w.document.write('<p style="font:15px system-ui,sans-serif;margin:30px">Druckfassung wird vorbereitet …</p>');
    // Logo-Bilder mit frischen Links versehen, damit sie auch nach langer Zeit im Druck erscheinen
    var tmp = document.createElement('div'); tmp.innerHTML = lastHtml;
    var imgs = [].slice.call(tmp.querySelectorAll('img[data-pfimg]'));
    var ids = imgs.map(function (i) { return i.getAttribute('data-pfimg'); }).filter(function (v, i, a) { return a.indexOf(v) === i; });
    var fresh = {};
    Promise.all(ids.map(function (id) { return signed(id).then(function (u) { fresh[id] = u; }).catch(function () { }); })).then(function () {
      imgs.forEach(function (i) { var u = fresh[i.getAttribute('data-pfimg')]; if (u) i.setAttribute('src', u); else i.remove(); });
      [].forEach.call(tmp.querySelectorAll('[data-pf]'), function (a) { a.removeAttribute('href'); });
      writePrint(w, tmp.innerHTML);
    });
  }
  function pageHtml(html, auto) {
    return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' + esc(lastTitle) + '</title>' + lastFonts +
      '<style>body{font:13px/1.45 system-ui,Segoe UI,Arial,sans-serif;color:#1d1d1b;margin:18px}@page{size:A4;margin:12mm}' + SHEETCSS + '</style></head><body>' + html +
      (auto ? '<script>window.onload=function(){setTimeout(function(){window.print()},900)}<\/script>' : '') + '</body></html>';
  }
  function writePrint(w, html) { w.document.open(); w.document.write(pageHtml(html, true)); w.document.close(); }

  function start(ask) {
    var m = location.href.match(/order-ui\/(\d+)/) || location.href.match(/[?&#\/]orderId[=\/](\d+)/);
    var id = (!ask && m) ? m[1] : prompt('Auftrags-ID für das Druckblatt:', m ? m[1] : '');
    if (!id) { box.style.display = 'none'; return; }
    id = String(id).replace(/\D/g, '');
    D = null;
    box.innerHTML = bar('Druckblatt – Auftrag ' + id) + '<div class="w39db-sheet">Lade Auftrag ' + esc(id) + ' …</div>';
    build(id).catch(function (e) { box.querySelector('.w39db-sheet').innerHTML = '<div class="db"><div class="warn">Fehler: ' + esc(e.message) + '</div><p>Ist die Sitzung im Backend noch aktiv? Seite neu laden und erneut versuchen.</p></div>'; });
  }

  // Druck-Designer ab 26.11.2026: Druckzeilen ohne Merkmale, Angaben in druck.json (Plugin S39Staffel)
  var DRUCK_SHOP = 'https://www.sport39.de/rest/s39staffel/druck?ref=';
  var DRUCK_REF = /\(Druck-Ref ([A-Za-z0-9_-]{8,40})\)/;
  var druckErr = '';
  function firstVal(i, pid) { return String(((i.op[pid] || [])[0] || {}).v || ''); }
  function druckRef(top) {
    druckErr = '';
    var ref = '';
    top.forEach(function (i) { var m = firstVal(i, P.artikel).match(DRUCK_REF); if (m) { ref = m[1]; } });
    if (!ref) { return Promise.resolve(); }
    return fetch(DRUCK_SHOP + encodeURIComponent(ref)).then(function (r) {
      if (!r.ok) { throw new Error('Status ' + r.status); }
      return r.json();
    }).then(function (j) {
      var es = (j && j.entries) || [];
      if (!es.length) { throw new Error('keine Einträge zu Druck-Ref ' + ref); }
      var vids = {};
      es.forEach(function (e) { vids[e.vid] = true; });
      // Druckzeilen ohne eigene Angaben und die Trägerzeile ersetzen; Zeilen mit eigenen Angaben (Logo-Upload) bleiben
      var keep = [], base = {};
      top.forEach(function (i) {
        var a = firstVal(i, P.artikel);
        if (vids[i.itemVariationId] && (a === '' || DRUCK_REF.test(a))) { base[i.itemVariationId] = base[i.itemVariationId] || i; return; }
        keep.push(i);
      });
      es.forEach(function (e) {
        var dup = keep.some(function (i) { return firstVal(i, P.artikel) === e.artikel && firstVal(i, P.position) === e.pos; });
        if (dup) { return; }
        var b = base[e.vid] || {};
        var op = {};
        op[P.artikel] = [{ v: e.artikel }];
        op[P.position] = [{ v: e.pos }];
        if (e.text) { op[P.text] = [{ v: e.text }]; }
        if (e.logo) { op[P.logo] = [{ v: e.logo }]; }
        keep.push({ id: b.id || ('dj' + e.vid), typeId: b.typeId || 1, itemVariationId: e.vid, orderItemName: e.name || b.orderItemName || 'Druck', quantity: e.qty || b.quantity || 0, properties: [], references: [], kids: [], op: op });
      });
      top.length = 0;
      keep.forEach(function (i) { top.push(i); });
    }).catch(function (e) {
      druckErr = 'Druckangaben zu Druck-Ref ' + ref + ' konnten nicht geladen werden (' + e.message + '). Bitte die Druckliste öffnen: https://www.sport39.de/rest/s39staffel/druckliste?orderId=';
    });
  }

  function propsOf(it) { var o = {}; (it.properties || []).forEach(function (p) { o[p.typeId] = p.value; }); return o; }

  function build(id) {
    return api('/rest/orders/' + id + '?with[]=orderItems.references&with[]=addresses&with[]=comments').then(function (o) {
      var items = o.orderItems || [];
      var byId = {}; items.forEach(function (i) { byId[i.id] = i; i.kids = []; });
      var top = [];
      items.forEach(function (i) {
        var ref = (i.references || []).filter(function (r) { return r.referenceType === 'order_property'; })[0];
        if (ref && byId[ref.referenceOrderItemId]) byId[ref.referenceOrderItemId].kids.push(i); else top.push(i);
      });
      // Merkmale je Position einsammeln
      top.forEach(function (i) {
        i.op = {};
        i.kids.forEach(function (k) {
          var pp = propsOf(k), pid = Number(pp[81]);
          if (!pid) return;
          (i.op[pid] = i.op[pid] || []).push({ v: pp[82], item: k, name: k.orderItemName });
        });
      });
      return druckRef(top).then(function () {
      var shirts = top.filter(function (i) { return i.typeId === 1 && !i.op[P.artikel]; });
      var prints = top.filter(function (i) { return i.op[P.artikel]; });
      var other = top.filter(function (i) { return i.typeId !== 1 && i.typeId !== 6 && !i.op[P.artikel]; });

      // Shirts nach Artikel + Farbe gruppieren
      var shirtGroups = {};
      shirts.forEach(function (s) {
        var av = String(s.attributeValues || '').split(/,\s*/);
        var size = av[0] || '', color = av.slice(1).join(', ');
        var key = s.orderItemName + (color ? ', ' + color : '');
        var g = shirtGroups[key] = shirtGroups[key] || { key: key, name: s.orderItemName, color: color, sizes: {}, vids: [], items: [] };
        g.sizes[size] = (g.sizes[size] || 0) + s.quantity; g.vids.push(s.itemVariationId); g.items.push(s);
      });

      // Druckpositionen (neues Format „Name, Farbe – 2× M, 1× L“; altes Format je Shirt eine Zeile)
      var groups = [], oldMap = {};
      prints.forEach(function (p) {
        var a = (p.op[P.artikel][0] || {}).v || '';
        var posv = (p.op[P.position] || [{}])[0].v || '';
        var txt = (p.op[P.text] || []).map(function (x) { return x.v; });
        var logos = (p.op[P.logoFile] || []).map(function (x) { return x.item; });
        var logoInfo = ((p.op[P.logo] || [{}])[0].v) || '';
        var extras = [];
        P.extra.forEach(function (eid) { (p.op[eid] || []).forEach(function (x) { extras.push({ item: x.item, name: x.name }); }); });
        var i = a.lastIndexOf(' – ');
        if (i > 0 && /×/.test(a.slice(i))) {
          var sizes = {}; a.slice(i + 3).split(/,\s*/).forEach(function (x) { var m = x.match(/^(\d+)\s*×\s*(.+)$/); if (m) sizes[m[2].trim()] = +m[1]; });
          groups.push({ print: p, name: p.orderItemName, qty: p.quantity, key: a.slice(0, i), sizes: sizes, pos: posv, txt: txt, logos: logos, logoInfo: logoInfo, extras: extras, fmt: 'neu' });
        } else if (/Größe/.test(a)) {
          var mk = a.match(/^(.*?),\s*Größe\s+([^ (]+)/) || [];
          var key = (mk[1] || a).trim(), size = mk[2] || '?';
          var k2 = p.orderItemName + '|' + key + '|' + posv;
          var g = oldMap[k2];
          if (!g) { g = oldMap[k2] = { print: p, name: p.orderItemName, qty: 0, key: key, sizes: {}, pos: posv, txt: [], names: {}, logos: [], logoInfo: logoInfo, extras: [], fmt: 'alt' }; groups.push(g); }
          g.qty += p.quantity; g.sizes[size] = (g.sizes[size] || 0) + p.quantity;
          if (txt.length) (g.names[size] = g.names[size] || []).push(txt.join(' '));
          g.logos = g.logos.concat(logos); g.extras = g.extras.concat(extras);
        } else {
          groups.push({ print: p, name: p.orderItemName, qty: p.quantity, key: a, sizes: {}, pos: posv, txt: txt, logos: logos, logoInfo: logoInfo, extras: extras, fmt: 'pauschal' });
        }
      });
      // alte Namenslisten in das neue Format überführen
      groups.forEach(function (g) {
        if (g.fmt === 'alt' && Object.keys(g.names).length) {
          g.txt = sortSizes(Object.keys(g.names)).map(function (s) { return g.names[s].length + ' × ' + s + ' (' + g.names[s].join(', ') + ')'; });
        }
        // Druckposition zerlegen
        var parts = String(g.pos).split(/\s*\|\s*/);
        g.posName = parts[0] || '';
        parts.slice(1).forEach(function (x) {
          var m;
          if ((m = x.match(/^Größe:\s*(.+)$/))) g.gr = m[1];
          else if ((m = x.match(/^Kinder(?: bis 140)?:\s*(.+)$/))) g.grK = m[1];
          else if ((m = x.match(/^Schrift:\s*(.+)$/))) g.font = m[1];
          else if ((m = x.match(/^Farbe:\s*(.+)$/))) g.color = m[1];
          else if ((m = x.match(/^Lage:\s*(\w+)\s+([\d.]+)\/([\d.]+)\/([\d.]+)/))) g.lage = { view: m[1], x: +m[2], y: +m[3], s: +m[4] };
        });
        var d = POS[g.posName];
        if (!g.lage && d) g.lage = { view: d[0], x: d[1], y: d[2], s: /Name groß/i.test(g.name) ? 4.5 : /Name/i.test(g.name) ? 2.5 : /groß/i.test(g.name) ? 36 : 14, guess: true };
        g.isLogo = !!(g.logos.length || g.logoInfo || /logo|wappen/i.test(g.name));
        // Namensliste auswerten
        g.list = null; g.same = null;
        var joined = g.txt.join(', ');
        var ma = joined.match(/^alle\s+(\d+)\s*×\s*(.*)$/);
        if (ma) g.same = ma[2];
        else {
          var re = /(\d+)\s*×\s*([^\s(]+)\s*\(([^)]*)\)/g, x, any = false, L = {};
          while ((x = re.exec(joined))) { any = true; L[x[2]] = (L[x[2]] || []).concat(x[3].split(/,\s*/)); }
          if (any) g.list = L; else if (joined) g.same = joined;
        }
        // Prüfung: Namen je Größe passen zu den Größen des Drucks und zu den Shirts
        g.problems = [];
        if (g.list) {
          Object.keys(g.list).forEach(function (s) {
            if (!g.sizes[s]) g.problems.push('Namen für Größe ' + s + ', aber diese Größe ist beim Druck nicht bestellt.');
            else if (g.list[s].length !== g.sizes[s]) g.problems.push('Größe ' + s + ': ' + g.sizes[s] + ' Shirts, aber ' + g.list[s].length + ' Namen.');
          });
          Object.keys(g.sizes).forEach(function (s) { if (!g.list[s]) g.problems.push('Größe ' + s + ': keine Namen eingetragen.'); });
        }
        var sg = shirtGroups[g.key];
        if (sg && g.fmt !== 'pauschal') {
          Object.keys(g.sizes).forEach(function (s) { if ((sg.sizes[s] || 0) < g.sizes[s]) g.problems.push('Größe ' + s + ': ' + g.sizes[s] + '× bedruckt, aber nur ' + (sg.sizes[s] || 0) + ' Shirts im Auftrag.'); });
        }
        g.shirt = sg || null;
      });

      // Logo-Links und Produktbilder parallel laden
      var jobs = [];
      groups.forEach(function (g) {
        g.files = [];
        g.logos.concat(g.extras.map(function (e) { return e.item; })).forEach(function (it, n) {
          jobs.push(api('/rest/orders/items/' + it.id + '/order_property_file/generate_signed_url', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, H), body: '{}' })
            .then(function (r) { g.files.push({ id: it.id, url: r.url, label: n < g.logos.length ? 'Logo-Datei' : (it.orderItemName || 'Weitere Datei') }); })
            .catch(function () { g.files.push({ id: it.id, url: null, label: 'Datei nicht abrufbar (Position ' + it.id + ')' }); }));
        });
      });
      Object.keys(shirtGroups).forEach(function (k) {
        var sg = shirtGroups[k];
        jobs.push(api('/rest/items/variations?id=' + sg.vids[0]).then(function (v) {
          var vv = (v.entries || [])[0]; if (!vv) return;
          sg.number = vv.number;
          return Promise.all([api('/rest/items/' + vv.itemId + '/variations/' + vv.id + '/variation_images'), api('/rest/items/' + vv.itemId + '/images')]).then(function (r) {
            var ids = (r[0] || []).map(function (x) { return x.imageId; });
            var all = r[1] || [];
            var mine = all.filter(function (x) { return ids.indexOf(x.id) >= 0; }).sort(function (a, b) { return a.position - b.position; });
            var url = function (x) { return x.urlMiddle || x.url; };
            var fname = function (x) { return String(x.url || '').split('/').pop(); };
            var front = mine.filter(function (x) { return !/[-_](b|back|rueck|rück)[-_.]/i.test(fname(x)); })[0] || mine[0];
            if (front) {
              sg.front = url(front);
              var pre = fname(front).replace(/[-_][^-_]*\.[a-z0-9]+$/i, '').replace(/[-_][^-_]*$/, '');
              var back = all.filter(function (x) { var f = fname(x); return /[-_](b|back|rueck|rück)\.[a-z0-9]+$/i.test(f) && (!pre || f.toLowerCase().indexOf(pre.toLowerCase()) === 0); })[0];
              if (back) sg.back = url(back);
            }
          });
        }).catch(function () { }));
      });

      jobs.push(lib('qrcode.js', 'qrcode').catch(function () { }));
      return Promise.all(jobs).then(function () {
        D = { o: o, groups: groups, shirtGroups: shirtGroups };
        render(o, shirtGroups, groups, other);
        if (druckErr) {
          var sh = box.querySelector('.w39db-sheet');
          if (sh) { sh.insertAdjacentHTML('afterbegin', '<div class="warn">' + esc(druckErr + o.id) + '</div>'); }
        }
      });
      });
    });
  }

  function addrLine(o) {
    var a = (o.addresses || [])[0] || {};
    var n = [a.name1, [a.name2, a.name3].filter(Boolean).join(' ')].filter(Boolean).join(' · ');
    return n || '[Kunde]';
  }

  function render(o, shirtGroups, groups, other) {
    var fonts = {}; groups.forEach(function (g) { var f = FONTS[g.font] || (g.font ? [g.font.replace(/\s*\(.*\)/, ''), 400] : null); if (f) fonts[f[0]] = f[1]; });
    var fam = Object.keys(fonts);
    lastFonts = fam.length ? '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?' + fam.map(function (f) { return 'family=' + encodeURIComponent(f).replace(/%20/g, '+') + ':wght@' + fonts[f]; }).join('&') + '&display=swap">' : '';
    if (lastFonts && !document.getElementById('w39db-fonts')) { var l = document.createElement('div'); l.id = 'w39db-fonts'; l.innerHTML = lastFonts; document.head.appendChild(l.firstChild); }
    lastTitle = 'Druckblatt Auftrag ' + o.id;
    var created = (o.dates || []).filter(function (d) { return d.typeId === 2; })[0];
    var qr = '';
    try { if (window.qrcode) { var q = window.qrcode(0, 'M'); q.addData(location.origin + '/plenty/terra/order/order-ui/' + o.id); q.make(); qr = '<div class="qr">' + q.createSvgTag({ cellSize: 3, margin: 0 }) + '<small>Auftrag öffnen</small></div>'; } } catch (e) { }
    var h = '<div class="db">' + qr + '<h1>Druckblatt · Auftrag ' + esc(o.id) + '</h1><div class="meta">' +
      '<span>Kunde: <b>' + esc(addrLine(o)) + '</b></span>' +
      '<span>Auftragsdatum: <b>' + esc(created ? new Date(created.date).toLocaleDateString('de-DE') : '[Datum]') + '</b></span>' +
      '<span>Mandant: <b>' + esc(o.plentyId) + '</b></span>' +
      '<span>Erstellt: <b>' + new Date().toLocaleString('de-DE') + '</b></span></div>';
    var probs = groups.reduce(function (n, g) { return n + g.problems.length; }, 0);
    h += probs ? '<div class="warn">Achtung: ' + probs + ' Unstimmigkeit' + (probs > 1 ? 'en' : '') + ' bei den Namen oder Größen. Bitte vor dem Druck mit dem Kunden klären (rot markiert).</div>' : '<p class="ok">Namen und Größen passen zu den bestellten Shirts.</p>';

    // Shirts
    h += '<h2>Textilien</h2><table><tr><th>Artikel</th><th>Farbe</th><th>Größen</th><th>Menge</th></tr>';
    Object.keys(shirtGroups).forEach(function (k) {
      var sg = shirtGroups[k], tot = 0;
      var sz = sortSizes(Object.keys(sg.sizes)).map(function (s) { tot += sg.sizes[s]; return sg.sizes[s] + ' × ' + s; }).join(', ');
      h += '<tr><td>' + esc(sg.name) + (sg.number ? '<br><small>' + esc(String(sg.number).replace(/-[^-]+$/, '')) + '</small>' : '') + '</td><td>' + esc(sg.color) + '</td><td>' + esc(sz) + '</td><td><b>' + tot + '</b></td></tr>';
    });
    h += '</table>';

    // Vorschau je Shirt und Seite
    var byShirt = {};
    groups.forEach(function (g) { if (g.lage && g.fmt !== 'pauschal') { var k = g.key; (byShirt[k] = byShirt[k] || []).push(g); } });
    var keys = Object.keys(byShirt);
    if (keys.length) {
      h += '<h2>Vorschau</h2>';
      keys.forEach(function (k) {
        var sg = shirtGroups[k] || {};
        var views = {}; byShirt[k].forEach(function (g) { (views[g.lage.view] = views[g.lage.view] || []).push(g); });
        h += '<div class="card"><h3>' + esc(k) + '</h3><div class="views">';
        ['front', 'back', 'pants'].forEach(function (v) {
          if (!views[v]) return;
          var img = v === 'front' ? sg.front : v === 'back' ? sg.back : sg.front;
          h += '<div class="view"><div class="stage">' + (img ? '<img src="' + esc(img) + '" alt="">' : '<div class="sil">' + SIL + '</div>');
          views[v].forEach(function (g) {
            var L = g.lage;
            if (g.isLogo) {
              var f = g.files[0];
              h += '<div class="el lg" style="left:' + L.x + '%;top:' + L.y + '%;width:' + L.s + '%">' + (f && f.url ? '<img src="' + esc(f.url) + '" data-pfimg="' + esc(f.id) + '" alt="" onerror="this.remove()">' : '') + '<span style="position:absolute">LOGO</span></div>';
            } else {
              var sample = g.same || (g.list ? (function () { var s = sortSizes(Object.keys(g.list)); for (var i = 0; i < s.length; i++) { var n = (g.list[s[i]] || []).filter(function (x) { return x && x !== 'ohne'; })[0]; if (n) return n; } return 'NAME'; })() : 'NAME');
              var ff = FONTS[g.font] || [g.font ? g.font.replace(/\s*\(.*\)/, '') : 'Oswald', 400], cc = COL[g.color] || '#1d1d1b';
              var light = /^#(f|e|c)/i.test(cc);
              h += '<div class="el" style="left:' + L.x + '%;top:' + L.y + '%;font-family:\'' + esc(ff[0]) + '\',Impact,sans-serif;font-weight:' + ff[1] + ';color:' + cc + ';font-size:' + (L.s / 100 * STAGE).toFixed(1) + 'px;text-shadow:0 0 2px ' + (light ? 'rgba(0,0,0,.7)' : 'rgba(255,255,255,.85)') + '">' + esc(sample) + '</div>';
            }
          });
          h += '</div><p>' + (v === 'front' ? 'Vorderseite' : v === 'back' ? 'Rückseite' : 'Hose') + (img ? '' : ' (Silhouette)') + '</p></div>';
        });
        h += '</div>' + (byShirt[k].some(function (g) { return g.lage.guess; }) ? '<p style="font-size:12px;color:#777;margin:6px 0 0">Lage teils geschätzt (Standardposition), da der Auftrag noch keine Lagedaten enthält.</p>' : '') + '</div>';
      });
    }

    // Druckpositionen
    h += '<h2>Druckpositionen</h2>';
    groups.forEach(function (g, n) {
      h += '<div class="card"><h3>' + (n + 1) + '. ' + esc(g.name) + '<span class="tag">' + esc(g.qty) + ' ×</span></h3><dl class="grid">';
      h += '<dt>Für Artikel</dt><dd>' + esc(g.key) + '</dd>';
      if (Object.keys(g.sizes).length) h += '<dt>Größen</dt><dd>' + esc(sortSizes(Object.keys(g.sizes)).map(function (s) { return g.sizes[s] + ' × ' + s; }).join(', ')) + '</dd>';
      if (g.posName) h += '<dt>Position</dt><dd>' + esc(g.posName) + '</dd>';
      if (g.font) { var ff = FONTS[g.font] || [g.font.replace(/\s*\(.*\)/, ''), 400]; h += '<dt>Schrift</dt><dd><span style="font-family:\'' + esc(ff[0]) + '\',Impact,sans-serif;font-weight:' + ff[1] + ';font-size:20px">' + esc(g.font) + '</span></dd>'; }
      if (g.color) h += '<dt>Druckfarbe</dt><dd><span class="sw" style="background:' + (COL[g.color] || '#fff') + '"></span>' + esc(g.color) + '</dd>';
      if (g.gr) h += '<dt>Druckgröße</dt><dd' + (/abweichend|Standard/.test(g.gr) ? ' style="color:#b8050f"' : '') + '>' + esc(g.gr) + (g.grK ? ' · Kinder bis 140: ' + esc(g.grK) : '') + '</dd>';
      if (g.lage && !g.gr) h += '<dt>Größe auf Vorschau</dt><dd>' + (g.isLogo ? 'Breite ' + g.lage.s + ' % der Vorschau' : 'Schrifthöhe ' + g.lage.s + ' % der Vorschau') + (g.lage.guess ? ' (Standard)' : '') + '</dd>';
      if (g.logoInfo) h += '<dt>Logo</dt><dd>' + esc(g.logoInfo) + '</dd>';
      h += '</dl>';
      if (g.same) h += '<div class="names"><b>Text:</b> ' + esc(g.same) + (g.fmt !== 'pauschal' ? ' <small>(auf allen ' + esc(g.qty) + ' Stück gleich)</small>' : '') + '</div>';
      if (g.list) {
        h += '<ul class="names">';
        sortSizes(Object.keys(g.list)).forEach(function (s) {
          var bad = !g.sizes[s] || g.sizes[s] !== g.list[s].length;
          h += '<li' + (bad ? ' style="color:#b8050f"' : '') + '><b>' + g.list[s].length + ' × ' + esc(s) + '</b> ' + g.list[s].map(esc).join(', ') + '</li>';
        });
        h += '</ul>';
      }
      g.problems.forEach(function (p) { h += '<div class="warn">' + esc(p) + '</div>'; });
      if (g.files.length) {
        h += '<div class="files">';
        g.files.forEach(function (f) {
          if (!f.url) { h += '<span class="warn">' + esc(f.label) + '</span> <a href="#" data-pf="' + esc(f.id) + '">Erneut versuchen</a>'; return; }
          h += '<img src="' + esc(f.url) + '" data-pfimg="' + esc(f.id) + '" alt="" onerror="this.remove()"><a href="#" data-pf="' + esc(f.id) + '">' + esc(f.label) + ' öffnen</a>';
        });
        h += '<div style="font-size:12px;color:#777">Die Datei wird bei jedem Klick frisch geladen.</div></div>';
      }
      h += '</div>';
    });
    if (other.length) {
      h += '<h2>Weitere Positionen</h2><table><tr><th>Position</th><th>Menge</th></tr>';
      other.forEach(function (i) { h += '<tr><td>' + esc(i.orderItemName) + '</td><td>' + esc(i.quantity) + '</td></tr>'; });
      h += '</table>';
    }
    h += '<div class="foot">Druckblatt aus PlentyONE · Auftrag ' + esc(o.id) + ' · Platzierung laut Kunden-Vorschau, endgültige Platzierung wie mit dem Kunden abgestimmt.</div></div>';
    lastHtml = h;
    box.innerHTML = bar('Druckblatt – Auftrag ' + o.id) + '<div class="w39db-sheet">' + h + '</div>';
  }

  start();
})();
