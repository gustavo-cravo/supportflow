# Backend de métricas dos atendimentos etapa 3

A contagem atual de vínculos e os denominadores estão em [Múltiplas subcategorias por atendimento](ticket-multiple-subcategories.md). As regras de contagem desta etapa descrevem a seleção singular.

A API estatística calcula indicadores, distribuições por sistema e classificação, evolução mensal e os atendimentos que compõem cada resultado. Todas as consultas usam os dados atuais de `support_tickets` e são somente de leitura. O Dashboard operacional e o frontend permanecem com seus comportamentos anteriores.

## Endpoints

Todos os endpoints abaixo são `GET` e usam os mesmos filtros.

| Caminho                      | Resultado                                                                                            |
| ---------------------------- | ---------------------------------------------------------------------------------------------------- |
| `/api/metrics/summary`       | Total, encerrados, não encerrados, sistemas distintos e sem classificação.                           |
| `/api/metrics/systems`       | Volume, encerrados e não encerrados por sistema.                                                     |
| `/api/metrics/categories`    | Ocorrências por categoria, participação no total filtrado e distribuição por sistema.                |
| `/api/metrics/subcategories` | Ocorrências por categoria/subcategoria, participação dentro da categoria e distribuição por sistema. |
| `/api/metrics/monthly`       | Série mensal em ordem cronológica.                                                                   |
| `/api/metrics/tickets`       | Atendimentos paginados responsáveis pelo resultado filtrado.                                         |

Os agregados retornam `data` e `meta.filters`, com os filtros efetivamente aplicados. As distribuições e a série mensal também retornam `meta.total`, a soma dos volumes apresentados. A listagem de atendimentos reutiliza `SupportTicketResource` e a paginação atual, com `data`, `links`, `meta.total`, `meta.current_page` e demais metadados, acrescentando `meta.filters`.

## Parâmetros

| Parâmetro                | Valores e comportamento                                                                                                                                                 |
| ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `period`                 | `this_month`, `last_30_days`, `last_3_months`, `last_6_months`, `this_year`, `all` ou `custom`. Padrão: `last_6_months`.                                                |
| `date_from`, `date_to`   | Datas `YYYY-MM-DD`, obrigatórias somente em `custom`. A final deve ser igual ou posterior à inicial.                                                                    |
| `system_id`              | ID de um sistema existente, inclusive inativo. Omitir para todos.                                                                                                       |
| `status`                 | `all` (padrão), `closed`, `not_closed`, `open`, `in_progress`, `waiting` ou `resolved`.                                                                                 |
| `problem_category_id`    | ID de uma categoria existente, inclusive inativa. Omitir para todas.                                                                                                    |
| `problem_subcategory_id` | ID de uma subcategoria existente, inclusive inativa. Pode ser usado sem filtro de categoria; quando ambos são enviados, devem corresponder ao mesmo cadastro principal. |
| `classification`         | `all` (padrão), `unclassified` ou `without_subcategory`. Os dois últimos permitem consultar os grupos sem categoria e sem subcategoria.                                 |
| `page`, `per_page`       | Exclusivos de `/tickets`. Página mínima 1; tamanho padrão 15, de 1 a 100 registros.                                                                                     |

`classification=unclassified` não pode acompanhar uma categoria ou subcategoria específica. `classification=without_subcategory` exige categoria preenchida nos registros, subcategoria nula e não aceita um filtro de subcategoria específica; pode acompanhar `problem_category_id`.

As referências precisam existir, mas sua situação atual ou disponibilidade atual em um sistema não impede uma consulta histórica. Por exemplo, sistema e categoria continuam sendo filtros válidos após a desabilitação de sua associação. Uma combinação válida sem ocorrências retorna totais zerados e listas vazias.

Parâmetros inválidos e combinações incompatíveis retornam HTTP 422 no formato atual de validação do SupportFlow, com mensagens em `errors` por campo. Não existem operações de criação ou atualização nas rotas de métricas.

## Interpretação dos períodos

