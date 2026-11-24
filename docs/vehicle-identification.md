# Identificação veicular

## Banco de dados

| Coluna                                                   | Tipo no MySQL     | Restrições                                                                                           |
| -------------------------------------------------------- | ----------------- | ---------------------------------------------------------------------------------------------------- |
| `vehicle_brands.id`                                      | `BIGINT UNSIGNED` | Chave primária autoincremental                                                                       |
| `vehicle_brands.name`                                    | `VARCHAR(255)`    | Obrigatória, mantém a capitalização informada                                                        |
| `vehicle_brands.normalized_name`                         | `VARCHAR(512)`    | Chave de busca obrigatória, com índice único                                                         |
| `vehicle_brands.created_at`, `vehicle_brands.updated_at` | `TIMESTAMP`       | Gerenciados pelo Eloquent                                                                            |
| `vehicles.id`                                            | `BIGINT UNSIGNED` | Chave primária autoincremental                                                                       |
| `vehicles.brand_id`                                      | `BIGINT UNSIGNED` | Obrigatória após a migração final, indexada, referencia `vehicle_brands.id` com `ON DELETE RESTRICT` |
| `vehicles.vehicle_line`                                  | `VARCHAR(5)`      | Obrigatória após a migração final, indexada, aceita `light` e `heavy`                                |
| `vehicles.plate`                                         | `VARCHAR(7)`      | Obrigatória, com índice único                                                                        |
| `vehicles.model_description`                             | `TEXT`            | Aceita `NULL`                                                                                        |
| `vehicles.created_at`, `vehicles.updated_at`             | `TIMESTAMP`       | Gerenciados pelo Eloquent                                                                            |
| `support_tickets.vehicle_id`                             | `BIGINT UNSIGNED` | Aceita `NULL`, possui índice e referencia `vehicles.id` com `ON DELETE RESTRICT`                     |

`VehicleBrand::vehicles()` e `Vehicle::supportTickets()` são relações `hasMany`. `Vehicle::brand()` é `belongsTo` e `SupportTicket::vehicle()` é `belongsTo` opcional. A mesma marca pode ter veículos das duas linhas. Cada atendimento guarda apenas a referência ao veículo. Consultas posteriores retornam a marca, a linha, a placa e a descrição atuais. Registre identificações incorretas exibidas por outros sistemas na descrição do problema do atendimento.

## Aplicar a atualização

Na raiz do projeto, confira o ambiente de destino e execute as migrations incrementais. Em bases sem veículos já cadastrados, o comando aplica todas as alterações.

```powershell
php artisan migrate:status
php artisan migrate --no-interaction
php artisan migrate:status
```

| Migration                                                    | Alteração                                                   |
| ------------------------------------------------------------ | ----------------------------------------------------------- |
| `2026_11_24_000002_create_vehicles_table`                    | Cria o cadastro de veículos                                 |
| `2026_11_24_000003_add_vehicle_id_to_support_tickets_table`  | Adiciona a referência opcional aos atendimentos             |
| `2026_11_24_000004_create_vehicle_brands_table`              | Cria o cadastro de marcas                                   |
| `2026_11_24_000005_add_brand_and_line_to_vehicles_table`     | Adiciona marca e linha ao cadastro, inicialmente com `NULL` |
| `2026_11_24_000006_require_brand_and_line_on_vehicles_table` | Confere os dados e torna os dois campos obrigatórios        |

Se a base já contém veículos, a migration final fica pendente enquanto algum registro estiver incompleto. As migrations anteriores ficam aplicadas, preservando IDs e vínculos. Para fazer o preenchimento antes de tentar aplicar a última migration, execute apenas as migrations de marcas e dos novos campos em uma base onde `000002` e `000003` já foram aplicadas.

```powershell
php artisan migrate --path=database/migrations/2026_11_24_000004_create_vehicle_brands_table.php --no-interaction
php artisan migrate --path=database/migrations/2026_11_24_000005_add_brand_and_line_to_vehicles_table.php --no-interaction
```

1. Identifique os veículos pendentes com a consulta abaixo.
2. Confirme os dados de cada veículo. Cadastre ou localize a marca real em `/api/vehicle-brands`. Envie os valores confirmados a `PATCH /api/vehicles/{id}`, por exemplo `{"brand_id":1,"vehicle_line":"light"}`, usando o ID retornado pelo cadastro. Durante a transição, campos legados incompletos são retornados como `null`.
3. Repita a consulta. Quando ela estiver vazia, rode `php artisan migrate --no-interaction` e confira o resultado com `php artisan migrate:status`.

