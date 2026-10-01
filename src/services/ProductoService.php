<?php

declare(strict_types=1);

namespace Incancelables\Services;

use Incancelables\Repositories\ProductoRepository;

final class ProductoService
{
    public function __construct(private ProductoRepository $productoRepository)
    {
    }

    public function listarActivos(): array
    {
        return $this->productoRepository->buscarActivos();
    }
}
