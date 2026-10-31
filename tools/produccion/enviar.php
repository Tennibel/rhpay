<?php
/**
 * Recibe los formularios del sitio (contacto y descarga del manual) y los envía por correo.
 * Se llama desde assets/js/main.js con un POST en JSON a /enviar.php.
 *
 * Envío:
 *  - Si existe config-correo.php (junto a este archivo) usa SMTP autenticado con esa cuenta (recomendado).
 *  - Si no existe, usa mail() de PHP.
 * Los errores de envío se guardan en ../enviar-errores.log (una carpeta arriba de public_html).
 */
declare(strict_types=1);
ini_set('display_errors', '0');

const DESTINO   = 'mkt@rhpay.com.mx';
const REMITENTE = 'formularios@rhpaysolutions.com.mx'; // debe ser una cuenta del dominio que aloja el sitio
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

function config(): array
{
    $ruta = __DIR__ . '/config-correo.php';
    if (!is_file($ruta)) {
        return [];
    }
    $c = include $ruta;
    return is_array($c) ? $c : [];
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
function enviar_smtp(array $c, string $de, string $para, string $asunto, string $cuerpo, string $replyTo): void
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
        smtp_cmd($f, 'DATA', [354]);

        $cab = [
            'Date: ' . date('r'),
            'From: ' . mb_encode_mimeheader('RH Pay Solutions', 'UTF-8', 'B', "\r\n") . ' <' . $de . '>',
            'To: <' . $para . '>',
            'Reply-To: ' . $replyTo,
            'Subject: ' . mb_encode_mimeheader($asunto, 'UTF-8', 'B', "\r\n"),
            'Message-ID: <' . bin2hex(random_bytes(8)) . '@rhpaysolutions.com.mx>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $datos = implode("\r\n", $cab) . "\r\n\r\n" . chunk_split(base64_encode($cuerpo), 76, "\r\n");
        fwrite($f, $datos . ".\r\n");
        smtp_cmd($f, '', [250]);
        @fwrite($f, "QUIT\r\n");
    } finally {
        @fclose($f);
    }
}

function enviar_correo(string $asunto, string $cuerpo, string $replyTo): void
{
    $c = config();
    if (!empty($c['host'])) {
        // El remitente debe ser la misma cuenta con la que se inicia sesión en el SMTP.
        enviar_smtp($c, (string) ($c['user'] ?? REMITENTE), DESTINO, $asunto, $cuerpo, $replyTo);
        return;
    }
    $cabeceras = [
        'From: RH Pay Solutions <' . REMITENTE . '>',
        'Reply-To: ' . $replyTo,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . PHP_VERSION,
    ];
    $ok = mail(DESTINO, mb_encode_mimeheader($asunto, 'UTF-8', 'B', "\r\n"), $cuerpo, implode("\r\n", $cabeceras), '-f' . REMITENTE);
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
    echo 'Remitente: ' . REMITENTE . "\nDestino: " . DESTINO . "\n";
    try {
        enviar_correo('[Sitio web] Prueba de envío', "Este es un correo de prueba del formulario de rhpaysolutions.com.mx.\nFecha: " . date('d/m/Y H:i') . "\n", REMITENTE);
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

try {
    enviar_correo($asunto, implode("\r\n", $lineas), $nombre . ' <' . $correo . '>');
} catch (Throwable $e) {
    registrar('Falló el envío (' . $formulario . ', ' . $correo . '): ' . $e->getMessage());
    responder(500, ['ok' => false, 'error' => 'envio']);
}

$marcas[] = $ahora;
@file_put_contents($archivo, json_encode($marcas), LOCK_EX);

responder(200, ['ok' => true]);
