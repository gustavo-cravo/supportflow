<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import AppIcon from '@/components/AppIcon.vue'

const props = defineProps({ file: { type: Object, required: true } })
const url = ref('')
const inline = ref(navigator.pdfViewerEnabled !== false)
let openedInTab = false

onMounted(() => {
    try {
        url.value = URL.createObjectURL(props.file.blob)
    } catch {
        inline.value = false
    }
})
onUnmounted(() => {
    if (!url.value) return
    const objectUrl = url.value
    // Give a new tab time to consume its navigation before releasing the URL.
    if (openedInTab) setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
    else URL.revokeObjectURL(objectUrl)
})
</script>

<template>
    <div class="metrics-pdf-preview">
        <p v-if="!inline" class="alert alert-info" role="status">
            {{
                url
                    ? 'A visualização dentro do modal está indisponível. Abra o PDF em nova aba ou baixe o arquivo.'
                    : 'Não foi possível abrir a pré-visualização. Baixe o arquivo ou volte à configuração para tentar novamente.'
            }}
        </p>
        <div v-if="url" class="metrics-pdf-preview-tools">
            <a
                class="text-button metrics-pdf-preview-link"
                :href="url"
                target="_blank"
                rel="noopener noreferrer"
                @click="openedInTab = true"
            >
                <AppIcon name="arrow" :size="15" /> Abrir PDF em nova aba
            </a>
            <p v-if="inline" class="muted metrics-pdf-preview-help">
                Se o visualizador ficar em branco, abra em nova aba ou baixe o PDF.
            </p>
        </div>
        <iframe
            v-if="url && inline"
            class="metrics-pdf-preview-frame"
            :src="url"
            title="Páginas do relatório PDF"
            tabindex="0"
            @error="inline = false"
        ></iframe>
    </div>
</template>

<style scoped>
:global(.modal-backdrop:has(.metrics-pdf-preview)) {
    overflow: clip;
}
:global(.modal:has(.metrics-pdf-preview)) {
    max-width: 1060px;
    height: 90dvh;
}
:global(.modal:has(.metrics-pdf-preview) > .modal-body) {
    display: flex;
    flex: 1;
    min-height: 0;
}
.metrics-pdf-preview {
    display: flex;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    gap: 10px;
}
.metrics-pdf-preview .alert {
    margin-bottom: 0;
}
.metrics-pdf-preview-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.metrics-pdf-preview-tools {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 4px 14px;
}
.metrics-pdf-preview-help {
    font-size: 11px;
}
.metrics-pdf-preview-frame {
    width: 100%;
    min-height: 150px;
    flex: 1;
    border: 1px solid var(--line);
    border-radius: 7px;
    background: #f5f8fc;
}
</style>
