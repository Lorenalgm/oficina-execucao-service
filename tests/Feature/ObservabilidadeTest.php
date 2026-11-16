<?php

namespace Tests\Feature;

use App\Logging\CorrelationProcessor;
use App\Logging\TapCorrelation;
use App\Support\CorrelationContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

class ObservabilidadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->prefix('api')->group(function () {
            Route::get('/teste/{status}', fn (int $status) => response()->json(['ok' => true], $status));
            Route::get('/teste-excecao', fn () => throw new RuntimeException('falhou'));
        });
    }

    protected function tearDown(): void
    {
        CorrelationContext::clear();
        parent::tearDown();
    }

    public function test_resposta_devolve_correlation_id_gerado(): void
    {
        $correlationId = $this->getJson('/api/teste/200')->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $correlationId);
    }

    public function test_correlation_id_recebido_e_propagado(): void
    {
        $this->getJson('/api/teste/200', ['X-Request-Id' => 'id-vindo-do-gateway'])
            ->assertHeader('X-Request-Id', 'id-vindo-do-gateway');
    }

    public function test_correlation_id_derivado_do_trace_da_aws(): void
    {
        $this->getJson('/api/teste/200', ['X-Amzn-Trace-Id' => 'Root=1-5759e988-bd862e3fe1be46a994272793;Parent=53995c3f42cd8ad8'])
            ->assertHeader('X-Request-Id', '1-5759e988-bd862e3fe1be46a994272793');
    }

    public function test_requisicao_gera_log_estruturado_com_latencia(): void
    {
        Log::spy();

        $this->getJson('/api/teste/200');

        Log::shouldHaveReceived('log')->once()->withArgs(
            fn (string $nivel, string $mensagem, array $contexto) => $nivel === 'info'
                && $mensagem === 'http_request'
                && $contexto['route'] === 'api/teste/{status}'
                && $contexto['status'] === 200
                && is_float($contexto['duration_ms'])
        );
    }

    public function test_erro_do_servidor_e_logado_como_erro(): void
    {
        Log::spy();

        $this->getJson('/api/teste/503');

        Log::shouldHaveReceived('log')->once()->withArgs(fn (string $nivel) => $nivel === 'error');
    }

    public function test_excecao_nao_tratada_gera_evento_para_alerta(): void
    {
        Log::spy();

        $this->getJson('/api/teste-excecao')->assertStatus(500);

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $mensagem, array $contexto = []) => ($contexto['event_type'] ?? null) === 'unhandled_exception'
                && $contexto['exception_class'] === RuntimeException::class
        )->once();
    }

    public function test_processador_injeta_correlation_id_servico_e_ambiente(): void
    {
        CorrelationContext::set('corr-123');
        $registro = new LogRecord(new \DateTimeImmutable(), 'teste', Level::Info, 'mensagem');

        $extra = (new CorrelationProcessor())($registro)->extra;

        $this->assertSame('corr-123', $extra['correlation_id']);
        $this->assertSame(config('app.name'), $extra['service']);
        $this->assertSame('testing', $extra['env']);
    }

    public function test_tap_aplica_o_processador_nos_handlers_do_canal(): void
    {
        $logger = Log::channel('single');

        (new TapCorrelation())($logger);

        foreach ($logger->getLogger()->getHandlers() as $handler) {
            $this->assertInstanceOf(CorrelationProcessor::class, $handler->popProcessor());
        }
    }

    public function test_correlation_context_gera_quando_vazio(): void
    {
        CorrelationContext::clear();

        $gerado = CorrelationContext::getOrGenerate();

        $this->assertSame($gerado, CorrelationContext::get());
    }

    public function test_raiz_identifica_o_servico_e_health_check_responde(): void
    {
        $this->getJson('/')->assertOk()->assertJson(['servico' => 'oficina-execucao-service']);
        $this->get('/up')->assertOk();
    }
}
