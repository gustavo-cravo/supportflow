<script setup>
import { computed } from 'vue'
import { metricNumber } from '@/config/ticketMetrics'
import StatePanel from '@/components/StatePanel.vue'
const props = defineProps({ rows: { type: Array, required: true } })
const maximum = computed(() => Math.max(1, ...props.rows.map((row) => row.total)))
</script>
<template>
    <section class="panel" aria-labelledby="metrics-systems-title">
        <div class="panel-heading">
            <div>
                <h2 id="metrics-systems-title">Atendimentos por sistema</h2>
                <p class="metrics-caption">Volume de atendimentos no período filtrado</p>
            </div>
        </div>
        <ol
            v-if="rows.length"
            class="metrics-bars"
            aria-label="Quantidade de atendimentos por sistema"
        >
            <li v-for="row in rows" :key="row.system_id">
                <div class="metrics-bar-label">
                    <span
                        >{{ row.system_name }}
                        <small v-if="!row.is_active" class="muted">Inativo</small></span
                    ><strong>{{ metricNumber(row.total) }}</strong>
                </div>
                <div class="metrics-bar-track" aria-hidden="true">
                    <span :style="{ width: (row.total / maximum) * 100 + '%' }"></span>
                </div>
            </li>
        </ol>
        <StatePanel
            v-else
            compact
            title="Nenhum sistema com atendimentos"
            description="Não há ocorrências para os filtros aplicados."
        />
    </section>
</template>
