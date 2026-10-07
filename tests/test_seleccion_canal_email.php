<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$index = $base . '/public/index.php';
$archivoConfig = $base . '/src/config/email.php';
$archivoLocal = $base . '/src/config/email.local.php';
$archivoEjemplo = $base . '/src/config/email.local.example.php';

require $base . '/src/mail/EmailSender.php';
require $base . '/src/mail/LogEmailSender.php';
require $base . '/src/mail/ResendEmailSender.php';

use Incancelables\Mail\EmailSender;
use Incancelables\Mail\LogEmailSender;
use Incancelables\Mail\ResendEmailSender;

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

function escribirLocal(?string $channel, ?string $apiKey, ?string $from): void
{
    global $archivoLocal;

    $config = [];

    if ($channel !== null) {
        $config['channel'] = $channel;
    }

    if ($apiKey !== null || $from !== null) {
        $config['resend'] = array_filter([
            'api_key' => $apiKey,
            'from' => $from,
        ], static fn (string $valor): bool => $valor !== '');
    }

    file_put_contents($archivoLocal, "<?php\n\nreturn " . var_export($config, true) . ";\n");
}

function ejecutarBootstrap(string $archivoLog): array
{
    global $index, $base;

    foreach ([$archivoLog, $archivoLog . '.stderr'] as $archivo) {
        if (file_exists($archivo)) {
            unlink($archivo);
        }
    }

    $descriptores = [
        1 => ['pipe', 'w'],
        2 => ['file', $archivoLog . '.stderr', 'a'],
    ];

    $proceso = proc_open(
        [PHP_BINARY, '-d', 'error_log=' . $archivoLog, $index],
        $descriptores,
        $tuberias,
        $base
    );

    if (!is_resource($proceso)) {
        return ['salida' => '', 'log' => ''];
    }

    $salida = (string) stream_get_contents($tuberias[1]);
    fclose($tuberias[1]);
    proc_close($proceso);

    $log = (string) @file_get_contents($archivoLog);

    return ['salida' => $salida, 'log' => $log];
}

if (file_exists($archivoLocal)) {
    echo "OMITIDO: existe src/config/email.local.php (configuración local real).\n";
    echo "Quitá temporalmente ese archivo para correr esta suite de selección de canal.\n";
    exit(0);
}

$archivoLog = sys_get_temp_dir() . '/incancelables_bootstrap_test.log';

echo "--- configuración por defecto ---\n";

$config = require $archivoConfig;
check('channel por defecto = log', ($config['channel'] ?? null) === 'log', var_export($config['channel'] ?? null, true));
check('resend.endpoint estructural', ($config['resend']['endpoint'] ?? null) === 'https://api.resend.com/emails');
check('resend.timeout estructural = 10', ($config['resend']['timeout'] ?? null) === 10);
check('resend.api_key vacía por defecto', ($config['resend']['api_key'] ?? null) === '');
check('resend.from vacío por defecto', ($config['resend']['from'] ?? null) === '');
check('el archivo de ejemplo existe y no se auto-carga', is_file($archivoEjemplo) && ($config['resend']['api_key'] ?? null) === '');

echo "\n--- contrato intacto ---\n";

check('LogEmailSender sigue implementando EmailSender', is_subclass_of(LogEmailSender::class, EmailSender::class));
check('ResendEmailSender implementa EmailSender', is_subclass_of(ResendEmailSender::class, EmailSender::class));

foreach ([LogEmailSender::class, ResendEmailSender::class] as $clase) {
    $metodo = new ReflectionMethod($clase, 'enviar');
    $parametros = $metodo->getParameters();

    check(
        $clase . '::enviar(string, string, string): bool',
        count($parametros) === 3
            && (string) $parametros[0]->getType() === 'string'
            && (string) $parametros[1]->getType() === 'string'
            && (string) $parametros[2]->getType() === 'string'
            && (string) $metodo->getReturnType() === 'bool'
    );
}

$pedidoService = (string) file_get_contents($base . '/src/services/PedidoService.php');
$emailVerificationService = (string) file_get_contents($base . '/src/services/EmailVerificationService.php');

check('PedidoService no conoce Resend', !str_contains($pedidoService, 'Resend'));
check('PedidoService no conoce cURL', !str_contains($pedidoService, 'curl'));
check('EmailVerificationService no conoce Resend', !str_contains($emailVerificationService, 'Resend'));

echo "\n--- selección de canal en el composition root ---\n";

$ejecucion = ejecutarBootstrap($archivoLog);
check('channel=log: el bootstrap termina bien', str_contains($ejecucion['salida'], 'Ruta no encontrada'), $ejecucion['salida']);
check('channel=log: sin errores en el log', $ejecucion['log'] === '', $ejecucion['log']);

try {
    escribirLocal('resend', '', '');
    $ejecucion = ejecutarBootstrap($archivoLog);
    check('channel=resend sin api_key: falla explícitamente', str_contains($ejecucion['salida'], 'Error interno del servidor'), $ejecucion['salida']);
    check('channel=resend sin api_key: mensaje del fallo', str_contains($ejecucion['log'], 'sin api_key configurada'), $ejecucion['log']);
    check('channel=resend sin api_key: no hace fallback a log', !str_contains($ejecucion['salida'], 'Ruta no encontrada'));

    $lineas = array_values(array_filter(explode("\n", trim($ejecucion['log']))));
    check(
        'channel=resend sin api_key: sólo el mensaje del fallo en el log',
        count($lineas) === 1 && str_contains($ejecucion['log'], '[incancelables] Canal de email resend sin api_key configurada'),
        $ejecucion['log']
    );

    escribirLocal('resend', 're_test_dummy', '');
    $ejecucion = ejecutarBootstrap($archivoLog);
    check('channel=resend sin from: falla explícitamente', str_contains($ejecucion['log'], 'sin remitente'), $ejecucion['log']);
    check('channel=resend sin from: no hace fallback a log', !str_contains($ejecucion['salida'], 'Ruta no encontrada'));
    check('channel=resend sin from: sin secretos en el log', !str_contains($ejecucion['log'], 're_test_dummy'), $ejecucion['log']);

    escribirLocal('resend', 're_test_dummy', 'Incancelables <no-reply@ejemplo.test>');
    $ejecucion = ejecutarBootstrap($archivoLog);
    check('channel=resend con configuración: el bootstrap termina bien', str_contains($ejecucion['salida'], 'Ruta no encontrada'), $ejecucion['salida']);
    check('channel=resend con configuración: sin secretos en el log', !str_contains($ejecucion['log'], 're_test_dummy'), $ejecucion['log']);
    check('channel=resend con configuración: sin errores en el log', $ejecucion['log'] === '', $ejecucion['log']);

    escribirLocal('desconocido', '', '');
    $ejecucion = ejecutarBootstrap($archivoLog);
    check('canal desconocido: falla explícitamente', str_contains($ejecucion['log'], 'Canal de email desconocido'), $ejecucion['log']);
    check('canal desconocido: sin errores de PHP', !str_contains($ejecucion['log'], 'Fatal error') && !str_contains($ejecucion['log'], 'Uncaught'), $ejecucion['log']);
} finally {
    if (file_exists($archivoLocal)) {
        unlink($archivoLocal);
    }
}

check('no queda email.local.php tras la suite', !file_exists($archivoLocal));

echo "\nOK: $ok\n";

if ($fails !== []) {
    echo 'FALLAS: ' . count($fails) . ' -> ' . implode(', ', $fails) . "\n";
    exit(1);
}

echo "FALLAS: 0\n";
exit(0);
