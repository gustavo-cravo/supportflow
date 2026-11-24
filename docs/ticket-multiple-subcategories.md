# Múltiplas subcategorias por atendimento

Cada atendimento tem um sistema, uma categoria opcional e uma lista opcional de subcategorias dessa categoria. O seletor pesquisa e pagina as opções elegíveis para o sistema e a categoria e exibe cada seleção como uma etiqueta removível. Trocar a categoria limpa os vínculos anteriores. Ao trocar o sistema, o formulário mantém a classificação para revisão e exige a correção das incompatibilidades antes de salvar.

Vínculos históricos podem permanecer ou ser removidos individualmente ao manter o sistema e a categoria originais. Cada associação nova exige uma categoria e uma subcategoria ativas e disponíveis para um sistema ativo. A troca de sistema revalida todos os vínculos que serão mantidos.

## Aplicar a atualização

1. Faça um backup do banco e pause os processos que gravam atendimentos, inclusive versões antigas da aplicação.
2. Na raiz do repositório, confira o banco configurado e execute:

    ```powershell
    php artisan migrate:status
    php artisan migrate
    ```

3. Inicie a API e o frontend atualizados. Em desenvolvimento, use `php artisan serve` na raiz e `npm.cmd run dev` em `frontend`. Para gerar os assets da SPA, rode `npm.cmd run build` em `frontend`.

A migration `2026_11_24_000001_create_problem_subcategory_support_ticket_table.php` cria a tabela `problem_subcategory_support_ticket`. A chave primária composta impede duplicatas. As chaves estrangeiras compostas obrigam o atendimento e a subcategoria a compartilhar a mesma categoria. Os índices atendem a consulta nos dois sentidos.

A cópia dos vínculos antigos e sua conferência ocorrem em uma transação e preservam inclusive os cadastros inativos. A migration confere cada vínculo original e o total de vínculos copiados. Um vínculo legado sem categoria interrompe a operação para revisão. A coluna singular é conservada e recebe o menor ID da lista durante as gravações, na mesma transação que atualiza o atendimento e os vínculos.

O rollback da migration verifica se cada atendimento possui no máximo um vínculo e se a coluna legada coincide com a tabela intermediária. Havendo múltiplos vínculos ou divergência, a reversão é bloqueada. Para voltar à versão antiga com dados múltiplos, preserve um backup completo e planeje a conversão, pois o esquema antigo comporta apenas um vínculo por atendimento.

## Contrato da API

`POST /api/support-tickets` e `PUT/PATCH /api/support-tickets/{id}` aceitam a lista `problem_subcategory_ids` de inteiros positivos. Por exemplo:

```json
{ "problem_category_id": 2, "problem_subcategory_ids": [4, 7, 9] }
```

- Uma lista vazia remove todos os vínculos. Omitir a lista preserva os vínculos se a categoria permanecer a mesma. Ao trocar ou limpar a categoria com a lista omitida, os vínculos antigos são removidos.
- A API retorna `problem_subcategory_ids` e `problem_subcategories` nas consultas e respostas principais de atendimento, ordenados por ID. Itens inválidos na lista recebem HTTP 422 com erros como `problem_subcategory_ids.0`.
- Os campos singulares `problem_subcategory_id` e `problem_subcategory` representam o primeiro registro pela ordem de ID. A escrita singular continua disponível para atendimentos com até um vínculo. Em atendimentos com múltiplos vínculos, repetir o ID legado preserva a lista, e qualquer outra alteração singular recebe 422. Envie apenas uma das formas na mesma requisição.
- O filtro `problem_subcategory_id` da listagem e dos endpoints de métricas localiza atendimentos por qualquer vínculo correspondente.

## Métricas e relatórios

O resumo, os agrupamentos por sistema e categoria, a evolução mensal e as listagens contam cada atendimento uma vez. Na distribuição por subcategoria, cada vínculo rende uma ocorrência. O resumo retorna `subcategory_occurrences` e a distribuição retorna `meta.occurrences`, ambos sem os grupos de ausência de classificação. O `meta.total` da distribuição inclui os grupos `Sem subcategoria` e `Sem classificação`.

O percentual de uma subcategoria tem como denominador os atendimentos distintos da categoria no recorte. Um atendimento com três subcategorias conta uma vez no resumo e três vezes na distribuição, por isso os percentuais da categoria podem somar mais de 100%. Ao filtrar por uma subcategoria, a distribuição e os relatórios exibem apenas as ocorrências do vínculo selecionado. As listas de detalhamento mostram cada atendimento uma vez.

O PDF e o Excel usam os mesmos agregados e apresentam a quantidade de vínculos. Os relatórios contêm informações estatísticas consolidadas.

## Validação manual

1. Abra um atendimento novo, selecione sistema e categoria e procure duas subcategorias. Selecione ambas e confira as etiquetas ao salvar e reabrir o atendimento.
2. Remova uma etiqueta, adicione outra e salve. Em seguida, use **Limpar subcategorias** e confira que a categoria permaneceu. Troque a categoria e confirme que as etiquetas anteriores saíram.
3. Cadastre três subcategorias em um atendimento de teste. Use um recorte que inclua somente esse atendimento e confira o total de um atendimento e três ocorrências em **Dashboard → Métricas**. Abra o detalhamento de uma subcategoria e compare o PDF e o Excel.
4. Abra um dos cadastros rápidos durante o preenchimento e confira o retorno ao mesmo rascunho após salvar ou cancelar.

## Arquivos da mudança

- **Novos** — `database/migrations/2026_11_24_000001_create_problem_subcategory_support_ticket_table.php`, `tests/Feature/TicketSubcategoryMigrationTest.php` e este guia.
- **Gravação e API** — `app/Actions/SaveSupportTicket.php`, `app/Actions/ValidateTicketClassification.php`, `app/Models/SupportTicket.php`, `app/Models/ProblemSubcategory.php`, `SupportTicketController.php`, `ListRequest.php`, `SupportTicketRequest.php` e `SupportTicketResource.php` em `app/Http`.
- **Métricas e exportação** — `app/Http/Controllers/Api/TicketMetricsController.php`, `app/Support/TicketMetricsFilters.php`, `app/Services/TicketMetrics.php`, `app/Services/Reports/TicketMetricsReport.php`, `app/Services/Reports/XlsxTicketMetricsReport.php` e `resources/views/reports/ticket-metrics.blade.php`.
- **Vue** — `TicketFormView.vue`, `TicketDetailView.vue`, `TicketsView.vue`, `TicketClassificationFields.vue`, `RelationSelect.vue`, `RecordTable.vue`, `MetricsDashboard.vue`, `config/resources.js`, `utils/format.js` e `assets/main.css` em `frontend/src`.
- **Seeders e regressões** — `SupportFlowValidationSeeder.php`, `SupportFlowValidationReference.php`, `TicketClassificationTest.php`, `TicketMetricsTest.php`, `TicketMetricsExportTest.php`, `SupportFlowValidationSeederTest.php`, `ticket-classification.spec.js` e `ticket-quick-registration.spec.js`.
- **Documentação anterior** — `docs/ticket-classification.md`, `docs/ticket-metrics.md` e `docs/statistical-reports.md` apontam para o contrato atual.
