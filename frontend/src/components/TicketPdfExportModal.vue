<script setup>
import { onBeforeUnmount, ref, useId } from 'vue'
import AppIcon from '@/components/AppIcon.vue'
import AppModal from '@/components/AppModal.vue'
import { useNotifications } from '@/composables/useNotifications'
import { api } from '@/services/api'
import { saveDownload } from '@/utils/download'

const props = defineProps({ ticket: { type: Object, required: true } })
const emit = defineEmits(['close'])
const id = useId()
const { notify } = useNotifications()
const hasVehicle = !!props.ticket.vehicle
const options = [
    { key: 'general', label: 'Informações gerais do atendimento', required: true },
    { key: 'include_classification', label: 'Classificação do problema' },
    {
        key: 'include_vehicle',
        label: 'Identificação veicular',
        unavailable: !hasVehicle,
        hint: hasVehicle ? '' : 'Nenhum veículo vinculado.',
    },
    { key: 'description', label: 'Descrição do problema', required: true },
    { key: 'include_resolution', label: 'Resolução e confirmação' },
    {
        key: 'include_pending_tasks',
        label: 'Pendências associadas',
        hint: props.ticket.pending_tasks?.length ? '' : 'Nenhuma pendência associada.',
    },
    {
        key: 'include_reminders',
        label: 'Lembretes associados',
        hint: props.ticket.reminders?.length ? '' : 'Nenhum lembrete associado.',
    },
    {
        key: 'include_related_tickets',
        label: 'Atendimentos relacionados',
        hint:
            props.ticket.previous_ticket || props.ticket.follow_up_tickets?.length
                ? ''
                : 'Sem vínculos anteriores ou retornos.',
    },
]
const selection = ref({})
const busy = ref(false)
const error = ref('')
const fieldErrors = ref([])
let controller

function selectAll() {
    selection.value = Object.fromEntries(options.map(({ key, unavailable }) => [key, !unavailable]))
}

async function exportPdf() {
    if (busy.value) return
    const active = (controller = new AbortController())
    busy.value = true
    error.value = ''
    fieldErrors.value = []
    const data = Object.fromEntries(
        options
            .filter(({ required }) => !required)
            .map(({ key, unavailable }) => [key, !unavailable && selection.value[key]]),
    )

    try {
        const file = await api.downloadTicketPdf(props.ticket.id, data, active.signal)
        if (active.signal.aborted) return
        saveDownload(file)
        notify('PDF gerado. O download foi iniciado.')
        emit('close')
    } catch (exception) {
        if (!active.signal.aborted) {
            error.value =
                exception.status === 500
                    ? 'Não foi possível gerar o PDF do atendimento. Tente novamente.'
                    : exception.message
            fieldErrors.value = Object.values(exception.errors || {}).flat()
        }
    } finally {
        if (!active.signal.aborted) busy.value = false
    }
}

function cancel() {
    controller?.abort()
    emit('close')
}

selectAll()
onBeforeUnmount(() => controller?.abort())
</script>

<template>
    <AppModal title="Exportar atendimento" :busy="busy" @close="cancel">
        <p class="form-intro">Selecione as informações que deseja incluir no relatório PDF.</p>
        <form :id="id" :aria-busy="busy" @submit.prevent="exportPdf">
            <div class="export-selection">
                <button class="text-button" type="button" :disabled="busy" @click="selectAll">
                    Selecionar tudo
                </button>
                <label v-for="option in options" :key="option.key" class="export-option">
                    <input
                        v-model="selection[option.key]"
                        type="checkbox"
                        :name="option.key"
                        :disabled="busy || option.required || option.unavailable"
                    />
                    <span>
                        {{ option.label }}
                        <small v-if="option.required || option.hint" class="muted">
                            {{ option.required ? 'Obrigatória' : option.hint }}
                        </small>
                    </span>
                </label>
            </div>
            <p v-if="busy" class="export-feedback muted" role="status">Gerando o PDF…</p>
            <div v-if="error" class="alert alert-error export-feedback" role="alert">
                <p>{{ error }}</p>
                <ul v-if="fieldErrors.length">
                    <li v-for="message in fieldErrors" :key="message">{{ message }}</li>
                </ul>
            </div>
        </form>
        <template #footer>
            <button class="btn btn-secondary" type="button" @click="cancel">Cancelar</button>
            <button class="btn btn-primary" type="submit" :form="id" :disabled="busy">
                <span v-if="busy" class="spinner spinner-small"></span>
                <AppIcon v-else name="download" :size="16" />
                {{ busy ? 'Gerando…' : 'Exportar PDF' }}
            </button>
        </template>
    </AppModal>
</template>

<style scoped>
.export-selection {
    display: grid;
    gap: 11px;
}
.export-selection > button {
    justify-self: start;
}
.export-option {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    font-size: 13px;
}
.export-option input {
    margin-top: 2px;
}
.export-option small {
    display: block;
    margin-top: 2px;
}
.export-feedback {
    margin-top: 16px;
}
</style>
