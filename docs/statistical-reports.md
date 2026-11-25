# Exportação de relatórios estatísticos

A contabilização atual das ocorrências e dos atendimentos nos relatórios está em [Múltiplas subcategorias por atendimento](ticket-multiple-subcategories.md).

O Dashboard oferece o PDF gerencial personalizável e a planilha analítica. O GET legado de PDF continua disponível para clientes da API. A [auditoria final](statistical-reports-validation.md) reúne a matriz de conformidade, os resultados e os documentos sintéticos usados na validação.

## Como utilizar

1. Abra **Dashboard → Métricas** e aplique os filtros desejados.
2. Clique em **Exportar PDF** para personalizar seções, indicadores, formatos, agrupamentos e título. Confira o recorte em **Filtros aplicados**.
3. Use **Exportar PDF** no modal para baixar diretamente ou **Pré-visualizar** para conferir as páginas. Na pré-visualização, **Baixar PDF** salva o arquivo exibido e **Voltar à configuração** preserva todas as escolhas.
4. Para a planilha, clique em **Exportar Excel**, escolha se quer **Incluir estatísticas veiculares** e clique em **Gerar relatório**.

O download só é iniciado depois de receber um arquivo válido. A mensagem confirma que o download foi iniciado, sem afirmar que o usuário já o salvou. O botão impede envios duplicados enquanto a geração está em andamento. Cancelar, ESC ou clicar fora fecha o modal, cancela a espera no navegador e mantém a análise e seus filtros; isso não interrompe uma requisição que o servidor já estiver processando.

Opções alteradas nos filtros, mas ainda não aplicadas, não entram no relatório. O modal usa o mesmo objeto de filtros **aplicados** da aba, sem um segundo formulário de filtragem. O botão fica desabilitado durante carregamento ou erro das métricas e permite relatórios de resultados vazios.

## Endpoint e parâmetros

```http
GET /api/metrics/export?format=pdf&period=last_6_months
GET /api/metrics/export?format=xlsx&period=custom&date_from=2026-11-01&date_to=2026-11-30&system_id=1&status=closed&problem_category_id=1&problem_subcategory_id=1
GET /api/metrics/export?format=pdf&period=all&vehicle_line=heavy&include_vehicle_statistics=1
```

Os identificadores do exemplo devem ser substituídos pelos registros existentes no ambiente. Para validar localmente:

```powershell
curl.exe --fail --output supportflow_relatorio.pdf "http://127.0.0.1:8000/api/metrics/export?format=pdf&period=last_6_months"
curl.exe --fail --output supportflow_relatorio.xlsx "http://127.0.0.1:8000/api/metrics/export?format=xlsx&period=all"
```

