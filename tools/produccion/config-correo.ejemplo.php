<?php
/**
 * Configuración del envío por SMTP.
 *
 * 1. Copia este archivo como config-correo.php (en la misma carpeta que enviar.php, dentro de public_html).
 * 2. Escribe la contraseña de la cuenta formularios@rhpaysolutions.com.mx (la que creaste en cPanel).
 * 3. Cambia 'clave_diagnostico' por una frase larga y propia.
 * 4. Abre https://rhpaysolutions.com.mx/enviar.php?diagnostico=TU_CLAVE para mandar un correo de prueba.
 *
 * Datos del servidor: en cPanel > Cuentas de correo > Conectar dispositivos, usa "Servidor saliente".
 * En HostGator suele ser mail.rhpaysolutions.com.mx, puerto 465 con SSL.
 */
return [
    'host'   => 'mail.rhpaysolutions.com.mx',
    'port'   => 465,
    'secure' => 'ssl',   // 'ssl' (puerto 465), 'tls' (puerto 587) o '' (sin cifrado, solo para pruebas locales)
    'user'   => 'formularios@rhpaysolutions.com.mx',
    'pass'   => 'ESCRIBE_AQUI_LA_CONTRASENA',
    'clave_diagnostico' => 'cambia-esta-frase-por-una-clave-larga',
];
