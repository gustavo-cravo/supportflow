<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/services/api'
import { useList } from '@/composables/useList'
import { useNotifications } from '@/composables/useNotifications'
import { problemFilters, systemNames } from '@/config/problemClassifications'
import { formatDate } from '@/utils/format'
import AppIcon from './AppIcon.vue'
import AppModal from './AppModal.vue'
import FilterBar from './FilterBar.vue'
import PaginationBar from './PaginationBar.vue'
import ProblemClassificationForm from './ProblemClassificationForm.vue'
import RecordTable from './RecordTable.vue'
import StatePanel from './StatePanel.vue'
import StatusBadge from './StatusBadge.vue'

const props = defineProps({ category: Object })
const route = useRoute(),
    router = useRouter(),
    { notify } = useNotifications()
const endpoint = computed(() => (props.category ? 'problem-subcategories' : 'problem-categories'))
const singular = computed(() => (props.category ? 'subcategoria' : 'categoria'))
const params = computed(() => ({
    ...Object.fromEntries(
        ['q', 'is_active', 'system_id', 'page']
            .filter((key) => route.query[key] !== undefined)
            .map((key) => [key, route.query[key]]),
    ),
    ...(props.category ? { problem_category_id: props.category.id } : {}),
}))
const { rows, meta, total, loading, error, reload } = useList(endpoint, params)
const tableRows = computed(() =>
    rows.value.map((row) => ({ ...row, system_names: systemNames(row.systems) })),
)
const columns = computed(() => [
    { key: 'name', label: props.category ? 'Subcategoria' : 'Categoria', primary: true },
    { key: 'system_names', label: 'Sistemas associados' },
    { key: 'is_active', label: 'Situação', type: 'badge' },
    ...(!props.category ? [{ key: 'subcategories_count', label: 'Subcategorias' }] : []),
])
const formOpen = ref(false),
    editing = ref(null),
    detail = ref(null),
    action = ref(null),
    actionBusy = ref(false),
    actionError = ref('')
