# CI do SupportFlow

## Causa da falha de conexão

O job `tests / ci (pull_request)` executava a etapa `Setup Application` com `composer setup`. Esse script de desenvolvimento instala dependências, copia `.env.example` quando não há `.env`, gera uma chave e executa `php artisan migrate --force` antes dos testes.

O exemplo usa MySQL em `127.0.0.1:3306`, banco `supportflow`. O runner não tinha um serviço MySQL configurado. Portanto, a migration tentava abrir essa conexão e falhava com `SQLSTATE[HY000] [2002] Connection refused`, antes de alcançar `Run CI Checks`.

`phpunit.xml` e `tests/bootstrap.php` já isolavam os testes em SQLite, mas só são carregados pelo PHPUnit/Pest. Eles não configuram os comandos Artisan anteriores ao início da suíte.

## Configuração corrigida

[O workflow](../.github/workflows/tests.yml) define no job, antes da instalação e de qualquer Artisan:

- `APP_ENV=testing`, chave pública específica de testes e caminhos de cache exclusivos de CI;
- `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL` vazia;
- cache e sessão em array, fila síncrona e email em array;
- PHP 8.5 com `pdo_sqlite`/`sqlite3` explícitos e Node 22.

Foram separados instalação por lockfiles, validação das migrations, builds e checks. O CI não executa mais o script de configuração de desenvolvimento, não copia o `.env` de desenvolvimento e não precisa de credenciais ou servidor MySQL.

A etapa `Validate migrations on isolated SQLite` executa as 11 migrations em uma conexão em memória descartada ao encerrar aquele comando. A suíte cria novamente seu próprio banco em memória por `RefreshDatabase`; ela não depende de persistência entre steps.

SQLite é adequado aos testes funcionais existentes. Uma validação futura de locks/concorrência ou comportamento exclusivo do MySQL exigirá outro ambiente descartável com serviço MySQL e verificações próprias; a suíte atual continua recusando conexões ao banco principal. Não foram alteradas migrations, `.env`, `.env.example`, regras de negócio ou configurações de desenvolvimento do banco.

## Cobertura do workflow

1. `composer install --no-interaction --prefer-dist --no-progress` e `npm ci` nos dois projetos JavaScript.
2. `php artisan migrate --force --no-interaction`, com o ambiente SQLite do job.
3. `npm run build` na raiz e em `frontend/`.
4. `composer ci:check`, preservando lint/formatação Vite Plus, tipos Vue do starter, Pint, PHPStan e toda a suíte Laravel.
5. Em `frontend/`, `npm run test:unit -- --run` e Prettier para fontes e configurações Vite/Vitest.

Também foram corrigidos bloqueios encontrados ao executar os checks completos:

- O formatador da raiz usava regras diferentes das do Prettier do frontend independente. Apenas a formatação de `frontend/**` passa a ser delegada ao seu próprio check Prettier obrigatório. O lint da raiz continua incluindo esse código.
- Os quatro documentos existentes foram formatados pelo Vite Plus, sem alterar seus contratos. A configuração Vitest foi formatada pelo Prettier.
- O lint com tipos identificou que `route.meta.title` pode ser um objeto. O router passa a aceitar texto e usar o título padrão nos demais casos, preservando os títulos das rotas existentes.

O teste `tests/Unit/CiEnvironmentTest.php` lê o ambiente real do YAML e valida o isolamento antes de iniciar um processo Artisan separado para executar migrations. Assim, a regressão cobre a etapa anterior ao bootstrap do PHPUnit que originou o problema.

## Arquivos desta correção

- `.github/workflows/tests.yml`: ambiente isolado desde o início e etapas explícitas para ambos os projetos JavaScript.
- `tests/Unit/CiEnvironmentTest.php`: regressão executando migrations fora do PHPUnit com a configuração do workflow.
- `vite.config.ts`: escopo do formatador, mantendo lint e checagens de tipos existentes.
- `frontend/src/router/index.js`: tratamento do tipo do título identificado pelo lint.
- `frontend/vitest.config.js`: somente formatação.
- `docs/api.md`, `docs/database.md`, `docs/frontend.md`, `docs/validation.md`: somente formatação exigida pelo check da raiz.
- `docs/ci.md`: causa raiz, configuração e reprodução do CI.

## Reprodução e limites

`php artisan test --compact` usa o isolamento existente automaticamente. Para reproduzir migrations e `composer ci:check` com segurança, use um terminal temporário com as mesmas variáveis do job no workflow; não execute `composer setup` como substituto do CI sobre o ambiente MySQL local.

Resultados locais em 21/10/2026:

Também foi concluído `composer install --no-interaction --prefer-dist --no-progress -vv` com o ambiente SQLite do job: lockfile compatível, sem atualizações de dependências, autoload otimizado e `package:discover` aprovados. Após essa instalação, a suíte Laravel foi repetida e manteve 99 testes / 723 assertions aprovados.

| Validação                                                                     | Resultado                                                                                |
| ----------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `php artisan migrate --force --no-interaction` com as variáveis SQLite do job | 11 migrations aprovadas em memória                                                       |
| `composer ci:check` com as variáveis do job                                   | Aprovado: lint/formatação, tipos Vue, Pint, PHPStan e 99 testes Laravel / 723 assertions |
| `npm ci --no-audit --no-fund` nos dois projetos JavaScript                    | Instalações pelos lockfiles aprovadas                                                    |
| `npm run test:unit -- --run` em `frontend/`, após reinstalação                | 28 testes aprovados, 3 arquivos                                                          |
| `npm run build` na raiz e no frontend                                         | Ambos aprovados                                                                          |
| Prettier de fontes e configs do frontend                                      | Aprovado                                                                                 |
| `composer validate --strict --no-check-publish`                               | Aprovado                                                                                 |
| `git diff --check`                                                            | Aprovado                                                                                 |

Ocorrências locais resolvidas: o sandbox bloqueou inicialmente rede/leitura de dependências; comandos necessários foram repetidos com autorização. Uma repetição do Vitest não iniciou workers e uma tentativa de `npm ci` encontrou a DLL nativa do Rolldown em uso pelos servidores de prévia anteriores. Esses servidores iniciados pelo agente foram encerrados; a reinstalação e a execução padrão dos 28 testes passaram depois disso. Não foram alterados timeouts ou desativados testes para contornar esse problema.

O ambiente local usa Windows 11, PHP 8.5 e Node 24.22; a execução exata em Ubuntu/Node 22 depende da próxima execução do GitHub Actions, depois de enviar as alterações ao PR. Não foi disparado ou confirmado um novo run remoto nesta correção. Não há etapa com testes desativados, erros ignorados ou `continue-on-error`.
