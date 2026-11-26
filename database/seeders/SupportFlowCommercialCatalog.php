<?php

namespace Database\Seeders;

use App\Enums\VehicleLine;

final class SupportFlowCommercialCatalog
{
    public const array SYSTEMS = ['Indicação Técnica', 'Consulta Veicular', 'Integrações e Cadastro'];

    public const array CUSTOMERS = [
        'Oficina Horizonte Azul', 'Rede Giro Norte', 'Centro Técnico Vale Claro', 'Frota Estrada Serena',
        'Oficina Ponto de Revisão', 'Distribuidora Eixo Solar', 'Autopeças Vértice Verde', 'Rede Rota do Cedro',
        'Frota Ponte das Serras', 'Centro Automotivo Alameda', 'Oficina Trilho do Vale', 'Frota Vila das Pedras',
        'Distribuidora Pátio Leste', 'Rede Oficina do Largo', 'Centro Diesel Refúgio',
    ];

    public const array CONTACTS = [
        'Marina', 'Daniel', 'Lívia', 'Caio', 'Joana', 'André', 'Sofia', 'Rafael', 'Bianca', 'Igor',
        'Camila', 'Tiago', 'Helena', 'Vinícius', 'Alice', 'Otávio', 'Nina', 'Pedro', 'Cecília', 'Mateus',
        'Laura', 'Bruno', 'Elisa', 'Renato', 'Isabela',
    ];

    private const array LIGHT_MODELS = [
        'VOLKSWAGEN' => ['Golf Comfortline', 'Saveiro Robust'],
        'CHEVROLET' => ['Onix LT', 'S10 LTZ'],
        'FIAT' => ['Uno Way', 'Strada Freedom'],
        'FORD' => ['Ranger XLS', 'Ka SE'],
        'TOYOTA' => ['Corolla XEi', 'Hilux SRV'],
        'HONDA' => ['Civic EX', 'Fit EXL'],
        'HYUNDAI' => ['HB20 Comfort', 'Creta Limited'],
        'RENAULT' => ['Duster Iconic', 'Sandero Stepway'],
        'NISSAN' => ['Kicks Advance', 'Frontier Attack'],
        'JEEP' => ['Renegade Longitude', 'Compass Limited'],
        'PEUGEOT' => ['208 Allure', 'Partner Rapid'],
        'CITROEN' => ['C3 Feel', 'Berlingo'],
        'MITSUBISHI' => ['L200 Triton Sport', 'ASX'],
        'KIA' => ['Sportage EX', 'Cerato SX'],
    ];

    private const array HEAVY_MODELS = [
        'VOLVO' => ['FH 540', 'FM 460'],
        'SCANIA' => ['R 450', 'P 320'],
        'MERCEDES-BENZ' => ['Actros 2651', 'Atego 2426'],
        'DAF' => ['XF 530', 'CF 410'],
        'IVECO' => ['S-Way 480', 'Tector 240E'],
        'MAN' => ['TGX 29.480', 'TGS'],
    ];

    public const array MONTHLY_VOLUME = [12, 16, 14, 19, 15, 21, 18, 25, 23, 28, 24, 35];

    public const array CATEGORY_ROTATION = [0, 1, 0, 2, 3, 4, 0, 5, 1, 6, 2, 0, 7, 3, 1, 2, 5, 0, 6, 4, 1, 3, 2, 0, 7];

    public const array CONTEXTS = [
        'abertura de ordem de serviço', 'revisão de orçamento', 'troca de turno',
        'pedido recebido no balcão', 'triagem do suporte', 'revisão de uma ficha na filial',
        'preparação de manutenção programada', 'conferência com a equipe de peças', 'consulta pelo portal interno',
        'revisão de catálogo antes da publicação', 'operação assistida da oficina', 'atendimento de uma filial',
        'passagem para o segundo nível', 'preparação da fila da manhã', 'análise de uma solicitação da frota',
        'registro de uma dúvida da oficina', 'conferência antes de repassar a consulta', 'revisão de evidência com a operação',
    ];

