# SupportFlow — API REST (etapa 2)

> Evolução na etapa 3: adicionado `GET /api/dashboard/summary` para o frontend Vue, sem mudança nas regras existentes. Consulte o contrato abaixo e as instruções em [frontend.md](frontend.md).

## Resumo do dashboard — etapa 3

`GET /api/dashboard/summary` retorna HTTP 200 com `data` contendo:

- `counts`: `open`, `in_progress`, `waiting`, `overdue_tasks` e `today_reminders`, com contagens completas, inclusive quando as listas resumidas ultrapassam cinco registros.
- `recent_tickets`: até cinco atendimentos recentes por ID decrescente, com sistema, contato e cliente, incluindo históricos de sistemas inativos.
- `overdue_tasks`: até cinco pendências `pending` com `due_at < agora`, ordenadas por prazo/ID, com atendimento associado quando existente.
- `today_reminders`: até cinco lembretes não concluídos do dia local, ordenados por previsão/ID.
- `overdue_reminders`: até cinco lembretes não concluídos com previsão anterior a agora, ordenados por previsão/ID.
- `upcoming_reminders`: até cinco lembretes não concluídos a partir de amanhã, ordenados por previsão/ID.

As listas usam os mesmos API Resources dos endpoints existentes, sem `links`/`meta` por serem resumos limitados. As datas usam `America/Sao_Paulo`. Lembretes vencidos hoje podem aparecer tanto na seção de hoje quanto na de atrasados, conforme a regra já existente. O endpoint é somente de leitura, sem filtros ou paginação; utilize as listagens originais para consultar todos os registros.

Exemplo com banco sem registros:

```json
{
    "data": {
        "counts": {
            "open": 0,
            "in_progress": 0,
            "waiting": 0,
            "overdue_tasks": 0,
            "today_reminders": 0
        },
        "recent_tickets": [],
        "overdue_tasks": [],
        "today_reminders": [],
        "overdue_reminders": [],
        "upcoming_reminders": []
    }
}
```

API local sem autenticação, construída sobre os sete Models da etapa 1. Não há integração WhatsApp, notificações externas ou exclusões permanentes.

## Execução e convenções

```powershell
php artisan migrate --no-interaction
php artisan serve --host=127.0.0.1 --port=8000
```

URL base: `http://127.0.0.1:8000/api`. Use `Accept: application/json` e `Content-Type: application/json`. Não é necessário token, login ou cookie CSRF nas rotas API. Erros nessas rotas são JSON mesmo sem o cabeçalho Accept.

- Consulta e atualização: HTTP 200, corpo `{"data": {...}}`.
- Criação: HTTP 201, corpo `{"data": {...}}`.
- Registro inexistente: HTTP 404.
- Encerramento com pendências sem confirmação: HTTP 409.
- Dados ou filtros inválidos: HTTP 422, com `message` e `errors` por campo.
- DELETE: não disponível (HTTP 405 em URLs de recursos existentes). Não existem ações de exclusão nesta API.
- As operações implementadas retornam a representação atualizada; nenhuma requer resposta 204.
- PUT e PATCH atualizam somente os campos enviados; campos obrigatórios não podem ser anulados. Campos opcionais podem ser removidos com `null`. Campos desconhecidos são ignorados e não persistidos.
- Textos simples: até 255 caracteres; telefone: até 50; campos text: até 16.000 caracteres na API. Não foram impostas unicidades sobre nome, telefone ou e-mail.
- `page`: inteiro a partir de 1; `per_page`: inteiro de 1 a 100, padrão 15. Listagens retornam `data`, `links` e `meta`; os links preservam os filtros. Ordenação estável por ID como desempate.
- Sistemas, contatos e clientes são ordenados por nome/ID. Atendimentos, pendências e artigos por ID decrescente. Lembretes por data prevista/ID.

## Datas e horários

O fuso da aplicação é `America/Sao_Paulo`. Envie datas/horários como **`YYYY-MM-DD HH:mm:ss` no horário local**, sem offset. As respostas serializam as datas em ISO 8601 com offset, por exemplo `2026-10-21T10:00:00-03:00`.

Datas de filtro usam `YYYY-MM-DD`. Os limites inicial e final de criação de atendimento incluem o dia inteiro. Não são aceitos atalhos como "amanhã" ou datas inválidas. Não houve reescrita automática de datas históricas na alteração do fuso da aplicação.

## Endpoints

Para **cada** recurso abaixo existem estas operações:

