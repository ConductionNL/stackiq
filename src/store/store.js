/* eslint-disable no-console */
// The store script handles app wide variables (or state), for the use of these variables and there governing concepts read the design.md
import pinia from '../pinia.js'
import { useNavigationStore } from './modules/navigation.js'
import { useObjectStore } from './modules/object.js'
import { useCatalogStore } from './modules/catalog.js'

const navigationStore = useNavigationStore(pinia)
const objectStore = useObjectStore(pinia)
const catalogStore = useCatalogStore(pinia)

export {
	// generic
	navigationStore,
	objectStore,
	catalogStore,
}
