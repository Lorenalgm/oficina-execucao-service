<?php

namespace App\Providers;

use Application\Execucao\Enfileirar;
use Domain\Execucao\ExecucaoRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Infrastructure\Messaging\Broker;
use Infrastructure\Messaging\HandlerRegistry;
use Infrastructure\Messaging\OutboxRelay;
use Infrastructure\Messaging\OutboxStore;
use Infrastructure\Messaging\ProcessadorDeMensagens;
use Infrastructure\Messaging\RabbitMq\RabbitMqBroker;
use Infrastructure\Persistence\MongoExecucaoRepository;
use MongoDB\Client;
use MongoDB\Database;

class MensageriaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Database::class, fn () => (new Client(config('services.mongo.uri')))
            ->selectDatabase(config('services.mongo.database')));
        $this->app->singleton(MongoExecucaoRepository::class);
        $this->app->bind(ExecucaoRepository::class, MongoExecucaoRepository::class);
        $this->app->bind(OutboxStore::class, MongoExecucaoRepository::class);

        $this->app->singleton(Broker::class, fn () => new RabbitMqBroker(
            config('mensageria.rabbitmq'),
            config('mensageria.exchange_retry'),
        ));

        $this->app->bind(OutboxRelay::class, fn (Application $app) => new OutboxRelay(
            $app->make(Broker::class),
            $app->make(OutboxStore::class),
            config('mensageria.exchange'),
        ));

        $this->app->bind(HandlerRegistry::class, fn (Application $app) => new HandlerRegistry(
            config('mensageria.handlers'),
            fn (string $classe) => $app->make($classe),
        ));

        $this->app->bind(ProcessadorDeMensagens::class, fn (Application $app) => new ProcessadorDeMensagens(
            $app->make(HandlerRegistry::class),
            config('mensageria.max_tentativas'),
        ));

        $this->app->when(Enfileirar::class)->needs('$capacidadePorFilial')->giveConfig('mensageria.capacidade_por_filial');
    }
}
