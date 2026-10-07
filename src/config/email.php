<?php

$configuracion = [
    'channel' => 'log',

    'resend' => [
        'endpoint' => 'https://api.resend.com/emails',
        'timeout' => 10,
        'api_key' => '',
        'from' => '',
    ],
];

$archivoLocal = __DIR__ . '/email.local.php';

if (is_file($archivoLocal)) {
    $local = require $archivoLocal;

    if (is_array($local)) {
        $canalLocal = $local['channel'] ?? null;

        if (is_string($canalLocal) && trim($canalLocal) !== '') {
            $configuracion['channel'] = $canalLocal;
        }

        $resendLocal = $local['resend'] ?? null;

        if (is_array($resendLocal)) {
            foreach (['api_key', 'from'] as $clave) {
                $valor = $resendLocal[$clave] ?? null;

                if (is_string($valor) && trim($valor) !== '') {
                    $configuracion['resend'][$clave] = $valor;
                }
            }
        }
    }
}

return $configuracion;
