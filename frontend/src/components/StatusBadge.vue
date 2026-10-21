<script setup>
import { displayStatus } from '@/utils/format'
defineProps({ value: [String, Boolean], overdue: Boolean })
</script>
<template>
    <span class="badge" :class="'badge-' + (overdue ? 'overdue' : String(value))"
        ><span class="badge-dot"></span
        >{{
            overdue
                ? 'Atrasada'
                : value === true
                  ? 'Ativo'
                  : value === false
                    ? 'Inativo'
                    : displayStatus(value)
        }}</span
    >
</template>
