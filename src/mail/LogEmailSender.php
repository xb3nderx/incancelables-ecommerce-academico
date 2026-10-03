<?php

declare(strict_types=1);

namespace Incancelables\Mail;

final class LogEmailSender implements EmailSender
{
    public function enviar(string $para, string $asunto, string $cuerpo): bool
    {
        error_log('[incancelables] email log — para: ' . $para . ' | asunto: ' . $asunto);
        error_log('[incancelables] email log — cuerpo: ' . $cuerpo);

        return true;
    }
}
