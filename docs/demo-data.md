# Base fictícia para validação final do SupportFlow

## Execução explícita e segura

Em um banco **local/de teste separado**, com as migrations atuais já aplicadas:

```powershell
php artisan db:seed --class=SupportFlowValidationSeeder
```

O comando anterior `php artisan db:seed --class=SupportFlowDemoSeeder` continua disponível e chama exatamente o mesmo conjunto. O `DatabaseSeeder` padrão permanece separado, sem chamar a população de validação. Não são geradas novas contas ou senhas. Tabelas de infraestrutura (sessões, cache, filas e tokens) não recebem registros fictícios que poderiam interferir no funcionamento da aplicação.

O seeder recusa ambientes diferentes de `local` e `testing`, inclusive quando `db:seed --force` é utilizado. A conexão é aquela explicitamente configurada pelo usuário; `APP_ENV=local` não transforma um banco real em banco de testes. Para esta entrega, todas as execuções de validação ocorreram exclusivamente em SQLite isolado em memória, sem utilizar o MySQL configurado na aplicação.

Não há exclusões, limpeza, `truncate`, alterações de esquema ou restauração de dados antigos. A implementação anterior de demonstração foi substituída por uma entrada de compatibilidade: os antigos registros `[DEMO SF]`, quando existentes, permanecem intactos e **não são convertidos nem removidos**.

## Identidade e reexecução

Cada registro possui uma chave estável no início do campo textual correspondente, por exemplo:

```text
SFT-QA SIS-001 | Indicar fictício
SFT-QA CAT-001 | Falha de acesso
SFT-QA SUB-001 | Sessão expirada
SFT-QA CON-001 | Ana Exemplo
SFT-QA CLI-001 | Oficina Exemplo
SFT-QA ATE-001 | Retorno: Falha de acesso
SFT-QA PEN-001 | Conferir evidências após encerramento
SFT-QA LEM-001 | Retorno vencido
SFT-QA ART-001 | Operador não consegue acessar a consulta
```

Notas/descrições também contêm o marcador `SFT-QA:validation:v1`. Os códigos são identificadores do conjunto fictício, **não chaves primárias do banco**. Todos os relacionamentos utilizam os IDs efetivamente criados pelo Eloquent.

Na primeira execução, todo o conjunto é criado em uma transação. Na repetição, sua identidade e completude são conferidas e os registros existentes são apenas consultados: IDs, datas, status e edições manuais são preservados. Não há atualização automática para trazer os atendimentos novamente para “hoje”. Para uma nova rodada com datas recentes, prepare outro banco de testes separado; o seeder não apaga a rodada anterior.

Se houver uma identidade duplicada, removida, incompleta ou em conflito com um cadastro manual, o processo é recusado sem escolher registros ou tentar reconstruí-los. Preserve os códigos e marcadores ao editar a base. A execução não tenta decidir que um registro real com nome semelhante pertence ao conjunto. Execute uma população por vez; não há coordenação de duas primeiras execuções simultâneas.

**Os totais da referência abaixo pertencem somente ao conjunto SFT-QA.** O Dashboard e as exportações continuam contabilizando todos os registros que correspondam aos filtros. Em uma base com dados preexistentes, selecione um dos sistemas SFT-QA para conferir seu resultado específico. Para comparar os totais globais exatos, utilize uma base inicialmente sem dados de domínio.

## Entidades criadas

| Entidade | Quantidade | Cobertura |
| --- | ---: | --- |
| Sistemas | 3 | Indicar e iLube ativos; Legado inativo |
| Categorias | 3 | Duas ativas e uma inativa; compartilhamento e exclusividade |
| Subcategorias | 7 | Três de acesso, duas de placa e duas de integração; duas inativas |
| Contatos | 5 | Nomes fictícios, emails reservados e campos opcionais ausentes |
| Clientes | 3 | Documento sintético de 14 dígitos, de 11 dígitos e ausente |
| Atendimentos | 24 | Cinco status, três retornos, seis sem cliente, três sem classificação |
| Pendências | 8 | Cinco abertas e três concluídas; cinco vinculadas e três independentes |
| Lembretes | 8 | Vencidos, hoje, futuros e concluídos; quatro vinculados e quatro independentes |
| Artigos | 5 | Três específicos de sistemas, incluindo legado, e dois gerais |
| Associações categoria/sistema | 6 | Incluem um vínculo histórico desabilitado |
| Associações subcategoria/sistema | 14 | Incluem os vínculos históricos correspondentes |

