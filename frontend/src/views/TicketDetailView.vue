<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/services/api'
import { resources } from '@/config/resources'
import { displayStatus, formatDate, ticketStatuses, ticketSubcategories } from '@/utils/format'
import { useNotifications } from '@/composables/useNotifications'
import AppIcon from '@/components/AppIcon.vue'
import CloseTicketModal from '@/components/CloseTicketModal.vue'
import PageHeading from '@/components/PageHeading.vue'
import RecordDetail from '@/components/RecordDetail.vue'
import StatePanel from '@/components/StatePanel.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TicketPdfExportModal from '@/components/TicketPdfExportModal.vue'
import VehicleIdentity from '@/components/VehicleIdentity.vue'
const route = useRoute(),
    { notify } = useNotifications()
const ticket = ref(null),
    loading = ref(true),
    error = ref(''),
    status = ref(''),
    busy = ref(false),
    closing = ref(false),
    exporting = ref(false),
    quickView = ref('')
const subcategories = computed(() => ticketSubcategories(ticket.value))
const historyReturn = computed(() => {
    const target = route.query.retorno
    return typeof target === 'string' && /^\/veiculos\/\d+\/historico(?:\?[^#]*)?$/.test(target)
        ? target
        : null
})
const vehicleHistory = computed(() => {
    const path = '/veiculos/' + ticket.value?.vehicle?.id + '/historico'
    return historyReturn.value?.split('?')[0] === path ? historyReturn.value : path
})
const controller = new AbortController()
async function load() {
    loading.value = true
    error.value = ''
    try {
        ticket.value = (
            await api.get('support-tickets/' + route.params.id, undefined, controller.signal)
        ).data
        status.value = ticket.value.status
    } catch (exception) {
        if (!controller.signal.aborted) error.value = exception.message
    } finally {
        loading.value = false
    }
}
async function changeStatus() {
    if (status.value === 'closed') {
        status.value = ticket.value.status
        closing.value = true
        return
    }
    busy.value = true
    try {
        ticket.value = (
            await api.patch('support-tickets/' + ticket.value.id + '/status', {
                status: status.value,
            })
        ).data
        notify('Status atualizado.')
    } catch (exception) {
        status.value = ticket.value.status
        notify(exception.message, 'error')
    } finally {
        busy.value = false
    }
}
function onClosed(record) {
    ticket.value = record
    status.value = record.status
    closing.value = false
}
load()
onBeforeUnmount(() => controller.abort())
</script>
<template>
    <RouterLink :to="historyReturn || '/atendimentos'" class="back-link"
        ><AppIcon name="back" :size="16" />{{
            historyReturn ? 'Voltar ao histórico veicular' : 'Voltar aos atendimentos'
        }}</RouterLink
    ><StatePanel v-if="loading || error" :loading="loading" :error="error" @retry="load" /><template
        v-else-if="ticket"
        ><PageHeading
            :title="ticket.title"
            :eyebrow="'ATENDIMENTO #' + String(ticket.id).padStart(4, '0')"
            :description="'Aberto em ' + formatDate(ticket.created_at)"
            ><button class="btn btn-secondary" type="button" @click="exporting = true">
                <AppIcon name="download" :size="16" />Exportar PDF</button
            ><RouterLink
                class="btn btn-secondary"
                :to="{
                    name: 'ticket-edit',
                    params: { id: ticket.id },
                    query: historyReturn ? { retorno: historyReturn } : {},
                }"
                ><AppIcon name="edit" :size="16" />Editar</RouterLink
            ><button
                v-if="ticket.status !== 'closed'"
                class="btn btn-primary"
                type="button"
                :disabled="busy"
                @click="closing = true"
            >
                <AppIcon name="check" :size="17" />Encerrar atendimento
            </button></PageHeading
        >
        <div class="ticket-detail-layout">
            <div class="detail-main">
                <section class="panel detail-panel">
                    <div class="panel-heading">
                        <h2>Descrição do problema</h2>
                        <StatusBadge :value="ticket.status" />
                    </div>
                    <p class="preserve-text">{{ ticket.description }}</p>
                </section>
                <section class="panel detail-panel">
                    <div class="panel-heading">
                        <h2>Resolução e confirmação</h2>
                        <AppIcon name="check" />
                    </div>
                    <dl class="detail-grid">
                        <div class="field-full">
                            <dt>Solução aplicada</dt>
                            <dd class="preserve-text">
                                {{ ticket.resolution || 'A solução ainda não foi registrada.' }}
                            </dd>
                        </div>
                        <div>
                            <dt>Fonte da confirmação</dt>
                            <dd>{{ displayStatus(ticket.verification_source) }}</dd>
                        </div>
                        <div>
                            <dt>Confirmado em</dt>
                            <dd>{{ formatDate(ticket.verified_at) }}</dd>
                        </div>
                        <div class="field-full">
                            <dt>Confirmado por</dt>
                            <dd>{{ ticket.verified_by || 'Não informado' }}</dd>
                        </div>
                        <div class="field-full">
                            <dt>Como foi verificado</dt>
                            <dd class="preserve-text">
                                {{ ticket.verification_notes || 'Nenhuma observação registrada.' }}
                            </dd>
                        </div>
                    </dl>
                </section>
                <section class="panel detail-panel">
                    <div class="panel-heading">
                        <h2>
                            Pendências associadas
                            <span class="count-pill">{{ ticket.pending_tasks.length }}</span>
                        </h2>
                        <RouterLink
                            class="text-button"
                            :to="{
                                path: '/pendencias',
                                query: { support_ticket_id: ticket.id, novo: 1 },
                            }"
                            >Adicionar</RouterLink
                        >
                    </div>
                    <div v-if="ticket.pending_tasks.length" class="activity-list">
                        <div
                            v-for="task in ticket.pending_tasks"
                            :key="task.id"
                            class="activity-row"
                        >
                            <span class="activity-symbol" :class="{ danger: task.is_overdue }"
                                ><AppIcon name="tasks" :size="18"
                            /></span>
                            <div>
                                <p>{{ task.description }}</p>
                                <small>{{ formatDate(task.due_at) }}</small>
                            </div>
                            <StatusBadge :value="task.status" :overdue="task.is_overdue" />
                        </div>
                    </div>
                    <p v-else class="inline-empty">
                        Nenhuma pendência associada a este atendimento.
                    </p>
                    <RouterLink
                        v-if="ticket.pending_tasks.length"
                        class="section-bottom-link"
                        :to="{ path: '/pendencias', query: { support_ticket_id: ticket.id } }"
                        >Gerenciar pendências <AppIcon name="arrow" :size="15"
                    /></RouterLink>
                </section>
                <section class="panel detail-panel">
                    <div class="panel-heading">
                        <h2>
                            Lembretes associados
                            <span class="count-pill">{{ ticket.reminders.length }}</span>
                        </h2>
                        <RouterLink
                            class="text-button"
                            :to="{
                                path: '/lembretes',
                                query: { support_ticket_id: ticket.id, novo: 1 },
                            }"
                            >Adicionar</RouterLink
                        >
                    </div>
                    <div v-if="ticket.reminders.length" class="activity-list">
                        <div
                            v-for="reminder in ticket.reminders"
                            :key="reminder.id"
                            class="activity-row"
                        >
                            <span class="activity-symbol"><AppIcon name="bell" :size="18" /></span>
                            <div>
                                <p>{{ reminder.title }}</p>
                                <small>{{ formatDate(reminder.scheduled_at) }}</small>
                            </div>
                            <StatusBadge
                                :value="reminder.completed_at ? 'completed' : 'pending'"
                                :overdue="reminder.is_overdue"
                            />
                        </div>
                    </div>
                    <p v-else class="inline-empty">Nenhum lembrete associado a este atendimento.</p>
                    <RouterLink
                        v-if="ticket.reminders.length"
                        class="section-bottom-link"
                        :to="{ path: '/lembretes', query: { support_ticket_id: ticket.id } }"
                        >Gerenciar lembretes <AppIcon name="arrow" :size="15"
                    /></RouterLink>
                </section>
            </div>
            <aside class="detail-aside">
                <section class="panel detail-panel">
                    <h2>Informações</h2>
                    <label class="form-field status-control"
                        >Status<select
                            v-model="status"
                            class="input"
                            :disabled="busy"
                            @change="changeStatus"
                        >
                            <option
                                v-for="option in ticketStatuses"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select></label
                    >
                    <dl class="detail-list">
                        <div>
                            <dt>Sistema</dt>
                            <dd>
                                {{ ticket.system.name
                                }}<small v-if="!ticket.system.is_active"> · Inativo</small>
                            </dd>
                        </div>
                        <div>
                            <dt>Contato</dt>
                            <dd>
                                <button
                                    type="button"
                                    class="text-button registration-link"
                                    aria-haspopup="dialog"
                                    :aria-label="'Visualizar contato ' + ticket.contact.name"
                                    @click="quickView = 'contact'"
                                >
                                    {{ ticket.contact.name }}
                                </button>
                            </dd>
                        </div>
                        <div>
                            <dt>Categoria do problema</dt>
                            <dd>
                                {{ ticket.problem_category?.name || 'Não classificado'
                                }}<small v-if="ticket.problem_category?.is_active === false">
                                    · Inativa</small
                                >
                            </dd>
                        </div>
                        <div>
                            <dt>Subcategorias do problema</dt>
                            <dd>
                                <ul v-if="subcategories.length" class="subcategory-list">
                                    <li v-for="subcategory in subcategories" :key="subcategory.id">
                                        {{ subcategory.name
                                        }}<small v-if="subcategory.is_active === false">
                                            · Inativa</small
                                        >
                                    </li>
                                </ul>
                                <template v-else>
                                    {{
                                        ticket.problem_category
                                            ? 'Não informada'
                                            : 'Não classificado'
                                    }}
                                </template>
                            </dd>
                        </div>
                        <div>
                            <dt>Cliente</dt>
                            <dd>
                                <button
                                    v-if="ticket.customer"
                                    type="button"
                                    class="text-button registration-link"
                                    aria-haspopup="dialog"
                                    :aria-label="'Visualizar cliente ' + ticket.customer.name"
                                    @click="quickView = 'customer'"
                                >
                                    {{ ticket.customer.name }}
                                </button>
                                <span v-else>Não associado</span>
                            </dd>
                        </div>
                        <div>
                            <dt>Última atualização</dt>
                            <dd>{{ formatDate(ticket.updated_at) }}</dd>
                        </div>
                        <div>
                            <dt>Encerramento</dt>
                            <dd>{{ formatDate(ticket.closed_at) }}</dd>
                        </div>
                    </dl>
                </section>
                <section v-if="ticket.vehicle" class="panel detail-panel">
                    <div class="panel-heading">
                        <h2>Identificação veicular</h2>
                        <AppIcon name="car" />
                    </div>
                    <VehicleIdentity :vehicle="ticket.vehicle" />
                    <RouterLink class="section-bottom-link" :to="vehicleHistory">
                        Histórico de atendimentos <AppIcon name="arrow" :size="15" />
                    </RouterLink>
                </section>
                <section class="panel detail-panel">
                    <div class="panel-heading"><h2>Atendimentos relacionados</h2></div>
                    <div v-if="ticket.previous_ticket" class="related-card">
                        <small>ATENDIMENTO ANTERIOR</small
                        ><RouterLink :to="'/atendimentos/' + ticket.previous_ticket.id"
                            >#{{ ticket.previous_ticket.id }} ·
                            {{ ticket.previous_ticket.title }}</RouterLink
                        ><StatusBadge :value="ticket.previous_ticket.status" />
                    </div>
                    <div
                        v-for="related in ticket.follow_up_tickets"
                        :key="related.id"
                        class="related-card"
                    >
                        <small>RETORNO</small
                        ><RouterLink :to="'/atendimentos/' + related.id"
                            >#{{ related.id }} · {{ related.title }}</RouterLink
                        ><StatusBadge :value="related.status" />
                    </div>
                    <p
                        v-if="!ticket.previous_ticket && !ticket.follow_up_tickets.length"
                        class="inline-empty"
                    >
                        Sem vínculos anteriores ou retornos.
                    </p>
                    <RouterLink
                        class="section-bottom-link"
                        :to="{ path: '/atendimentos/novo', query: { anterior: ticket.id } }"
                        >Registrar novo retorno <AppIcon name="arrow" :size="15"
                    /></RouterLink>
                </section>
            </aside>
        </div>
        <RecordDetail
            v-if="quickView"
            :config="resources[quickView === 'contact' ? 'contacts' : 'customers']"
            :record="ticket[quickView]"
            read-only
            @close="quickView = ''" />
        <TicketPdfExportModal v-if="exporting" :ticket="ticket" @close="exporting = false" />
        <CloseTicketModal
            v-if="closing"
            :ticket="ticket"
            @close="closing = false"
            @closed="onClosed"
    /></template>
</template>
<style scoped>
.registration-link {
    padding: 0;
    font: inherit;
    text-align: left;
    overflow-wrap: anywhere;
}
.registration-link:hover {
    text-decoration: underline;
}
</style>
