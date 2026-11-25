<script setup>
import { computed } from 'vue'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps({
    sectionId: { type: String, required: true },
    definition: { type: Object, required: true },
    formats: { type: Object, required: true },
    defaultFormat: { type: String, required: true },
    vehicleGrouping: Object,
    advanced: Boolean,
    expanded: Boolean,
})
const selection = defineModel({ type: Object, required: true })
defineEmits(['toggle', 'expand'])

const panel = computed(() => `metrics-pdf-section-${props.sectionId}`)
const grouping = computed(() => selection.value.grouping)
const primary = computed(() => grouping.value?.primary?.by ?? '')
const additional = computed(() => grouping.value?.additional ?? [])
const additionalDimensions = computed(() =>
    Object.entries(props.vehicleGrouping?.dimensions ?? {}).filter(([by]) => by !== primary.value),
)
const indicatorCount = computed(
    () => Object.values(selection.value.indicators).filter(({ enabled }) => enabled).length,
)
const total = computed(
    () => Object.keys(props.definition.indicators).length + Number(!!props.vehicleGrouping),
)
const selected = computed(() => indicatorCount.value + Number(!!primary.value))
const complete = computed(() => selected.value === total.value)
const partial = computed(() => selected.value > 0 && !complete.value)

function changePrimary(by) {
    if (!by && (indicatorCount.value || additional.value.length)) return
    const promoted = additional.value.find((group) => group.by === by)
    grouping.value.primary = by ? (promoted ?? { by, format: props.defaultFormat }) : null
    grouping.value.additional = additional.value.filter((group) => group.by !== by)
}
function chooseIndicator(id, enabled) {
    selection.value.indicators[id].enabled = enabled
    if (enabled && grouping.value && !primary.value)
        changePrimary(props.vehicleGrouping.activation_primary)
}
function chooseAdditional(by, enabled) {
    if (enabled) grouping.value.additional.push({ by, format: props.defaultFormat })
    else grouping.value.additional = additional.value.filter((group) => group.by !== by)
}
</script>

<template>
    <div class="pdf-section">
        <div class="pdf-section-heading">
            <label class="pdf-option pdf-section-title">
                <input
                    :id="panel + '-enabled'"
                    type="checkbox"
                    :checked="complete"
                    :indeterminate.prop="partial"
                    :aria-checked="partial ? 'mixed' : complete"
                    @change="$emit('toggle', $event.target.checked, partial)"
                />
                {{ definition.label }}
            </label>
            <button
                type="button"
                class="text-button pdf-expand"
                :aria-label="`${expanded ? 'Recolher' : 'Expandir'} ${definition.label}`"
                :aria-expanded="expanded"
                :aria-controls="panel"
                @click="$emit('expand')"
            >
                <span
                    >{{ selected }}/{{ total }}
                    <span class="sr-only">opções selecionadas</span></span
                >
                <AppIcon name="chevron" :size="15" />
            </button>
        </div>
        <div :id="panel" :hidden="!expanded" class="pdf-section-body">
            <template v-if="expanded">
                <div v-if="vehicleGrouping" class="pdf-grouping">
                    <div class="pdf-option-row">
                        <div class="form-field">
                            <label for="metrics-pdf-group-primary">Agrupamento principal</label>
                            <select
                                id="metrics-pdf-group-primary"
                                class="input"
                                :value="primary"
                                @change="changePrimary($event.target.value)"
                            >
                                <option
                                    value=""
                                    :disabled="indicatorCount > 0 || additional.length > 0"
                                >
                                    Selecione o agrupamento
                                </option>
                                <option
                                    v-for="(dimension, by) in vehicleGrouping.dimensions"
                                    :key="by"
                                    :value="by"
                                >
                                    {{ dimension.label }}
                                </option>
                            </select>
                        </div>
                        <div v-if="advanced && primary" class="form-field">
                            <label for="metrics-pdf-group-format"
                                >Formato do agrupamento principal</label
                            >
                            <select
                                id="metrics-pdf-group-format"
                                v-model="grouping.primary.format"
                                class="input"
                            >
                                <option
                                    v-for="format in vehicleGrouping.formats"
                                    :key="format"
                                    :value="format"
                                >
                                    {{ formats[format] }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div v-if="primary" class="pdf-grouping">
                        <p class="pdf-options-label">
                            Agrupamentos adicionais <span class="optional-label">opcional</span>
                        </p>
                        <div
                            v-for="[by, dimension] in additionalDimensions"
                            :key="by"
                            class="pdf-option-row"
                        >
                            <label class="pdf-option">
                                <input
                                    :id="`metrics-pdf-group-add-${by}`"
                                    type="checkbox"
                                    :checked="additional.some((group) => group.by === by)"
                                    @change="chooseAdditional(by, $event.target.checked)"
                                />
                                {{ dimension.label }}
                            </label>
                            <select
                                v-if="advanced && additional.some((group) => group.by === by)"
                                v-model="additional.find((group) => group.by === by).format"
                                class="input"
                                :aria-label="`Formato do agrupamento adicional por ${dimension.label}`"
                            >
                                <option
                                    v-for="format in vehicleGrouping.formats"
                                    :key="format"
                                    :value="format"
                                >
                                    {{ formats[format] }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <p class="pdf-options-label">Informações complementares</p>
                </div>
                <div v-for="(option, id) in definition.indicators" :key="id" class="pdf-option-row">
                    <label class="pdf-option">
                        <input
                            :id="`${panel}-${id}`"
                            type="checkbox"
                            :checked="selection.indicators[id].enabled"
                            @change="chooseIndicator(id, $event.target.checked)"
                        />
                        {{ option.label }}
                    </label>
                    <select
                        v-if="advanced && selection.indicators[id].enabled && option.formats.length"
                        v-model="selection.indicators[id].format"
                        class="input"
                        :aria-label="`Formato de ${option.label}`"
                    >
                        <option v-for="format in option.formats" :key="format" :value="format">
                            {{ formats[format] }}
                        </option>
                    </select>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.pdf-section {
    border: 1px solid var(--line);
    border-radius: 7px;
}
.pdf-section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 4px 10px 4px 12px;
}
.pdf-section-title {
    font-weight: 600;
}
.pdf-expand {
    display: inline-flex;
    min-height: 36px;
    flex: none;
    align-items: center;
    gap: 7px;
    padding-inline: 4px;
}
.pdf-expand[aria-expanded='true'] svg {
    transform: rotate(90deg);
}
.pdf-section-body {
    border-top: 1px solid var(--line);
    padding: 8px 12px;
}
.pdf-option-row {
    display: grid;
    align-items: center;
    gap: 8px 12px;
    grid-template-columns: minmax(0, 1fr) minmax(0, 0.8fr);
    padding-block: 3px;
}
.pdf-option {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
    line-height: 1.4;
}
.pdf-option input {
    flex: none;
    width: 16px;
    height: 16px;
}
.pdf-grouping {
    margin-block: 4px 8px;
}
.pdf-options-label {
    font-size: 12px;
    font-weight: 600;
    margin-block: 8px 2px;
}
@media (max-width: 520px) {
    .pdf-option-row {
        grid-template-columns: minmax(0, 1fr);
        gap: 3px;
    }
}
</style>
