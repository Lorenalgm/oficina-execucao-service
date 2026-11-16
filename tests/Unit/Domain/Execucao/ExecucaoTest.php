<?php

namespace Tests\Unit\Domain\Execucao;

use DateTimeImmutable;
use Domain\Execucao\Execucao;
use Domain\Execucao\StatusExecucao;
use Domain\Execucao\TransicaoInvalida;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExecucaoTest extends TestCase
{
    private function nova(): Execucao
    {
        return Execucao::enfileirar('saga-1', 42, 'MATRIZ', ['placa' => 'ABC1D23'], [['descricao' => 'Troca de óleo']], new DateTimeImmutable('2026-11-16T10:00:00Z'));
    }

    public function test_enfileirada_comeca_na_fila_com_marco_na_linha_do_tempo(): void
    {
        $execucao = $this->nova();

        $this->assertSame(StatusExecucao::NaFila, $execucao->status());
        $this->assertSame([['status' => 'na_fila', 'em' => '2026-11-16T10:00:00+00:00']], $execucao->linhaDoTempo());
        $this->assertSame('saga-1', $execucao->id);
    }

    public function test_avanca_um_passo_por_vez_ate_finalizar(): void
    {
        $execucao = $this->nova();
        $em = new DateTimeImmutable('2026-11-16T11:00:00Z');

        $this->assertSame(StatusExecucao::NaFila, $execucao->avancarPara(StatusExecucao::EmDiagnostico, $em));
        $execucao->avancarPara(StatusExecucao::EmReparo, $em);
        $execucao->avancarPara(StatusExecucao::Finalizada, $em);

        $this->assertSame(StatusExecucao::Finalizada, $execucao->status());
        $this->assertSame(['na_fila', 'em_diagnostico', 'em_reparo', 'finalizada'], array_column($execucao->linhaDoTempo(), 'status'));
    }

    public static function saltos(): array
    {
        return [
            'pular o diagnóstico' => [StatusExecucao::EmReparo],
            'finalizar direto' => [StatusExecucao::Finalizada],
            'voltar para a fila' => [StatusExecucao::NaFila],
        ];
    }

    #[DataProvider('saltos')]
    public function test_nao_pula_etapas(StatusExecucao $destino): void
    {
        $this->expectException(TransicaoInvalida::class);

        $this->nova()->avancarPara($destino, new DateTimeImmutable());
    }

    public function test_rejeitada_nao_avanca(): void
    {
        $execucao = Execucao::rejeitar('saga-1', 42, 'MATRIZ', [], [], new DateTimeImmutable());

        $this->assertSame(StatusExecucao::Rejeitada, $execucao->status());
        $this->expectException(TransicaoInvalida::class);

        $execucao->avancarPara(StatusExecucao::EmDiagnostico, new DateTimeImmutable());
    }

    public function test_capacidade_da_filial(): void
    {
        $this->assertTrue(Execucao::cabeNaFilial(2, 3));
        $this->assertFalse(Execucao::cabeNaFilial(3, 3));
    }

    public function test_status_ativos(): void
    {
        $this->assertSame([StatusExecucao::NaFila, StatusExecucao::EmDiagnostico, StatusExecucao::EmReparo], StatusExecucao::ativos());
    }
}
