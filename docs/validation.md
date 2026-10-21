# Validação integrada — etapa 4

Execução em 21/10/2026, com Laravel 13.37, PHP 8.5 e frontend Vue 3 existente. Foram lidos `database.md`, `api.md` e `frontend.md` antes das alterações. Nenhuma migration, regra de encerramento, credencial ou módulo de produto foi alterado.

## Isolamento

- PHPUnit: SQLite `:memory:`, chave pública exclusiva de testes, cache e sessão em memória, filas síncronas e email em array.
- `tests/bootstrap.php` substitui `getenv()`, `$_ENV` e `$_SERVER` antes de iniciar o Laravel. `force="true"` no XML sozinho não substituía todas as fontes consultadas pelo Laravel. O teste com variáveis MySQL fictícias herdadas reproduziu a divergência e passou depois da correção.
- `Tests\TestCase` recusa executar se a configuração efetiva não for `testing`, SQLite em memória e sem URL alternativa. A recusa ocorre antes do `RefreshDatabase`.
- Navegador: arquivo exclusivo `storage/framework/testing/browser-qa.sqlite`, ignorado pelo Git. O bootstrap de QA não carrega o `.env` principal e verifica o driver, ambiente, caminho exato e ausência de URL alternativa antes de servir requisições ou executar fixtures.
- API de QA em `127.0.0.1:8011`; Vite de QA em `127.0.0.1:5181`, com proxy fixo para 8011. O HTTP confirmou `X-SupportFlow-QA: isolated-sqlite` antes de qualquer cadastro no navegador.
- Não houve escrita no MySQL principal, `migrate:fresh`, `db:wipe`, `db:seed` ou cópia de credenciais reais. Somente `migrate` no arquivo SQLite recém-criado. O inicializador recusa reinicializar arquivo existente.

## Cenários e evidências

Todos os sete cenários foram executados pela API na regressão `IntegratedWorkflowTest.php` e exercitados no navegador contra o servidor isolado. As datas de fixtures e da regressão são relativas ao dia de execução em `America/Sao_Paulo`.

| Cenário | Execução no navegador e resultado |
| --- | --- |
| 1. Cadastro | Sistemas `QA ERP` e `QA Fiscal` ativos; `QA Legado` inativo. Contatos `QA Ana` e `QA Bruno`, cliente `QA Loja`. Listagens e seletores apresentaram os registros corretamente. |
| 2. Atendimento | Atendimento #1 com sistema, contato e cliente; #2 sem cliente. Edição do título de #1 persistiu. Formulário vazio exibiu os quatro erros obrigatórios. Seletor de novo atendimento excluiu o sistema inativo; a API também rejeitou o ID inativo com 422. Pesquisa, status e intervalo de datas funcionaram. |
| 3. Acompanhamento | Pendência #1 e lembrete #1 associados ao atendimento #1. Pendência #2 e lembretes #2/#3 independentes. Prazo de ontem apareceu atrasado; conclusão de pendência gravou observações e data. Lembrete concluído saiu dos indicadores pendentes. Filtros de atrasadas, concluídos, hoje e próximos conferidos. |
| 4. Encerramento | Primeira tentativa de fechar #1 exibiu o modal de conflito 409 com a pendência #1. Cancelamento seguido de recarga preservou aberto. Nova tentativa e botão “Encerrar mesmo assim” encerraram #1, mantendo pendência #1 aberta. Solução, fonte Cliente, pessoa e horário da verificação persistiram. A regressão API também exercitou fonte Setor responsável. |
| 5. Relacionamento | #2 criado pelo link “Registrar novo retorno”, com anterior #1. Vínculo apresentado em ambas as telas. Após fechar #1, #2 continuou aberto e sem encerramento. |
| 6. Dashboard | Após fixtures adicionais: **7 abertos, 5 em andamento, 5 aguardando retorno, 1 pendência atrasada, 1 lembrete de hoje**. Seções de hoje, atrasados e futuros mostraram os registros esperados e excluíram o lembrete concluído. Contagens verificadas também na API e no SQLite. |
| 7. Conhecimento | Artigo #1 vinculado a sistema e #2 independente. Procedimento de #1 editado, encontrado pela pesquisa “revisado” e consultado no modal com o texto salvo. |

Paginação real: 18 atendimentos, primeira página com 15 e segunda com 3; pesquisa `q=QA` preservada ao avançar. Filtro de encerrados retornou somente #1; aguardando retorno com intervalo do dia retornou 5. Seletor de contatos carregou 18 opções em duas páginas e permitiu escolher `QA Paginação 16` após a correção.

Interface inspecionada em **1280×900** e **390×844**: dashboard, tabela, filtros, menu móvel, modal e mensagens obrigatórias. Sem transbordamento horizontal da página; a tabela usa sua área de rolagem. Modal móvel permaneceu dentro da tela, exibiu erros e fechou por Escape. Rota inexistente apresentou a página apropriada. A leitura do console nas verificações finais não apresentou erros ou avisos. A navegação foi automatizada nesta sessão; não foi adicionada uma dependência de automação de navegador ao projeto.

Evidências locais, ignoradas pelo Git: `storage/framework/testing/evidence/dashboard-desktop.jpg`, `dashboard-mobile.jpg` e `dashboard-summary.jpg`.

## Defeitos corrigidos

