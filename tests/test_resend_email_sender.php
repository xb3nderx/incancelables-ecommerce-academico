<?php

declare(strict_types=1);

require __DIR__ . '/../src/mail/EmailSender.php';
require __DIR__ . '/../src/mail/ResendEmailSender.php';

use Incancelables\Mail\ResendEmailSender;

const CLAVE_DE_TEST = 're_test_dummy';
const REMITENTE_DE_TEST = 'Incancelables <no-reply@ejemplo.test>';
const DESTINATARIO_DE_TEST = 'comprador@example.test';
const ASUNTO_DE_TEST = 'Confirmá tu compra en Incancelables';
const CUERPO_DE_TEST = "Hola,\n\nIngresá al siguiente enlace:\n\nhttp://127.0.0.1:5500/pages/resultado.html?flow=checkout&token=abc123\n\nIncancelables";

$ok = 0;
$fails = [];

function check(string $nombre, bool $cond, string $extra = ''): void
{
    global $ok, $fails;

    if ($cond) {
        $ok++;
        echo "[PASS] $nombre\n";
    } else {
        $fails[] = $nombre;
        echo "[FAIL] $nombre" . ($extra !== '' ? " — $extra" : '') . "\n";
    }
}

function puertoLibre(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($socket === false) {
        throw new RuntimeException('No se pudo obtener un puerto libre: ' . $errstr);
    }

    $nombre = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) $nombre, strrpos((string) $nombre, ':') + 1);
}

function arrancarServidor(int $puerto, string $router, string $archivoSalida, string $archivoError)
{
    $descriptores = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $archivoSalida, 'a'],
        2 => ['file', $archivoError, 'a'],
    ];

    $proceso = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $puerto, $router],
        $descriptores,
        $tuberias,
        dirname($router)
    );

    return is_resource($proceso) ? $proceso : null;
}

function esperarServidor(int $puerto): bool
{
    $limite = microtime(true) + 10.0;

    while (microtime(true) < $limite) {
        $conexion = @fsockopen('127.0.0.1', $puerto, $errno, $errstr, 0.25);

        if ($conexion !== false) {
            fclose($conexion);

            return true;
        }

        usleep(100000);
    }

    return false;
}

function leerRegistro(): ?array
{
    $archivo = sys_get_temp_dir() . '/incancelables_fixture_resend.json';
    $texto = @file_get_contents($archivo);

    if ($texto === false) {
        return null;
    }

    $datos = json_decode($texto, true);

    return is_array($datos) ? $datos : null;
}

function sender(int $puerto, string $ruta, int $timeout = 5): ResendEmailSender
{
    return new ResendEmailSender(
        CLAVE_DE_TEST,
        REMITENTE_DE_TEST,
        'http://127.0.0.1:' . $puerto . $ruta,
        $timeout,
    );
}

$archivoLog = sys_get_temp_dir() . '/incancelables_resend_test.log';
$archivoSalida = sys_get_temp_dir() . '/incancelables_resend_fixture_out.log';
$archivoError = sys_get_temp_dir() . '/incancelables_resend_fixture_err.log';

foreach ([$archivoLog, $archivoSalida, $archivoError] as $archivo) {
    if (file_exists($archivo)) {
        unlink($archivo);
    }
}

ini_set('error_log', $archivoLog);

$puerto = puertoLibre();
$router = __DIR__ . '/fixture_resend.php';
$proceso = arrancarServidor($puerto, $router, $archivoSalida, $archivoError);

if ($proceso === null || !esperarServidor($puerto)) {
    fwrite(STDERR, "No se pudo levantar el fixture local en 127.0.0.1:$puerto\n");
    if ($proceso !== null) {
        proc_terminate($proceso);
        proc_close($proceso);
    }
    exit(1);
}

$ping = @file_get_contents('http://127.0.0.1:' . $puerto . '/ping');
check('fixture local responde /ping', $ping === 'ok', var_export($ping, true));

