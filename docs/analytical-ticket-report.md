# Relatório analítico de atendimentos

Os resultados das suítes, da comparação em navegador, das amostras PDF e da validação em MySQL estão no [relatório de auditoria final](analytical-ticket-report-audit.md).

## Etapa 1/5

O backend oferece catálogo, listagem de elegíveis, validação da seleção e preparação de conteúdo. O relatório analítico reúne atendimentos individuais conforme os campos escolhidos. Os fluxos existentes de PDF estatístico, Excel estatístico e PDF individual conservam suas rotas e contratos.

`AnalyticalTicketReportRequest` reutiliza a validação de `TicketMetricsRequest`. `AnalyticalTicketReport` aplica `TicketMetricsFilters` sobre o conjunto de atendimentos e resolve a seleção. `AnalyticalTicketReportProjection` seleciona as colunas e relações necessárias e projeta os valores aprovados. O agrupamento por modelo usa `VehicleModelGroup`, a mesma normalização de Métricas. O catálogo e o objeto de configuração concentram os identificadores, as dependências e os padrões.

## Endpoints

| Método | Caminho                                        | Resultado                                                                                                                                                               |
| ------ | ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/api/metrics/analytical-report/configuration` | Catálogo em `data`, com seções, campos, padrões, opções de apresentação e `required_fields` por agrupamento.                                                            |
| GET    | `/api/metrics/analytical-report/tickets`       | Elegíveis em `data`, ordenados do mais recente para o mais antigo. `meta.total` representa o recorte inteiro. `meta.filters` apresenta os filtros resolvidos.           |
| POST   | `/api/metrics/analytical-report/configuration` | Valida a emissão e retorna `data.configuration`, `data.title`, `data.eligible_count` e `data.selected_count`. O retorno contém os filtros resolvidos em `meta.filters`. |
| POST   | `/api/metrics/analytical-report/pdf`           | Revalida a emissão e devolve os bytes do PDF e a contagem efetivamente incluída em `X-Report-Selected-Count`.                                                           |

A listagem usa `page=1` e `per_page=20` por padrão, com até 100 registros por página. Cada item traz o ID para seleção, título, data de abertura para distinguir títulos iguais, sistema, categoria, nomes de subcategorias e marca/modelo quando houver veículo. A prévia da descrição do modelo na listagem tem até 160 unidades de largura textual antes das reticências. A preparação interna conserva o modelo completo. As respostas de sucesso usam `Cache-Control: private, no-store`.

O recorte segue os [filtros de Métricas](ticket-metrics.md), incluindo período, sistema, categoria, subcategoria, classificação, status, presença veicular, placa, marca, modelo e linha. O padrão de período continua sendo `last_6_months`, resolvido em `America/Sao_Paulo`. Sistemas inativos com histórico permanecem elegíveis. `group_vehicle_id` e `group_vehicle_model` são parâmetros da navegação pelas linhas de Métricas. Eles ficam fora do recorte global do relatório analítico. Os campos de paginação valem somente para a listagem.

## Campos permitidos

Cada campo tem rótulo, identificador, tipo, largura, seleção padrão e mensagem de ausência. `long_text` ocupa toda a largura. Os demais tipos usam a opção de colunas.

| Seção        | Campo                                                  | Origem no cadastro                                                           | Tipo        |
| ------------ | ------------------------------------------------------ | ---------------------------------------------------------------------------- | ----------- |
| `ticket`     | `title` — Título                                       | `SupportTicket.title`                                                        | `short`     |
| `ticket`     | `system` — Sistema                                     | `system.name`                                                                | `short`     |
| `ticket`     | `contact` — Contato                                    | `contact.name`                                                               | `short`     |
| `ticket`     | `customer` — Cliente                                   | `customer.name`                                                              | `short`     |
| `ticket`     | `previous_ticket` — Atendimento anterior               | `previousTicket.id` e `previousTicket.title`, na referência `#0001 · Título` | `reference` |
| `ticket`     | `description` — Descrição do problema                  | `SupportTicket.description`                                                  | `long_text` |
| `ticket`     | `problem_category` — Categoria do problema             | `problemCategory.name`                                                       | `short`     |
| `ticket`     | `problem_subcategories` — Subcategorias do problema    | Nomes de `problemSubcategories`, na ordem do relacionamento existente        | `list`      |
| `vehicle`    | `vehicle_plate` — Placa                                | `vehicle.plate`                                                              | `short`     |
| `vehicle`    | `vehicle_brand` — Marca                                | `vehicle.brand.name`                                                         | `short`     |
| `vehicle`    | `vehicle_model` — Modelo completo                      | `vehicle.model_description`                                                  | `long_text` |
| `vehicle`    | `vehicle_line` — Linha veicular                        | Valor do enum `VehicleLine` de `vehicle.vehicle_line`                        | `option`    |
| `resolution` | `resolution` — Solução aplicada                        | `SupportTicket.resolution`                                                   | `long_text` |
| `resolution` | `verification_source` — Fonte da confirmação           | Valor salvo de `SupportTicket.verification_source`                           | `option`    |
| `resolution` | `verified_at` — Data e horário da confirmação          | `SupportTicket.verified_at`, formato local `Y-m-d H:i:s`                     | `datetime`  |
| `resolution` | `verified_by` — Confirmado por                         | `SupportTicket.verified_by`                                                  | `short`     |
| `resolution` | `verification_notes` — Como a resolução foi verificada | `SupportTicket.verification_notes`                                           | `long_text` |

