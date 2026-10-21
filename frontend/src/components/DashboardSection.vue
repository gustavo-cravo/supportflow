<script setup>
import AppIcon from './AppIcon.vue'
import { formatDate } from '@/utils/format'
defineProps({
    title: String,
    icon: String,
    rows: Array,
    link: [String, Object],
    kind: String,
    empty: String,
})
</script>
<template>
    <section class="panel dashboard-section">
        <div class="panel-heading">
            <div class="section-title">
                <AppIcon :name="icon" :size="19" />
                <h2>{{ title }}</h2>
            </div>
            <RouterLink :to="link" class="text-button"
                >Ver todos <AppIcon name="arrow" :size="14"
            /></RouterLink>
        </div>
        <div v-if="rows.length" class="activity-list">
            <RouterLink
                v-for="row in rows"
                :key="row.id"
                class="activity-row"
                :to="
                    kind === 'ticket'
                        ? '/atendimentos/' + row.id
                        : kind === 'task'
                          ? {
                                path: '/pendencias',
                                query: { ver: row.id },
                            }
                          : { path: '/lembretes', query: { ver: row.id } }
                "
                ><span class="activity-symbol" :class="{ danger: row.is_overdue }"
                    ><AppIcon :name="icon" :size="17"
                /></span>
                <div>
                    <p class="line-clamp">{{ row.title || row.description }}</p>
                    <small>{{
                        kind === 'ticket'
                            ? row.system?.name + ' · ' + row.contact?.name
                            : formatDate(row.due_at || row.scheduled_at)
                    }}</small>
                </div>
                <AppIcon name="chevron" :size="16"
            /></RouterLink>
        </div>
        <div v-else class="dashboard-empty">
            <span class="empty-check"><AppIcon name="check" :size="21" /></span
            ><strong>{{ empty }}</strong
            ><span>Quando houver registros, você os verá aqui.</span>
        </div>
    </section>
</template>
