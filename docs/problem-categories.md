# Categorias de problemas etapa 1

O gerenciamento de categorias e subcategorias fica em **Cadastros → Categorias de Problemas**, na rota Vue `/categorias-problemas`. Esta etapa prepara os cadastros e suas disponibilidades por sistema. Os atendimentos e o Dashboard continuam com os campos e comportamentos anteriores.

## Utilização

1. Clique em **Nova categoria**, informe o nome, a descrição opcional e selecione os sistemas associados.
2. Clique no nome da categoria ou na ação de visualizar para acessar seus dados e subcategorias.
3. Clique em **Nova subcategoria** e selecione sua disponibilidade entre os sistemas da categoria principal.
4. Use as ações de edição e ativação/desativação nas listagens. A mudança de situação pela listagem pede confirmação.
5. Pesquise por nome/descrição e filtre por situação e sistema. A paginação é realizada pela API.

As seleções de sistemas de uma categoria carregam todas as páginas da API existente de sistemas. Sistemas inativos aparecem no formulário quando já fazem parte do vínculo editado. Uma subcategoria apresenta apenas os sistemas habilitados na categoria principal e preserva seus vínculos existentes com sistemas inativos.

## Banco e integridade

A migration `2026_11_23_000001_create_problem_classifications_tables.php` cria quatro tabelas, sem alterar as tabelas existentes:

| Tabela                       | Conteúdo e restrições                                                                                                                        |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `problem_categories`         | Nome, nome normalizado único, descrição opcional, `is_active` e timestamps.                                                                  |
| `problem_subcategories`      | Categoria obrigatória, nome, nome normalizado, descrição opcional, `is_active` e timestamps. O nome normalizado é único dentro da categoria. |
| `problem_category_system`    | Identificador próprio, categoria, sistema, `is_active` e timestamps. A combinação categoria/sistema é única.                                 |
| `problem_subcategory_system` | Identificador próprio, subcategoria, categoria, sistema, `is_active` e timestamps. A combinação subcategoria/sistema é única.                |

As chaves estrangeiras usam restrição de exclusão. Duas chaves compostas garantem que o vínculo de uma subcategoria pertença à categoria correta e referencie uma associação categoria/sistema existente. A API valida também que a associação principal esteja habilitada.

Os nomes têm espaços externos removidos e sequências de espaços, tabulações e quebras de linha reduzidas a um espaço. A chave normalizada usa minúsculas Unicode; a capitalização do nome apresentado é preservada. Índices únicos protegem também contra gravações concorrentes. As gravações e alterações de disponibilidade usam transações, com bloqueio da categoria para serializar mudanças na categoria e suas subcategorias.

### Preservação de vínculos

- Remover um sistema da seleção desabilita sua associação; a linha, seu identificador e a data inicial permanecem no banco.
- Remover o sistema da categoria desabilita também suas associações nas subcategorias, na mesma transação.
- Reassociar o sistema à categoria reutiliza a linha existente. As disponibilidades das subcategorias precisam ser habilitadas explicitamente, evitando reativações silenciosas.
- Desativar uma categoria ou subcategoria altera apenas sua situação; seus registros e associações são preservados.
- Novas associações exigem sistemas ativos. Vínculos já habilitados com sistemas posteriormente inativos podem ser mantidos ou removidos.
- A categoria de uma subcategoria não pode ser trocada. Para outro agrupamento, cadastre uma nova subcategoria e desative a anterior.
- Novos cadastros e alterações explícitas da seleção exigem pelo menos um sistema. Uma subcategoria que perdeu toda a disponibilidade por remoção na categoria continua consultável e editável, inclusive sem recriar vínculos.

O gerenciamento normal não oferece exclusão definitiva. A integração futura com atendimentos deverá verificar a situação do sistema, da categoria, da subcategoria e de ambas as associações antes de criar um novo vínculo. Essa seleção e os campos nos atendimentos pertencem à etapa 2.

## API

Todos os caminhos abaixo possuem o prefixo `/api`. As respostas seguem os recursos JSON e a paginação existentes; erros de validação retornam HTTP 422 com `errors` por campo.

| Recurso       | Listar/criar                         | Consultar/atualizar                               |
| ------------- | ------------------------------------ | ------------------------------------------------- |
| Categorias    | `GET`, `POST /problem-categories`    | `GET`, `PUT`, `PATCH /problem-categories/{id}`    |
| Subcategorias | `GET`, `POST /problem-subcategories` | `GET`, `PUT`, `PATCH /problem-subcategories/{id}` |

