# SupportFlow — Demonstração comercial

O lote **SFT-COMERCIAL v1** simula uma operação de suporte a catálogos automotivos. Os casos, empresas, contatos, placas e ocorrências são fictícios. Marcas e nomes de modelos dão contexto à apresentação. As narrativas usam fontes e respostas simuladas e não publicam valores de viscosidade, tolerâncias ou capacidades como orientação técnica.

## Executar na base local

A seeder usa a conexão padrão do Laravel, a mesma que as demais seeders usam. SFT-COMERCIAL, SFT-QA e SFT-VEIC podem coexistir nessa base. Com o esquema migrado e o catálogo permanente de marcas instalado, execute o comando abaixo.

```bash
php artisan db:seed --class=SupportFlowCommercialDemoSeeder
```

A carga é explícita e aceita apenas `local` e `testing`. Em uma base nova, aplique as migrations e instale o catálogo com `php artisan db:seed --class=VehicleBrandSeeder` antes de carregar SFT-COMERCIAL. O comando imprime a janela efetivamente semeada no horário de Brasília.

## Quantitativos e distribuição

| Entidade | Quantidade |
| --- | ---: |
| Atendimentos | 250 |
| Com veículo | 150 (60%) |
| Sem veículo | 100 |
| Com múltiplas subcategorias | 46 |
| Com atendimento anterior | 20 |
| Sistemas | 3 |
| Clientes | 15 |
| Contatos | 25 |
| Categorias | 8 |
| Subcategorias | 24 |
| Pendências | 20 (11 pendentes e 9 concluídas) |
| Lembretes | 15 (9 pendentes e 6 concluídos) |
| Artigos | 15 (12 por sistema e 3 gerais) |
| Veículos | 60 |
| Marcas com ocorrências | 20, reutilizadas |

Pendências pendentes incluem quatro vencidas, quatro com prazo futuro e três sem prazo na carga. Os lembretes pendentes incluem três agendados para ontem, três para o dia da carga e três futuros. Os recortes relativos mudam à medida que os dias passam.

### Sistemas e status

Os três sistemas usam o prefixo `SFT-COMERCIAL |` na interface.

| Sistema | Atendimentos |
| --- | ---: |
| Indicação Técnica | 110 |
| Consulta Veicular | 95 |
| Integrações e Cadastro | 45 |

| Status | Atendimentos |
| --- | ---: |
| Encerrado | 113 |
| Aberto | 38 |
| Em andamento | 37 |
| Aguardando | 37 |
| Resolvido | 25 |

### Categorias e subcategorias

Os totais das subcategorias contam ocorrências. Um atendimento pode contribuir para duas subcategorias da mesma categoria.

| Categoria | Atendimentos | Ocorrências por subcategoria |
| --- | ---: | --- |
| Indicação de lubrificantes | 59 | Viscosidade divergente (23), Especificação divergente (24), Capacidade inconsistente (24) |
| Aplicação de filtros | 42 | Referência incompatível (18), Equivalência de peças (15), Filtro sem aplicação (15) |
| Identificação de veículos | 43 | Modelo identificado incorretamente (18), Linha veicular divergente (18), Variante ou ano ambíguo (16) |
| Consulta por placa | 26 | Placa sem retorno (10), Tempo de consulta (12), Formato da placa (8) |
| Dados técnicos e atualização | 20 | Informação ausente (8), Atualização de catálogo (9), Cadastro duplicado (6) |
| Integração de catálogo | 19 | Lote interrompido (9), Mapeamento divergente (6), Atraso na sincronização (8) |
| Acesso e permissões | 20 | Sessão expirada (7), Permissão de consulta (9), Usuário bloqueado (8) |
| Orientação operacional | 21 | Pesquisa por aplicação (8), Comparação de fichas (8), Registro de evidências (9) |

### Evolução mensal

A janela contém o mês da carga e os 11 meses anteriores no fuso `America/Sao_Paulo`. As aberturas são limitadas ao momento da carga. Os seis meses apresentados inicialmente no Dashboard contêm **153 atendimentos**, dos quais **91 com veículo**.

