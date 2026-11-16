<?php

namespace Infrastructure\Messaging;

use Domain\Shared\Messaging\Envelope;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutboxRelay
{
    public function __construct(
        private Broker $broker,
        private OutboxStore $outbox,
        private string $exchange,
    ) {}

    public function publicarPendentes(int $lote): int
    {
        $publicadas = 0;

        foreach ($this->outbox->pendentes($lote) as $pendente) {
            try {
                $envelope = Envelope::deJson($pendente['corpo']);
                $this->broker->publicar($this->exchange, $envelope->type, $pendente['corpo'], $envelope->cabecalhos() + ['message_id' => $envelope->messageId]);
            } catch (Throwable $e) {
                Log::error('Falha ao publicar mensagem da outbox', [
                    'event_type' => 'mensageria.outbox_falhou',
                    'message_id' => $pendente['message_id'],
                    'erro' => $e->getMessage(),
                ]);
                break;
            }

            $this->outbox->marcarPublicada($pendente['documento'], $pendente['message_id']);
            $publicadas++;
        }

        return $publicadas;
    }
}
