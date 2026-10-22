import { taskStatuses } from '@/utils/format'
const text = (name, label, extra = {}) => ({ name, label, type: 'text', max: 255, ...extra })
const memo = (name, label, extra = {}) =>
    text(name, label, { type: 'textarea', max: 16000, full: true, ...extra })
const relation = (name, label, endpoint, extra = {}) => ({
    name,
    label,
    endpoint,
    type: 'relation',
    ...extra,
})
const ticket = () =>
    relation('support_ticket_id', 'Atendimento', 'support-tickets', { labelField: 'title' })
const search = {
    name: 'q',
    label: 'Pesquisar',
    placeholder: 'Pesquisar nos registros…',
    type: 'search',
}
const registration = [
    text('name', 'Nome', { required: true, full: true }),
    text('phone', 'Telefone', { max: 50 }),
    text('email', 'E-mail', { type: 'email' }),
    memo('notes', 'Observações'),
]
export const resources = {
    systems: {
        endpoint: 'systems',
        path: '/sistemas',
        title: 'Sistemas',
        singular: 'sistema',
        icon: 'layers',
        description: 'Organize os sistemas que recebem seus atendimentos.',
        fields: [
            text('name', 'Nome', { required: true, full: true }),
            memo('description', 'Descrição'),
            {
                name: 'is_active',
                label: 'Sistema ativo',
                type: 'checkbox',
                default: true,
                full: true,
            },
        ],
        filters: [
            search,
            {
                name: 'is_active',
                label: 'Situação',
                type: 'select',
                options: [
                    { value: '1', label: 'Ativos' },
                    { value: '0', label: 'Inativos' },
                ],
            },
        ],
        columns: [
            { key: 'name', label: 'Sistema', primary: true },
            { key: 'description', label: 'Descrição' },
            { key: 'is_active', label: 'Situação', type: 'badge' },
        ],
    },
    contacts: {
        endpoint: 'contacts',
        path: '/contatos',
        title: 'Contatos',
        singular: 'contato',
        icon: 'users',
        description: 'As pessoas que procuram você, sempre por perto.',
        fields: registration,
        filters: [search],
        columns: [
            { key: 'name', label: 'Nome', primary: true },
            { key: 'phone', label: 'Telefone' },
            { key: 'email', label: 'E-mail' },
            { key: 'notes', label: 'Observações' },
        ],
    },
    customers: {
        endpoint: 'customers',
        path: '/clientes',
        title: 'Clientes',
        singular: 'cliente',
        icon: 'building',
        description: 'Empresas, lojas e clientes dos seus atendimentos.',
        fields: [
            registration[0],
            {
                name: 'document',
                label: 'CPF/CNPJ',
                type: 'text',
                mask: 'cpf-cnpj',
                requiredOnCreate: true,
                inputmode: 'numeric',
                placeholder: 'CPF com 11 dígitos ou CNPJ com 14 dígitos',
            },
            ...registration.slice(1),
        ],
        filters: [search],
        columns: [
            { key: 'name', label: 'Cliente', primary: true },
            { key: 'phone', label: 'Telefone' },
            { key: 'email', label: 'E-mail' },
            { key: 'notes', label: 'Observações' },
        ],
    },
    'pending-tasks': {
        endpoint: 'pending-tasks',
        path: '/pendencias',
        title: 'Pendências',
        singular: 'pendência',
        icon: 'tasks',
        description: 'Nada fica para trás. Acompanhe o que ainda precisa de atenção.',
        fields: [
            memo('description', 'Descrição', { required: true }),
            ticket(),
            { name: 'due_at', label: 'Prazo', type: 'datetime-local' },
            {
                name: 'status',
                label: 'Situação',
                type: 'select',
                options: taskStatuses,
                editOnly: true,
                required: true,
            },
            memo('completion_notes', 'Observações da conclusão'),
        ],
        filters: [
            {
                name: 'filter',
                label: 'Acompanhamento',
                type: 'select',
                options: [
                    { value: 'open', label: 'Abertas' },
                    { value: 'overdue', label: 'Atrasadas' },
                    { value: 'completed', label: 'Concluídas' },
                ],
            },
            { name: 'status', label: 'Situação', type: 'select', options: taskStatuses },
            ticket(),
        ],
        columns: [
            { key: 'description', label: 'Pendência', primary: true },
            { key: 'support_ticket', label: 'Atendimento', type: 'ticket' },
            { key: 'due_at', label: 'Prazo', type: 'date' },
            { key: 'status', label: 'Situação', type: 'badge' },
        ],
        complete: true,
    },
    reminders: {
        endpoint: 'reminders',
        path: '/lembretes',
        title: 'Lembretes',
        singular: 'lembrete',
        icon: 'bell',
        description: 'O retorno certo, na hora certa. Planeje os próximos contatos.',
        fields: [
            text('title', 'Título', { required: true, full: true }),
            memo('description', 'Descrição'),
            ticket(),
            {
                name: 'scheduled_at',
                label: 'Data e horário previstos',
                type: 'datetime-local',
                required: true,
            },
        ],
        filters: [
            {
                name: 'filter',
                label: 'Período',
                type: 'select',
                options: [
                    { value: 'today', label: 'Hoje' },
                    { value: 'overdue', label: 'Atrasados' },
                    { value: 'upcoming', label: 'Próximos' },
                    { value: 'completed', label: 'Concluídos' },
                    { value: 'pending', label: 'Não concluídos' },
                ],
            },
            ticket(),
        ],
        columns: [
            { key: 'title', label: 'Lembrete', primary: true },
            { key: 'support_ticket', label: 'Atendimento', type: 'ticket' },
            { key: 'scheduled_at', label: 'Previsão', type: 'date' },
            { key: 'completion', label: 'Situação', type: 'badge' },
        ],
        complete: true,
    },
    'knowledge-articles': {
        endpoint: 'knowledge-articles',
        path: '/conhecimento',
        title: 'Base de conhecimento',
        singular: 'artigo',
        icon: 'book',
        description: 'Resolva uma vez. Encontre a solução sempre que precisar.',
        fields: [
            relation('system_id', 'Sistema', 'systems', { full: true }),
            memo('problem', 'Problema', { required: true }),
            memo('resolution_procedure', 'Procedimento de resolução', { required: true }),
        ],
        filters: [
            { ...search, placeholder: 'Pesquisar problema, solução ou sistema…' },
            relation('system_id', 'Sistema', 'systems'),
        ],
        columns: [
            { key: 'problem', label: 'Problema', primary: true },
            { key: 'resolution_procedure', label: 'Solução' },
            { key: 'system', label: 'Sistema', type: 'system' },
            { key: 'updated_at', label: 'Atualização', type: 'date' },
        ],
    },
}
export const ticketFields = [
    text('title', 'Título', {
        required: true,
        full: true,
        placeholder: 'Ex.: Falha ao emitir nota fiscal',
    }),
    relation('system_id', 'Sistema', 'systems', { required: true }),
    relation('contact_id', 'Contato', 'contacts', { required: true }),
    relation('customer_id', 'Cliente', 'customers'),
    relation('previous_ticket_id', 'Atendimento anterior', 'support-tickets', {
        labelField: 'title',
    }),
    memo('description', 'Descrição do problema', { required: true }),
]
