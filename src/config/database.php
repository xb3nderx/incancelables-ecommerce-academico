<?php

require_once __DIR__ . '/env.php';

cargarVariablesDeEntorno(dirname(__DIR__, 2) . '/.env');

if (!function_exists('valorDeEntornoMariaDb')) {
    function valorDeEntornoMariaDb(string $nombre): string
    {
        $valor = getenv($nombre);

        if ($valor === false) {
            throw new RuntimeException('Falta la variable de entorno obligatoria ' . $nombre . ' para la conexion a la base de datos');
        }

        return $valor;
    }
}

$host = valorDeEntornoMariaDb('DB_HOST');
$port = valorDeEntornoMariaDb('DB_PORT');
$dbname = valorDeEntornoMariaDb('DB_NAME');
$username = valorDeEntornoMariaDb('DB_USER');
$password = valorDeEntornoMariaDb('DB_PASSWORD');

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

$pdo = new PDO($dsn, $username, $password);

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
