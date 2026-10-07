<?php

declare(strict_types=1);

namespace Incancelables\Mail;

final class ResendEmailSender implements EmailSender
{
    private const USER_AGENT = 'Incancelables-ECommerce/1.0';

    public function __construct(
        private string $apiKey,
        private string $from,
        private string $endpoint,
        private int $timeout,
    ) {
    }

    public function enviar(string $para, string $asunto, string $cuerpo): bool
    {
        $datos = [
            'from' => $this->from,
            'to' => $para,
            'subject' => $asunto,
            'text' => $cuerpo,
        ];

        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $this->registrarFallo('json_de_solicitud');

            return false;
        }

        $curl = curl_init();

        if ($curl === false) {
            $this->registrarFallo('curl_no_disponible');

            return false;
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'User-Agent: ' . self::USER_AGENT,
            ],
        ]);

        $respuesta = curl_exec($curl);

        if ($respuesta === false) {
            $errno = (int) curl_errno($curl);
            curl_close($curl);
            $this->registrarFallo('transporte', null, $errno);

            return false;
        }

        $estado = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($estado < 200 || $estado >= 300) {
            $this->registrarFallo('respuesta_http', $estado);

            return false;
        }

        $datosRespuesta = json_decode((string) $respuesta, true);

        if (!is_array($datosRespuesta)) {
            $this->registrarFallo('json_de_respuesta', $estado);

            return false;
        }

        $id = $datosRespuesta['id'] ?? null;

        if (!is_string($id) || $id === '') {
            $this->registrarFallo('respuesta_sin_id', $estado);

            return false;
        }

        return true;
    }

    private function registrarFallo(string $categoria, ?int $estado = null, ?int $errno = null): void
    {
        $detalle = [$categoria];

        if ($errno !== null) {
            $detalle[] = 'errno ' . $errno;
        }

        if ($estado !== null) {
            $detalle[] = 'status ' . $estado;
        }

        error_log('[incancelables] email resend — ' . implode(' | ', $detalle));
    }
}
