<?php

namespace Tests\Feature\Execucao;

use DateTimeImmutable;
use Domain\Execucao\Execucao;
use Domain\Execucao\ExecucaoRepository;
use Domain\Shared\Messaging\Envelope;
use Infrastructure\Messaging\OutboxStore;
use Tests\Concerns\AutenticaComJwt;
use Tests\Concerns\UsaMongo;
use Tests\TestCase;

class RotasExecucaoTest extends TestCase
{
    use AutenticaComJwt, UsaMongo;

    private string $saga;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conectarMongo();
        $this->saga = Envelope::novoUuid();
        $execucao = Execucao::enfileirar($this->saga, 42, 'MATRIZ', ['placa' => 'ABC1D23'], [['descricao' => 'Troca de óleo']], new DateTimeImmutable());
        app(ExecucaoRepository::class)->registrarNova($execucao, Envelope::criar('execucao.evt.enfileirada', $this->saga, 'corr', 42, []));
    }

    private function avancar(string $status, int $osId = 42)
    {
        return $this->patchJson("/api/execucoes/{$osId}/status", ['status' => $status], $this->cabecalhoJwt() + ['X-Request-Id' => 'corr-mecanico']);
    }

    /** @return list<Envelope> */
    private function eventos(): array
    {
        return array_map(fn ($p) => Envelope::deJson($p['corpo']), app(OutboxStore::class)->pendentes(50));
    }

    public function test_fila_e_detalhe(): void
    {
        $this->getJson('/api/execucoes?filial=matriz', $this->cabecalhoJwt())
            ->assertOk()
            ->assertJsonPath('data.0.os_id', 42)
            ->assertJsonPath('data.0.status', 'na_fila');
        $this->getJson('/api/execucoes?filial=SP', $this->cabecalhoJwt())->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/execucoes/42', $this->cabecalhoJwt())
            ->assertOk()
            ->assertJsonPath('data.execucao_id', $this->saga)
            ->assertJsonPath('data.linha_do_tempo.0.status', 'na_fila');
        $this->getJson('/api/execucoes/99', $this->cabecalhoJwt())->assertNotFound();
    }

    public function test_avanco_ate_finalizar_avisa_a_saga(): void
    {
        $this->avancar('em_diagnostico')->assertOk()->assertJsonPath('data.status', 'em_diagnostico');
        $this->avancar('em_reparo')->assertOk();
        $this->avancar('finalizada')->assertOk()->assertJsonPath('data.status', 'finalizada');

        $eventos = $this->eventos();
        $this->assertSame(
            ['execucao.evt.enfileirada', 'execucao.evt.status-alterado', 'execucao.evt.status-alterado', 'execucao.evt.status-alterado', 'execucao.evt.finalizada'],
            array_map(fn (Envelope $e) => $e->type, $eventos),
        );
        $this->assertSame('em_reparo', $eventos[3]->payload['status_anterior']);
        $this->assertSame($this->saga, $eventos[4]->sagaId);
        $this->assertSame('corr-mecanico', $eventos[4]->correlationId);
        $this->assertSame(['execucao_id' => $this->saga], array_intersect_key($eventos[4]->payload, ['execucao_id' => 1]));
    }

    public function test_pular_etapa_retorna_409_sem_publicar(): void
    {
        $this->avancar('finalizada')->assertStatus(409);

        $this->assertCount(1, $this->eventos());
    }

    public function test_validacao_os_inexistente_e_autenticacao(): void
    {
        $this->avancar('na_fila')->assertStatus(422);
        $this->avancar('em_diagnostico', 99)->assertNotFound();
        $this->getJson('/api/execucoes')->assertUnauthorized();
    }
}
