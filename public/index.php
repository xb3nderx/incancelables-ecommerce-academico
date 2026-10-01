<?php

declare(strict_types=1);

spl_autoload_register(static function (string $clase): void {
    $prefijos = [
        'Incancelables\\Controllers\\' => __DIR__ . '/../src/controllers/',
        'Incancelables\\Services\\' => __DIR__ . '/../src/services/',
        'Incancelables\\Repositories\\' => __DIR__ . '/../src/repositories/',
        'Incancelables\\Payments\\' => __DIR__ . '/../src/payments/',
        'Incancelables\\Models\\' => __DIR__ . '/../src/models/',
    ];

    foreach ($prefijos as $prefijo => $directorio) {
        if (!str_starts_with($clase, $prefijo)) {
            continue;
        }

        $archivo = $directorio . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
        if (is_file($archivo)) {
            require $archivo;
        }

        return;
    }
});

header('Content-Type: application/json; charset=utf-8');

function responder(int $codigo, array $cuerpo): never
{
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$ruta = '/' . ltrim($ruta, '/');

try {
    $rutas = require __DIR__ . '/../routes/api.php';
    require __DIR__ . '/../src/config/database.php';

    $productoRepository = new \Incancelables\Repositories\ProductoRepository($pdo);
    $pedidoRepository = new \Incancelables\Repositories\PedidoRepository($pdo);
    $emailVerificationRepository = new \Incancelables\Repositories\EmailVerificationRepository($pdo);
    $productoService = new \Incancelables\Services\ProductoService($productoRepository);
    $emailVerificationService = new \Incancelables\Services\EmailVerificationService($emailVerificationRepository);
    $pagoRepository = new \Incancelables\Repositories\PagoRepository($pdo);
    $paymentValidationService = new \Incancelables\Services\PaymentValidationService(
        $pagoRepository,
        new \Incancelables\Payments\DummyPaymentProvider(),
    );
    $pedidoService = new \Incancelables\Services\PedidoService(
        $productoRepository,
        $pedidoRepository,
        $emailVerificationService,
        $pagoRepository,
        $paymentValidationService,
    );

    $controladores = [
        \Incancelables\Controllers\ProductoController::class => new \Incancelables\Controllers\ProductoController(
            $productoService,
            $pedidoService,
        ),
        \Incancelables\Controllers\PedidoController::class => new \Incancelables\Controllers\PedidoController(
            $pedidoService,
        ),
        \Incancelables\Controllers\EmailVerificationController::class => new \Incancelables\Controllers\EmailVerificationController(
            $pedidoService,
        ),
    ];

    $parametros = [];
    $permitidos = [];

    foreach ($rutas as $definicion => $valor) {
        [$metodoRuta, $rutaDefinida] = explode(' ', $definicion, 2);

        $patron = '#^';
        foreach (explode('/', $rutaDefinida) as $posicion => $segmento) {
            if ($posicion === 0 && $segmento === '') {
                continue;
            }

            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segmento, $coincidencia)) {
                $patron .= '/(?P<' . $coincidencia[1] . '>[^/]+)';
                continue;
            }

            $patron .= '/' . preg_quote($segmento, '#');
        }
        $patron .= '$#';

        if (!preg_match($patron, $ruta)) {
            continue;
        }

        if ($metodoRuta !== $metodo) {
            $permitidos[] = $metodoRuta;
            continue;
        }

        preg_match($patron, $ruta, $coincidencias);

        foreach (explode('/', $rutaDefinida) as $segmento) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $segmento, $nombre)) {
                $parametros[$nombre[1]] = $coincidencias[$nombre[1]];
            }
        }

        [$claseControlador, $accion] = $valor;
        break;
    }

    if (!isset($claseControlador)) {
        if ($permitidos !== []) {
            header('Allow: ' . implode(', ', $permitidos));
            responder(405, ['error' => 'Método no permitido']);
        }

        responder(404, ['error' => 'Ruta no encontrada']);
    }

    if (!isset($controladores[$claseControlador])) {
        throw new RuntimeException('Controlador no registrado: ' . $claseControlador);
    }

    $controladores[$claseControlador]->{$accion}($parametros);
} catch (Throwable $error) {
    error_log('[incancelables] ' . $error->getMessage());
    responder(500, ['error' => 'Error interno del servidor']);
}
