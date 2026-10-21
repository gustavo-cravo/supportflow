# SupportFlow — banco de dados (etapa 1)

> Atualização da etapa 2: a migration incremental `2026_10_21_000008_add_verification_source_to_support_tickets_table.php` adiciona `support_tickets.verification_source` (string de até 50 caracteres, opcional), sem modificar migrations anteriores ou dados existentes. As decisões provisórias sobre status, sistemas inativos, vínculos e conclusão passaram a seguir as regras da [API REST](api.md). O restante deste documento registra a entrega da etapa 1.

## Ambiente e configuração

- Laravel 13.37.0, PHP 8.5.11 e MySQL 8.4.11 identificados no ambiente local.
- O `.env` já contém configuração MySQL, preservada sem alteração das credenciais. Como `APP_KEY` estava ausente/vazia, foi gerada uma chave local com o comando padrão Laravel. Chaves existentes não são sobrescritas.
- `.env.example` usa MySQL e `supportflow` como nome sugerido do banco. `root` é apenas o usuário de exemplo do esqueleto Laravel; ajuste ao ambiente. A senha permanece vazia no exemplo, sem copiar segredos locais.
- Não foram adicionados autenticação, controllers, endpoints, telas ou notificações. Os arquivos originais do starter kit foram preservados.

## Tabelas

Todas as novas tabelas possuem `id` (bigint unsigned, chave primária), `created_at` e `updated_at`. Os nomes seguem as convenções em inglês do Eloquent: Model singular, tabela plural e chaves `*_id`.

| Tabela / Model                            | Campos específicos                                                                                                                                                                                                                                                                                    |
| ----------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `systems` / `System`                      | `name` obrigatório, `description` text opcional, `is_active` boolean obrigatório, padrão `true`                                                                                                                                                                                                       |
| `contacts` / `Contact`                    | `name` obrigatório; `phone` (até 50 caracteres), `email` e `notes` text opcionais                                                                                                                                                                                                                     |
| `customers` / `Customer`                  | `name` obrigatório; `phone` (até 50 caracteres), `email` e `notes` text opcionais                                                                                                                                                                                                                     |
| `support_tickets` / `SupportTicket`       | `system_id` e `contact_id` obrigatórios; `customer_id` e `previous_ticket_id` opcionais; `title` e `description` text obrigatórios; `status` (até 50 caracteres, padrão `open`); `resolution` text, `verification_notes` text, `verified_by`, `verified_at` datetime e `closed_at` datetime opcionais |
| `pending_tasks` / `PendingTask`           | `support_ticket_id` opcional; `description` text obrigatório; `due_at` datetime opcional; `status` (até 50 caracteres, padrão `pending`); `completion_notes` text e `completed_at` datetime opcionais                                                                                                 |
| `reminders` / `Reminder`                  | `support_ticket_id` opcional; `title` obrigatório; `description` text opcional; `scheduled_at` datetime obrigatório; `completed_at` datetime opcional                                                                                                                                                 |
| `knowledge_articles` / `KnowledgeArticle` | `system_id` opcional; `problem` e `resolution_procedure` text obrigatórios                                                                                                                                                                                                                            |

Campos string sem comprimento indicado aceitam até 255 caracteres. Datas de domínio usam datetime; timestamps de auditoria seguem o padrão Laravel. Os Models convertem datas para Carbon e `is_active` para boolean.

## Relacionamentos

