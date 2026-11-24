<script setup>
import RelationSelect from './RelationSelect.vue'
import AppIcon from './AppIcon.vue'

defineProps({ fields: { type: Array, required: true } })
const draft = defineModel({ type: Object, required: true })
</script>
<template>
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
                :disabled="field.disabledWhen?.(draft)"
                maxlength="255"
            />
        </div>
        <RelationSelect
            v-else-if="field.type === 'relation'"
            :id="'filter-' + field.name"
            v-model="draft[field.name]"
            :endpoint="field.endpoint"
            :label-field="field.labelField"
            :placeholder="field.placeholder"
            :disabled="field.disabledWhen?.(draft)"
        /><select
            v-else-if="field.type === 'select'"
            :id="'filter-' + field.name"
            v-model="draft[field.name]"
            class="input"
            :disabled="field.disabledWhen?.(draft)"
        >
            <option value="">{{ field.emptyLabel || 'Todos' }}</option>
            <option v-for="option in field.options" :key="option.value" :value="option.value">
                {{ option.label }}
            </option></select
        ><input
            v-else
            :id="'filter-' + field.name"
            v-model="draft[field.name]"
            class="input"
            :type="field.type || 'text'"
            :placeholder="field.placeholder"
            :disabled="field.disabledWhen?.(draft)"
            :maxlength="field.type === 'text' ? 255 : undefined"
        />
    </div>
</template>
