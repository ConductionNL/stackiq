import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import AdminSettings from './views/settings/SoftwareCatalogSettings.vue'
import pinia from './pinia.js'

const app = createApp({
	render: () => h(AdminSettings),
})

// Vue 3 has no global `Vue.mixin`; install `t`/`n` on this app instance so
// templates in the settings tree keep resolving them.
app.mixin({ methods: { t, n } })
app.use(pinia)

// ⚠️ Vue 2's `$mount('#settings')` REPLACED the matched element; Vue 3's
// `mount()` renders INSIDE it, and `#settings` is a generic id Nextcloud's own
// settings chrome can also carry. The host element is named after the app
// (see templates/settings/admin.php) so there is nothing to disambiguate.
app.mount('#softwarecatalog-settings')
