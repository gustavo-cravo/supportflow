import { afterEach, describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import App from '../App.vue'
import { routes } from '../router'
import { api } from '../services/api'

afterEach(() => vi.restoreAllMocks())

describe('App', () => {
    it('renders the navigation and loads the routed real-resource view', async () => {
        vi.spyOn(api, 'get').mockResolvedValue({ data: [], meta: { total: 0 } })
        const router = createRouter({ history: createMemoryHistory(), routes })
        await router.push('/contatos')
        const wrapper = mount(App, { global: { plugins: [router] } })
        await flushPromises()
        expect(wrapper.text()).toContain('SupportFlow')
        expect(wrapper.find('h1').text()).toBe('Contatos')
        expect(wrapper.findAll('nav[aria-label="Navegação principal"] a')).toHaveLength(8)
        expect(api.get).toHaveBeenCalledWith('contacts', {}, expect.any(AbortSignal))
        wrapper.unmount()
    })
})
