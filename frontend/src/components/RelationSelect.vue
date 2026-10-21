<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '@/services/api'
import AppIcon from './AppIcon.vue'
const props = defineProps({
    modelValue: [String, Number],
    endpoint: String,
    labelField: { type: String, default: 'name' },
    params: Object,
    excludeId: Number,
    id: String,
    invalid: Boolean,
    required: Boolean,
})
const emit = defineEmits(['update:modelValue'])
const open = ref(false),
    search = ref(''),
    rows = ref([]),
    meta = ref(null),
    loading = ref(false),
    error = ref(''),
    selected = ref(null),
    active = ref(0)
let timer,
    controller,
    version = 0
const searchInput = ref(null)
const label = (record) =>
    record
        ? (props.endpoint === 'support-tickets' ? '#' + record.id + ' · ' : '') +
          record[props.labelField]
        : ''
const visible = computed(() => rows.value.filter((row) => row.id !== props.excludeId))
async function load(page = 1) {
    controller?.abort()
    controller = new AbortController()
    const current = ++version
    loading.value = true
    error.value = ''
    try {
        const result = await api.get(
            props.endpoint,
            { ...props.params, q: search.value, per_page: 15, page },
            controller.signal,
        )
        if (current !== version) return
        rows.value = page === 1 ? result.data : [...rows.value, ...result.data]
        meta.value = result.meta
        active.value = 0
    } catch (exception) {
        if (current === version && !controller.signal.aborted) error.value = exception.message
    } finally {
        if (current === version) loading.value = false
    }
}
async function toggle() {
    open.value = !open.value
    if (open.value) {
        search.value = ''
        await load()
        searchInput.value?.focus()
    }
}
function loadMore() {
    // The button disappears on the last page. Keep focus inside the popover
    // before disabling/removing it, otherwise focusout closes the new options.
    searchInput.value?.focus()
    load(meta.value.current_page + 1)
}
function select(row) {
    selected.value = row
    emit('update:modelValue', row.id)
    open.value = false
    document.getElementById(props.id)?.focus()
}
function clear() {
    selected.value = null
    emit('update:modelValue', null)
    open.value = false
}
function onSearch() {
    clearTimeout(timer)
    timer = setTimeout(() => load(), 250)
}
function onKey(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault()
        active.value = Math.min(active.value + 1, visible.value.length - 1)
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault()
        active.value = Math.max(active.value - 1, 0)
    }
    if (event.key === 'Enter') {
        event.preventDefault()
        if (visible.value[active.value]) select(visible.value[active.value])
    }
    if (event.key === 'Escape') {
        event.stopPropagation()
        open.value = false
        document.getElementById(props.id)?.focus()
    }
}
watch(
    () => props.modelValue,
    async (value) => {
        if (!value) {
            selected.value = null
            return
        }
        if (String(selected.value?.id) === String(value)) return
        try {
            const result = await api.get(props.endpoint + '/' + value)
            if (String(props.modelValue) === String(value)) selected.value = result.data
        } catch {
            if (String(props.modelValue) === String(value))
                selected.value = { id: value, [props.labelField]: 'Registro #' + value }
        }
    },
    { immediate: true },
)
watch(
    () => props.params,
    () => {
        if (open.value) load()
    },
    { deep: true },
)
onBeforeUnmount(() => {
    clearTimeout(timer)
    version++
    controller?.abort()
})
</script>
<template>
    <div
        class="relation-select"
        @focusout="
            (event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) open = false
            }
        "
    >
        <div class="relation-trigger">
            <button
                :id="id"
                type="button"
                class="input relation-value"
                :class="{ invalid }"
                :aria-expanded="open"
                :aria-invalid="invalid"
                :aria-required="required"
                :aria-describedby="invalid ? id + '-error' : undefined"
                aria-haspopup="listbox"
                @click="toggle"
            >
                <span>{{
                    selected ? label(selected) : modelValue ? 'Carregando seleção…' : 'Selecionar…'
                }}</span
                ><AppIcon name="chevron" :size="15" /></button
            ><button
                v-if="modelValue"
                class="icon-button relation-clear"
                type="button"
                aria-label="Limpar seleção"
                @click="clear"
            >
                <AppIcon name="close" :size="14" />
            </button>
        </div>
        <div v-if="open" class="relation-popover">
            <div class="lookup-search">
                <AppIcon name="search" :size="16" /><input
                    ref="searchInput"
                    v-model="search"
                    aria-label="Pesquisar opções"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-expanded="true"
                    :aria-controls="id + '-options'"
                    :aria-activedescendant="
                        visible[active] ? id + '-option-' + visible[active].id : undefined
                    "
                    placeholder="Digite para pesquisar…"
                    @input="onSearch"
                    @keydown="onKey"
                />
            </div>
            <p v-if="error" class="field-error" role="alert">
                {{ error }}
                <button type="button" class="text-button" @click="load()">Tentar novamente</button>
            </p>
            <p v-else-if="loading && !rows.length" class="lookup-hint" role="status">
                Buscando opções…
            </p>
            <ul :id="id + '-options'" role="listbox" aria-label="Opções disponíveis">
                <li
                    v-for="(row, index) in visible"
                    :id="id + '-option-' + row.id"
                    :key="row.id"
                    role="option"
                    :aria-selected="String(modelValue) === String(row.id)"
                    :class="{ active: active === index }"
                >
                    <button type="button" @click="select(row)">
                        {{ label(row) }}<small v-if="row.is_active === false"> · Inativo</small>
                    </button>
                </li>
            </ul>
            <p v-if="!loading && !error && !visible.length" class="lookup-hint">
                Nenhum registro encontrado.
            </p>
            <button
                v-if="meta && meta.current_page < meta.last_page"
                type="button"
                class="lookup-more"
                :disabled="loading"
                @click="loadMore"
            >
                {{ loading ? 'Carregando…' : 'Carregar mais opções' }}
            </button>
        </div>
    </div>
</template>
