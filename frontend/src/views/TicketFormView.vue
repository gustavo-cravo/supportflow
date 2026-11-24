<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { resources, ticketFields, ticketClassificationFields } from '@/config/resources'
import { vehicles } from '@/config/vehicleCatalog'
import { api } from '@/services/api'
import { initialValues, formPayload, validateFields } from '@/utils/forms'
import { fieldError } from '@/utils/format'
import { resolutionValues, resolutionPayload, validateResolution } from '@/utils/resolution'
import { useNotifications } from '@/composables/useNotifications'
import AppIcon from '@/components/AppIcon.vue'
import FormField from '@/components/FormField.vue'
import PageHeading from '@/components/PageHeading.vue'
import RelationSelect from '@/components/RelationSelect.vue'
import ResourceForm from '@/components/ResourceForm.vue'
import ResolutionFields from '@/components/ResolutionFields.vue'
import StatePanel from '@/components/StatePanel.vue'
import TicketClassificationFields from '@/components/TicketClassificationFields.vue'
import VehicleFormModal from '@/components/VehicleFormModal.vue'
import VehicleIdentity from '@/components/VehicleIdentity.vue'
const route = useRoute(),
    router = useRouter(),
    { notify } = useNotifications()
const editing = Boolean(route.params.id)
function ticketDetail(id) {
    return { name: 'ticket-detail', params: { id }, query: { retorno: route.query.retorno } }
}
const allFields = [...ticketFields, ...ticketClassificationFields]
const quickFields = ticketFields.filter((field) =>
    ['system_id', 'contact_id', 'customer_id'].includes(field.name),
)
const quickField = ref(null)
const quickRecords = reactive({})
const classification = ref(null)
const vehicleEnabled = ref(false)
const vehicleId = ref(null)
const vehicleRecord = ref(null)
const vehicleModalOpen = ref(false)
const vehicleTrigger = ref(null)
const quickOpen = computed(() => Boolean(quickField.value) || vehicleModalOpen.value)
const values = reactive({
    ...initialValues(allFields),
    ...resolutionValues(),
    previous_ticket_id: Number(route.query.anterior) || null,
})
const record = ref(null),
    loading = ref(editing),
    loadError = ref(''),
    busy = ref(false),
    error = ref(''),
    errors = ref({})