A referência anterior continua sendo um único campo, mesmo quando aponta a um registro fora do recorte atual. Ela mantém o número exibido no PDF individual e o título do vínculo. Os demais dados e as seções desse atendimento não são carregados como conteúdo da referência.

## Contrato

No POST, envie os filtros na raiz do JSON. `configuration` contém as escolhas da emissão.

| Propriedade em `configuration` | Valores                                                                         | Padrão                  |
| ------------------------------ | ------------------------------------------------------------------------------- | ----------------------- |
| `selection.mode`               | `all`, `all_except`, `only`                                                     | `all`                   |
| `selection.ids`                | Lista de inteiros JSON positivos                                                | `[]`                    |
| `sections`                     | Mapa por seção, com `enabled` booleano e `fields` como lista de identificadores | Três seções e 17 campos |
| `grouping`                     | `none`, `system`, `category`, `brand`, `model`                                  | `none`                  |
| `order`                        | `newest`, `oldest`, `title`                                                     | `newest`                |
| `empty_fields`                 | `smart`, `show`, `hide`                                                         | `smart`                 |
| `title`                        | `{"mode":"automatic","text":null}` ou `{"mode":"custom","text":"…"}`            | `automatic`             |
| `subtitle`                     | Texto de até 180 caracteres ou nulo                                             | Nulo                    |
| `layout.pagination`            | `automatic`, `compact`, `individual`                                            | `automatic`             |
| `layout.columns`               | `automatic`, `one`, `two`                                                       | `automatic`             |
| `layout.spacing`               | `compact`, `normal`, `expanded`                                                 | `normal`                |

Quando `sections` está ausente, todas as seções e campos ficam ativos. Quando o mapa é enviado, as seções omitidas ficam desativadas. Dentro de uma seção ativa, a ausência de `fields` ativa todos os seus campos. Uma lista vazia ou `enabled: false` elimina a seção. Campos repetidos são reduzidos a uma ocorrência e mantêm a ordem do catálogo. A API rejeita chaves desconhecidas, campos de outra seção e configurações sem nenhum campo.

`all` representa todos os elegíveis sem enviar IDs. `all_except` envia os IDs excluídos. `only` envia os IDs incluídos. Os dois últimos exigem uma lista não vazia. IDs repetidos são deduplicados. Cada ID é validado no próprio recorte, com o mesmo erro genérico para registro inexistente ou fora do recorte. Excluir todo o recorte ou validar uma emissão com zero elegíveis retorna HTTP 422. A listagem de recorte vazio continua retornando 200 e `meta.total: 0`.

O título automático usa **Relatório analítico de atendimentos** ou **Relatório analítico de erros de indicação** conforme a subcategoria ou categoria filtrada quando seu campo também foi selecionado. Um qualificador elegível pode vir da marca filtrada e, em seguida, do sistema, sob a mesma condição. Placa, descrição usada para pesquisar o modelo, cliente e contato ficam fora da composição automática. O título personalizado aceita até 120 caracteres e o subtítulo aceita até 180. A regra compartilhada com o PDF estatístico rejeita HTML, caracteres de controle e padrões de placas, referências individuais e dados de contato. Para restaurar o título automático, envie `{"mode":"automatic","text":null}`.

### Exemplo com dados sintéticos