O campo de referência é **`support_tickets.created_at`**, a abertura do atendimento, independentemente de `closed_at`. O projeto grava e interpreta essas datas no fuso **America/Sao_Paulo**, definido em `config/app.php`. Os limites e os meses são calculados nesse mesmo fuso, sem depender das tabelas de fusos nomeados do MySQL.

Os períodos relativos incluem o dia atual inteiro:

| Período         | Início                                  | Final inclusivo      |
| --------------- | --------------------------------------- | -------------------- |
| `this_month`    | Primeiro dia do mês atual               | Hoje                 |
| `last_30_days`  | Hoje menos 29 dias                      | Hoje                 |
| `last_3_months` | Primeiro dia do mês atual menos 2 meses | Hoje                 |
| `last_6_months` | Primeiro dia do mês atual menos 5 meses | Hoje                 |
| `this_year`     | 1º de janeiro do ano atual              | Hoje                 |
| `all`           | Sem limite inicial                      | Sem limite final     |
| `custom`        | Data inicial informada                  | Data final informada |

Assim, em 23/11/2026, o padrão cobre **01/06/2026 a 23/11/2026**, e os últimos 30 dias cobrem **25/10/2026 a 23/11/2026**. Os períodos de meses são meses de calendário, incluindo o atual; os últimos 30 dias são 30 datas locais, e não uma janela móvel de 720 horas.

O SQL usa `created_at >= início às 00:00` e `created_at < início do dia seguinte à data final`. Isso inclui todo o último dia e permite o uso de índices na coluna, sem aplicar uma função a `created_at` no filtro. As datas personalizadas aceitam de 01/01/1000 a 30/12/9999; o limite superior mantém representável o dia seguinte exclusivo.

Na evolução mensal, períodos limitados incluem todos os meses entre suas datas, com zero quando necessário. Em `all`, são retornados apenas os meses com ocorrências, sem inventar limites para o histórico. Os períodos relativos terminam hoje, portanto não criam meses futuros nem incluem datas posteriores ao dia atual. O modo `all` não aplica restrição temporal.

## Lógica de contabilização

- Cada linha de atendimento vale uma ocorrência. Atendimentos anteriores e retornos são registros independentes e contam uma vez cada.
- Apenas `status=closed` é encerrado. `open`, `in_progress`, `waiting` e **`resolved`** são não encerrados, conforme o enum existente. Não foram criados status novos.
- Os mesmos filtros são aplicados aos indicadores, a todos os agrupamentos e à listagem paginada.
- Sistemas, categorias e subcategorias inativos continuam participando quando possuírem ocorrências correspondentes aos filtros.
- Categoria nula forma o grupo **Sem classificação**, com `problem_category_id=null`.
- Categoria preenchida e subcategoria nula formam **Sem subcategoria** dentro daquela categoria. Na consulta de subcategorias, os atendimentos sem categoria ficam no grupo **Sem classificação**, com os dois IDs nulos.
- A participação da categoria é `ocorrências / total filtrado × 100`. A participação da subcategoria é `ocorrências / total filtrado daquela categoria × 100`.
- Todos os filtros afetam os denominadores. Ao consultar somente uma subcategoria, ela representa 100% da categoria dentro desse recorte. Os percentuais têm duas casas de precisão; somas percentuais podem variar por arredondamento, enquanto as contagens conciliam exatamente.
- Cadastros sem ocorrências no recorte não geram linhas artificiais de volume zero. A série mensal limitada é a exceção necessária para os futuros gráficos.

Os rankings são ordenados por volume decrescente e IDs para desempate; grupos nulos ficam após IDs reais em empates. As distribuições por sistema seguem a mesma ordenação. A listagem de atendimentos usa abertura decrescente e ID decrescente como desempate.

## Estruturas JSON

Resumo real observado na validação local com `period=all`:

```json
{
    "data": {
        "total": 16,
        "closed": 7,
        "not_closed": 9,
        "systems_count": 4,
        "unclassified": 16
    },
    "meta": {
        "filters": {
            "period": "all",
            "date_from": null,
            "date_to": null,
            "timezone": "America/Sao_Paulo",
            "system_id": null,
            "status": "all",
            "problem_category_id": null,
            "problem_subcategory_id": null,
            "classification": "all"
        }
    }
}
```

