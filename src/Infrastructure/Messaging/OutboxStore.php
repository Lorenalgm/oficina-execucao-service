<?php

namespace Infrastructure\Messaging;

interface OutboxStore
{
    /** @return list<array{documento: string, message_id: string, corpo: string}> */
    public function pendentes(int $limite): array;

    public function marcarPublicada(string $documento, string $messageId): void;
}