| Método       | Caminho               | Operação             |
| ------------ | --------------------- | -------------------- |
| GET          | `/api/{recurso}`      | Listar com paginação |
| POST         | `/api/{recurso}`      | Criar                |
| GET          | `/api/{recurso}/{id}` | Consultar            |
| PUT ou PATCH | `/api/{recurso}/{id}` | Atualizar            |

Recursos: `systems`, `contacts`, `customers`, `support-tickets`, `pending-tasks`, `reminders` e `knowledge-articles`.

Operações adicionais:

| Método | Caminho                             | Corpo / resultado                                                                         |
| ------ | ----------------------------------- | ----------------------------------------------------------------------------------------- |
| PATCH  | `/api/support-tickets/{id}/status`  | `status` obrigatório; `confirm_open_tasks` opcional                                       |
| POST   | `/api/support-tickets/{id}/close`   | Dados opcionais de resolução e confirmação; encerra individualmente                       |
| GET    | `/api/support-tickets/{id}/related` | Lista paginada do atendimento anterior e dos retornos diretamente ligados a este registro |
| POST   | `/api/pending-tasks/{id}/complete`  | `completion_notes` opcional; conclui e registra a data                                    |
| POST   | `/api/reminders/{id}/complete`      | Sem corpo; registra a conclusão                                                           |

A ativação/desativação de sistema utiliza `PATCH /api/systems/{id}` com `{"is_active": true}` ou `{"is_active": false}`.

## Campos de escrita por recurso

| Recurso              | Obrigatórios na criação                           | Opcionais                                                                                                                                                    |
| -------------------- | ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `systems`            | `name`                                            | `description`, `is_active` (padrão true)                                                                                                                     |
| `contacts`           | `name`                                            | `phone`, `email`, `notes`                                                                                                                                    |
| `customers`          | `name`, `document`                                | `phone`, `email`, `notes`                                                                                                                                    |
| `support-tickets`    | `system_id`, `contact_id`, `title`, `description` | `customer_id`, `previous_ticket_id`, `status`, `resolution`, `verification_source`, `verified_at`, `verified_by`, `verification_notes`, `confirm_open_tasks` |
| `pending-tasks`      | `description`                                     | `support_ticket_id`, `due_at`, `status`, `completion_notes`                                                                                                  |
| `reminders`          | `title`, `scheduled_at`                           | `description`, `support_ticket_id`                                                                                                                           |
| `knowledge-articles` | `problem`, `resolution_procedure`                 | `system_id`                                                                                                                                                  |

Todos os identificadores associados devem existir. Cliente e contato continuam independentes. Pendências e lembretes podem ser criados ou desvinculados com `support_ticket_id: null`. Artigos podem ser gerais com `system_id: null`.

Em clientes, `document` é uma string obrigatória na criação: CPF com 11 dígitos ou CNPJ com 14 dígitos. A API aceita pontuação e espaços, remove os caracteres não numéricos e valida a quantidade de dígitos antes de salvar. Zeros à esquerda são preservados; não há validação de dígitos verificadores nem unicidade. Ausência ou valor vazio retorna 422 com `O CPF ou CNPJ é obrigatório.`; formato inválido retorna `Informe um CPF com 11 dígitos ou um CNPJ com 14 dígitos.` em `errors.document`.

Em `PUT` e `PATCH`, o documento pode ser omitido ou enviado como `null`; um documento preenchido passa pela mesma normalização e validação. Clientes antigos sem documento continuam disponíveis para listagem, consulta, edição e associação aos atendimentos.

`closed_at` e `completed_at` são controlados pelas operações e não aceitam datas manuais. `created_at` e `updated_at` são gerenciados pelo Eloquent.

## Filtros de listagem

Além de `page` e `per_page`:

| Recurso                 | Parâmetros                                                                                                  |
| ----------------------- | ----------------------------------------------------------------------------------------------------------- |
| `systems`               | `name` (trecho), `q` (nome), `is_active` (`1` ou `0`)                                                       |
| `contacts`, `customers` | `q` busca nome, telefone, e-mail e observações                                                              |
| `support-tickets`       | `system_id`, `contact_id`, `customer_id`, `status`, `created_from`, `created_to`, `q` (título ou descrição) |
| `pending-tasks`         | `support_ticket_id`, `status` (`pending` ou `completed`), `filter` (`open`, `completed` ou `overdue`)       |
| `reminders`             | `support_ticket_id`, `filter` (`today`, `overdue`, `upcoming`, `completed` ou `pending`)                    |
| `knowledge-articles`    | `system_id`, `q` (problema, procedimento ou nome do sistema)                                                |

Filtros de uma mesma listagem são combinados com AND; os campos pesquisados por `q` são combinados com OR. IDs válidos sem correspondência no filtro retornam lista vazia. Na consulta `/related` são usados apenas `page` e `per_page`.

