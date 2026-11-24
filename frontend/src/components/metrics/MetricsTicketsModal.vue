<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue'
import AppModal from '@/components/AppModal.vue'
import StatePanel from '@/components/StatePanel.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { api } from '@/services/api'
import { formatDate } from '@/utils/format'
const props = defineProps({ filters: { type: Object, required: true }, label: String })
defineEmits(['close'])
const context = ref(null)
const rows = ref([]),
    meta = ref(null),
    loading = ref(true),
    error = ref(''),
    currentPage = ref(1)
let controller
async function load(page = currentPage.value) {
    const restoreFocus = !!document.activeElement?.closest('.metrics-tickets')
    controller?.abort()
    const active = (controller = new AbortController())
    currentPage.value = page
    loading.value = true
    error.value = ''
    rows.value = []
    meta.value = null
    try {
        const result = await api.get(
            'metrics/tickets',
            { ...props.filters, page, per_page: 15 },
            active.signal,
        )
        if (!active.signal.aborted) {
            rows.value = result.data
            meta.value = result.meta
        }
    } catch (exception) {
        if (!active.signal.aborted) error.value = exception.message
    } finally {
        if (!active.signal.aborted) {
            loading.value = false
            if (restoreFocus) {
                await nextTick()
                context.value?.focus()
            }
        }
    }
}
load()
onBeforeUnmount(() => controller?.abort())
</script>
<template>
    <AppModal title="Atendimentos relacionados às métricas" wide @close="$emit('close')">
        <div class="metrics-tickets">
            <p ref="context" class="metrics-modal-context" tabindex="-1">
                {{ label }} · Mesmos filtros da análise
            </p>
            <StatePanel v-if="loading || error" :loading="loading" :error="error" @retry="load()" />
            <template v-else-if="rows.length"
                ><div class="table-scroll">
                    <table>
                        <caption class="sr-only">
                            Atendimentos relacionados ao resultado estatístico selecionado
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Atendimento</th>
                                <th scope="col">Sistema</th>
                                <th scope="col">Status</th>
                                <th scope="col">Abertura</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.id">
                                <td>
                                    <RouterLink class="table-link" :to="'/atendimentos/' + row.id"
                                        >#{{ row.id }} · {{ row.title }}</RouterLink
                                    >
                                    <RouterLink
                                        v-if="row.vehicle"
                                        class="text-button"
                                        :to="{
                                            name: 'vehicle-history',
                                            params: { id: row.vehicle.id },
                                        }"
                                        >Histórico de {{ row.vehicle.plate }}</RouterLink
                                    >
                                </td>
                                <td>{{ row.system?.name }}</td>
                                <td><StatusBadge :value="row.status" /></td>
                                <td class="date-cell">{{ formatDate(row.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <PaginationBar :meta="meta" :loading="loading" @change="load"
            /></template>
            <StatePanel
                v-else
                compact
                title="Nenhum atendimento encontrado"
                description="Não há registros para esta seleção e os filtros aplicados."
            />
        </div>
        <template #footer
            ><button class="btn btn-secondary" type="button" @click="$emit('close')">
                Fechar
            </button></template
        >
    </AppModal>
</template>
