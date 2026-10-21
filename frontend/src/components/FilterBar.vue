<script setup>
import { reactive, watch } from 'vue'
import RelationSelect from './RelationSelect.vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ fields: Array, values: Object })
const emit = defineEmits(['apply'])
const draft = reactive({})
watch(
    () => props.values,
    (values) => {
        for (const field of props.fields) draft[field.name] = values?.[field.name] ?? ''
    },
    { immediate: true, deep: true },
)
function clear() {
    for (const field of props.fields) draft[field.name] = ''
    emit('apply', {})
}
</script>
<template>
    <form
        class="filter-bar"
        aria-label="Filtros da listagem"
        @submit.prevent="$emit('apply', { ...draft })"
    >
        <div
            v-for="field in fields"
            :key="field.name"
            class="filter-field"
            :class="{ 'filter-search': field.type === 'search' }"
        >
            <label :for="'filter-' + field.name">{{ field.label }}</label>
            <div v-if="field.type === 'search'" class="search-input">
                <AppIcon name="search" :size="17" /><input
                    :id="'filter-' + field.name"
                    v-model="draft[field.name]"
                    type="search"
                    :placeholder="field.placeholder"
                    maxlength="255"
                />
            </div>
            <RelationSelect
                v-else-if="field.type === 'relation'"
                :id="'filter-' + field.name"
                v-model="draft[field.name]"
                :endpoint="field.endpoint"
                :label-field="field.labelField"
            /><select
                v-else-if="field.type === 'select'"
                :id="'filter-' + field.name"
                v-model="draft[field.name]"
                class="input"
            >
                <option value="">Todos</option>
                <option v-for="option in field.options" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option></select
            ><input
                v-else
                :id="'filter-' + field.name"
                v-model="draft[field.name]"
                class="input"
                :type="field.type || 'text'"
            />
        </div>
        <div class="filter-actions">
            <button class="btn btn-secondary" type="submit">
                <AppIcon name="filter" :size="16" />Filtrar</button
            ><button class="text-button" type="button" @click="clear">Limpar</button>
        </div>
    </form>
</template>
