# SupportFlow — frontend Vue 3 (etapa 3)

Interface em português brasileiro, implementada no projeto Vue já existente em `frontend/`, com JavaScript e Composition API (`<script setup>`). O Laravel continua na raiz e mantém a API REST e a página original do starter kit.

## Iniciar no Windows

No terminal da raiz do repositório:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Em outro terminal:

```powershell
cd frontend
npm.cmd run dev
```

Abra o endereço mostrado pelo Vite, normalmente `http://localhost:5173`. O uso de `npm.cmd` evita o bloqueio de `npm.ps1` pela política de execução do PowerShell, sem alterar políticas do Windows. Caso as dependências ainda não estejam instaladas em uma nova cópia do projeto, execute `npm.cmd ci` dentro de `frontend/`.

O `vite.config.js` encaminha `/api` para `http://127.0.0.1:8000`. Não é necessário configurar CORS, copiar credenciais do MySQL para o navegador ou criar um novo `.env` no frontend. As chamadas usam caminhos relativos e JSON. O banco e as credenciais continuam sob responsabilidade do Laravel.

Na validação desta etapa, o Vite também foi iniciado fora do sandbox em `http://127.0.0.1:5174`, pois a instância restrita não era acessível pelo navegador automatizado. A configuração normal do projeto continua com a porta padrão do Vite; não há dependência da porta 5174 no código.

## Dependências

Foram reutilizados Vue 3, Vue Router, Vite, o plugin Vue, Vue DevTools, Vitest, Vue Test Utils, jsdom e Prettier já presentes. Nenhuma dependência nova foi adicionada. Não foi executado `npm create vue` e não foram substituídos `package.json`, lockfile ou configurações de testes.

A camada HTTP utiliza `fetch` nativo. Os ícones são SVGs locais consistentes, sem download de biblioteca, fonte ou imagem externa. O estilo utiliza CSS responsivo e fontes do sistema.

## Estrutura

```text
frontend/
  public/favicon.svg
  src/
    App.vue                       # sidebar, cabeçalho, layout responsivo e navegação móvel
    main.js
    assets/main.css               # identidade visual e breakpoints
    router/index.js               # rotas SPA e títulos das páginas
    services/api.js               # fetch, JSON, query string e erros HTTP
    config/resources.js           # campos, filtros e colunas dos seis cadastros comuns
    composables/
      useList.js                  # paginação, carregamento, cancelamento e respostas obsoletas
      useNotifications.js         # mensagens globais de sucesso/erro
    utils/
      format.js                   # rótulos, datas e fuso de Brasília
      forms.js                    # validação e serialização de campos
      resolution.js               # confirmação da resolução
    views/
      DashboardView.vue
      TicketsView.vue
      TicketFormView.vue
      TicketDetailView.vue
      ResourceListView.vue        # pendências, lembretes, artigos, contatos, clientes e sistemas
      NotFoundView.vue
    components/
      AppIcon.vue, AppModal.vue, PageHeading.vue, ToastList.vue
      FilterBar.vue, PaginationBar.vue, RecordTable.vue, StatePanel.vue, StatusBadge.vue
      FormField.vue, RelationSelect.vue, ResourceForm.vue, RecordDetail.vue
      ResolutionFields.vue, CloseTicketModal.vue, DashboardSection.vue
    __tests__/
      App.spec.js, api.spec.js, flows.spec.js
```

Os seis cadastros compartilham comportamentos de listagem e edição, mas mantêm campos, filtros e ações explícitos na configuração. Os atendimentos possuem views próprias para os fluxos específicos. A lógica do contrato HTTP e das datas permanece fora dos componentes visuais.

## Páginas e funcionalidades

