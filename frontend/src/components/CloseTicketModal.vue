<script setup>
import { reactive, ref } from 'vue'
import { api } from '@/services/api'
import { resolutionPayload, resolutionValues, validateResolution } from '@/utils/resolution'
import { useNotifications } from '@/composables/useNotifications'
import AppModal from './AppModal.vue'
import AppIcon from './AppIcon.vue'
import ResolutionFields from './ResolutionFields.vue'
defineOptions({ name: 'CloseTicketModal' })
const props = defineProps({ ticket: Object })
const emit = defineEmits(['close', 'closed'])
const values = reactive(resolutionValues(props.ticket))
const conflict = ref(null),
    busy = ref(false),
    error = ref(''),
    errors = ref({})
const { notify } = useNotifications()
async function closeTicket(confirmed = false) {
    if (busy.value) return
    errors.value = validateResolution(values)
    if (Object.keys(errors.value).length) {
        error.value = 'Revise a confirmação da resolução.'
        return
    }
    busy.value = true
    error.value = ''
    try {
        const payload = resolutionPayload(values)
        if (confirmed) payload.confirm_open_tasks = true
        const result = await api.post('support-tickets/' + props.ticket.id + '/close', payload)
        notify('Atendimento encerrado com sucesso.')
        emit('closed', result.data)
    } catch (exception) {
        if (exception.status === 409 && exception.payload.code === 'open_pending_tasks')
            conflict.value = exception.payload
        else {
            errors.value = exception.errors || {}
            error.value = exception.message
        }
    } finally {
        busy.value = false
    }
}
</script>
<template>
    <AppModal
        :title="conflict ? 'Há pendências abertas' : 'Encerrar atendimento'"
        :busy="busy"
        wide
        @close="!busy && $emit('close')"
    >
        <template v-if="conflict"
            ><div class="conflict-banner">
                <AppIcon name="alert" :size="25" />
                <div>
                    <strong
                        >{{ conflict.open_tasks_count }} pendência(s) ainda precisa(m) de
                        atenção</strong
                    >
                    <p>
                        Você pode encerrar este atendimento. As pendências e os atendimentos
                        relacionados manterão suas situações atuais.
                    </p>
                </div>
            </div>
            <ul class="conflict-tasks">
                <li v-for="task in conflict.pending_tasks" :key="task.id">
                    <span>#{{ task.id }}</span
                    >{{ task.description }}
                </li>
            </ul>
            <p class="muted">
                Confirme abaixo somente se deseja continuar com o encerramento.
            </p></template
        >
        <template v-else
            ><p class="form-intro">
                Atendimento #{{ ticket.id }} · {{ ticket.title }}. Registre a solução e, se
                disponível, como ela foi confirmada. Nenhum atendimento relacionado será encerrado.
            </p>
            <fieldset :disabled="busy">
                <ResolutionFields :model="values" :errors="errors" /></fieldset
        ></template>
        <div v-if="error" class="alert alert-error" role="alert">{{ error }}</div>
        <template #footer
            ><button
                type="button"
                class="btn btn-secondary"
                :disabled="busy"
                @click="$emit('close')"
            >
                Cancelar</button
            ><button
                v-if="conflict"
                type="button"
                class="btn btn-warning"
                :disabled="busy"
                @click="closeTicket(true)"
            >
                {{ busy ? 'Encerrando…' : 'Encerrar mesmo assim' }}</button
            ><button
                v-else
                type="button"
                class="btn btn-primary"
                :disabled="busy"
                @click="closeTicket(false)"
            >
                {{ busy ? 'Encerrando…' : 'Encerrar atendimento' }}
            </button></template
        >
    </AppModal>
</template>
