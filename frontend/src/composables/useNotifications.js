import { ref } from 'vue'
const notices = ref([])
let nextId = 0
export function useNotifications() {
    function dismiss(id) {
        notices.value = notices.value.filter((notice) => notice.id !== id)
    }
    function notify(message, tone = 'success') {
        const id = ++nextId
        notices.value.push({ id, message, tone })
        setTimeout(() => dismiss(id), 6500)
    }
    return { notices, notify, dismiss }
}