| Origem               | Relacionamento Eloquent                              | Destino                                            |
| -------------------- | ---------------------------------------------------- | -------------------------------------------------- |
| Sistema              | `supportTickets()` / `knowledgeArticles()` — hasMany | Atendimentos / artigos                             |
| Contato              | `supportTickets()` — hasMany                         | Atendimentos                                       |
| Cliente              | `supportTickets()` — hasMany                         | Atendimentos                                       |
| Atendimento          | `system()` / `contact()` — belongsTo obrigatório     | Sistema / contato                                  |
| Atendimento          | `customer()` — belongsTo opcional                    | Cliente                                            |
| Atendimento          | `previousTicket()` — belongsTo opcional              | Atendimento anterior                               |
| Atendimento          | `followUpTickets()` — hasMany                        | Novos atendimentos referentes ao problema anterior |
| Atendimento          | `pendingTasks()` / `reminders()` — hasMany           | Pendências / lembretes                             |
| Pendência / lembrete | `supportTicket()` — belongsTo opcional               | Atendimento                                        |
| Artigo               | `system()` — belongsTo opcional                      | Sistema                                            |

Contato e cliente são cadastros independentes, sem chave estrangeira entre eles. Um atendimento anterior pode receber vários retornos. Cada registro mantém status, encerramento e verificação próprios, sem propagação automática de alterações.

## Integridade e índices

- Todas as associações usam chaves estrangeiras com `RESTRICT` na exclusão. Registros referenciados não podem ser removidos, inclusive quando a associação é opcional. Não há cascatas nem limpeza automática de vínculos históricos.
- Sistemas são desativados com `$system->update(['is_active' => false])`. `SystemBuilder` bloqueia `delete`, `forceDelete` e `truncate` pelo Eloquent, incluindo exclusões em lote, `destroy` e `deleteQuietly`. Sistemas inativos continuam acessíveis aos relacionamentos históricos.
- Essa proteção do Model não substitui permissões do banco: SQL direto ou `DB::table()` pode excluir sistemas sem referências. Com referências, as chaves estrangeiras impedem a exclusão. Não foram criados triggers ou usuários SQL nesta etapa.
- Nomes de sistemas, contatos e clientes têm índice; `systems.is_active` também.
- Atendimentos: índices compostos `(status, created_at)` e `(system_id, status)`.
- Pendências: índice composto `(status, due_at)`.
- Lembretes: índice composto `(completed_at, scheduled_at)` para consultas do painel.
- As chaves estrangeiras recebem índices no MySQL. Não foram impostas unicidades sobre nomes, telefone ou e-mail.

## Decisões provisórias para validação

1. Os status iniciais são `open` para atendimento e `pending` para pendência. São strings, sem enum nem lista fechada; o fluxo e as transições serão definidos em etapa posterior. `closed` e `completed` nos testes são exemplos, sem imposição como regra de negócio.
2. A confirmação da resolução usa texto livre em `verification_notes` (como foi verificada) e `verified_by` (cliente, pessoa ou setor), com data opcional `verified_at`. Não depende de usuário autenticado. A solução aplicada pode ser registrada em `resolution`.
3. Pendências podem não ter prazo. Lembretes exigem data prevista; sua conclusão é representada por `completed_at`, sem boolean redundante. Pendências também têm texto de conclusão.
4. Artigos podem ser gerais, sem sistema associado. Se a base precisar exigir sistema, será necessária uma migration posterior e revisão dos registros existentes.
5. Vínculos opcionais também usam `RESTRICT` para preservar histórico. Uma futura funcionalidade de exclusão de atendimentos, contatos ou clientes precisará definir sua política explicitamente.
6. Não há sincronização automática entre status e datas de conclusão/encerramento, nem exigência de verificação para fechar um atendimento. Essas regras não foram solicitadas.
7. `previous_ticket_id` registra o vínculo com o problema anterior. A chave estrangeira garante a existência do registro, mas não verifica semelhança do problema, ordem cronológica ou ciclos; não foram adicionadas regras sobre isso nesta etapa.
8. Não foi impedido o cadastro de atendimento em sistema inativo: o requisito define desativação, mas não determina essa restrição operacional.

## Comandos de desenvolvimento

```powershell
php artisan migrate --no-interaction
php artisan migrate:status
php artisan test
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --no-progress
```

