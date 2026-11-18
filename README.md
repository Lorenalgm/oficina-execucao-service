# oficina-execucao-service

Execução e produção da oficina mecânica (fila, diagnóstico e reparo) — Tech Challenge 13SOAT, Fase 4.

Último passo da **Saga orquestrada** pelo [OS Service](https://github.com/Lorenalgm/oficina_mecanica): recebe a OS paga, coloca na fila da filial e avisa cada mudança de etapa até a finalização. Não acessa o banco de nenhum outro serviço ([contrato de mensagens](https://github.com/Lorenalgm/oficina_mecanica/blob/main/docs/fase4/contrato-mensagens.md)).

[![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=Lorenalgm_oficina-execucao-service&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=Lorenalgm_oficina-execucao-service)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=Lorenalgm_oficina-execucao-service&metric=coverage)](https://sonarcloud.io/summary/new_code?id=Lorenalgm_oficina-execucao-service)

## Arquitetura

```mermaid
flowchart LR
    OS[OS Service] -- "execucao.cmd.enfileirar" --> MQ[(RabbitMQ<br/>execucao.comandos)] --> W[execucao-worker]
    W --> DB[(MongoDB<br/>execucoes)]
    Mecanico((Mecânico)) -- "PATCH /status (JWT)" --> API[execucao-api] --> DB
    DB -- outbox no documento --> R[relay] -- "execucao.evt.*" --> MQ2[(os.saga.eventos)] --> OS
```

**Banco NoSQL (MongoDB)**: cada execução é um documento (`_id` = `saga_id`) com estado, linha do tempo e eventos pendentes. Não usamos transação multi-documento (exigiria replica set); em vez disso:

- **Idempotência**: criar a execução é um `insertOne`; o mesmo comando da Saga de novo bate na chave duplicada e não repete o efeito.
- **Outbox no próprio documento**: o evento entra no array `outbox` na mesma operação atômica da mudança de estado; o relay publica e marca `publicado_em`.
- Por que documento: a execução é lida e escrita inteira, sem joins, com linha do tempo de tamanho variável.

## Mensagens

| Recebe | Responde |
|---|---|
| `execucao.cmd.enfileirar` | `execucao.evt.enfileirada` (com `posicao_na_fila`) ou `execucao.evt.rejeitada` (`capacidade_esgotada`, dispara o estorno na Saga) |

Publicados a partir do mecânico: `execucao.evt.status-alterado` a cada etapa e `execucao.evt.finalizada` no fim (a OS vai para *Finalizada*).

Etapas: `na_fila → em_diagnostico → em_reparo → finalizada`, uma por vez. Capacidade por filial em `EXECUCAO_CAPACIDADE_POR_FILIAL` (padrão 5; use 1 para demonstrar a fila cheia).

## Rotas

Protegidas pelo JWT da [Lambda de autenticação](https://github.com/Lorenalgm/oficina-auth-lambda). Documentadas no [readme.io](https://lorenalgm.readme.io/reference) (junto com as do OS e do Billing) e em [`openapi.yaml`](openapi.yaml).

| Rota | Uso |
|---|---|
| `GET /api/execucoes?filial=` | Fila ativa da filial |
| `GET /api/execucoes/{osId}` | Detalhe com linha do tempo |
| `PATCH /api/execucoes/{osId}/status` | Avança a etapa (`em_diagnostico`, `em_reparo`, `finalizada`) |

## Cobertura de testes

[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=Lorenalgm_oficina-execucao-service&metric=coverage)](https://sonarcloud.io/summary/overall?id=Lorenalgm_oficina-execucao-service) [![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=Lorenalgm_oficina-execucao-service&metric=alert_status)](https://sonarcloud.io/summary/overall?id=Lorenalgm_oficina-execucao-service)

- Cobertura medida no CI a cada PR, com **mínimo de 80%** (`php artisan test --coverage --min=80`); abaixo disso o PR não passa.
- Relatório completo no [SonarCloud](https://sonarcloud.io/summary/overall?id=Lorenalgm_oficina-execucao-service), com quality gate.
- Testes unitários em `tests/Unit` e de integração em `tests/Feature` (RabbitMQ e banco reais no CI).
- O fluxo completo da Saga é testado com BDD no [OS Service](https://github.com/Lorenalgm/oficina_mecanica/blob/main/features/saga_os.feature).

## Rodar localmente

```bash
cp .env.example .env && php artisan key:generate
docker compose up -d                              # API em http://localhost:8002 e MongoDB na 27018
MONGO_TEST_URI=mongodb://root:secret@localhost:27018 php artisan test
```

Sem `MONGO_TEST_URI` e `RABBITMQ_TEST_HOST`, os testes de integração são pulados; no CI, MongoDB e RabbitMQ sobem como services.

## Kubernetes e CI/CD

- `kubectl apply -k k8s/` depois de criar o Secret `execucao-secrets` (`k8s/secret.example.yaml`). Usa o RabbitMQ e o MongoDB da plataforma do [oficina-infra-k8s](https://github.com/Lorenalgm/oficina-infra-k8s).
- **CI** (PR): testes com cobertura mínima de 80%, SonarCloud e build da imagem. `main` só recebe código por PR com o CI verde.
- **CD** (`main`): testes → imagem no GHCR → deploy no EKS (índices, rollout e smoke test).
