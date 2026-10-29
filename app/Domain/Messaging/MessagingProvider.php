<?php

namespace App\Domain\Messaging;

/**
 * Aísla el canal de mensajería (especificación §6.1). La lógica del bot y de la bandeja
 * no conoce la API concreta: hoy WhatsApp Cloud API o el modo simulado.
 */
interface MessagingProvider
{
    /** simulado | cloud */
    public function mode(): string;

    /** Texto libre: solo dentro de la ventana de 24 h (lo controla ConversationService). */
    public function sendText(string $to, string $body): SendResult;

    /** Plantilla aprobada por Meta; @param list<string> $params variables del cuerpo. */
    public function sendTemplate(string $to, string $name, string $language, array $params = []): SendResult;
}