Os exemplos seguintes são **linhas fictícias** dos arrays `data`, para ilustrar o contrato dos agrupamentos. Não representam o estado do banco local.

Linha por sistema:

```json
{
    "system_id": 1,
    "system_name": "Indicar",
    "is_active": true,
    "total": 6,
    "closed": 3,
    "not_closed": 3
}
```

Linha por categoria, em um recorte com 13 atendimentos:

```json
{
    "problem_category_id": 1,
    "category_name": "Falha de acesso",
    "is_active": false,
    "total": 7,
    "percentage": 53.85,
    "systems": [
        {
            "system_id": 1,
            "system_name": "Indicar",
            "is_active": true,
            "total": 3
        },
        {
            "system_id": 2,
            "system_name": "iLube",
            "is_active": false,
            "total": 3
        },
        { "system_id": 3, "system_name": ".way", "is_active": true, "total": 1 }
    ]
}
```

Linha por subcategoria, dentro da categoria com sete ocorrências:

```json
{
    "problem_category_id": 1,
    "category_name": "Falha de acesso",
    "category_is_active": false,
    "problem_subcategory_id": 1,
    "subcategory_name": "Sessão expirada",
    "is_active": false,
    "total": 4,
    "category_total": 7,
    "percentage": 57.14,
    "systems": [
        {
            "system_id": 1,
            "system_name": "Indicar",
            "is_active": true,
            "total": 2
        },
        {
            "system_id": 2,
            "system_name": "iLube",
            "is_active": false,
            "total": 1
        },
        { "system_id": 3, "system_name": ".way", "is_active": true, "total": 1 }
    ]
}
```

Exemplo fictício completo da série mensal, com parte dos metadados de filtro omitida nesta ilustração:

```json
{
    "data": [
        { "month": "2026-06", "total": 2 },
        { "month": "2026-07", "total": 0 },
        { "month": "2026-08", "total": 1 },
        { "month": "2026-09", "total": 1 },
        { "month": "2026-10", "total": 1 },
        { "month": "2026-11", "total": 4 }
    ],
    "meta": { "total": 9 }
}
```

No grupo sem subcategoria, `problem_subcategory_id` e `is_active` são nulos e `subcategory_name` é **Sem subcategoria**. No grupo sem categoria, o identificador e a situação da categoria também são nulos e seu nome é **Sem classificação**.

O endpoint `/tickets` usa os campos completos de `SupportTicketResource`, incluindo `id`, `system`, `contact`, `customer`, `problem_category` e `problem_subcategory`. O ID permite acessar `/atendimentos/{id}` ou a consulta API `/api/support-tickets/{id}`. Essa consulta é interna ao SupportFlow; os agregados não incluem identificação individual de atendimentos. Não há exportação nesta etapa.

## Exemplos de requisição local

Com o Laravel disponível em `http://127.0.0.1:8000`, execute no PowerShell:

```powershell
curl.exe "http://127.0.0.1:8000/api/metrics/summary"
curl.exe "http://127.0.0.1:8000/api/metrics/summary?period=all"
curl.exe "http://127.0.0.1:8000/api/metrics/systems?period=this_year&status=not_closed"
curl.exe "http://127.0.0.1:8000/api/metrics/categories?period=custom&date_from=2026-11-01&date_to=2026-11-30"
curl.exe "http://127.0.0.1:8000/api/metrics/monthly?period=last_6_months"
curl.exe "http://127.0.0.1:8000/api/metrics/tickets?period=all&classification=unclassified&page=1&per_page=15"
```

Para consultas de classificação, substitua os IDs abaixo por cadastros existentes. As referências podem estar inativas, mas uma subcategoria deve pertencer à categoria informada:

```powershell
curl.exe "http://127.0.0.1:8000/api/metrics/categories?period=custom&date_from=2026-11-01&date_to=2026-11-30&system_id=1&problem_category_id=1"
curl.exe "http://127.0.0.1:8000/api/metrics/subcategories?period=all&problem_category_id=1"
curl.exe "http://127.0.0.1:8000/api/metrics/tickets?period=all&problem_subcategory_id=1&page=1&per_page=15"
curl.exe "http://127.0.0.1:8000/api/metrics/tickets?period=all&problem_category_id=1&classification=without_subcategory"
```

