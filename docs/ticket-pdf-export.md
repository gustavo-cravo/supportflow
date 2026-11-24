# Exportação individual de atendimento em PDF

Nos detalhes de um atendimento, **Exportar PDF** abre o modal **Exportar atendimento**. As informações gerais e a descrição ficam marcadas e são obrigatórias. Classificação do problema, identificação veicular, resolução e confirmação, pendências, lembretes e atendimentos relacionados começam marcados. **Selecionar tudo** restaura todas as opções disponíveis. Sem veículo vinculado, a opção veicular fica desabilitada. O modal indica quando as listas de pendências, lembretes ou atendimentos relacionados estão vazias.

O download mantém o usuário na tela de detalhes. Uma falha mantém o modal aberto e conserva as escolhas. **Cancelar** encerra a espera pelo arquivo no navegador. As escolhas valem para uma única exportação.

## Endpoint

`POST /api/support-tickets/{id}/export/pdf` segue as regras de acesso da consulta de atendimento. Envie JSON com as seis opções complementares. Cada campo aceita somente os booleanos JSON `true` e `false`. Campos omitidos assumem `true`.

```json
{
    "include_classification": true,
    "include_vehicle": true,
    "include_resolution": true,
    "include_pending_tasks": false,
    "include_reminders": false,
    "include_related_tickets": true
}
```

HTTP 200 retorna `application/pdf` e `Content-Disposition: attachment`, com nome como `supportflow-atendimento-0003-2026-11-24.pdf` e `Cache-Control: private, no-store`. O número é preenchido à esquerda até quatro dígitos. A data de geração usa o horário de São Paulo. Atendimentos inexistentes retornam HTTP 404, opções inválidas retornam HTTP 422 e falhas de geração retornam HTTP 500 com uma mensagem genérica.

## Estrutura do PDF

O documento A4 retrato exibe número, título e status atual no cabeçalho. As informações gerais trazem datas de abertura, atualização e encerramento, sistema, contato e cliente. A descrição do problema mantém o texto cadastrado. O rodapé repete o número do atendimento, a data de geração e a numeração das páginas.

Quando selecionadas, as seções complementares trazem a categoria e todas as subcategorias, a identificação atual do veículo, a solução e os dados de confirmação, as pendências e os lembretes diretamente vinculados e um resumo do atendimento anterior e dos retornos diretos. Seções desmarcadas ficam fora do documento. Sem veículo, a identificação veicular é omitida mesmo se solicitada. As demais seções selecionadas exibem mensagens quando faltam dados.

A placa vinculada pode aparecer no PDF individual. De contatos e clientes, o relatório usa apenas os nomes. O resumo de atendimentos relacionados usa número, título, sistema, abertura e status. O backend consulta somente as relações das seções escolhidas. O Blade escapa os textos do usuário e o Dompdf renderiza com recursos remotos e scripts desabilitados. A exportação é uma operação de leitura.

## Verificação manual

1. Abra um atendimento em aberto e exporte com todas as opções disponíveis. Confira o status, a data de geração e as indicações de campos sem dados.
2. Abra um atendimento com veículo, múltiplas subcategorias e atividades vinculadas. Confira as informações com o cadastro atual e exporte novamente marcando apenas as duas seções obrigatórias.
3. Gere um documento com descrição longa e vários retornos. Confira as quebras de parágrafo, a numeração de páginas e o cabeçalho repetido da tabela de atendimentos relacionados.
