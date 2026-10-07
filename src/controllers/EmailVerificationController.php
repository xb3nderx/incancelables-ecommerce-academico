<?php

declare(strict_types=1);

namespace Incancelables\Controllers;

use Incancelables\Services\PedidoService;

final class EmailVerificationController
{
    public function __construct(private PedidoService $pedidoService)
    {
    }

    public function validar(): void
    {
        $datos = $this->leerCuerpo();

        if (!is_array($datos)) {
            responder(400, ['error' => 'Cuerpo JSON inválido']);
        }

        if (!array_key_exists('token', $datos)) {
            responder(400, ['error' => 'Falta el campo token']);
        }

        if (!is_string($datos['token']) || trim($datos['token']) === '' || strlen($datos['token']) > 255) {
            responder(400, ['error' => 'token inválido']);
        }

        $resultado = $this->pedidoService->confirmarVerificacionEmail(trim($datos['token']));

        if ($resultado['resultado'] === 'confirmado') {
            responder(200, [
                'resultado' => 'confirmado',
                'email_verificacion_id' => $resultado['email_verificacion_id'],
                'pedidos' => $resultado['pedidos'],
            ]);
        }

        responder(400, ['error' => $resultado['resultado']]);
    }

    public function pedidos(): void
    {
        $datos = $this->leerCuerpo();

        if (!is_array($datos)) {
            responder(400, ['error' => 'Cuerpo JSON inválido']);
        }

        if (!array_key_exists('token', $datos)) {
            responder(400, ['error' => 'Falta el campo token']);
        }

        if (!is_string($datos['token']) || trim($datos['token']) === '' || strlen($datos['token']) > 255) {
            responder(400, ['error' => 'token inválido']);
        }

        $resultado = $this->pedidoService->obtenerPedidosPorToken(trim($datos['token']));

        if ($resultado['resultado'] === 'ok') {
            responder(200, [
                'resultado' => 'ok',
                'pedidos' => $resultado['pedidos'],
            ]);
        }

        $errores = [
            'token_invalido' => 400,
            'token_expirado' => 400,
            'verificacion_pendiente' => 409,
        ];

        responder($errores[$resultado['error']] ?? 500, ['error' => $resultado['error']]);
    }

    /**
     * Reemplazo del Pedido activo de una verificación ya confirmada, autorizado
     * por el token: valida la estructura del request y delega la operación en
     * PedidoService (que a su vez delega en el mecanismo de sustitución
     * existente). El controller no contiene lógica de negocio.
     */
    public function reemplazar(): void
    {
        $datos = $this->leerCuerpo();

        if (!is_array($datos)) {
            responder(400, ['error' => 'Cuerpo JSON inválido']);
        }

        if (!array_key_exists('token', $datos)) {
            responder(400, ['error' => 'Falta el campo token']);
        }

        if (!is_string($datos['token']) || trim($datos['token']) === '' || strlen($datos['token']) > 255) {
            responder(400, ['error' => 'token inválido']);
        }

        if (!array_key_exists('items', $datos)) {
            responder(400, ['error' => 'Falta el campo items']);
        }

        $items = ItemsRequestValidator::validar($datos);

        $resultado = $this->pedidoService->reemplazarPedidoPorToken(trim($datos['token']), $items);

        if ($resultado['resultado'] === 'error') {
            $this->responderErrorDeReemplazo($resultado);
        }

        responder(200, $resultado);
    }

    private function responderErrorDeReemplazo(array $resultado): never
    {
        $errores = [
            'token_invalido' => 400,
            'token_expirado' => 400,
            'verificacion_pendiente' => 409,
            'pedido_no_activo' => 409,
            'varios_pedidos_activos' => 409,
            'pedido_inexistente' => 404,
            'pedido_vencido' => 409,
            'carrito_vacio' => 400,
            'accion_invalida' => 400,
        ];

        $cuerpo = ['error' => $resultado['error']];

        if (isset($resultado['pedido'])) {
            $cuerpo['pedido'] = $resultado['pedido'];
        }

        responder($errores[$resultado['error']] ?? 500, $cuerpo);
    }

    public function reenvio(): void
    {
        $datos = $this->leerCuerpo();

        if (!is_array($datos)) {
            responder(400, ['error' => 'Cuerpo JSON inválido']);
        }

        if (!array_key_exists('pedido_id', $datos)) {
            responder(400, ['error' => 'Falta el campo pedido_id']);
        }

        $pedidoId = filter_var($datos['pedido_id'], FILTER_VALIDATE_INT);

        if ($pedidoId === false || $pedidoId <= 0) {
            responder(400, ['error' => 'pedido_id inválido']);
        }

        $resultado = $this->pedidoService->reenviarVerificacionEmail((int) $pedidoId);

        if ($resultado['resultado'] === 'reenviado') {
            responder(200, [
                'resultado' => 'reenviado',
                'email_verificacion_id' => $resultado['email_verificacion_id'],
            ]);
        }

        if ($resultado['resultado'] === 'cooldown') {
            responder(429, [
                'error' => 'cooldown_activo',
                'segundos_restantes' => $resultado['segundos_restantes'],
            ]);
        }

        if ($resultado['resultado'] === 'ya_verificado') {
            responder(409, ['error' => 'verificacion_ya_verificada']);
        }

        $errores = [
            'pedido_inexistente' => 404,
            'pedido_no_pendiente_de_verificacion' => 409,
            'pedido_vencido' => 409,
            'error_envio_email' => 502,
        ];

        $codigo = $errores[$resultado['error']] ?? 500;

        $cuerpo = ['error' => $resultado['error']];

        if (isset($resultado['pedido'])) {
            $cuerpo['pedido'] = $resultado['pedido'];
        }

        responder($codigo, $cuerpo);
    }

    private function leerCuerpo(): ?array
    {
        $cuerpo = file_get_contents('php://input');
        $datos = json_decode($cuerpo === false ? '' : $cuerpo, true);

        return is_array($datos) ? $datos : null;
    }
}