## Organização e desempenho

`TicketMetricsFilters` resolve o período uma única vez por requisição e aplica a mesma lógica ao Query Builder dos agregados e ao Eloquent da listagem. `TicketMetrics` concentra os cálculos reutilizáveis; o controller apenas valida e apresenta os resultados.

Os agregados usam `COUNT`, `SUM`, `COUNT(DISTINCT ...)` e `GROUP BY` diretamente no banco. Não carregam atendimentos completos em memória: somente as linhas já agrupadas são organizadas em distribuições e recebem percentuais. As junções são exclusivamente de cada atendimento aos seus cadastros de sistema/categoria/subcategoria por chave primária. As tabelas de associação a múltiplos sistemas não entram nessas queries, evitando multiplicação de ocorrências.

Cada agregado requer uma query de cálculo. A validação de identificadores pode acrescentar consultas de existência. A listagem é paginada e carrega seus relacionamentos antecipadamente, sem N+1. Os resultados são calculados a cada consulta, sem cache estatístico que possa ficar desatualizado.

Migration aditiva: `2026_11_23_000003_add_ticket_metrics_indexes.php`. Ela acrescenta somente índices em `support_tickets`:

- `(created_at)`: período sem outro filtro.
- `(system_id, created_at)`: sistema e período.
- `(problem_category_id, created_at)`: categoria e período.
- `(problem_subcategory_id, created_at)`: subcategoria e período.

O índice existente `(status, created_at)` continua atendendo os recortes por status e período. Os índices anteriores por sistema/status ou subcategoria/categoria não possuem `created_at` na posição necessária para esses novos recortes. A reversão restaura o índice automático de chave estrangeira de categoria caso o InnoDB o tenha substituído pelo novo índice composto.

Para outro ambiente:

```powershell
php artisan migrate --path=database/migrations/2026_11_23_000003_add_ticket_metrics_indexes.php
```

## Validação executada

- `php artisan test --compact`: **257 testes aprovados, 3.528 assertions**, incluindo **64 cenários específicos** de métricas e índices. A suíte usa SQLite em memória, conforme o isolamento existente.
- Cobertura de contagens, percentuais, múltiplos sistemas, nomes iguais em categorias diferentes, estados históricos inativos, grupos nulos, todos os status, filtros combinados, períodos relativos e personalizados, datas locais inclusivas, ano bissexto, instantes UTC na mudança de mês, meses zerados, paginação, consistência entre agrupamentos, validações, ausência de N+1 e consultas agregadas em SQL.
- Comparação dos registros de 11 tabelas e da resposta do Dashboard operacional antes/depois das consultas, sem alterações.
- PHPStan e Pint aprovados; `git diff --check` aprovado.
- MySQL **8.4.11** local: os seis endpoints foram validados com histórico completo e período personalizado, totalizando 12 cenários de requisição. Os totais conciliaram; as queries registradas dos endpoints foram somente de leitura e as 11 tabelas funcionais permaneceram iguais.
- Migration aplicada e ciclo de reversão/reaplicação dos índices validado também no MySQL. O plano `EXPLAIN` confirmou a disponibilidade e utilização de índice na consulta de período; isso não constitui um benchmark de volume.

## Arquivos da etapa

Criados:

- `app/Http/Requests/Api/TicketMetricsRequest.php`
- `app/Support/TicketMetricsFilters.php`
- `app/Services/TicketMetrics.php`
- `app/Http/Controllers/Api/TicketMetricsController.php`
- `database/migrations/2026_11_23_000003_add_ticket_metrics_indexes.php`
- `tests/Feature/Api/TicketMetricsTest.php`
- `docs/ticket-metrics.md`

Alterado: `routes/api.php`, para registrar o grupo de rotas `metrics.*`.

O serviço está disponível para a futura aba Métricas e para relatórios consolidados. Frontend, Dashboard operacional, formulários e regras de gravação dos atendimentos não foram modificados nesta etapa.
