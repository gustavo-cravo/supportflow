# Classificação de atendimentos etapa 2

Os formulários atuais de cadastro e edição de atendimentos incluem **Categoria do problema** e **Subcategoria do problema**, ambas opcionais. A descrição do problema e os demais campos permanecem no mesmo formulário. A seção **Informações** dos detalhes apresenta os nomes da classificação, incluindo registros inativos, sem edição direta.

## Cadastro e edição

- Selecione um sistema para habilitar a escolha da categoria. A lista apresenta apenas categorias ativas com associação habilitada ao sistema ativo.
- A subcategoria permanece desabilitada enquanto não houver sistema e categoria. Suas opções precisam estar ativas, pertencer à categoria selecionada e estar habilitadas para aquele sistema.
- As opções são carregadas ao abrir a seleção, com pesquisa, paginação, carregamento e tentativa de recuperação em caso de erro. Contextos alterados cancelam consultas anteriores e retiram suas opções da lista.
- Os cadastros já retornados pelo atendimento ou pela seleção são reutilizados para os nomes e a verificação de compatibilidade, evitando consultas individuais duplicadas.
- Alterar sistema ou categoria mantém os valores selecionados visíveis. Havendo incompatibilidade, o formulário informa o problema e bloqueia o envio até uma escolha válida ou remoção explícita. Outros campos preenchidos são preservados.
- O usuário pode remover uma subcategoria sem remover a categoria, ou salvar sem classificação. Uma subcategoria exige uma categoria.

Em um atendimento antigo, os dois campos começam vazios e podem ser preenchidos posteriormente. Nenhum preenchimento retroativo é automático.

## Preservação histórica

O backend compara o sistema, a categoria e a subcategoria efetivos com os valores persistidos do atendimento. Manter os mesmos vínculos durante uma edição permite preservar categorias e subcategorias posteriormente desativadas e disponibilidades posteriormente desabilitadas. Isso também se aplica à edição com o sistema original inativo e às operações existentes de status e encerramento.

Remover explicitamente a subcategoria pode manter a categoria histórica inativa. Uma nova categoria, uma nova subcategoria ou uma mudança de sistema exige verificar novamente as situações e disponibilidades correspondentes. Uma nova subcategoria não pode aproveitar uma categoria histórica inativa ou uma associação principal desabilitada.

Nos detalhes, a ausência de categoria aparece como **Não classificado**. Uma categoria sem subcategoria apresenta **Não informada** no campo de subcategoria. Cadastros inativos mantêm seus nomes e a indicação **Inativa**. Os modais de consulta rápida de contato e cliente continuam disponíveis.

## Migration e integridade

Migration aditiva: `database/migrations/2026_11_23_000002_add_problem_classification_to_support_tickets_table.php`.

Ela adiciona somente a `support_tickets`:

- `problem_category_id`, anulável, com chave estrangeira para `problem_categories` e exclusão restrita.
- `problem_subcategory_id`, anulável, com chave estrangeira para `problem_subcategories` e exclusão restrita.
- Uma chave estrangeira composta de subcategoria/categoria para a combinação correspondente em `problem_subcategories`, garantindo a propriedade da subcategoria quando ambos os campos estão preenchidos.

Não há exclusão em cascata, atualização de dados antigos nem alteração de outras tabelas. A API impede subcategoria sem categoria e valida as associações aos sistemas. A verificação de novas classificações é executada dentro da transação de gravação do atendimento, usando os bloqueios de categoria, subcategoria e sistema na ordem do gerenciamento existente.

A migration foi aplicada no MySQL local **8.4.11**. Foram comparados os valores das colunas preexistentes em 11 tabelas funcionais antes e depois: os valores permaneceram iguais, e a contagem de atendimentos classificados após a migration foi zero.

Para aplicar em outro ambiente:

```powershell
php artisan migrate --path=database/migrations/2026_11_23_000002_add_problem_classification_to_support_tickets_table.php
```

A migration depende das estruturas da Etapa 1 já existentes.

## Endpoints adaptados

