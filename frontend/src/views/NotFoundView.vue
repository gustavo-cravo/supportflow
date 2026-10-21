<script setup>
import PageHeading from '@/components/PageHeading.vue'
import StatePanel from '@/components/StatePanel.vue'
</script>
<template>
    <PageHeading title="Página não encontrada" eyebrow="SUPPORTFLOW" />
    <section class="panel">
        <StatePanel
            title="Esse caminho não está disponível"
            description="Volte ao painel para continuar organizando seus atendimentos."
            ><RouterLink class="btn btn-primary" to="/">Voltar ao dashboard</RouterLink></StatePanel
        >
    </section>
</template>
