/**
 * useLiveCollections — collection-scoped live updates for module views.
 *
 * Subscribes the given object store to the or-collection-{register}-{schema}
 * event scope of every listed type (nc-vue liveUpdatesPlugin, default-on in
 * createObjectStore since beta.212). Events are refetch HINTS only: the
 * plugin re-runs fetchCollection with the last-used params, which lands in
 * the exact `getCollection(type)` state the module views render from — no
 * extra bridging needed.
 *
 * Built on the library's useObjectSubscription, so each subscription is
 * released automatically when the calling component's scope is disposed.
 * Subscribing is gated on the type being registered in the store: the module
 * views register types lazily inside their loadData()/fetchType() path, and
 * the reactive `enabled` gate flips the subscription on as soon as the
 * registration lands.
 *
 * Use inside a Vue component `setup()`.
 *
 * @param {object} objectStore The app's createObjectStore-based store instance.
 * @param {Array<string>} types Object type slugs to subscribe to (static list).
 * @return {void}
 *
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */
import { computed } from 'vue'
import { useObjectSubscription } from '@conduction/nextcloud-vue'

export function useLiveCollections(objectStore, types) {
	for (const type of types) {
		useObjectSubscription(objectStore, type, null, {
			enabled: computed(() => Boolean(objectStore.objectTypeRegistry?.[type])),
		})
	}
}
