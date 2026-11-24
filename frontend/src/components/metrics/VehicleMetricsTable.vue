<script setup>
import { computed, ref, watch } from 'vue'
import PaginationBar from '@/components/PaginationBar.vue'
import StatePanel from '@/components/StatePanel.vue'
import { metricNumber, metricPercentage } from '@/config/ticketMetrics'
import { vehicleLineLabel } from '@/config/vehicleCatalog'

const props = defineProps({
    title: { type: String, required: true },
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
})
const perPage = 10
const page = ref(1)
const offset = computed(() => (page.value - 1) * perPage)
const meta = computed(() => ({
    current_page: page.value,
    last_page: Math.ceil(props.rows.length / perPage),
    total: props.rows.length,
    from: offset.value + 1,
    to: Math.min(props.rows.length, offset.value + perPage),
}))
watch(
    () => props.rows,
    () => {
        page.value = 1
    },
)

function display(row, key) {
    const value = row[key]
    if (key === 'percentage') return metricPercentage(value)
    if (key === 'vehicle_line') return value === 'mixed' ? 'Leve e pesada' : vehicleLineLabel(value)
    if (key === 'model_description') return value || 'Modelo não informado'
    return typeof value === 'number' ? metricNumber(value) : value
}
</script>

<template>
    <section class="panel vehicle-metrics-table" :aria-label="title">
        <div class="panel-heading">
            <h3>{{ title }}</h3>
        </div>
        <slot name="controls" />
        <template v-if="rows.length">
            <div class="table-scroll">
                <table>
                    <caption class="sr-only">
                        {{
                            title
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th v-for="column in columns" :key="column.key" scope="col">
                                {{ column.label }}
                            </th>
                            <th v-if="$slots.actions" scope="col">Consulta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in rows.slice(offset, meta.to)"
                            :key="offset + index"
                        >
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                :class="{
                                    'vehicle-metrics-model': column.key === 'model_description',
                                }"
                            >
                                {{ display(row, column.key) }}
                            </td>
                            <td v-if="$slots.actions"><slot name="actions" :row="row" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <PaginationBar v-if="meta.last_page > 1" :meta="meta" @change="page = $event" />
        </template>
        <StatePanel
            v-else
            compact
            title="Nenhum resultado"
            description="Nenhum registro nesta análise para os filtros aplicados."
        />
    </section>
</template>