```sql
SELECT id, plate, brand_id, vehicle_line
FROM vehicles
WHERE brand_id IS NULL OR vehicle_line IS NULL
ORDER BY id;
```

O MySQL aplica `NOT NULL`. O SQLite aplica a obrigatoriedade por triggers de inserção e atualização para preservar a tabela que os atendimentos referenciam. Ambos aplicam o domínio da linha e a integridade da marca. A retirada das colunas é bloqueada enquanto houver classificações preenchidas. A retirada das tabelas é bloqueada enquanto houver registros.

## Catálogo permanente de marcas

O `DatabaseSeeder` inclui `VehicleBrandSeeder` ao inicializar uma base nova. Para carregar apenas as marcas em uma base com a tabela `vehicle_brands` já migrada, confira a conexão de destino e execute:

```powershell
php artisan db:seed --class=VehicleBrandSeeder
```

A fonte é [vehicle-brands.txt](../database/seeders/data/vehicle-brands.txt), cópia das 125 linhas fornecidas para o SupportFlow. A grafia, a ordem, a caixa, os espaços e os hífens são preservados. Por exemplo, GWM e GREAT WALL, EFFA e EFFA-JMC, SAAB e SAAB-SCANIA são cadastros separados.

O seeder procura pela chave única `normalized_name`. Se a marca existente só difere na capitalização, ele grava o nome oficial mantendo o ID, os vínculos e as datas. Marcas com outras chaves permanecem no cadastro. Uma diferença além da capitalização na mesma chave gera erro com os nomes e o ID, e a transação reverte as mudanças dessa execução. A quantidade final de marcas pode passar de 125 se o banco já contiver marcas adicionais.

