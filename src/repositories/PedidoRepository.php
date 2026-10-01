<?php

declare(strict_types=1);

namespace Incancelables\Repositories;

use PDO;

final class PedidoRepository
{
    public function __construct(private PDO $conexion)
    {
    }

    public function cantidadesComprometidasPorProducto(array $codigosEstado, ?int $excluirPedidoId = null): array
    {
        if ($codigosEstado === []) {
            return [];
        }

        $marcadores = implode(', ', array_fill(0, count($codigosEstado), '?'));

        $sql = "SELECT pi.producto_id AS producto_id,
                    SUM(pi.cantidad) AS cantidad
               FROM pedidos_items pi
               INNER JOIN pedidos p ON p.id = pi.pedido_id
               INNER JOIN pedidos_estados e ON e.id = p.estado_id
              WHERE e.codigo IN ($marcadores)";

        $parametros = array_values($codigosEstado);

        if ($excluirPedidoId !== null) {
            $sql .= ' AND p.id <> ?';
            $parametros[] = $excluirPedidoId;
        }

        $sql .= ' GROUP BY pi.producto_id';

        $consulta = $this->conexion->prepare($sql);
        $consulta->execute($parametros);

        $cantidades = [];
        foreach ($consulta->fetchAll() as $fila) {
            $cantidades[(int) $fila['producto_id']] = (int) $fila['cantidad'];
        }

        return $cantidades;
    }

    public function vencerPrimerPeriodoVencidos(array $codigosEstado, int $minutos): int
    {
        if ($codigosEstado === []) {
            return 0;
        }

        $marcadores = implode(', ', array_fill(0, count($codigosEstado), '?'));

        $consulta = $this->conexion->prepare(
            "UPDATE pedidos p
                INNER JOIN pedidos_estados e ON e.id = p.estado_id
                SET p.estado_id = (SELECT id FROM pedidos_estados WHERE codigo = 'NO_PAGADO')
              WHERE e.codigo IN ($marcadores)
                AND p.created_at <= NOW() - INTERVAL " . (int) $minutos . ' MINUTE'
        );
        $consulta->execute(array_values($codigosEstado));

        return $consulta->rowCount();
    }

    public function idDeEstado(string $codigo): int
    {
        $consulta = $this->conexion->prepare(
            'SELECT id
               FROM pedidos_estados
              WHERE codigo = ?'
        );
        $consulta->execute([$codigo]);
        $fila = $consulta->fetch();

        if ($fila === false) {
            throw new \RuntimeException('Estado de pedido inexistente: ' . $codigo);
        }

        return (int) $fila['id'];
    }

    public function iniciarTransaccion(): void
    {
        if (!$this->conexion->inTransaction()) {
            $this->conexion->beginTransaction();
        }
    }

    public function confirmar(): void
    {
        $this->conexion->commit();
    }

    public function deshacer(): void
    {
        if ($this->conexion->inTransaction()) {
            $this->conexion->rollBack();
        }
    }

    public function buscarPorId(int $pedidoId, bool $bloquear = false): ?array
    {
        $consulta = $this->conexion->prepare(
            'SELECT p.id,
                    p.email,
                    p.nombre,
                    p.apellido,
                    e.codigo AS estado,
                    p.email_verificacion_id,
                    p.created_at,
                    NOW() AS ahora
               FROM pedidos p
               INNER JOIN pedidos_estados e ON e.id = p.estado_id
              WHERE p.id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $consulta->execute([$pedidoId]);
        $fila = $consulta->fetch();

        if ($fila === false) {
            return null;
        }

        return [
            'id' => (int) $fila['id'],
            'email' => $fila['email'],
            'nombre' => $fila['nombre'],
            'apellido' => $fila['apellido'],
            'estado' => $fila['estado'],
            'email_verificacion_id' => $fila['email_verificacion_id'] === null
                ? null
                : (int) $fila['email_verificacion_id'],
            'created_at' => $fila['created_at'],
            'ahora' => $fila['ahora'],
        ];
    }

    public function buscarItemsPorPedido(int $pedidoId): array
    {
        $consulta = $this->conexion->prepare(
            'SELECT producto_id, cantidad, precio_unitario
               FROM pedidos_items
              WHERE pedido_id = ?
              ORDER BY id'
        );
        $consulta->execute([$pedidoId]);

        $items = [];
        foreach ($consulta->fetchAll() as $fila) {
            $items[] = [
                'producto_id' => (int) $fila['producto_id'],
                'cantidad' => (int) $fila['cantidad'],
                'precio_unitario' => (float) $fila['precio_unitario'],
            ];
        }

        return $items;
    }

    public function buscarTodosPorEmailVerificacion(int $emailVerificacionId): array
    {
        $consulta = $this->conexion->prepare(
            'SELECT p.id,
                    e.codigo AS estado,
                    p.created_at,
                    NOW() AS ahora
               FROM pedidos p
               INNER JOIN pedidos_estados e ON e.id = p.estado_id
              WHERE p.email_verificacion_id = ?
              ORDER BY p.id'
        );
        $consulta->execute([$emailVerificacionId]);

        return $consulta->fetchAll();
    }

    public function asociarEmailVerificacion(int $pedidoId, int $emailVerificacionId): void
    {
        $consulta = $this->conexion->prepare(
            'UPDATE pedidos
                SET email_verificacion_id = ?
              WHERE id = ?'
        );
        $consulta->execute([$emailVerificacionId, $pedidoId]);
    }

    public function actualizarEstado(int $pedidoId, int $estadoId): void
    {
        $consulta = $this->conexion->prepare(
            'UPDATE pedidos
                SET estado_id = ?
              WHERE id = ?'
        );
        $consulta->execute([$estadoId, $pedidoId]);
    }

    public function insertarPedido(array $pedido): int
    {
        $consulta = $this->conexion->prepare(
            'INSERT INTO pedidos (email, nombre, apellido, estado_id, email_verificacion_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $consulta->execute([
            $pedido['email'],
            $pedido['nombre'],
            $pedido['apellido'],
            $pedido['estado_id'],
            $pedido['email_verificacion_id'] ?? null,
        ]);

        return (int) $this->conexion->lastInsertId();
    }

    public function insertarPedidoItems(int $pedidoId, array $items): void
    {
        $consulta = $this->conexion->prepare(
            'INSERT INTO pedidos_items (pedido_id, producto_id, cantidad, precio_unitario)
             VALUES (?, ?, ?, ?)'
        );

        foreach ($items as $item) {
            $consulta->execute([
                $pedidoId,
                $item['producto_id'],
                $item['cantidad'],
                $item['precio_unitario'],
            ]);
        }
    }
}
