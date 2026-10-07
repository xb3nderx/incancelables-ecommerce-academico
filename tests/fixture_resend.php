<?php

declare(strict_types=1);

$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$ruta = is_string($ruta) ? $ruta : '/';

$cabeceras = [];

if (function_exists('getallheaders')) {
    foreach (getallheaders() as $nombre => $valor) {
        $cabeceras[strtolower((string) $nombre)] = (string) $valor;
    }
}

foreach ($_SERVER as $clave => $valor) {
    if (!str_starts_with((string) $clave, 'HTTP_')) {
        continue;
    }

    $nombre = strtolower(str_replace('_', '-', substr((string) $clave, 5)));

    if (!isset($cabeceras[$nombre])) {
        $cabeceras[$nombre] = (string) $valor;
    }
}

if (isset($_SERVER['CONTENT_TYPE']) && !isset($cabeceras['content-type'])) {
    $cabeceras['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
}

file_put_contents(
    sys_get_temp_dir() . '/incancelables_fixture_resend.json',
    json_encode([
        'metodo' => $_SERVER['REQUEST_METHOD'] ?? '',
        'ruta' => $ruta,
        'cabeceras' => $cabeceras,
        'cuerpo' => (string) file_get_contents('php://input'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

$respuestas = [
    '/ok' => [200, '{"id":"9d1b4f8e-1111-4000-8000-000000000001"}'],
    '/sin-id' => [200, '{}'],
    '/id-vacio' => [200, '{"id":""}'],
    '/json-invalido' => [200, 'no es json'],
    '/400' => [400, '{"name":"validation_error","message":"The body of the request is invalid."}'],
    '/401' => [401, '{"name":"missing_api_key","message":"Missing API key in the authorization header."}'],
    '/403' => [403, '{"name":"restricted_api_key","message":"API key is not active"}'],
    '/429' => [429, '{"name":"rate_limit_exceeded","message":"Too many requests."}'],
    '/500' => [500, '{"name":"application_error","message":"An unexpected error occurred."}'],
    '/503' => [503, '{"name":"service_unavailable","message":"API is temporarily unavailable"}'],
];

if ($ruta === '/ping') {
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'ok';

    return true;
}

if ($ruta === '/lento') {
    sleep(3);
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{"id":"demasiado-tarde"}';

    return true;
}

[$codigo, $cuerpoRespuesta] = $respuestas[$ruta] ?? [404, '{"name":"not_found","message":"The requested endpoint does not exist."}'];

http_response_code($codigo);
header('Content-Type: application/json');
echo $cuerpoRespuesta;

return true;
