<script setup>
import { computed, reactive, watch } from 'vue'
import { ticketClassificationFields } from '@/config/resources'
import { ticketSubcategories } from '@/utils/format'
import RelationSelect from './RelationSelect.vue'

const props = defineProps({
    systemId: [String, Number],
    categoryId: [String, Number],
    subcategoryIds: { type: Array, required: true },
    record: Object,
    errors: Object,
})
const emit = defineEmits(['update:categoryId', 'update:subcategoryIds'])
const [categoryField, subcategoryField] = ticketClassificationFields
const categoryInput = 'ticket-' + categoryField.name
const subcategoryInput = 'ticket-' + subcategoryField.name
const categories = reactive(new Map())
const subcategories = reactive(new Map())
const id = (value) => (value ? Number(value) : null)
watch(
    () => props.record,
    (record) => {
        remember(categories, record?.problem_category)
        for (const subcategory of ticketSubcategories(record)) remember(subcategories, subcategory)
    },
    { immediate: true },
)
const category = computed(() => categories.get(id(props.categoryId)))
const historicalIds = computed(
    () =>
        new Set(
            (
                props.record?.problem_subcategory_ids ??
                ticketSubcategories(props.record).map((subcategory) => subcategory.id)
            ).map(id),
        ),
)
const retainingCategory = computed(
    () =>
        !!props.record?.id &&
        id(props.systemId) === id(props.record.system_id) &&
        id(props.categoryId) === id(props.record.problem_category_id),
)
const retainingSubcategory = (value) =>
    retainingCategory.value && historicalIds.value.has(id(value))
const available = (record) =>
    !!record?.is_active &&
    record.systems?.some((system) => id(system.id) === id(props.systemId) && system.is_active)
const selected = computed(() =>
    props.subcategoryIds.map((value) => {
        const record = subcategories.get(id(value))
        return {
            id: value,
            name: record?.name ?? 'Registro #' + value,
            inactive: record?.is_active === false,
            compatible:
                available(record) && id(record?.problem_category_id) === id(props.categoryId),
        }
    }),
)

function validate() {
    const errors = {}
    if (!props.categoryId) {
        if (props.subcategoryIds.length)
            errors.problem_subcategory_ids = [
                'Selecione uma categoria para vincular subcategorias.',
            ]
        return errors
    }
    const assigningSubcategory = selected.value.some((row) => !retainingSubcategory(row.id))
    if ((!retainingCategory.value || assigningSubcategory) && !available(category.value)) {
        errors.problem_category_id = [
            'A categoria está inativa ou indisponível para este sistema. Selecione outra ou remova a classificação.',
        ]
    }
    const incompatible = selected.value.filter(
        (row) => !retainingSubcategory(row.id) && !row.compatible,
    )
    if (incompatible.length) {
        errors.problem_subcategory_ids = incompatible.map(
            (row) => row.name + ' está inativa ou indisponível para esta categoria e sistema.',
        )
    }
    return errors
}

const compatibilityErrors = computed(validate)
const categoryError = computed(
    () =>
        compatibilityErrors.value.problem_category_id?.[0] ||
        props.errors?.problem_category_id?.[0],
)
const subcategoryErrors = computed(
    () =>
        compatibilityErrors.value.problem_subcategory_ids ||
        Object.entries(props.errors || {})
            .filter(
                ([key]) =>
                    key === 'problem_subcategory_id' || key.startsWith('problem_subcategory_ids'),
            )
            .flatMap(([, messages]) => messages),
)
const historical = computed(
    () =>
        !!props.categoryId &&
        retainingCategory.value &&
        (!available(category.value) ||
            selected.value.some((row) => retainingSubcategory(row.id) && !row.compatible)),
)
const scope = computed(() => ({ selectable: 1, system_id: props.systemId }))
const subcategoryScope = computed(() => ({
    ...scope.value,
    problem_category_id: props.categoryId,
}))

function remember(collection, record) {
    if (record) collection.set(id(record.id), record)
}
function updateCategory(value) {
    if (id(value) !== id(props.categoryId)) emit('update:subcategoryIds', [])
    emit('update:categoryId', value)
}
function removeSubcategory(value) {
    emit(
        'update:subcategoryIds',
        props.subcategoryIds.filter((selectedId) => id(selectedId) !== id(value)),
    )
}
defineExpose({ validate })
</script>

<template>
    <div class="form-field">
        <label :for="categoryInput"
            >{{ categoryField.label }} <span class="optional-label">opcional</span></label
        >
        <RelationSelect
            :id="categoryInput"
            :model-value="categoryId"
            :initial-record="category"
            :endpoint="categoryField.endpoint"
            :params="scope"
            :disabled="!systemId"
            :invalid="!!categoryError"
            empty-message="Nenhuma categoria disponível para este sistema."
            clear-label="Remover categoria"
            @select="remember(categories, $event)"
            @update:model-value="updateCategory"
        />
        <p v-if="categoryError" :id="categoryInput + '-error'" class="field-error" role="alert">
            {{ categoryError }}
        </p>
        <small v-if="!systemId" class="field-hint"
            >Selecione um sistema para classificar o problema.</small
        >
    </div>
    <div class="form-field">
        <label :for="subcategoryInput"
            >{{ subcategoryField.label }} <span class="optional-label">opcional</span></label
        >
        <RelationSelect
            :id="subcategoryInput"
            :model-value="subcategoryIds"
            :endpoint="subcategoryField.endpoint"
            :params="subcategoryScope"
            :disabled="!systemId || !categoryId"
            :invalid="!!subcategoryErrors.length"
            multiple
            placeholder="Adicionar subcategoria…"
            empty-message="Nenhuma outra subcategoria disponível para esta categoria e sistema."
            @select="remember(subcategories, $event)"
            @update:model-value="$emit('update:subcategoryIds', $event)"
        />
        <ul v-if="selected.length" class="subcategory-tags" aria-label="Subcategorias selecionadas">
            <li v-for="row in selected" :key="row.id" class="subcategory-tag">
                <span>{{ row.name }}<small v-if="row.inactive"> · Inativa</small></span>
                <button
                    type="button"
                    class="icon-button"
                    :aria-label="'Remover subcategoria ' + row.name"
                    @click="removeSubcategory(row.id)"
                >
                    <span aria-hidden="true">×</span>
                </button>
            </li>
        </ul>
        <button
            v-if="selected.length"
            type="button"
            class="text-button"
            @click="$emit('update:subcategoryIds', [])"
        >
            Limpar subcategorias
        </button>
        <div
            v-if="subcategoryErrors.length"
            :id="subcategoryInput + '-error'"
            class="field-error"
            role="alert"
        >
            <p v-for="message in new Set(subcategoryErrors)" :key="message">{{ message }}</p>
        </div>
        <small v-if="!categoryId || !systemId" class="field-hint"
            >Selecione um sistema e uma categoria para escolher subcategorias.</small
        >
    </div>
    <div
        v-if="compatibilityErrors.problem_category_id"
        class="alert alert-info field-full"
        role="alert"
    >
        A classificação atual foi mantida para revisão. Escolha opções compatíveis ou
        <button type="button" class="text-button" @click="updateCategory(null)">
            remova a classificação
        </button>
        antes de salvar.
    </div>
    <p v-if="historical" class="alert alert-info field-full">
        Classificação histórica preservada. Você pode editar outros campos mantendo estes vínculos,
        mesmo após desativação ou remoção de disponibilidade.
    </p>
</template>
