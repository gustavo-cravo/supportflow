# Auditoria final do PDF estatístico

## Escopo e arquitetura revisada

A auditoria percorreu o catálogo e a validação Laravel, o modal e o estado Vue, o gerador Dompdf, a pré-visualização local e a entrega binária. O PDF gerencial usa a mesma projeção de documentos em `inline` e `attachment`. Dashboard, PDF e Excel chamam os serviços estatísticos compartilhados. Cada formato decide quais agregados exibir. O [guia de exportação](statistical-reports.md) detalha o contrato, as cinco seções, os formatos, os filtros, a prioridade do título automático e os endpoints.

Os testes e a geração de documentos usaram dados sintéticos no SQLite em memória. O script de cenários verifica ambiente, conexão, caminho do banco e URL de conexão antes de executar as migrações. Nenhum registro do ambiente de uso foi alterado.

## Matriz de conformidade

| Requisito                                         | Resultado e evidência                                                                                                                                                                                                                                         |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Identidade visual e layout gerencial              | Conforme nos documentos A–F. Cabeçalho, rodapé, tipografia, margens e hierarquia foram conferidos nas páginas renderizadas.                                                                                                                                   |
| Cinco seções e seleção independente               | Conforme. Catálogo, validação e modal cobrem seleção vazia, individual, parcial, total, por área e com zeros. O texto do resumo considera as métricas habilitadas no resumo.                                                                                  |
| Agrupamentos e complementos veiculares            | Conforme. As dimensões marca, marca e modelo e linha são verificadas em tabelas e grupos descritivos. Os testes distinguem categoria, ocorrência e cruzamento por sistema.                                                                                    |
| Layout híbrido                                    | Conforme. Foram exercitados automático, indicadores, barras horizontais e verticais, rosca, tabela, texto e agrupamento descritivo. A série por linha conserva seus valores em cada formato compatível. A API rejeita rosca em percentuais de subcategorias.  |
| Título e subtítulo                                | Conforme. O teste cobre a prioridade do tema, a escolha de marca e sistema, o modo automático e a restauração após edição. Título e subtítulo seguem os limites do catálogo e a validação textual da API.                                                     |
| Pré-visualização opcional, retorno e nova emissão | Conforme nos testes da rota e do modal. O retorno preserva a seleção e descarta a URL anterior. A renderização independente dos PDFs foi inspecionada. O funcionamento no visualizador nativo foi validado manualmente pelo responsável na Etapa 4.           |
| Exportação direta e download da prévia            | Conforme. A emissão direta baixa o arquivo sem passar pela prévia. O download da prévia reutiliza o Blob e o nome, conserva a data da emissão e evita dois downloads em cliques consecutivos.                                                                 |
| Filtros do Dashboard                              | Conforme. Período inclusivo, sistema ativo ou inativo no histórico, categoria, subcategoria, status, presença veicular, placa, modelo, marca e linha foram verificados no PDF. A cobertura inclui classificação, filtros combinados e recorte sem resultados. |
| Precisão e agregação                              | Conforme para as bases conhecidas. O PDF, a API de Métricas e o XLSX real concordaram nas medidas comparáveis. Veículos distintos, atendimentos e vínculos preservam suas unidades.                                                                           |
| Privacidade e estado temporário                   | Conforme nos dados estruturados e nas entradas testadas. O conteúdo agregado, o filtro por placa, os metadados, o nome do arquivo e o ciclo de vida da URL foram verificados. Campos livres exigem descrições gerenciais.                                     |
| Excel, PDF individual e PDF legado                | Conforme nos testes de regressão. A planilha mantém a finalidade analítica. O PDF individual continua contendo os dados previstos para um atendimento. O GET legado continua atendendo clientes existentes.                                                   |

## Correções comprovadas