Ativação/desativação: envie `{"is_active": false}` ou `{"is_active": true}` por `PATCH`. Associação/desassociação: envie a seleção completa em `system_ids`, por exemplo `{"system_ids": [1, 2]}`. Campos omitidos em atualizações são preservados.

Criação de categoria:

```json
{
    "name": "Falha de acesso",
    "description": null,
    "is_active": true,
    "system_ids": [1, 2]
}
```

Criação de subcategoria:

```json
{ "problem_category_id": 1, "name": "Credenciais inválidas", "system_ids": [2] }
```

As respostas incluem `systems` e `system_ids` para as associações habilitadas. `system_associations` inclui também as desabilitadas, identificadas por `association_active`, com datas de associação. `is_active` de cada sistema representa a situação do próprio sistema. Categorias incluem `subcategories_count`; subcategorias incluem `problem_category_id` e os dados resumidos da categoria.

Filtros comuns: `q`, `is_active`, `system_id`, `page` e `per_page` (até 100). Subcategorias aceitam também `problem_category_id`. O filtro por sistema considera apenas associações habilitadas; o filtro `is_active` representa a situação do registro consultado. Não é um endpoint de seleção de classificação para atendimentos.

## Instalação e validação

Para outro ambiente que ainda não tenha a estrutura:

```powershell
php artisan migrate --path=database/migrations/2026_11_23_000001_create_problem_classifications_tables.php
```

A migration foi aplicada no MySQL local 8.4.11. Uma comparação dos conteúdos de `systems`, `contacts`, `customers`, `support_tickets`, `pending_tasks`, `reminders` e `knowledge_articles`, antes e depois, confirmou que seus registros permaneceram iguais. As chaves estrangeiras e os índices únicos foram conferidos no MySQL.

Resultados da entrega:

- Laravel/Pest: **163 testes aprovados, 2.890 assertions**, incluindo 33 cenários novos. A suíte usa SQLite em memória, conforme o isolamento existente.
- Vue/Vitest: **84 testes aprovados**, incluindo 23 cenários novos. A suíte preserva os testes de consulta rápida de contatos/clientes e dos fluxos anteriores.
- PHPStan: aprovado, sem erros. Pint, Prettier e `git diff --check`: aprovados.
- `npm.cmd run build`, dentro de `frontend`: aprovado.
- Navegador em QA isolado: criação, edição, seleção múltipla, disponibilidade por sistema, erro de nome duplicado, confirmação de ativação/desativação, remoção consistente de disponibilidade e consulta histórica. Formulário móvel conferido em 390×844, sem transbordamento horizontal.

## Arquivos da implementação

Criados no backend:

- `database/migrations/2026_11_23_000001_create_problem_classifications_tables.php`
- `app/Models/ProblemCategory.php`
- `app/Models/ProblemSubcategory.php`
- `app/Models/Concerns/NormalizesProblemName.php`
- `app/Support/ProblemName.php`
- `app/Actions/SaveProblemClassification.php`
- `app/Http/Requests/Api/ProblemClassificationRequest.php`
- `app/Http/Resources/ProblemClassificationResource.php`
- `app/Http/Controllers/Api/ProblemCategoryController.php`
- `app/Http/Controllers/Api/ProblemSubcategoryController.php`
- `tests/Feature/Api/ProblemClassificationTest.php`

Criados no frontend:

- `frontend/src/views/ProblemCategoriesView.vue`
- `frontend/src/components/ProblemClassificationForm.vue`
- `frontend/src/components/ProblemClassificationList.vue`
- `frontend/src/config/problemClassifications.js`
- `frontend/src/__tests__/problem-classifications.spec.js`

Modificados para a integração:

- `app/Models/System.php`: relacionamentos inversos.
- `app/Http/Requests/Api/ListRequest.php`: filtros dos novos recursos.
- `routes/api.php`: rotas REST dos dois recursos.
- `frontend/src/router/index.js`: rota de gerenciamento e detalhe.
- `frontend/src/App.vue`: entrada no menu existente.
- `frontend/src/__tests__/App.spec.js`: verificação da nova entrada.

Documentação criada: `docs/problem-categories.md`.
