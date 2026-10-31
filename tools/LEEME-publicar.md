# Publicar RH Pay Solutions en producción (HostGator)

1. `node tools/build-produccion.js` genera la carpeta `dist/` (no se sube a Git).
2. Sube **el contenido** de `dist/` a `public_html` (incluye `.htaccess`, `enviar.php` y `404.html`; activa "mostrar archivos ocultos" en el administrador de archivos).
3. La cuenta que envía es `contacto@rhpaysolutions.com.mx` (la de BioSync); los mensajes llegan a mkt@rhpay.com.mx.
4. Prueba: envía el formulario de /contactanos/, abre una URL inexistente (debe verse la 404 de marca) y revisa https://rhpaysolutions.com.mx/robots.txt.
5. Search Console: enviar `https://rhpaysolutions.com.mx/sitemap.xml`.

Pendiente: redirecciones 301 del sitio anterior (sección 3 de `.htaccess`) y Google Analytics (ID `G-...`).

## Probar antes de publicar (subdominio de pruebas)
`node tools/build-produccion.js --pruebas` genera un `dist/` que conserva `noindex`, bloquea `robots.txt` y no redirige al dominio final. Súbelo a la raíz de un subdominio (por ejemplo `pruebas.rhpaysolutions.com.mx`), no a una subcarpeta: las rutas empiezan en `/`. Cuando termines, vuelve a correr el comando **sin** `--pruebas` para el sitio real.

## Correo del formulario (SMTP)
1. Usa la misma cuenta que BioSync: `contacto@rhpaysolutions.com.mx`, servidor `mail.rhpaysolutions.com.mx`, puerto 465 (sus datos están en `Biosync-Manager/sitio/.env`; la contraseña NO se guarda en Git).
2. En `public_html` copia `config-correo.ejemplo.php` como `config-correo.php` (Administrador de archivos > Copiar) y edítalo: contraseña y `clave_diagnostico`. Ese archivo no se sube a Git y el `.htaccess` impide abrirlo desde el navegador.
3. Abre `https://rhpaysolutions.com.mx/enviar.php?diagnostico=TU_CLAVE`: manda un correo de prueba a mkt@rhpay.com.mx y muestra el resultado o el error exacto.
4. Los fallos del formulario quedan en `enviar-errores.log`, una carpeta arriba de `public_html`.
Si no existe `config-correo.php`, el script usa `mail()` de PHP (menos confiable: puede caer en spam).
