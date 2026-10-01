<?php

declare(strict_types=1);

namespace Incancelables\Repositories;

use PDO;

final class PagoRepository
{
    public function __construct(private PDO $conexion)
    {
    }

    public function idDeEstadoValidacion(string $codigo): int
    {
        $consulta = $this->conexion->prepare(
            'SELECT id
               FROM validaciones_pagos_estados
              WHERE codigo = ?'
        );
        $consulta->execute([$codigo]);
        $fila = $consulta->fetch();

        if ($fila === false) {
            throw new \RuntimeException('Estado de validación de pago inexistente: ' . $codigo);
        }

        return (int) $fila['id'];
    }

    public function buscarProveedor(string $idOcodigo): ?array
    {
        if (ctype_digit($idOcodigo)) {
            $consulta = $this->conexion->prepare(
                'SELECT id, codigo
                   FROM proveedores
                  WHERE id = ?'
            );
            $consulta->execute([(int) $idOcodigo]);
        } else {
            $consulta = $this->conexion->prepare(
                'SELECT id, codigo
                   FROM proveedores
                  WHERE codigo = ?'
            );
            $consulta->execute([$idOcodigo]);
        }

        $fila = $consulta->fetch();

        if ($fila === false) {
            return null;
        }

        return ['id' => (int) $fila['id'], 'codigo' => (string) $fila['codigo']];
    }

    public function insertarPago(int $pedidoId, float $importe): int
    {
        $consulta = $this->conexion->prepare(
            'INSERT INTO pagos (pedido_id, importe)
             VALUES (?, ?)'
        );
        $consulta->execute([$pedidoId, $importe]);

        return (int) $this->conexion->lastInsertId();
    }

    public function insertarValidacionPago(int $pagoId, int $estadoId, int $proveedorId, float $importe): int
    {
        $consulta = $this->conexion->prepare(
            'INSERT INTO validaciones_pagos (pago_id, estado_id, proveedor_id, importe)
             VALUES (?, ?, ?, ?)'
        );
        $consulta->execute([$pagoId, $estadoId, $proveedorId, $importe]);

        return (int) $this->conexion->lastInsertId();
    }

    public function buscarValidacionPorPago(int $pagoId): ?array
    {
        $consulta = $this->conexion->prepare(
            'SELECT v.id,
                    v.pago_id,
                    e.codigo AS estado,
                    v.proveedor_id,
                    v.referencia_proveedor,
                    v.importe,
                    v.created_at
               FROM validaciones_pagos v
               INNER JOIN validaciones_pagos_estados e ON e.id = v.estado_id
              WHERE v.pago_id = ?'
        );
        $consulta->execute([$pagoId]);
        $fila = $consulta->fetch();

        if ($fila === false) {
            return null;
        }

        return $fila;
    }

    public function actualizarValidacionPago(int $pagoId, int $estadoId, ?string $referenciaProveedor, float $importe): void
    {
        $consulta = $this->conexion->prepare(
            'UPDATE validaciones_pagos
                SET estado_id = ?,
                    referencia_proveedor = ?,
                    importe = ?
              WHERE pago_id = ?'
        );
        $consulta->execute([$estadoId, $referenciaProveedor, $importe, $pagoId]);
    }

    public function contarIntentos(int $pedidoId): int
    {
        $consulta = $this->conexion->prepare(
            'SELECT COUNT(*)
               FROM pagos
              WHERE pedido_id = ?'
        );
        $consulta->execute([$pedidoId]);

        return (int) $consulta->fetchColumn();
    }

    public function pedidoTieneValidacionAprobada(int $pedidoId): bool
    {
        $consulta = $this->conexion->prepare(
            'SELECT COUNT(*)
               FROM validaciones_pagos v
               INNER JOIN pagos p ON p.id = v.pago_id
               INNER JOIN validaciones_pagos_estados e ON e.id = v.estado_id
              WHERE p.pedido_id = ?
                AND e.codigo = \'APROBADO\''
        );
        $consulta->execute([$pedidoId]);

        return (int) $consulta->fetchColumn() > 0;
    }

    public function insertarCasoRevision(int $pagoId, string $tipoCodigo): void
    {
        $consulta = $this->conexion->prepare(
            'SELECT COUNT(*)
               FROM casos_revision c
               INNER JOIN casos_revision_tipos t ON t.id = c.tipo_id
              WHERE c.pago_id = ?
                AND t.codigo = ?'
        );
        $consulta->execute([$pagoId, $tipoCodigo]);

        if ((int) $consulta->fetchColumn() > 0) {
            return;
        }

        $insercion = $this->conexion->prepare(
            'INSERT INTO casos_revision (pago_id, tipo_id, estado_id)
             SELECT ?, t.id, e.id
               FROM casos_revision_tipos t
              CROSS JOIN casos_revision_estados e
              WHERE t.codigo = ?
                AND e.codigo = \'PENDIENTE\''
        );
        $insercion->execute([$pagoId, $tipoCodigo]);
    }

    public function resolverCasosRevision(int $pagoId, string $tipoCodigo): void
    {
        $consulta = $this->conexion->prepare(
            'UPDATE casos_revision c
                INNER JOIN casos_revision_tipos t ON t.id = c.tipo_id
                INNER JOIN casos_revision_estados e ON c.estado_id = e.id
                SET c.estado_id = (SELECT id FROM casos_revision_estados WHERE codigo = \'RESUELTO\')
              WHERE c.pago_id = ?
                AND t.codigo = ?
                AND e.codigo = \'PENDIENTE\''
        );
        $consulta->execute([$pagoId, $tipoCodigo]);
    }

    public function buscarCasosRevision(int $pagoId): array
    {
        $consulta = $this->conexion->prepare(
            'SELECT c.id,
                    t.codigo AS tipo,
                    e.codigo AS estado,
                    c.created_at,
                    c.updated_at
               FROM casos_revision c
               INNER JOIN casos_revision_tipos t ON t.id = c.tipo_id
               INNER JOIN casos_revision_estados e ON e.id = c.estado_id
              WHERE c.pago_id = ?
              ORDER BY c.id'
        );
        $consulta->execute([$pagoId]);

        return $consulta->fetchAll();
    }
}
