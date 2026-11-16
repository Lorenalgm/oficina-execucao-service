<?php

namespace Application\Execucao;

use DateTimeImmutable;
use Domain\Execucao\Execucao;
use Domain\Execucao\ExecucaoRepository;
use Domain\Execucao\StatusExecucao;
use Domain\Shared\Messaging\Envelope;

class AvancarEtapa
{
    public function __construct(private ExecucaoRepository $execucoes) {}

    public function executar(int $osId, StatusExecucao $novo, string $correlationId): ?Execucao
    {
        $execucao = $this->execucoes->buscarPorOs($osId);
        if ($execucao === null) {
            return null;
        }

        $agora = new DateTimeImmutable();
        $anterior = $execucao->avancarPara($novo, $agora);

        $eventos = [$this->evento($execucao, $correlationId, 'execucao.evt.status-alterado', [
            'execucao_id' => $execucao->id,
            'status_anterior' => $anterior->value,
            'status' => $novo->value,
            'ocorrido_em' => $agora->format(DATE_ATOM),
        ])];
        if ($novo === StatusExecucao::Finalizada) {
            $eventos[] = $this->evento($execucao, $correlationId, 'execucao.evt.finalizada', [
                'execucao_id' => $execucao->id,
                'finalizada_em' => $agora->format(DATE_ATOM),
            ]);
        }

        $this->execucoes->salvarComEventos($execucao, ...$eventos);

        return $execucao;
    }

    private function evento(Execucao $execucao, string $correlationId, string $type, array $payload): Envelope
    {
        return Envelope::criar($type, $execucao->id, $correlationId, $execucao->osId, $payload);
    }
}
