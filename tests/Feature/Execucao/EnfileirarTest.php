<?php

namespace Tests\Feature\Execucao;

use Domain\Execucao\ExecucaoRepository;
use Domain\Execucao\StatusExecucao;
use Domain\Shared\Messaging\Envelope;
use Infrastructure\Messaging\Decisao;
use Infrastructure\Messaging\OutboxStore;
use Infrastructure\Messaging\ProcessadorDeMensagens;
use Tests\Concerns\UsaMongo;
use Tests\TestCase;

class EnfileirarTest extends TestCase
{
    use UsaMongo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conectarMongo();
        config(['mensageria.capacidade_por_filial' => 1]);
    }

    private function enfileirar(int $osId, string $filial = 'matriz', ?Envelope $comando = null): Envelope
    {
        $comando ??= Envelope::criar('execucao.cmd.enfileirar', Envelope::novoUuid(), 'corr-saga', $osId, [
            'filial' => $filial,
            'veiculo' => ['placa' => 'ABC1D23'],
            'servicos' => [['descricao' => 'Troca de óleo']],
        ]);

        $this->assertSame(Decisao::Confirmar, app(ProcessadorDeMensagens::class)->processar($comando->paraJson(), 0));

        return $comando;
    }

    /** @return list<Envelope> */
    private function eventos(): array
    {
        return array_map(fn ($p) => Envelope::deJson($p['corpo']), app(OutboxStore::class)->pendentes(50));
    }

    public function test_enfileira_e_responde_com_a_posicao(): void
    {
        $comando = $this->enfileirar(42);

        [$evento] = $this->eventos();
        $this->assertSame('execucao.evt.enfileirada', $evento->type);
        $this->assertSame(['execucao_id' => $comando->sagaId, 'posicao_na_fila' => 1], $evento->payload);
        $this->assertSame('corr-saga', $evento->correlationId);
        $execucao = app(ExecucaoRepository::class)->buscarPorOs(42);
        $this->assertSame(StatusExecucao::NaFila, $execucao->status());
        $this->assertSame('MATRIZ', $execucao->filial);
    }

    public function test_filial_cheia_rejeita_para_a_saga_compensar(): void
    {
        $this->enfileirar(1);

        $this->enfileirar(2);
        $this->enfileirar(3, 'sp');

        $tipos = array_map(fn (Envelope $e) => $e->type, $this->eventos());
        $this->assertSame(['execucao.evt.enfileirada', 'execucao.evt.rejeitada', 'execucao.evt.enfileirada'], $tipos);
        $this->assertSame(['motivo' => 'capacidade_esgotada'], $this->eventos()[1]->payload);
        $this->assertSame(StatusExecucao::Rejeitada, app(ExecucaoRepository::class)->buscarPorOs(2)->status());
    }

    public function test_comando_repetido_nao_duplica(): void
    {
        $comando = $this->enfileirar(42);

        $this->enfileirar(42, comando: $comando);

        $this->assertCount(1, $this->eventos());
    }

    public function test_comando_cria_os_indices(): void
    {
        $this->artisan('mongo:indices')->expectsOutput('Índices criados.')->assertSuccessful();

        $this->assertGreaterThanOrEqual(4, count(iterator_to_array($this->mongo->selectCollection('execucoes')->listIndexes())));
    }
}