Pendência atrasada: `status=pending` e `due_at < agora`. Sem prazo não é atraso. O campo de resposta `is_overdue` é calculado, sem coluna redundante.

Filtros de lembretes:

- `today`: não concluídos, com data prevista entre meia-noite de hoje e meia-noite de amanhã, no fuso local.
- `overdue`: não concluídos com horário previsto anterior a agora; inclui horários já vencidos de hoje.
- `upcoming`: não concluídos previstos a partir de amanhã, sem limite final arbitrário.
- `completed`: somente os que possuem `completed_at`.
- `pending`: todos os não concluídos.
- Sem filtro: todos os lembretes. Portanto, um lembrete vencido hoje aparece tanto em `today` quanto em `overdue`. A resposta também informa `is_overdue`.

## Regras de atendimento

1. O sistema deve estar ativo na criação. Ao trocar o sistema de um atendimento, o novo também deve estar ativo. Um atendimento histórico pode manter o sistema inativo e continuar sendo consultado ou atualizado.
2. Novos atendimentos começam em `open`. Na criação, `status` pode ser omitido ou enviado como `open`. Nas atualizações, são aceitos `open`, `in_progress`, `waiting`, `resolved` e `closed`, sem ordem obrigatória de transições.
3. `previous_ticket_id` pode apontar para um atendimento existente. Autorrelacionamento e ciclos diretos ou indiretos são rejeitados com 422. Não há restrição por sistema, contato ou status do anterior, pois essas restrições não foram solicitadas.
4. Atendimentos relacionados têm status e encerramentos independentes. `/related` retorna vínculos imediatos, sem incluir automaticamente toda a árvore ou irmãos. O detalhe completo contém sistema, contato, cliente, atendimento anterior, retornos diretos, pendências e lembretes. As listagens carregam os relacionamentos necessários antecipadamente.
5. Ao transitar para `closed`, qualquer pendência com status `pending` vinculada ao próprio atendimento exige confirmação. A regra vale igualmente para `/close`, `/status`, PUT e PATCH. Lembretes e pendências independentes ou de outros atendimentos não bloqueiam.
6. A confirmação deve ser o booleano JSON **`true`**. Omissão ou `false` produz 409 se houver pendências. Strings como `"true"` e números como `1` são rejeitados com 422.
7. Em 409, nenhuma alteração enviada é persistida. Após confirmação, o atendimento é encerrado e as pendências permanecem abertas. A validação é refeita na requisição confirmada. Transações e bloqueios de registros coordenam encerramento, associação e alterações de pendências; deadlocks podem ser repetidos até três vezes.
8. A data de encerramento é gerada pelo servidor. Repetir o encerramento já realizado mantém a data, inclusive se novas pendências tiverem sido associadas posteriormente. Alterar para qualquer status diferente de `closed` reabre o registro e limpa somente seu `closed_at`.

## Confirmação da resolução

Nova coluna incremental: `support_tickets.verification_source`, string opcional de até 50 caracteres. Valores aceitos pela API:

- `customer`: cliente.
- `responsible_department`: setor responsável.

`verification_source` e `verified_at` devem ser enviados juntos ao registrar ou alterar uma confirmação. Para removê-la, envie ambos como `null`. `verified_by` identifica opcionalmente pessoa, empresa ou setor; `verification_notes` armazena o procedimento de verificação ou observações. `resolution` registra a solução aplicada.

A confirmação pode ser informada na criação/atualização ou no encerramento. O encerramento não exige fonte específica nem torna a confirmação obrigatória, já que essa exigência não foi definida. A confirmação pelo setor responsável é tão válida quanto a do cliente. Dados históricos de verificação em texto livre não são apagados ou convertidos automaticamente.

## Conclusão de pendências e lembretes

Pendências começam em `pending`. Atualizar para `completed` ou chamar `/complete` registra `completed_at` automaticamente. Repetir a conclusão mantém a primeira data; `completion_notes` pode ser atualizada. Alterar para `pending` reabre a pendência e limpa a data de conclusão, preservando as observações existentes até que sejam editadas.

Lembretes são criados sem conclusão. `/complete` é idempotente e mantém a primeira data de conclusão. Não foi criada uma ação de reabertura de lembretes, pois não foi solicitada.

## Exemplos JSON e respostas

Criar sistema — `POST /api/systems`:

```json
{ "name": "ERP", "description": "Sistema de gestão", "is_active": true }
```

Resposta 201 (identificadores e datas variam):

