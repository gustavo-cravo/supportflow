<script setup>
import AppIcon from './AppIcon.vue'
import StatusBadge from './StatusBadge.vue'
import { formatDate } from '@/utils/format'
defineProps({ rows: Array, columns: Array })
defineEmits(['view', 'edit'])
</script>
<template>
    <div class="table-scroll">
        <table>
            <caption class="sr-only">
                Registros encontrados
            </caption>
            <thead>
                <tr>
                    <th v-for="column in columns" :key="column.key" scope="col">
                        {{ column.label }}
                    </th>
                    <th scope="col" class="actions-column">Ações</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in rows" :key="row.id" :class="{ 'row-overdue': row.is_overdue }">
                    <td v-for="column in columns" :key="column.key">
                        <button
                            v-if="column.primary"
                            type="button"
                            class="record-title"
                            @click="$emit('view', row)"
                        >
                            <span class="line-clamp">{{ row[column.key] || 'Sem título' }}</span
                            ><small>#{{ String(row.id).padStart(4, '0') }}</small>
                        </button>
                        <StatusBadge
                            v-else-if="column.type === 'badge'"
                            :value="
                                column.key === 'completion'
                                    ? row.completed_at
                                        ? 'completed'
                                        : 'pending'
                                    : row[column.key]
                            "
                            :overdue="
                                row.is_overdue && row.status !== 'completed' && !row.completed_at
                            "
                        />
                        <span
                            v-else-if="column.type === 'date'"
                            class="date-cell"
                            :class="{ 'text-danger': row.is_overdue }"
                            >{{ formatDate(row[column.key]) }}</span
                        >
                        <RouterLink
                            v-else-if="column.type === 'ticket' && row[column.key]"
                            class="table-link"
                            :to="'/atendimentos/' + row[column.key].id"
                            >#{{ row[column.key].id }} · {{ row[column.key].title }}</RouterLink
                        >
                        <span v-else-if="column.type === 'ticket'" class="muted">Independente</span>
                        <span v-else-if="column.type === 'system'">{{
                            row[column.key]?.name || 'Conhecimento geral'
                        }}</span>
                        <span v-else-if="column.type === 'entity'">{{
                            row[column.key]?.name || '—'
                        }}</span>
                        <span
                            v-else
                            class="line-clamp muted"
                            :title="String(row[column.key] || '')"
                            >{{ row[column.key] ?? '—' }}</span
                        >
                    </td>
                    <td>
                        <div class="row-actions">
                            <slot name="actions" :row="row"
                                ><button
                                    type="button"
                                    class="icon-button"
                                    :aria-label="'Visualizar registro ' + row.id"
                                    @click="$emit('view', row)"
                                >
                                    <AppIcon name="chevron" :size="17" /></button
                                ><button
                                    type="button"
                                    class="icon-button"
                                    :aria-label="'Editar registro ' + row.id"
                                    @click="$emit('edit', row)"
                                >
                                    <AppIcon name="edit" :size="17" /></button
                            ></slot>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
