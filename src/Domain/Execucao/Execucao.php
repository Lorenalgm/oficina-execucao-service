<?php

namespace Domain\Execucao;

use DateTimeImmutable;

class Execucao
{
    /**
     * @param list<array{status: string, em: string}> $linhaDoTempo
     */
    public function __construct(
        public readonly string $id,
        public readonly int $osId,
        public readonly string $filial,
        public readonly array $veiculo,
        public readonly array $servicos,
        private StatusExecucao $status,
        private array $linhaDoTempo,
    ) {}

    public static function enfileirar(string $sagaId, int $osId, string $filial, array $veiculo, array $servicos, DateTimeImmutable $em): self
    {
        return new self($sagaId, $osId, $filial, $veiculo, $servicos, StatusExecucao::NaFila, [self::marco(StatusExecucao::NaFila, $em)]);
    }

    public static function rejeitar(string $sagaId, int $osId, string $filial, array $veiculo, array $servicos, DateTimeImmutable $em): self
    {
        return new self($sagaId, $osId, $filial, $veiculo, $servicos, StatusExecucao::Rejeitada, [self::marco(StatusExecucao::Rejeitada, $em)]);
    }

    public static function cabeNaFilial(int $ativasNaFilial, int $capacidade): bool
    {
        return $ativasNaFilial < $capacidade;
    }

    public function status(): StatusExecucao
    {
        return $this->status;
    }

    /** @return list<array{status: string, em: string}> */
    public function linhaDoTempo(): array
    {
        return $this->linhaDoTempo;
    }

    public function avancarPara(StatusExecucao $novo, DateTimeImmutable $em): StatusExecucao
    {
        if ($this->status->proximo() !== $novo) {
            throw new TransicaoInvalida("Execução {$this->status->value} não pode ir para {$novo->value}.");
        }

        $anterior = $this->status;
        $this->status = $novo;
        $this->linhaDoTempo[] = self::marco($novo, $em);

        return $anterior;
    }

    private static function marco(StatusExecucao $status, DateTimeImmutable $em): array
    {
        return ['status' => $status->value, 'em' => $em->format(DATE_ATOM)];
    }
}
