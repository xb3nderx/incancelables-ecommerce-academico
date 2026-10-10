<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$archivoConfig = $base . '/src/config/database.php';

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

function codigoSeguro(Throwable $error): string
{
    $codigo = (string) $error->getCode();

    if ($codigo !== '' && $codigo !== '0') {
        return $codigo;
    }

    return get_class($error);
}

if (!is_file($archivoConfig)) {
    echo "No existe src/config/database.php\n";
    exit(1);
}

echo "--- carga de la configuración actual ---\n";

require $archivoConfig;

check('database.php entrega un objeto PDO', isset($pdo) && $pdo instanceof PDO, isset($pdo) ? gettype($pdo) : 'no definido');

if (!isset($pdo) || !$pdo instanceof PDO) {
    echo "\nOK: $ok\n";
    echo 'FALLAS: ' . count($fails) . ' -> ' . implode(', ', $fails) . "\n";
    exit(1);
}

echo "\n--- conexión válida ---\n";

try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    check('el driver de la conexión es mysql', $driver === 'mysql', (string) $driver);
} catch (Throwable $error) {
    check('el driver de la conexión es mysql', false, 'codigo ' . codigoSeguro($error));
}

try {
    $consulta = $pdo->query('SELECT 1');
    check('SELECT 1 devuelve un resultado', $consulta instanceof PDOStatement, get_debug_type($consulta));
} catch (Throwable $error) {
    check('SELECT 1 devuelve un resultado', false, 'codigo ' . codigoSeguro($error));
    $consulta = null;
}

if ($consulta instanceof PDOStatement) {
    try {
        $valor = $consulta->fetchColumn();
        check('SELECT 1 devuelve el valor 1', (string) $valor === '1', var_export($valor, true));
    } catch (Throwable $error) {
        check('SELECT 1 devuelve el valor 1', false, 'codigo ' . codigoSeguro($error));
    }

    check('la consulta es de sólo lectura', $consulta->columnCount() === 1, (string) $consulta->columnCount());
}

try {
    $estado = $pdo->getAttribute(PDO::ATTR_SERVER_INFO);
    check('la conexión sigue activa tras la consulta', is_string($estado) && trim($estado) !== '');
} catch (Throwable $error) {
    check('la conexión sigue activa tras la consulta', false, 'codigo ' . codigoSeguro($error));
}

echo "\nOK: $ok\n";

if ($fails !== []) {
    echo 'FALLAS: ' . count($fails) . ' -> ' . implode(', ', $fails) . "\n";
    exit(1);
}

echo "FALLAS: 0\n";
exit(0);