Os testes automatizados usam exclusivamente SQLite em memória, conforme a configuração original do `phpunit.xml`, sem tocar no MySQL local. O arquivo agora também define uma chave pública fixa exclusiva de testes, para dispensar uma `APP_KEY` local durante a suíte. Essa chave não deve ser usada no `.env` local nem em produção. O teste HTTP original usa `withoutVite()` para verificar a resposta sem depender de um build frontend. Os testes cobrem obrigatoriedade e integridade das associações, preservação do histórico, bloqueio de exclusão de sistemas, retornos independentes, conclusão de pendências/lembretes e artigos com ou sem sistema.

Para uma instalação nova, crie um banco MySQL e configure os valores reais no `.env` antes de executar `migrate`. As migrations originais de usuários, sessões, cache e filas foram mantidas, pois fazem parte do starter kit e algumas são usadas pela configuração atual; isso não adiciona autenticação ao sistema.

Convenções consultadas: [migrations Laravel 13](https://laravel.com/docs/13.x/migrations), [Eloquent Laravel 13](https://laravel.com/docs/13.x/eloquent) e [relacionamentos Eloquent](https://laravel.com/docs/13.x/eloquent-relationships).

## Arquivos entregues

Criados:

- `app/Models/System.php`
- `app/Models/Contact.php`
- `app/Models/Customer.php`
- `app/Models/SupportTicket.php`
- `app/Models/PendingTask.php`
- `app/Models/Reminder.php`
- `app/Models/KnowledgeArticle.php`
- `app/Models/Builders/SystemBuilder.php`
- `database/migrations/2026_10_21_000001_create_systems_table.php`
- `database/migrations/2026_10_21_000002_create_contacts_table.php`
- `database/migrations/2026_10_21_000003_create_customers_table.php`
- `database/migrations/2026_10_21_000004_create_support_tickets_table.php`
- `database/migrations/2026_10_21_000005_create_pending_tasks_table.php`
- `database/migrations/2026_10_21_000006_create_reminders_table.php`
- `database/migrations/2026_10_21_000007_create_knowledge_articles_table.php`
- `tests/Feature/SupportFlowDatabaseTest.php`
- `docs/database.md`

Modificados: `.env` (somente geração da chave ausente, arquivo ignorado pelo Git), `.env.example`, `phpunit.xml` e `tests/Feature/ExampleTest.php`.

## Verificações executadas em 21/10/2026

| Verificação                                                            | Resultado                                                              |
| ---------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `php -v` / `php artisan --version`                                     | PHP 8.5.11 / Laravel 13.37.0                                           |
| Conexão PDO MySQL e `SELECT VERSION()`                                 | MySQL 8.4.11; banco inicialmente sem tabelas                           |
| Geração condicional de `APP_KEY` via `key:generate`                    | Chave local gerada porque estava ausente/vazia                         |
| `php artisan migrate --no-interaction`                                 | 10 migrations aplicadas: 3 originais e 7 do domínio                    |
| `php artisan migrate:status`                                           | Todas com status `Ran`                                                 |
| `php artisan test`                                                     | 29 testes aprovados, 68 assertions; inclui 27 casos do domínio         |
| `php vendor/bin/pint --dirty`, seguido de `php vendor/bin/pint --test` | Arquivos formatados; verificação final aprovada                        |
| `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M`     | Aprovado sem erros                                                     |
| `migrate`, `migrate:rollback`, `migrate` em SQLite em memória          | Aplicação, reversão completa e reaplicação aprovadas; MySQL preservado |
| `git diff --check`                                                     | Aprovado                                                               |

As primeiras execuções encontraram restrições do sandbox ao ler/escrever arquivos PHP e acessar MySQL; os comandos necessários foram repetidos fora dele, com aprovação. A análise estática inicialmente excedeu 128 MB e passou com 512 MB. O teste HTTP original inicialmente falhou por falta de `APP_KEY` e de build Vite; as correções de configuração dos testes descritas acima resolveram ambas as falhas. Não há pendência de credenciais, conexão ou migrations nesta etapa.
