<?php

declare(strict_types=1);

namespace Incancelables\Payments;

interface PaymentProvider
{
    public function codigo(): string;

    /**
     * Procesa un intento de pago y devuelve el resultado normalizado del adaptador:
     *
     * [
     *     'estado' => 'APROBADO' | 'RECHAZADO' | 'EN_PROCESO',
     *     'importe' => float,
     *     'referencia_proveedor' => string,
     * ]
     *
     * El frontend no determina la aprobación: el resultado siempre proviene
     * del proveedor y es normalizado por PaymentValidationService.
     */
    public function procesarIntento(int $pagoId, float $importe, ?string $resultadoSimulado = null): array;
}
