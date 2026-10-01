<?php

declare(strict_types=1);

namespace Incancelables\Repositories;

use PDO;

final class ProductoRepository
{
    public function __construct(private PDO $conexion)
    {
    }

    public function buscarActivos(): array
    {
        $consulta = $this->conexion->prepare(
            'SELECT id, nombre, descripcion, precio, stock
               FROM productos
              WHERE activo = 1
              ORDER BY id'
        );
        $consulta->execute();

        return $consulta->fetchAll();
    }

    public function bloquearPorIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        sort($ids, SORT_NUMERIC);

        $consulta = $this->conexion->prepare(
            'SELECT id, nombre, descripcion, precio, stock, activo
               FROM productos
              WHERE id = ?
              FOR UPDATE'
        );

        $productos = [];
        foreach ($ids as $id) {
            $consulta->execute([$id]);
            $fila = $consulta->fetch();

            if ($fila !== false) {
                $productos[(int) $fila['id']] = $fila;
            }
        }

        return $productos;
    }

    public function descontarStock(int $productoId, int $cantidad): void
    {
        $consulta = $this->conexion->prepare(
            'UPDATE productos
                SET stock = stock - ?
              WHERE id = ?
                AND stock >= ?'
        );
        $consulta->execute([$cantidad, $productoId, $cantidad]);

        if ($consulta->rowCount() !== 1) {
            throw new \RuntimeException('Stock insuficiente para descontar el producto: ' . $productoId);
        }
    }
}
