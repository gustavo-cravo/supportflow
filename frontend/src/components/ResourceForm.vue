<script setup>
import { computed, reactive, ref } from 'vue'
import { api } from '@/services/api'
import { initialValues, formPayload, validateFields } from '@/utils/forms'
import AppModal from './AppModal.vue'
import FormField from './FormField.vue'
const props = defineProps({ config: Object, record: Object, defaults: Object })
const emit = defineEmits(['close', 'saved'])
const fields = computed(() =>
    props.config.fields.filter((field) => !field.editOnly || props.record?.id),
)
const values = reactive(initialValues(fields.value, props.record || props.defaults || {}))
const busy = ref(false),
    errors = ref({}),
    error = ref('')
const title = computed(() =>
    props.record
        ? 'Editar ' + props.config.singular
        : (props.config.singular === 'pendência' ? 'Nova ' : 'Novo ') + props.config.singular,
)
async function save() {
    if (busy.value) return
    errors.value = validateFields(fields.value, values)
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
    } finally {
        busy.value = false
    }
}
</script>
<template>
    <AppModal :title="title" :busy="busy" wide @close="!busy && $emit('close')"
        ><form id="resource-form" novalidate @submit.prevent="save">
            <p class="form-intro">
                Preencha as informações abaixo. Campos com <span class="required-mark">*</span> são
                obrigatórios.
            </p>
            <div v-if="error" class="alert alert-error" role="alert">{{ error }}</div>
            <fieldset :disabled="busy" class="form-grid">
                <FormField
                    v-for="field in fields"
                    :key="field.name"
                    v-model="values[field.name]"
                    :field="field"
                    :errors="errors"
                    prefix="resource"
                />
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
            ><button form="resource-form" type="submit" class="btn btn-primary" :disabled="busy">
                <span v-if="busy" class="spinner spinner-small"></span
                >{{ busy ? 'Salvando…' : 'Salvar ' + config.singular }}
            </button></template
        ></AppModal
    >
</template>
