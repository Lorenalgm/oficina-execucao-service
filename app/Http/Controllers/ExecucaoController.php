<?php

namespace App\Http\Controllers;

use App\Support\CorrelationContext;
use Application\Execucao\AvancarEtapa;
use Domain\Execucao\Execucao;
use Domain\Execucao\ExecucaoRepository;
use Domain\Execucao\StatusExecucao;
use Domain\Execucao\TransicaoInvalida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExecucaoController extends Controller
{
    public function __construct(private ExecucaoRepository $execucoes) {}

    public function index(Request $request): JsonResponse
    {
        $filial = $request->query('filial');

        return response()->json(['data' => array_map(
            fn (Execucao $e) => $this->dados($e),
            $this->execucoes->fila($filial ? strtoupper($filial) : null),
        )]);
    }

    public function show(int $osId): JsonResponse
    {
        $execucao = $this->execucoes->buscarPorOs($osId);

        return $execucao
            ? response()->json(['data' => $this->dados($execucao)])
            : response()->json(['message' => 'Execução não encontrada.'], 404);
    }

    public function avancar(Request $request, int $osId, AvancarEtapa $avancar): JsonResponse
    {
        $dados = $request->validate([
            'status' => ['required', Rule::in(['em_diagnostico', 'em_reparo', 'finalizada'])],
        ]);

        try {
            $execucao = $avancar->executar($osId, StatusExecucao::from($dados['status']), CorrelationContext::getOrGenerate());
        } catch (TransicaoInvalida $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return $execucao
            ? response()->json(['data' => $this->dados($execucao)])
            : response()->json(['message' => 'Execução não encontrada.'], 404);
    }

    private function dados(Execucao $e): array
    {
        return [
            'execucao_id' => $e->id,
            'os_id' => $e->osId,
            'filial' => $e->filial,
            'status' => $e->status()->value,
            'veiculo' => $e->veiculo,
            'servicos' => $e->servicos,
            'linha_do_tempo' => $e->linhaDoTempo(),
        ];
    }
}