```json
{
    "data": {
        "id": 1,
        "name": "ERP",
        "description": "Sistema de gestão",
        "is_active": true,
        "created_at": "2026-10-21T10:00:00-03:00",
        "updated_at": "2026-10-21T10:00:00-03:00"
    }
}
```

Criar contato — `POST /api/contacts`:

```json
{ "name": "Ana", "phone": "11999999999", "email": "ana@example.com" }
```

Criar cliente — `POST /api/customers`:

```json
{ "name": "Loja Central", "document": "12.345.678/0001-90", "notes": "Matriz" }
```

Criar atendimento — `POST /api/support-tickets` (substitua os IDs pelos retornados):

```json
{
    "system_id": 1,
    "contact_id": 1,
    "customer_id": 1,
    "title": "Erro ao emitir nota",
    "description": "Falha ao finalizar emissão fiscal."
}
```

Novo atendimento relacionado — `POST /api/support-tickets`:

```json
{
    "system_id": 1,
    "contact_id": 1,
    "title": "Retorno sobre a emissão",
    "description": "Cliente voltou a solicitar apoio.",
    "previous_ticket_id": 1
}
```

Alterar status — `PATCH /api/support-tickets/1/status`:

```json
{ "status": "waiting" }
```

Criar pendência — `POST /api/pending-tasks`:

```json
{
    "support_ticket_id": 1,
    "description": "Aguardar log do setor fiscal",
    "due_at": "2026-10-22 09:00:00"
}
```

Solicitar encerramento — `POST /api/support-tickets/1/close` com `{}`. Se houver pendências abertas, resposta 409 (trecho ilustrativo; cada pendência inclui seus demais campos):

```json
{
    "message": "Há pendências abertas. Confirme explicitamente para encerrar o atendimento.",
    "code": "open_pending_tasks",
    "open_tasks_count": 1,
    "pending_tasks": [
        {
            "id": 1,
            "support_ticket_id": 1,
            "description": "Aguardar log do setor fiscal",
            "status": "pending"
        }
    ]
}
```

Confirmar encerramento com validação pelo setor — repita `POST /api/support-tickets/1/close`:

```json
{
    "confirm_open_tasks": true,
    "resolution": "Configuração fiscal corrigida.",
    "verification_source": "responsible_department",
    "verified_at": "2026-10-21 15:00:00",
    "verified_by": "Setor fiscal da Loja Central",
    "verification_notes": "O setor realizou a emissão e confirmou o funcionamento."
}
```

Resposta 200 contém `data.status = "closed"`, `data.closed_at` e os campos da resolução; a pendência continua `pending`.

Concluir pendência — `POST /api/pending-tasks/1/complete`:

```json
{ "completion_notes": "Log recebido e analisado." }
```

Criar lembrete independente — `POST /api/reminders`:

```json
{
    "title": "Revisar retornos",
    "description": "Conferir pendências do dia.",
    "scheduled_at": "2026-10-22 09:00:00",
    "support_ticket_id": null
}
```

Concluir lembrete — `POST /api/reminders/1/complete`, sem corpo ou com `{}`.

Criar artigo geral — `POST /api/knowledge-articles`:

```json
{
    "system_id": null,
    "problem": "Computador sem conexão",
    "resolution_procedure": "Verificar cabo, endereço IP e conectividade com o gateway."
}
```

Consulta combinada:

```text
GET /api/support-tickets?system_id=1&status=waiting&created_from=2026-10-01&created_to=2026-10-31&q=nota&per_page=15&page=1
GET /api/pending-tasks?filter=overdue
GET /api/reminders?filter=today
GET /api/knowledge-articles?q=certificado
```

Formato das listagens (exemplo de sistema, campos abreviados):

```json
{
    "data": [{ "id": 1, "name": "ERP", "is_active": true }],
    "links": {
        "first": "http://127.0.0.1:8000/api/systems?page=1",
        "last": "http://127.0.0.1:8000/api/systems?page=1",
        "prev": null,
        "next": null
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 1,
        "path": "http://127.0.0.1:8000/api/systems",
        "per_page": 15,
        "to": 1,
        "total": 1
    }
}
```

Exemplo 422 ao criar sistema sem nome:

```json
{
    "message": "O campo name é obrigatório.",
    "errors": { "name": ["O campo name é obrigatório."] }
}
```

## Testar no Postman

