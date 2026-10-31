<?php
/**
 * Recibe los formularios del sitio (contacto y descarga del manual) y los envía por correo.
 * Se llama desde assets/js/main.js con un POST en JSON a /enviar.php.
 *
 * Envío:
 *  - Si existe config-correo.php (junto a este archivo) usa SMTP autenticado con esa cuenta (recomendado).
 *  - Si no existe, usa mail() de PHP.
 * El correo llega en HTML con la marca del sitio y una versión de texto plano.
 * Los errores de envío se guardan en ../enviar-errores.log (una carpeta arriba de public_html).
 */
declare(strict_types=1);
ini_set('display_errors', '0');

const DESTINO   = 'mkt@rhpay.com.mx';
const REMITENTE = 'formularios@rhpaysolutions.com.mx'; // se usa solo si no hay config-correo.php
const MAX_ENVIOS_POR_VENTANA = 5;                       // por IP
const VENTANA_SEGUNDOS = 600;

function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function registrar(string $mensaje): void
{
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $mensaje . "\n";
    foreach ([dirname(__DIR__) . '/enviar-errores.log', __DIR__ . '/enviar-errores.log'] as $ruta) {
        if (@file_put_contents($ruta, $linea, FILE_APPEND | LOCK_EX) !== false) {
            return;
        }
    }
}

function limpiar($valor, int $max, bool $saltos = false): string
{
    $valor = strip_tags((string) $valor);
    $valor = $saltos
        ? preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r", '', $valor))
        : preg_replace('/[\p{C}]+/u', ' ', $valor);
    $valor = trim((string) $valor);
    return mb_substr($valor, 0, $max);
}

function h(string $t): string
{
    return htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
}

function config(): array
{
    $ruta = __DIR__ . '/config-correo.php';
    if (!is_file($ruta)) {
        return [];
    }
    $c = include $ruta;
    return is_array($c) ? $c : [];
}

/** Arma el cuerpo MIME: solo texto, o multipart/alternative (texto + HTML). Devuelve [cabeceras, cuerpo]. */
function armar_mime(string $texto, ?string $html): array
{
    $b64 = static fn (string $t): string => chunk_split(base64_encode($t), 76, "\r\n");
    if ($html === null) {
        return [['Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'], $b64($texto)];
    }
    $lim = 'rhpay_' . bin2hex(random_bytes(8));
    $cuerpo = '--' . $lim . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($texto)
        . '--' . $lim . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html)
        . '--' . $lim . "--\r\n";
    return [['Content-Type: multipart/alternative; boundary="' . $lim . '"'], $cuerpo];
}

/**
 * Correo HTML con la marca del sitio.
 * @param array<string,string> $filas   etiqueta => valor (el valor ya viene escapado / con HTML)
 * @param array<string,string> $botones etiqueta => URL
 */
function armar_html(string $etiqueta, string $titulo, string $subtitulo, array $filas, string $mensaje, array $botones, string $pie): string
{
    $azul = '#1A237E';
    $naranja = '#FF731D';
    $f = '';
    foreach ($filas as $k => $v) {
        $f .= '<tr><td style="padding:10px 14px;background:#F4F6FB;border-radius:10px 0 0 10px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#5A6072;width:34%;vertical-align:top;">' . h($k) . '</td>'
            . '<td style="padding:10px 14px;background:#F4F6FB;border-radius:0 10px 10px 0;font-size:15px;font-weight:600;color:#141A45;vertical-align:top;">' . $v . '</td></tr>';
    }
    $m = '';
    if ($mensaje !== '') {
        $m = '<tr><td style="padding:6px 32px 8px;"><div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#5A6072;margin:6px 0 8px;">Mensaje</div>'
            . '<div style="font-size:15px;line-height:1.6;color:#141A45;background:#FFF4EC;border-left:4px solid ' . $naranja . ';padding:14px 16px;border-radius:0 12px 12px 0;">' . nl2br(h($mensaje)) . '</div></td></tr>';
    }
    $b = '';
    foreach ($botones as $txt => $url) {
        $principal = $b === '';
        $b .= '<a href="' . h($url) . '" style="display:inline-block;margin:0 8px 8px 0;padding:12px 20px;border-radius:999px;font-size:14px;font-weight:700;text-decoration:none;'
            . ($principal ? 'background:' . $naranja . ';color:#FFFFFF;' : 'background:#FFFFFF;color:' . $azul . ';border:1.5px solid #C9D1EE;') . '">' . h($txt) . '</a>';
    }
    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($titulo) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#EEF1F8;">'
        . '<div style="background:#EEF1F8;padding:28px 12px;font-family:Segoe UI,Helvetica,Arial,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#FFFFFF;border-radius:20px;overflow:hidden;box-shadow:0 10px 30px rgba(14,20,82,.12);">'
        . '<tr><td style="background:#0E1452;background-image:linear-gradient(135deg,#1A237E,#0E1452);padding:26px 32px;"><img src="https://rhpaysolutions.com.mx/assets/img/logos/logo-correo.png" alt="RH Pay Solutions" width="150" style="display:block;width:150px;height:auto;border:0;"></td></tr>'
        . '<tr><td style="height:4px;background:' . $naranja . ';line-height:4px;font-size:0;">&nbsp;</td></tr>'
        . '<tr><td style="padding:26px 32px 0;"><span style="display:inline-block;background:' . $naranja . ';color:#FFFFFF;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:6px 14px;border-radius:999px;">' . h($etiqueta) . '</span>'
        . '<div style="font-size:24px;line-height:1.25;font-weight:800;color:' . $azul . ';margin-top:14px;">' . h($titulo) . '</div>'
        . '<div style="font-size:14px;color:#5A6072;margin-top:4px;">' . h($subtitulo) . '</div></td></tr>'
        . '<tr><td style="padding:18px 32px 6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0 6px;">' . $f . '</table></td></tr>'
        . $m
        . '<tr><td style="padding:14px 32px 22px;">' . $b . '</td></tr>'
        . '<tr><td style="padding:16px 32px;background:#F4F6FB;border-top:1px solid #E3E8F5;font-size:12px;line-height:1.6;color:#5A6072;">' . $pie . '</td></tr>'
        . '</table></div></body></html>';
}

