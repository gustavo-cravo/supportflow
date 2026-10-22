<script setup>
import { computed } from 'vue'
import { fieldError, formatDocument } from '@/utils/format'
import RelationSelect from './RelationSelect.vue'
const props = defineProps({
    field: Object,
    modelValue: [String, Number, Boolean],
    errors: Object,
    prefix: { type: String, default: 'field' },
})
const emit = defineEmits(['update:modelValue'])
const id = computed(() => props.prefix + '-' + props.field.name)
const error = computed(() => fieldError(props.errors, props.field.name, props.field.label))
function updateInput(event) {
    if (props.field.mask === 'cpf-cnpj') {
        event.target.value = formatDocument(event.target.value)
    }
    emit('update:modelValue', event.target.value)
}
</script>
<template>
    <div class="form-field" :class="{ 'field-full': field.full }">
        <label :for="id"
            >{{ field.label }}
            <span v-if="field.required" class="required-mark" aria-hidden="true">*</span
            ><span v-else-if="field.type !== 'checkbox'" class="optional-label"
                >opcional</span
            ></label
        >
        <RelationSelect
            v-if="field.type === 'relation'"
            :id="id"
            :model-value="modelValue"
            :endpoint="field.endpoint"
            :label-field="field.labelField"
            :params="field.params"
            :exclude-id="field.excludeId"
            :invalid="!!error"
            :required="field.required"
            @update:model-value="$emit('update:modelValue', $event)"
        />
        <textarea
            v-else-if="field.type === 'textarea'"
            :id="id"
            :value="modelValue"
            :required="field.required"
            :maxlength="field.max"
            :placeholder="field.placeholder"
            rows="4"
            class="input"
            :aria-invalid="!!error"
            :aria-describedby="error ? id + '-error' : undefined"
            @input="$emit('update:modelValue', $event.target.value)"
        ></textarea>
        <select
            v-else-if="field.type === 'select'"
            :id="id"
            class="input"
            :value="modelValue ?? ''"
            :required="field.required"
            :aria-invalid="!!error"
            :aria-describedby="error ? id + '-error' : undefined"
            @change="$emit('update:modelValue', $event.target.value)"
        >
            <option v-if="!field.required" value="">Não informado</option>
            <option v-for="option in field.options" :key="option.value" :value="option.value">
                {{ option.label }}
            </option>
        </select>
        <div v-else-if="field.type === 'checkbox'" class="checkbox-row">
            <input
                :id="id"
                type="checkbox"
                :checked="modelValue"
                @change="$emit('update:modelValue', $event.target.checked)"
            /><span>{{
                modelValue
                    ? 'Disponível para novos atendimentos'
                    : 'Preservado apenas para consulta histórica'
            }}</span>
        </div>
        <input
            v-else
            :id="id"
            class="input"
            :type="field.type || 'text'"
            :value="field.mask === 'cpf-cnpj' ? formatDocument(modelValue) : modelValue"
            :inputmode="field.inputmode"
            :required="field.required"
            :maxlength="field.max"
            :step="field.type === 'datetime-local' ? 1 : undefined"
            :placeholder="field.placeholder"
            :aria-invalid="!!error"
            :aria-describedby="error ? id + '-error' : undefined"
            @input="updateInput"
        />
        <p v-if="error" :id="id + '-error'" class="field-error" role="alert">{{ error }}</p>
        <small v-if="field.type === 'datetime-local'" class="field-hint">Horário de Brasília</small>
    </div>
</template>