| Mês relativo à carga | Total | Com veículo | Sem veículo |
| --- | ---: | ---: | ---: |
| −11 | 12 | 8 | 4 |
| −10 | 16 | 10 | 6 |
| −9 | 14 | 8 | 6 |
| −8 | 19 | 11 | 8 |
| −7 | 15 | 9 | 6 |
| −6 | 21 | 13 | 8 |
| −5 | 18 | 10 | 8 |
| −4 | 25 | 15 | 10 |
| −3 | 23 | 15 | 8 |
| −2 | 28 | 16 | 12 |
| −1 | 24 | 14 | 10 |
| Atual | 35 | 21 | 14 |

### Marcas, modelos e linhas

São **42 veículos leves com 115 ocorrências** e **18 pesados com 35**. Há 35 grupos de marca e modelo com descrição, cinco veículos sem modelo e pelo menos duas placas com o mesmo modelo principal por marca. Há 20 placas no formato tradicional e 40 no formato Mercosul. Quarenta e cinco veículos voltam a aparecer, somando 135 atendimentos em veículos recorrentes. O máximo é oito atendimentos para uma placa.

| Marca | Linha | Total | Ocorrências por modelo |
| --- | --- | ---: | --- |
| Volkswagen | Leve | 13 | Golf Comfortline (10), sem modelo (3) |
| Chevrolet | Leve | 8 | Onix LT (6), S10 LTZ (2) |
| Fiat | Leve | 10 | Uno Way (8), Strada Freedom (2) |
| Ford | Leve | 7 | Ranger XLS (5), Ka SE (2) |
| Toyota | Leve | 9 | Corolla XEi (6), sem modelo (3) |
| Honda | Leve | 7 | Civic EX (5), Fit EXL (2) |
| Hyundai | Leve | 10 | HB20 Comfort (8), Creta Limited (2) |
| Renault | Leve | 9 | Duster Iconic (5), Sandero Stepway (4) |
| Nissan | Leve | 10 | Kicks Advance (7), sem modelo (3) |
| Jeep | Leve | 10 | Renegade Longitude (6), Compass Limited (4) |
| Peugeot | Leve | 7 | 208 Allure (5), Partner Rapid (2) |
| Citroën | Leve | 7 | C3 Feel (5), Berlingo (2) |
| Mitsubishi | Leve | 3 | L200 Triton Sport (2), sem modelo (1) |
| Kia | Leve | 5 | Sportage EX (4), Cerato SX (1) |
| Volvo | Pesada | 11 | FH 540 (9), FM 460 (2) |
| Scania | Pesada | 8 | R 450 (7), P 320 (1) |
| Mercedes-Benz | Pesada | 5 | Actros 2651 (4), sem modelo (1) |
| DAF | Pesada | 3 | XF 530 (2), CF 410 (1) |
| Iveco | Pesada | 4 | S-Way 480 (3), Tector 240E (1) |
| MAN | Pesada | 4 | TGX 29.480 (3), TGS (1) |

## Arquitetura e propriedade

- `SupportFlowCommercialDemoSeeder.php` é a entrada Artisan. `SupportFlowCommercialCatalog.php` contém a frota, os temas e textos revisáveis. `SupportFlowCommercialDataset.php` verifica o esquema e os conflitos na conexão ativa, monta os registros e administra a transação. A persistência reaproveita `SaveSupportTicket`, `SavePendingTask`, os pivôs e os modelos da aplicação.
- A tabela existente `vehicle_demo_batches` admite mais de um ID. A linha **2** registra o perfil, a versão, a janela original e os IDs das entidades novas. O lote SFT-VEIC usa a linha **1**, inclusive na limpeza e na adoção de marcas. As marcas são consultadas pelo nome canônico e ficam fora da propriedade comercial.
- Em uma primeira carga, placas, nomes dos sistemas, nomes normalizados das categorias e e-mails reservados são verificados antes de reservar o lote. Conflitos impedem a carga. A reserva pela chave primária e a transação impedem uma segunda criação do mesmo lote e revertem uma tentativa que falhe.
- Em uma reexecução, a seeder confere a versão e a presença dos IDs registrados, sem recriar linhas ou recalcular datas. Edições feitas na interface são preservadas. A verificação do manifesto valida presença e quantidade dos registros gerenciados, sem restaurar valores ou comparar todos os pivôs. Um manifesto incompatível ou com registro faltante provoca erro.
- A limpeza `demo:vehicles:clean` cobre SFT-VEIC. As datas relativas de SFT-COMERCIAL são fixadas na primeira carga e mantidas nas reexecuções. A migration existente do manifesto impede remover sua tabela enquanto houver um lote.

## Roteiro de apresentação

