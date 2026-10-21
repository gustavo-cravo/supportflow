<script setup>
import { useNotifications } from '@/composables/useNotifications'
import AppIcon from './AppIcon.vue'
const { notices, dismiss } = useNotifications()
</script>
<template>
    <div class="toast-list" aria-live="polite">
        <div
            v-for="notice in notices"
            :key="notice.id"
            class="toast"
            :class="'toast-' + notice.tone"
            :role="notice.tone === 'error' ? 'alert' : 'status'"
        >
            <AppIcon :name="notice.tone === 'error' ? 'alert' : 'check'" /><span>{{
                notice.message
            }}</span
            ><button
                class="icon-button"
                type="button"
                aria-label="Dispensar mensagem"
                @click="dismiss(notice.id)"
            >
                <AppIcon name="close" :size="16" />
            </button>
        </div>
    </div>
</template>
