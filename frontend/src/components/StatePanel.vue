<script setup>
import AppIcon from './AppIcon.vue'
defineProps({
    loading: Boolean,
    error: String,
    title: { type: String, default: 'Tudo pronto para começar' },
    description: {
        type: String,
        default: 'Os registros aparecerão aqui assim que forem cadastrados.',
    },
    compact: Boolean,
})
defineEmits(['retry'])
</script>
<template>
    <div class="state-panel" :class="{ compact }" :role="error ? 'alert' : 'status'">
        <span v-if="loading" class="spinner"></span
        ><span v-else class="state-icon"
            ><AppIcon :name="error ? 'alert' : 'layers'" :size="25"
        /></span>
        <h3>
            {{ loading ? 'Carregando informações…' : error ? 'Não foi possível carregar' : title }}
        </h3>
        <p>{{ error || (loading ? 'Só um instante.' : description) }}</p>
        <button v-if="error" type="button" class="btn btn-secondary" @click="$emit('retry')">
            <AppIcon name="refresh" :size="16" />Tentar novamente
        </button>
        <slot v-if="!loading && !error" />
    </div>
</template>
