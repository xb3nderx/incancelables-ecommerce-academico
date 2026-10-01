<?php

declare(strict_types=1);

namespace Incancelables\Models;

final class PaymentValidationResult
{
    public function __construct(
        public readonly int $id,
        public readonly int $pagoId,
        public readonly string $estado,
        public readonly int $proveedorId,
        public readonly ?string $referenciaProveedor,
        public readonly float $importe,
        public readonly string $createdAt,
    ) {
    }

    public static function desdeFila(array $fila): self
    {
        return new self(
            (int) $fila['id'],
            (int) $fila['pago_id'],
            (string) $fila['estado'],
            (int) $fila['proveedor_id'],
            $fila['referencia_proveedor'] === null ? null : (string) $fila['referencia_proveedor'],
            (float) $fila['importe'],
            (string) $fila['created_at'],
        );
    }

    public function esDefinitivo(): bool
    {
        return in_array($this->estado, ['APROBADO', 'RECHAZADO', 'CANCELADO'], true);
    }

    public function estaAprobado(): bool
    {
        return $this->estado === 'APROBADO';
    }
}