const vehicleError = computed(() => fieldError(errors.value, 'vehicle_id', 'Veículo'))
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
        vehicleId.value = record.value.vehicle_id ?? null
        vehicleEnabled.value = vehicleId.value !== null
        vehicleRecord.value = record.value.vehicle ?? null
        Object.assign(
            values,
            initialValues(allFields, record.value),
            resolutionValues(record.value),
            {
                problem_subcategory_ids:
                    record.value.problem_subcategory_ids ??
                    (record.value.problem_subcategory_id
                        ? [record.value.problem_subcategory_id]
                        : []),
            },
        )
    } catch (exception) {
        if (!controller.signal.aborted) loadError.value = exception.message
    } finally {
        loading.value = false
    }
}
async function save() {
    if (busy.value || quickOpen.value) return
    errors.value = {
        ...validateFields(fields.value, values),
        ...validateResolution(values),
        ...classification.value?.validate(),
        ...(vehicleEnabled.value && !vehicleId.value
            ? { vehicle_id: ['Selecione um veículo para o atendimento.'] }
            : {}),
    }
    error.value = ''
    if (Object.keys(errors.value).length) {
        error.value = 'Revise os campos destacados.'
        return
    }
    busy.value = true
    try {
        const payload = { ...formPayload(allFields, values), ...resolutionPayload(values) }
        if (editing || vehicleEnabled.value) {
            payload.vehicle_id = vehicleEnabled.value ? vehicleId.value : null
        }
        const result = await (editing
            ? api.patch('support-tickets/' + route.params.id, payload)
            : api.post('support-tickets', payload))
        notify(editing ? 'Atendimento atualizado.' : 'Atendimento registrado com sucesso.')
        await router.push(ticketDetail(result.data.id))
    } catch (exception) {
        error.value = exception.message
        errors.value = exception.errors || {}
    } finally {
        busy.value = false
    }
}
function onQuickSaved(record) {
    const field = quickField.value.name
    quickRecords[field] = record
    values[field] = record.id
    delete errors.value[field]
    if (field === 'system_id') {
        // A newly created system has no classification associations yet.
        values.problem_category_id = null
        values.problem_subcategory_ids = []
        delete errors.value.problem_category_id
        for (const key of Object.keys(errors.value)) {
            if (key.startsWith('problem_subcategory_id')) delete errors.value[key]
        }
    }
    quickField.value = null
    notify('Registro salvo com sucesso.')
}
function selectVehicle(vehicle) {
    vehicleRecord.value = vehicle
    vehicleId.value = vehicle?.id ?? null
    delete errors.value.vehicle_id
}
async function closeVehicleModal() {
    vehicleModalOpen.value = false
    await nextTick()
    vehicleTrigger.value?.focus()
}
function onVehicleSaved(vehicle) {
    selectVehicle(vehicle)
    closeVehicleModal()
    notify('Veículo cadastrado com sucesso.')
}
load()
onBeforeUnmount(() => controller.abort())
</script>
<template>
    <RouterLink class="back-link" :to="editing ? ticketDetail(route.params.id) : '/atendimentos'"
        ><AppIcon name="back" :size="16" />Voltar
        {{ editing ? 'ao atendimento' : 'aos atendimentos' }}</RouterLink
    ><PageHeading
        :title="editing ? 'Editar atendimento' : 'Novo atendimento'"
        description="Registre o contexto agora. Tenha tudo à mão quando precisar."
        eyebrow="ATENDIMENTOS"
    /><StatePanel v-if="loading || loadError" :loading="loading" :error="loadError" @retry="load" />
    <form
        v-else
        class="ticket-form"
        :inert.attr="quickOpen ? '' : null"
        novalidate
        @submit.prevent="save"
    >
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
                    :initial-record="quickRecords[field.name]"
                    :field="field"
                    :errors="errors"
                    prefix="ticket"
                />
                <TicketClassificationFields
                    ref="classification"
                    v-model:category-id="values.problem_category_id"
                    v-model:subcategory-ids="values.problem_subcategory_ids"
                    :system-id="values.system_id"
                    :record="record"
                    :errors="errors"
                />
            </fieldset>
            <p v-if="record?.system?.is_active === false" class="alert alert-info">
                O sistema atual está inativo. Você pode mantê-lo neste histórico ou selecionar outro
                sistema ativo.
            </p>
            <p class="form-links">
                Precisa de um cadastro?
                <template v-for="(field, index) in quickFields" :key="field.name">
                    <span v-if="index">·</span>
                    <button
                        type="button"
                        aria-haspopup="dialog"
                        :disabled="busy"
                        @click="quickField = field"
                    >
                        Novo {{ resources[field.endpoint].singular }}
                    </button>
                </template>
            </p>
        </section>
        <section class="panel ticket-vehicle" aria-labelledby="ticket-vehicle-title">
            <header class="vehicle-heading">
                <h2 id="ticket-vehicle-title">Identificação veicular</h2>
                <label class="vehicle-switch">
                    <input
                        v-model="vehicleEnabled"
                        type="checkbox"
                        role="switch"
                        aria-labelledby="ticket-vehicle-title"
                        :disabled="busy"
                        @change="delete errors.vehicle_id"
                    />
                    <span aria-hidden="true"></span>
                </label>
            </header>
            <div v-if="vehicleEnabled" class="form-field">
                <div class="vehicle-heading">
                    <label for="ticket-vehicle_id">
                        Veículo <span class="required-mark" aria-hidden="true">*</span>
                    </label>
                    <button
                        ref="vehicleTrigger"
                        type="button"
                        class="text-button"
                        aria-haspopup="dialog"
                        :disabled="busy"
                        @click="vehicleModalOpen = true"
                    >
                        Novo veículo
                    </button>
                </div>
                <RelationSelect
                    id="ticket-vehicle_id"
                    :model-value="vehicleId"
                    :initial-record="vehicleRecord"
                    :endpoint="vehicles.endpoint"
                    label-field="plate"
                    placeholder="Selecione por placa, marca ou modelo"
                    search-label="Pesquisar veículos"
                    empty-message="Nenhum veículo encontrado."
                    clear-label="Remover veículo"
                    :invalid="!!vehicleError"
                    :disabled="busy || quickOpen"
                    required
                    @select="selectVehicle"
                >
                    <template #option="{ record: option }"
                        ><VehicleIdentity :vehicle="option"
                    /></template>
                </RelationSelect>
                <VehicleIdentity
                    v-if="vehicleRecord"
                    class="selected-vehicle"
                    :vehicle="vehicleRecord"
                />
                <p
                    v-if="vehicleError"
                    id="ticket-vehicle_id-error"
                    class="field-error"
                    role="alert"
                >
                    {{ vehicleError }}
                </p>
            </div>
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
                :to="editing ? ticketDetail(route.params.id) : '/atendimentos'"
                >Voltar</RouterLink
            ><button class="btn btn-primary" type="submit" :disabled="busy">
                <span v-if="busy" class="spinner spinner-small"></span
                >{{ busy ? 'Salvando…' : editing ? 'Salvar alterações' : 'Registrar atendimento' }}
            </button>
        </div>
    </form>
    <ResourceForm
        v-if="quickField"
        :config="resources[quickField.endpoint]"
        :require-active-system="quickField.name === 'system_id'"
        @close="quickField = null"
        @saved="onQuickSaved"
    />
    <VehicleFormModal
        v-else-if="vehicleModalOpen"
        @close="closeVehicleModal"
        @saved="onVehicleSaved"
    />
</template>
<style scoped>
.ticket-vehicle {
    padding: 16px 24px;
    margin-bottom: 22px;
}
.vehicle-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.ticket-vehicle .form-field {
    margin-top: 14px;
}
.form-field .vehicle-heading {
    margin-bottom: 6px;
    color: #65738b;
    font-size: 12px;
    font-weight: 550;
}
.selected-vehicle {
    margin-top: 10px;
    border: 1px solid var(--line);
    border-radius: 6px;
    padding: 10px 11px;
    margin-bottom: 2px;
}
.vehicle-switch {
    position: relative;
    display: inline-flex;
    flex: 0 0 36px;
}
.vehicle-switch input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    margin: 0;
    opacity: 0;
    cursor: pointer;
}
.vehicle-switch > span {
    display: block;
    width: 36px;
    height: 20px;
    border-radius: 999px;
    background: #75839a;
    pointer-events: none;
}
.vehicle-switch > span::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: white;
}
.vehicle-switch input:checked + span {
    background: var(--blue);
}
.vehicle-switch input:checked + span::after {
    transform: translateX(16px);
}
.vehicle-switch input:focus-visible + span {
    outline: 2px solid var(--blue);
    outline-offset: 3px;
}
.vehicle-switch input:disabled {
    cursor: not-allowed;
}
@media (max-width: 520px) {
    .ticket-vehicle {
        padding: 15px 18px;
    }
}
</style>