As marcas oficiais são dados permanentes. Os seeders demonstrativos podem reutilizá-las. Se uma marca tiver sido criada pela demonstração e for adotada pelo catálogo oficial, o próprio seeder retira seu ID do registro de propriedade da limpeza demonstrativa. Veja o [guia de dados fictícios](demo-data.md#limpeza-da-base-veicular).

## API de marcas

| Método e caminho                          | Resultado                                            |
| ----------------------------------------- | ---------------------------------------------------- |
| `GET /api/vehicle-brands?q=exemplo`       | HTTP 200, lista paginada com busca parcial pelo nome |
| `POST /api/vehicle-brands`                | HTTP 201, marca criada                               |
| `GET /api/vehicle-brands/{id}`            | HTTP 200, marca consultada                           |
| `PUT` ou `PATCH /api/vehicle-brands/{id}` | HTTP 200, marca atualizada                           |

Envie `{"name":"Marca Exemplo"}`. O nome é obrigatório no cadastro e aceita até 255 caracteres. A API remove espaços das extremidades e reduz sequências de espaços internos a um espaço. Para busca e unicidade, usa a versão normalizada em minúsculas. `"  MARCA   EXEMPLO "` é duplicado de `"Marca Exemplo"` e retorna HTTP 422 em `name`. Em uma edição, o próprio registro é excluído da verificação de duplicidade. A API retorna `id`, `name`, `created_at` e `updated_at`.

## API de veículos

| Método e caminho                    | Resultado                                   |
| ----------------------------------- | ------------------------------------------- |
| `GET /api/vehicles`                 | HTTP 200, lista paginada ordenada por placa |
| `POST /api/vehicles`                | HTTP 201, veículo criado                    |
| `GET /api/vehicles/{id}`            | HTTP 200, veículo consultado                |
| `PUT` ou `PATCH /api/vehicles/{id}` | HTTP 200, veículo atualizado                |

Envie `Accept: application/json` e, para requisições com corpo JSON, `Content-Type: application/json`. Consultas e edições de um ID inexistente retornam HTTP 404. Dados inválidos, inclusive nomes ou placas duplicados, retornam HTTP 422 com erros por campo. `DELETE` retorna HTTP 405 nos dois recursos. O banco protege marcas e veículos vinculados.

A placa é obrigatória na criação e aceita sete caracteres no formato tradicional (`ABC1234`) ou Mercosul (`ABC1D23`). O backend remove hífens e espaços, converte letras ASCII para maiúsculas e valida o formato e a unicidade. Por exemplo, com `ABC1D23` cadastrado, `abc-1d23` é duplicado. Os formatos tradicional e Mercosul são preservados. A busca e a gravação Eloquent reutilizam a mesma normalização.

`brand_id` deve ser o ID inteiro JSON de uma marca existente. `vehicle_line` deve ser `light` (linha leve) ou `heavy` (linha pesada), escolhido manualmente. Ambos são obrigatórios na criação. `model_description` aceita texto livre de até 16.000 caracteres ou `null`. Em `PATCH` e `PUT`, os campos omitidos conservam seus valores, e os campos enviados são validados. Envie `model_description: null` para remover a descrição.

Exemplo de corpo para `POST /api/vehicles`

```json
{
    "brand_id": 1,
    "vehicle_line": "light",
    "plate": "hfs-4456",
    "model_description": "SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007"
}
```

Exemplo de resposta HTTP 201

```json
{
    "data": {
        "id": 7,
        "brand_id": 1,
        "brand": {
            "id": 1,
            "name": "Marca Exemplo",
            "created_at": "2026-11-24T10:00:00-03:00",
            "updated_at": "2026-11-24T10:00:00-03:00"
        },
        "vehicle_line": "light",
        "plate": "HFS4456",
        "model_description": "SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007",
        "support_tickets_count": 0,
        "created_at": "2026-11-24T10:00:00-03:00",
        "updated_at": "2026-11-24T10:00:00-03:00"
    }
}
```

Use `GET /api/vehicles?q=hfs-44` para buscar por placa parcial, `GET /api/vehicles?q=Trend%20Total` para buscar por parte da descrição e `GET /api/vehicles?q=Marca%20Exemplo` para buscar por nome da marca. O parâmetro `q` aceita até 255 caracteres e basta uma das três correspondências. A pesquisa de placa ignora caixa, hífens e espaços. A descrição e o nome da marca são pesquisados como texto literal, inclusive quando contêm `%` ou `_`. Veículos sem descrição aparecem na listagem geral e na busca por placa ou marca.

Use `brand_id` para filtrar por ID de marca e `vehicle_line` para filtrar pela linha. Combine-os com `q`, por exemplo `GET /api/vehicles?brand_id=1&vehicle_line=light&q=trend`. Cada filtro informado precisa ser atendido.

As listagens retornam `data`, `links` e `meta`. Use `page` a partir de 1 e `per_page` de 1 a 100, com padrão de 15. As respostas dos endpoints de veículos incluem `support_tickets_count` com o total de vínculos, sem listar os atendimentos.

`GET /api/vehicles` retorna `meta.counts` com `total`, `light` e `heavy` para todo o cadastro, independentemente da busca, dos filtros ou da página. `meta.total` continua sendo o total da listagem filtrada. Durante a migração, veículos legados sem linha entram no total global e ficam fora dos subtotais por linha.

`GET /api/vehicle-brands` inclui `vehicles_count` em cada marca da listagem, contando todas as linhas veiculares. As duas contagens são usadas na [página Veículos](frontend.md#veículos-e-marcas).

## API de atendimentos

Os endpoints de criação e atualização de `/api/support-tickets` aceitam `vehicle_id` como inteiro JSON de um veículo existente, ou `null`. Omitir o campo na criação gera um atendimento sem veículo. Omiti-lo na edição preserva o vínculo. Enviar outro ID troca o veículo. Enviar `{"vehicle_id":null}` remove o vínculo.

O interruptor do formulário de atendimento é local. Com a identificação ativa, a interface exige a seleção de um veículo e envia o ID. Uma criação com a identificação desativada omite o campo. Na edição, desativar envia `null` para desvincular. As gravações de marca e veículo realizadas em modais são independentes da gravação do atendimento. [Fluxo do frontend](frontend.md#identificação-veicular-no-atendimento)

Exemplo de corpo para `PATCH /api/support-tickets/42`

```json
{ "vehicle_id": 7 }
```

A listagem, a consulta individual e a consulta de relacionados retornam `data.vehicle_id` e `data.vehicle` em cada atendimento. Quando vinculado, `vehicle` contém os mesmos campos de identificação e datas do cadastro, incluindo `brand_id`, `brand` e `vehicle_line`. Ambos os campos têm valor `null` se o vínculo estiver ausente.

### Filtros veiculares dos atendimentos

`GET /api/support-tickets` aceita os seguintes filtros opcionais. Combine-os com o texto, a situação, as datas e os demais filtros da listagem.

| Parâmetro          | Comportamento                                                                                                         |
| ------------------ | --------------------------------------------------------------------------------------------------------------------- |
| `vehicle_presence` | `with` para atendimentos com veículo, `without` para os sem veículo. A omissão inclui ambos.                          |
| `vehicle_plate`    | Placa completa ou parcial. Normaliza caixa, espaços e hífens e aceita até sete letras ou números após a normalização. |
| `vehicle_brand_id` | ID da marca atual.                                                                                                    |
| `vehicle_model`    | Texto literal completo ou parcial da descrição atual, com até 255 caracteres.                                         |
| `vehicle_line`     | `light` ou `heavy`, conforme o cadastro atual.                                                                        |

Cada filtro específico precisa ser atendido pelo mesmo veículo. Informar qualquer um deles restringe a busca a atendimentos vinculados. Combinar um filtro específico com `vehicle_presence=without` retorna HTTP 422. IDs de filtro seguem a convenção das listagens. IDs válidos sem correspondência retornam uma página vazia.

Exemplo de consulta combinada

```http
GET /api/support-tickets?vehicle_plate=hfs-44&vehicle_line=light&status=waiting
```

### Histórico por veículo

`GET /api/vehicles/{vehicle}/support-tickets` retorna uma lista paginada dos atendimentos vinculados ao ID do veículo, inclusive em sistemas diferentes. Os registros são ordenados por `created_at` decrescente e por ID decrescente no empate. O mesmo recurso de atendimento fornece sistema, contato, cliente, classificação, status, problema, solução e datas. Um veículo inexistente retorna HTTP 404. Um veículo sem atendimentos retorna HTTP 200 com lista vazia.

Use `system_id`, `problem_category_id`, `problem_subcategory_id`, `status`, `created_from` e `created_to` em conjunto. O intervalo usa a data de abertura no fuso da aplicação e inclui os dias inicial e final. Use `page` a partir de 1 e `per_page` de 1 a 100, com padrão de 15. Os links de paginação mantêm os filtros.

`meta.vehicle` contém o cadastro atual. `meta.total` conta os atendimentos da consulta filtrada. `meta.counts` sempre representa o histórico completo com `total`, `not_closed` e `closed`. Conforme a definição já usada nas métricas existentes, `not_closed` inclui todos os status diferentes de `closed`, inclusive `resolved`. As relações de subcategorias são consultadas por existência, mantendo um resultado por atendimento mesmo quando há várias subcategorias.

Filtros, resumos veiculares e detalhes consultam o cadastro atual. Corrigir a placa, a marca, o modelo ou a linha muda as próximas consultas, preservando os IDs das ligações e o texto original de problemas e soluções.

## Validação manual pela API

1. Em uma base de teste migrada, inicie a API com `php artisan serve --host=127.0.0.1 --port=8000`. Use `http://127.0.0.1:8000` como prefixo dos caminhos acima.
2. Crie a marca `Marca Exemplo` e guarde seu ID. Tente criar novamente o mesmo nome com caixa e espaços diferentes. Confira o erro 422 em `name`.
3. Crie dois veículos da mesma marca, um `light` com placa `hfs-4456` e outro `heavy` com placa `abc 1d23` e descrição omitida. Confira as placas canônicas. Repita o segundo cadastro com `ABC-1D23` e confira o erro 422 em `plate`.
4. Consulte `GET /api/vehicles?q=hfs%2044` e `GET /api/vehicles?q=exemplo&brand_id=1&vehicle_line=heavy&per_page=1`, substituindo `1` pelo ID correto. Confira `meta.total`, os resultados e a contagem inicial de vínculos.
5. Envie `PATCH /api/support-tickets/{id}` para dois atendimentos de teste com o ID do primeiro veículo. Consulte `GET /api/vehicles/{id}` e confira `support_tickets_count: 2`.
6. Atualize a marca ou a linha em `PATCH /api/vehicles/{id}` e consulte novamente os dois atendimentos. Edite o título de um deles omitindo `vehicle_id`, depois envie `{"vehicle_id":null}`. Confira o vínculo preservado na primeira edição e a contagem reduzida para 1 após a segunda.
7. Envie `DELETE` para os endpoints individuais de marcas e de veículos para conferir HTTP 405.

## Testes e dados fictícios

```powershell
php artisan test --filter=Vehicle
php artisan test
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --no-progress
```

O bootstrap da suíte força SQLite em memória. Os testes acionam `VehicleValidationSeeder` explicitamente nesse ambiente. O seeder é separado do `DatabaseSeeder` e rejeita conexões fora do SQLite em memória. A massa inclui duas marcas, veículos leves e pesados de uma mesma marca, placas dos dois padrões, veículos de marcas distintas com a mesma descrição, um veículo sem descrição, dois atendimentos para a mesma placa e um atendimento sem vínculo.

Os testes de veículos, histórico, métricas veiculares e exportação preparam suas fixtures tipadas em `beforeEach`. O contêiner da aplicação de teste mantém uma instância por caso. Antes, as fixtures eram propriedades dinâmicas em `$this`. O Pest as disponibilizava em execução, mas o Intelephense sinalizava `P1014` porque essas propriedades não tinham declaração estática. Nos testes de exportação, os callbacks tipados com `MockInterface` tornam visíveis os métodos de configuração que o Mockery adiciona aos objetos simulados em execução.

## Métricas veiculares e relatórios

Na aba **Métricas**, **Filtros veiculares** combina identificação, placa, marca, modelo e linha com período, status, sistema, categoria e subcategoria. Os filtros permanecem ativos ao recolher a seção. **Limpar filtros veiculares** conserva os demais filtros aplicados. A opção **Somente atendimentos sem veículo** desabilita filtros específicos de veículo.

**Estatísticas veiculares** permanece recolhida inicialmente e carrega ao expandir. A seção exibe indicadores, distribuição por linha, rankings e problemas por marca, modelo ou linha, com recorte por sistema e classificação. Os botões **Ver atendimentos** reutilizam o detalhamento paginado da aba. O ranking de placas oferece também o link para o histórico completo do veículo, com seus próprios filtros. Os totais descrevem o histórico do SupportFlow e a base de cálculo é o conjunto de atendimentos do recorte.

| Medida                                     | Regra                                                                                                                                                                                                                                                     |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Atendimentos com identificação             | Cada atendimento com um veículo vinculado conta uma vez. A participação usa todos os atendimentos filtrados como denominador.                                                                                                                             |
| Veículos distintos                         | Contagem de IDs de veículos com atendimento no recorte.                                                                                                                                                                                                   |
| Modelos distintos                          | Agrupamento pelo ID da marca e pela descrição completa, com capitalização e espaços equivalentes. A grafia original do primeiro veículo por ID no recorte é usada para exibição. Ano, versão, motorização e acentos continuam fazendo parte da descrição. |
| Modelo não informado                       | Grupo separado por marca, excluído da quantidade de modelos distintos e incluído na contagem de veículos distintos sem modelo.                                                                                                                            |
| Distribuição por linha e ranking de marcas | A participação usa somente os atendimentos com veículo no recorte. Os percentuais são arredondados a duas casas e retornam zero quando o denominador é zero.                                                                                              |
| Recorrência por veículo                    | O ranking usa o ID do veículo e o número de atendimentos no recorte. O resumo considera veículos com pelo menos dois atendimentos, o volume desses veículos e o maior total individual.                                                                   |
| Categoria e subcategoria                   | Cada atendimento conta uma vez em sua categoria. Cada vínculo com uma subcategoria conta uma ocorrência. Com o filtro específico de subcategoria, somente os vínculos daquela subcategoria são contabilizados no cruzamento.                              |

Os agrupamentos usam os dados atuais do veículo. Alterar marca, modelo ou linha pode reclassificar as estatísticas de períodos anteriores. O histórico do atendimento continua vinculado ao mesmo veículo e conserva o problema e a solução registrados.

Em **Exportar relatório**, **Incluir estatísticas veiculares** vem desmarcada para PDF e Excel. Os filtros aplicam-se aos indicadores tradicionais mesmo com a opção desmarcada. Quando marcada, o PDF acrescenta as tabelas veiculares e o Excel acrescenta **Resumo veicular**, **Linhas veiculares**, **Marcas**, **Modelos**, **Problemas por marca**, **Problemas por modelo** e **Problemas por linha**. As planilhas preservam os valores quantitativos como números e as participações em formato percentual.

Os arquivos contêm somente estatísticas agregadas. O critério de placa aparece como **Filtro de placa aplicado**, sem o termo usado. Placas cadastradas encontradas em rótulos descritivos são omitidas. O ranking por placa, os identificadores internos, as informações individuais de atendimentos, clientes e contatos ficam fora das exportações.

O template PDF usa `@style` para as larguras calculadas das colunas e das barras. O Blade entrega CSS completo ao Dompdf. Essa forma evita que o validador do editor interprete a interpolação Blade como parte do valor CSS.
