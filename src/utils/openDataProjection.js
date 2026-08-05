/**
 * openDataProjection — sanitize a catalog entry for anonymous open-data reuse.
 *
 * The published (anonymous-visible) representation of a catalog entry MUST be a
 * sanitized projection: no RBAC/ownership metadata, no internal notes, and no
 * contact-person PII (names, e-mail, phone). It retains stable identifiers
 * (UUID, slug) and carries reuse metadata: a license (default CC0), the
 * publishing organisation's public name, and a last-modified timestamp.
 *
 * Applying the projection at the publication boundary means every consumer of
 * the published surface (API, federation, sitemap) sees the same safe shape.
 *
 * NOTE (build status, updated 2026-06-15): the anonymous READ surface is LIVE on
 * the OpenRegister RBAC publish model — an entry is anonymously visible once its
 * `publicatiedatum` is set (schema read rule `{group:public, match:
 * {publicatiedatum:{$lte:$now}}}`); "publish" = set publicatiedatum via the
 * PublicationService (`PUT /api/publication/{objectType}/{uuid}/publish`). The
 * earlier `@self.published` gap note is stale (that predicate is removed from
 * OpenRegister). This projection is the open-data serialization the public
 * surface applies; it is fully unit-tested.
 *
 * @module utils/openDataProjection
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 */

/**
 * Fields that are PII or internal and MUST never appear in an open-data
 * projection. Matched case-insensitively against top-level property names.
 *
 * @type {Array<string>}
 */
const STRIPPED_FIELDS = Object.freeze([
	'contactpersoon',
	'contactpersonen',
	'contactpersoonAanbieder',
	'contactpersoonGebruiker',
	'interneAantekening',
	'email',
	'telefoonnummer',
	'voornaam',
	'achternaam',
	'owner',
	'authorization',
	'geregistreerdDoor',
])

/**
 * Default open-data license when none is configured.
 *
 * @type {string}
 */
export const DEFAULT_LICENSE = 'CC0-1.0'

/**
 * Read the data bag + @self envelope of an OR object.
 *
 * @param {object} entry An OR object (with `@self`) or plain data bag.
 * @return {{data: object, self: object}} The split.
 */
function split(entry) {
	if (!entry || typeof entry !== 'object') {
		return { data: {}, self: {} }
	}
	const self = (entry['@self'] && typeof entry['@self'] === 'object') ? entry['@self'] : {}
	const data = (entry.object && typeof entry.object === 'object') ? entry.object : entry
	return { data, self }
}

/**
 * Whether a top-level property name is a stripped (PII/internal) field.
 *
 * @param {string} name A property name.
 * @return {boolean} True when the field must be stripped.
 */
function isStripped(name) {
	const lower = String(name).toLowerCase()
	return STRIPPED_FIELDS.some((f) => f.toLowerCase() === lower)
		|| lower.startsWith('contactpersoon')
}

/**
 * Project a catalog entry into its anonymous open-data representation.
 *
 * @param {object} entry                    An OR object (with `@self`) or data bag.
 * @param {object} [options]                Projection options.
 * @param {string} [options.license]        The open-data license (default CC0-1.0).
 * @param {string} [options.publisherName]  The publishing organisation's public name.
 * @return {object} The sanitized projection.
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 */
export function projectOpenData(entry, { license = DEFAULT_LICENSE, publisherName = null } = {}) {
	const { data, self } = split(entry)

	const projected = {}
	for (const [key, value] of Object.entries(data)) {
		if (key.startsWith('@')) {
			continue
		}
		if (isStripped(key)) {
			continue
		}
		projected[key] = value
	}

	// Stable identifiers retained from the @self envelope.
	const identifiers = {
		uuid: self.uuid ?? self.id ?? data.uuid ?? null,
		slug: self.slug ?? data.slug ?? null,
	}

	return {
		...projected,
		'@id': identifiers.uuid,
		uuid: identifiers.uuid,
		slug: identifiers.slug,
		license,
		publisher: publisherName,
		lastModified: self.updated ?? self.modified ?? data.updated ?? null,
	}
}

/**
 * Assert (for tests / callers) that a projection carries no PII/internal fields.
 *
 * @param {object} projection A result of projectOpenData().
 * @return {boolean} True when the projection is clean.
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 */
export function isClean(projection) {
	if (!projection || typeof projection !== 'object') {
		return false
	}
	return Object.keys(projection).every((key) => !isStripped(key) && !key.startsWith('@self'))
}