Os contatos e empresas são curados e inteiramente fictícios. Emails usam `sft-qa.example.test`; telefones usam zeros deliberadamente. CPF/CNPJ são sequências sintéticas sem validade cadastral, adequadas à validação atualmente existente. Não há relacionamento direto fictício entre contato e cliente: essa combinação continua representada pelo atendimento.

O terceiro cliente representa um cadastro histórico anterior à obrigatoriedade do documento. Sua ausência é aceita pelo esquema nullable e pela edição de históricos; o cadastro de novos clientes pela API continua exigindo CPF/CNPJ.

Os procedimentos da base de conhecimento reaproveitam o catálogo curado existente, incluindo acesso, placa, consulta ao histórico, rede e preparação de evidências. A tabela real possui `problem`, `resolution_procedure` e `system_id`, sem vínculo de classificação; nenhum campo inexistente foi introduzido.

## Sistemas e classificações

As categorias são `CAT-001` Falha de acesso, `CAT-002` Erro na busca por placa e `CAT-003` Integração de dados. Todas recebem o prefixo SFT-QA no cadastro.

- Acesso: associado aos três sistemas. Sessão expirada atende os três; credenciais inválidas atende os dois ativos; usuário bloqueado atende os três e está inativo.
- Placa: exclusivo do Indicar, com placa não encontrada e tempo de consulta esgotado.
- Integração: associado historicamente ao Indicar e iLube, com fila interrompida e mapeamento divergente. A categoria e mapeamento estão inativos; a disponibilidade para iLube foi desabilitada na categoria e nas suas subcategorias, mantendo as linhas históricas.

Os cadastros, vínculos e atendimentos históricos são inseridos antes das desativações, dentro da primeira transação. Os quatro atendimentos do Legado estão encerrados antes da desativação do sistema, ocorrida há 60 dias. As classificações inativadas/desabilitadas também possuem ocorrências anteriores às mudanças. Novos cadastros pela API continuam sujeitos às mesmas regras de sistemas e classificações ativos.

Há três retornos: `ATE-001 → ATE-009`, `ATE-002 → ATE-015` e `ATE-015 → ATE-018`. Os atendimentos anteriores são mais antigos, mantêm sistema/contato/cliente coerentes e não formam ciclos. Encerramentos continuam independentes.

## Referência estatística — primeira execução em 23/11/2026

As datas são calculadas na primeira execução com `America/Sao_Paulo`. A tabela abaixo foi conferida com referência em **23/11/2026 às 15h**. Executar pela primeira vez em outra data muda os meses e os filtros relativos; o comando imprime uma referência calculada a partir dos registros efetivamente existentes.

### Indicadores e status

Selecione **Todo o histórico** para obter o total completo:

| Indicador | Esperado |
| --- | ---: |
| Total | 24 |
| Encerrados (`closed`) | 10 |
| Não encerrados | 14 |
| Sistemas com atendimentos | 3 |
| Sem classificação | 3 |
| Abertos (`open`) | 4 |
| Em andamento (`in_progress`) | 3 |
| Aguardando retorno (`waiting`) | 4 |
| Resolvidos (`resolved`) | 3 |

Resolvidos continuam no grupo **Não encerrados**, conforme a regra existente. Na Visão geral, os cinco cartões operacionais ficam **4 / 3 / 4 / 2 / 2**: abertos, em andamento, aguardando retorno, pendências atrasadas e lembretes de hoje.

### Distribuição por sistema e categoria

| Sistema | Total | Encerrados | Não encerrados | Participação |
| --- | ---: | ---: | ---: | ---: |
| Indicar fictício | 13 | 5 | 8 | 54,17% |
| iLube fictício | 7 | 1 | 6 | 29,17% |
| Legado fictício | 4 | 4 | 0 | 16,67% |
| **Total** | **24** | **10** | **14** | **100%** |