| Endpoint | Comportamento |
| --- | --- |
| `POST /api/support-tickets` | Aceita os dois identificadores opcionais. |
| `PUT/PATCH /api/support-tickets/{id}` | Preserva campos omitidos, valida alterações e aceita remoção explícita com `null`. |
| `GET /api/support-tickets` e `GET /api/support-tickets/{id}` | Retornam identificadores e os cadastros associados, reutilizando `ProblemClassificationResource`. |
| `GET /api/support-tickets/{id}/related` | Retorna também os cadastros de classificação dos registros relacionados. |
| `GET /api/problem-categories?selectable=1&system_id={id}` | Filtra opções válidas para um atendimento. Exige sistema e respeita categoria, sistema e associação ativos. |
| `GET /api/problem-subcategories?selectable=1&system_id={id}&problem_category_id={id}` | Exige o contexto completo e valida a disponibilidade da categoria principal e da subcategoria. |

As listagens de gerenciamento da Etapa 1 mantêm seu comportamento quando `selectable` não é informado. As rotas existentes de status e encerramento preservam as classificações; seus contratos permanecem iguais. Não foram criadas rotas novas.

Exemplo dos campos adicionais:

```json
{"problem_category_id": 2, "problem_subcategory_id": 4}
```

Para remover ambos:

```json
{"problem_category_id": null, "problem_subcategory_id": null}
```

Se um atendimento já possuir subcategoria, mudar apenas a categoria não apaga a subcategoria: uma combinação incompatível retorna HTTP 422 em `errors.problem_subcategory_id`. Envie uma nova subcategoria compatível ou `null` explicitamente. As demais validações seguem o formato atual de erros por campo do SupportFlow.

## Testes executados

- `php artisan test --compact`: **193 testes aprovados, 3.100 assertions**, incluindo 30 cenários novos de integração. A suíte usa SQLite em memória, conforme o isolamento existente.
- `npm.cmd run test:unit -- --run`, em `frontend`: **104 testes aprovados**, incluindo 20 cenários novos. Inclui os testes anteriores de consulta rápida, gerenciamento de classificações, formulários e encerramento.
- `npm.cmd run build`, em `frontend`: aprovado.
- PHPStan: aprovado, sem erros. Pint, Prettier e `git diff --check`: aprovados.
- Navegador com API real e SQLite isolado de QA: criação classificada, filtragem por sistema/categoria, bloqueio da troca incompatível de sistema, substituição explícita de subcategoria, preservação da descrição, manutenção de classificação após desativação da categoria, detalhes, modais de contato/cliente e formulário em 390×844. Console final sem erros ou avisos e sem transbordamento horizontal.
- A comparação dos registros anteriores do ambiente de QA confirmou sua preservação após a validação visual.

## Arquivos da etapa

Criados:

- `database/migrations/2026_11_23_000002_add_problem_classification_to_support_tickets_table.php`
- `app/Actions/ValidateTicketClassification.php`
- `frontend/src/components/TicketClassificationFields.vue`
- `tests/Feature/Api/TicketClassificationTest.php`
- `frontend/src/__tests__/ticket-classification.spec.js`
- `docs/ticket-classification.md`

Alterados:

- `app/Actions/SaveSupportTicket.php`
- `app/Http/Requests/Api/SupportTicketRequest.php`
- `app/Models/SupportTicket.php`
- `app/Models/ProblemCategory.php`
- `app/Models/ProblemSubcategory.php`
- `app/Http/Resources/SupportTicketResource.php`
- `app/Http/Controllers/Api/SupportTicketController.php`
- `app/Http/Requests/Api/ListRequest.php`
- `app/Http/Controllers/Api/ProblemCategoryController.php`
- `app/Http/Controllers/Api/ProblemSubcategoryController.php`
- `frontend/src/components/RelationSelect.vue`
- `frontend/src/config/resources.js`
- `frontend/src/views/TicketFormView.vue`
- `frontend/src/views/TicketDetailView.vue`
- `tests/Feature/Api/ProblemClassificationTest.php`: a verificação da Etapa 1 continua garantindo registros inalterados e atendimentos sem preenchimento automático, agora com os campos adicionados pela Etapa 2.

Esta entrega está limitada à classificação dos atendimentos. Métricas, indicadores, gráficos, relatórios e classificação automática permanecem para as próximas etapas.
