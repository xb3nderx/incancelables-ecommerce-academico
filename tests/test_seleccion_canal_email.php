<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$index = $base . '/public/index.php';
$archivoConfig = $base . '/src/config/email.php';

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

function escribirPrepend(array $env): string
{
    $prepend = sys_get_temp_dir() . '/inc_prepend_canal_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.php';
    $contenido = "<?php\n";

    foreach ($env as $clave => $valor) {
        $contenido .= 'putenv(' . var_export($clave . '=' . $valor, true) . ");\n";
        $contenido .= '$_ENV[' . var_export($clave, true) . '] = ' . var_export($valor, true) . ";\n";
    }

    file_put_contents($prepend, $contenido);

    return $prepend;
}

function entornoNeutro(): array
{
    return ['EMAIL_CHANNEL' => '', 'RESEND_API_KEY' => '', 'RESEND_FROM' => ''];
}

function entornoResend(string $canal, string $apiKey, string $from): array
{
    return ['EMAIL_CHANNEL' => $canal, 'RESEND_API_KEY' => $apiKey, 'RESEND_FROM' => $from];
}

function ejecutarProbe(array $env): array
{
    $probe = sys_get_temp_dir() . '/inc_probe_canal_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.php';
    $prepend = escribirPrepend($env);

    file_put_contents(
        $probe,
        '<?php' . "\n"
        . '$config = require ' . var_export(dirname(__DIR__) . '/src/config/email.php', true) . ";\n"
        . 'echo json_encode(['
        . "'channel' => \$config['channel'], "
        . "'endpoint' => \$config['resend']['endpoint'], "
        . "'timeout' => \$config['resend']['timeout'], "
        . "'api_vacia' => \$config['resend']['api_key'] === '', "
        . "'from_vacio' => \$config['resend']['from'] === ''"
        . "]);\n"
    );

    $proceso = proc_open(
        [PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, $probe],
        [1 => ['pipe', 'w'], 2 => ['file', $probe . '.err', 'a']],
        $tuberias,
        dirname(__DIR__)
    );

    $salida = is_resource($proceso) ? (string) stream_get_contents($tuberias[1]) : '';

    if (is_resource($proceso)) {
        fclose($tuberias[1]);
        proc_close($proceso);
    }

    $datos = json_decode($salida, true);
    $stderr = (string) @file_get_contents($probe . '.err');

    @unlink($probe);
    @unlink($probe . '.err');
    @unlink($prepend);

    return [
        'datos' => is_array($datos) ? $datos : null,
        'salida' => $salida,
        'log' => $stderr,
    ];
}

function ejecutarBootstrap(array $env, string $archivoLog): array
{
    global $index, $base;

    $prepend = escribirPrepend($env);

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
        [PHP_BINARY, '-d', 'error_log=' . $archivoLog, '-d', 'auto_prepend_file=' . $prepend, $index],
        $descriptores,
        $tuberias,
        $base
    );

    if (!is_resource($proceso)) {
        @unlink($prepend);

        return ['salida' => '', 'log' => ''];
    }

    $salida = (string) stream_get_contents($tuberias[1]);
    fclose($tuberias[1]);
    proc_close($proceso);

    @unlink($prepend);

    $log = (string) @file_get_contents($archivoLog);

    return ['salida' => $salida, 'log' => $log];
}

echo "--- configuracion por defecto ---\n";

$probe = ejecutarProbe(entornoNeutro());

check('el probe devuelve JSON valido', is_array($probe['datos']), $probe['salida']);
check('sin errores de PHP en el probe', trim($probe['log']) === '', $probe['log']);

$config = $probe['datos'] ?? [];

check('channel por defecto = log', ($config['channel'] ?? null) === 'log', var_export($config['channel'] ?? null, true));
check('resend.endpoint estructural', ($config['endpoint'] ?? null) === 'https://api.resend.com/emails');
check('resend.timeout estructural = 10', ($config['timeout'] ?? null) === 10);
check('resend.api_key vacia por defecto', ($config['api_vacia'] ?? null) === true);
check('resend.from vacio por defecto', ($config['from_vacio'] ?? null) === true);

echo "\n--- canal log explicito ---\n";

$probe = ejecutarProbe(entornoResend('log', 're_test_dummy', 'Incancelables <no-reply@ejemplo.test>'));

