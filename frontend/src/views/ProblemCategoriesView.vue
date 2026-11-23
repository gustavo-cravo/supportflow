<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/services/api'
import { systemNames } from '@/config/problemClassifications'
import { useNotifications } from '@/composables/useNotifications'
import { formatDate } from '@/utils/format'
import AppIcon from '@/components/AppIcon.vue'
import PageHeading from '@/components/PageHeading.vue'
import ProblemClassificationForm from '@/components/ProblemClassificationForm.vue'
import ProblemClassificationList from '@/components/ProblemClassificationList.vue'
import StatePanel from '@/components/StatePanel.vue'
import StatusBadge from '@/components/StatusBadge.vue'

const route = useRoute(),
    { notify } = useNotifications()
const category = ref(null),
    loading = ref(!!route.params.id),
    error = ref(''),
    editing = ref(false)
let controller
async function load() {
    controller?.abort()
    const current = (controller = new AbortController())
    loading.value = true
    error.value = ''
    try {
        category.value = (
            await api.get('problem-categories/' + route.params.id, undefined, current.signal)
        ).data
    } catch (exception) {
        if (!current.signal.aborted) error.value = exception.message
    } finally {
        if (!current.signal.aborted) loading.value = false
    }
}
function saved(record) {
    category.value = record
    editing.value = false
    notify('Categoria salva com sucesso.')
}
if (route.params.id) load()
onBeforeUnmount(() => controller?.abort())
</script>
<template>
    <template v-if="!route.params.id">
        <PageHeading
            title="Categorias de Problemas"
            eyebrow="CADASTROS"
            description="Organize os problemas e sua disponibilidade em cada sistema."
        />
        <ProblemClassificationList />
    </template>
    <template v-else>
        <RouterLink to="/categorias-problemas" class="back-link"
            ><AppIcon name="back" :size="16" />Voltar às categorias</RouterLink
        >
        <StatePanel v-if="loading || error" :loading="loading" :error="error" @retry="load" />
        <template v-else-if="category">
            <PageHeading
                :title="category.name"
                eyebrow="CATEGORIA DE PROBLEMAS"
                description="Consulte a categoria e gerencie suas subcategorias."
                ><button type="button" class="btn btn-secondary" @click="editing = true">
                    <AppIcon name="edit" :size="16" />Editar categoria
                </button></PageHeading
            >
            <section class="panel detail-panel category-summary">
                <dl class="detail-grid">
                    <div class="field-full">
                        <dt>Descrição</dt>
                        <dd class="preserve-text">{{ category.description || 'Não informada' }}</dd>
                    </div>
                    <div>
                        <dt>Situação</dt>
                        <dd><StatusBadge :value="category.is_active" /></dd>
                    </div>
                    <div>
                        <dt>Sistemas associados</dt>
                        <dd class="preserve-text">{{ systemNames(category.systems) }}</dd>
                    </div>
                    <div
                        v-if="
                            category.system_associations.some(
                                (system) => !system.association_active,
                            )
                        "
                        class="field-full"
                    >
                        <dt>Associações desabilitadas (histórico)</dt>
                        <dd class="preserve-text">
                            {{
                                systemNames(
                                    category.system_associations.filter(
                                        (system) => !system.association_active,
                                    ),
                                )
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt>Criado em</dt>
                        <dd>{{ formatDate(category.created_at) }}</dd>
                    </div>
                    <div>
                        <dt>Última atualização</dt>
                        <dd>{{ formatDate(category.updated_at) }}</dd>
                    </div>
                </dl>
            </section>
            <ProblemClassificationList :category="category" />
            <ProblemClassificationForm
                v-if="editing"
                :record="category"
                @close="editing = false"
                @saved="saved"
            />
        </template>
    </template>
</template>
<style scoped>
.category-summary {
    margin-bottom: 24px;
}
.detail-grid > div {
    min-width: 0;
    overflow-wrap: anywhere;
}
</style>