`GET /api/metrics/analytical-report/tickets?period=all&vehicle_plate=HFS-4456&status=open&per_page=20` retorna o atendimento aberto da placa pesquisada na fixture de teste. O trecho preserva o item e os metadados de paginação.

```json
{
    "data": [
        {
            "id": 1,
            "title": "TITULO_PRIVADO_987",
            "created_at": "2026-11-20T09:00:00-03:00",
            "system": "Sistema A",
            "category": "Identificação",
            "subcategories": ["Modelo divergente", "Ano divergente"],
            "vehicle": { "brand": "Marca Alfa", "model": "Série 1.6 LX 2007" }
        }
    ],
    "meta": { "current_page": 1, "per_page": 20, "total": 1 }
}
```

O POST de configuração pode usar a mesma fixture.

```json
{
    "period": "all",
    "configuration": {
        "selection": { "mode": "only", "ids": [1, 1] },
        "sections": { "ticket": { "enabled": true, "fields": ["description"] } }
    }
}
```

A resposta 200 devolve a seleção deduplicada.

```json
{
    "data": {
        "configuration": {
            "selection": { "mode": "only", "ids": [1] },
            "sections": {
                "ticket": { "enabled": true, "fields": ["description"] }
            },
            "grouping": "none",
            "order": "newest",
            "empty_fields": "smart",
            "title": { "mode": "automatic", "text": null },
            "subtitle": null,
            "layout": {
                "pagination": "automatic",
                "columns": "automatic",
                "spacing": "normal"
            }
        },
        "title": "Relatório analítico de atendimentos",
        "eligible_count": 7,
        "selected_count": 1
    }
}
```

Enviar `grouping: "model"` com essa seleção de campos retorna 422 em `configuration.grouping`. Enviar `ids: []` no modo `only` retorna 422 em `configuration.selection.ids`.

## Agrupamento e ordem

Os grupos são formados sobre o conjunto efetivo após as inclusões ou exclusões. Cada atendimento pertence a um único grupo. A ordem de apresentação dos grupos segue a primeira ocorrência na ordem escolhida. Dentro do grupo, a ordem é a mesma da consulta.

| Agrupamento | Campos que o usuário precisa selecionar | Grupos sem preenchimento                                        |
| ----------- | --------------------------------------- | --------------------------------------------------------------- |
| `none`      | Nenhum adicional                        | Um grupo sem rótulo.                                            |
| `system`    | `system`                                | Sistema ausente recebe mensagem contextual.                     |
| `category`  | `problem_category`                      | Sem classificação.                                              |
| `brand`     | `vehicle_brand`                         | Sem identificação veicular.                                     |
| `model`     | `vehicle_brand` e `vehicle_model`       | Sem identificação veicular ou **Marca / Modelo não informado**. |

O modelo agrupa descrições equivalentes por marca usando a identidade normalizada das métricas veiculares. O rótulo usa a descrição integral do primeiro atendimento na ordem do grupo. Os IDs e chaves normalizadas de agrupamento ficam internos. A ordem cronológica usa `created_at` e, em seguida, ID no mesmo sentido. A ordem alfabética usa `LOWER(title)` na ordenação do banco e ID crescente no empate. A colação determina a ordenação de acentos.

## Campos vazios e dados preparados

`show` inclui cada campo selecionado com a mensagem correspondente do catálogo. `hide` elimina campos ausentes e seções que ficariam vazias. `smart` oculta cliente, atendimento anterior, subcategorias e detalhes opcionais da confirmação quando vazios. Categoria recebe **Sem classificação**. Uma seção veicular ativa sem vínculo recebe uma única mensagem **Sem veículo vinculado**. Em um veículo existente, modelo vazio recebe **Modelo não informado**. Campos de solução e verificação vazios recebem suas mensagens de ausência. Strings com valor `"0"` continuam visíveis.

`AnalyticalTicketReport::prepare($filters, $configuration)` entrega a projeção ao gerador. A configuração deve vir do Form Request validado. O serviço revalida os IDs no recorte na mesma transação em que prepara o conteúdo.

- `controls` contém filtros normalizados e configuração para controle da emissão.
- `document` contém título, subtítulo, data de emissão, fuso, período, resumo dos filtros próprio para impressão, contagem, agrupamento, layout e `groups`. Cada grupo tem `label` e `tickets`. Cada atendimento tem `sections`. Cada seção tem `label`, `absence` e `fields`.
- Cada campo traz `label`, `type`, `width`, `value` e `absence`. Valores são texto, lista de textos ou nulo. Mensagens de ausência ficam separadas do valor. Um atendimento com todos os campos selecionados vazios em modo `hide` pode ter `sections: []`.

