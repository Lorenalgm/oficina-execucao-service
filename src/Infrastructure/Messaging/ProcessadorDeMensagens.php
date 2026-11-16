<?php

namespace Infrastructure\Messaging;

use App\Support\CorrelationContext;
use Domain\Shared\Messaging\Envelope;
use Domain\Shared\Messaging\MensagemInvalida;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessadorDeMensagens
{
    public function __construct(
        private HandlerRegistry $handlers,
        private int $maxTentativas,
    ) {}

    public function processar(string $corpo, int $tentativas): Decisao
    {
        try {
            $envelope = Envelope::deJson($corpo);
        } catch (MensagemInvalida $e) {
            Log::warning('Mensagem inválida enviada para a DLQ', [
                'event_type' => 'mensageria.mensagem_invalida',
                'erro' => $e->getMessage(),
            ]);

            return Decisao::Rejeitar;
        }

        $contexto = [
            'message_id' => $envelope->messageId,
            'message_type' => $envelope->type,
            'saga_id' => $envelope->sagaId,
            'os_id' => $envelope->osId,
        ];

        $handler = $this->handlers->para($envelope->type);
        if ($handler === null) {
            Log::error('Tipo de mensagem sem handler enviado para a DLQ', $contexto + ['event_type' => 'mensageria.tipo_desconhecido']);

            return Decisao::Rejeitar;
        }

        CorrelationContext::set($envelope->correlationId);

        try {
            $handler->tratar($envelope);
        } catch (Throwable $e) {
            $tentativa = $tentativas + 1;
            $esgotou = $tentativa >= $this->maxTentativas;
            Log::log($esgotou ? 'error' : 'warning', 'Falha ao processar mensagem', $contexto + [
                'event_type' => $esgotou ? 'mensageria.enviada_dlq' : 'mensageria.retentativa',
                'tentativa' => $tentativa,
                'exception_class' => $e::class,
                'erro' => $e->getMessage(),
            ]);

            return $esgotou ? Decisao::Rejeitar : Decisao::Retentar;
        } finally {
            CorrelationContext::clear();
        }

        Log::info('Mensagem processada', $contexto + ['event_type' => 'mensageria.processada']);

        return Decisao::Confirmar;
    }
}
