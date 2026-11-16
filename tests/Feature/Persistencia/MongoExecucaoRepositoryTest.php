<?php

namespace Tests\Feature\Persistencia;

use DateTimeImmutable;
use Domain\Execucao\Execucao;
use Domain\Execucao\StatusExecucao;
use Domain\Shared\Messaging\Envelope;
use Infrastructure\Messaging\OutboxRelay;
use Infrastructure\Persistence\MongoExecucaoRepository;
use Tests\Concerns\UsaMongo;
use Tests\Support\FakeBroker;
use Tests\TestCase;

class MongoExecucaoRepositoryTest extends TestCase
{
    use UsaMongo;

    private MongoExecucaoRepository $repositorio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conectarMongo();
        $this->repositorio = new MongoExecucaoRepository($this->mongo);
        $this->repositorio->criarIndices();
    }

    private function execucao(string $saga, int $osId = 42, string $filial = 'MATRIZ'): Execucao
    {
        return Execucao::enfileirar($saga, $osId, $filial, ['placa' => 'ABC1D23'], [['descricao' => 'Troca de óleo']], new DateTimeImmutable('2026-11-16T10:00:00Z'));
    }

    private function evento(string $saga, string $type = 'execucao.evt.enfileirada'): Envelope
    {
        return Envelope::criar($type, $saga, 'corr', 42, ['execucao_id' => $saga]);
    }

    public function test_registra_e_recupera_pela_os(): void
    {
        $saga = Envelope::novoUuid();

        $this->assertTrue($this->repositorio->registrarNova($this->execucao($saga), $this->evento($saga)));

        $this->assertEquals($this->execucao($saga), $this->repositorio->buscarPorOs(42));
        $this->assertNull($this->repositorio->buscarPorOs(99));
    }

    public function test_mesma_saga_nao_e_registrada_duas_vezes(): void
    {
        $saga = Envelope::novoUuid();
        $this->repositorio->registrarNova($this->execucao($saga), $this->evento($saga));

        $this->assertFalse($this->repositorio->registrarNova($this->execucao($saga), $this->evento($saga)));
        $this->assertCount(1, $this->repositorio->pendentes(10));
    }

    public function test_fila_e_contagem_consideram_so_ativas_da_filial(): void
    {
        $a = Envelope::novoUuid();
        $b = Envelope::novoUuid();
        $this->repositorio->registrarNova($this->execucao($a, 1), $this->evento($a));
        $this->repositorio->registrarNova($this->execucao($b, 2, 'SP'), $this->evento($b));
        $rejeitada = Envelope::novoUuid();
        $this->repositorio->registrarNova(Execucao::rejeitar($rejeitada, 3, 'MATRIZ', [], [], new DateTimeImmutable()), $this->evento($rejeitada));

        $this->assertSame(1, $this->repositorio->contarAtivas('MATRIZ'));
        $this->assertSame([1], array_map(fn ($e) => $e->osId, $this->repositorio->fila('MATRIZ')));
        $this->assertCount(2, $this->repositorio->fila(null));
    }

    public function test_salvar_com_evento_atualiza_status_e_empilha_na_outbox(): void
    {
        $saga = Envelope::novoUuid();
        $execucao = $this->execucao($saga);
        $this->repositorio->registrarNova($execucao, $this->evento($saga));
        $execucao->avancarPara(StatusExecucao::EmDiagnostico, new DateTimeImmutable());

        $this->repositorio->salvarComEvento($execucao, $this->evento($saga, 'execucao.evt.status-alterado'));

        $this->assertSame(StatusExecucao::EmDiagnostico, $this->repositorio->buscarPorOs(42)->status());
        $this->assertCount(2, $this->repositorio->pendentes(10));
    }

    public function test_relay_publica_em_ordem_e_marca_como_publicado(): void
    {
        $saga = Envelope::novoUuid();
        $execucao = $this->execucao($saga);
        $primeiro = $this->evento($saga);
        $this->repositorio->registrarNova($execucao, $primeiro);
        $execucao->avancarPara(StatusExecucao::EmDiagnostico, new DateTimeImmutable());
        $this->repositorio->salvarComEvento($execucao, $this->evento($saga, 'execucao.evt.status-alterado'));
        $broker = new FakeBroker();
        $relay = new OutboxRelay($broker, $this->repositorio, 'oficina');

        $this->assertSame(2, $relay->publicarPendentes(10));
        $this->assertSame(0, $relay->publicarPendentes(10));

        $this->assertSame(['execucao.evt.enfileirada', 'execucao.evt.status-alterado'], array_column($broker->publicadas, 'routing_key'));
        $this->assertSame($primeiro->paraJson(), $broker->publicadas[0]['corpo']);
        $this->assertSame($primeiro->messageId, $broker->publicadas[0]['cabecalhos']['message_id']);
    }

    public function test_relay_para_no_primeiro_erro_sem_perder_mensagens(): void
    {
        $saga = Envelope::novoUuid();
        $this->repositorio->registrarNova($this->execucao($saga), $this->evento($saga));
        $broker = new FakeBroker();
        $broker->falharNaPublicacao = 0;

        $this->assertSame(0, (new OutboxRelay($broker, $this->repositorio, 'oficina'))->publicarPendentes(10));
        $this->assertCount(1, $this->repositorio->pendentes(10));
    }
}
