<?php

namespace Tests\Feature\Mensageria;

use App\Support\CorrelationContext;
use Domain\Shared\Messaging\Envelope;
use Domain\Shared\Messaging\MessageHandler;
use Illuminate\Support\Facades\Log;
use Infrastructure\Messaging\Decisao;
use Infrastructure\Messaging\HandlerRegistry;
use Infrastructure\Messaging\ProcessadorDeMensagens;
use RuntimeException;
use Tests\TestCase;

class ProcessadorDeMensagensTest extends TestCase
{
    private const TIPO = 'execucao.cmd.enfileirar';

    private HandlerEspiao $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new HandlerEspiao();
        Log::spy();
    }

    private function processar(string $corpo, int $tentativas = 0): Decisao
    {
        $registro = new HandlerRegistry([self::TIPO => HandlerEspiao::class], fn () => $this->handler);

        return (new ProcessadorDeMensagens($registro, 3))->processar($corpo, $tentativas);
    }

    private function envelope(string $type = self::TIPO): Envelope
    {
        return Envelope::criar($type, '5d2a3c4e-1f2b-4c3d-9e8f-0a1b2c3d4e5f', 'corr-saga', 42, ['filial' => 'MATRIZ']);
    }

    public function test_entrega_ao_handler_com_o_correlation_id_da_saga(): void
    {
        $envelope = $this->envelope();

        $this->assertSame(Decisao::Confirmar, $this->processar($envelope->paraJson()));

        $this->assertEquals([$envelope], $this->handler->recebidas);
        $this->assertSame('corr-saga', $this->handler->correlationIdVisto);
        $this->assertNull(CorrelationContext::get());
    }

    public function test_falha_pede_retentativa_e_na_ultima_vai_para_a_dlq(): void
    {
        $this->handler->falhar = true;

        $this->assertSame(Decisao::Retentar, $this->processar($this->envelope()->paraJson(), 0));
        $this->assertSame(Decisao::Rejeitar, $this->processar($this->envelope()->paraJson(), 2));
        Log::shouldHaveReceived('log')->withArgs(fn ($nivel, $msg, $ctx) => $nivel === 'error' && $ctx['event_type'] === 'mensageria.enviada_dlq')->once();
    }

    public function test_mensagem_invalida_ou_sem_handler_vai_para_a_dlq(): void
    {
        $this->assertSame(Decisao::Rejeitar, $this->processar('lixo'));
        $this->assertSame(Decisao::Rejeitar, $this->processar($this->envelope('execucao.cmd.desconhecido')->paraJson()));
        $this->assertSame([], $this->handler->recebidas);
    }

    public function test_registro_resolve_pelo_tipo(): void
    {
        $registro = new HandlerRegistry([self::TIPO => HandlerEspiao::class], fn (string $classe) => new $classe());

        $this->assertInstanceOf(HandlerEspiao::class, $registro->para(self::TIPO));
        $this->assertNull($registro->para('outro'));
    }
}

class HandlerEspiao implements MessageHandler
{
    public array $recebidas = [];
    public ?string $correlationIdVisto = null;
    public bool $falhar = false;

    public function tratar(Envelope $envelope): void
    {
        $this->correlationIdVisto = CorrelationContext::get();
        if ($this->falhar) {
            throw new RuntimeException('Mongo fora');
        }
        $this->recebidas[] = $envelope;
    }
}