try {
    $casos = [
        ['/ok', true, '200 + JSON {"id":"..."} -> true'],
        ['/sin-id', false, '200 + {} -> false'],
        ['/id-vacio', false, '200 + {"id":""} -> false'],
        ['/json-invalido', false, '200 + JSON inválido -> false'],
        ['/400', false, '400 -> false'],
        ['/401', false, '401 -> false'],
        ['/403', false, '403 -> false'],
        ['/429', false, '429 -> false'],
        ['/500', false, '500 -> false'],
        ['/503', false, '503 -> false'],
    ];

    foreach ($casos as [$ruta, $esperado, $nombre]) {
        $resultado = sender($puerto, $ruta)->enviar(DESTINATARIO_DE_TEST, ASUNTO_DE_TEST, CUERPO_DE_TEST);
        check($nombre, $resultado === $esperado, 'devolvió ' . var_export($resultado, true));
    }

    $registro = leerRegistro();
    check('el fixture registró el request', is_array($registro), 'sin registro');

    if (is_array($registro)) {
        $cuerpo = json_decode((string) ($registro['cuerpo'] ?? ''), true);
        $cabeceras = is_array($registro['cabeceras'] ?? null) ? $registro['cabeceras'] : [];

        check('request: método POST', ($registro['metodo'] ?? null) === 'POST', (string) ($registro['metodo'] ?? ''));
        check('request: JSON válido', is_array($cuerpo), (string) ($registro['cuerpo'] ?? ''));
        check('request: from configurado', is_array($cuerpo) && ($cuerpo['from'] ?? null) === REMITENTE_DE_TEST, is_array($cuerpo) ? (string) ($cuerpo['from'] ?? '') : '');
        check('request: to recibido', is_array($cuerpo) && ($cuerpo['to'] ?? null) === DESTINATARIO_DE_TEST, is_array($cuerpo) ? (string) ($cuerpo['to'] ?? '') : '');
        check('request: subject recibido', is_array($cuerpo) && ($cuerpo['subject'] ?? null) === ASUNTO_DE_TEST, is_array($cuerpo) ? (string) ($cuerpo['subject'] ?? '') : '');
        check('request: text recibido', is_array($cuerpo) && ($cuerpo['text'] ?? null) === CUERPO_DE_TEST, is_array($cuerpo) ? substr((string) ($cuerpo['text'] ?? ''), 0, 40) : '');
        check('request: Content-Type application/json', str_contains(strtolower((string) ($cabeceras['content-type'] ?? '')), 'application/json'), (string) ($cabeceras['content-type'] ?? ''));
        check('request: Authorization Bearer con la clave dummy', ($cabeceras['authorization'] ?? null) === 'Bearer ' . CLAVE_DE_TEST, (string) ($cabeceras['authorization'] ?? ''));
        check('request: User-Agent', ($cabeceras['user-agent'] ?? null) === 'Incancelables-ECommerce/1.0', (string) ($cabeceras['user-agent'] ?? ''));
    }

    $puertoCaido = puertoLibre();
    $resultado = sender($puertoCaido, '/ok')->enviar(DESTINATARIO_DE_TEST, ASUNTO_DE_TEST, CUERPO_DE_TEST);
    check('error de conexión/cURL -> false', $resultado === false, 'devolvió ' . var_export($resultado, true));

    $resultado = sender($puerto, '/lento', 1)->enviar(DESTINATARIO_DE_TEST, ASUNTO_DE_TEST, CUERPO_DE_TEST);
    check('timeout -> false', $resultado === false, 'devolvió ' . var_export($resultado, true));
} finally {
    proc_terminate($proceso);
    proc_close($proceso);
}

$log = (string) @file_get_contents($archivoLog);

check('log: se registró el fallo del transporte', str_contains($log, '[incancelables] email resend'), 'log vacío');
check('log: no contiene la API key', !str_contains($log, CLAVE_DE_TEST));
check('log: no contiene el header Authorization', !str_contains($log, 'Bearer'));
check('log: no contiene el destinatario', !str_contains($log, DESTINATARIO_DE_TEST));
check('log: no contiene el enlace/token del cuerpo', !str_contains($log, 'token=abc123'));
check('log: no contiene la respuesta cruda de Resend', !str_contains($log, 'demasiado-tarde') && !str_contains($log, 'validation_error'));

echo "\nOK: $ok\n";

if ($fails !== []) {
    echo 'FALLAS: ' . count($fails) . ' -> ' . implode(', ', $fails) . "\n";
    exit(1);
}

echo "FALLAS: 0\n";
exit(0);
