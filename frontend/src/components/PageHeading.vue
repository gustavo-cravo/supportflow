<script setup>
defineProps({ title: String, description: String, eyebrow: String })
</script>
<template>
    <header class="page-heading">
        <div>
            <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
            <h1>{{ title }}</h1>
            <p v-if="description" class="page-description">{{ description }}</p>
        </div>
        <div class="heading-actions"><slot /></div>
    </header>
</template>
