<?php

namespace Domain\Execucao;

use Domain\Shared\Messaging\Envelope;

interface ExecucaoRepository
{
    /** Grava a execução e o evento de resposta; false se o comando da Saga já tinha sido tratado. */
    public function registrarNova(Execucao $execucao, Envelope $evento): bool;

    public function salvarComEvento(Execucao $execucao, Envelope $evento): void;

    public function buscarPorOs(int $osId): ?Execucao;

    public function contarAtivas(string $filial): int;

    /** @return list<Execucao> */
    public function fila(?string $filial): array;
}
