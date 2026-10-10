<?php

declare(strict_types=1);

if (!function_exists('cargarVariablesDeEntorno')) {
    function cargarVariablesDeEntorno(?string $ruta = null): array
    {
        $ruta ??= dirname(__DIR__, 2) . '/.env';

        if (!is_string($ruta) || $ruta === '' || !is_file($ruta) || !is_readable($ruta)) {
            return [];
        }

        $contenido = file_get_contents($ruta);

        if ($contenido === false) {
            return [];
        }

        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        }

        $cargadas = [];

        foreach (preg_split('/\R/', $contenido) ?: [] as $linea) {
            $linea = trim($linea);

            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }

            [$nombre, $valor] = explode('=', $linea, 2);
            $nombre = trim($nombre);
            $valor = trim($valor);

            if ($nombre === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $nombre)) {
                continue;
            }

            if (strlen($valor) >= 2) {
                $comillaInicial = $valor[0];
                $comillaFinal = $valor[strlen($valor) - 1];

                if (($comillaInicial === '"' && $comillaFinal === '"')
                    || ($comillaInicial === "'" && $comillaFinal === "'")) {
                    $valor = substr($valor, 1, -1);
                }
            }

            if (getenv($nombre) !== false) {
                continue;
            }

            putenv($nombre . '=' . $valor);
            $_ENV[$nombre] = $valor;

            $cargadas[] = $nombre;
        }

        return $cargadas;
    }
}
