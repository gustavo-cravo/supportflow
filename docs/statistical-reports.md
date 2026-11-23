# Exportação de relatórios estatísticos — Etapa 5

## Estado encontrado e conclusão da retomada

Antes da interrupção já estavam escritos o endpoint, a validação, a estrutura compartilhada de dados consolidados, os geradores PDF/XLSX, o template PDF, o modal, o download binário e os testes específicos. As dependências estavam declaradas no Composer e disponíveis no autoload na retomada. Essa implementação foi preservada.

Na retomada foram executados os testes novos e de regressão, a análise estática, a geração e inspeção dos documentos e os downloads reais no navegador. Também foram organizados os testes e esta documentação. A reformulação visual posterior do PDF, descrita abaixo, substituiu a proteção de seções inteiras por paginação contínua.

## Como utilizar

1. Abra **Dashboard → Métricas** e aplique os filtros desejados.
2. Clique em **Exportar relatório**, próximo ao título da aba.
3. Confira o resumo do período, sistema, status, categoria e subcategoria.
4. Selecione **PDF** ou **Excel (.xlsx)** e clique em **Gerar relatório**.

O download só é iniciado depois de receber um arquivo válido. A mensagem confirma que o download foi iniciado, sem afirmar que o usuário já o salvou. O botão impede envios duplicados enquanto a geração está em andamento. Cancelar, ESC ou clicar fora fecha o modal, cancela a espera no navegador e mantém a análise e seus filtros; isso não interrompe uma requisição que o servidor já estiver processando.

Opções alteradas nos filtros, mas ainda não aplicadas, não entram no relatório. O modal usa o mesmo objeto de filtros **aplicados** da aba, sem um segundo formulário de filtragem. O botão fica desabilitado durante carregamento ou erro das métricas e permite relatórios de resultados vazios.

## Endpoint e parâmetros

```http
GET /api/metrics/export?format=pdf&period=last_6_months
GET /api/metrics/export?format=xlsx&period=custom&date_from=2026-11-01&date_to=2026-11-30&system_id=1&status=closed&problem_category_id=1&problem_subcategory_id=1
```

Os identificadores do exemplo devem ser substituídos pelos registros existentes no ambiente. Para validar localmente:

```powershell
curl.exe --fail --output supportflow_relatorio.pdf "http://127.0.0.1:8000/api/metrics/export?format=pdf&period=last_6_months"
curl.exe --fail --output supportflow_relatorio.xlsx "http://127.0.0.1:8000/api/metrics/export?format=xlsx&period=all"
```

`format` é obrigatório e aceita somente `pdf` ou `xlsx`. Os demais parâmetros e validações são herdados de `TicketMetricsRequest`, documentados em [ticket-metrics.md](ticket-metrics.md): `period`, `date_from`, `date_to`, `system_id`, `status`, `problem_category_id`, `problem_subcategory_id` e `classification`. `page` e `per_page` são proibidos na exportação. As datas personalizadas continuam inclusivas e os períodos relativos usam `America/Sao_Paulo`.

O endpoint utiliza o mesmo grupo de acesso da API atual. Não foi introduzida uma regra de autenticação diferente. Parâmetros inválidos retornam os erros 422 usuais. Falhas de geração retornam HTTP 500 em JSON, sem expor caminhos internos ou entregar um arquivo incompleto como sucesso.

Arquivos são retornados diretamente como attachments com os tipos MIME correspondentes, `Cache-Control: private, no-store` e `X-Content-Type-Options: nosniff`. Os nomes usam a data de geração: `supportflow_relatorio_YYYY-MM-DD.pdf` ou `.xlsx`. O frontend verifica o tipo de conteúdo, rejeita arquivos vazios e restringe os nomes de download. Os objetos URL são liberados após iniciar o download.

## Dados compartilhados, consistência e privacidade

`TicketMetricsReport` chama as cinco consultas agregadas existentes de `TicketMetrics`: resumo, sistemas, categorias, subcategorias e evolução mensal. Não chama a consulta de atendimentos paginados e não carrega clientes ou contatos. As consultas são agrupadas em uma transação de leitura; nenhum registro é gravado.

Os totais gerais, por sistema, categoria, subcategoria e mês são conciliados antes da geração. A soma das subcategorias também é comparada ao total da categoria. Uma divergência provoca erro de geração; não há correções automáticas no banco.

`TicketMetricsReportData` contém somente critérios textuais, data de geração, indicadores, nomes dos agrupamentos, contagens e percentuais. Identificadores usados pelas consultas são retirados na projeção para o documento. PDF e XLSX recebem essa mesma estrutura. Não são exportados números/títulos de chamados, clientes, contatos, telefones, documentos, descrições ou resoluções individuais.

As contagens e percentuais existentes são reutilizados. Os percentuais adicionais exigidos pelo relatório são calculados uma única vez na estrutura compartilhada:

- Sistema: quantidade do sistema dividida pelo total filtrado.
- Categoria: percentual fornecido pelo serviço existente, sobre o total filtrado.
- Subcategoria consolidada: percentual fornecido pelo serviço existente, dentro da categoria.
- Linha de subcategoria por sistema no XLSX: quantidade daquela linha dividida pelo **total filtrado da categoria**. Somar as linhas dos sistemas recupera a participação consolidada da subcategoria. Não há linhas duplicadas de subtotal que causem dupla contagem.

Pequenas diferenças na soma dos percentuais podem ocorrer pelo arredondamento em duas casas. Grupos sem classificação ou sem subcategoria, sistemas inativos e classificações desativadas continuam incluídos conforme o histórico e os filtros.

As estatísticas são consultadas novamente no momento da geração. Se os registros mudarem desde o último carregamento da aba, atualizar as métricas permite comparar os resultados atuais; a exportação não congela uma cópia antiga dos dados do navegador.

## Documento PDF

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

| Cenário | Páginas |
| --- | ---: |
| 2 atendimentos, 2 sistemas, 2 categorias e 2 meses | 1 |
| Sem resultados, com meses zerados | 1 |
| 18 sistemas, 45 categorias e 90 subcategorias, com nomes extensos | 13 |
| Uma categoria com 110 subcategorias e 36 meses | 8 |
| Dois atendimentos com filtros textuais longos | 1 |
| Nomes de 255 caracteres sem espaços | 2 |
| Base isolada: 50 atendimentos, todo o histórico | 2 |
| Base isolada: 50 atendimentos, últimos 6 meses | 2 |
| Base isolada: 8 atendimentos, este mês | 1 |

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

## Arquivos da Etapa 5

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

## Validação executada após a retomada

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
