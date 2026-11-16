<?php

namespace Tests\Concerns;

use MongoDB\Client;
use MongoDB\Database;

trait UsaMongo
{
    protected Database $mongo;

    protected function conectarMongo(): void
    {
        $uri = getenv('MONGO_TEST_URI');
        if (! $uri) {
            $this->markTestSkipped('Defina MONGO_TEST_URI para rodar contra um MongoDB real.');
        }

        $this->mongo = (new Client($uri))->selectDatabase('execucao_teste_'.bin2hex(random_bytes(4)));
        $this->app->instance(Database::class, $this->mongo);
    }

    protected function tearDown(): void
    {
        if (isset($this->mongo)) {
            $this->mongo->drop();
        }
        parent::tearDown();
    }
}
