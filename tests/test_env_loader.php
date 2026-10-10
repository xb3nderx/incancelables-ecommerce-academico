<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$loader = $base . '/src/config/env.php';

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

function limpiar(array $nombres): void
{
    foreach ($nombres as $nombre) {
        putenv($nombre);
        unset($_ENV[$nombre], $_SERVER[$nombre]);
    }
}

if (!is_file($loader)) {
    echo "No existe src/config/env.php\n";
    exit(1);
}

$sufijo = '_' . getmypid() . '_' . bin2hex(random_bytes(4));
$dirTmp = sys_get_temp_dir();
$archivo = $dirTmp . '/inc_env_loader' . $sufijo . '.env';

echo "--- carga del loader ---\n";

$salida = '';
ob_start();

require $loader;

$salida = (string) ob_get_clean();

check('el loader no emite salida al cargarse', $salida === '', $salida);
check('el loader define la función cargarVariablesDeEntorno', function_exists('cargarVariablesDeEntorno'));

$nombresUsados = [];

try {
    echo "\n--- archivo .env ausente ---\n";

    $ausente = $dirTmp . '/inc_env_loader' . $sufijo . '_ausente.env';

    if (file_exists($ausente)) {
        unlink($ausente);
    }

    $resultado = cargarVariablesDeEntorno($ausente);
    check('archivo ausente: no produce error', true);
    check('archivo ausente: retorna lista vacía', $resultado === [], var_export($resultado, true));
    check('archivo ausente: no define ninguna variable', getenv('INC_ENV_AUSENTE') === false);

    echo "\n--- archivo ilegible / ruta inválida ---\n";

    $resultado = cargarVariablesDeEntorno($dirTmp . '/inc_env_loader_no_existe' . $sufijo);
    check('ruta inexistente: retorna lista vacía', $resultado === []);

    echo "\n--- variables presentes ---\n";

    file_put_contents(
        $archivo,
        "INC_ENV_ALFA=valoralfa\nINC_ENV_BETA=\"valor beta\"\nINC_ENV_GAMMA='valor gamma'\n"
    );

    $nombresUsados = ['INC_ENV_ALFA', 'INC_ENV_BETA', 'INC_ENV_GAMMA'];
    $resultado = cargarVariablesDeEntorno($archivo);

    check('presentes: retorna los tres nombres', $resultado === $nombresUsados, var_export($resultado, true));
    check('presentes: getenv devuelve valoralfa', getenv('INC_ENV_ALFA') === 'valoralfa');
    check('presentes: las comillas dobles se quitan', getenv('INC_ENV_BETA') === 'valor beta');
    check('presentes: las comillas simples se quitan', getenv('INC_ENV_GAMMA') === 'valor gamma');
    check('presentes: $_ENV queda sincronizado', ($_ENV['INC_ENV_ALFA'] ?? null) === 'valoralfa');

    limpiar($nombresUsados);
    $nombresUsados = [];

    echo "\n--- variables vacías ---\n";

    file_put_contents(
        $archivo,
        "INC_ENV_VACIA=\nINC_ENV_VACIA_COMILLAS=\"\"\nINC_ENV_COMENTADA=#INC_ENV_OCULTA=secreto\n"
    );

    $nombresUsados = ['INC_ENV_VACIA', 'INC_ENV_VACIA_COMILLAS'];
    $resultado = cargarVariablesDeEntorno($archivo);

    check('vacías: la línea sin valor se carga', in_array('INC_ENV_VACIA', $resultado, true));
    check('vacías: getenv devuelve cadena vacía', getenv('INC_ENV_VACIA') === '');
    check('vacías: getenv no devuelve false', getenv('INC_ENV_VACIA') !== false);
    check('vacías: las comillas vacías se limpian', getenv('INC_ENV_VACIA_COMILLAS') === '');
    check('vacías: la línea comentada no se carga', getenv('INC_ENV_OCULTA') === false);

    limpiar($nombresUsados);
    $nombresUsados = [];

    echo "\n--- no sobrescribe variables ya definidas ---\n";

    putenv('INC_ENV_PREVIA=valor_original');
    $_ENV['INC_ENV_PREVIA'] = 'valor_original';

    file_put_contents(
        $archivo,
        "INC_ENV_PREVIA=valor_del_archivo\nINC_ENV_NUEVA=valor_nueva\n"
    );

    $resultado = cargarVariablesDeEntorno($archivo);

    check('no sobrescribe: conserva el valor original', getenv('INC_ENV_PREVIA') === 'valor_original');
    check('no sobrescribe: no figura como cargada', !in_array('INC_ENV_PREVIA', $resultado, true));
    check('no sobrescribe: la nueva sí se carga', getenv('INC_ENV_NUEVA') === 'valor_nueva');
    check('no sobrescribe: $_ENV conserva el original', ($_ENV['INC_ENV_PREVIA'] ?? null) === 'valor_original');

    limpiar(['INC_ENV_PREVIA', 'INC_ENV_NUEVA']);

    echo "\n--- archivo con formato inválido ---\n";

    file_put_contents(
        $archivo,
        "esto no tiene signo igual\n"
        . "SOLO_NOMBRE\n"
        . "=valor_sin_nombre\n"
        . "1MAL_NOMBRE=x\n"
        . "NOMBRE CON ESPACIOS=x\n"
        . "\n"
        . "   \n"
        . "# comentario libre\n"
        . "INC_ENV_VALIDA=ok\n"
    );

    $nombresUsados = ['INC_ENV_VALIDA'];
    $resultado = cargarVariablesDeEntorno($archivo);

    check('formato inválido: no produce error', true);
    check('formato inválido: sólo carga la línea válida', $resultado === ['INC_ENV_VALIDA'], var_export($resultado, true));
    check('formato inválido: la válida queda operativa', getenv('INC_ENV_VALIDA') === 'ok');

    limpiar($nombresUsados);
    $nombresUsados = [];

    echo "\n--- el loader no se ejecuta automáticamente ---\n";

    file_put_contents($archivo, "INC_ENV_AUTOMATICA=no_deberia_cargarse\n");
    $resultado = cargarVariablesDeEntorno($archivo);
    limpiar(['INC_ENV_AUTOMATICA']);

    file_put_contents($archivo, "INC_ENV_AUTOMATICA=no_deberia_cargarse\n");
    require $loader;
    check('requerir env.php de nuevo no carga nada', getenv('INC_ENV_AUTOMATICA') === false);

    limpiar(['INC_ENV_AUTOMATICA']);
} finally {
    limpiar($nombresUsados);
    limpiar(['INC_ENV_ALFA', 'INC_ENV_BETA', 'INC_ENV_GAMMA', 'INC_ENV_VACIA', 'INC_ENV_VACIA_COMILLAS', 'INC_ENV_OCULTA', 'INC_ENV_PREVIA', 'INC_ENV_NUEVA', 'INC_ENV_VALIDA', 'INC_ENV_AUTOMATICA']);

    if (file_exists($archivo)) {
        unlink($archivo);
    }

    $ausente = $dirTmp . '/inc_env_loader' . $sufijo . '_ausente.env';

    if (file_exists($ausente)) {
        unlink($ausente);
    }
}

check('no quedan archivos temporales del loader', !file_exists($archivo));

echo "\nOK: $ok\n";

if ($fails !== []) {
    echo 'FALLAS: ' . count($fails) . ' -> ' . implode(', ', $fails) . "\n";
    exit(1);
}

echo "FALLAS: 0\n";
exit(0);
