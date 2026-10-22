# CPF/CNPJ obrigatório no cadastro de clientes

Implementação concluída em 22/10/2026. Novos clientes precisam informar `document` como string com CPF de 11 dígitos ou CNPJ de 14 dígitos. A API remove caracteres não numéricos antes de validar e salvar, preservando zeros à esquerda. Não foram adicionadas unicidade ou validação de dígitos verificadores.

O formulário indica a obrigatoriedade, aplica máscara dinâmica de CPF/CNPJ, bloqueia documentos vazios ou com quantidade inválida de dígitos e apresenta erros locais e da API junto ao campo.

## Análise e compatibilidade

O projeto não possuía campo de documento, máscara nem factory de clientes. Foram reutilizados `CustomerRequest`, `CustomerResource`, o model `Customer` e o formulário compartilhado configurado em `resources.js`. O controller existente já persiste os campos validados, sem necessidade de alteração; não existe service de clientes neste fluxo.

A consulta somente de leitura ao banco configurado encontrou 0 clientes e confirmou a ausência da coluna `document`. A migration incremental adiciona uma coluna nullable, sem preencher, remover ou alterar registros existentes. Clientes antigos sem documento continuam acessíveis e editáveis; na edição, documentos preenchidos são normalizados e validados, enquanto omissão ou `null` permanecem permitidos.

O seeder gera documentos de teste apenas para novos clientes de demonstração. Documentos de registros existentes não são preenchidos automaticamente. Os testes que representam históricos antigos continuam utilizando clientes sem documento.

## Arquivos criados ou modificados

| Área                 | Arquivos                                                                                                                                                                                                                                                                                                         |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend              | [CustomerRequest.php](../app/Http/Requests/Api/CustomerRequest.php), [CustomerResource.php](../app/Http/Resources/CustomerResource.php), [Customer.php](../app/Models/Customer.php)                                                                                                                              |
| Banco e demonstração | [2026_10_22_000001_add_document_to_customers_table.php](../database/migrations/2026_10_22_000001_add_document_to_customers_table.php), [SupportFlowDemoSeeder.php](../database/seeders/SupportFlowDemoSeeder.php)                                                                                                |
| Frontend             | [resources.js](../frontend/src/config/resources.js), [ResourceForm.vue](../frontend/src/components/ResourceForm.vue), [FormField.vue](../frontend/src/components/FormField.vue), [forms.js](../frontend/src/utils/forms.js), [format.js](../frontend/src/utils/format.js)                                        |
| Testes Laravel       | [CustomerDocumentTest.php](../tests/Feature/Api/CustomerDocumentTest.php), [SupportFlowApiTest.php](../tests/Feature/Api/SupportFlowApiTest.php), [IntegratedWorkflowTest.php](../tests/Feature/Api/IntegratedWorkflowTest.php), [SupportFlowDemoSeederTest.php](../tests/Feature/SupportFlowDemoSeederTest.php) |
| Testes Vue           | [customer-form.spec.js](../frontend/src/__tests__/customer-form.spec.js)                                                                                                                                                                                                                                         |
| Documentação         | [api.md](api.md), [database.md](database.md), [frontend.md](frontend.md), [customer-document.md](customer-document.md)                                                                                                                                                                                           |

## Verificações

| Verificação                                                                                                       | Resultado                                                               |
| ----------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| Testes Laravel relacionados (`CustomerDocument`, `SupportFlowApi`, `IntegratedWorkflow`, `SupportFlowDemoSeeder`) | 97 testes aprovados                                                     |
| `php artisan test --compact`                                                                                      | 130 testes e 2.625 assertions aprovados                                 |
| `npm run test:unit -- --run` em `frontend/`                                                                       | 40 testes aprovados                                                     |
| `php vendor/bin/pint --test`                                                                                      | Aprovado                                                                |
| `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M`                                                | Aprovado, sem erros                                                     |
| `npm exec vp -- check --no-fmt`                                                                                   | Aprovado, sem avisos ou erros de lint                                   |
| Vite Plus nos arquivos alterados e Prettier nas fontes e configurações do frontend                                | Aprovados                                                               |
| `npm run types:check`                                                                                             | Aprovado                                                                |
| `npm run build` em `frontend/`                                                                                    | Aprovado                                                                |
| `git diff --check`                                                                                                | Aprovado                                                                |
| `npm run check` global                                                                                            | Falha de formatação preexistente em `README.md`, que não foi modificado |

Os testes cobrem ausência, valor vazio, CPF, CNPJ, normalização, quantidade inválida de dígitos, apresentação dos erros no Vue e acesso a clientes antigos sem documento. Também verificam zeros à esquerda, tipos inválidos na API, ausência de unicidade/dígitos verificadores, troca dinâmica das máscaras, edição e preservação de documentos ausentes pelo seeder.

Os testes Laravel e as migrations da suíte usaram SQLite em memória. Os testes Laravel e Vue foram repetidos fora do sandbox após bloqueios de acesso a arquivos; as execuções finais passaram.

## Aplicação da migration

A migration ainda não foi aplicada no banco da aplicação. Execute `php artisan migrate` no ambiente de destino antes de utilizar o cadastro atualizado. Nenhum dado do banco principal foi alterado nesta entrega.
