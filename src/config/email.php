<?php

require_once __DIR__ . '/env.php';

cargarVariablesDeEntorno(dirname(__DIR__, 2) . '/.env');

$canalEntorno = getenv('EMAIL_CHANNEL');
$apiKeyEntorno = getenv('RESEND_API_KEY');
$fromEntorno = getenv('RESEND_FROM');

$canalDefinido = is_string($canalEntorno) && trim($canalEntorno) !== '';
$apiKeyDefinida = is_string($apiKeyEntorno) && trim($apiKeyEntorno) !== '';
$fromDefinido = is_string($fromEntorno) && trim($fromEntorno) !== '';

$configuracion = [
    'channel' => $canalDefinido ? trim($canalEntorno) : 'log',

    'resend' => [
        'endpoint' => 'https://api.resend.com/emails',
        'timeout' => 10,
        'api_key' => $apiKeyDefinida ? $apiKeyEntorno : '',
        'from' => $fromDefinido ? $fromEntorno : '',
    ],
];

return $configuracion;
