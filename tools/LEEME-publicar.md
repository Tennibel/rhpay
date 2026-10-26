# Publicar RH Pay Solutions en producción (HostGator)

1. `node tools/build-produccion.js` genera la carpeta `dist/` (no se sube a Git).
2. Sube **el contenido** de `dist/` a `public_html` (incluye `.htaccess`, `enviar.php` y `404.html`; activa "mostrar archivos ocultos" en el administrador de archivos).
3. En cPanel crea la cuenta de correo `formularios@rhpaysolutions.com.mx` (es el remitente de `enviar.php`; los mensajes llegan a `mkt@rhpay.com.mx`).
4. Prueba: envía el formulario de /contactanos/, abre una URL inexistente (debe verse la 404 de marca) y revisa https://rhpaysolutions.com.mx/robots.txt.
5. Search Console: enviar `https://rhpaysolutions.com.mx/sitemap.xml`.

Pendiente: redirecciones 301 del sitio anterior (sección 3 de `.htaccess`) y Google Analytics (ID `G-...`).

## Probar antes de publicar (subdominio de pruebas)
`node tools/build-produccion.js --pruebas` genera un `dist/` que conserva `noindex`, bloquea `robots.txt` y no redirige al dominio final. Súbelo a la raíz de un subdominio (por ejemplo `pruebas.rhpaysolutions.com.mx`), no a una subcarpeta: las rutas empiezan en `/`. Cuando termines, vuelve a correr el comando **sin** `--pruebas` para el sitio real.