/** Lee la respuesta de un servidor SMTP y devuelve [código, texto]. */
function smtp_leer($f): array
{
    $texto = '';
    while (($l = fgets($f, 1024)) !== false) {
        $texto .= $l;
        if (strlen($l) < 4 || $l[3] === ' ') {
            break;
        }
    }
    return [(int) substr($texto, 0, 3), trim($texto)];
}

function smtp_cmd($f, string $cmd, array $esperados): string
{
    if ($cmd !== '') {
        fwrite($f, $cmd . "\r\n");
    }
    [$cod, $txt] = smtp_leer($f);
    if (!in_array($cod, $esperados, true)) {
        throw new RuntimeException('SMTP respondió ' . $txt . ($cmd !== '' && strpos($cmd, 'AUTH') !== 0 ? ' (a: ' . $cmd . ')' : ''));
    }
    return $txt;
}

/** Envío por SMTP autenticado, sin librerías externas. Lanza RuntimeException si falla. */
function enviar_smtp(array $c, string $de, string $para, string $asunto, string $cuerpo, string $replyTo, ?string $html = null): void
{
    $host   = (string) ($c['host'] ?? '');
    $puerto = (int) ($c['port'] ?? 465);
    $modo   = (string) ($c['secure'] ?? 'ssl'); // ssl | tls | ''
    $prefijo = $modo === 'ssl' ? 'ssl://' : '';
    $f = @stream_socket_client($prefijo . $host . ':' . $puerto, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
    if (!$f) {
        throw new RuntimeException("No se pudo conectar a $host:$puerto ($errstr)");
    }
    stream_set_timeout($f, 15);
    try {
        smtp_cmd($f, '', [220]);
        $ehlo = gethostname() ?: 'rhpaysolutions.com.mx';
        smtp_cmd($f, 'EHLO ' . $ehlo, [250]);
        if ($modo === 'tls') {
            smtp_cmd($f, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($f, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo activar TLS');
            }
            smtp_cmd($f, 'EHLO ' . $ehlo, [250]);
        }
        if (!empty($c['user'])) {
            smtp_cmd($f, 'AUTH LOGIN', [334]);
            smtp_cmd($f, base64_encode((string) $c['user']), [334]);
            smtp_cmd($f, base64_encode((string) ($c['pass'] ?? '')), [235]);
        }
        smtp_cmd($f, 'MAIL FROM:<' . $de . '>', [250]);
        smtp_cmd($f, 'RCPT TO:<' . $para . '>', [250, 251]);
        // Copias ocultas (por ejemplo, para comprobar que los correos llegan): config-correo.php > 'copia'.
        foreach ((array) ($c['copia'] ?? []) as $extra) {
            $extra = filter_var(trim((string) $extra), FILTER_VALIDATE_EMAIL);
            if ($extra) {
                smtp_cmd($f, 'RCPT TO:<' . $extra . '>', [250, 251]);
            }
        }
        smtp_cmd($f, 'DATA', [354]);

        $cab = [
            'Date: ' . date('r'),
            'From: ' . mb_encode_mimeheader('RH Pay Solutions', 'UTF-8', 'B', "\r\n") . ' <' . $de . '>',
            'To: <' . $para . '>',
            'Reply-To: ' . $replyTo,
            'Subject: ' . mb_encode_mimeheader($asunto, 'UTF-8', 'B', "\r\n"),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@rhpaysolutions.com.mx>',
            'MIME-Version: 1.0',
        ];
        [$cabMime, $cuerpoMime] = armar_mime($cuerpo, $html);
        $datos = implode("\r\n", array_merge($cab, $cabMime)) . "\r\n\r\n" . $cuerpoMime;
        fwrite($f, $datos . ".\r\n");
        smtp_cmd($f, '', [250]);
        @fwrite($f, "QUIT\r\n");
    } finally {
        @fclose($f);
    }
}

function enviar_correo(string $asunto, string $cuerpo, string $replyTo, ?string $html = null): void
{
    $c = config();
    if (!empty($c['host'])) {
        // El remitente debe ser la misma cuenta con la que se inicia sesión en el SMTP.
        enviar_smtp($c, (string) ($c['user'] ?? REMITENTE), DESTINO, $asunto, $cuerpo, $replyTo, $html);
        return;
    }
    [$cabMime, $cuerpoMime] = armar_mime($cuerpo, $html);
    $cabeceras = array_merge([
        'From: RH Pay Solutions <' . REMITENTE . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'X-Mailer: PHP/' . PHP_VERSION,
    ], $cabMime);
    $ok = mail(DESTINO, mb_encode_mimeheader($asunto, 'UTF-8', 'B', "\r\n"), $cuerpoMime, implode("\r\n", $cabeceras), '-f' . REMITENTE);
    if (!$ok) {
        throw new RuntimeException('mail() de PHP devolvió falso');
    }
}

/* ---------- Diagnóstico: /enviar.php?diagnostico=CLAVE ---------- */
if (isset($_GET['diagnostico'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $c = config();
    $clave = (string) ($c['clave_diagnostico'] ?? '');
    if ($clave === '' || !hash_equals($clave, (string) $_GET['diagnostico'])) {
        http_response_code(404);
        exit("No encontrado\n");
    }
    echo 'PHP ' . PHP_VERSION . "\n";
    echo 'Modo de envío: ' . (!empty($c['host']) ? 'SMTP (' . $c['host'] . ':' . ($c['port'] ?? 465) . ', ' . ($c['secure'] ?? 'ssl') . ')' : 'mail() de PHP') . "\n";
    echo 'Remitente: ' . (!empty($c['host']) ? ($c['user'] ?? REMITENTE) : REMITENTE) . "\nDestino: " . DESTINO . "\n";
    $copias = array_filter((array) ($c['copia'] ?? []));
    echo 'Copia oculta a: ' . ($copias ? implode(', ', $copias) : '(ninguna)') . "\n";
    try {
        $fecha = date('d/m/Y H:i');
        $html = armar_html(
            'Prueba de envío',
            'El formulario está funcionando',
            'Correo de prueba de rhpaysolutions.com.mx',
            ['Estado' => 'Enviado correctamente', 'Fecha' => h($fecha)],
            '',
            ['Ir al sitio' => 'https://rhpaysolutions.com.mx/'],
            'Este es un correo de prueba generado desde el enlace de diagnóstico.'
        );
        enviar_correo('[Sitio web] Prueba de envío', "Este es un correo de prueba del formulario de rhpaysolutions.com.mx.\nFecha: " . $fecha . "\n", REMITENTE, $html);
        echo "RESULTADO: enviado. Revisa la bandeja (y spam) de " . DESTINO . "\n";
    } catch (Throwable $e) {
        echo 'RESULTADO: ERROR - ' . $e->getMessage() . "\n";
    }
    exit;
}

/* ---------- Formularios ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'metodo']);
}

// Solo acepta envíos hechos desde el propio sitio.
$origen = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
if ($origen !== '' && parse_url($origen, PHP_URL_HOST) !== $host) {
    responder(403, ['ok' => false, 'error' => 'origen']);
}

$crudo = file_get_contents('php://input');
if ($crudo === false || strlen($crudo) > 20000) {
    responder(413, ['ok' => false, 'error' => 'tamano']);
}
$d = json_decode($crudo, true);
if (!is_array($d)) {
    responder(400, ['ok' => false, 'error' => 'formato']);
}

// Campo trampa: las personas no lo ven, los robots sí lo llenan. Se responde "ok" sin enviar nada.
if (!empty($d['web'])) {
    responder(200, ['ok' => true]);
}

// Límite simple por IP.
$ip      = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$archivo = sys_get_temp_dir() . '/rhpay_form_' . md5($ip) . '.json';
$ahora   = time();
$marcas  = [];
if (is_file($archivo)) {
    $marcas = json_decode((string) file_get_contents($archivo), true) ?: [];
    $marcas = array_values(array_filter($marcas, fn ($t) => is_int($t) && $t > $ahora - VENTANA_SEGUNDOS));
}
if (count($marcas) >= MAX_ENVIOS_POR_VENTANA) {
    responder(429, ['ok' => false, 'error' => 'limite']);
}

$formulario = in_array($d['formulario'] ?? '', ['contacto', 'manual'], true) ? $d['formulario'] : 'contacto';
$nombre     = limpiar($d['nombre'] ?? '', 120);
$empresa    = limpiar($d['empresa'] ?? '', 160);
$correo     = filter_var(trim((string) ($d['correo'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
$telefono   = limpiar($d['telefono'] ?? '', 40);
$interes    = limpiar($d['interes'] ?? '', 120);
$colabs     = limpiar($d['colaboradores'] ?? '', 40);
$mensaje    = limpiar($d['mensaje'] ?? '', 2000, true);
$origenForm = limpiar($d['origen'] ?? '', 60);
$pagina     = limpiar($d['pagina'] ?? '', 200);
$aviso      = !empty($d['aviso']);

if ($nombre === '' || $empresa === '' || $correo === '' || !$aviso) {
    responder(422, ['ok' => false, 'error' => 'datos']);
}

$titulos = ['contacto' => 'Solicitud de diagnóstico', 'manual' => 'Descarga del manual'];
$asunto  = '[Sitio web] ' . $titulos[$formulario] . ' - ' . $empresa;

$tel = preg_replace('/\D+/', '', $telefono) ?? '';
$wa  = strlen($tel) === 10 ? '52' . $tel : $tel;

/* Versión de texto plano */
$lineas = [
    $titulos[$formulario],
    str_repeat('-', 40),
    'Nombre: ' . $nombre,
    'Empresa: ' . $empresa,
    'Correo: ' . $correo,
    'Teléfono: ' . ($telefono !== '' ? $telefono : 'No indicado'),
];
if ($interes !== '') {
    $lineas[] = 'Interés: ' . $interes;
}
if ($colabs !== '') {
    $lineas[] = 'Colaboradores: ' . $colabs;
}
if ($mensaje !== '') {
    $lineas[] = '';
    $lineas[] = 'Mensaje:';
    $lineas[] = $mensaje;
}
$lineas[] = '';
$lineas[] = str_repeat('-', 40);
$lineas[] = 'Aceptó el aviso de privacidad: sí';
$lineas[] = 'Página: ' . ($pagina !== '' ? $pagina : '-') . ($origenForm !== '' ? ' (origen: ' . $origenForm . ')' : '');
$lineas[] = 'Fecha: ' . date('d/m/Y H:i') . ' (hora del servidor)';
$lineas[] = 'IP: ' . $ip;

/* Versión HTML */
$filas = [
    'Nombre'   => h($nombre),
    'Empresa'  => h($empresa),
    'Correo'   => '<a href="mailto:' . h($correo) . '" style="color:#1A237E;text-decoration:none;">' . h($correo) . '</a>',
    'Teléfono' => $telefono !== ''
        ? '<a href="tel:+' . h($tel) . '" style="color:#1A237E;text-decoration:none;">' . h($telefono) . '</a>'
        : '<span style="color:#8A90A2;font-weight:400;">No indicado</span>',
];
if ($interes !== '') {
    $filas['Le interesa'] = h($interes);
}
if ($colabs !== '') {
    $filas['Colaboradores'] = h($colabs);
}
$botones = ['Responder por correo' => 'mailto:' . $correo . '?subject=' . rawurlencode('Re: ' . $titulos[$formulario] . ' - RH Pay Solutions')];
if (strlen($tel) >= 10) {
    $botones['Escribir por WhatsApp'] = 'https://wa.me/' . $wa;
}
$pie = 'Enviado desde <strong>' . h($pagina !== '' ? $pagina : '/') . '</strong>' . ($origenForm !== '' ? ' · origen: ' . h($origenForm) : '')
     . '<br>' . date('d/m/Y H:i') . ' (hora del servidor) · IP ' . h($ip) . '<br>Aceptó el aviso de privacidad.';
$html = armar_html(
    $formulario === 'manual' ? 'Descarga del manual' : 'Nueva solicitud',
    $empresa,
    $nombre . ' · ' . $titulos[$formulario],
    $filas,
    $mensaje,
    $botones,
    $pie
);

try {
    enviar_correo($asunto, implode("\r\n", $lineas), $nombre . ' <' . $correo . '>', $html);
} catch (Throwable $e) {
    registrar('Falló el envío (' . $formulario . ', ' . $correo . '): ' . $e->getMessage());
    responder(500, ['ok' => false, 'error' => 'envio']);
}

$marcas[] = $ahora;
@file_put_contents($archivo, json_encode($marcas), LOCK_EX);

responder(200, ['ok' => true]);