1. **Isolamento vulnerável a variáveis herdadas:** bootstrap explícito nas três fontes de ambiente e guarda antes de migrations dos testes. Regressões: `TestEnvironmentTest.php`, cenário integrado e execução com `DB_CONNECTION=mysql`, banco fictício e URL fictícia apontando à porta 1. A execução corrigida passou em SQLite sem tentar usar essa URL.
2. **Consultas sem timeout quando recebiam sinal de cancelamento:** `AbortSignal.any` combina cancelamento do componente com limite de 20 segundos. Regressões simulam requisição sem resposta e cancelamento intencional.
3. **Seletor fechava ao carregar a última página:** o botão removido perdia foco e disparava fechamento do popover. A ação agora devolve o foco à pesquisa antes de carregar. Reproduzido no navegador (`15 opções → lista fechada`) e retestado (`18 opções, lista aberta → seleção concluída`). O teste existente de paginação passou a usar foco real no DOM e verificar sua preservação.

## Comandos e resultados finais

| Comando | Diretório | Resultado |
| --- | --- | --- |
| `php artisan test --compact` | raiz | **98 aprovados, 715 assertions** |
| `php artisan test --compact --filter=IntegratedWorkflow` com variáveis MySQL fictícias herdadas | raiz | **1 aprovado, 62 assertions**, SQLite obrigatório |
| `npm.cmd run test:unit -- --run` | frontend | **28 aprovados, 3 arquivos** |
| `npm.cmd run build` | frontend | Aprovado, 52 módulos, artefatos em `frontend/dist` |
| `php vendor/bin/pint --test` | raiz | Aprovado; alterações PHP formatadas com `--dirty` |
| `php vendor/bin/phpstan analyse --no-progress --memory-limit=512M` | raiz | Aprovado, zero erros |
| `npm.cmd exec prettier -- --check src/ vite.config.js vite.qa.config.js index.html` | frontend | Aprovado |
| `git diff --check` | raiz | Aprovado |
| `npm.cmd run check` | raiz, starter Inertia | Não executou: binário `vp` ausente (`node_modules/.bin/vp.cmd` inexistente). Dependência de desenvolvimento do starter não instalada; não é o frontend independente validado acima. |

Não há ESLint configurado no frontend. O comando adicional do starter usa Vite Plus. Não foram instaladas dependências ou alterados arquivos desse starter apenas para substituir seu ferramental. As restrições do sandbox impediram inicialmente leitura de dependências PHP e escrita do Pint; os mesmos comandos passaram com a execução autorizada fora do sandbox. O primeiro servidor Vite também precisou ser reiniciado fora da restrição de rede antes dos testes HTTP/UI.

## Reprodução do ambiente de navegador

Na raiz, para uma base ainda inexistente:

```powershell
php tests/Support/browser-qa-init.php
php -S 127.0.0.1:8011 tests/Support/browser-qa-router.php
```

Em outro terminal:

```powershell
cd frontend
npm.cmd run dev -- --config vite.qa.config.js
```

Abrir `http://127.0.0.1:5181`. Conferir o cabeçalho `X-SupportFlow-QA` na resposta de `/api/systems` antes de cadastrar dados. O arquivo SQLite desta execução foi preservado para consulta; não é preciso inicializá-lo novamente. As portas 8000/5174 do desenvolvimento normal não são utilizadas por esse proxy.

Os primeiros cadastros e registros acima foram feitos pelos formulários. Depois deles, `php tests/Support/browser-qa-fixtures.php` na raiz acrescenta 16 atendimentos distribuídos em três status, 16 contatos para paginação e um lembrete atrasado. Esse script também recusa repetição e depende dos cadastros fictícios indicados; não sobrescreve registros. Para uma nova rodada do zero, parar os dois servidores e arquivar exclusivamente `storage/framework/testing/browser-qa.sqlite` antes de executar o inicializador. Nunca apontar esses procedimentos ao banco principal.

## Arquivos desta etapa

- Alterados: `phpunit.xml`, `tests/TestCase.php`, `frontend/src/services/api.js`, `frontend/src/components/RelationSelect.vue`, `frontend/src/__tests__/api.spec.js`, `frontend/src/__tests__/flows.spec.js`, `docs/frontend.md`.
- Criados: `tests/bootstrap.php`, `tests/Unit/TestEnvironmentTest.php`, `tests/Feature/Api/IntegratedWorkflowTest.php`, `tests/Support/isolated-app.php`, `tests/Support/browser-qa-init.php`, `tests/Support/browser-qa-router.php`, `tests/Support/browser-qa-fixtures.php`, `frontend/vite.qa.config.js`, `docs/validation.md`.
- Banco descartável, screenshots e build são artefatos locais ignorados. Alterações preexistentes da etapa 3, incluindo dashboard, rota e documentação da API, foram preservadas.

## Prontidão e validações restantes

**Apto para começar o uso pessoal local**, com os fluxos funcionais desta etapa aprovados no ambiente isolado. Não foram criadas novas regras de negócio ou decisões provisórias de domínio. As únicas configurações novas são específicas de testes.

Limites objetivos, ainda sem aprovação:

- Escritas e concorrência específicas do MySQL 8.4 não foram repetidas em uma instância MySQL descartável; esta validação funcional usou SQLite. Não se deve testar destrutivamente no banco principal para suprir isso.
- Não houve teste em celular físico, teclado virtual, Firefox ou Edge. O móvel foi validado por viewport do navegador disponível.
- Timeout e cancelamento têm testes automatizados controlados; uma queda real do servidor durante o uso não foi induzida no navegador.
- O `check` do starter Inertia da raiz requer suas dependências locais, em especial Vite Plus, caso esse starter volte a ser desenvolvido.
- Publicação do build, fallback SPA e encaminhamento de `/api` continuam fora desta etapa, conforme `frontend.md`.