O template recebe `document`. Descrição, solução, verificação e modelo são preservados com seus caracteres e quebras de linha. `controls` contém IDs e termos de filtro de uso técnico. A lista de candidatos e a pré-validação retornam somente os dados necessários ao modal.

## Segurança e escala

As rotas adotam o perímetro da API de atendimentos, que atualmente não tem login nem política de autorização por registro. A checagem de elegibilidade aplica os filtros existentes, inclusive suas combinações válidas, e não cria permissões de usuário. Os campos são projetados por lista explícita. As consultas carregam relações em lote e a preparação percorre os atendimentos em blocos de 200, independentemente do tamanho de página da interface. O objeto final representa a emissão inteira em memória. Nenhuma configuração é persistida e nenhuma migration foi acrescentada.

## Etapa 2/5 — Modal de configuração

Em **Dashboard → Métricas**, abra **Relatório analítico (PDF)**. **Exportar PDF estatístico** abre a personalização e a pré-visualização estatísticas. **Exportar Excel** abre a emissão da planilha estatística.

| Aba          | Operações                                                                                                                         |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------- |
| Atendimentos | Conferir os filtros da abertura, marcar registros e percorrer as páginas de 20. **Selecionar todos** vale para o recorte inteiro. |
| Conteúdo     | Selecionar os 17 campos do catálogo, expandir seções, escolher o tratamento de vazios e restaurar os padrões de conteúdo.         |
| Apresentação | Editar título e subtítulo, restaurar o título do servidor, escolher agrupamento, ordem, paginação, colunas e espaçamento.         |

O Dashboard mantém o rascunho em memória durante a sessão. `useAnalyticalReport.js` reúne os estados de catálogo, listagem e geração. O modal consome `defaults` e `required_fields` do catálogo e usa os rótulos e opções retornados por ele. A composição reutiliza `AppModal`, `FormField`, `PaginationBar`, `StatePanel` e a função de resumo dos filtros de Métricas. `AnalyticalReportTickets.vue`, `AnalyticalReportContent.vue` e `AnalyticalReportPresentation.vue` implementam as abas.

Na abertura, o Dashboard copia os filtros aplicados. O modal usa as datas resolvidas pelo Dashboard como período personalizado durante a sessão. A listagem, a sugestão de título e a emissão compartilham essa cópia. Se o Dashboard receber outro recorte, o modal informa a mudança e preserva o recorte da emissão. Para usar os novos filtros, feche e reabra. Fechar ou cancelar descarta o rascunho e interrompe as requisições pendentes no cliente.

O rascunho começa em `all`. Desmarcar um atendimento registra a exclusão em `all_except`. Ao limpar a seleção, o modal entra em `only` com zero IDs, estado que bloqueia a validação. A troca de página consulta só os registros daquela página e conserva as inclusões e exclusões em memória. Alterações detectadas no total do recorte são sinalizadas. O backend confirma a elegibilidade de cada ID.

O modal mostra os estados de seleção completa, parcial e vazia das seções. Agrupamentos com requisitos ausentes ficam indisponíveis para nova seleção. Se o usuário desmarcar um campo exigido pelo agrupamento ativo, o modal conserva a escolha, destaca os campos faltantes e bloqueia a validação até o ajuste.

Após obter o catálogo e identificar atendimentos elegíveis, o modal consulta o endpoint de validação para obter o título automático do backend. Se a seleção mudar em um campo de título ligado ao recorte, o modal solicita uma sugestão atualizada e descarta respostas antigas. A edição manual muda o modo para `custom` e permanece enquanto a sugestão é atualizada. Restaurar o automático volta à sugestão vigente. Em caso de falha, a aba Apresentação oferece nova tentativa.

As ações **Pré-visualizar** e **Exportar PDF** estão no rodapé, acessíveis pelas três abas. O endpoint JSON continua atendendo à sugestão inicial de título e aos clientes da Etapa 1. A emissão recalcula o título automático com os filtros e os campos efetivamente selecionados.

### Arquivos e resultados da Etapa 2

