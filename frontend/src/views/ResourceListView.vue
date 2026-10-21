<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { resources } from '@/config/resources'
import { useList } from '@/composables/useList'
import { useNotifications } from '@/composables/useNotifications'
import { api } from '@/services/api'
import AppIcon from '@/components/AppIcon.vue'
import AppModal from '@/components/AppModal.vue'
import FilterBar from '@/components/FilterBar.vue'
import PageHeading from '@/components/PageHeading.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RecordDetail from '@/components/RecordDetail.vue'
import RecordTable from '@/components/RecordTable.vue'
import ResourceForm from '@/components/ResourceForm.vue'
import StatePanel from '@/components/StatePanel.vue'
const props = defineProps({ kind: String })
const route = useRoute(),
    router = useRouter(),
    { notify } = useNotifications()
const config = computed(() => resources[props.kind])
const params = computed(() =>
    Object.fromEntries(
        ['page', 'per_page', ...config.value.filters.map((field) => field.name)]
            .filter((key) => route.query[key] !== undefined)
            .map((key) => [key, route.query[key]]),
    ),
)
const { rows, meta, total, loading, error, reload } = useList(() => config.value.endpoint, params)
const formOpen = ref(false),
    editing = ref(null),
    detail = ref(null),
    action = ref(null),
    actionBusy = ref(false),
    actionError = ref(''),
    notes = ref('')
