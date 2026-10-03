<?php

declare(strict_types=1);

namespace Incancelables\Mail;

interface EmailSender
{
    /**
     * Transporta un email ya preparado. Devuelve true si la entrega fue
     * exitosa según el canal implementado (last_sent_at sólo se registra
     * en ese caso) y false si falló.
     */
    public function enviar(string $para, string $asunto, string $cuerpo): bool;
}
