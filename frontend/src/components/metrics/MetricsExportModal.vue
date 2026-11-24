<script setup>
import { onBeforeUnmount, ref } from 'vue'
import AppModal from '@/components/AppModal.vue'
import AppIcon from '@/components/AppIcon.vue'
import { api } from '@/services/api'
import { useNotifications } from '@/composables/useNotifications'
import { saveDownload } from '@/utils/download'
const props = defineProps({
    filters: { type: Object, required: true },
    criteria: { type: Array, required: true },
})
const emit = defineEmits(['close'])
const format = ref('pdf'),
    includeVehicles = ref(false),
    busy = ref(false),
    error = ref(''),
    errors = ref([])
const { notify } = useNotifications()
let controller
async function generate() {
    if (busy.value) return
    const active = (controller = new AbortController())
    busy.value = true
    error.value = ''
    errors.value = []
    try {
        const file = await api.download(
            'metrics/export',
            {
                ...props.filters,
                format: format.value,
                ...(includeVehicles.value ? { include_vehicle_statistics: 1 } : {}),
            },
            active.signal,
        )
        if (active.signal.aborted) return
        saveDownload(file)
        notify('Relatório gerado. O download foi iniciado.')
        emit('close')
    } catch (exception) {
        if (!active.signal.aborted) {
            error.value =
                exception.status === 500
                    ? 'Não foi possível gerar o relatório. Tente novamente.'
                    : exception.message
            errors.value = Object.values(exception.errors || {}).flat()
        }
    } finally {
        if (!active.signal.aborted) busy.value = false
    }
}
function cancel() {
    controller?.abort()
    emit('close')
}
onBeforeUnmount(() => controller?.abort())
</script>
<template>
    <AppModal title="Exportar relatório" @close="cancel">
        <p class="form-intro">
            O relatório utiliza os filtros aplicados à análise e contém somente estatísticas
            consolidadas.
        </p>
        <dl class="detail-grid metrics-export-criteria">
            <div v-for="criterion in criteria" :key="criterion.label">
                <dt>{{ criterion.label }}</dt>
                <dd>{{ criterion.value }}</dd>
            </div>
            <div>
                <dt>Fuso horário</dt>
                <dd>America/Sao_Paulo</dd>
            </div>
        </dl>
        <form id="metrics-export-form" @submit.prevent="generate">
            <div class="form-field">
                <label for="metrics-export-format">Formato do relatório</label
                ><select
                    id="metrics-export-format"
                    v-model="format"
                    class="input"
                    :disabled="busy"
                    required
                >
                    <option value="pdf">PDF · Relatório gerencial</option>
                    <option value="xlsx">Excel (.xlsx) · Tabelas estatísticas</option>
                </select>
            </div>
            <label class="metrics-export-option">
                <input v-model="includeVehicles" type="checkbox" :disabled="busy" />
                Incluir estatísticas veiculares
            </label>
            <p v-if="busy" class="metrics-export-progress muted" role="status">
                Gerando o relatório… Aguarde o início do download.
            </p>
            <div v-if="error" class="alert alert-error" role="alert">
                <p>{{ error }}</p>
                <ul v-if="errors.length">
                    <li v-for="message in errors" :key="message">{{ message }}</li>
                </ul>
            </div>
        </form>
        <template #footer
            ><button class="btn btn-secondary" type="button" @click="cancel">Cancelar</button
            ><button
                class="btn btn-primary"
                type="submit"
                form="metrics-export-form"
                :disabled="busy"
            >
                <span v-if="busy" class="spinner spinner-small"></span
                ><AppIcon v-else name="download" :size="16" />{{
                    busy ? 'Gerando…' : 'Gerar relatório'
                }}
            </button></template
        >
    </AppModal>
</template>
<style scoped>
.metrics-export-criteria {
    margin-bottom: 24px;
}
.metrics-export-criteria dd {
    overflow-wrap: anywhere;
}
.metrics-export-progress {
    font-size: 12px;
    margin-top: 16px;
}
.metrics-export-option {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 16px;
}
</style>
