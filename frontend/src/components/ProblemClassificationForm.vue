<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { api } from '@/services/api'
import { problemFields } from '@/config/problemClassifications'
import { initialValues, validateFields } from '@/utils/forms'
import AppModal from './AppModal.vue'
import FormField from './FormField.vue'
import StatePanel from './StatePanel.vue'

const props = defineProps({ record: Object, category: Object })
const emit = defineEmits(['close', 'saved'])
const singular = computed(() => (props.category ? 'subcategoria' : 'categoria'))
const initialIds = [...(props.record?.system_ids || [])]
const values = reactive({
    ...initialValues(problemFields, props.record || {}),
    is_active: props.record?.is_active ?? true,
    system_ids: [...initialIds],
})
const systems = ref([]),
    systemsLoading = ref(false),
    systemsError = ref('')
const busy = ref(false),
    errors = ref({}),
    error = ref('')
let controller,
    version = 0
const systemErrors = computed(() =>
    Object.entries(errors.value)
        .filter(([key]) => key === 'system_ids' || key.startsWith('system_ids.'))
        .flatMap(([, messages]) => messages),
)
const categoryErrors = computed(() => errors.value.problem_category_id || [])
const options = computed(() =>
    systems.value.filter((system) => system.is_active || initialIds.includes(system.id)),
)
const sameSystems = () =>
    [...initialIds].sort().join(',') === [...values.system_ids].sort().join(',')

async function loadSystems() {
    if (props.category) {
        systems.value = props.category.systems
        return
    }
    controller?.abort()
    controller = new AbortController()
    const current = ++version
    systemsLoading.value = true
    systemsError.value = ''
    try {
        const rows = []
        let page = 1,
            lastPage = 1
        do {
            const response = await api.get('systems', { per_page: 100, page }, controller.signal)
            if (current !== version) return
            rows.push(...response.data)
            lastPage = response.meta?.last_page || 1
            page++
        } while (page <= lastPage)
        systems.value = rows
    } catch (exception) {
        if (current === version && !controller.signal.aborted)
            systemsError.value = exception.message
    } finally {
        if (current === version) systemsLoading.value = false
    }
}
async function save() {
    if (busy.value || systemsLoading.value || systemsError.value) return
    errors.value = validateFields(problemFields, values)
    if (!values.system_ids.length && (!props.record || !sameSystems()))
        errors.value.system_ids = ['Selecione pelo menos um sistema.']
    error.value = ''
    if (Object.keys(errors.value).length) {
        error.value = 'Revise os campos destacados.'
        return
    }
    busy.value = true
    try {
        const data = {
            name: values.name,
            description: values.description || null,
            is_active: values.is_active,
        }
        if (!props.record || !sameSystems()) data.system_ids = [...values.system_ids]
        if (props.category && !props.record) data.problem_category_id = props.category.id
        const endpoint = props.category ? 'problem-subcategories' : 'problem-categories'
        const result = await (props.record
            ? api.patch(endpoint + '/' + props.record.id, data)
            : api.post(endpoint, data))
        emit('saved', result.data)
    } catch (exception) {
        errors.value = exception.errors || {}
        error.value = exception.message
    } finally {
        busy.value = false
    }
}
loadSystems()
onBeforeUnmount(() => {
    version++
    controller?.abort()
})
</script>
<template>
    <AppModal
        :title="(record ? 'Editar ' : 'Nova ') + singular"
        :busy="busy"
        wide
        @close="!busy && $emit('close')"
    >
        <form id="problem-classification-form" novalidate @submit.prevent="save">
            <p v-if="category" class="form-intro">
                Categoria: <strong>{{ category.name }}</strong>
            </p>
            <div v-if="error" class="alert alert-error" role="alert">{{ error }}</div>
            <p v-for="message in categoryErrors" :key="message" class="field-error" role="alert">
                {{ message }}
            </p>
            <fieldset class="form-grid" :disabled="busy">
                <FormField
                    v-for="field in problemFields"
                    :key="field.name"
                    v-model="values[field.name]"
                    :field="field"
                    :errors="errors"
                    prefix="problem"
                />
                <div class="form-field field-full">
                    <label class="checkbox-row" for="problem-active"
                        ><input id="problem-active" v-model="values.is_active" type="checkbox" />{{
                            singular === 'categoria' ? 'Categoria ativa' : 'Subcategoria ativa'
                        }}</label
                    >
                    <p v-if="errors.is_active" class="field-error" role="alert">
                        {{ errors.is_active[0] }}
                    </p>
                </div>
                <fieldset
                    class="form-field field-full system-options"
                    :aria-describedby="
                        systemErrors.length ? 'problem-systems-error' : 'problem-systems-hint'
                    "
                >
                    <legend>Sistemas associados <span class="required-mark">*</span></legend>
                    <p id="problem-systems-hint" class="field-hint">
                        {{
                            category
                                ? 'Selecione a disponibilidade nos sistemas da categoria principal.'
                                : 'Uma categoria pode ser compartilhada por vários sistemas.'
                        }}
                    </p>
                    <StatePanel
                        v-if="systemsLoading || systemsError"
                        :loading="systemsLoading"
                        :error="systemsError"
                        compact
                        @retry="loadSystems"
                    />
                    <div v-else class="system-options-list">
                        <label v-for="system in options" :key="system.id" class="checkbox-row">
                            <input
                                v-model="values.system_ids"
                                type="checkbox"
                                :value="system.id"
                                :aria-invalid="!!systemErrors.length"
                            />
                            <span
                                >{{ system.name
                                }}<small v-if="!system.is_active">
                                    · Inativo (vínculo preservado)</small
                                ></span
                            >
                        </label>
                        <p v-if="!options.length" class="inline-empty">
                            Nenhum sistema disponível.
                            {{
                                category
                                    ? 'Associe sistemas ativos à categoria principal.'
                                    : 'Cadastre ou ative um sistema para continuar.'
                            }}
                        </p>
                    </div>
                    <div v-if="systemErrors.length" id="problem-systems-error" role="alert">
                        <p
                            v-for="message in [...new Set(systemErrors)]"
                            :key="message"
                            class="field-error"
                        >
                            {{ message }}
                        </p>
                    </div>
                </fieldset>
                <p v-if="record && !category" class="field-hint field-full">
                    Ao remover um sistema, a disponibilidade das subcategorias nesse sistema será
                    desabilitada. O histórico será preservado.
                </p>
            </fieldset>
        </form>
        <template #footer>
            <button
                type="button"
                class="btn btn-secondary"
                :disabled="busy"
                @click="$emit('close')"
            >
                Cancelar
            </button>
            <button
                type="submit"
                form="problem-classification-form"
                class="btn btn-primary"
                :disabled="busy || systemsLoading || !!systemsError"
            >
                <span v-if="busy" class="spinner spinner-small"></span
                >{{ busy ? 'Salvando…' : 'Salvar ' + singular }}
            </button>
        </template>
    </AppModal>
</template>
<style scoped>
.system-options {
    border: 0;
    padding: 0;
    min-width: 0;
}
.system-options legend {
    margin-bottom: 8px;
}
.system-options-list {
    display: grid;
    gap: 12px;
    margin-top: 14px;
    max-height: 240px;
    overflow-y: auto;
}
.system-options-list .checkbox-row {
    align-items: flex-start;
    overflow-wrap: anywhere;
}
</style>