| Reprodução e causa                                                                                                                                               | Correção e teste de regressão                                                                                                                                                                                                                                                                                                                                      |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Dois cliques consecutivos em **Baixar PDF** chamavam o navegador duas vezes para o mesmo Blob. A ação do modal encaminhava cada clique ao download.              | `MetricsPdfConfigurationModal.vue` ignora o segundo acionamento durante um segundo após um download iniciado. Falhas permitem tentar imediatamente. O teste em `metrics-pdf-configuration.spec.js` verifica um único download em dois cliques, a tentativa posterior com o mesmo arquivo, a ausência de nova geração e a liberação da URL ao fechar.               |
| Com muitas subcategorias e o ranking habilitado, a tabela continuava com os nomes dos problemas e as colunas, mas a categoria aparecia apenas no título externo. | `TicketMetricsPdfProjection.php` põe a categoria no cabeçalho repetido da tabela consolidada de problemas e do ranking. O teste `repeats category context on continued problem and ranking tables` gera páginas reais e verifica o contexto e o último item.                                                                                                       |
| A mesma placa com hífen não separável era aceita no título e podia aparecer em um modelo armazenado. O padrão de texto tratava apenas o hífen simples.           | `TicketMetricsPdfConfigurationRequest.php`, `VehiclePlate.php`, `ReportPlateRedaction.php` e `ConfiguredTicketMetricsPdf.php` tratam separadores tipográficos para o PDF configurável. O teste envia o título à rota binária, verifica HTTP 422 e confirma a omissão no PDF que contém o modelo. A normalização de cadastro e busca conserva o contrato existente. |
| Na rosca com uma única fatia positiva, o gráfico representava 100% sem a chave numérica que ligava a fatia à legenda.                                            | `TicketMetricsPdfLayout.php` desenha a chave no anel. O teste cobre a posição da fatia única na legenda e a amostra B confirma o resultado renderizado.                                                                                                                                                                                                            |

## Conferência estatística e de filtros

O cenário integrado contabiliza 7 atendimentos, com 2 encerrados, 5 não encerrados, 3 sem classificação e 28,57% de encerramento. Um sistema inativo mantém seu atendimento histórico. A categoria de identificação tem 4 atendimentos e 4 vínculos de problemas, distribuídos em 2, 1 e 1 ocorrência. Os percentuais de 50%, 25% e 25% usam os 4 atendimentos da categoria. O recorte de um único atendimento com três vínculos produz 100% por problema e soma de 300%.

A mesma base tem 6 atendimentos com veículo, 1 sem veículo e 5 veículos distintos. Linha leve e pesada registram 4 e 2 atendimentos, com 3 e 2 veículos distintos. O modelo normalizado da Marca Alfa agrega 3 atendimentos de 2 veículos, com linhas mistas. Modelos homônimos de marcas diferentes são separados e um modelo ausente fica dentro da respectiva marca. Os três agrupamentos de problemas preservam 6 atendimentos e 4 ocorrências. A recorrência do recorte conta um veículo com dois atendimentos. Um atendimento do mesmo veículo na véspera e o extremo de um segundo antes da meia-noite exercitam os limites do período.

O Excel foi lido com PhpSpreadsheet a partir da resposta binária. As planilhas de resumo, sistema, categorias, subcategorias, linhas, marcas, modelos, recorrência e agrupamentos foram cruzadas com os agregados da API. O PDF foi lido a partir dos fluxos de texto do arquivo efetivamente gerado. Os percentuais que aparecem no PDF gerencial e não têm coluna equivalente no Excel, como o encerramento geral, foram conferidos pela fórmula e pelos numeradores e denominadores da base. A planilha de subcategorias apresenta linhas por sistema. O denominador continua sendo o total da categoria no recorte.

## Cenários e inspeção visual

Execute na raiz para gerar os seis documentos em `storage/app/private/pdf/auditoria-final/`. O script usa o Form Request da exportação binária e o gerador da aplicação.

```powershell
php -d memory_limit=768M tests/Support/audit-configured-metrics-pdfs.php
```

