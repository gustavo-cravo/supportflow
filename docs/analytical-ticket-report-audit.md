# Auditoria final do relatório analítico de atendimentos

## Parecer

A auditoria verificou a seleção, a integridade dos dados, a emissão em PDF, o retorno ao rascunho e os downloads. O layout aprovado foi reproduzido. A aprovação integral fica condicionada à confirmação visual da pré-visualização em um navegador de desktop compatível. A emissão e o download foram verificados no navegador de QA, que exibiu o quadro incorporado em branco nos dois relatórios de Métricas.

As verificações operaram com registros sintéticos em SQLite em memória, no SQLite isolado do navegador e em um esquema próprio no MySQL descartável. O contrato, catálogo, padrões e instruções de utilização estão no [guia do relatório analítico](analytical-ticket-report.md).

## Matriz de conformidade

| Área                                             | Resultado                          | Evidência                                                                                                                                                                                                                                                                                     |
| ------------------------------------------------ | ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Catálogo e padrões                               | Aprovado                           | Três seções, 17 campos e valores padrão conferidos na API e no modal. O fechamento descarta escolhas temporárias.                                                                                                                                                                             |
| Filtros e consistência com Métricas              | Aprovado                           | O endpoint de PDF entrou na matriz de 14 recortes em SQLite. Os cenários incluem todos os filtros, sistema inativo, múltiplas subcategorias, combinação e recorte vazio.                                                                                                                      |
| Seleção híbrida e contagem                       | Aprovado                           | Testes de `all`, `all_except` e `only` cobrem paginação, alteração de elegibilidade, duplicatas, IDs inválidos, recorte vazio e contagem efetiva na API e no modal.                                                                                                                           |
| Agrupamento, ordem e ausências                   | Aprovado                           | Cinco agrupamentos, três ordens, desempates, dependências e os três modos de vazios cobertos. Modelo depende de Marca e Modelo.                                                                                                                                                               |
| Personalização e rascunho                        | Aprovado                           | Título automático e manual, subtítulo, paginação, colunas e espaçamento. Testes Vue preservam a aba, a página, o recorte e as exclusões ao retornar.                                                                                                                                          |
| Interface e erros                                | Aprovado                           | Navegação por teclado, seleção parcial, estados vazios, 422, 500, rede, timeout, binário inválido, tentativas duplicadas e cancelamento cobertos pelas suítes. Em 390 px, houve rolagem interna da configuração e as ações dos dois estados do modal ficaram visíveis sem rolagem horizontal. |
| Pré-visualização e integridade binária           | Emissão e bytes aprovados          | A API devolveu `application/pdf` com disposição `inline`. O download usou uma única geração e reproduziu o SHA-256 da resposta.                                                                                                                                                               |
| Renderização incorporada no navegador de desktop | Pendente                           | Quadro em branco reproduzido no navegador de QA. O Chrome de desktop permaneceu indisponível para a automação.                                                                                                                                                                                |
| Exportação direta                                | Aprovado                           | Um POST com `attachment`, arquivo válido, hash compatível e seleção coerente. Os testes verificaram a preservação dos registros originais.                                                                                                                                                    |
| Inspeção dos PDFs                                | Aprovado                           | Oito amostras regeneradas, 57 páginas conferidas e comparadas com a etapa anterior.                                                                                                                                                                                                           |
| MySQL                                            | Aprovado para o ambiente isolado   | 15 recortes, 16 PDFs, três modos de seleção e cinco agrupamentos de modelo em MySQL 8.4.11.                                                                                                                                                                                                   |
| Privacidade dentro do perímetro atual            | Aprovado com limitação conhecida   | Validação de chaves e elegibilidade, campos desmarcados, escape Blade, resposta privada e logs genéricos. A autorização individual continua necessária para perfis distintos.                                                                                                                 |
| Exportações anteriores                           | Aprovado em regressão automatizada | PDF estatístico, exportação estatística em Excel e PDF individual passaram nas suítes existentes.                                                                                                                                                                                             |

## Pré-visualização, exportação e segurança

Na comparação de QA, o relatório analítico e o estatístico usaram o mesmo `MetricsPdfPreview`, receberam `application/pdf` com `inline` e exibiram um iframe com altura útil e `src` `blob:` igual ao link alternativo. O iframe analítico permaneceu montado ao iniciar o download e mostrou o aviso de espera. Os dois quadros permaneceram em branco. A captura dos logs do navegador não retornou erro nem aviso nesses fluxos. Os testes Vue verificam a revogação da URL no descarte e o acesso ao Blob enquanto o visualizador está aberto. A inspeção da configuração do aplicativo não encontrou regra de CSP própria para bloquear esse iframe.

O resultado é compatível com uma limitação de renderização do ambiente de QA, mas a causa final exige reprodução em navegador de desktop. A restrição à abertura de Blob em nova aba já havia sido observada na Etapa 4. As duas verificações disponíveis, o download e a leitura offline, foram aprovadas e são independentes da confirmação visual no navegador.