1. Inicie o servidor com o comando acima. Crie uma coleção com variável `base_url = http://127.0.0.1:8000/api` e os dois cabeçalhos JSON.
2. Configure autenticação como **No Auth**. Nas requisições de escrita, selecione Body → raw → JSON.
3. Execute os exemplos de sistema, contato e cliente. Guarde os respectivos `data.id` e use-os no cadastro do atendimento.
4. Crie uma pendência vinculada. Envie `{}` para `{{base_url}}/support-tickets/{id}/close` e confira o 409, a quantidade e os IDs das pendências.
5. Repita com `confirm_open_tasks: true` e confira o 200 e o encerramento. Consulte a pendência para confirmar que continua aberta; conclua-a pela ação própria.
6. Crie o retorno relacionado e confira que o encerramento do anterior não alterou seu status. Teste vínculo com o próprio ID e ciclo para verificar o 422.
7. Desative o sistema com PATCH. O histórico continua consultável, mas criar novo atendimento nesse sistema retorna 422. Reative-o para continuar os cadastros.
8. Crie lembrete independente e artigo sem sistema. Experimente os filtros, `page=2`, `per_page=1`, um ID inexistente (404) e DELETE (405).

## Verificações automatizadas

```powershell
php artisan test
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
php artisan route:list --path=api --except-vendor
php artisan migrate:status
```

Os testes usam SQLite em memória e preservam os testes da etapa 1. Nenhum comando destrutivo deve ser executado no MySQL existente. A migration incremental adiciona apenas uma coluna opcional, sem alterar migrations já aplicadas.

## Arquivos da etapa 2

Criados:

- `routes/api.php`.
- `app/Actions/SaveSupportTicket.php` e `SavePendingTask.php`.
- `app/Enums/TicketStatus.php`, `PendingTaskStatus.php` e `VerificationSource.php`.
- `app/Exceptions/OpenTasksConflict.php`.
- Em `app/Http/Controllers/Api/`: `SystemController.php`, `ContactController.php`, `CustomerController.php`, `SupportTicketController.php`, `PendingTaskController.php`, `ReminderController.php` e `KnowledgeArticleController.php`.
- Em `app/Http/Requests/Api/`: `ApiRequest.php`, `ListRequest.php`, `SystemRequest.php`, `ContactRequest.php`, `CustomerRequest.php`, `SupportTicketRequest.php`, `TicketStatusRequest.php`, `CloseTicketRequest.php`, `PendingTaskRequest.php`, `CompleteTaskRequest.php`, `ReminderRequest.php` e `KnowledgeArticleRequest.php`.
- Em `app/Http/Resources/`: `ApiResource.php`, `SystemResource.php`, `ContactResource.php`, `CustomerResource.php`, `SupportTicketResource.php`, `PendingTaskResource.php`, `ReminderResource.php` e `KnowledgeArticleResource.php`.
- `database/migrations/2026_10_21_000008_add_verification_source_to_support_tickets_table.php`.
- `tests/Feature/Api/SupportFlowApiTest.php`.
- `docs/api.md`.

Modificados:

- `bootstrap/app.php`: registro das rotas API.
- `config/app.php`: fuso `America/Sao_Paulo`.
- `app/Models/SupportTicket.php`: novo campo preenchível e documentação dos tipos das datas.
- `app/Models/PendingTask.php` e `app/Models/Reminder.php`: documentação dos tipos das datas, compatível com o Carbon imutável já configurado no projeto.
- `docs/database.md`: referência à evolução incremental e às regras desta API.

Migrations antigas, credenciais, demais Models, `SystemBuilder`, frontend e testes anteriores foram preservados.

## Resultados da validação

- `php artisan test --compact`: **94 testes aprovados, 624 assertions** (65 casos novos da API e 29 anteriores).
- `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: aprovado, sem erros.
- `php vendor/bin/pint --test`: aprovado.
- `git diff --check`: aprovado.
- `php artisan route:list --path=api --except-vendor`: **33 registros de rotas**, incluindo PUT/PATCH agrupados pelo Laravel e as cinco operações adicionais.
- `php artisan migrate --no-interaction`: migration incremental aplicada no MySQL, batch 2.
- `php artisan migrate:status`: todas as 11 migrations aplicadas.
- Verificação pelo kernel HTTP usando MySQL real: criação dos sete recursos, relacionamento e rejeição de ciclo, conflito 409 por pendência, confirmação pelo setor, conclusão de pendência/lembrete, filtros, desativação e consulta histórica aprovados. Todos os registros de teste foram revertidos em transação, com contagens conferidas ao final. O banco não possuía registros de domínio antes dessa verificação.
- Aplicação e rollback de um passo da nova migration em SQLite em memória: aprovados; a tabela foi preservada e apenas a coluna nova foi removida na reversão. MySQL não foi afetado.

Não foi executado `migrate:fresh`, `db:wipe` ou rollback no MySQL. Não há pendência de banco ou dependência nova para consumir a API localmente.
