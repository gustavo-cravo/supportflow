<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import AppIcon from './AppIcon.vue'
defineProps({ title: String, busy: Boolean, wide: Boolean })
const emit = defineEmits(['close'])
const panel = ref(null)
let previousFocus
let previousOverflow
function onKey(event) {
    if (event.key === 'Escape') emit('close')
    if (event.key !== 'Tab') return
    const elements = [
        ...panel.value.querySelectorAll(
            'button:not(:disabled), input:not(:disabled), textarea:not(:disabled), select:not(:disabled), a[href], [tabindex="0"]',
        ),
    ]
    if (!elements.length) {
        event.preventDefault()
        panel.value.focus()
        return
    }
    if (event.shiftKey && document.activeElement === elements[0]) {
        event.preventDefault()
        elements.at(-1).focus()
    } else if (!event.shiftKey && document.activeElement === elements.at(-1)) {
        event.preventDefault()
        elements[0].focus()
    }
}
onMounted(async () => {
    previousFocus = document.activeElement
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    await nextTick()
    panel.value?.focus()
})
onBeforeUnmount(() => {
    document.body.style.overflow = previousOverflow
    previousFocus?.focus?.()
})
</script>
<template>
    <Teleport to="body"
        ><div class="modal-backdrop" @click.self="!busy && $emit('close')">
            <section
                ref="panel"
                class="modal"
                :class="{ 'modal-wide': wide }"
                role="dialog"
                aria-modal="true"
                aria-labelledby="modal-title"
                tabindex="-1"
                @keydown="onKey"
            >
                <header class="modal-header">
                    <h2 id="modal-title">{{ title }}</h2>
                    <button
                        type="button"
                        class="icon-button"
                        :disabled="busy"
                        aria-label="Fechar janela"
                        @click="$emit('close')"
                    >
                        <AppIcon name="close" />
                    </button>
                </header>
                <div class="modal-body"><slot /></div>
                <footer v-if="$slots.footer" class="modal-footer"><slot name="footer" /></footer>
            </section></div
    ></Teleport>
</template>
