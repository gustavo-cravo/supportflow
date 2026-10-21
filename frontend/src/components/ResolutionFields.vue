<script setup>
import FormField from './FormField.vue'
import { verificationSources } from '@/utils/format'
defineProps({ model: Object, errors: Object })
const fields = [
    { name: 'resolution', label: 'Solução aplicada', type: 'textarea', max: 16000, full: true },
    {
        name: 'verification_source',
        label: 'Fonte da confirmação',
        type: 'select',
        options: verificationSources,
    },
    { name: 'verified_at', label: 'Data e horário da confirmação', type: 'datetime-local' },
    { name: 'verified_by', label: 'Confirmado por', type: 'text', max: 255, full: true },
    {
        name: 'verification_notes',
        label: 'Como a resolução foi verificada',
        type: 'textarea',
        max: 16000,
        full: true,
    },
]
</script>
<template>
    <div class="form-grid">
        <FormField
            v-for="field in fields"
            :key="field.name"
            :model-value="model[field.name]"
            :field="field"
            :errors="errors"
            prefix="resolution"
            @update:model-value="model[field.name] = $event"
        />
    </div>
</template>
