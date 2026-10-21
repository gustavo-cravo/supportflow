<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ticketFields } from '@/config/resources'
import { api } from '@/services/api'
import { initialValues, formPayload, validateFields } from '@/utils/forms'
import { resolutionValues, resolutionPayload, validateResolution } from '@/utils/resolution'
import { useNotifications } from '@/composables/useNotifications'
import AppIcon from '@/components/AppIcon.vue'
import FormField from '@/components/FormField.vue'
import PageHeading from '@/components/PageHeading.vue'
import ResolutionFields from '@/components/ResolutionFields.vue'
import StatePanel from '@/components/StatePanel.vue'
const route = useRoute(),
    router = useRouter(),
    { notify } = useNotifications()
const editing = Boolean(route.params.id)
const values = reactive({
    ...initialValues(ticketFields),
    ...resolutionValues(),
    previous_ticket_id: Number(route.query.anterior) || null,
})
const record = ref(null),
    loading = ref(editing),
    loadError = ref(''),
    busy = ref(false),
    error = ref(''),
    errors = ref({})
const controller = new AbortController()
const fields = computed(() =>
    ticketFields.map((field) => ({
        ...field,
        ...(field.name === 'system_id' ? { params: { is_active: 1 } } : {}),
        ...(field.name === 'previous_ticket_id'
            ? { excludeId: Number(route.params.id) || null }
            : {}),
    })),
)
async function load() {
    if (!editing) return
    loading.value = true
    loadError.value = ''
    try {
        record.value = (
            await api.get('support-tickets/' + route.params.id, undefined, controller.signal)
        ).data
        Object.assign(
            values,
            initialValues(ticketFields, record.value),
            resolutionValues(record.value),
        )
    } catch (exception) {
        if (!controller.signal.aborted) loadError.value = exception.message
    } finally {
        loading.value = false
    }
}
async function save() {
    if (busy.value) return
    errors.value = { ...validateFields(fields.value, values), ...validateResolution(values) }
    error.value = ''
    if (Object.keys(errors.value).length) {
        error.value = 'Revise os campos destacados.'
        return
    }
    busy.value = true
    try {
        const payload = { ...formPayload(ticketFields, values), ...resolutionPayload(values) }
        const result = await (editing
            ? api.patch('support-tickets/' + route.params.id, payload)
            : api.post('support-tickets', payload))
        notify(editing ? 'Atendimento atualizado.' : 'Atendimento registrado com sucesso.')
        await router.push('/atendimentos/' + result.data.id)
    } catch (exception) {
        error.value = exception.message
        errors.value = exception.errors || {}
    } finally {
        busy.value = false
    }
}
load()
onBeforeUnmount(() => controller.abort())
</script>
<template>
    <RouterLink
        class="back-link"
        :to="editing ? '/atendimentos/' + route.params.id : '/atendimentos'"
        ><AppIcon name="back" :size="16" />Voltar
        {{ editing ? 'ao atendimento' : 'aos atendimentos' }}</RouterLink
    ><PageHeading
        :title="editing ? 'Editar atendimento' : 'Novo atendimento'"
        description="Registre o contexto agora. Tenha tudo à mão quando precisar."
        eyebrow="ATENDIMENTOS"
    /><StatePanel v-if="loading || loadError" :loading="loading" :error="loadError" @retry="load" />
    <form v-else class="ticket-form" novalidate @submit.prevent="save">
        <div v-if="error" class="alert alert-error" role="alert">{{ error }}</div>
        <section class="panel form-panel">
            <header class="section-heading">
                <span class="section-number">01</span>
                <div>
                    <h2>Informações do atendimento</h2>
                    <p>Campos com * são obrigatórios.</p>
                </div>
            </header>
            <fieldset class="form-grid" :disabled="busy">
                <FormField
                    v-for="field in fields"
                    :key="field.name"
                    v-model="values[field.name]"
                    :field="field"
                    :errors="errors"
                    prefix="ticket"
                />
            </fieldset>
            <p v-if="record?.system?.is_active === false" class="alert alert-info">
                O sistema atual está inativo. Você pode mantê-lo neste histórico ou selecionar outro
                sistema ativo.
            </p>
            <p class="form-links">
                Precisa de um cadastro? <RouterLink to="/sistemas?novo=1">Novo sistema</RouterLink
                ><span>·</span><RouterLink to="/contatos?novo=1">Novo contato</RouterLink
                ><span>·</span><RouterLink to="/clientes?novo=1">Novo cliente</RouterLink>
            </p>
        </section>
        <section class="panel form-panel">
            <header class="section-heading">
                <span class="section-number">02</span>
                <div>
                    <h2>Resolução e confirmação</h2>
                    <p>Opcional. Pode ser preenchida agora ou depois.</p>
                </div>
            </header>
            <fieldset :disabled="busy">
                <ResolutionFields :model="values" :errors="errors" />
            </fieldset>
        </section>
        <div class="form-actions">
            <RouterLink
                class="btn btn-secondary"
                :aria-disabled="busy"
                :to="editing ? '/atendimentos/' + route.params.id : '/atendimentos'"
                >Voltar</RouterLink
            ><button class="btn btn-primary" type="submit" :disabled="busy">
                <span v-if="busy" class="spinner spinner-small"></span
                >{{ busy ? 'Salvando…' : editing ? 'Salvar alterações' : 'Registrar atendimento' }}
            </button>
        </div>
    </form>
</template>