A pré-visualização analítica baixada incluiu 10 atendimentos em seis páginas, com uma exclusão. Um único POST precedeu o download e o SHA-256 da resposta e do arquivo foi `2bd9b4ee0c6e5353ed1306bc141256a98d1f19cb04ce850c75642069045390e7`. A exportação direta, em nova sessão com os padrões, incluiu 11 atendimentos em seis páginas e fez um único POST próprio. Seu resultado coincidia com `X-Report-Selected-Count` e o arquivo reproduziu o corpo da resposta `attachment`.

O PDF é entregue com `private, no-store` e `nosniff`. A geração mantém o PDF em memória e o nome do arquivo é fixado por data. Campos estruturados desmarcados não foram incorporados automaticamente aos PDFs verificados. A referência ao atendimento anterior contém a referência aprovada quando selecionada. Textos livres são preservados, logo a seleção de campos não constitui anonimização. O perímetro atual não aplica autorização individual por atendimento. Ambientes com níveis distintos de acesso dependem dessa autorização antes da utilização.

## Ocorrências e correções

| Gravidade                                     | Ocorrência                                                                                                                                                          | Resultado                                                                                                                                                                                                                                                                                                                                                                                                                     |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Média                                         | A sugestão automática de título usava a configuração inicial mesmo após retirar a marca usada como filtro. O gerador já recalculava o título pelos campos efetivos. | Corrigido em `useAnalyticalReport.js`. A sugestão é atualizada pelo endpoint existente quando muda um campo relevante. A resposta anterior é cancelada e ignorada. A suíte Vue reproduziu a divergência antes do ajuste e confirmou a atualização, o descarte de resposta atrasada e a preservação de título manual depois dele. No navegador local, desmarcar Sistema retirou o qualificador da sugestão retornada pela API. |
| Pendente de ambiente                          | Painel PDF em branco no QA incorporado.                                                                                                                             | Geração, envio, ciclo de vida do Blob e download foram verificados. A renderização visual em navegador compatível permanece aberta.                                                                                                                                                                                                                                                                                           |
| Restrição de implantação                      | Ausência de autorização individual por atendimento.                                                                                                                 | Registrada como condição para uso com perfis de acesso distintos.                                                                                                                                                                                                                                                                                                                                                             |
| Sugestões futuras já aprovadas para adiamento | Repetição de rótulos e contexto, compactação e realce entre blocos.                                                                                                 | O layout aprovado foi conservado.                                                                                                                                                                                                                                                                                                                                                                                             |

O resultado da auditoria não aponta falha funcional crítica ou alta reproduzida na implementação dentro do perímetro aprovado. A confirmação da visualização pelo navegador habitual é critério de aceitação da funcionalidade.

## Amostras e integridade de conteúdo

Os arquivos sintéticos ficam em `storage/app/private/pdf/analitico/`. As 57 páginas foram rasterizadas em Poppler, conferidas visualmente e comparadas por hash com as imagens da Etapa 3. Formato A4, margens, cabeçalhos, rodapés, ordem, numeração, agrupamentos e continuações mantiveram o resultado aprovado. Foram verificadas as coordenadas do texto e a presença de conteúdo nas páginas. As páginas esparsas do modo individual mantêm a continuidade prevista, sem sobreposição ou páginas vazias indevidas.

| Amostra                | Arquivo                       | Atendimentos | Páginas |
| ---------------------- | ----------------------------- | -----------: | ------: |
| A. Completo            | `A-completo.pdf`              |            3 |       3 |
| B. Validação           | `B-setor-validacao.pdf`       |            3 |       3 |
| C. Vazios inteligentes | `C-vazios-inteligentes.pdf`   |            3 |       3 |
| D. Compacto            | `D-paginacao-compacta.pdf`    |           10 |       8 |
| E. Individual          | `E-paginacao-individual.pdf`  |            3 |      12 |
| F. Descrições extensas | `F-descricoes-extensas.pdf`   |            1 |       9 |
| G. Grande volume       | `G-grande-volume.pdf`         |           34 |      18 |
| H. Seleção mínima      | `H-personalizacao-minima.pdf` |            2 |       1 |

No cenário F foram conferidos 25 marcadores de descrição, 19 de modelo, 20 de solução, 16 de verificação e os quatro trechos finais, todos em uma única ocorrência por marcador. No G, os dois títulos excluídos estavam ausentes e 34 foram encontrados uma vez cada. O H confirmou que placa, sistema, contato, cliente e títulos desmarcados não reaparecem no cabeçalho nem nos blocos. O A conservou subcategorias e a referência anterior sem expandir o conteúdo do atendimento referenciado.

## MySQL isolado

