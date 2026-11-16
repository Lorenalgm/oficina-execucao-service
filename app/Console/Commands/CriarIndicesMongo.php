<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Infrastructure\Persistence\MongoExecucaoRepository;

class CriarIndicesMongo extends Command
{
    protected $signature = 'mongo:indices';

    protected $description = 'Cria os índices da coleção de execuções (idempotente)';

    public function handle(MongoExecucaoRepository $repositorio): int
    {
        $repositorio->criarIndices();
        $this->info('Índices criados.');

        return self::SUCCESS;
    }
}
