<script setup>
import { nextTick, ref } from 'vue'
import { vehicleBrands, vehicles } from '@/config/vehicleCatalog'
import { useNotifications } from '@/composables/useNotifications'
import FormField from './FormField.vue'
import ResourceForm from './ResourceForm.vue'

defineProps({ record: Object })
defineEmits(['close', 'saved'])

const { notify } = useNotifications()
const brandOpen = ref(false)
const brandLink = ref(null)
const newBrand = ref(null)

async function closeBrand() {
    brandOpen.value = false
    await nextTick()
    brandLink.value?.focus()
}

function selectNewBrand(brand, values, errors) {
    newBrand.value = brand
    values.brand_id = brand.id
    delete errors.brand_id
    closeBrand()
    notify('Marca cadastrada com sucesso.')
}

function reportFailure(error) {
    if (error.status !== 422) notify(error.message, 'error')
}
</script>

<template>
    <ResourceForm
        :config="vehicles"
        :record="record"
        :suspended="brandOpen"
        prefix="vehicle"
        @close="$emit('close')"
        @saved="$emit('saved', $event)"
        @failed="reportFailure"
    >
        <template #field-brand_id="{ field, values, errors }">
            <FormField
                v-model="values.brand_id"
                :field="field"
                :errors="errors"
                :initial-record="newBrand?.id === values.brand_id ? newBrand : record?.brand"
                prefix="vehicle"
            >
                <template #label-action>
                    <button
                        ref="brandLink"
                        type="button"
                        class="text-button"
                        aria-haspopup="dialog"
                        @click="brandOpen = true"
                    >
                        Nova marca
                    </button>
                </template>
            </FormField>
            <ResourceForm
                v-if="brandOpen"
                :config="vehicleBrands"
                prefix="vehicle-brand"
                @close="closeBrand"
                @saved="selectNewBrand($event, values, errors)"
                @failed="reportFailure"
            />
        </template>
    </ResourceForm>
</template>
