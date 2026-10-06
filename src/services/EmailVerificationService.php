<?php

declare(strict_types=1);

namespace Incancelables\Services;

use Incancelables\Repositories\EmailVerificationRepository;

final class EmailVerificationService
{
    private const DIAS_EXPIRACION = 7;
    private const COOLDOWN_MINUTOS = 15;
    private const LONGITUD_TOKEN_BYTES = 32;

    public function __construct(private EmailVerificationRepository $emailVerificationRepository)
    {
    }

    public function iniciar(string $email): array
    {
        $token = bin2hex(random_bytes(self::LONGITUD_TOKEN_BYTES));

        $emailVerificacionId = $this->emailVerificationRepository->crear(
            $email,
            hash('sha256', $token),
            self::DIAS_EXPIRACION,
        );

        return [
            'email_verificacion_id' => $emailVerificacionId,
            'token' => $token,
        ];
    }

    public function reenviar(int $emailVerificacionId): array
    {
        $registro = $this->emailVerificationRepository->buscarPorId($emailVerificacionId);

        if ($registro === null) {
            return ['resultado' => 'no_encontrada'];
        }

        if ($registro['verificado']) {
            return ['resultado' => 'ya_verificado'];
        }

        $cooldownSegundos = self::COOLDOWN_MINUTOS * 60;
        $desdeUltimoEnvio = $registro['segundos_desde_ultimo_envio'];

        if ($desdeUltimoEnvio !== null && $desdeUltimoEnvio < $cooldownSegundos) {
            return [
                'resultado' => 'cooldown',
                'segundos_restantes' => $cooldownSegundos - $desdeUltimoEnvio,
            ];
        }

        $token = bin2hex(random_bytes(self::LONGITUD_TOKEN_BYTES));

        $this->emailVerificationRepository->regenerarToken(
            $emailVerificacionId,
            hash('sha256', $token),
            self::DIAS_EXPIRACION,
        );

        return [
            'resultado' => 'token_generado',
            'email_verificacion_id' => $emailVerificacionId,
            'token' => $token,
        ];
    }

    public function validar(string $token): array
    {
        $registro = $this->emailVerificationRepository->buscarPorTokenHash(hash('sha256', $token));

        if ($registro === null) {
            return ['resultado' => 'token_invalido'];
        }

        if ($registro['verificado']) {
            return [
                'resultado' => 'confirmado',
                'email_verificacion_id' => $registro['id'],
            ];
        }

        if ($registro['expirado']) {
            return ['resultado' => 'token_expirado'];
        }

        $this->emailVerificationRepository->marcarVerificada($registro['id']);

        return [
            'resultado' => 'confirmado',
            'email_verificacion_id' => $registro['id'],
        ];
    }

    /**
     * Consulta SOLO LECTURA del registro de verificación a partir del token.
     * No marca ni modifica nada: existe para autorizar la continuación de la
     * compra (Pedido + PedidoItem como fuente de verdad) sin efectos secundarios.
     */
    public function consultarPorToken(string $token): ?array
    {
        return $this->emailVerificationRepository->buscarPorTokenHash(hash('sha256', $token));
    }

    public function registrarEnvio(int $emailVerificacionId): void
    {
        $this->emailVerificationRepository->registrarEnvio($emailVerificacionId);
    }
}
