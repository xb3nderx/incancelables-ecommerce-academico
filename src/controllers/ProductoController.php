<?php

declare(strict_types=1);

namespace Incancelables\Controllers;

use Incancelables\Services\PedidoService;
use Incancelables\Services\ProductoService;
use RuntimeException;

final class ProductoController
{
    public function __construct(
        private ProductoService $productoService,
        private PedidoService $pedidoService,
    ) {
    }

    public function index(): void
    {
        $productos = $this->productoService->listarActivos();
        $disponibilidad = $this->pedidoService->disponibilidadDeCatalogo();

        $respuesta = [];
        foreach ($productos as $producto) {
            $id = (int) $producto['id'];

            if (!array_key_exists($id, $disponibilidad)) {
                throw new RuntimeException('Disponibilidad no calculada para el producto ' . $id);
            }

            $respuesta[] = [
                'id' => $id,
                'nombre' => $producto['nombre'],
                'descripcion' => $producto['descripcion'],
                'precio' => (float) $producto['precio'],
                'disponibilidad' => $disponibilidad[$id],
            ];
        }

        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