- Criados `MetricsAnalyticalReportModal.vue`, `AnalyticalReportTickets.vue`, `AnalyticalReportContent.vue` e `AnalyticalReportPresentation.vue` em `frontend/src/components/metrics/`, além de `useAnalyticalReport.js` e `analytical-report.spec.js`.
- Modificados `MetricsDashboard.vue` para a nova ação e o rascunho da sessão, e `metrics-pdf-configuration.spec.js` para o novo rótulo do botão estatístico. Os guias de Métricas e exportação estatística refletem esse rótulo.
- Laravel aprovou 544 testes e 6.465 asserções. Vue aprovou 260 testes, incluindo 13 casos novos. PHPStan, Pint, Prettier, tipagem e lint passaram. O build de produção do frontend compilou 108 módulos.
- A inspeção no navegador usou a API real com o SQLite sintético de QA, atualizado com as migrations existentes. A exclusão foi preservada entre páginas e a API confirmou 50 dos 51 elegíveis. As três abas foram conferidas a 390 px, sem rolagem horizontal no painel e com o rodapé acessível. O foco retornou ao botão de abertura após cancelar.
- As capturas estão em `storage/framework/testing/evidence/analytical-report/` com os nomes `atendimentos.jpg`, `conteudo.jpg` e `apresentacao.jpg`.

## Etapa 3/5 — Gerador de PDF

`PdfAnalyticalTicketReport::generate(TicketMetricsFilters $filters, AnalyticalTicketReportConfiguration $configuration, ?CarbonImmutable $issuedAt = null): string` retorna os bytes do documento. A configuração é construída pelo `AnalyticalTicketReportRequest` depois da validação. O gerador reutiliza a seleção elegível, os filtros de Métricas, a projeção dos 17 campos e as regras de agrupamento e ordenação das etapas anteriores. O template Blade consome `document`. A seleção vazia, os IDs inelegíveis e a ausência de campos válidos são rejeitados antes da renderização.

O PDF usa a biblioteca Dompdf 3.1.6 já instalada, em A4 retrato e com fonte DejaVu Sans. O template principal define o cabeçalho, a hierarquia, a paleta e as margens alinhadas aos relatórios do SupportFlow. A parcial de campos imprime texto escapado, listas e mensagens de ausência. O rodapé do Dompdf informa **Página X de Y** na margem inferior. A emissão usa o fuso `America/Sao_Paulo`. A data fixa opcional serve para reproduzir os cenários de teste.

O título original, quando selecionado e preenchido, aparece no bloco do atendimento junto da numeração sequencial. A numeração é global e segue a ordem preparada, inclusive na mudança de grupo. As seções usam a projeção de vazios descrita acima. Quando todos os campos do atendimento ficam ocultos, o PDF conserva sua numeração com uma mensagem discreta. As subcategorias são listadas dentro do mesmo atendimento. A referência ao atendimento anterior é impressa apenas como o valor do campo escolhido.

| Paginação    | Comportamento                                                                                               |
| ------------ | ----------------------------------------------------------------------------------------------------------- |
| `automatic`  | Mantém juntos os atendimentos com conteúdo curto quando cabe na página. Os blocos extensos podem continuar. |
| `compact`    | Usa o espaço restante para iniciar novos atendimentos e permite que o conteúdo continue na página seguinte. |
| `individual` | Inicia cada atendimento após o primeiro em uma nova página. O primeiro começa abaixo do cabeçalho inicial.  |

As páginas que começam no meio de um atendimento mostram **Atendimento NN - Continuação**. O rótulo do grupo também aparece quando aplicável. O cabeçalho original conserva o rótulo integral. A repetição na margem superior pode usar reticências para caber em uma linha. O valor original do campo Modelo permanece completo no corpo. A identificação da continuação é derivada dos quadros de texto renderizados pelo Dompdf.

O planejador organiza campos curtos em uma coluna, em pares de duas colunas ou, no modo automático, em pares quando a seção tem pelo menos três campos curtos compatíveis. Valores excepcionalmente longos fluem em largura integral, pois o Dompdf mantém cada linha de tabela indivisível. Descrição, solução, verificação e modelo sempre usam a largura integral. Compacto, normal e ampliado controlam espaçamento entre campos, seções e atendimentos, independentemente da paginação e do tamanho da fonte.

### Privacidade e limitações

