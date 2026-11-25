<script setup>
import AppIcon from '@/components/AppIcon.vue'
import FormField from '@/components/FormField.vue'

const props = defineProps({
    catalog: { type: Object, required: true },
    prefix: { type: String, required: true },
})
const draft = defineModel({ type: Object, required: true })
const expanded = defineModel('expanded', { type: Array, required: true })
defineEmits(['restore'])

const names = (section) => Object.keys(props.catalog.sections[section].fields)
const selected = (section) => draft.value.sections[section]?.fields ?? []
const complete = (section) => selected(section).length === names(section).length
const partial = (section) => selected(section).length > 0 && !complete(section)
const emptyOptions = {
    smart: 'Oculta complementos vazios e indica ausências relevantes, como falta de veículo ou de solução registrada.',
    show: 'Inclui os campos selecionados com uma mensagem quando estiverem vazios.',
    hide: 'Exibe somente os campos selecionados que tiverem valor.',
}

function choose(section, fields) {
    draft.value.sections[section] = { enabled: fields.length > 0, fields }
}
function toggleField(section, field, checked) {
    choose(
        section,
        checked ? [...selected(section), field] : selected(section).filter((key) => key !== field),
    )
}
function chooseAll(checked) {
    for (const section of Object.keys(props.catalog.sections))
        choose(section, checked ? names(section) : [])
}
function toggleExpanded(section) {
    expanded.value = expanded.value.includes(section)
        ? expanded.value.filter((key) => key !== section)
        : [...expanded.value, section]
}
</script>

<template>
    <div class="analytical-field-actions">
        <button type="button" class="btn btn-secondary btn-sm" @click="chooseAll(true)">
            Selecionar todos os campos
        </button>
        <button type="button" class="btn btn-secondary btn-sm" @click="chooseAll(false)">
            Limpar seleção de campos
        </button>
        <button type="button" class="text-button" @click="$emit('restore')">
            Restaurar conteúdo padrão
        </button>
    </div>
    <div v-for="(section, id) in catalog.sections" :key="id" class="analytical-field-section">
        <div class="analytical-field-heading">
            <label>
                <input
                    type="checkbox"
                    :checked="complete(id)"
                    :indeterminate.prop="partial(id)"
                    :aria-checked="partial(id) ? 'mixed' : complete(id)"
                    @change="choose(id, $event.target.checked ? names(id) : [])"
                />
                {{ section.label }}
            </label>
            <button
                type="button"
                class="text-button"
                :aria-label="(expanded.includes(id) ? 'Recolher ' : 'Expandir ') + section.label"
                :aria-expanded="expanded.includes(id)"
                :aria-controls="prefix + '-' + id"
                @click="toggleExpanded(id)"
            >
                {{ selected(id).length }}/{{ names(id).length }}
                <span class="sr-only">campos selecionados</span>
                <AppIcon name="chevron" :size="15" />
            </button>
        </div>
        <div
            :id="prefix + '-' + id"
            :hidden="!expanded.includes(id)"
            class="analytical-field-options"
        >
            <template v-if="expanded.includes(id)">
                <label v-for="(field, key) in section.fields" :key="key">
                    <input
                        type="checkbox"
                        :checked="selected(id).includes(key)"
                        @change="toggleField(id, key, $event.target.checked)"
                    />{{ field.label }}
                </label>
            </template>
        </div>
    </div>
    <div class="analytical-empty-fields">
        <FormField
            v-model="draft.empty_fields"
            :prefix="prefix"
            :field="{
                name: 'empty-fields',
                label: 'Campos vazios',
                type: 'select',
                required: true,
                options: Object.entries(catalog.empty_modes).map(([value, label]) => ({
                    value,
                    label,
                })),
            }"
        />
        <p class="muted">{{ emptyOptions[draft.empty_fields] }}</p>
    </div>
</template>

<style scoped>
.analytical-field-actions,
.analytical-field-heading {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px 12px;
}
.analytical-field-actions {
    margin-bottom: 12px;
}
.analytical-field-section {
    border: 1px solid var(--line);
    border-radius: 9px;
    margin-bottom: 8px;
}
.analytical-field-heading {
    justify-content: space-between;
    padding: 10px 12px;
}
.analytical-field-heading label,
.analytical-field-options label {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    cursor: pointer;
}
.analytical-field-heading label {
    font-weight: 650;
}
.analytical-field-options:not([hidden]) {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(230px, 100%), 1fr));
    padding: 10px 12px;
    border-top: 1px solid var(--line);
    gap: 8px 12px;
    font-size: 12px;
}
.analytical-empty-fields {
    margin-top: 16px;
}
.analytical-empty-fields p {
    margin-top: 5px;
    font-size: 12px;
}
</style>
