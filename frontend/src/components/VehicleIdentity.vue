<script setup>
import { vehicleLineLabel } from '@/config/vehicleCatalog'

defineProps({ vehicle: Object })
</script>

<template>
    <span class="vehicle-identity">
        <strong>{{ vehicle.plate }}</strong>
        <span
            >{{ vehicle.brand?.name || 'Marca não informada' }} —
            {{ vehicle.model_description || 'Modelo não informado' }}</span
        >
        <small>{{ vehicleLineLabel(vehicle.vehicle_line) }}</small>
    </span>
</template>

<style scoped>
.vehicle-identity {
    display: grid;
    gap: 4px;
    min-width: 0;
    font-size: 12px;
    line-height: 1.5;
    text-align: left;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.vehicle-identity strong {
    color: var(--blue);
    font-size: 13px;
    letter-spacing: 0.03em;
}
.vehicle-identity small {
    color: #65738b;
}
</style>
