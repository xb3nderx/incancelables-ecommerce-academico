<?php

declare(strict_types=1);

namespace Incancelables\Controllers;

use Incancelables\Services\PedidoService;

final class PedidoController
{
    public function __construct(private PedidoService $pedidoService)
    {
    }

    public function crear(): void
    {
        $cuerpo = file_get_contents('php://input');
        $datos = json_decode($cuerpo === false ? '' : $cuerpo, true);

        if (!is_array($datos)) {
            responder(400, ['error' => 'Cuerpo JSON inválido']);
        }

        foreach (['email', 'nombre', 'apellido', 'items'] as $campo) {
            if (!array_key_exists($campo, $datos)) {
                responder(400, ['error' => 'Falta el campo ' . $campo]);
            }
        }

        if (!is_string($datos['email']) || filter_var($datos['email'], FILTER_VALIDATE_EMAIL) === false) {
            responder(400, ['error' => 'Email inválido']);
        }

        if (!is_string($datos['nombre']) || trim($datos['nombre']) === '' || mb_strlen($datos['nombre']) > 100) {
            responder(400, ['error' => 'Nombre inválido']);
        }

        if (!is_string($datos['apellido']) || trim($datos['apellido']) === '' || mb_strlen($datos['apellido']) > 100) {
            responder(400, ['error' => 'Apellido inválido']);
        }

        $items = $this->validarItemsCuerpo($datos);

        $resultado = $this->pedidoService->crearPedido(
            $datos['email'],
            trim($datos['nombre']),
            trim($datos['apellido']),
            $items,
        );

        if ($resultado['resultado'] === 'creado') {
            http_response_code(201);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function cancelar(array $parametros = []): void
    {
        $pedidoId = $this->pedidoIdDesdeParametros($parametros);

        $resultado = $this->pedidoService->cancelarPedido($pedidoId);

        if ($resultado['resultado'] === 'error') {
            $this->responderErrorDePedido($resultado);
        }

        responder(200, $resultado);
    }

    public function pago(array $parametros = []): void
    {
        $pedidoId = $this->pedidoIdDesdeParametros($parametros);

        $cuerpo = file_get_contents('php://input');
        $datos = json_decode($cuerpo === false ? '' : $cuerpo, true);

        if (!is_array($datos)) {
            responder(400, ['error' => 'Cuerpo JSON inválido']);
        }

        if (!array_key_exists('accion', $datos)) {
            responder(400, ['error' => 'Falta el campo accion']);
        }

        if (!is_string($datos['accion']) || !in_array($datos['accion'], ['iniciar_pago', 'aceptar_correccion'], true)) {
            responder(400, ['error' => 'accion inválida']);
        }

        $items = [];
        $proveedor = null;
        $simulacion = null;

        if ($datos['accion'] === 'iniciar_pago') {
            if (!array_key_exists('items', $datos)) {
                responder(400, ['error' => 'Falta el campo items']);
            }

            $items = $this->validarItemsCuerpo($datos);

            if (!array_key_exists('proveedor_id', $datos)) {
                responder(400, ['error' => 'Falta el campo proveedor_id']);
            }

            if (is_int($datos['proveedor_id'])) {
                $proveedor = (string) $datos['proveedor_id'];
            } elseif (is_string($datos['proveedor_id']) && trim($datos['proveedor_id']) !== '') {
                $proveedor = trim($datos['proveedor_id']);
            } else {
                responder(400, ['error' => 'proveedor_id inválido']);
            }

            if (array_key_exists('simulacion', $datos)) {
                if (!is_string($datos['simulacion']) || !in_array($datos['simulacion'], ['APROBADO', 'RECHAZADO', 'EN_PROCESO'], true)) {
                    responder(400, ['error' => 'simulacion inválida']);
                }

                $simulacion = $datos['simulacion'];
            }
        }

        if ($datos['accion'] === 'aceptar_correccion') {
            if (!array_key_exists('items', $datos)) {
                responder(400, ['error' => 'Falta el campo items']);
            }

            $items = $this->validarItemsCuerpo($datos);
        }

        $resultado = $this->pedidoService->procesarPago($pedidoId, $datos['accion'], $items, $proveedor, $simulacion);

        if ($resultado['resultado'] === 'error') {
            $this->responderErrorDePedido($resultado);
        }

        responder(200, $resultado);
    }

    private function pedidoIdDesdeParametros(array $parametros): int
    {
        $pedidoId = isset($parametros['id']) ? filter_var($parametros['id'], FILTER_VALIDATE_INT) : false;

        if ($pedidoId === false || $pedidoId <= 0) {
            responder(400, ['error' => 'pedido_id inválido']);
        }

        return (int) $pedidoId;
    }

    private function responderErrorDePedido(array $resultado): never
    {
        $errores = [
            'pedido_inexistente' => 404,
            'pedido_vencido' => 409,
            'pedido_no_activo' => 409,
            'pedido_no_pendiente_de_pago' => 409,
            'pedido_no_procesando' => 409,
            'pago_ya_aprobado' => 409,
            'maximo_intentos_alcanzado' => 409,
            'accion_invalida' => 400,
            'carrito_vacio' => 400,
            'simulacion_invalida' => 400,
            'proveedor_faltante' => 400,
            'proveedor_inexistente' => 400,
            'proveedor_no_soportado' => 400,
        ];

        $cuerpo = ['error' => $resultado['error']];

        if (isset($resultado['pedido'])) {
            $cuerpo['pedido'] = $resultado['pedido'];
        }

        foreach (['intentos', 'maximo_intentos'] as $campo) {
            if (isset($resultado[$campo])) {
                $cuerpo[$campo] = $resultado[$campo];
            }
        }

        responder($errores[$resultado['error']] ?? 500, $cuerpo);
    }

    private function validarItemsCuerpo(array $datos): array
    {
        if (!is_array($datos['items']) || $datos['items'] === []) {
            responder(400, ['error' => 'El carrito está vacío']);
        }

        $items = [];

        foreach ($datos['items'] as $item) {
            if (!is_array($item) || !array_key_exists('producto_id', $item) || !array_key_exists('cantidad', $item)) {
                responder(400, ['error' => 'Ítem de carrito inválido']);
            }

            $productoId = filter_var($item['producto_id'], FILTER_VALIDATE_INT);
            $cantidad = filter_var($item['cantidad'], FILTER_VALIDATE_INT);

            if ($productoId === false || $productoId <= 0) {
                responder(400, ['error' => 'producto_id inválido']);
            }

            if ($cantidad === false || $cantidad <= 0) {
                responder(400, ['error' => 'cantidad inválida']);
            }

            if (!array_key_exists('precio_unitario', $item)) {
                responder(400, ['error' => 'precio_unitario es obligatorio']);
            }

            if (!is_numeric($item['precio_unitario'])) {
                responder(400, ['error' => 'precio_unitario inválido']);
            }

            $items[] = [
                'producto_id' => $productoId,
                'cantidad' => $cantidad,
                'precio_unitario' => (float) $item['precio_unitario'],
            ];
        }

        return $items;
    }
}
