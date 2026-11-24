import { computed, onScopeDispose, ref, shallowRef, toValue, watch } from 'vue'
import { api } from '@/services/api'
export function useList(endpoint, params) {
    const rows = ref([])
    const meta = ref(null)
    const loading = ref(false)
    const error = ref('')
    const failure = shallowRef(null)
    let controller
    let version = 0
    async function reload() {
        controller?.abort()
        controller = new AbortController()
        const current = ++version
        loading.value = true
        error.value = ''
        failure.value = null
        try {
            const result = await api.get(toValue(endpoint), toValue(params), controller.signal)
            if (current !== version) return
            rows.value = result.data
            meta.value = result.meta
        } catch (exception) {
            if (current !== version || controller.signal.aborted) return
            error.value = exception.message
            failure.value = exception
            rows.value = []
            meta.value = null
        } finally {
            if (current === version) loading.value = false
        }
    }
    watch([() => toValue(endpoint), () => toValue(params)], reload, { immediate: true, deep: true })
    onScopeDispose(() => {
        version++
        controller?.abort()
    })
    return {
        rows,
        meta,
        loading,
        error,
        failure,
        reload,
        total: computed(() => meta.value?.total ?? 0),
    }
}