O roteiro `tests/Support/audit-analytical-ticket-mysql.php` confirmou a porta `33317` e o diretório `storage/framework/testing/subcategory-mysql-qa` antes de criar o esquema sintético `supportflow_analytical_qa_12007dfc`. A conexão Laravel foi novamente verificada antes das migrations. Os testes compararam IDs de Métricas com a listagem analítica e verificaram o limite inclusivo da data, sistema inativo, múltiplos vínculos de subcategoria, filtros veiculares, agrupamento normalizado de modelos, ordens e desempates, seleção híbrida e rejeição fora do recorte. Os registros de atendimentos permanecem idênticos ao início do teste. O roteiro pode ser repetido com a instância descartável já iniciada no loopback. Cada execução cria um esquema sintético novo.

## Desempenho

O roteiro `tests/Support/audit-analytical-ticket-performance.php` usa um processo PHP e SQLite em memória por carga, com `memory_limit=768M`. Ele mede preparação e renderização, após montar os dados de teste. O cenário com um atendimento usa os 17 campos padrão. Dez atendimentos usam paginação individual e espaçamento ampliado. De 50 a 250 atendimentos, o mesmo conjunto de oito campos usa agrupamento por modelo e paginação compacta. A cada cinco registros, a fixture acrescenta parágrafos de descrição e solução.

| Atendimentos | Páginas | Tamanho em bytes | Tempo de duas execuções | SELECTs |  Pico PHP | Resultado |
| -----------: | ------: | ---------------: | ----------------------: | ------: | --------: | --------- |
|            1 |       1 |           24.330 |              223–235 ms |      10 |  59,0 MiB | Aprovado  |
|           10 |      20 |           48.667 |              487–610 ms |      10 |  60,7 MiB | Aprovado  |
|           50 |      33 |           84.336 |          1.215–1.270 ms |       6 |  74,8 MiB | Aprovado  |
|          100 |      65 |          143.578 |          2.424–2.482 ms |       6 |  96,1 MiB | Aprovado  |
|          250 |     161 |          321.578 |          7.820–8.269 ms |      11 | 170,6 MiB | Aprovado  |

Os picos adicionais sobre a memória antes da geração foram 25,3, 27,1, 41,1, 62,4 e 136,9 MiB, na mesma ordem. Para 50 e 100 atendimentos com o mesmo perfil, a contagem de SELECTs permaneceu em seis. Aos 250, o segundo lote de 200 elevou a contagem a 11. O tempo de renderização cresceu mais que a proporção de registros nesse intervalo, mesmo com consultas em lote.

As medições são locais. Volume, extensão do texto, opções do PDF, configuração do servidor e concorrência influenciam tempo e memória. O relatório é síncrono e o documento completo permanece em memória durante a emissão. O cliente usa prazo de 120 segundos para o PDF.

## Suítes, arquivos e reprodução

| Verificação                                        | Resultado                                   |
| -------------------------------------------------- | ------------------------------------------- |
| Laravel completo                                   | 588 aprovados, 6.894 asserções, zero falhas |
| Vue completo                                       | 274 aprovados em 16 arquivos, zero falhas   |
| PHPStan                                            | Zero erros                                  |
| Pint, Prettier, tipagem, lint e `git diff --check` | Aprovados                                   |
| Build de produção do frontend                      | Aprovado com 108 módulos                    |

Foram acrescentadas duas regressões Vue. A matriz Laravel existente recebeu 81 asserções de integração ao verificar também a resposta do gerador real para cada recorte.

- Roteiros criados em `tests/Support/audit-analytical-ticket-performance.php` e `tests/Support/audit-analytical-ticket-mysql.php`.
- Correção e testes Vue em `frontend/src/composables/useAnalyticalReport.js` e `frontend/src/__tests__/analytical-report.spec.js`.
- Emissão real de 14 recortes, incluindo a rejeição do recorte vazio, acrescentada à matriz existente em `tests/Feature/Api/AnalyticalTicketReportTest.php`.
- Documentação atualizada em `docs/analytical-ticket-report.md` e `docs/api.md`.

Para regenerar as amostras, execute `php -d memory_limit=768M tests/Support/generate-analytical-ticket-pdfs.php`. Para repetir uma carga, execute `php -d memory_limit=768M tests/Support/audit-analytical-ticket-performance.php 100` e substitua o volume por 1, 10, 50, 100 ou 250. Os logs de auditoria e os PNGs ficam em `storage/framework/testing`, fora da área pública e do Git.

## Conferência final pelo painel

1. No navegador de desktop habitual, use **Dashboard → Métricas → Relatório analítico (PDF)** com um recorte conhecido. Desmarque um atendimento e um campo que participa do título automático.
2. Em **Apresentação**, confira a sugestão atualizada. Abra **Pré-visualizar** e confirme se a primeira página é visível e a navegação funciona. Registre o navegador e a versão se o painel permanecer em branco.
3. Volte às configurações e confira as escolhas. Gere outra pré-visualização. Use **Baixar PDF** para comparar o arquivo com a página visualizada e confirme o POST único pelo painel de rede.
4. Em outra sessão, selecione **Exportar PDF** diretamente e confira os documentos do mesmo recorte. Repita os controles do modal em uma largura estreita, se aplicável.
