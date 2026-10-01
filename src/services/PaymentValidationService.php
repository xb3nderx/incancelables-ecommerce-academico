<?php

declare(strict_types=1);

namespace Incancelables\Services;

use Incancelables\Models\PaymentValidationResult;
use Incancelables\Payments\PaymentProvider;
use Incancelables\Payments\PaymentProviderException;
use Incancelables\Repositories\PagoRepository;

/**
 * Frontera del backend con el proveedor de pagos: es el único componente
 * autorizado a interactuar con el adaptador (PaymentProvider), normaliza los
 * resultados del proveedor y los persiste en el PaymentValidationResult
 * (validaciones_pagos). No decide el estado comercial del Pedido: esa regla
 * es de PedidoService.
 */
final class PaymentValidationService
{
    private const RESULTADOS_NORMALIZABLES = ['APROBADO', 'RECHAZADO', 'EN_PROCESO'];

    public function __construct(
        private PagoRepository $pagoRepository,
        private PaymentProvider $paymentProvider,
    ) {
    }

    /**
     * Crea el Pago y su único PaymentValidationResult en estado no definitivo
     * (EN_PROCESO) ANTES de interactuar con el proveedor, de modo que el
     * intento quede registrado aunque la interacción posterior falle.
     */
    public function registrarIntento(int $pedidoId, float $importe, int $proveedorId): int
    {
        $pagoId = $this->pagoRepository->insertarPago($pedidoId, $importe);
        $this->pagoRepository->insertarValidacionPago(
            $pagoId,
            $this->pagoRepository->idDeEstadoValidacion('EN_PROCESO'),
            $proveedorId,
            $importe,
        );

        return $pagoId;
    }

    /**
     * Consulta/valida contra el proveedor, normaliza el resultado y lo
     * registra sobre el PaymentValidationResult existente (1:1, jamás se crea
     * un segundo resultado para el mismo Pago). Un resultado ya definitivo no
     * se sobrescribe (idempotencia). Un resultado desconocido del proveedor
     * se normaliza como EN_PROCESO: nunca se convierte automáticamente en
     * RECHAZADO.
     */
    public function procesarIntento(int $pagoId, float $importe, ?string $resultadoSimulado = null): PaymentValidationResult
    {
        $actual = $this->pagoRepository->buscarValidacionPorPago($pagoId);

        if ($actual === null) {
            throw new \RuntimeException('Validación de pago inexistente para el pago: ' . $pagoId);
        }

        $resultadoActual = PaymentValidationResult::desdeFila($actual);

        if ($resultadoActual->esDefinitivo()) {
            return $resultadoActual;
        }

        try {
            $respuesta = $this->paymentProvider->procesarIntento($pagoId, $importe, $resultadoSimulado);
        } catch (\Throwable $error) {
            throw new PaymentProviderException(
                'El proveedor de pagos no pudo procesar el intento: ' . $error->getMessage(),
                0,
                $error
            );
        }

        $estado = $respuesta['estado'] ?? null;

        if (!is_string($estado) || !in_array($estado, self::RESULTADOS_NORMALIZABLES, true)) {
            $estado = 'EN_PROCESO';
        }

        $referencia = $respuesta['referencia_proveedor'] ?? null;
        $importeInformado = $respuesta['importe'] ?? null;

        $this->pagoRepository->actualizarValidacionPago(
            $pagoId,
            $this->pagoRepository->idDeEstadoValidacion($estado),
            is_string($referencia) ? $referencia : null,
            is_numeric($importeInformado) ? (float) $importeInformado : $importe,
        );

        $fila = $this->pagoRepository->buscarValidacionPorPago($pagoId);

        return PaymentValidationResult::desdeFila($fila);
    }
}