O cabeçalho assinala filtros de campos desmarcados sem copiar seu valor. Placa e termo de pesquisa de modelo aparecem apenas como indicação de filtro. Seus valores registrados podem ser incluídos como campos selecionados. Textos livres selecionados são impressos integralmente e podem conter menções a dados omitidos em outros campos. O relatório analítico inclui dados individuais e segue o perímetro atual de acesso, descrito em **Segurança e escala**. HTML, JavaScript e PHP presentes em textos são tratados como conteúdo literal. O Dompdf tem acesso remoto desativado. O gerador mantém o documento em memória e usa o temporário do sistema para recursos auxiliares da biblioteca.

O Dompdf mantém a estrutura HTML e o PDF na memória durante a geração síncrona. A preparação usa consultas em lotes, preserva todos os registros selecionados e deixa o consumo variar com o tamanho do documento. A cobertura de caracteres depende da fonte instalada. A combinação de uma coluna, espaçamento ampliado e paginação individual pode deixar uma página de continuação curta quando os campos finais ultrapassam a página anterior, como no cenário E.

### Demonstrações e verificação

Gere os oito arquivos com `php -d memory_limit=768M tests/Support/generate-analytical-ticket-pdfs.php`. O roteiro usa `APP_ENV=testing`, SQLite em memória e registros sintéticos. Os arquivos ficam em `storage/app/private/pdf/analitico/`, um diretório local ignorado pelo Git e fora da área pública.

| Cenário                                                | Arquivo                       | Atendimentos | Páginas | Geração (ms) | Pico adicional (MiB) | Consultas `SELECT` |
| ------------------------------------------------------ | ----------------------------- | -----------: | ------: | -----------: | -------------------: | -----------------: |
| A. Conteúdo completo                                   | `A-completo.pdf`              |            3 |       3 |          397 |                 24,2 |                 11 |
| B. Setor de validação                                  | `B-setor-validacao.pdf`       |            3 |       3 |          278 |                  0,9 |                  9 |
| C. Vazios inteligentes                                 | `C-vazios-inteligentes.pdf`   |            3 |       3 |          368 |                  9,1 |                 11 |
| D. Paginação e espaçamento compactos                   | `D-paginacao-compacta.pdf`    |           10 |       8 |          494 |                 10,1 |                  8 |
| E. Paginação individual e espaçamento ampliado         | `E-paginacao-individual.pdf`  |            3 |      12 |          350 |                  2,5 |                  8 |
| F. Textos e modelo extensos                            | `F-descricoes-extensas.pdf`   |            1 |       9 |          334 |                 14,4 |                  8 |
| G. Agrupamento por modelo com dois registros excluídos | `G-grande-volume.pdf`         |           34 |      18 |        2.079 |                 21,2 |                  7 |
| H. Descrição, solução e verificação                    | `H-personalizacao-minima.pdf` |            2 |       1 |          505 |                 16,9 |                  3 |

Medição local de uma execução sequencial com SQLite em memória. Cada pico é medido a partir da memória em uso no início daquela geração. O processo reaproveita bibliotecas e recursos carregados nas emissões anteriores. As medições abrangem a preparação e a renderização.

As 57 páginas foram renderizadas com Poppler e inspecionadas. A extração independente do texto confirmou a numeração, as margens, a presença dos trechos longos de F, as exclusões de G e a ocultação dos valores estruturados de H. Em uma validação manual, confira a referência opcional em A, a mudança de grupo em B, as ausências em C, o início de dois atendimentos na mesma página em D e os cabeçalhos de continuação em E e F.

Os testes específicos podem ser executados com `php -d memory_limit=768M vendor/bin/pest tests/Feature/AnalyticalTicketPdfTest.php --compact`. Eles cobrem a estrutura do PDF, os 17 campos, a seleção, os filtros, todos os modos de agrupamento, a ordem, os vazios, as três opções de paginação, colunas e espaçamento, as quebras de textos extensos, a privacidade e a constância das consultas dentro de um lote.

A suíte específica aprovou 26 casos e 184 asserções. A suíte Laravel completa aprovou 570 testes e 6.649 asserções. A suíte Vue aprovou 260 testes em 16 arquivos. PHPStan, Pint, Prettier, tipagem e lint passaram. O build de produção do frontend compilou 108 módulos e informou os tempos dos plugins do Vite.