    // Each topic carries a title, the reported symptom, the resolution and its verification.
    public const array CLASSIFICATIONS = [
        ['name' => 'Indicação de lubrificantes', 'systems' => [0, 0, 0, 1], 'topics' => [
            ['Viscosidade divergente', 'Viscosidade difere entre busca rápida e ficha', 'A busca rápida e a ficha detalhada exibiram viscosidades diferentes para a mesma variante. A equipe suspendeu o uso da indicação enquanto comparava as duas origens.', 'A equipe de catálogo isolou uma revisão antiga, retirou a indicação duvidosa da consulta e publicou o vínculo atualizado no cenário de teste.', 'Foi repetida a consulta nos dois caminhos, com a mesma variante. Os resultados passaram a apontar para a mesma revisão e o aviso de conferência permaneceu visível.'],
            ['Especificação divergente', 'Ficha e lista de aplicações exibem especificações diferentes', 'O operador abriu a lista de aplicações e encontrou uma especificação diferente da exibida na ficha. A origem de cada texto aparecia com revisões distintas.', 'O suporte conciliou as referências de origem com o catálogo, removeu a duplicidade e direcionou a consulta para a versão revisada.', 'A ficha e a lista foram comparadas no ambiente de demonstração. A origem e a revisão aparecem iguais nas duas telas.'],
            ['Capacidade inconsistente', 'Capacidade de óleo muda ao alternar a variante', 'Após alternar a versão do veículo, a tela manteve o campo de capacidade da opção anterior. O operador interrompeu a preparação da proposta de serviço.', 'Foi corrigido o recarregamento dos campos dependentes. Quando a fonte não informa a capacidade, a ficha passou a exibir a ausência em vez de carregar o valor anterior.', 'Foram alternadas duas variantes fictícias. O campo foi recarregado em ambas e permaneceu vazio no caso sem fonte disponível.'],
        ]],
        ['name' => 'Aplicação de filtros', 'systems' => [0, 0, 1, 1], 'topics' => [
            ['Referência incompatível', 'Busca oferece um filtro de outra aplicação', 'O resultado incluía uma referência de filtro associada a outra versão. A oficina reteve a cotação até que a aplicação fosse revisada.', 'A associação indevida foi retirada da massa de teste e o catálogo passou a usar a chave completa de variante ao mostrar referências.', 'A consulta da variante original deixou de listar a referência incorreta e a variante que a utilizava manteve o resultado esperado no teste.'],
            ['Equivalência de peças', 'Equivalência de filtro aparece apenas em uma tela', 'A pesquisa por peça mostrou uma equivalência que não constava na ficha veicular. O balcão não tinha como saber qual tela consultava a revisão mais recente.', 'O suporte separou o cadastro histórico do atual e alinhou as telas para exibir a mesma origem e vigência da equivalência.', 'A revisão foi reproduzida pelos dois caminhos com referências fictícias e a opção histórica permaneceu distinguível.'],
            ['Filtro sem aplicação', 'Catálogo encontra a peça mas omite a aplicação', 'A pesquisa de referência retornava a peça, mas deixava o vínculo de aplicação vazio. A oficina precisava diferenciar ausência de informação de incompatibilidade.', 'A equipe registrou a ausência como condição explícita e enviou o vínculo ao fluxo de revisão de catálogo.', 'A consulta de teste indicou que a aplicação está em revisão, sem apresentar uma peça como validada para uso.'],
        ]],
        ['name' => 'Identificação de veículos', 'systems' => [1, 1, 0, 2], 'topics' => [
            ['Modelo identificado incorretamente', 'Consulta associa a placa à versão incorreta', 'A busca trouxe o modelo esperado, mas associou uma variante diferente da usada na ordem de serviço. O operador reteve a seleção automática.', 'O cadastro de demonstração teve a associação de variantes revisada e a seleção automática foi desativada para respostas ambíguas.', 'A equipe refez a pesquisa e confirmou que o operador consegue escolher a variante correta sem aceitar a resposta ambígua como definitiva.'],
            ['Linha veicular divergente', 'Linha exibida na busca diverge do cadastro', 'O operador encontrou uma divergência entre a linha do cadastro e a versão apresentada na busca. A classificação incorreta alterava os filtros disponíveis.', 'A equipe revisou a estratégia de mapeamento do provedor e reclassificou o exemplo fictício após conferir a família do modelo.', 'O resultado reapareceu na linha esperada e continuou acessível pelo histórico da placa de teste.'],
            ['Variante ou ano ambíguo', 'Consulta lista variantes sem distinguir o ano-modelo', 'A lista de resultados apresentava duas variantes com o mesmo texto. O usuário não conseguia saber qual ano-modelo estava selecionando.', 'A equipe passou a separar ano-modelo e família nas referências de demonstração e sinalizou casos em que a origem não informa o ano.', 'A revisão foi verificada com uma resposta completa e outra incompleta. Cada caso exibiu rótulo e indicação de ausência coerentes.'],
        ]],
        ['name' => 'Consulta por placa', 'systems' => [1, 1, 0], 'topics' => [
            ['Placa sem retorno', 'Pesquisa por placa termina sem resultado', 'A placa digitada não retornou veículo e a interface não diferenciou ausência na fonte de erro de comunicação.', 'A consulta foi reproduzida em ambiente controlado e a mensagem passou a informar falta de correspondência sem sugerir um veículo alternativo.', 'Foram usadas duas placas sintéticas, uma com cadastro e outra ausente. O fluxo exibiu os dois retornos esperados.'],
            ['Tempo de consulta', 'Consulta demora e impede abrir a ficha', 'A consulta permaneceu aguardando resposta do provedor. A oficina precisava continuar a triagem mesmo sem uma ficha disponível.', 'O suporte orientou a consulta manual enquanto o conector era analisado e conferiu o tratamento de timeout com um retorno simulado.', 'Foi conferido que o tempo esgotado libera a triagem e que uma nova consulta, quando autorizada, parte sem reaproveitar resposta incompleta.'],
            ['Formato da placa', 'Mesmo veículo retorna resultados diferentes com hífen', 'A equipe percebeu que a consulta digitada com separador retornava de outro modo quando comparada à pesquisa sem separador.', 'O cenário foi refeito nas duas entradas e a equipe ajustou a normalização anterior ao envio para a fonte de teste.', 'As duas entradas sintéticas chegaram à mesma chave e retornaram a mesma ficha na conferência.'],
        ]],
        ['name' => 'Dados técnicos e atualização', 'systems' => [2, 0, 1], 'topics' => [
            ['Informação ausente', 'Ficha de consulta abre com campos técnicos ausentes', 'O cadastro aparecia na busca, mas a ficha chegava sem parte dos campos técnicos. O operador precisava registrar a ausência sem completar por suposição.', 'O caso foi encaminhado à revisão documental e os campos incompletos passaram a exibir a origem e o estado da informação.', 'O setor conferiu que os campos vazios continuam identificados até existir uma fonte aprovada.'],
            ['Atualização de catálogo', 'Oficina solicita revisão de uma informação de catálogo', 'A oficina enviou uma referência de atualização e pediu retorno sobre a diferença para o cadastro em uso.', 'O suporte registrou a solicitação, vinculou a revisão ao fluxo de curadoria e publicou a atualização apenas na massa de demonstração.', 'O solicitante fictício conferiu a nova revisão e recebeu orientação para solicitar nova análise ao usar outra versão.'],
            ['Cadastro duplicado', 'Duas fichas representam a mesma família de veículo', 'A busca listou registros quase iguais e um deles não tinha origem cadastrada. A diferença impedia escolher uma ficha com segurança.', 'A curadoria identificou o registro principal, conservou o histórico de revisão e retirou do resultado a cópia de teste sem origem.', 'A pesquisa voltou a exibir uma única ficha ativa e a revisão anterior continuou acessível para conferência.'],
        ]],
        ['name' => 'Integração de catálogo', 'systems' => [2, 2, 0, 1], 'topics' => [
            ['Lote interrompido', 'Lote recebido não conclui a atualização de catálogo', 'O lote aparecia como recebido, mas parte das aplicações permanecia na versão anterior. O operador informou quais registros eram afetados.', 'A equipe isolou os itens que ficaram pendentes, conferiu a chave de deduplicação e reprocessou somente os eventos simulados com erro.', 'A nova conferência mostrou uma única revisão por item e manteve a fila sem registros duplicados.'],
            ['Mapeamento divergente', 'Conector importa a família no campo da variante', 'Após uma atualização, a busca passou a agrupar variantes distintas no mesmo rótulo. A amostra de integração mostrou troca de campos.', 'O integrador revisou o mapeamento com base em um lote fictício e corrigiu a origem de família, versão e revisão.', 'A importação de conferência preservou as relações esperadas e distinguiu as fichas que antes recebiam o mesmo rótulo.'],
            ['Atraso na sincronização', 'Filial continua consultando a revisão anterior', 'A revisão nova estava publicada, mas uma filial ainda recebia o catálogo anterior. O suporte comparou os horários de atualização.', 'A equipe verificou o consumo da fila e atualizou o catálogo simulado da filial sem reenviar o lote já processado.', 'A conferência comparou os identificadores de revisão da filial e da origem e verificou que o atraso deixou de aparecer.'],
        ]],
        ['name' => 'Acesso e permissões', 'systems' => [2, 2, 1, 0], 'topics' => [
            ['Sessão expirada', 'Sessão termina ao alternar entre consulta e ficha', 'O operador retornava à tela de autenticação após abrir a ficha em outra aba. A ordem de serviço permanecia em triagem.', 'O suporte reproduziu o fluxo com um perfil de teste e ajustou a manutenção da sessão ao abrir a ficha.', 'A consulta, a troca de aba e o retorno à tela inicial foram repetidos com a sessão de teste ativa.'],
            ['Permissão de consulta', 'Perfil de oficina não consegue abrir a ficha detalhada', 'A busca estava disponível, porém a ficha detalhada retornava uma mensagem de perfil insuficiente.', 'O responsável revisou a permissão prevista para esse papel e habilitou apenas o acesso necessário no cenário de teste.', 'A ficha passou a abrir para o papel autorizado e permaneceu indisponível para o papel de consulta restrita.'],
            ['Usuário bloqueado', 'Equipe não consegue entrar após as tentativas de acesso', 'O bloqueio impediu a equipe de retornar à consulta. A oficina pediu uma alternativa enquanto o acesso era conferido.', 'O suporte encaminhou a recuperação pelo fluxo previsto e verificou a liberação com uma conta inteiramente fictícia.', 'O login foi validado com o perfil de demonstração, sem alterar permissões de consulta ou compartilhar credenciais.'],
        ]],
        ['name' => 'Orientação operacional', 'systems' => [0, 1, 2], 'topics' => [
            ['Pesquisa por aplicação', 'Balcão solicita orientação para pesquisar por aplicação', 'A oficina queria distinguir pesquisa por variante de pesquisa por referência, especialmente quando o veículo ainda não estava identificado.', 'O suporte preparou um roteiro com a sequência de filtros e o caminho para registrar dúvidas quando a indicação estiver incompleta.', 'A operação repetiu o roteiro com um exemplo fictício e encontrou a ficha esperada ou o aviso de revisão em cada cenário.'],
            ['Comparação de fichas', 'Operador precisa comparar duas revisões de uma ficha', 'A equipe localizou duas revisões e precisava saber onde a interface indicava qual estava vigente.', 'O suporte demonstrou origem, data e histórico de revisão e registrou um procedimento para encaminhar divergências.', 'O operador conseguiu distinguir a revisão ativa da histórica e identificar quando a ficha precisava de conferência.'],
            ['Registro de evidências', 'Equipe quer padronizar os anexos de uma divergência', 'Os detalhes recebidos em um atendimento não permitiam reconstruir a consulta. A oficina pediu um roteiro de evidências.', 'O suporte orientou registrar tela, revisão, horário e resultado observado usando apenas exemplos anonimizados.', 'A equipe simulou um chamado e conferiu que as evidências reproduzem a sequência sem incluir identificadores de clientes reais.'],
        ]],
    ];

    /** @return list<array{brand: string, plate: string, line: VehicleLine, model: ?string}> */
    public static function vehicles(): array
    {
        $vehicles = [];
        foreach ([VehicleLine::Light->value => self::LIGHT_MODELS, VehicleLine::Heavy->value => self::HEAVY_MODELS] as $line => $brands) {
            foreach ($brands as $brand => [$primary, $alternative]) {
                $model = count($vehicles) % 12 === 0 ? null : $alternative;
                foreach ([$primary, $primary, $model] as $description) {
                    $number = count($vehicles) + 1;
                    $vehicles[] = [
                        'brand' => $brand, 'line' => VehicleLine::from($line), 'model' => $description,
                        'plate' => $number % 3 === 0 ? sprintf('SFC9%03d', $number) : sprintf('SFC9C%02d', $number),
                    ];
                }
            }
        }

        return $vehicles;
    }
}
