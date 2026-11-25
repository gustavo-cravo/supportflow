<script setup>
import PaginationBar from '@/components/PaginationBar.vue'
import StatePanel from '@/components/StatePanel.vue'
import { formatDate } from '@/utils/format'

defineProps({
    criteria: { type: Array, required: true },
    tickets: { type: Array, required: true },
    meta: Object,
    loading: Boolean,
    error: String,
    busy: Boolean,
    isSelected: { type: Function, required: true },
})
defineEmits(['all', 'clear', 'ticket', 'page'])
</script>

<template>
    <details class="analytical-filters">
        <summary>
            Filtros aplicados
            <span class="muted">{{ criteria[0]?.value }}</span>
        </summary>
        <dl>
            <div v-for="criterion in criteria" :key="criterion.label">
                <dt>{{ criterion.label }}</dt>
                <dd>{{ criterion.value }}</dd>
            </div>
        </dl>
        <p class="muted">
            Para usar outro recorte, reabra a configuração com os filtros aplicados.
        </p>
    </details>

    <StatePanel
        v-if="loading || error"
        compact
        :loading="loading"
        :error="error"
        @retry="$emit('page')"
    />
    <StatePanel
        v-else-if="!meta?.total"
        compact
        title="Nenhum atendimento elegível"
        description="Os filtros aplicados não retornaram atendimentos para o relatório."
    />
    <template v-else>
        <div class="analytical-ticket-actions">
            <button
                type="button"
                class="btn btn-secondary btn-sm"
                :disabled="busy"
                @click="$emit('all')"
            >
                Selecionar todos
            </button>
            <button
                type="button"
                class="btn btn-secondary btn-sm"
                :disabled="busy"
                @click="$emit('clear')"
            >
                Limpar seleção
            </button>
            <span class="muted">A seleção vale para todas as páginas.</span>
        </div>
        <ul class="analytical-tickets" aria-label="Atendimentos elegíveis">
            <li v-for="ticket in tickets" :key="ticket.id">
                <label>
                    <input
                        type="checkbox"
                        :checked="isSelected(ticket.id)"
                        :disabled="busy"
                        @change="$emit('ticket', ticket.id, $event.target.checked)"
                    />
                    <span class="analytical-ticket-details">
                        <span class="analytical-ticket-heading">
                            <strong>{{ ticket.title }}</strong>
                            <small v-if="ticket.created_at" class="muted">{{
                                formatDate(ticket.created_at)
                            }}</small>
                        </span>
                        <span class="muted">{{
                            [ticket.system, ticket.category, ...ticket.subcategories]
                                .filter(Boolean)
                                .join(' · ')
                        }}</span>
                        <span v-if="ticket.vehicle" class="muted">{{
                            [ticket.vehicle.brand, ticket.vehicle.model].filter(Boolean).join(' · ')
                        }}</span>
                    </span>
                </label>
            </li>
        </ul>
        <PaginationBar :meta="meta" :loading="busy" @change="$emit('page', $event)" />
    </template>
</template>

<style scoped>
.analytical-filters {
    border: 1px solid var(--line);
    border-radius: 9px;
    padding: 11px 13px;
    margin-bottom: 14px;
    font-size: 12px;
}
.analytical-filters summary {
    cursor: pointer;
    font-weight: 650;
}
.analytical-filters summary span {
    margin-left: 7px;
    font-weight: 400;
}
.analytical-filters dl {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr));
    gap: 8px 14px;
    margin: 12px 0 8px;
    overflow-wrap: anywhere;
}
.analytical-filters dt {
    font-weight: 650;
}
.analytical-ticket-actions,
.analytical-ticket-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 5px 10px;
}
.analytical-ticket-actions {
    margin-bottom: 8px;
    font-size: 12px;
}
.analytical-tickets {
    display: grid;
    list-style: none;
    margin: 0 0 12px;
}
.analytical-tickets label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    cursor: pointer;
    padding: 10px 2px;
    border-bottom: 1px solid var(--line);
}
.analytical-tickets input {
    margin-top: 3px;
    flex: 0 0 auto;
}
.analytical-ticket-details {
    display: grid;
    flex: 1;
    min-width: 0;
    gap: 2px;
    font-size: 12px;
    overflow-wrap: anywhere;
}
.analytical-ticket-heading {
    justify-content: space-between;
}
.analytical-ticket-heading strong {
    font-size: 13px;
}
</style>