| Alteração                    | Arquivos                                                                                                                                          |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Serviços novos               | `app/Services/Reports/PdfAnalyticalTicketReport.php`, `AnalyticalTicketPdfLayout.php` e `AnalyticalTicketReportContext.php`                       |
| Templates novos              | `resources/views/reports/analytical-ticket-report.blade.php` e `partials/analytical-ticket-field.blade.php`                                       |
| Testes e demonstrações novos | `tests/Feature/AnalyticalTicketPdfTest.php`, `tests/Support/AnalyticalTicketPdfFixture.php` e `tests/Support/generate-analytical-ticket-pdfs.php` |
| Serviços atualizados         | `app/Services/Reports/AnalyticalTicketReport.php` e `AnalyticalTicketReportProjection.php`                                                        |
| Documentação atualizada      | `docs/analytical-ticket-report.md`                                                                                                                |

## Etapa 4/5 — Pré-visualização e exportação

`POST /api/metrics/analytical-report/pdf?disposition=inline` devolve o PDF para pré-visualização. Use `disposition=attachment` para exportação direta, que é também a opção padrão. O corpo JSON mantém o contrato da seção **Contrato**, com filtros na raiz e escolhas em `configuration`. O servidor consulta os registros e rejeita chaves desconhecidas, incluindo valores individuais enviados pelo cliente.

| Cabeçalho da resposta HTTP 200 | Valor                                                                          |
| ------------------------------ | ------------------------------------------------------------------------------ |
| `Content-Type`                 | `application/pdf`                                                              |
| `Content-Disposition`          | `inline` ou `attachment`, com `supportflow-relatorio-analitico-AAAA-MM-DD.pdf` |
| `Cache-Control`                | `private, no-store`                                                            |
| `X-Content-Type-Options`       | `nosniff`                                                                      |
| `X-Report-Selected-Count`      | Total de atendimentos incluídos naquela emissão                                |

A data do arquivo segue `America/Sao_Paulo` e o nome independe do título, dos filtros e dos valores individuais. O controller usa o `AnalyticalTicketReportRequest` e `PdfAnalyticalTicketReport::generateDocument()`. Esse método devolve os bytes e a contagem da mesma projeção. O backend revalida a seleção a cada geração. A API devolve o conteúdo diretamente na resposta e não cria arquivos públicos ou persistentes.

O modal reutiliza `MetricsPdfPreview`, `AppModal`, `saveDownload`, o cliente binário da API e as notificações. A pré-visualização usa um Blob e uma URL de objeto para o PDF efetivamente recebido. O visualizador nativo define navegação, zoom e impressão. Se o visualizador incorporado estiver indisponível, o arquivo continua acessível em nova aba ou pelo botão **Baixar PDF**. Um iframe em branco pode exigir essa alternativa mesmo quando o navegador informa suporte. O download da pré-visualização usa o mesmo Blob e conserva a data de emissão sem nova solicitação.

**Voltar às configurações** descarta o PDF, libera sua URL e preserva o rascunho, a aba ativa, a página da lista e a expansão das seções. Uma alteração do rascunho enquanto há um PDF invalida a pré-visualização. **Exportar PDF** gera uma vez e inicia o download local. O modal fecha após iniciar o download com sucesso e fica aberto se ocorrer uma falha. Sair pelo botão de fechar, por Esc ou por **Cancelar** descarta a sessão. O cliente aborta as requisições pendentes e ignora respostas atrasadas. O processamento síncrono já iniciado no servidor pode continuar.

As duas ações compartilham o estado de geração. O cliente impede pedidos concorrentes e suprime cliques repetidos no download da pré-visualização durante um segundo. As URLs do download e da abertura em outra aba têm um intervalo curto para o navegador consumir o arquivo antes da liberação. A emissão usa um único PDF em memória. O tempo limite do cliente para a resposta binária é de 120 segundos. Limites do PHP e do servidor também se aplicam, e as medições locais da Etapa 3 representam somente o cenário sintético.

`all` e `all_except` são recalculados com os registros elegíveis na hora da emissão. `only` revalida cada ID diante do recorte capturado. IDs usados como exclusão também precisam continuar elegíveis, conforme o contrato existente. Quando o total confirmado difere do resumo do modal, o aviso aparece na pré-visualização ou na notificação do download direto. O HTTP 422 mantém a configuração, identifica campos inválidos e oferece atualizar a listagem quando a seleção deixou de ser elegível. Falhas de rede, tempo limite, resposta binária inválida e erros do servidor permitem nova tentativa. A falha do gerador registra apenas o tipo da exceção.

