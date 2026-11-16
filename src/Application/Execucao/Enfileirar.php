<?php

namespace Application\Execucao;

use DateTimeImmutable;
use Domain\Execucao\Execucao;
use Domain\Execucao\ExecucaoRepository;
use Domain\Shared\Messaging\Envelope;
use Domain\Shared\Messaging\MessageHandler;

class Enfileirar implements MessageHandler
{
    public function __construct(
        private ExecucaoRepository $execucoes,
        private int $capacidadePorFilial,
    ) {}

    public function tratar(Envelope $comando): void
    {
        $filial = strtoupper($comando->payload['filial'] ?? 'MATRIZ');
        $veiculo = $comando->payload['veiculo'] ?? [];
        $servicos = $comando->payload['servicos'] ?? [];
        $ativas = $this->execucoes->contarAtivas($filial);
        $agora = new DateTimeImmutable();

        if (Execucao::cabeNaFilial($ativas, $this->capacidadePorFilial)) {
            $execucao = Execucao::enfileirar($comando->sagaId, $comando->osId, $filial, $veiculo, $servicos, $agora);
            $evento = $this->evento($comando, 'execucao.evt.enfileirada', ['execucao_id' => $execucao->id, 'posicao_na_fila' => $ativas + 1]);
        } else {
            $execucao = Execucao::rejeitar($comando->sagaId, $comando->osId, $filial, $veiculo, $servicos, $agora);
            $evento = $this->evento($comando, 'execucao.evt.rejeitada', ['motivo' => 'capacidade_esgotada']);
        }

        $this->execucoes->registrarNova($execucao, $evento);
    }

    private function evento(Envelope $comando, string $type, array $payload): Envelope
    {
        return Envelope::criar($type, $comando->sagaId, $comando->correlationId, $comando->osId, $payload);
    }
}