| Placa sintética | Ocorrência inicial | Caminho do retorno | Chamados da placa |
| --- | --- | --- | ---: |
| `SFC9C01` | Viscosidade entre busca rápida e ficha, Volkswagen Golf | Indicação Técnica → Consulta Veicular | 8 |
| `SFC9C43` | Referência de filtro incompatível, Volvo FH | Indicação Técnica → Consulta Veicular | 7 |
| `SFC9C46` | Identificação de versão divergente, Scania R | Consulta Veicular → Integrações e Cadastro | 6 |

1. Em **Visão geral**, confira os cartões e abra uma pendência vencida ou um lembrete do dia. Em **Métricas**, comece com **Últimos 6 meses** e depois mude para **Todo o histórico**. SFT-COMERCIAL contribui com 153 e 250 chamados nesses recortes, respectivamente. Em uma base compartilhada, filtre por um sistema com prefixo `SFT-COMERCIAL |` para ver somente os chamados comerciais daquele sistema.
2. Em **Métricas veiculares**, compare as linhas, abra a marca Volkswagen e consulte o histórico de `SFC9C01`. Para refinar, use linha pesada, Volvo e a categoria **Aplicação de filtros**, ou Scania e **Identificação de veículos**.
3. No histórico de cada placa, abra o chamado inicial do mês anterior que possui a pendência concluída e o lembrete **Retorno à operação para validar a correção**. Confira as duas subcategorias, a solução, a confirmação e o encerramento. Abra o novo chamado em **Relacionados** e confirme a mesma placa em outro sistema.
4. Para o **PDF estatístico configurável**, use o resumo geral, a série mensal e os rankings de marcas e modelos. Para o **PDF estatístico padrão**, comece com **Últimos 30 dias** e inclua estatísticas veiculares. Para **XLSX**, use **Todo o histórico** e, se houver outras massas na base, selecione um sistema comercial para restringir o recorte.
5. Para o **PDF analítico**, selecione os chamados iniciais e seus retornos, agrupe por modelo e habilite descrição, subcategorias, atendimento anterior, resolução e confirmação. Gere também o **PDF individual** de um chamado inicial. Analítico e individual são PDFs, enquanto o XLSX vem da exportação estatística.
6. Na **Base de conhecimento**, busque “viscosidade”, “filtro” ou “catálogo” para mostrar o problema, o tratamento simulado e o procedimento de conferência.

## Validação automatizada

O arquivo `tests/Feature/SupportFlowCommercialDemoSeederTest.php` verifica o manifesto, os quantitativos, as datas, os três casos completos, os filtros, os PDFs, o XLSX, a idempotência, a preservação, a conexão padrão, a guarda de produção e a reversão da carga. Os **10 testes comerciais passaram**. O teste em ambiente `local` carregou as três massas na conexão padrão apontada para um arquivo SQLite temporário e confirmou 282 atendimentos e os manifestos distintos de SFT-VEIC e SFT-COMERCIAL. Os demais casos usaram SQLite em memória.

```bash
php vendor/bin/pest tests/Feature/SupportFlowCommercialDemoSeederTest.php
php vendor/bin/pint --test
php vendor/bin/phpstan analyse --no-progress
```

Pint e PHPStan passaram. A execução conjunta das quatro suítes de seeders, com limite de PHP de 512 MiB para as exportações, teve **47 de 50 testes aprovados**. Os três testes apontados abaixo falharam ao executar a raiz. Os quantitativos detalhados foram conferidos em uma carga descartável com relógio fixado em 26/11/2026 às 15h, que gerou aberturas de 03/12/2025 a 26/11/2026.

## Limitações e tarefa técnica independente

- Os totais gerais incluem os demais atendimentos do banco.
- O PDF estatístico integral com todas as seções veiculares atingiu o limite de 128 MiB do PHP na validação inicial. Em um processo isolado com limite de 512 MiB, a exportação integral retornou HTTP 200 com pico de 198 MiB. Para apresentação, confira o limite do servidor ou aplique o recorte de 30 dias.
- **Tarefa técnica independente.** Alinhar a documentação e os testes com o contrato do `DatabaseSeeder`. A cópia de trabalho auditada chama SFT-COMERCIAL a partir da raiz. Essa chamada produz três sistemas e um manifesto onde `SupportFlowValidationSeederTest`, `VehicleDemoSeederTest` e `VehicleBrandSeederTest` esperam encontrar as tabelas correspondentes vazias.
