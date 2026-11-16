<?php

namespace Tests\Feature\Mensageria;

use Domain\Shared\Messaging\Envelope;
use Domain\Shared\Messaging\MessageHandler;
use Illuminate\Console\Signals;
use Infrastructure\Messaging\Broker;
use Infrastructure\Messaging\Decisao;
use Infrastructure\Messaging\OutboxRelay;
use Tests\Support\FakeBroker;
use Tests\TestCase;

class ComandosMensageriaTest extends TestCase
{
    private const SAGA_ID = '5d2a3c4e-1f2b-4c3d-9e8f-0a1b2c3d4e5f';

    private FakeBroker $broker;
    private string $heartbeat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broker = new FakeBroker();
        $this->app->instance(Broker::class, $this->broker);
        $this->heartbeat = tempnam(sys_get_temp_dir(), 'heartbeat');
        unlink($this->heartbeat);
        config(['mensageria.heartbeat_arquivo' => $this->heartbeat]);
        Signals::resolveAvailabilityUsing(fn () => true);
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeat);
        Signals::resolveAvailabilityUsing(fn () => false);
        parent::tearDown();
    }

    private function envelope(string $type): Envelope
    {
        return Envelope::criar($type, self::SAGA_ID, 'corr-1', 42);
    }

    public function test_outbox_publicar_informa_quantas_publicou(): void
    {
        $relay = $this->createMock(OutboxRelay::class);
        $relay->method('publicarPendentes')->willReturn(2);
        $this->app->instance(OutboxRelay::class, $relay);

        $this->artisan('outbox:publicar')->expectsOutput('2 mensagem(ns) publicada(s).')->assertSuccessful();
    }

    public function test_outbox_publicar_em_loop_para_no_sigterm_e_atualiza_o_heartbeat(): void
    {
        config(['mensageria.outbox.intervalo_ms' => 1]);
        $chamadas = 0;
        $relay = $this->createMock(OutboxRelay::class);
        $relay->method('publicarPendentes')->willReturnCallback(function () use (&$chamadas) {
            if (++$chamadas === 2) {
                posix_kill(getmypid(), SIGTERM);
            }

            return 0;
        });
        $this->app->instance(OutboxRelay::class, $relay);

        $this->artisan('outbox:publicar', ['--loop' => true])->assertSuccessful();

        $this->assertSame(2, $chamadas);
        $this->assertFileExists($this->heartbeat);
    }

    public function test_consumir_processa_a_fila_do_servico(): void
    {
        config(['mensageria.handlers' => ['execucao.cmd.enfileirar' => HandlerNulo::class]]);
        $valida = $this->envelope('execucao.cmd.enfileirar');
        $this->broker->enfileirar($valida->paraJson());
        $this->broker->enfileirar($valida->paraJson());
        $this->broker->enfileirar('lixo');

        $this->artisan('mensageria:consumir')->assertSuccessful();

        $this->assertSame(
            [Decisao::Confirmar, Decisao::Confirmar, Decisao::Rejeitar],
            $this->broker->decisoes,
        );
        $this->assertFileExists($this->heartbeat);
    }

    public function test_consumir_para_no_sigterm(): void
    {
        config(['mensageria.handlers' => ['execucao.cmd.enfileirar' => HandlerQueParaOConsumidor::class]]);
        $this->broker->enfileirar($this->envelope('execucao.cmd.enfileirar')->paraJson());
        $this->broker->enfileirar($this->envelope('execucao.cmd.enfileirar')->paraJson());

        $this->artisan('mensageria:consumir')->assertSuccessful();

        $this->assertCount(1, $this->broker->decisoes);
        $this->assertCount(1, $this->broker->entrada);
    }
}

class HandlerNulo implements MessageHandler
{
    public function tratar(Envelope $envelope): void
    {
    }
}

class HandlerQueParaOConsumidor implements MessageHandler
{
    public function tratar(Envelope $envelope): void
    {
        posix_kill(getmypid(), SIGTERM);
    }
}