| Caso | Arquivo                     | Conferência principal                                                                                                                                    |
| ---- | --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A    | `A-sem-atendimentos.pdf`    | Indicadores selecionados com zero, percentuais sem base, ausência de gráficos vazios e páginas com conteúdo.                                             |
| B    | `B-um-atendimento.pdf`      | Um atendimento identificado, dois problemas com 100% cada e uma única fatia com chave numérica. O PDF informa a aplicação do filtro sem revelar a placa. |
| C    | `C-poucos-atendimentos.pdf` | Sete atendimentos, título personalizado, formatos manuais, unidades e agrupamentos com poucos registros.                                                 |
| D    | `D-grande-volume.pdf`       | 54 atendimentos, 47 modelos e problemas adicionais, cabeçalhos repetidos e preservação dos rótulos.                                                      |
| E    | `E-descricoes-extensas.pdf` | Título e subtítulo longos, categoria e subcategoria extensas e modelo de mais de quatro mil caracteres em tabela, legenda e agrupamento.                 |
| F    | `F-todas-informacoes.pdf`   | As cinco seções, todos os indicadores e as três dimensões de detalhamento, com paginação proporcional ao conteúdo.                                       |

As 78 páginas dos cenários foram rasterizadas com PDFium. Os mosaicos e as páginas de transição foram inspecionados para verificar gráficos, alinhamento, quebras e continuidade das tabelas. Os casos A–F tiveram, respectivamente, 2, 2, 5, 22, 5 e 42 páginas. A extração de texto verificou A4, margens, rodapés numerados, rótulos do volume e o marcador final do modelo longo. A leitura dos metadados verificou a ausência dos identificadores sintéticos. As imagens locais de inspeção ficam em `storage/framework/testing/auditoria-final/`.

No caso E, os segmentos de um único nome de modelo recebiam a mesma margem de parágrafos separados no detalhamento. Com uma categoria e um problema extensos, a última ocorrência aparecia sozinha na sexta página. O template `configured-ticket-metrics.blade.php` e a parcial `configured-metrics-block.blade.php` usam um espaçamento próprio para esses segmentos. A regeneração do caso E reuniu a categoria e seus problemas na quinta página, preservando o final da descrição e o tamanho da fonte. No caso F, a regra de paginação passou a manter juntas as linhas de um título de categoria. O caso regenerado foi conferido página a página.

## Desempenho, recursos e limites conhecidos

O script emite três amostras por cenário. O cronômetro mede a projeção e o Dompdf, e exclui a preparação das fixtures, a validação HTTP e a gravação dos bytes no disco. O pico mede a memória PHP adicional durante cada emissão. Os `SELECTs` da configuração completa permanecem constantes ao passar do recorte vazio aos 54 registros do cenário F. Os agrupamentos não solicitados ficam fora da consulta. O volume de texto e de linhas aumenta o trabalho de composição do documento. O download de uma prévia dispensa nova geração.

| Cenário | Mediana da geração | Maior pico adicional | SELECTs por emissão |
| ------- | -----------------: | -------------------: | ------------------: |
| A       |             217 ms |             22,6 MiB |                  12 |
| B       |             209 ms |             12,4 MiB |                   8 |
| C       |             345 ms |             18,7 MiB |                  12 |
| D       |           2.388 ms |             17,0 MiB |                   9 |
| E       |             706 ms |             14,1 MiB |                   7 |
| F       |           4.597 ms |             31,9 MiB |                  12 |

As amostras variaram com a carga local. O caso F levou de 4.479 a 4.775 ms nesta rodada. Os resultados mostram o custo de composição no volume ensaiado. A contagem de consultas permaneceu constante para a mesma seleção e os rótulos sintéticos.