| Rota | Funcionalidades |
| --- | --- |
| `/` | Cinco indicadores reais, atendimentos recentes, pendências atrasadas, lembretes de hoje, atrasados e próximos; atalhos de cadastro |
| `/atendimentos` | Tabela com ID, título, sistema, contato, cliente, status e abertura; pesquisa, filtros por cadastros/status/período e paginação real |
| `/atendimentos/novo` | Cadastro com sistema ativo, contato obrigatório, cliente e atendimento anterior opcionais; resolução e confirmação opcionais |
| `/atendimentos/{id}` | Detalhes completos, status, resolução, confirmação, vínculos diretos, pendências e lembretes; edição, encerramento e registro de retorno |
| `/atendimentos/{id}/editar` | Edição dos dados e da confirmação, preservando o sistema inativo atual em registros históricos |
| `/pendencias` | Cadastro, consulta, edição, conclusão e reabertura por situação; filtros de abertas/concluídas/atrasadas e por atendimento; prazo e observações de conclusão |
| `/lembretes` | Cadastro, consulta, edição e conclusão; filtros de hoje/atrasados/próximos/concluídos/não concluídos; associação opcional |
| `/conhecimento` | Cadastro, detalhe e edição de artigos; pesquisa por problema, procedimento ou sistema e filtro de sistema; artigos gerais permitidos |
| `/contatos` | Cadastro, consulta, edição e pesquisa por nome, telefone, e-mail e observações |
| `/clientes` | Cadastro, consulta, edição e pesquisa; cadastro independente dos contatos |
| `/sistemas` | Cadastro, consulta e edição; filtro por nome e ativo/inativo; ativação/desativação com confirmação e sem exclusão |

A navegação é feita pelo Vue Router sem recarregar o documento. Filtros e paginação ficam na URL, permitindo atualizar a página, compartilhar o caminho local e usar voltar/avançar do navegador.

O parâmetro de interface `novo=1` abre o formulário do respectivo cadastro. `ver={id}` abre o detalhe de lembretes ou pendências selecionados no painel. Esses parâmetros não são enviados como filtros inventados à API. `anterior={id}` no novo atendimento sugere o vínculo de retorno.

## Integração e regras preservadas

- As listagens utilizam `data`, `links` e `meta` do Laravel; não simulam paginação sobre uma lista local.
- Seletores de relacionamento pesquisam na API e carregam páginas de 15 opções com "Carregar mais opções". Não há truncamento permanente no primeiro lote de cadastros. Seleções existentes são consultadas individualmente quando necessário.
- Sistemas disponíveis para seleção em novo atendimento são filtrados por `is_active=1`. O sistema inativo atual de um histórico continua visível e pode ser mantido. A validação definitiva continua no backend.
- Os valores de status permanecem `open`, `in_progress`, `waiting`, `resolved`, `closed`, `pending` e `completed`; somente os rótulos são traduzidos.
- Na edição de pendência, mudar para `pending` reabre conforme a regra existente. Não há reabertura de lembrete, em conformidade com a API.
- Atendimentos anteriores e retornos aparecem como links independentes, com seus próprios status. A interface não propaga encerramentos.
- A associação de pendências e lembretes pode ser removida; os artigos podem não possuir sistema. Não existe ação de exclusão permanente.
- Filtros de prazo das pendências usam o estado de atraso calculado pelo backend. Não foi inventado filtro de intervalo de `due_at` que a API atual não oferece.

### Encerramento com pendências

A escolha de `closed` no seletor de status abre o mesmo fluxo de encerramento do botão próprio. A primeira chamada utiliza `POST /api/support-tickets/{id}/close` sem `confirm_open_tasks`.

Se o Laravel retornar 409 com `code=open_pending_tasks`, o modal apresenta a quantidade e as descrições/IDs das pendências. Cancelar não faz outra requisição. Somente o clique em **Encerrar mesmo assim** repete a chamada com o booleano JSON `confirm_open_tasks: true`. O backend valida novamente; as pendências continuam abertas e os atendimentos relacionados permanecem independentes.

Fonte e data de confirmação são preenchidas juntas. As fontes são `customer` (Cliente) e `responsible_department` (Setor responsável). É possível deixar ambas vazias ou removê-las juntas. Não é exigida confirmação exclusiva do cliente.

### Datas

Respostas ISO 8601 são formatadas com `Intl.DateTimeFormat` explicitamente em `America/Sao_Paulo`, independentemente do fuso do computador. Campos `datetime-local` representam o horário de Brasília. Ao salvar, são enviados como `YYYY-MM-DD HH:mm:ss`, sem converter indevidamente o horário de parede para UTC; segundos existentes são preservados.