function edit(record = null) {
    editing.value = record
    detail.value = null
    formOpen.value = true
}
function view(record) {
    if (props.category) detail.value = record
    else router.push('/categorias-problemas/' + record.id)
}
function saved() {
    formOpen.value = false
    notify('Registro salvo com sucesso.')
    reload()
}
function prepareAction(record) {
    action.value = record
    actionError.value = ''
}
async function toggle() {
    if (actionBusy.value) return
    actionBusy.value = true
    actionError.value = ''
    try {
        await api.patch(endpoint.value + '/' + action.value.id, {
            is_active: !action.value.is_active,
        })
        action.value = null
        notify('Situação atualizada.')
        reload()
    } catch (exception) {
        actionError.value = exception.message
    } finally {
        actionBusy.value = false
    }
}
</script>
<template>
    <section class="panel">
        <div class="panel-heading">
            <div class="section-title">
                <AppIcon name="layers" />
                <h2>{{ category ? 'Subcategorias' : 'Todas as categorias' }}</h2>
                <span class="count-pill">{{ total }}</span>
            </div>
            <button type="button" class="btn btn-primary btn-sm" @click="edit()">
                <AppIcon name="plus" :size="16" />Nova {{ singular }}
            </button>
        </div>
        <FilterBar
            :fields="problemFilters"
            :values="route.query"
            @apply="router.replace({ query: { ...$event, page: 1 } })"
        />
        <StatePanel
            v-if="loading || error || !rows.length"
            :loading="loading"
            :error="error"
            title="Nenhum registro encontrado"
            description="Cadastre um registro ou ajuste os filtros para continuar."
            @retry="reload"
        />
        <RecordTable v-else :columns="columns" :rows="tableRows" @view="view" @edit="edit">
            <template #actions="{ row }">
                <button
                    type="button"
                    class="text-button"
                    :aria-label="
                        (row.is_active ? 'Desativar ' : 'Ativar ') + singular + ' ' + row.name
                    "
                    @click="prepareAction(row)"
                >
                    {{ row.is_active ? 'Desativar' : 'Ativar' }}
                </button>
                <button
                    type="button"
                    class="icon-button"
                    :aria-label="'Editar ' + singular + ' ' + row.name"
                    @click="edit(row)"
                >
                    <AppIcon name="edit" :size="17" />
                </button>
                <button
                    type="button"
                    class="icon-button"
                    :aria-label="
                        category
                            ? 'Visualizar subcategoria ' + row.name
                            : 'Visualizar subcategorias de ' + row.name
                    "
                    @click="view(row)"
                >
                    <AppIcon name="chevron" :size="17" />
                </button>
            </template>
        </RecordTable>
        <PaginationBar
            v-if="!error"
            :meta="meta"
            :loading="loading"
            @change="router.replace({ query: { ...route.query, page: $event } })"
        />
    </section>
    <ProblemClassificationForm
        v-if="formOpen"
        :record="editing"
        :category="category"
        @close="formOpen = false"
        @saved="saved"
    />
    <AppModal
        v-if="action"
        :title="(action.is_active ? 'Desativar ' : 'Ativar ') + singular"
        :busy="actionBusy"
        @close="!actionBusy && (action = null)"
    >
        <div v-if="actionError" class="alert alert-error" role="alert">{{ actionError }}</div>
        <p class="preserve-text">
            {{ action.is_active ? 'Desativar' : 'Ativar' }} {{ action.name }}?
        </p>
        <p class="field-hint">
            {{
                action.is_active
                    ? 'O registro e seus vínculos serão preservados para consulta histórica.'
                    : 'As disponibilidades de sistemas configuradas serão mantidas.'
            }}
        </p>
        <template #footer
            ><button
                type="button"
                class="btn btn-secondary"
                :disabled="actionBusy"
                @click="action = null"
            >
                Cancelar</button
            ><button type="button" class="btn btn-primary" :disabled="actionBusy" @click="toggle">
                {{ actionBusy ? 'Salvando…' : 'Confirmar' }}
            </button></template
        >
    </AppModal>
    <AppModal v-if="detail" title="Dados da subcategoria" wide @close="detail = null">
        <dl class="detail-grid">
            <div class="field-full">
                <dt>Nome</dt>
                <dd class="preserve-text">{{ detail.name }}</dd>
            </div>
            <div class="field-full">
                <dt>Categoria</dt>
                <dd>{{ category.name }}<small v-if="!category.is_active"> · Inativa</small></dd>
            </div>
            <div class="field-full">
                <dt>Descrição</dt>
                <dd class="preserve-text">{{ detail.description || 'Não informada' }}</dd>
            </div>
            <div>
                <dt>Situação</dt>
                <dd><StatusBadge :value="detail.is_active" /></dd>
            </div>
            <div>
                <dt>Sistemas habilitados</dt>
                <dd class="preserve-text">{{ systemNames(detail.systems) }}</dd>
            </div>
            <div
                v-if="detail.system_associations.some((system) => !system.association_active)"
                class="field-full"
            >
                <dt>Associações desabilitadas (histórico)</dt>
                <dd class="preserve-text">
                    {{
                        systemNames(
                            detail.system_associations.filter(
                                (system) => !system.association_active,
                            ),
                        )
                    }}
                </dd>
            </div>
            <div>
                <dt>Criado em</dt>
                <dd>{{ formatDate(detail.created_at) }}</dd>
            </div>
            <div>
                <dt>Última atualização</dt>
                <dd>{{ formatDate(detail.updated_at) }}</dd>
            </div>
        </dl>
        <template #footer
            ><button type="button" class="btn btn-secondary" @click="detail = null">Fechar</button
            ><button type="button" class="btn btn-primary" @click="edit(detail)">
                Editar subcategoria
            </button></template
        >
    </AppModal>
</template>
