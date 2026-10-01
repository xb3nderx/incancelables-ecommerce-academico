<?php

declare(strict_types=1);

function aplicarCors(array $cors, string $metodo, string $origen): void
{
    $origenes = $cors['origenes'][$cors['entorno']] ?? [];

    if ($origen === '' || !in_array($origen, $origenes, true)) {
        return;
    }

    header('Access-Control-Allow-Origin: ' . $origen);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: ' . implode(', ', $cors['metodos']));
    header('Access-Control-Allow-Headers: ' . implode(', ', $cors['headers']));
    header('Access-Control-Max-Age: ' . (string) $cors['max_age']);

    if (!empty($cors['permitir_credenciales'])) {
        header('Access-Control-Allow-Credentials: true');
    }

    if ($metodo === 'OPTIONS') {
        header_remove('Content-Type');
        http_response_code(204);
        exit;
    }
}
