<?php

namespace Infrastructure\Persistence;

use DateTimeImmutable;
use Domain\Execucao\Execucao;
use Domain\Execucao\ExecucaoRepository;
use Domain\Execucao\StatusExecucao;
use Domain\Shared\Messaging\Envelope;
use Infrastructure\Messaging\OutboxStore;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;

class MongoExecucaoRepository implements ExecucaoRepository, OutboxStore
{
    private const CHAVE_DUPLICADA = 11000;
    private const TIPOS = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    private Collection $colecao;

    public function __construct(Database $banco)
    {
        $this->colecao = $banco->selectCollection('execucoes', ['typeMap' => self::TIPOS]);
    }

    public function criarIndices(): void
    {
        $this->colecao->createIndex(['os_id' => 1, 'criado_em' => -1]);
        $this->colecao->createIndex(['filial' => 1, 'status' => 1, 'criado_em' => 1]);
        $this->colecao->createIndex(['outbox.publicado_em' => 1, 'atualizado_em' => 1]);
    }

    public function registrarNova(Execucao $execucao, Envelope $evento): bool
    {
        $agora = new UTCDateTime();

        try {
            $this->colecao->insertOne([
                '_id' => $execucao->id,
                'os_id' => $execucao->osId,
                'filial' => $execucao->filial,
                'veiculo' => $execucao->veiculo,
                'servicos' => $execucao->servicos,
                'status' => $execucao->status()->value,
                'linha_do_tempo' => $execucao->linhaDoTempo(),
                'criado_em' => $agora,
                'atualizado_em' => $agora,
                'outbox' => [$this->item($evento)],
            ]);
        } catch (BulkWriteException $e) {
            if ($e->getWriteResult()->getWriteErrors()[0]?->getCode() === self::CHAVE_DUPLICADA) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    public function salvarComEventos(Execucao $execucao, Envelope ...$eventos): void
    {
        $this->colecao->updateOne(['_id' => $execucao->id], [
            '$set' => [
                'status' => $execucao->status()->value,
                'linha_do_tempo' => $execucao->linhaDoTempo(),
                'atualizado_em' => new UTCDateTime(),
            ],
            '$push' => ['outbox' => ['$each' => array_map(fn (Envelope $e) => $this->item($e), $eventos)]],
        ]);
    }

    public function buscarPorOs(int $osId): ?Execucao
    {
        $documento = $this->colecao->findOne(['os_id' => $osId], ['sort' => ['criado_em' => -1]]);

        return $documento ? $this->paraEntidade($documento) : null;
    }

    public function contarAtivas(string $filial): int
    {
        return $this->colecao->countDocuments(['filial' => $filial, 'status' => ['$in' => $this->ativos()]]);
    }

    public function fila(?string $filial): array
    {
        $filtro = ['status' => ['$in' => $this->ativos()]] + ($filial ? ['filial' => $filial] : []);

        return array_map(
            fn (array $d) => $this->paraEntidade($d),
            $this->colecao->find($filtro, ['sort' => ['criado_em' => 1]])->toArray(),
        );
    }

    public function pendentes(int $limite): array
    {
        $pendentes = [];
        $documentos = $this->colecao->find(
            ['outbox.publicado_em' => null],
            ['sort' => ['atualizado_em' => 1], 'limit' => $limite, 'projection' => ['outbox' => 1]],
        );

        foreach ($documentos as $documento) {
            foreach ($documento['outbox'] as $item) {
                if ($item['publicado_em'] === null && count($pendentes) < $limite) {
                    $pendentes[] = ['documento' => $documento['_id'], 'message_id' => $item['message_id'], 'corpo' => $item['corpo']];
                }
            }
        }

        return $pendentes;
    }

    public function marcarPublicada(string $documento, string $messageId): void
    {
        $this->colecao->updateOne(
            ['_id' => $documento, 'outbox.message_id' => $messageId],
            ['$set' => ['outbox.$.publicado_em' => new UTCDateTime()]],
        );
    }

    private function item(Envelope $evento): array
    {
        return ['message_id' => $evento->messageId, 'corpo' => $evento->paraJson(), 'publicado_em' => null];
    }

    private function ativos(): array
    {
        return array_map(fn (StatusExecucao $s) => $s->value, StatusExecucao::ativos());
    }

    private function paraEntidade(array $d): Execucao
    {
        return new Execucao(
            $d['_id'],
            $d['os_id'],
            $d['filial'],
            $d['veiculo'],
            $d['servicos'],
            StatusExecucao::from($d['status']),
            $d['linha_do_tempo'],
        );
    }
}