| Categoria | Ocorrências | Participação no total |
| --- | ---: | ---: |
| Falha de acesso | 12 | 50% |
| Erro na busca por placa | 5 | 20,83% |
| Integração de dados | 4 | 16,67% |
| Sem classificação | 3 | 12,50% |

Arredondamentos podem fazer os percentuais exibidos somarem 100,01%.

### Subcategorias

| Categoria | Subcategoria | Ocorrências | Na categoria |
| --- | --- | ---: | ---: |
| Acesso | Sessão expirada | 5 | 41,67% |
| Acesso | Credenciais inválidas | 2 | 16,67% |
| Acesso | Usuário bloqueado, inativa | 3 | 25% |
| Acesso | Sem subcategoria | 2 | 16,67% |
| Placa | Placa não encontrada | 2 | 40% |
| Placa | Tempo de consulta esgotado | 2 | 40% |
| Placa | Sem subcategoria | 1 | 20% |
| Integração | Fila interrompida | 2 | 50% |
| Integração | Mapeamento divergente, inativa | 1 | 25% |
| Integração | Sem subcategoria | 1 | 25% |
| Sem classificação | Sem classificação | 3 | 100% |

São quatro atendimentos classificados sem subcategoria. Os três sem categoria ficam em um grupo distinto. Percentuais do Dashboard e dos relatórios continuam sendo calculados sobre o conjunto filtrado, não sobre um total fixo da referência.

### Distribuição de datas

As métricas usam **`support_tickets.created_at`**, não `updated_at` nem `closed_at`. São preenchidos abertura, atualização e, quando aplicável, encerramento e confirmação. Datas finais nunca antecedem a abertura nem ultrapassam o instante de geração.

| Idade em dias de calendário | Quantidade |
| --- | ---: |
| Hoje | 3 |
| Ontem | 1 |
| 2 a 7 dias | 3 |
| 8 a 15 dias | 3 |
| 16 a 30 dias | 4 |
| 31 a 60 dias | 3 |
| 61 a 90 dias | 3 |
| Mais de 90 dias | 4 |

Os deslocamentos exatos dos códigos `ATE-001` a `ATE-024` são: **0, 0, 0, 1, 2, 4, 7, 9, 12, 15, 18, 22, 27, 30, 35, 45, 58, 65, 75, 88, 105, 130, 200 e 400 dias**. Cada abertura ocorre entre 9h e 15h; para os registros de hoje, o horário é limitado ao instante da execução. Isso mantém a coerência também próximo da meia-noite.

| Mês de abertura | Quantidade |
| --- | ---: |
| Outubro/2025 | 1 |
| Maio/2026 | 1 |
| Junho/2026 | 0 |
| Julho/2026 | 1 |
| Agosto/2026 | 2 |
| Setembro/2026 | 3 |
| Outubro/2026 | 4 |
| Novembro/2026 | 12 |

Junho zerado aparece no gráfico de períodos limitados. O filtro Todo o histórico continua exibindo apenas os meses observados, conforme a API existente.

### Filtros para conferir manualmente

| Seleção | Total | Encerrados | Não encerrados | Sem classificação |
| --- | ---: | ---: | ---: | ---: |
| Todo o histórico | 24 | 10 | 14 | 3 |
| Este mês | 12 | 3 | 9 | 1 |
| Últimos 30 dias | 13 | 3 | 10 | 1 |
| Últimos 3 meses | 19 | 6 | 13 | 2 |
| **Últimos 6 meses, padrão da aba** | **22** | **8** | **14** | **2** |
| Este ano | 23 | 9 | 14 | 2 |
| Histórico + Indicar | 13 | 5 | 8 | 2 |
| Histórico + Falha de acesso | 12 | 5 | 7 | 0 |
| Histórico + Sessão expirada | 5 | 2 | 3 | 0 |
| Histórico + Indicar + acesso + sessão | 2 | 1 | 1 | 0 |
| Histórico + Sem classificação | 3 | 1 | 2 | 3 |
| Histórico + Sem subcategoria | 4 | 1 | 3 | 0 |
| Personalizado 17/11/2026 a 23/11/2026 | 6 | 1 | 5 | 0 |
| Personalizado 01/10/2026 a 31/10/2026 | 4 | 2 | 2 | 0 |