const newLabel = computed(
    () => (config.value.singular === 'pendência' ? 'Nova ' : 'Novo ') + config.value.singular,
)
function openForm(record = null) {
    detail.value = null
    editing.value = record
    formOpen.value = true
}
async function closeForm() {
    formOpen.value = false
    if (route.query.novo) {
        const query = { ...route.query }
        delete query.novo
        await router.replace({ query })
    }
}
async function saved() {
    await closeForm()
    notify('Registro salvo com sucesso.')
    reload()
}
async function applyFilters(query) {
    await router.replace({ query: { ...query, page: 1 } })
}
async function viewRecord(row) {
    try {
        detail.value = (await api.get(config.value.endpoint + '/' + row.id)).data
    } catch (exception) {
        notify(exception.message, 'error')
    }
}
function prepareAction(row, type) {
    action.value = { row, type }
    notes.value = row.completion_notes || ''
    actionError.value = ''
}
async function confirmAction() {
    if (actionBusy.value) return
    actionBusy.value = true
    actionError.value = ''
    try {
        if (action.value.type === 'toggle')
            await api.patch('systems/' + action.value.row.id, {
                is_active: !action.value.row.is_active,
            })
        else
            await api.post(
                config.value.endpoint + '/' + action.value.row.id + '/complete',
                props.kind === 'pending-tasks' ? { completion_notes: notes.value || null } : {},
            )
        action.value = null
        notify('Operação concluída com sucesso.')
        reload()
    } catch (exception) {
        actionError.value = exception.message
    } finally {
        actionBusy.value = false
    }
}
watch(
    () => [props.kind, route.query.novo, route.query.ver],
    () => {
        detail.value = null
        action.value = null
        formOpen.value = false
        if (route.query.novo === '1') openForm()
        else if (/^\d+$/.test(String(route.query.ver || ''))) viewRecord({ id: route.query.ver })
    },
    { immediate: true },
)
</script>
<template>
    <PageHeading
        :title="config.title"
        :description="config.description"
        eyebrow="SEU ESPAÇO DE TRABALHO"
        ><button class="btn btn-primary" type="button" @click="openForm()">
            <AppIcon name="plus" :size="18" />{{ newLabel }}
        </button></PageHeading
    >
    <section class="panel">
        <div class="panel-heading">
            <div class="section-title">
                <AppIcon :name="config.icon" />
                <h2>Todos os registros</h2>
                <span class="count-pill">{{ total }}</span>
            </div>
            <button
                class="icon-button"
                type="button"
                :disabled="loading"
                aria-label="Atualizar listagem"
                @click="reload"
            >
                <AppIcon name="refresh" :size="18" />
            </button>
        </div>
        <FilterBar
            :key="kind"
            :fields="config.filters"
            :values="route.query"
            @apply="applyFilters"
        /><StatePanel
            v-if="loading || error || !rows.length"
            :loading="loading"
            :error="error"
            :title="
                Object.keys(params).some((key) => !['page', 'per_page'].includes(key))
                    ? 'Nenhum registro encontrado'
                    : 'Seu próximo registro começa aqui'
            "
            description="Cadastre um registro ou ajuste os filtros para continuar."
            @retry="reload"
            ><button type="button" class="btn btn-primary" @click="openForm()">
                <AppIcon name="plus" :size="16" />{{ newLabel }}
            </button></StatePanel
        ><RecordTable
            v-else
            :rows="rows"
            :columns="config.columns"
            @view="viewRecord"
            @edit="openForm"
            ><template #actions="{ row }"
                ><button
                    v-if="config.complete && !row.completed_at && row.status !== 'completed'"
                    class="icon-button success-action"
                    type="button"
                    :aria-label="'Concluir registro ' + row.id"
                    @click="prepareAction(row, 'complete')"
                >
                    <AppIcon name="check" :size="18" /></button
                ><button
                    v-if="kind === 'systems'"
                    class="text-button"
                    type="button"
                    @click="prepareAction(row, 'toggle')"
                >
                    {{ row.is_active ? 'Desativar' : 'Ativar' }}</button
                ><button
                    class="icon-button"
                    type="button"
                    :aria-label="'Visualizar registro ' + row.id"
                    @click="viewRecord(row)"
                >
                    <AppIcon name="chevron" :size="18" /></button
                ><button
                    class="icon-button"
                    type="button"
                    :aria-label="'Editar registro ' + row.id"
                    @click="openForm(row)"
                >
                    <AppIcon name="edit" :size="17" /></button></template></RecordTable
        ><PaginationBar
            v-if="!error"
            :meta="meta"
            :loading="loading"
            @change="router.replace({ query: { ...route.query, page: $event } })"
        />
    </section>
    <ResourceForm
        v-if="formOpen"
        :key="kind + '-' + (editing?.id || 'new')"
        :config="config"
        :record="editing"
        :defaults="{ support_ticket_id: Number(route.query.support_ticket_id) || null }"
        @close="closeForm"
        @saved="saved"
    />
    <RecordDetail
        v-if="detail"
        :config="config"
        :record="detail"
        @close="detail = null"
        @edit="openForm"
    />
    <AppModal
        v-if="action"
        :title="
            action.type === 'toggle'
                ? action.row.is_active
                    ? 'Desativar sistema'
                    : 'Ativar sistema'
                : 'Confirmar conclusão'
        "
        :busy="actionBusy"
        @close="!actionBusy && (action = null)"
        ><p>
            {{
                action.type === 'toggle'
                    ? 'O histórico será preservado. Sistemas inativos não ficam disponíveis para novos atendimentos.'
                    : 'Deseja marcar este registro como concluído? A data da conclusão será registrada agora.'
            }}
        </p>
        <p class="confirmation-record">
            {{ action.row.name || action.row.title || action.row.description }}
        </p>
        <label v-if="kind === 'pending-tasks'" class="form-field"
            >Observações da conclusão<textarea
                v-model="notes"
                class="input"
                maxlength="16000"
                rows="3"
                :disabled="actionBusy"
            ></textarea>
        </label>
        <p v-if="actionError" class="alert alert-error" role="alert">{{ actionError }}</p>
        <template #footer
            ><button
                type="button"
                class="btn btn-secondary"
                :disabled="actionBusy"
                @click="action = null"
            >
                Cancelar</button
            ><button
                type="button"
                class="btn btn-primary"
                :disabled="actionBusy"
                @click="confirmAction"
            >
                {{ actionBusy ? 'Salvando…' : 'Confirmar' }}
            </button></template
        ></AppModal
    >
</template>
