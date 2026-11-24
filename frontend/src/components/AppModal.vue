<script>
let openModals = 0
let previousOverflow
</script>

<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import AppIcon from './AppIcon.vue'
const props = defineProps({ title: String, busy: Boolean, wide: Boolean, suspended: Boolean })
const emit = defineEmits(['close'])
const panel = ref(null)
const titleId = useId()
let previousFocus
function onKey(event) {
    if (props.suspended) return
    if (event.key === 'Escape' && !props.busy) emit('close')
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
    if (!elements.includes(document.activeElement)) {
        event.preventDefault()
        const target = event.shiftKey ? elements.at(-1) : elements[0]
        target.focus()
    } else if (event.shiftKey && document.activeElement === elements[0]) {
        event.preventDefault()
        elements.at(-1).focus()
    } else if (!event.shiftKey && document.activeElement === elements.at(-1)) {
        event.preventDefault()
        elements[0].focus()
    }
}
onMounted(async () => {
    previousFocus = document.activeElement
    if (openModals++ === 0) previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    await nextTick()
    panel.value?.focus()
})
onBeforeUnmount(() => {
    if (--openModals === 0) document.body.style.overflow = previousOverflow
    nextTick(() => {
        if (previousFocus?.isConnected) previousFocus.focus()
    })
})
</script>
<template>
    <Teleport to="body"
        ><div
            class="modal-backdrop"
            :inert.attr="suspended ? '' : null"
            :aria-hidden="suspended || undefined"
            @click.self="!busy && !suspended && $emit('close')"
        >
            <section
                ref="panel"
                class="modal"
                :class="{ 'modal-wide': wide }"
                role="dialog"
                :aria-modal="!suspended"
                :aria-labelledby="titleId"
                tabindex="-1"
                @keydown="onKey"
            >
                <header class="modal-header">
                    <h2 :id="titleId">{{ title }}</h2>
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
