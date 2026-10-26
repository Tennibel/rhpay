<?php
/**
 * Recibe los formularios del sitio (contacto y descarga del manual) y los envía por correo.
 * Se llama desde assets/js/main.js con un POST en JSON a /enviar.php.
 */
declare(strict_types=1);
ini_set('display_errors', '0');

const DESTINO   = 'mkt@rhpay.com.mx';
const REMITENTE = 'formularios@rhpaysolutions.com.mx'; // debe ser una cuenta del dominio que aloja el sitio
const MAX_ENVIOS_POR_VENTANA = 5;                       // por IP
const VENTANA_SEGUNDOS = 600;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
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

$cabeceras = [
    'From: RH Pay Solutions <' . REMITENTE . '>',
    'Reply-To: ' . $nombre . ' <' . $correo . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . PHP_VERSION,
];

$enviado = mail(
    DESTINO,
    mb_encode_mimeheader($asunto, 'UTF-8', 'B', "\r\n"),
    implode("\r\n", $lineas),
    implode("\r\n", $cabeceras),
    '-f' . REMITENTE
);

if (!$enviado) {
    responder(500, ['ok' => false, 'error' => 'envio']);
}

$marcas[] = $ahora;
@file_put_contents($archivo, json_encode($marcas), LOCK_EX);

responder(200, ['ok' => true]);
