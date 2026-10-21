# Dados de demonstração do SupportFlow

## Executar explicitamente

Com as dependências e migrations já instaladas, na raiz do projeto:

```powershell
php artisan db:seed --class=SupportFlowDemoSeeder
```

O comando usa a conexão já configurada no ambiente local. Não altera `.env`, credenciais ou migrations. A geração só é permitida quando `APP_ENV` é `local` ou `testing`; outros ambientes, inclusive `production` e `staging`, são recusados pelo próprio seeder. A classe não é registrada no `DatabaseSeeder`, nas migrations ou na inicialização da aplicação. No CI, só é chamada pelos testes isolados, sem uma etapa de população do banco da aplicação. Nenhum usuário ou senha de autenticação é gerado.

## Quantidades e cenários

O conjunto tem **260 registros**, além de quaisquer registros que já existam:

| Entidade             | Quantidade | Distribuição                                                           |
| -------------------- | ---------- | ---------------------------------------------------------------------- |
| Sistemas             | 8          | 6 ativos, 2 inativos com histórico                                     |
| Contatos             | 26         | Pessoas fictícias em `pt_BR`, com dados opcionais variados             |
| Clientes             | 20         | Empresas fictícias em `pt_BR`; vários contatos atendem a mesma empresa |
| Atendimentos         | 90         | 75 iniciais e 15 retornos relacionados                                 |
| Pendências           | 48         | 36 vinculadas, 12 independentes                                        |
| Lembretes            | 48         | 36 vinculados, 12 independentes                                        |
| Base de conhecimento | 20         | 16 artigos de sistemas e 4 gerais                                      |

Atendimentos: **19 abertos, 16 em andamento, 18 aguardando retorno, 9 resolvidos e 28 encerrados**. Há históricos com meses de uso, registros recentes, solicitações sem cliente, resoluções confirmadas pelo contato ou pelo setor responsável e casos que dependem do consultor. Os retornos reutilizam sistema, contato, cliente e contexto do problema anterior, mas mantêm status e encerramentos próprios. Um caso tem o anterior encerrado e o retorno aberto.

Os sistemas têm, respectivamente, 27, 16, 14, 7, 7, 7, 6 e 6 atendimentos. Os dois últimos são legados desativados; seus atendimentos são históricos encerrados antes da desativação. O seeder popula esse histórico enquanto os sistemas estão ativos e os desativa no final da mesma transação. A API continua rejeitando novos atendimentos em sistemas inativos.

Pendências: 12 abertas atrasadas, 12 abertas com prazo futuro, 12 abertas sem prazo e 12 concluídas com data e observações. Algumas atividades de acompanhamento foram registradas depois do encerramento do atendimento histórico; elas não são concluídas automaticamente por esse encerramento.

Lembretes: 12 atrasados, 12 previstos para hoje, 12 futuros e 12 concluídos. As datas são relativas à execução em `America/Sao_Paulo`, inclusive próximo da meia-noite. Um lembrete de hoje cujo horário já passou também aparece como atrasado, conforme os filtros existentes da API. Lembretes concluídos não entram nos indicadores pendentes.

Em uma base inicialmente sem dados de domínio, o dashboard mostra **19 / 16 / 18 / 12 / 12** nos indicadores de abertos, em andamento, aguardando retorno, pendências atrasadas e lembretes do dia. Registros reais preexistentes continuam contribuindo normalmente para esses totais. As listas resumidas mantêm o limite de cinco itens; as páginas de atendimento têm volume para seis páginas de 15 registros.

Os problemas incluem OS, impressão, consulta de placas, rejeição fiscal, certificado, estoque, importação de cadastros, acesso, sessão, webhooks e lotes de integração. Os artigos incluem procedimentos específicos e orientações gerais de rede, evidências, backup e navegador. Os textos são modelos curados; Faker é usado para nomes de pessoas, empresas, cidades e telefones. Emails usam o domínio reservado `supportflow.example.test`.