A API permanece no perímetro atual de acesso aos atendimentos. Antes de disponibilizar a aplicação com níveis de acesso distintos, será necessário definir autorização por atendimento e aplicá-la tanto à listagem como à emissão. Os campos desmarcados ficam fora da projeção. Textos livres selecionados são preservados e podem mencionar dados de outros campos. A seleção de campos não equivale à anonimização.

### Testes e validação pelo Dashboard

`tests/Feature/AnalyticalTicketPdfTest.php` cobre o endpoint com o gerador real, os cabeçalhos, os três modos de seleção, o recorte com mais de uma página, as alterações de elegibilidade, as rejeições e a falha de geração. `frontend/src/__tests__/analytical-report.spec.js` e `report-download.spec.js` cobrem a emissão, o retorno, a identidade do Blob, a liberação das URLs, o fluxo direto, o cancelamento, os erros e a resposta binária.

Na sessão de QA com SQLite sintético, o download pela pré-visualização incluiu nove atendimentos em cinco páginas. A extração do texto confirmou o título, o subtítulo, os campos desmarcados e as duas exclusões. A exportação direta com os padrões do mesmo recorte incluiu 11 atendimentos em seis páginas. O retorno preservou as escolhas e o cancelamento restaurou os padrões na abertura seguinte. Em 390 px, os dois estados do modal mantiveram suas ações no rodapé e a largura útil sem rolagem horizontal. O navegador de QA exibiu a área incorporada em branco e restringiu a navegação para a URL de objeto em outra aba. O download serviu como alternativa e os PDFs foram verificados fora do visualizador incorporado. Em uma rodada instrumentada no SQLite de QA, a pré-visualização e seu download produziram um único POST. O arquivo baixado teve o mesmo SHA-256 do corpo da resposta.

1. Aplique um recorte em **Dashboard → Métricas** e abra **Relatório analítico (PDF)**. Desmarque um atendimento e confira a seleção ao trocar de página.
2. Em **Conteúdo**, desmarque alguns campos. Em **Apresentação**, informe título e subtítulo, escolha agrupamento com os campos exigidos e ajuste a paginação.
3. Use **Pré-visualizar**, confira o PDF e selecione **Voltar às configurações**. Confira a aba ativa, as inclusões ou exclusões e as opções preservadas.
4. Altere a seleção, gere outra pré-visualização e use **Baixar PDF**. No painel de rede do navegador, o download não deve originar outro `POST` ao endpoint de PDF.
5. Reabra o modal e use **Exportar PDF** diretamente. Abra de novo, cancele e confira que a abertura seguinte usa os padrões. Em tela estreita, confira as ações de retorno, download e fechamento.

## Verificação local da Etapa 1

Use `php -d memory_limit=768M vendor/bin/pest tests/Feature/Api/AnalyticalTicketReportTest.php --compact` para os casos específicos. O bootstrap usa SQLite em memória e fixtures sintéticas. A suíte compara os elegíveis com o Dashboard, e cobre seleção, contagem, agrupamento, ordenação, projeção, textos, campos vazios e carregamento em lote.

A suíte Laravel completa aprovou 544 testes e 6.465 asserções, incluindo 58 casos do relatório analítico. A suíte Vue aprovou 247 testes. PHPStan, Pint, Prettier, Vite Plus e a verificação de tipos passaram. O caso de preparação com 208 atendimentos selecionados em dois lotes executou sete consultas de leitura com título, sistema e subcategorias projetados.

| Componente                      | Arquivo                                                                                                                 |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| Rotas                           | `routes/api.php`                                                                                                        |
| Controller                      | `app/Http/Controllers/Api/AnalyticalTicketReportController.php`                                                         |
| Contrato HTTP                   | `app/Http/Requests/Api/AnalyticalTicketReportRequest.php`                                                               |
| Listagem                        | `app/Http/Resources/AnalyticalTicketSelectionResource.php`                                                              |
| Catálogo e configuração efetiva | `app/Support/AnalyticalTicketReportCatalog.php` e `app/Support/AnalyticalTicketReportConfiguration.php`                 |
| Consulta e projeção             | `app/Services/Reports/AnalyticalTicketReport.php` e `app/Services/Reports/AnalyticalTicketReportProjection.php`         |
| Validação textual compartilhada | `app/Support/ReportHeadingText.php`, usada pelo Form Request analítico e por `TicketMetricsPdfConfigurationRequest.php` |
| Testes                          | `tests/Feature/Api/AnalyticalTicketReportTest.php`                                                                      |
