<?php

declare(strict_types=1);

namespace Incancelables\Repositories;

use PDO;

final class EmailVerificationRepository
{
    public function __construct(private PDO $conexion)
    {
    }

    public function crear(string $email, string $tokenHash, int $diasExpiracion): int
    {
        $consulta = $this->conexion->prepare(
            sprintf(
                'INSERT INTO emails_verificaciones (email, token_hash, expires_at, last_sent_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL %d DAY), NULL)',
                $diasExpiracion
            )
        );
        $consulta->execute([$email, $tokenHash]);

        return (int) $this->conexion->lastInsertId();
    }

    public function buscarPorTokenHash(string $tokenHash): ?array
    {
        return $this->buscar('token_hash = ?', [$tokenHash]);
    }

    public function buscarPorId(int $emailVerificacionId): ?array
    {
        return $this->buscar('id = ?', [$emailVerificacionId]);
    }

    public function regenerarToken(int $emailVerificacionId, string $tokenHash, int $diasExpiracion): void
    {
        $consulta = $this->conexion->prepare(
            sprintf(
                'UPDATE emails_verificaciones
                    SET token_hash = ?,
                        expires_at = CASE
                            WHEN expires_at < NOW() THEN DATE_ADD(NOW(), INTERVAL %d DAY)
                            ELSE expires_at
                        END
                  WHERE id = ?',
                $diasExpiracion
            )
        );
        $consulta->execute([$tokenHash, $emailVerificacionId]);
    }

    public function marcarVerificada(int $emailVerificacionId): void
    {
        $consulta = $this->conexion->prepare(
            'UPDATE emails_verificaciones
                SET verified_at = NOW()
              WHERE id = ?
                AND verified_at IS NULL'
        );
        $consulta->execute([$emailVerificacionId]);
    }

    public function registrarEnvio(int $emailVerificacionId): void
    {
        $consulta = $this->conexion->prepare(
            'UPDATE emails_verificaciones
                SET last_sent_at = NOW()
              WHERE id = ?'
        );
        $consulta->execute([$emailVerificacionId]);
    }

    private function buscar(string $condicion, array $parametros): ?array
    {
        $consulta = $this->conexion->prepare(
            'SELECT id,
                    email,
                    token_hash,
                    (expires_at < NOW()) AS expirado,
                    (verified_at IS NOT NULL) AS verificado,
                    TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS segundos_desde_ultimo_envio
               FROM emails_verificaciones
              WHERE ' . $condicion
        );
        $consulta->execute($parametros);
        $fila = $consulta->fetch();

        if ($fila === false) {
            return null;
        }

        return [
            'id' => (int) $fila['id'],
            'email' => $fila['email'],
            'token_hash' => $fila['token_hash'],
            'expirado' => (int) $fila['expirado'] === 1,
            'verificado' => (int) $fila['verificado'] === 1,
            'segundos_desde_ultimo_envio' => $fila['segundos_desde_ultimo_envio'] === null
                ? null
                : (int) $fila['segundos_desde_ultimo_envio'],
        ];
    }
}
