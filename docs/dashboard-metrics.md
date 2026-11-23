# Dashboard — aba Métricas (Etapa 4)

## Acesso e utilização

Abra o Dashboard e selecione **Métricas**. **Visão geral** continua sendo a aba inicial e mantém os cinco indicadores operacionais, atendimentos recentes, acesso rápido, pendências, lembretes e links anteriores, na mesma ordem.

As métricas são carregadas apenas no primeiro acesso à nova aba. Alternar entre as abas mantém filtros, resultados e categoria detalhada, sem repetir consultas. Sair da página ou recarregá-la inicia uma nova sessão de análise; não há persistência de filtros no navegador.

1. Escolha período, sistema, status, categoria e, opcionalmente, subcategoria.
2. Clique em **Aplicar filtros**. Alterações nas opções são rascunhos até a aplicação.
3. Consulte os quatro indicadores, barras por sistema, linha mensal e ranking.
4. Clique no nome de uma categoria para detalhar suas subcategorias. **Ver atendimentos** abre os registros da categoria; o nome de uma subcategoria abre seu grupo específico.
5. O modal possui paginação de 15 registros e links para os detalhes. Feche pelo botão, ESC ou fundo para voltar à análise com os mesmos filtros.
6. **Redefinir filtros** aplica novamente os últimos seis meses, todos os sistemas/status e todas as classificações.

## Filtros e dados históricos

Os sete períodos, os status individuais e os parâmetros são os existentes no backend, documentados em [ticket-metrics.md](ticket-metrics.md). O padrão é `last_6_months`. Datas personalizadas são enviadas como `date_from`/`date_to`, inclusivas, no horário de Brasília. Datas não são enviadas para períodos relativos. O frontend não calcula limites dos períodos.

Categorias são limitadas aos vínculos do sistema escolhido; subcategorias também são limitadas à categoria e ao sistema. São consideradas **todas** as entradas de `system_associations`, inclusive vínculos posteriormente desabilitados, para permitir consultas históricas. Cadastros inativos são identificados e continuam selecionáveis. Não se utiliza `selectable=1`, que se destina a novas classificações de atendimentos.

Mudar sistema ou categoria redefine escolhas dependentes incompatíveis. A mudança de sistema apresenta uma mensagem quando redefine a classificação; os resultados só mudam ao aplicar. Sem categoria selecionada, a subcategoria fica desabilitada. Há opções explícitas **Sem classificação** (`classification=unclassified`) e **Sem subcategoria** (`classification=without_subcategory`), com combinações consistentes de identificadores.

## API utilizada

Todas as requisições passam por `services/api.js` e são somente GET. Nenhum endpoint, cálculo, migration ou regra de gravação foi alterado nesta etapa.

| Endpoint | Utilização |
| --- | --- |
| `/api/metrics/summary` | Total, não encerrados, encerrados, sistemas com atendimentos |
| `/api/metrics/systems` | Barras por sistema, na ordem de volume da API |
| `/api/metrics/monthly` | Evolução mensal na ordem cronológica da API |
| `/api/metrics/categories` | Ranking e percentuais no conjunto filtrado |
| `/api/metrics/subcategories` | Detalhamento e percentuais dentro da categoria |
| `/api/metrics/tickets` | Registros relacionados, com `page` e `per_page=15` |
| `/api/systems` | Opções de sistemas, incluindo inativos |
| `/api/problem-categories` | Opções de categorias e vínculos históricos |
| `/api/problem-subcategories` | Opções de subcategorias e vínculos históricos |

Os três cadastros de opções são carregados uma vez por montagem, percorrendo suas páginas com `per_page=100`. Aplicar filtros não repete essas consultas. As cinco consultas agregadas recebem o mesmo conjunto de parâmetros; a apresentação só é publicada quando todas terminam. Elas representam consultas separadas ao banco, conforme a API da Etapa 3; não constituem uma transação com snapshot entre endpoints.

## Gráficos, ranking e consulta

Não foi adicionada biblioteca: o gráfico de sistemas utiliza barras CSS com valores textuais; a evolução usa SVG e oferece uma tabela expansível com todos os meses/valores. O frontend calcula apenas coordenadas e escala visual, sem somar ocorrências, recalcular percentuais, preencher meses ou gerar números estatísticos.

