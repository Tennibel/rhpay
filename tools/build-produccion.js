/*
 * Genera la carpeta dist/ lista para subir a la raíz de rhpaysolutions.com.mx (HostGator).
 * Uso:  node tools/build-produccion.js
 *
 * No toca los archivos de origen: la vista previa en GitHub Pages (/rhpay/) sigue funcionando.
 * En dist/:
 *  - quita el prefijo /rhpay/ de todas las rutas
 *  - quita noindex (salvo en las páginas de "gracias" y en la 404) y abre robots.txt
 *  - conecta los formularios con /enviar.php
 *  - agrega .htaccess, enviar.php y 404.html
 */
const fs = require("fs");
const path = require("path");

const RAIZ = path.resolve(__dirname, "..");
const DIST = path.join(RAIZ, "dist");
const EXCLUIR = new Set([".git", ".gitignore", ".nojekyll", "tools", "dist", "node_modules"]);
const TEXTO = new Set([".html", ".css", ".js", ".xml", ".txt", ".svg", ".json"]);
const DOMINIO = "https://rhpaysolutions.com.mx";
const GTM_ID = "GTM-PRZSSZQD"; // Google Tag Manager: solo se instala en la versión de producción, no en la de pruebas ni en GitHub Pages
const GTM_HEAD = `<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','${GTM_ID}');</script>
`;
const GA_ID = "G-C6RT1J8431"; // Google Analytics 4 (gtag directo). No agregues también una etiqueta GA4 en Tag Manager: contaría cada visita dos veces.
const GA_HEAD = `<script async src="https://www.googletagmanager.com/gtag/js?id=${GA_ID}"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','${GA_ID}');</script>
`;
const GTM_BODY = `
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=${GTM_ID}" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>`;

const PRUEBAS = process.argv.includes("--pruebas"); // para un subdominio de pruebas: conserva noindex y bloquea robots
const cuentas = { archivos: 0, prefijo: 0, noindex: 0, gtm: 0 };

function copiar(origen, destino) {
  fs.mkdirSync(destino, { recursive: true });
  for (const e of fs.readdirSync(origen, { withFileTypes: true })) {
    if (origen === RAIZ && EXCLUIR.has(e.name)) continue;
    const o = path.join(origen, e.name);
    const d = path.join(destino, e.name);
    if (e.isDirectory()) copiar(o, d);
    else {
      cuentas.archivos++;
      if (TEXTO.has(path.extname(e.name).toLowerCase())) fs.writeFileSync(d, transformar(o, fs.readFileSync(o, "utf8")));
      else fs.copyFileSync(o, d);
    }
  }
}

function transformar(ruta, s) {
  const rel = path.relative(RAIZ, ruta).split(path.sep).join("/");
  const antes = s;
  s = s.split("/rhpay/").join("/");
  if (s !== antes) cuentas.prefijo++;

  if (rel.endsWith(".html") && !PRUEBAS && rel !== "404.html") {
    if (!/<head>/.test(s) || !/<body[^>]*>/.test(s)) throw new Error("Sin <head> o <body> en " + rel);
    s = s.replace(/<head>\r?\n/, (m) => m + GTM_HEAD + GA_HEAD).replace(/<body[^>]*>/, (m) => m + GTM_BODY);
    cuentas.gtm++;
  }
  if (rel.endsWith(".html")) {
    const esGracias = rel.includes("/gracias/");
    if (!esGracias && !PRUEBAS) {
      const n = s.length;
      s = s.replace(/<meta name="robots" content="noindex[^"]*">\s*/g, "");
      if (s.length !== n) cuentas.noindex++;
    }
  }
  if (rel === "assets/js/main.js") {
    if (!s.includes('const FORM_ENDPOINT = "";')) throw new Error("No encontré FORM_ENDPOINT en main.js");
    s = s.replace('const FORM_ENDPOINT = "";', 'const FORM_ENDPOINT = "/enviar.php";');
  }
  if (rel === "sitemap.xml") {
    const hoy = new Date().toISOString().slice(0, 10);
    s = s.replace(/<lastmod>[^<]*<\/lastmod>/g, `<lastmod>${hoy}</lastmod>`);
  }
  return s;
}

