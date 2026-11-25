<script setup>
import { computed } from 'vue'
import FormField from '@/components/FormField.vue'

const props = defineProps({
    catalog: { type: Object, required: true },
    prefix: { type: String, required: true },
    automaticTitle: String,
    titleLoading: Boolean,
    titleRetry: Boolean,
    errors: Object,
    unmet: { type: Function, required: true },
    fieldLabels: { type: Object, required: true },
})
const draft = defineModel({ type: Object, required: true })
const emit = defineEmits(['restore', 'title'])

const automatic = computed(() => draft.value.title.mode === props.catalog.defaults.title.mode)
const paginationNotes = {
    automatic: 'Distribui os atendimentos conforme a extensão das informações.',
    compact: 'Prioriza o aproveitamento do espaço das páginas.',
    individual: 'Cada atendimento começa em uma nova página.',
}
const options = (map) => Object.entries(map).map(([value, label]) => ({ value, label }))

function restoreTitle() {
    draft.value.title = { ...props.catalog.defaults.title }
    if (props.titleRetry) emit('title')
}
</script>

<template>
    <div class="analytical-presentation">
        <fieldset>
            <legend>Identificação do relatório</legend>
            <FormField
                :prefix="prefix"
                :model-value="automatic ? automaticTitle : draft.title.text"
                :errors="errors"
                :field="{
                    name: 'title',
                    label: 'Título',
                    type: 'text',
                    required: true,
                    max: catalog.title.max_length,
                    placeholder: titleLoading ? 'Consultando título…' : 'Título automático',
                }"
                @update:model-value="draft.title = { mode: 'custom', text: $event }"
            >
                <template #label-action>
                    <button
                        v-if="!automatic"
                        class="text-button"
                        type="button"
                        @click="restoreTitle"
                    >
                        Restaurar título automático
                    </button>
                    <span v-else class="muted">Automático</span>
                </template>
            </FormField>
            <div
                v-if="automatic && !automaticTitle && !titleLoading"
                class="analytical-title-status"
            >
                <p v-if="titleRetry" class="muted">Não foi possível obter o título automático.</p>
                <p v-else class="muted">
                    O título automático depende de um recorte com atendimentos.
                </p>
                <button v-if="titleRetry" type="button" class="text-button" @click="$emit('title')">
                    Tentar obter o título
                </button>
            </div>
            <FormField
                :prefix="prefix"
                :model-value="draft.subtitle ?? ''"
                :errors="errors"
                :field="{
                    name: 'subtitle',
                    label: 'Subtítulo',
                    type: 'text',
                    max: catalog.subtitle.max_length,
                }"
                @update:model-value="draft.subtitle = $event"
            />
        </fieldset>

        <fieldset>
            <legend>Organização dos atendimentos</legend>
            <div class="analytical-presentation-grid">
                <div class="form-field">
                    <label :for="prefix + '-grouping'">Agrupamento</label>
                    <select :id="prefix + '-grouping'" v-model="draft.grouping" class="input">
                        <option
                            v-for="(group, id) in catalog.groupings"
                            :key="id"
                            :value="id"
                            :disabled="id !== draft.grouping && unmet(id).length > 0"
                        >
                            {{ group.label
                            }}{{
                                unmet(id).length
                                    ? ' — requer ' +
                                      unmet(id)
                                          .map((field) => fieldLabels[field])
                                          .join(' e ')
                                    : ''
                            }}
                        </option>
                    </select>
                </div>
                <FormField
                    v-model="draft.order"
                    :prefix="prefix"
                    :field="{
                        name: 'order',
                        label: 'Ordenação',
                        type: 'select',
                        required: true,
                        options: options(catalog.orders),
                    }"
                />
            </div>
            <p class="muted">Ao agrupar, a ordenação é aplicada dentro de cada grupo.</p>
        </fieldset>

        <fieldset>
            <legend>Paginação</legend>
            <FormField
                v-model="draft.layout.pagination"
                :prefix="prefix"
                :field="{
                    name: 'pagination',
                    label: 'Distribuição nas páginas',
                    type: 'select',
                    required: true,
                    options: options(catalog.layout.pagination),
                }"
            />
            <p class="muted" aria-live="polite">{{ paginationNotes[draft.layout.pagination] }}</p>
        </fieldset>

        <fieldset>
            <legend>Apresentação dos campos</legend>
            <div class="analytical-presentation-grid">
                <FormField
                    v-model="draft.layout.columns"
                    :prefix="prefix"
                    :field="{
                        name: 'columns',
                        label: 'Colunas dos campos curtos',
                        type: 'select',
                        required: true,
                        options: options(catalog.layout.columns),
                    }"
                />
                <FormField
                    v-model="draft.layout.spacing"
                    :prefix="prefix"
                    :field="{
                        name: 'spacing',
                        label: 'Espaçamento',
                        type: 'select',
                        required: true,
                        options: options(catalog.layout.spacing),
                    }"
                />
            </div>
            <p class="muted">Os campos descritivos extensos ocuparão toda a largura disponível.</p>
        </fieldset>
        <button type="button" class="text-button" @click="$emit('restore')">
            Restaurar apresentação padrão
        </button>
    </div>
</template>

<style scoped>
.analytical-presentation {
    display: grid;
    gap: 14px;
}
.analytical-presentation fieldset {
    min-width: 0;
    border: 1px solid var(--line);
    border-radius: 9px;
    padding: 10px 12px 12px;
}
.analytical-presentation legend {
    padding: 0 5px;
    font-weight: 650;
}
.analytical-presentation fieldset > .form-field + .form-field,
.analytical-title-status + .form-field {
    margin-top: 12px;
}
.analytical-presentation-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(245px, 100%), 1fr));
    gap: 10px 14px;
}
.analytical-presentation p {
    font-size: 12px;
    margin-top: 5px;
}
.analytical-presentation > button {
    justify-self: start;
}
</style>