### Carregamento e falhas

- Estados de carregamento, listas vazias, tentativa novamente e notificações de sucesso/erro.
- 422: mensagens por campo, com valores digitados preservados.
- 409: confirmação de encerramento conforme o contrato.
- 404: mensagem de registro não encontrado.
- Conexão indisponível, resposta inválida ou erro do servidor: mensagem legível, sem exibir traces internos.
- Requisições antigas de listagem são canceladas/ignoradas ao mudar filtros. Botões de gravação ficam indisponíveis durante a operação.
- Modais têm foco inicial, retorno de foco, contenção de Tab e fechamento por Escape quando a operação não está em andamento. Na navegação móvel, a área fora do menu fica inerte enquanto ele está aberto.

## Alteração no Laravel

Foi adicionado somente `GET /api/dashboard/summary`, por meio de `DashboardController`, da rota e de testes em `DashboardTest.php`. O endpoint retorna contagens completas e no máximo cinco itens por seção, evitando múltiplas requisições paginadas apenas para construir o painel. Seu contrato está em [api.md](api.md).

Não houve migration, alteração de credenciais, instalação de autenticação, criação de notificações ou mudança nas regras existentes de encerramento. Os registros do MySQL não foram alterados para validar esta etapa.

## Verificações

Na pasta `frontend/`:

```powershell
npm.cmd run build
npm.cmd run test:unit -- --run
npm.cmd exec prettier -- --check src/ vite.config.js index.html
```

Na raiz:

```powershell
php artisan test --compact
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
```

Resultados: build aprovado; **26 testes frontend aprovados**; **96 testes Laravel aprovados, com 640 assertions**; PHPStan sem erros. A formatação foi aplicada com Prettier e Pint. O teste do starter Vue foi atualizado para verificar o layout e a página de contatos, pois o conteúdo "You did it!" deixou de existir.

Os testes frontend usam respostas controladas apenas dentro dos testes, e os testes Laravel usam SQLite em memória. Não há dados fictícios permanentes nas telas. A primeira execução do Vitest encontrou bloqueio de arquivos temporários pelo sandbox; a execução fora dele foi aprovada e passou.

Verificações no navegador: dashboard real via proxy, navegação principal, carregamento de cadastros, validações obrigatórias do atendimento, modal de pendências, layout desktop e viewport móvel de 390 pixels, abertura/fechamento do menu e ausência de erros/avisos no console. Foi corrigida uma prioridade de CSS que inicialmente ocultava o botão do menu móvel. Requisições HTTP ao resumo, diretamente no Laravel e pelo proxy Vite, retornaram 200.

## Limites e validação manual

- A etapa 4 validou gravações completas, tabelas populadas, filtros, paginação, encerramento com conflito e layout móvel no navegador usando SQLite descartável. Resultados, correções, comandos para reproduzir e limites restantes estão em [validation.md](validation.md). Nenhum registro do MySQL foi criado ou editado para esses testes.
- Não foram adicionadas novas regras para transições de status, confirmação obrigatória ou restrições entre sistema/contato dos atendimentos relacionados.
- O build fica em `frontend/dist/`. Para publicá-lo será necessário configurar fallback das rotas SPA para `index.html` e encaminhamento de `/api` ao Laravel. Deploy não faz parte desta etapa; o frontend não substitui os assets Inertia da raiz.
- Sem autenticação, multiusuários, integração com WhatsApp ou notificações externas, conforme solicitado.

## Arquivos criados ou modificados

Novos: views, componentes, composables, services, utils, configuração de recursos, CSS e favicon SVG listados na estrutura; `src/__tests__/api.spec.js`, `src/__tests__/flows.spec.js`, `docs/frontend.md`, `app/Http/Controllers/Api/DashboardController.php` e `tests/Feature/Api/DashboardTest.php`.

Modificados: `frontend/src/App.vue`, `frontend/src/main.js`, `frontend/src/router/index.js`, `frontend/src/__tests__/App.spec.js`, `frontend/vite.config.js`, `frontend/index.html`, `routes/api.php` e `docs/api.md`. Os demais arquivos preexistentes do Vue foram mantidos.
