<script setup>
import { formatDate, displayStatus } from '@/utils/format'
import { useNotifications } from '@/composables/useNotifications'
import AppIcon from './AppIcon.vue'
import AppModal from './AppModal.vue'
import StatusBadge from './StatusBadge.vue'
const props = defineProps({ config: Object, record: Object, readOnly: Boolean })
defineEmits(['close', 'edit'])
const { notify } = useNotifications()
async function copyField(field) {
    try {
        await navigator.clipboard.writeText(props.record[field.name])
        notify(field.label + ' copiado.')
    } catch {
        notify('Não foi possível copiar. Selecione e copie o valor manualmente.', 'error')
    }
}
</script>
<template>
    <AppModal
        :title="(readOnly ? 'Dados do ' + config.singular : 'Detalhes') + ' · #' + record.id"
        wide
        @close="$emit('close')"
        ><dl class="detail-grid" :class="{ 'registration-detail': readOnly }">
            <div
                v-for="field in config.fields"
                :key="field.name"
                :class="{ 'field-full': field.full || field.type === 'textarea' }"
            >
                <dt>{{ field.label }}</dt>
                <dd v-if="field.type === 'checkbox'">
                    <StatusBadge :value="record[field.name]" />
                </dd>
                <dd v-else-if="field.type === 'datetime-local'">
                    {{ formatDate(record[field.name]) }}
                </dd>
                <dd v-else-if="field.type === 'relation'">
                    <RouterLink
                        v-if="field.name === 'support_ticket_id' && record.support_ticket"
                        class="table-link"
                        :to="'/atendimentos/' + record.support_ticket.id"
                        @click="$emit('close')"
                        >#{{ record.support_ticket.id }} ·
                        {{ record.support_ticket.title }}</RouterLink
                    ><span v-else>{{
                        record.system?.name ||
                        (record[field.name] ? '#' + record[field.name] : 'Não associado')
                    }}</span>
                </dd>
                <dd v-else-if="field.type === 'select'">{{ displayStatus(record[field.name]) }}</dd>
                <dd v-else-if="readOnly" class="copy-field">
                    <span class="preserve-text">{{ record[field.name] || 'Não informado' }}</span>
                    <button
                        v-if="record[field.name]"
                        type="button"
                        class="icon-button"
                        :aria-label="'Copiar ' + field.label"
                        :title="'Copiar ' + field.label"
                        @click="copyField(field)"
                    >
                        <AppIcon name="copy" :size="16" />
                    </button>
                </dd>
                <dd v-else class="preserve-text">{{ record[field.name] || 'Não informado' }}</dd>
            </div>
            <div>
                <dt>Criado em</dt>
                <dd>{{ formatDate(record.created_at) }}</dd>
            </div>
            <div>
                <dt>Última atualização</dt>
                <dd>{{ formatDate(record.updated_at) }}</dd>
            </div>
            <div v-if="record.completed_at">
                <dt>Concluído em</dt>
                <dd>{{ formatDate(record.completed_at) }}</dd>
            </div>
        </dl>
        <template #footer
            ><button class="btn btn-secondary" type="button" @click="$emit('close')">Fechar</button
            ><button
                v-if="!readOnly"
                class="btn btn-primary"
                type="button"
                @click="$emit('edit', record)"
            >
                Editar {{ config.singular }}
            </button></template
        ></AppModal
    >
</template>
<style scoped>
.registration-detail > div {
    min-width: 0;
}
.copy-field {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.copy-field > span {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}
</style>
