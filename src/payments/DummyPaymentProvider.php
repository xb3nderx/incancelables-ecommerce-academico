<?php

declare(strict_types=1);

namespace Incancelables\Payments;

final class DummyPaymentProvider implements PaymentProvider
{
    private const RESULTADOS = ['APROBADO', 'RECHAZADO', 'EN_PROCESO'];

    public function codigo(): string
    {
        return 'DUMMY';
    }

    public function procesarIntento(int $pagoId, float $importe, ?string $resultadoSimulado = null): array
    {
        $estado = $resultadoSimulado ?? 'APROBADO';

        if (!in_array($estado, self::RESULTADOS, true)) {
            throw new PaymentProviderException('Resultado de simulación inválido: ' . $estado);
        }

        return [
            'estado' => $estado,
            'importe' => $importe,
            'referencia_proveedor' => 'DUMMY-' . $pagoId,
        ];
    }
}
