<?php

declare(strict_types=1);

return [
    'GET /api/productos' => [\Incancelables\Controllers\ProductoController::class, 'index'],
    'POST /api/pedidos' => [\Incancelables\Controllers\PedidoController::class, 'crear'],
    'POST /api/pedidos/{id}/cancelar' => [\Incancelables\Controllers\PedidoController::class, 'cancelar'],
    'POST /api/pedidos/{id}/pago' => [\Incancelables\Controllers\PedidoController::class, 'pago'],
    'POST /api/email-verificaciones/validar' => [\Incancelables\Controllers\EmailVerificationController::class, 'validar'],
    'POST /api/email-verificaciones/reenvio' => [\Incancelables\Controllers\EmailVerificationController::class, 'reenvio'],
    'POST /api/email-verificaciones/pedidos' => [\Incancelables\Controllers\EmailVerificationController::class, 'pedidos'],
    'POST /api/email-verificaciones/pedidos/reemplazo' => [\Incancelables\Controllers\EmailVerificationController::class, 'reemplazar'],
];