## Identificação, reexecução e preservação

Todos os registros gerados têm uma chave estável no início de um campo textual existente:

```text
[DEMO SF] SIS-001 | Gestão de Oficinas
[DEMO SF] CON-001 | Nome fictício
[DEMO SF] CLI-001 | Empresa fictícia
[DEMO SF] ATE-001 | Ordem de serviço não permite finalizar
[DEMO SF] PEN-001 | Solicitar evidência atualizada do problema
[DEMO SF] LEM-001 | Retornar ao contato sobre o atendimento
[DEMO SF] ART-001 | Ordem de serviço não permite finalizar
```

As chaves ficam em `name`, `title`, `description` ou `problem`, conforme a entidade. Os números são identificadores do conjunto, **não IDs do banco**. As associações usam as instâncias retornadas pelo Eloquent, respeitando quaisquer IDs já ocupados. Não é criado relacionamento direto entre contato e cliente: essa associação é representada pelos atendimentos, como na modelagem existente.

Repetir o comando restaura os mesmos 260 registros e seus relacionamentos, mantendo seus IDs e recalculando as datas relativas. Alterações manuais feitas nesses registros de demonstração são substituídas, inclusive status e conclusões. A busca usa a chave reservada antes do nome; alterar apenas o nome depois de `|` não provoca duplicação.

Registros que não têm uma das chaves reservadas não são atualizados ou excluídos. O prefixo sozinho, sem o código completo e seu delimitador, não é suficiente para selecionar um registro. Reserve essas chaves apenas para demonstração; não as atribua a cadastros reais. Se uma chave reservada já estiver duplicada, a execução é recusada, sem escolher, mesclar ou apagar registros.

Toda a geração ocorre em uma transação. Qualquer falha reverte as inserções e atualizações daquela execução. Não há limpeza, `truncate`, `migrate:fresh`, `db:wipe` ou exclusão. Execute uma geração por vez: sem alterar o esquema e adicionar unicidade, não há garantia de idempotência para duas primeiras execuções simultâneas.

## Arquivos e validação

- `database/seeders/SupportFlowDemoSeeder.php`: geração, relacionamentos, guarda de ambiente, identidade e transação.
- `database/seeders/SupportFlowDemoCatalog.php`: sistemas, problemas e procedimentos fictícios.
- `tests/Feature/SupportFlowDemoSeederTest.php`: volumes, integridade, histórico, API, reexecução, preservação dos registros preexistentes, recusa fora de local/testing e rollback.
- `docs/demo-data.md`: instruções e decisões do conjunto.

As verificações de escrita desta implementação usam exclusivamente o SQLite em memória do PHPUnit. O seeder não foi executado no MySQL de desenvolvimento apenas para validá-lo.

```powershell
php artisan test --compact --filter=SupportFlowDemoSeeder
php artisan test --compact
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
npm.cmd run check
```

O teste executa a geração novamente no mesmo dia e cinco dias depois, conferindo IDs, ausência de duplicações, registros reais inalterados e retorno dos cenários de hoje/futuro. Também verifica o dashboard, filtros, paginação, fechamento independente e rejeição de novos atendimentos em sistemas inativos. Um conflito de chave no fim do processo comprova o rollback dos dados gerados antes dele.

Resultados locais: nove testes específicos aprovados, incluídos na suíte completa de **108 testes e 2.504 assertions** aprovados. Pint, PHPStan (zero erros), lint/formatação da raiz e `git diff --check` também aprovados. Não houve alterações no frontend, em migrations ou nas configurações de banco.

Não foram adicionados prioridades, categorias, gráficos, módulos ou factories de autenticação. A única factory existente é de usuário e não é pertinente aos dados de suporte. O conjunto usa apenas os campos e enums já implementados. Validação específica em MySQL, concorrência entre execuções e inspeção visual no navegador não fazem parte da cobertura automatizada desta entrega.