Os resultados medem o ambiente local com SQLite. Eles não estimam latência e memória em produção com MySQL. O gerador é síncrono e o PDF fica em memória. O tempo de espera do cliente é de dois minutos. O visualizador nativo determina a disponibilidade dos controles e a renderização embutida. Abrir em nova aba ou baixar continua disponível como alternativa. Nomes arbitrários digitados em campos livres podem ser indistinguíveis de descrições gerenciais, mesmo com o bloqueio de formatos identificáveis. A API rejeita HTML e padrões reconhecíveis de placas, referências individuais e contato. A projeção não busca registros de clientes, contatos ou descrições de chamados.

## Arquivos e testes da Etapa 5/5

- Backend de geração e privacidade. `TicketMetricsPdfProjection.php`, `TicketMetricsPdfLayout.php`, `ConfiguredTicketMetricsPdf.php`, `ReportPlateRedaction.php`, `VehiclePlate.php` e `TicketMetricsPdfConfigurationRequest.php`.
- Templates. `configured-ticket-metrics.blade.php`, `configured-metrics-block.blade.php` e `configured-metrics-series.blade.php`.
- Frontend. `MetricsPdfConfigurationModal.vue` e o teste `metrics-pdf-configuration.spec.js`.
- Evidências e documentação. `ConfiguredTicketMetricsPdfTest.php` cobre a comparação dos três formatos, 14 recortes, contexto nas tabelas, placa tipográfica e invariância das medidas por formato. O novo `audit-configured-metrics-pdfs.php` gera os seis casos e as amostras de desempenho. Os guias `statistical-reports.md` e `dashboard-metrics.md` descrevem o fluxo atual e apontam para esta auditoria.

## Resultado das verificações

| Verificação           | Comando                                                                                                             | Resultado final                                        |
| --------------------- | ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| Suíte Laravel         | `php -d memory_limit=768M vendor/bin/pest --compact`                                                                | 486 executados e aprovados, 5.921 asserções, 0 falhas. |
| Suíte Vue             | `npm.cmd run test:unit -- --run --maxWorkers=1` em `frontend/`                                                      | 247 testes em 15 arquivos, 0 falhas.                   |
| Análise estática PHP  | `php vendor/bin/phpstan analyse --memory-limit=768M --no-progress`                                                  | Aprovada, 0 erros.                                     |
| Formatação PHP        | `php vendor/bin/pint --test`                                                                                        | Aprovada.                                              |
| Tipagem e qualidade   | `npm.cmd run types:check` e `npm.cmd run check` na raiz                                                             | Aprovadas.                                             |
| Formatação Vue        | `npm.cmd exec prettier -- --check src/ vite.config.js vite.qa.config.js vitest.config.js index.html` em `frontend/` | Aprovada.                                              |
| Produção Vue          | `npm.cmd run build` em `frontend/`                                                                                  | Aprovada, 99 módulos.                                  |
| Ambiente e alterações | `composer validate --no-check-publish`, `composer check-platform-reqs` e `git diff --check`                         | Aprovados.                                             |

Uma execução do Vitest teve tempo limite na inicialização de workers. A execução completa com um worker terminou sem falhas. O Vite emitiu um aviso informativo de tempo dos plugins durante o build.

## Última validação manual

1. Em **Dashboard → Métricas**, aplique um recorte conhecido. Abra **Exportar PDF**, selecione uma seção e compare os totais do arquivo com a aba e o Excel.
2. Selecione problemas com múltiplas subcategorias e inclua uma dimensão veicular adicional. Gere a prévia, confira as páginas e baixe o arquivo. Em um navegador com visualizador nativo, confira as mesmas páginas e a mesma emissão.
3. Volte à configuração, confira os campos preservados, altere o subtítulo e gere novamente. Use **Exportar PDF** em uma emissão separada. Em tela estreita, confira o acesso ao rodapé e o retorno do foco ao fechar.
4. Use as amostras A, E e F para conferir zeros, rótulos extensos e a continuidade das tabelas. Verifique também a planilha e o PDF individual pelos botões existentes.
