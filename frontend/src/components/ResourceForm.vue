<script setup>
import { computed, reactive, ref } from 'vue'
import { api } from '@/services/api'
import { initialValues, formPayload, validateFields } from '@/utils/forms'
import AppModal from './AppModal.vue'
import FormField from './FormField.vue'
const props = defineProps({
    config: Object,
    record: Object,
    defaults: Object,
    requireActiveSystem: Boolean,
    prefix: { type: String, default: 'resource' },
    suspended: Boolean,
})
const emit = defineEmits(['close', 'saved', 'failed'])
const formId = computed(() => props.prefix + '-form')
const fields = computed(() =>
    props.config.fields
        .filter((field) => !field.editOnly || props.record?.id)
        .map((field) =>
            field.requiredOnCreate ? { ...field, required: !props.record?.id } : field,
        ),
)
const values = reactive(initialValues(fields.value, props.record || props.defaults || {}))
const busy = ref(false),
    errors = ref({}),
    error = ref('')
const title = computed(() =>
    props.record
        ? 'Editar ' + props.config.singular
        : props.config.newLabel ||
          (props.config.singular === 'pendência' ? 'Nova ' : 'Novo ') + props.config.singular,
)
async function save() {
    if (busy.value || props.suspended) return
    errors.value = validateFields(fields.value, values)
    if (props.requireActiveSystem && !values.is_active) {
        errors.value.is_active = ['Mantenha o sistema ativo para vinculá-lo ao atendimento.']
    }
    error.value = ''
    if (Object.keys(errors.value).length) {
        error.value = 'Revise os campos destacados.'
        return
    }
    busy.value = true
    try {
        const path = props.config.endpoint + (props.record ? '/' + props.record.id : '')
        const result = await (props.record
            ? api.patch(path, formPayload(fields.value, values))
            : api.post(path, formPayload(fields.value, values)))
        emit('saved', result.data)
    } catch (exception) {
        errors.value = exception.errors || {}
        error.value = exception.message
        emit('failed', exception)
    } finally {
        busy.value = false
    }
}
</script>
<template>
    <AppModal
        :title="title"
        :busy="busy"
        :wide="config.wide !== false"
        :suspended="suspended"
        @close="!busy && $emit('close')"
        ><form :id="formId" novalidate @submit.prevent="save">
            <p v-if="config.showInstructions !== false" class="form-intro">
                Preencha as informações abaixo. Campos com <span class="required-mark">*</span> são
                obrigatórios.
            </p>
            <div v-if="error" class="alert alert-error" role="alert">{{ error }}</div>
            <fieldset :disabled="busy" class="form-grid">
                <slot
                    v-for="field in fields"
                    :key="field.name"
                    :name="'field-' + field.name"
                    :field="field"
                    :values="values"
                    :errors="errors"
                >
                    <FormField
                        v-model="values[field.name]"
                        :field="field"
                        :errors="errors"
                        :prefix="prefix"
                    />
                </slot>
            </fieldset>
        </form>
        <template #footer
            ><button
                type="button"
                class="btn btn-secondary"
                :disabled="busy"
                @click="$emit('close')"
            >
                Cancelar</button
            ><button :form="formId" type="submit" class="btn btn-primary" :disabled="busy">
                <span v-if="busy" class="spinner spinner-small"></span
                >{{ busy ? 'Salvando…' : 'Salvar ' + config.singular }}
            </button></template
        ></AppModal
    >
</template>