check('channel explicito log se aplica', ($probe['datos']['channel'] ?? null) === 'log', (string) ($probe['datos']['channel'] ?? 'null'));
check('con canal log el endpoint se conserva', ($probe['datos']['endpoint'] ?? null) === 'https://api.resend.com/emails');
check('con canal log el timeout se conserva en 10', ($probe['datos']['timeout'] ?? null) === 10);
check('con canal log no se filtra la clave dummy', !str_contains($probe['salida'], 're_test_dummy'));

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

echo "\n--- seleccion de canal en el composition root ---\n";

$archivoLog = sys_get_temp_dir() . '/incancelables_bootstrap_test.log';

$ejecucion = ejecutarBootstrap(entornoNeutro(), $archivoLog);
check('channel=log: el bootstrap termina bien', str_contains($ejecucion['salida'], 'Ruta no encontrada'), $ejecucion['salida']);
check('channel=log: sin errores en el log', $ejecucion['log'] === '', $ejecucion['log']);

$ejecucion = ejecutarBootstrap(entornoResend('log', 're_test_dummy', 'Incancelables <no-reply@ejemplo.test>'), $archivoLog);
check('channel=log explicito: el bootstrap termina bien', str_contains($ejecucion['salida'], 'Ruta no encontrada'), $ejecucion['salida']);
check('channel=log explicito: sin errores en el log', $ejecucion['log'] === '', $ejecucion['log']);

$ejecucion = ejecutarBootstrap(entornoResend('resend', '', 'Incancelables <no-reply@ejemplo.test>'), $archivoLog);
check('channel=resend sin api_key: falla explicitamente', str_contains($ejecucion['salida'], 'Error interno del servidor'), $ejecucion['salida']);
check('channel=resend sin api_key: mensaje del fallo', str_contains($ejecucion['log'], 'sin api_key configurada'), $ejecucion['salida']);
check('channel=resend sin api_key: no hace fallback a log', !str_contains($ejecucion['salida'], 'Ruta no encontrada'));

$lineas = array_values(array_filter(explode("\n", trim($ejecucion['log']))));
check(
    'channel=resend sin api_key: solo el mensaje del fallo en el log',
    count($lineas) === 1 && str_contains($ejecucion['log'], '[incancelables] Canal de email resend sin api_key configurada'),
    $ejecucion['log']
);

$ejecucion = ejecutarBootstrap(entornoResend('resend', 're_test_dummy', ''), $archivoLog);
check('channel=resend sin from: falla explicitamente', str_contains($ejecucion['log'], 'sin remitente'), $ejecucion['salida']);
check('channel=resend sin from: no hace fallback a log', !str_contains($ejecucion['salida'], 'Ruta no encontrada'));
check('channel=resend sin from: sin secretos en el log', !str_contains($ejecucion['log'], 're_test_dummy'), $ejecucion['log']);

$ejecucion = ejecutarBootstrap(entornoResend('resend', 're_test_dummy', 'Incancelables <no-reply@ejemplo.test>'), $archivoLog);
check('channel=resend con configuracion: el bootstrap termina bien', str_contains($ejecucion['salida'], 'Ruta no encontrada'), $ejecucion['salida']);
check('channel=resend con configuracion: sin secretos en el log', !str_contains($ejecucion['log'], 're_test_dummy'), $ejecucion['log']);
check('channel=resend con configuracion: sin errores en el log', $ejecucion['log'] === '', $ejecucion['log']);

$ejecucion = ejecutarBootstrap(entornoResend('desconocido', '', ''), $archivoLog);
check('canal desconocido: falla explicitamente', str_contains($ejecucion['log'], 'Canal de email desconocido'), $ejecucion['log']);
check('canal desconocido: sin secretos en el log', !str_contains($ejecucion['log'], 're_test_dummy'), $ejecucion['log']);
check('canal desconocido: sin errores de PHP', !str_contains($ejecucion['log'], 'Fatal error') && !str_contains($ejecucion['log'], 'Uncaught'), $ejecucion['log']);

if (file_exists($archivoLog)) {
    unlink($archivoLog);
}

if (file_exists($archivoLog . '.stderr')) {
    unlink($archivoLog . '.stderr');
}

echo "\n--- los archivos locales reales permanecen intactos ---\n";

check('.env sigue existiendo', is_file($base . '/.env'));

echo "\nOK: $ok\n";

if ($fails !== []) {
    echo 'FALLAS: ' . count($fails) . ' -> ' . implode(', ', $fails) . "\n";
    exit(1);
}

echo "FALLAS: 0\n";
exit(0);