`GET /api/metrics/export` recebe `format=pdf` para o PDF legado e `format=xlsx` para Excel. O Dashboard usa o GET para Excel e o [POST configurável](#pré-visualização-e-download-etapa-45) para PDF. Os filtros e a validação continuam em `TicketMetricsRequest`, com os parâmetros documentados em [ticket-metrics.md](ticket-metrics.md). As datas personalizadas continuam inclusivas e os períodos relativos usam `America/Sao_Paulo`.

O endpoint utiliza o mesmo grupo de acesso da API atual. Não foi introduzida uma regra de autenticação diferente. Parâmetros inválidos retornam os erros 422 usuais. Falhas de geração retornam HTTP 500 em JSON, sem expor caminhos internos ou entregar um arquivo incompleto como sucesso.

`include_vehicle_statistics` aceita `1` ou `0` na URL e tem padrão `0`. A opção controla as seções adicionais. Os filtros veiculares seguem as validações da aba Métricas e afetam também o conteúdo tradicional. Quando há busca por placa, o documento registra somente que o filtro foi aplicado. Placas cadastradas que apareçam em rótulos descritivos também são omitidas.

Arquivos são retornados diretamente como attachments com os tipos MIME correspondentes, `Cache-Control: private, no-store` e `X-Content-Type-Options: nosniff`. Os nomes usam a data de geração: `supportflow_relatorio_YYYY-MM-DD.pdf` ou `.xlsx`. O frontend verifica o tipo de conteúdo, rejeita arquivos vazios e restringe os nomes de download. Os objetos URL são liberados após iniciar o download.

## Configuração do PDF gerencial (Etapa 1/5 da reestruturação)

| Método e rota                                | Resultado                                                                   |
| -------------------------------------------- | --------------------------------------------------------------------------- |
| `GET /api/metrics/export/pdf/configuration`  | Catálogo em `data`, com rótulos, seleções, formatos, dependências e padrões |
| `POST /api/metrics/export/pdf/configuration` | Validação de JSON e retorno da configuração efetiva em `data`               |
| `GET /api/metrics/export?format=pdf`         | Download legado. A presença de `configuration` nessa rota retorna HTTP 422  |

Nos POSTs de configuração e geração, os filtros de [Métricas](ticket-metrics.md#parâmetros) ficam na raiz do JSON, ao lado de `configuration`. A validação de `TicketMetricsRequest` inclui período, classificação e filtros veiculares. A rota de configuração retorna somente a seleção efetiva em JSON com `Cache-Control: private, no-store`. A rota binária converte os filtros validados com `TicketMetricsFilters::fromValidated()` e aplica a seleção à emissão.

### Seleção e padrões

- `configuration.sections` é um mapa por identificador de seção. Cada seção fornecida exige `enabled` com um booleano JSON. Seu mapa opcional `indicators` contém objetos com `enabled` e, quando compatível, `format`.
- Seções e indicadores omitidos ou com `enabled: false` ficam desativados. Seções sem seleção efetiva são descartadas. O POST exige ao menos um indicador ou agrupamento efetivo. A seleção independe do valor calculado e continua válida mesmo para zero ou um recorte vazio.
- O formato omitido assume `automatic`. Os identificadores disponíveis são `automatic`, `indicator`, `horizontal_bar`, `vertical_bar`, `doughnut`, `table`, `text` e `descriptive_group`.
- Em `vehicle_problems.grouping`, selecione `primary` com `by` e `format` opcional. O campo `additional` é uma lista ordenada com até dois objetos da mesma forma. As dimensões são `brand`, `model` (marca e modelo) e `line`. Cada dimensão pode aparecer uma vez. Selecionar complementos em uma seção ativa ou informar uma lista adicional não vazia exige um agrupamento principal. O agrupamento principal pode ser selecionado sozinho.
- `title.mode` aceita `automatic` (padrão) e `custom`. Em `custom`, `title.text` é obrigatório e aceita até 120 caracteres. O título personalizado é preservado ao mudar filtros e seções. Para restaurar o modo automático, omita `title` ou envie `{"mode":"automatic","text":null}`.
- `subtitle` é opcional, aceita até 180 caracteres e tem padrão `null`. A API apara espaços nas extremidades e converte texto vazio em `null`. Título e subtítulo aceitam texto simples de uma linha. Marcadores HTML, inclusive os codificados como entidades HTML, caracteres de controle e trechos com formato de placa são rejeitados.
- O JSON efetivo mantém apenas as seções e os indicadores habilitados, preenche os formatos padrão e sempre traz `title` e `subtitle`. Um agrupamento sem complementos tem `indicators: []`. A ordem informada em `grouping.additional` é preservada. Chaves desconhecidas e formatos incompatíveis retornam HTTP 422 mesmo dentro de uma opção desativada.

### Indicadores e formatos compatíveis

**Numérico** corresponde a `automatic`, `indicator` e `table`. **Série** corresponde a `automatic`, `horizontal_bar`, `vertical_bar` e `table`.

| Seção              | Indicadores                                                                | Formatos                                  |
| ------------------ | -------------------------------------------------------------------------- | ----------------------------------------- |
| `summary`          | `total`, `closed`, `not_closed`, `closed_percentage`, `unclassified`       | Numérico                                  |
| `summary`          | `text_summary`                                                             | `automatic`, `text`                       |
| `systems`          | `tickets_by_system`, `system_percentage`                                   | Série                                     |
| `systems`          | `systems_count`                                                            | Numérico                                  |
| `categories`       | `tickets_by_category`, `subcategory_occurrences`, `subcategory_percentage` | Série                                     |
| `categories`       | `most_frequent_problems`                                                   | `automatic`, `table`                      |
| `vehicles`         | `distinct_vehicles`, `with_vehicle`, `without_vehicle`, `recurrence`       | Numérico                                  |
| `vehicles`         | `lines`                                                                    | Série e `doughnut`                        |
| `vehicles`         | `brands`, `models`                                                         | Série                                     |
| `vehicle_problems` | `systems`, `categories`, `counts`                                          | Herdado de cada agrupamento selecionado   |
| `vehicle_problems` | Agrupamentos `brand`, `model`, `line`                                      | `automatic`, `table`, `descriptive_group` |

O catálogo identifica os rótulos indispensáveis em `semantic_fields` e as entradas internas em `calculation_inputs`. Ao selecionar apenas `closed_percentage`, o PDF exibe o percentual calculado a partir de encerrados e total filtrado. O ranking de marcas inclui a marca. O ranking de modelos inclui marca e descrição completa. **Modelo não informado** integra o grupo da respectiva marca. O resumo textual usa os indicadores do resumo habilitados pelo usuário.

A participação dos sistemas usa o total filtrado e inclui o histórico de sistemas inativos. As ocorrências de subcategorias vêm dos vínculos e o percentual usa o total filtrado de atendimentos da categoria. Um mesmo atendimento pode gerar vários vínculos, então a soma dos percentuais pode ultrapassar 100%. Os indicadores de subcategoria não aceitam rosca.

A distribuição por linha e os rankings distinguem atendimentos de veículos distintos. A participação por linha considera os atendimentos com veículo identificado. A recorrência identifica veículos com dois ou mais atendimentos no recorte. No detalhamento, a categoria conta atendimentos e a subcategoria conta ocorrências. As projeções reutilizam os agregados de `TicketMetrics` e `VehicleMetrics` e conservam a placa apenas como filtro de consulta.

### Exemplos para validar a API

Envie o exemplo a seguir como JSON para `POST /api/metrics/export/pdf/configuration`. O percentual de encerramento é o único indicador do resumo efetivo. O detalhamento inclui os totais solicitados, agrupados pela marca e descrição do modelo no formato padrão e, adicionalmente, por linha em tabela.

```json
{
    "period": "all",
    "vehicle_presence": "with",
    "configuration": {
        "sections": {
            "summary": {
                "enabled": true,
                "indicators": {
                    "closed_percentage": {
                        "enabled": true,
                        "format": "indicator"
                    }
                }
            },
            "vehicle_problems": {
                "enabled": true,
                "indicators": { "counts": { "enabled": true } },
                "grouping": {
                    "primary": { "by": "model" },
                    "additional": [{ "by": "line", "format": "table" }]
                }
            }
        },
        "title": {
            "mode": "custom",
            "text": "Relatório de erros de indicação — Ford"
        },
        "subtitle": "Atendimentos com identificação veicular"
    }
}
```

O título automático também pode ser usado com uma única seleção.

```json
{
    "configuration": {
        "sections": {
            "summary": {
                "enabled": true,
                "indicators": { "total": { "enabled": true } }
            }
        }
    }
}
```

| Exemplo inválido                                              | Resultado                          |
| ------------------------------------------------------------- | ---------------------------------- |
| `{"configuration":{"sections":{}}}`                           | 422 em `configuration.sections`    |
| `{"configuration":{"sections":{"unknown":{"enabled":true}}}}` | 422 em `configuration.sections`    |
| `subcategory_percentage` com `format: "doughnut"`             | 422 no formato do indicador        |
| `grouping.additional: [{"by":"line"}]` sem `primary`          | 422 em `grouping.primary` da seção |
| `title: {"mode":"custom","text":"<b>Relatório</b>"}`          | 422 em `configuration.title.text`  |

`TicketMetricsPdfConfiguration` representa a seleção efetiva no backend. O gerador combina essa seleção com `TicketMetricsFilters` para projetar o conteúdo habilitado e resolver a apresentação e o título.

## Modal de personalização do PDF (Etapa 2/5)

Em **Dashboard → Métricas**, aplique o recorte desejado e clique em **Exportar PDF**. O modal oferece [pré-visualização e exportação direta](#pré-visualização-e-download-etapa-45).

`MetricsDashboard.vue` é dono do rascunho temporário, entregue ao modal por `v-model:draft`. O rascunho pode ser reutilizado se o diálogo for remontado dentro da mesma sessão. Cancelar, usar o botão de fechar, pressionar ESC, clicar no fundo ou aplicar outro recorte encerra a sessão. O modal aborta requisições pendentes ao sair. Estados de expansão e exibição das opções avançadas são locais ao modal.

`MetricsPdfConfigurationModal.vue` reutiliza `AppModal` para rolagem interna, foco, teclado e rodapé, `FormField` para os campos textuais, `AppIcon` para os ícones e `StatePanel` para carregamento e nova tentativa. `MetricsPdfSection.vue` apresenta seleções e agrupamentos. `metricsPdfConfiguration.js` inicializa o rascunho, monta o contrato efetivo e calcula o resumo e o título sugerido.

### Seleção, formatos e filtros

- O catálogo é consultado ao abrir o modal. O rascunho inicial segue `defaults`: nenhuma seção ativa, apresentação e título automáticos e subtítulo vazio. Ao marcar uma seção vazia, são ativados os `activation_indicators` oferecidos pelo catálogo. No detalhamento veicular, o campo `vehicle_grouping.activation_primary` inicia o agrupamento por **Marca**.
- A seleção parcial usa o estado misto do checkbox. Clicar no principal de uma seção parcial completa seus indicadores. Desmarcá-lo quando completo remove seu conteúdo. Os indicadores podem ser ajustados individualmente em cada seção. O contador do detalhamento inclui o agrupamento principal, que pode ser usado sozinho.
- **Selecionar tudo** ativa todas as seções e indicadores. Os agrupamentos adicionais são escolhidos individualmente. Mudar o agrupamento principal para uma dimensão que já era adicional promove essa dimensão e conserva seu formato. Selecionar um complemento primeiro inicializa o agrupamento principal pelo catálogo.
- **Limpar seleção** retira as escolhas de conteúdo e preserva título e subtítulo. **Restaurar configuração padrão** reinicia os valores do catálogo. **Exibir configurações visuais avançadas** mostra os formatos compatíveis nas seções abertas e **Restaurar formatos automáticos** mantém as seleções.
- **Filtros aplicados** mostra o período e, ao expandir, sistema, categoria, subcategoria, status e filtros veiculares aplicáveis. O resumo usa os filtros já aplicados. Se houver uma marca, o modal consulta seu nome no cadastro existente. A placa aparece como **Filtro de placa aplicado**. Se o valor pesquisado por placa também aparecer em outro rótulo, esse rótulo é substituído por uma descrição genérica no modal.

### Título automático e validação

O título automático usa regras determinísticas nesta ordem:

1. O tema explícito de erros ou falhas de indicação tem prioridade. A subcategoria é examinada antes da categoria. A redação veicular considera o tema explícito, o conteúdo selecionado e a presença de filtros veiculares.
2. A seção exclusiva de problemas por marca e modelo recebe **Relatório de problemas por modelo veicular**. Um conjunto formado apenas por seções veiculares recebe **Relatório de estatísticas veiculares**.
3. Um sistema ou marca elegível pode identificar o recorte em **Relatório de atendimentos**. O resumo geral sozinho pode sugerir **Relatório de indicadores de atendimento**. O título base é **Relatório estatístico de atendimentos** quando nenhuma regra específica se aplica.

O título aceita um único complemento, com prioridade para a marca e, em seguida, para o sistema. Rótulos com dígitos, com o valor do filtro de placa ou que deixariam o título acima do limite do catálogo são omitidos da sugestão. Editar o campo muda o modo para **Personalizado**. **Restaurar título automático** recalcula a sugestão com as escolhas atuais.

O objeto enviado pelo modal usa o contrato da Etapa 1 com as seções, indicadores e agrupamentos efetivos, mais `title` e `subtitle`. Em modo automático, `title.text` é `null` e o gerador calcula o título. O modal bloqueia a geração sem conteúdo, com título personalizado vazio ou com texto acima dos limites do catálogo. O backend valida formatos, dependências e texto. As respostas 422 do título e do subtítulo aparecem no campo correspondente, que recebe o foco.

### Validação manual

1. Abra **Exportar PDF**, expanda duas seções e marque apenas um indicador. Verifique o estado parcial, o contador e o resumo.
2. Abra as opções visuais avançadas, escolha um formato e recolha a seção. Reabra para conferir a escolha. Use **Restaurar formatos automáticos** e confira que o indicador continua marcado.
3. Selecione um agrupamento principal e uma dimensão adicional. Edite o título, altere uma seção e confira que o título personalizado persiste. Restaure o título automático.
4. Envie um subtítulo com HTML, confira o erro no campo, corrija e gere novamente. Em tela estreita, confira a rolagem interna e as ações no rodapé.
5. Feche com ESC e confira o retorno do foco a **Exportar PDF**. Use **Exportar Excel** para conferir o fluxo da planilha.

## Gerador configurável de PDF (Etapa 3/5)

`ConfiguredTicketMetricsPdf::generate(TicketMetricsFilters, TicketMetricsPdfConfiguration)` entrega os bytes de um PDF A4 retrato. Recebe os filtros validados e a seleção efetiva das etapas anteriores. Ele atende à rota binária configurável. O gerador original atende aos clientes do GET legado.

### Fluxo de dados

1. `ConfiguredTicketMetricsPdf::prepare()` obtém os agregados de `TicketMetrics` e `VehicleMetrics` em uma transação. Os filtros são os mesmos de Métricas. São consultados apenas os agrupamentos de problemas veiculares solicitados e o ranking interno por placa fica fora da consulta.
2. `TicketMetricsPdfProjection` monta os indicadores e os blocos das quatro primeiras seções. `VehicleProblemsPdfProjection` compõe a última seção por marca, marca e modelo ou linha, mantendo a ordem escolhida para agrupamentos adicionais.
3. `TicketMetricsPdfContext` prepara o título, o subtítulo do contrato, o período e os filtros ativos. O modal e o PDF usam a política de título de `resources/reports/metrics-pdf-title.json`, que define as expressões, a ordem de prioridade, os títulos e o separador. O documento usa o nome do cadastro para marca e sistema quando elegível. Os critérios exibidos para placa e descrição pesquisada do modelo são informativos.
4. `ReportPlateRedaction` omite placas cadastradas presentes em rótulos e o valor aplicado no filtro. A projeção entrega nomes de agrupamentos, contagens e percentuais ao Blade. Identificadores usados internamente para agrupar, inclusive a chave normalizada de modelo, ficam fora da projeção.
5. O template `reports/configured-ticket-metrics.blade.php` e suas parciais compõem o documento em DejaVu Sans, com a paleta do SupportFlow. O Dompdf carrega os gráficos internos, incorpora fontes e numera as páginas. PHP, JavaScript e recursos remotos ficam desabilitados na renderização.

### Formatos e paginação

| Indicador ou agrupamento                                       | Formato automático                                                                                                                   |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Totais, percentuais gerais e recorrência                       | Cartões com até três indicadores por linha                                                                                           |
| Distribuições e rankings                                       | Barras horizontais para séries de 2 a 8 rótulos de até 125 caracteres. Tabela para séries unitárias, maiores ou com rótulos extensos |
| Quantidade e percentual por sistema selecionados juntos        | Tabela com as duas medidas, quando ambos permitem essa composição                                                                    |
| Ocorrências e percentuais de subcategorias selecionados juntos | Tabela compacta por categoria, quando ambos permitem essa composição                                                                 |
| Problemas mais frequentes e ranking de modelos                 | Tabela                                                                                                                               |
| Linha veicular                                                 | Rosca com base positiva. Tabela com zeros e indicação de ausência de veículos quando a base está vazia                               |
| Resumo textual                                                 | Texto construído com os indicadores gerais selecionados                                                                              |
| Detalhamento veicular                                          | Agrupamento descritivo para modelos ou até quatro grupos. Tabela para volumes maiores por marca ou linha                             |

Formatos manuais usam o formato solicitado. Barras usam a mesma escala em todos os blocos de uma série. Séries manuais com muitos itens são divididas em gráficos completos de até seis itens. Barras verticais e barras horizontais com rótulos longos usam chaves numéricas no SVG e legenda textual paginável. A rosca mostra o percentual dos atendimentos identificados e distingue atendimentos de veículos diferentes. Resultados sem base apresentam uma mensagem, inclusive quando não há proporção a desenhar.

Títulos são mantidos próximos ao conteúdo seguinte e gráficos são tratados como blocos indivisíveis. Cabeçalhos de tabelas são repetidos, incluindo a categoria nas tabelas de problemas e de ranking. A rosca usa a chave numérica da legenda também quando há uma única fatia. Agrupamentos descritivos pequenos ficam juntos e os extensos fluem para as páginas seguintes. Descrições extensas são divididas em segmentos, preferindo os limites entre palavras. Uma palavra que exceda a largura disponível pode quebrar dentro da linha. Esse tratamento contorna a impossibilidade do Dompdf de dividir uma célula de tabela entre páginas. O rodapé usa **Página X de Y**.

### Interpretação dos agregados

- O percentual de encerramento usa o total filtrado. O percentual de cada sistema usa todos os atendimentos do recorte. Se o denominador for zero, o relatório informa **Não aplicável** ou que o percentual está indisponível. Seleções numéricas com zero continuam visíveis.
- As ocorrências de problemas são vínculos. O percentual usa o total de atendimentos da categoria, e a soma pode ultrapassar 100%. O destaque de frequência considera o maior total de ocorrências dentro de cada categoria, exige pelo menos duas ocorrências e inclui todos os empates.
- As séries veiculares são ordenadas pelo número de atendimentos do agregado existente. Quando há contagem de veículos diferentes, ela aparece em unidade própria. Os rankings não atribuem posições a empates. Modelos ausentes permanecem associados à marca como **Modelo não informado**.
- Nos agrupamentos veiculares, a linha de categoria conta atendimentos e a linha de subcategoria conta ocorrências. Os totais vêm de agregados disjuntos e as subcategorias nunca são somadas para calcular atendimentos. Quando **Sistemas envolvidos** está selecionado, o detalhamento conserva os cruzamentos entre sistema, categoria e problema. A recorrência veicular continua significando pelo menos dois atendimentos por veículo dentro do recorte.

### Gerar e conferir os seis exemplos

Execute a partir da raiz do projeto:

```powershell
php -d memory_limit=768M tests/Support/generate-configured-metrics-pdfs.php
php -d memory_limit=768M vendor/bin/pest --compact --filter=ConfiguredTicketMetricsPdf
```

O script usa o bootstrap de testes, exige SQLite em memória e valida a entrada com o mesmo Form Request da API. Os arquivos em `storage/app/private/pdf/relatorios-gerenciais/` ficam sob o diretório privado ignorado pelo Git.

| Arquivo                     | O que avaliar                                                                                          |
| --------------------------- | ------------------------------------------------------------------------------------------------------ |
| `01-geral-simplificado.pdf` | Cinco seções, cartões, percentuais e agrupamento por marca                                             |
| `02-setor-validacao.pdf`    | Recorte por categoria, subtítulo, agrupamentos por modelo e linha, quatro seções                       |
| `03-gestao-suporte.pdf`     | Título personalizado e as três seções de atendimento, sistemas e problemas                             |
| `04-formatos-manuais.pdf`   | Barras horizontais e verticais, rosca, cartões, texto, tabelas e agrupamento descritivo                |
| `05-sem-registros.pdf`      | Zero selecionado, base indisponível e mensagens de ausência de dados                                   |
| `06-grande-volume.pdf`      | Continuação das tabelas, descrição longa até **Descrição integral confirmada** e rodapé de cada página |

## Pré-visualização e download (Etapa 4/5)

| Requisição                                                  | Uso                                              |
| ----------------------------------------------------------- | ------------------------------------------------ |
| `GET /api/metrics/export/pdf/configuration`                 | Carrega catálogo e opções padrão                 |
| `POST /api/metrics/export/pdf/configuration`                | Valida e normaliza JSON para clientes da Etapa 1 |
| `POST /api/metrics/export/pdf?disposition=inline`           | Gera o PDF para pré-visualização                 |
| `POST /api/metrics/export/pdf` ou `?disposition=attachment` | Gera o PDF para download direto                  |
| `GET /api/metrics/export?format=xlsx`                       | Gera a planilha Excel pelo contrato existente    |
| `GET /api/metrics/export?format=pdf`                        | Gera o PDF legado para clientes existentes       |

O POST binário usa o JSON da Etapa 1 com os filtros estatísticos na raiz e o objeto `configuration`. O Form Request do endpoint reaproveita a validação desse contrato e restringe `disposition` a `inline` ou `attachment`. O controller chama `ConfiguredTicketMetricsPdf` em ambos os modos e devolve `application/pdf`, `Content-Disposition`, `Cache-Control: private, no-store` e `X-Content-Type-Options: nosniff`. A data no nome fixo `supportflow_relatorio_YYYY-MM-DD.pdf` e no cabeçalho do documento vem do mesmo instante em São Paulo.

Ao abrir o modal, o Dashboard guarda uma cópia dos filtros aplicados e das datas resolvidas pela API. Para períodos relativos, o POST usa essas datas no formato de período personalizado, preservando o recorte mesmo após a virada do dia. O modal mostra o rótulo escolhido no Dashboard. Alterações ainda em edição ficam fora da requisição. Aplicar filtros encerra a sessão anterior, descarta sua pré-visualização e cancela a espera por respostas pendentes.

A pré-visualização mantém o rascunho e o Blob em memória. O visualizador nativo do navegador exibe o arquivo em um iframe com URL temporária. Paginação, rolagem e ampliação seguem os controles do navegador. **Abrir PDF em nova aba** e **Baixar PDF** continuam disponíveis caso a exibição interna falhe ou o navegador não ofereça suporte. **Voltar à configuração** libera a URL da visualização, preserva o rascunho e permite gerar outro documento. **Baixar PDF** usa o mesmo Blob, nome e data de emissão. Após um download iniciado, cliques consecutivos no primeiro segundo são ignorados. Se a abertura do download falhar, a ação pode ser repetida imediatamente.

A exportação direta valida e gera em uma única requisição. Durante a geração as duas ações ficam desabilitadas e **Cancelar** permanece disponível. A aplicação valida MIME, início e fim do arquivo antes de exibir ou baixar. Respostas 422 aparecem no modal. Falhas de rede, tempo limite, geração, abertura ou download exibem mensagens de nova tentativa com o rascunho preservado. Cancelar ou fechar descarta os recursos do navegador. A rota devolve bytes diretamente e o download usa uma URL separada, liberada depois que o navegador inicia a leitura.

O corpo do relatório vem dos agregados do recorte. Título e subtítulo são validados como texto simples, com rejeição de placas, inclusive com traços tipográficos, e padrões reconhecíveis de referências individuais. Use descrições gerenciais nos campos livres. O nome fixo do arquivo independe dos filtros e do título. A rota usa o grupo de acesso existente. O log de falha registra somente a classe da exceção.

Para validar a integração, abra a pré-visualização, baixe o documento, volte à configuração, altere o título e gere novamente. Compare a emissão e o conteúdo do primeiro download com a primeira visualização. Em seguida, exporte direto, aplique outro recorte e confira o descarte da sessão anterior. Repita em tela estreita e teste também **Exportar Excel**.

## Dados compartilhados, consistência e privacidade

`TicketMetricsReport` fornece dados para Excel e PDF legado. Chama as consultas agregadas de resumo, sistemas, categorias, subcategorias e evolução mensal dentro de uma transação de leitura. O PDF gerencial usa os mesmos serviços estatísticos por meio de sua própria projeção de conteúdo.

Os totais gerais, por sistema, categoria, subcategoria e mês são conciliados antes da geração. A soma das subcategorias também é comparada ao total da categoria. Uma divergência provoca erro de geração; não há correções automáticas no banco.

`TicketMetricsReportData` contém somente critérios textuais, data de geração, indicadores, nomes dos agrupamentos, contagens e percentuais. Identificadores usados pelas consultas são retirados na projeção para o documento. PDF e XLSX recebem essa mesma estrutura. Não são exportados números/títulos de chamados, clientes, contatos, telefones, documentos, descrições ou resoluções individuais.

Quando solicitada, a projeção veicular usa a mesma agregação do Dashboard e omite o ranking interno por placa. PDF e Excel recebem as mesmas colunas e contagens. O PDF repete cabeçalhos de tabelas e distribui descrições muito extensas em linhas consecutivas. O Excel conserva a descrição completa em uma célula. Para textos maiores que o limite de altura da linha, use a barra de fórmulas para consultar o conteúdo integral. A [documentação veicular](vehicle-identification.md#métricas-veiculares-e-relatórios) descreve as sete planilhas opcionais, os denominadores e a reclassificação de períodos anteriores.

As contagens e percentuais existentes são reutilizados. Os percentuais adicionais exigidos pelo relatório são calculados uma única vez na estrutura compartilhada:

- Sistema: quantidade do sistema dividida pelo total filtrado.
- Categoria: percentual fornecido pelo serviço existente, sobre o total filtrado.
- Subcategoria consolidada: percentual fornecido pelo serviço existente, dentro da categoria.
- Linha de subcategoria por sistema no XLSX: quantidade daquela linha dividida pelo **total filtrado da categoria**. Somar as linhas dos sistemas recupera a participação consolidada da subcategoria. Não há linhas duplicadas de subtotal que causem dupla contagem.

Pequenas diferenças na soma dos percentuais podem ocorrer pelo arredondamento em duas casas. Grupos sem classificação ou sem subcategoria, sistemas inativos e classificações desativadas continuam incluídos conforme o histórico e os filtros.

As estatísticas são consultadas novamente no momento da geração. Se os registros mudarem desde o último carregamento da aba, atualizar as métricas permite comparar os resultados atuais; a exportação não congela uma cópia antiga dos dados do navegador.

## Documento PDF legado

O PDF contém identificação do SupportFlow, título, filtros, período e data de geração; resumo dos cinco indicadores; distribuições por sistema e categoria; subcategorias agrupadas por categoria; e evolução mensal em gráfico e tabela.

O documento é A4 retrato, utiliza a cor azul do projeto, fonte Unicode incorporada, margens de impressão, tabelas com cabeçalhos, rodapé e numeração de páginas. Nomes são escapados pelo Blade. O gráfico usa somente os agregados mensais recebidos e é dividido em blocos de até 12 meses, mantendo os valores em tabelas. Meses zerados seguem a resposta do serviço, sem preenchimento estatístico independente.

### Reformulação visual e paginação

O cabeçalho apresenta marca, título, emissão e período sem capa. Os outros quatro filtros ocupam duas colunas; o fuso `America/Sao_Paulo` aparece discretamente no rodapé. Os cinco indicadores formam uma faixa compacta, com números destacados em azul-escuro.

Não há quebras obrigatórias entre seções nem proteção de seções inteiras contra paginação. Títulos e cabeçalhos ficam próximos ao conteúdo; linhas não são divididas e os cabeçalhos são repetidos nas continuações das tabelas. A proteção do cabeçalho também é aplicada à sua última linha, pois o Dompdf não usa a propriedade do grupo `thead` para impedir a quebra antes da primeira linha de dados.

As distribuições por sistema e categoria compartilham duas colunas somente quando **ambas têm até cinco linhas e nomes de até 48 caracteres**. São limites de apresentação para uma região curta e indivisível, não limites de resultados: tabelas maiores ou nomes mais longos aparecem empilhados e paginam naturalmente. Isso respeita a limitação do Dompdf de não dividir células de uma tabela externa entre páginas.

As subcategorias aparecem em uma tabela contínua, com categoria, subcategoria, quantidade e percentual na categoria. Os totais de cada categoria permanecem na distribuição anterior, evitando sua repetição em cada linha do detalhamento. O gráfico mensal tem altura compacta. Com até quatro meses, gráfico e tabela aparecem lado a lado; nos demais blocos, a tabela apresenta até três pares mês/quantidade por linha, em ordem cronológica da esquerda para a direita. Cada gráfico permanece próximo aos seus valores, sem manter toda a seção de evolução indivisível. Essas mudanças apenas reorganizam os agregados recebidos.

O PDF usa HTML/CSS compatível com o Dompdf, sem flexbox, grid ou dependências novas. Somente indicadores, pequenas tabelas mensais e regiões curtas lado a lado permanecem indivisíveis. Tabelas extensas fluem pelo documento sem limite artificial de páginas. Nomes muito longos são quebrados dentro das células.

A reformulação é exclusiva do template PDF, de suas tabelas parciais e das dimensões do gráfico/rodapé no gerador PDF. Serviços estatísticos, filtros, endpoints, Dashboard, dados compartilhados e gerador Excel permanecem iguais.

Arquivos desta revisão:

- `app/Services/Reports/PdfTicketMetricsReport.php`: dimensões do SVG e rodapé.
- `resources/views/reports/ticket-metrics.blade.php`: estrutura visual, estilos e regras de paginação.
- `resources/views/reports/partials/metrics-distribution.blade.php`: tabela reutilizada nas distribuições, empilhada ou compacta.
- `resources/views/reports/partials/metrics-monthly-values.blade.php`: valores mensais em tabela vertical ou pares de colunas.
- `tests/Feature/Api/TicketMetricsExportTest.php`: cenários reais de PDF pequeno, vazio e extenso, com preservação de linhas e meses.
- `docs/statistical-reports.md`: organização e validação do layout.

### Validação da reformulação

Os exemplos foram gerados exclusivamente com agregados fictícios ou consultas à base SQLite isolada de testes. Todos foram reabertos com pypdf/pdfplumber, renderizados com Poppler e inspecionados visualmente, incluindo as páginas de continuação. A conferência automática verificou A4 retrato, rótulos completos, valores mensais legíveis no gráfico e na tabela, limites das margens e separação do rodapé.

| Cenário                                                           | Páginas |
| ----------------------------------------------------------------- | ------: |
| 2 atendimentos, 2 sistemas, 2 categorias e 2 meses                |       1 |
| Sem resultados, com meses zerados                                 |       1 |
| 18 sistemas, 45 categorias e 90 subcategorias, com nomes extensos |      13 |
| Uma categoria com 110 subcategorias e 36 meses                    |       8 |
| Dois atendimentos com filtros textuais longos                     |       1 |
| Nomes de 255 caracteres sem espaços                               |       2 |
| Base isolada: 50 atendimentos, todo o histórico                   |       2 |
| Base isolada: 50 atendimentos, últimos 6 meses                    |       2 |
| Base isolada: 8 atendimentos, este mês                            |       1 |

São **9 cenários e 31 páginas**. O exemplo isolado anterior de 50 atendimentos tinha quatro páginas; com os mesmos totais, a nova apresentação utiliza duas. A quantidade de páginas continua dependendo do conteúdo, sem teto ou truncamento de resultados. Os exemplos, JSONs de referência, imagens e verificações estão em `storage/framework/testing/evidence/pdf-layout/`, fora do versionamento.

Os testes adicionados e atualizados verificam um relatório real de dois atendimentos em uma página, A4 retrato, grupos históricos e dois meses; preservação de 110 subcategorias e 36 meses em várias páginas; e relatório vazio em uma página. Os testes existentes de filtros, totais, ausência de informações individuais, consultas somente de leitura e exportação XLSX continuam sendo executados. O comparativo com o Dashboard utiliza os mesmos resultados da API de métricas.

Resultados finais desta revisão:

- `php artisan test --compact`: **318 testes e 3.980 verificações, aprovados**. Inclui os **46 testes de exportação** e a regressão dos módulos existentes.
- PHPStan: **sem erros**; Pint e `git diff --check`: aprovados.
- Leitura independente e inspeção visual dos nove PDFs: nenhum rótulo ausente, texto fora das margens ou sobreposição com o rodapé; datas dos gráficos continuam separadas e legíveis mesmo com 12 meses por bloco.
- Comparação dos registros da base isolada: **11 tabelas e 132 registros inalterados**. Nenhum banco de dados real foi utilizado ou modificado.

## Arquivo Excel

| Planilha        | Conteúdo                                                                  |
| --------------- | ------------------------------------------------------------------------- |
| Resumo Geral    | Filtros, período, data de geração e cinco indicadores                     |
| Por Sistema     | Sistema, total, encerrados, não encerrados e participação                 |
| Categorias      | Categoria, ocorrências e participação                                     |
| Subcategorias   | Categoria, subcategoria, sistema, ocorrências e participação na categoria |
| Evolução Mensal | Mês e quantidade de atendimentos                                          |

As planilhas possuem cabeçalhos destacados, larguras definidas, quebra de texto, formatos numéricos e percentuais, congelamento e filtros nos intervalos com dados. As contagens são números e os percentuais são frações numéricas com formato percentual. Não há fórmulas: nomes cadastrados, mesmo iniciados por `=`, `+`, `-` ou `@`, são gravados explicitamente como texto. Não são criados hyperlinks externos.

Meses são datas nativas formatadas como `mm/yyyy`. Para anos anteriores a 1900, não representáveis adequadamente pelo formato de datas do Excel, o mês é preservado como texto ISO `YYYY-MM`.

## Bibliotecas e instalação

- **Dompdf 3.1.6** (`~3.1.6`): gera o PDF diretamente do template Blade/HTML, com fonte Unicode e gráfico SVG embutido, sem navegador ou serviço externo. Recursos remotos, execução de PHP e JavaScript ficam desabilitados; o acesso local é restrito aos diretórios necessários. [Projeto oficial](https://github.com/dompdf/dompdf).
- **PhpSpreadsheet 5.12.0** (`~5.12.0`): escreve XLSX nativo com tipos explícitos, formatação, congelamento, filtros e configuração de impressão. [Documentação oficial](https://phpspreadsheet.readthedocs.io/).

Foram acrescentadas somente essas dependências diretas e suas dependências transitivas no Composer; os pacotes existentes não foram atualizados. Nenhuma biblioteca foi adicionada ao frontend. Em outro ambiente, executar `composer install` para usar o lockfile; nenhuma migration nova é necessária nesta etapa. As extensões exigidas foram conferidas por `composer check-platform-reqs` no ambiente local.

Geração é síncrona. O XLSX utiliza um stream temporário, fechado ao terminar; o PDF é entregue em memória. Não existe armazenamento permanente de relatórios nem envio externo. O timeout de espera do download no navegador é de dois minutos; os demais endpoints mantêm o timeout anterior. Volumes muito extensos continuam sujeitos aos recursos disponíveis no PHP.

## Arquivos da exportação legada e do Excel

Criados:

- `app/Http/Controllers/Api/ExportTicketMetricsController.php`
- `app/Http/Requests/Api/ExportTicketMetricsRequest.php`
- `app/Support/TicketMetricsReportData.php`
- `app/Services/Reports/TicketMetricsReport.php`
- `app/Services/Reports/PdfTicketMetricsReport.php`
- `app/Services/Reports/XlsxTicketMetricsReport.php`
- `resources/views/reports/ticket-metrics.blade.php`
- `frontend/src/components/metrics/MetricsExportModal.vue`
- `frontend/src/utils/download.js`
- `tests/Feature/Api/TicketMetricsExportTest.php`
- `frontend/src/__tests__/report-download.spec.js`
- `docs/statistical-reports.md`

Modificados:

- `composer.json` e `composer.lock`
- `routes/api.php`
- `frontend/src/services/api.js`
- `frontend/src/components/AppIcon.vue`
- `frontend/src/components/metrics/MetricsDashboard.vue`
- `frontend/src/components/metrics/MetricsFilters.vue`
- `frontend/src/config/ticketMetrics.js`
- `frontend/src/__tests__/dashboard-metrics.spec.js`
- `docs/dashboard-metrics.md`

As alterações anteriores das Etapas 1 a 4 no diretório de trabalho foram preservadas. Não houve alteração nos cálculos de `TicketMetrics`, formulários, cadastros ou regras de gravação dos atendimentos.

## Validação histórica do PDF legado e do Excel

- `php artisan test --compact --filter=TicketMetricsExportTest`: **44 testes, 1.149 verificações, aprovados**.
- `php artisan test --compact`: **301 testes, 4.677 verificações, aprovados**, incluindo as funcionalidades anteriores.
- `npm.cmd run test:unit -- --run`: **156 testes aprovados**, incluindo 16 novos cenários de exportação/download.
- `npm.cmd run build`: build de produção aprovado.
- PHPStan: aprovado, sem erros. Pint, `composer validate --no-check-publish`, `composer check-platform-reqs` e `git diff --check`: aprovados.
- Testes abrangem arquivos PDF/XLSX reais, MIME/nome, filtros relativos e personalizados, combinações de filtros, estados históricos, grupos nulos, percentuais, fórmulas, erros de geração/validação, download binário, cancelamento, respostas atrasadas e preservação da análise.
- Os PDFs foram renderizados com Poppler e conferidos visualmente: exemplo de quatro páginas e teste de 18 páginas com nomes longos. Cinco planilhas foram renderizadas e revisadas. Arquivos também foram lidos por pypdf e openpyxl, independentemente dos geradores.
- Navegador com API real e SQLite isolado: downloads PDF e XLSX concluídos. O Excel com novembro de 2026 + sistema + status encerrado + categoria + subcategoria histórica apresentou **1 ocorrência**, igual ao Dashboard. Fechar/reabrir manteve os filtros; o modal foi conferido também em 390 px, sem extravasamento e sem erros no console.
- Antes/depois das consultas e dos downloads: **11 tabelas funcionais, 132 registros, inalterados** na base isolada.

Exemplos e evidências locais, gerados exclusivamente da base isolada de testes, estão em `storage/framework/testing/evidence/reports/`, incluindo `supportflow_relatorio_exemplo.pdf`, `supportflow_relatorio_exemplo.xlsx` e capturas dos modais. Esses arquivos são artefatos de validação; o endpoint normal não os utiliza.

Não restam pendências funcionais identificadas. A compatibilidade XLSX foi verificada por leitores independentes e pela estrutura nativa do arquivo; a abertura no aplicativo Microsoft Excel não foi automatizada. Nenhum dado de produção foi utilizado para gerar os exemplos.