function crear404() {
  const base = fs.readFileSync(path.join(DIST, "contactanos", "gracias", "index.html"), "utf8");
  const contenido = `<section class="cta-final">
  <div class="container">
    <p class="eyebrow">Error 404</p>
    <h1>No encontramos esta página</h1>
    <p class="lead">La dirección que buscas ya no existe o cambió de lugar. Te dejamos algunos caminos para continuar.</p>
    <div class="btn-row">
      <a class="btn btn--primary" href="/"><svg class="ico" aria-hidden="true"><use href="/assets/img/icons.svg#arrow-right"/></svg> Ir al inicio</a>
      <a class="btn btn--ghost" href="/guia-de-nomina/"><svg class="ico" aria-hidden="true"><use href="/assets/img/icons.svg#book"/></svg> Leer la Guía de Nómina</a>
      <a class="btn btn--ghost" href="/contactanos/"><svg class="ico" aria-hidden="true"><use href="/assets/img/icons.svg#calendar"/></svg> Contáctanos</a>
    </div>
  </div>
</section>`;
  let s = base.replace(/<section class="cta-final">[\s\S]*?<\/section>/, () => contenido);
  s = s
    .replace(/<title>[^<]*<\/title>/, "<title>Página no encontrada | RH Pay</title>")
    .replace(/<meta name="description" content="[^"]*">/, '<meta name="description" content="La página que buscas no existe. Ve al inicio de RH Pay Solutions.">')
    .replace(/<link rel="canonical"[^>]*>\s*/, "")
    .replace(/<meta property="og:url"[^>]*>\s*/, "")
    .replace(/<meta property="og:title" content="[^"]*">/, '<meta property="og:title" content="Página no encontrada | RH Pay">')
    .replace(/<script type="application\/ld\+json">[\s\S]*?<\/script>\s*/g, "");
  if (!s.includes('content="noindex')) s = s.replace("</head>", '<meta name="robots" content="noindex, follow">\n</head>');
  fs.writeFileSync(path.join(DIST, "404.html"), s);
}

function verificar() {
  const problemas = [];
  (function recorrer(d) {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) { recorrer(p); continue; }
      if (!TEXTO.has(path.extname(e.name).toLowerCase())) continue;
      const s = fs.readFileSync(p, "utf8");
      const rel = path.relative(DIST, p).split(path.sep).join("/");
      if (s.includes("/rhpay/")) problemas.push(`${rel}: aún contiene /rhpay/`);
      if (!PRUEBAS && rel.endsWith(".html") && /name="robots" content="noindex/.test(s) && !(rel.includes("/gracias/") || rel === "404.html")) problemas.push(`${rel}: conserva noindex`);
    }
  })(DIST);
  return problemas;
}

fs.rmSync(DIST, { recursive: true, force: true });
copiar(RAIZ, DIST);

const robots = PRUEBAS
  ? ["User-agent: *", "Disallow: /", ""].join("\n")
  : ["User-agent: *", "Allow: /", "Disallow: /enviar.php", "", `Sitemap: ${DOMINIO}/sitemap.xml`, ""].join("\n");
fs.writeFileSync(path.join(DIST, "robots.txt"), robots);

let ht = fs.readFileSync(path.join(__dirname, "produccion", ".htaccess"), "utf8");
if (PRUEBAS) {
  // En pruebas no se redirige al dominio final: solo se fuerza HTTPS en el mismo host y no se toca el "www".
  const lineas = ht.split("\n");
  const salida = [];
  let enWww = false;
  for (const l of lineas) {
    if (l.includes("# 2) Sin")) { enWww = true; continue; }
    if (enWww) { if (l.includes("# 3)")) enWww = false; else continue; }
    salida.push(l.includes("https://rhpaysolutions.com.mx%{REQUEST_URI}") ? l.replace("rhpaysolutions.com.mx", "%{HTTP_HOST}") : l);
  }
  ht = salida.join("\n");
}
fs.writeFileSync(path.join(DIST, ".htaccess"), ht);
fs.copyFileSync(path.join(__dirname, "produccion", "enviar.php"), path.join(DIST, "enviar.php"));
fs.copyFileSync(path.join(__dirname, "produccion", "config-correo.ejemplo.php"), path.join(DIST, "config-correo.ejemplo.php"));
crear404();

const problemas = verificar();
console.log(`Archivos copiados: ${cuentas.archivos}`);
console.log(`Archivos con prefijo /rhpay/ corregido: ${cuentas.prefijo}`);
console.log(`Páginas con noindex retirado: ${cuentas.noindex}`);
console.log(`Páginas con Google Tag Manager: ${cuentas.gtm}`);
console.log(PRUEBAS ? "MODO PRUEBAS: noindex conservado y robots.txt bloqueado." : "");
console.log(problemas.length ? "PROBLEMAS:\n" + problemas.join("\n") : "Verificación: sin /rhpay/ ni noindex fuera de las páginas permitidas.");
process.exit(problemas.length ? 1 : 0);
