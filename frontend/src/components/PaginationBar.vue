<script setup>
defineProps({ meta: Object, loading: Boolean })
defineEmits(['change'])
</script>
<template>
    <nav v-if="meta && meta.total" class="pagination" aria-label="Paginação">
        <span
            >{{ meta.from }}–{{ meta.to }} de <strong>{{ meta.total }}</strong> registros</span
        >
        <div>
            <button
                class="btn btn-secondary btn-sm"
                type="button"
                :disabled="loading || meta.current_page <= 1"
                @click="$emit('change', meta.current_page - 1)"
            >
                Anterior</button
            ><span class="page-number">{{ meta.current_page }} / {{ meta.last_page }}</span
            ><button
                class="btn btn-secondary btn-sm"
                type="button"
                :disabled="loading || meta.current_page >= meta.last_page"
                @click="$emit('change', meta.current_page + 1)"
            >
                Próxima
            </button>
        </div>
    </nav>
</template>
