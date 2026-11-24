<script setup>
import { ref, watch } from 'vue'
import FilterFields from './FilterFields.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({
    fields: Array,
    values: Object,
    label: { type: String, default: 'Filtros da listagem' },
    clearLabel: { type: String, default: 'Limpar' },
})
const emit = defineEmits(['apply'])
const draft = ref({})
watch(
    () => props.values,
    (values) => {
        for (const field of props.fields) draft.value[field.name] = values?.[field.name] ?? ''
    },
    { immediate: true, deep: true },
)
function clear() {
    for (const field of props.fields) draft.value[field.name] = ''
    emit('apply', {})
}
function apply() {
    emit(
        'apply',
        Object.fromEntries(
            props.fields
                .filter((field) => !field.disabledWhen?.(draft.value))
                .map(({ name }) => [name, draft.value[name]]),
        ),
    )
}
</script>
<template>
    <form class="filter-bar" :aria-label="label" @submit.prevent="apply">
        <FilterFields v-model="draft" :fields="fields" />
        <div class="filter-actions">
            <button class="btn btn-secondary" type="submit">
                <AppIcon name="filter" :size="16" />Filtrar</button
            ><button class="text-button" type="button" @click="clear">{{ clearLabel }}</button>
        </div>
    </form>
</template>
