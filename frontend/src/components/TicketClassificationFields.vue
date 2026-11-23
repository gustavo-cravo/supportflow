<script setup>
import { computed, reactive, watch } from 'vue'
import { ticketClassificationFields } from '@/config/resources'
import RelationSelect from './RelationSelect.vue'

const props = defineProps({
    systemId: [String, Number],
    categoryId: [String, Number],
    subcategoryId: [String, Number],
    record: Object,
    errors: Object,
})
const emit = defineEmits(['update:categoryId', 'update:subcategoryId'])
const categories = reactive(new Map()),
    subcategories = reactive(new Map())
const id = (value) => (value ? Number(value) : null)
watch(
    () => props.record,
    (record) => {
        if (record?.problem_category)
            categories.set(id(record.problem_category.id), record.problem_category)
        if (record?.problem_subcategory)
            subcategories.set(id(record.problem_subcategory.id), record.problem_subcategory)
    },
    { immediate: true },
)
const category = computed(() => categories.get(id(props.categoryId)))
const subcategory = computed(() => subcategories.get(id(props.subcategoryId)))
const retainingCategory = computed(
    () =>
        !!props.record?.id &&
        id(props.systemId) === id(props.record.system_id) &&
        id(props.categoryId) === id(props.record.problem_category_id),
)
const retainingSubcategory = computed(
    () =>
        retainingCategory.value &&
        id(props.subcategoryId) === id(props.record.problem_subcategory_id),
)
const available = (record) =>
    !!record?.is_active &&
    record.systems?.some((system) => id(system.id) === id(props.systemId) && system.is_active)
function validate() {
    const errors = {}
    if (!props.categoryId) {
        if (props.subcategoryId)
            errors.problem_subcategory_id = [
                'Selecione uma categoria ou remova a subcategoria explicitamente.',
            ]
        return errors
    }
    const assigningSubcategory = !!props.subcategoryId && !retainingSubcategory.value
    if ((!retainingCategory.value || assigningSubcategory) && !available(category.value)) {
        errors.problem_category_id = [
            'A categoria está inativa ou indisponível para este sistema. Selecione outra ou remova a classificação.',
        ]
    }
    if (
        props.subcategoryId &&
        !retainingSubcategory.value &&
        (!available(subcategory.value) ||
            id(subcategory.value?.problem_category_id) !== id(props.categoryId))
    ) {
        errors.problem_subcategory_id = [
            'Selecione uma subcategoria ativa e compatível com a categoria e o sistema ou remova-a explicitamente.',
        ]
    }
    return errors
}
const compatibilityErrors = computed(validate)
const historical = computed(
    () =>
        !!props.categoryId &&
        retainingCategory.value &&
        (!available(category.value) ||
            (props.subcategoryId && retainingSubcategory.value && !available(subcategory.value))),
)
const fields = computed(() =>
    ticketClassificationFields.map((field, index) => ({
        ...field,
        value: index ? props.subcategoryId : props.categoryId,
        record: index ? subcategory.value : category.value,
        disabled: !props.systemId || (index === 1 && !props.categoryId),
        params: {
            selectable: 1,
            system_id: props.systemId,
            ...(index ? { problem_category_id: props.categoryId } : {}),
        },
        error: compatibilityErrors.value[field.name]?.[0] || props.errors?.[field.name]?.[0],
        emptyMessage: index
            ? 'Nenhuma subcategoria disponível para esta categoria e sistema.'
            : 'Nenhuma categoria disponível para este sistema.',
    })),
)
function selectRecord(field, record) {
    if (record)
        (field.name === 'problem_category_id' ? categories : subcategories).set(
            id(record.id),
            record,
        )
}
function update(field, value) {
    emit(field.name === 'problem_category_id' ? 'update:categoryId' : 'update:subcategoryId', value)
}
function clearClassification() {
    emit('update:categoryId', null)
    emit('update:subcategoryId', null)
}
defineExpose({ validate })
</script>
<template>
    <div v-for="field in fields" :key="field.name" class="form-field">
        <label :for="'ticket-' + field.name"
            >{{ field.label }} <span class="optional-label">opcional</span></label
        >
        <RelationSelect
            :id="'ticket-' + field.name"
            :model-value="field.value"
            :initial-record="field.record"
            :endpoint="field.endpoint"
            :params="field.params"
            :disabled="field.disabled"
            :invalid="!!field.error"
            :empty-message="field.emptyMessage"
            :clear-label="
                field.name === 'problem_category_id' ? 'Remover categoria' : 'Remover subcategoria'
            "
            @select="selectRecord(field, $event)"
            @update:model-value="update(field, $event)"
        />
        <p
            v-if="field.error"
            :id="'ticket-' + field.name + '-error'"
            class="field-error"
            role="alert"
        >
            {{ field.error }}
        </p>
        <small v-if="field.disabled" class="field-hint">{{
            !systemId
                ? 'Selecione um sistema para classificar o problema.'
                : 'Selecione uma categoria para escolher a subcategoria.'
        }}</small>
        <button
            v-if="
                field.name === 'problem_subcategory_id' &&
                compatibilityErrors.problem_subcategory_id
            "
            type="button"
            class="text-button"
            @click="$emit('update:subcategoryId', null)"
        >
            Remover subcategoria
        </button>
    </div>
    <div
        v-if="compatibilityErrors.problem_category_id"
        class="alert alert-info field-full"
        role="alert"
    >
        A classificação atual foi mantida para revisão. Escolha opções compatíveis ou
        <button type="button" class="text-button" @click="clearClassification">
            remova a classificação
        </button>
        antes de salvar.
    </div>
    <p v-if="historical" class="alert alert-info field-full">
        Classificação histórica preservada. Você pode editar outros campos mantendo estes vínculos,
        mesmo após desativação ou remoção de disponibilidade.
    </p>
</template>
