<?php

declare(strict_types=1);

namespace Incancelables\Controllers;

/**
 * Validación estructural de los items[] del cuerpo JSON (misma reglas para
 * POST /api/pedidos, POST /api/pedidos/{id}/pago y
 * POST /api/email-verificaciones/pedidos/reemplazo).
 */
final class ItemsRequestValidator
{
    public static function validar(array $datos): array
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