“Últimos 30 dias” inclui hoje e os 29 dias anteriores; portanto, o registro de 30 dias atrás fica de fora. Três/seis meses incluem o mês atual e os anteriores, com limite final em hoje. Na consulta de ocorrências, o histórico completo oferece duas páginas de 15 registros, sem repetição entre páginas.

## Pendências, lembretes e relatórios

Pendências: duas abertas atrasadas, duas abertas com prazo futuro, uma aberta sem prazo e três concluídas com observações e data. A pendência de acompanhamento de `ATE-009` permanece aberta embora esse atendimento esteja encerrado, condição permitida pelo sistema. Uma pendência pode ser registrada depois do encerramento para acompanhamento, sem reabrir automaticamente o atendimento.

Lembretes: dois de ontem, dois de hoje (11h e 12h), dois futuros e dois concluídos. Depois desses horários, os lembretes de hoje também entram em “atrasados”, conforme a API; continuam sendo dois lembretes no filtro “hoje”. Existem registros vinculados e independentes.

PDF e XLSX podem ser conferidos com os mesmos filtros da tabela. Não foi alterada nenhuma funcionalidade dos relatórios. Eles continuam contendo apenas indicadores e agrupamentos consolidados; códigos `ATE`, contatos, clientes, telefones, documentos e descrições individuais não são exportados. Prefixos de sistemas/categorias/subcategorias identificam agrupamentos fictícios, não atendimentos individuais.

## Arquivos e validação

- `database/seeders/SupportFlowValidationSeeder.php`: seeder central, segurança, identidade, criação e relacionamentos.
- `database/seeders/SupportFlowDemoSeeder.php`: entrada anterior delegando ao seeder central.
- `database/seeders/SupportFlowDemoCatalog.php`: catálogo curado reutilizado e matriz dos 24 cenários/classificações.
- `database/seeders/SupportFlowValidationReference.php`: resumo exclusivo do conjunto e tabelas de referência impressas ao executar.
- `tests/Feature/SupportFlowValidationSeederTest.php`: substitui os testes do antigo conjunto de 90 atendimentos, cobrindo a base atual.
- `docs/demo-data.md`: este guia.

Não foram modificados models, migrations, controllers, endpoints, componentes Vue, serviços estatísticos ou geradores de relatórios. A única factory existente é de usuário, que não representa dados do domínio de suporte; nenhum uso artificial foi acrescentado a ela.

As verificações executam o comando solicitado em SQLite em memória, com chaves estrangeiras ativas; comparam reexecuções no mesmo dia e cinco dias depois, incluindo edições e registros preexistentes; recusam ambientes indevidos mesmo com `--force`; e verificam rollback de falhas, classificações dependentes, vínculos, datas, métricas e relatórios reais. O teste isolado de referência também compara as 11 tabelas após a repetição. Os arquivos gerados localmente em `storage/framework/testing/evidence/validation-seeder-reference.json` e `validation-seeder-console.txt` registram a referência completa, sem acesso ao banco configurado da aplicação.

Resultados executados nesta revisão:

- Suíte Laravel completa: **316 testes e 3.822 verificações aprovados**, incluindo **25 casos específicos** da população de validação.
- PHPStan: aprovado, sem erros.
- Pint nos seeders e no teste atualizado: aprovado.
- `php -l` nos quatro arquivos de seeders/catálogo/referência e no teste: aprovado.
- `git diff --check`: aprovado.
- Execução independente em SQLite em memória: conjunto completo criado, repetição das 11 tabelas sem alterações e `PRAGMA foreign_key_check` sem violações.
- API: opções dependentes, sistema inativo, históricos, filtros de pendências/lembretes, Dashboard, paginação, métricas e geração real de PDF/XLSX verificados. O XLSX contém os totais esperados e não contém identificadores ou campos individuais do conjunto.

Limitações: a reexecução preserva as datas iniciais; registros preexistentes contribuem para o Dashboard; a validação de escrita desta entrega não utiliza o MySQL da aplicação; primeiras execuções simultâneas não são suportadas. Nenhuma dessas condições altera as regras do SupportFlow.
