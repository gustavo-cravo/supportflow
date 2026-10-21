<?php

namespace Database\Seeders;

final class SupportFlowDemoCatalog
{
    /** @return list<array{name: string, description: string, issues: list<array{title: string, description: string, resolution: string}>}> */
    public static function systems(): array
    {
        return [
            [
                'name' => 'Gestão de Oficinas',
                'description' => 'Ordens de serviço, agendamento e operação das oficinas.',
                'issues' => [
                    ['title' => 'Ordem de serviço não permite finalizar', 'description' => 'A OS permanece em execução após a inclusão das peças e serviços. A equipe precisa liberar o veículo.', 'resolution' => 'Conferir os serviços obrigatórios, preencher o responsável técnico e validar a finalização de uma OS de teste com o contato.'],
                    ['title' => 'Impressão da OS corta a descrição dos serviços', 'description' => 'O comprovante impresso não apresenta todos os serviços e dificulta a conferência no balcão.', 'resolution' => 'Selecionar o modelo de impressão correto, ajustar o tamanho do papel e conferir a prévia antes de imprimir uma OS completa.'],
                ],
            ],
            [
                'name' => 'Consulta Veicular',
                'description' => 'Pesquisa de placas e consulta de informações de veículos.',
                'issues' => [
                    ['title' => 'Consulta de placa não localiza o veículo', 'description' => 'Uma placa informada pelo operador não retorna resultado, embora outras consultas funcionem.', 'resolution' => 'Conferir a digitação da placa, selecionar a base adequada e encaminhar ao fornecedor a evidência anonimizada se a divergência persistir.'],
                    ['title' => 'Consulta de placas apresenta tempo esgotado', 'description' => 'As consultas ficam aguardando resposta e afetam a abertura das ordens de serviço.', 'resolution' => 'Consultar a disponibilidade do provedor, testar uma única requisição e confirmar a normalização antes de liberar novas tentativas.'],
                ],
            ],
            [
                'name' => 'Integração Fiscal',
                'description' => 'Emissão fiscal e comunicação com os serviços autorizadores.',
                'issues' => [
                    ['title' => 'Nota rejeitada por configuração fiscal do produto', 'description' => 'A emissão é rejeitada após a importação do cadastro de um novo produto.', 'resolution' => 'Solicitar ao setor fiscal a revisão do CFOP e da tributação do item, atualizar o cadastro autorizado e validar nova emissão.'],
                    ['title' => 'Certificado não é reconhecido na emissão', 'description' => 'O serviço fiscal informa que não encontrou um certificado válido na estação configurada.', 'resolution' => 'Conferir validade e instalação do certificado com o responsável, revisar o vínculo da empresa e testar a comunicação com o autorizador.'],
                ],
            ],
            [
                'name' => 'Estoque e Vendas',
                'description' => 'Cadastros de produtos, movimentações e sincronização entre lojas.',
                'issues' => [
                    ['title' => 'Saldo de estoque não sincroniza com a filial', 'description' => 'Uma transferência já confirmada na matriz ainda não aparece no saldo da filial.', 'resolution' => 'Conferir o identificador da transferência, analisar a fila do conector e reprocessar apenas o evento pendente após descartar movimentação duplicada.'],
                    ['title' => 'Importação criou produtos com referências duplicadas', 'description' => 'A pesquisa apresenta dois cadastros semelhantes após a importação de uma planilha.', 'resolution' => 'Comparar referências e vínculos de movimentação, definir o cadastro correto com o responsável e ajustar o mapeamento das próximas importações.'],
                ],
            ],
            [
                'name' => 'Portal de Acesso',
                'description' => 'Perfis, permissões e acesso dos operadores às aplicações.',
                'issues' => [
                    ['title' => 'Operador não consegue acessar a consulta', 'description' => 'O operador autentica, mas recebe uma mensagem de permissão insuficiente ao abrir a consulta.', 'resolution' => 'Confirmar com o responsável o perfil necessário, revisar as permissões aprovadas e solicitar novo acesso para validar a liberação.'],
                    ['title' => 'Tela retorna ao login durante a operação', 'description' => 'O acesso é interrompido ao alternar entre as telas e o operador precisa entrar novamente.', 'resolution' => 'Conferir relógio da estação e domínio de acesso, testar em janela limpa e validar a sessão após remover somente os dados locais do site afetado.'],
                ],
            ],
            [
                'name' => 'Conector de Integrações',
                'description' => 'Filas, webhooks e troca de dados com parceiros.',
                'issues' => [
                    ['title' => 'Integração recebe o mesmo pedido mais de uma vez', 'description' => 'O parceiro reenviou um webhook após um timeout e a equipe precisa conferir o processamento.', 'resolution' => 'Comparar a chave externa e o histórico de recebimento, confirmar a deduplicação com o consultor e validar o retorno do processamento ao parceiro.'],
                    ['title' => 'Lote de integração aguarda análise do consultor', 'description' => 'Parte do lote não foi processada e o retorno depende da análise do mapeamento pelo consultor.', 'resolution' => 'Separar os identificadores rejeitados, revisar o mapeamento com o consultor e reprocessar somente os itens corrigidos após aprovação do setor responsável.'],
                ],
            ],
            [
                'name' => 'Oficina Legada',
                'description' => 'Sistema desativado após migração; mantém o histórico das oficinas.',
                'issues' => [
                    ['title' => 'OS histórica apresenta responsável antigo', 'description' => 'A conferência da migração identificou uma ordem de serviço com o responsável anterior à troca de equipe.', 'resolution' => 'Conferir o relatório de origem com a oficina, documentar a correspondência dos responsáveis e validar a consulta do histórico migrado.'],
                    ['title' => 'Fechamento antigo diverge do relatório de comissão', 'description' => 'O relatório legado agrupa serviços em uma competência diferente daquela usada na conferência.', 'resolution' => 'Comparar os filtros de competência e a data de fechamento, emitir o relatório na mesma referência e registrar a conferência do setor financeiro.'],
                ],
            ],
            [
                'name' => 'Consulta Legada',
                'description' => 'Provedor antigo de consultas, mantido apenas para referências históricas.',
                'issues' => [
                    ['title' => 'Histórico de placa usa formato anterior', 'description' => 'A pesquisa do histórico antigo não considera o padrão de placa usado no cadastro atual.', 'resolution' => 'Conferir a correspondência documentada entre os formatos e validar a pesquisa no histórico sem alterar os registros de origem.'],
                    ['title' => 'Relatório antigo exporta caracteres incorretos', 'description' => 'Os nomes aparecem com acentuação incorreta ao abrir a exportação histórica em outra ferramenta.', 'resolution' => 'Importar o arquivo com a codificação informada pelo sistema de origem e validar os nomes com a visualização original antes de compartilhar o relatório.'],
                ],
            ],
        ];
    }

    /** @return list<array{title: string, resolution: string}> */
    public static function generalArticles(): array
    {
        return [
            ['title' => 'Estação sem comunicação com os serviços', 'resolution' => "1. Conferir conexão local e acesso ao gateway.\n2. Testar a resolução do domínio do serviço.\n3. Registrar horário e mensagem do erro e encaminhar à equipe de rede quando necessário."],
            ['title' => 'Preparar evidências para análise de uma falha', 'resolution' => "1. Registrar os passos, horário e identificadores técnicos.\n2. Remover dados pessoais e segredos das evidências.\n3. Anexar o resultado esperado e o observado ao atendimento para o setor responsável."],
            ['title' => 'Validar recuperação de um backup de teste', 'resolution' => "1. Confirmar a existência e a data da cópia.\n2. Usar ambiente separado para a restauração.\n3. Conferir os registros esperados sem sobrescrever o ambiente operacional."],
            ['title' => 'Conferir comportamento após atualização do navegador', 'resolution' => "1. Registrar a versão e a tela afetada.\n2. Comparar com uma janela limpa.\n3. Limpar somente os dados do site necessário e repetir o fluxo com o contato."],
        ];
    }
}
