<script setup>
import { computed } from 'vue'
import { metricMonth, metricNumber } from '@/config/ticketMetrics'
import StatePanel from '@/components/StatePanel.vue'
const props = defineProps({ rows: { type: Array, required: true }, period: String })
const width = computed(() => Math.max(320, props.rows.length * 90))
const maximum = computed(() => Math.max(1, ...props.rows.map((row) => row.total)))
const points = computed(() =>
    props.rows.map((row, index) => ({
        ...row,
        x:
            props.rows.length === 1
                ? width.value / 2
                : 48 + (index * (width.value - 86)) / (props.rows.length - 1),
        y: 210 - (row.total / maximum.value) * 165,
    })),
)
const line = computed(() => points.value.map((row) => `${row.x},${row.y}`).join(' '))
</script>
<template>
    <section class="panel" aria-labelledby="metrics-monthly-title">
        <div class="panel-heading">
            <div>
                <h2 id="metrics-monthly-title">Evolução dos atendimentos</h2>
                <p class="metrics-caption">Por mês de abertura · Horário de Brasília</p>
            </div>
        </div>
        <template v-if="rows.length">
            <div
                class="metrics-line-scroll"
                tabindex="0"
                role="region"
                aria-label="Gráfico de evolução mensal, com rolagem horizontal"
            >
                <svg
                    class="metrics-line"
                    :viewBox="`0 0 ${width} 270`"
                    :style="{ minWidth: width + 'px' }"
                    role="img"
                    aria-labelledby="metrics-monthly-svg-title metrics-monthly-svg-desc"
                >
                    <title id="metrics-monthly-svg-title">Evolução mensal dos atendimentos</title>
                    <desc id="metrics-monthly-svg-desc">
                        Quantidade de atendimentos por mês. Os valores completos estão na tabela
                        abaixo do gráfico.
                    </desc>
                    <line x1="35" y1="210" :x2="width - 20" y2="210" class="metrics-chart-grid" />
                    <line x1="35" y1="45" :x2="width - 20" y2="45" class="metrics-chart-grid" />
                    <text x="8" y="214" class="metrics-axis-label">0</text>
                    <polyline :points="line" class="metrics-chart-line" />
                    <g v-for="point in points" :key="point.month">
                        <circle :cx="point.x" :cy="point.y" r="4" class="metrics-chart-point">
                            <title>
                                {{ metricMonth(point.month) }}:
                                {{ metricNumber(point.total) }} atendimentos
                            </title>
                        </circle>
                        <text
                            :x="point.x"
                            :y="point.y - 13"
                            text-anchor="middle"
                            class="metrics-point-label"
                        >
                            {{ metricNumber(point.total) }}
                        </text>
                        <text :x="point.x" y="246" text-anchor="middle" class="metrics-axis-label">
                            {{ metricMonth(point.month) }}
                        </text>
                    </g>
                </svg>
            </div>
            <p class="metrics-chart-note muted">
                {{
                    period === 'all'
                        ? 'No histórico completo, são apresentados apenas os meses com registros.'
                        : 'Meses sem atendimentos são apresentados com zero.'
                }}
            </p>
            <details class="metrics-chart-data">
                <summary>Ver dados da evolução mensal</summary>
                <div class="table-scroll">
                    <table>
                        <caption class="sr-only">
                            Quantidade de atendimentos por mês de abertura
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Mês</th>
                                <th scope="col">Atendimentos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rows" :key="row.month">
                                <th scope="row">{{ metricMonth(row.month) }}</th>
                                <td>{{ metricNumber(row.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
        </template>
        <StatePanel
            v-else
            compact
            title="Sem evolução no período"
            description="Não há meses disponíveis para os filtros aplicados."
        />
    </section>
</template>