Ordenação, contagens, percentuais e meses zerados vêm da API. O histórico completo mostra os meses observados; períodos limitados utilizam os meses zerados fornecidos pelo backend. Uma única série/ponto continua válida e não ganha comparações artificiais. Sistemas numerosos possuem rolagem vertical; séries longas e tabelas possuem rolagem horizontal interna. Cartões passam de quatro para duas colunas em telas menores.

O detalhamento usa as subcategorias já recebidas, sem nova consulta. O modal recebe uma cópia dos filtros **aplicados**, acrescida do grupo selecionado; preserva eventuais filtros de subcategoria já aplicados ao abrir uma categoria. Grupos nulos utilizam os parâmetros próprios da API. Apenas título/número, sistema, status e abertura são apresentados, com link para `/atendimentos/{id}`. Não há ações de edição no modal.

Requisições anteriores são canceladas e suas respostas são ignoradas. Resultados anteriores são retirados durante atualizações, evitando gráficos antigos sob novos filtros. Erros de validação da API, falhas de catálogos/agregados/listagem, estados vazios e tentativas de recarregamento são apresentados pelos componentes existentes. Falhar um agregado impede exibir uma combinação parcial de resultados.

As abas suportam setas, Home/End, foco e atributos ARIA. Os filtros têm rótulos e controles nativos. O modal reutiliza `AppModal` (foco, ESC, clique fora, rolagem), `PaginationBar` e `StatusBadge`; após paginação, o foco permanece no contexto do modal.

## Arquivos desta etapa

Alterado:

- `frontend/src/views/DashboardView.vue` — navegação por abas e carregamento sob demanda; conteúdo operacional preservado.

Criados:

- `frontend/src/components/metrics/MetricsDashboard.vue` — coordenação das consultas, indicadores, ranking e detalhamento.
- `frontend/src/components/metrics/MetricsFilters.vue` — opções, relacionamentos, rascunhos e aplicação dos filtros.
- `frontend/src/components/metrics/MetricsSystemsChart.vue` — barras por sistema.
- `frontend/src/components/metrics/MetricsMonthlyChart.vue` — evolução SVG e tabela acessível.
- `frontend/src/components/metrics/MetricsTicketsModal.vue` — consulta paginada somente de leitura.
- `frontend/src/components/metrics/metrics.css` — estilos localizados, sem alterar os estilos operacionais.
- `frontend/src/config/ticketMetrics.js` — períodos, valores iniciais, parâmetros, formatação e recortes de consulta.
- `frontend/src/__tests__/dashboard-metrics.spec.js` — 36 casos para as novas interações.
- `docs/dashboard-metrics.md` — esta documentação.

## Verificação

- `npm.cmd run test:unit -- --run`: 140 testes aprovados, incluindo os 36 novos cenários de métricas.
- `npm.cmd run build`: build de produção aprovado, com a aba Métricas em um pacote carregado sob demanda.
- `php artisan test --compact`: 257 testes aprovados, 3.528 verificações.
- Navegador com API real e SQLite isolado: abas, dados reais da base de teste, mês zerado, ranking, subcategorias ativas/inativas, filtro combinado de novembro/sistema/status/subcategoria histórica, ausência de resultados, paginação, manutenção de filtros, acesso aos detalhes e modal de contato.
- Layout inspecionado em desktop e 390 px; largura de 320 px também conferida sem extravasamento da página. Tabelas e gráfico mantêm a rolagem dentro de seus painéis.
- Comparação antes/depois da navegação e das consultas: 11 tabelas funcionais, 132 registros da base isolada, sem alterações. Console do navegador sem erros ou avisos.
- `git diff --check`: aprovado.

A Etapa 5 acrescentou o botão **Exportar relatório** à aba, utilizando os filtros aplicados desta coordenação. O fluxo, os formatos, os arquivos e a validação da exportação estão documentados em [statistical-reports.md](statistical-reports.md). Os resultados de testes acima registram a entrega da Etapa 4; os resultados atuais estão na documentação da Etapa 5.
