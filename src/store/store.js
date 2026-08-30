// The store script handles app wide variables (or state), for the use of these variables and there governing concepts read the design.md
import pinia from '../pinia.js'
import { useCatalogStore } from './modules/catalog.js'
import { useNavigationStore } from './modules/navigation.js'
import { useObjectStore } from './modules/object.js'
import { useOrganisatieStore } from './modules/organisatie.js'
import { useSettingsStore } from './modules/settings.js'

const navigationStore = useNavigationStore(pinia)
const objectStore = useObjectStore(pinia)
const catalogStore = useCatalogStore(pinia)
const settingsStore = useSettingsStore(pinia)
const organisatieStore = useOrganisatieStore(pinia)

export {
	catalogStore,
	// generic
	navigationStore,
	objectStore,
	organisatieStore,
	settingsStore,
}
