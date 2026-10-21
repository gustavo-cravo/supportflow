import { mergeConfig } from 'vite'
import config from './vite.config.js'

// Fixed isolated API target. Never fall back to the normal development proxy.
export default mergeConfig(config, {
    server: {
        host: '127.0.0.1',
        port: 5181,
        strictPort: true,
        proxy: { '/api': { target: 'http://127.0.0.1:8011' } },
    },
})
