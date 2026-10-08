/**
 * seatLabels: the words the seats panel and the Seats section show for a
 * licence metric and a seat state, in the reader's language.
 *
 * @module utils/seatLabels
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 * @spec openspec/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
 */

import { translate as t } from '@nextcloud/l10n'
import { SEAT_STATE } from './licensePosture.js'

/**
 * The translated name of a licence metric value.
 *
 * @param {string} metric A licenceMetric enum value.
 * @return {string} The translated name, or the raw value when unknown.
 * @spec openspec/specs/licence-seats/spec.md#requirement-req-lsc-001-a-contract-shall-record-its-licence-metric-and-the-number-of-licences-bought-and-in-use
 */
export function licenceMetricLabel(metric) {
	const labels = {
		'Per named user': t('stackiq', 'Per named user'),
		'Per concurrent user': t('stackiq', 'Per concurrent user'),
		'Per device': t('stackiq', 'Per device'),
		'Per inhabitant': t('stackiq', 'Per inhabitant'),
		'Per organisation': t('stackiq', 'Per organisation'),
		Other: t('stackiq', 'Other'),
	}
	return labels[metric] ?? metric ?? ''
}

/**
 * The translated seat state of a seatPosition() result.
 *
 * @param {{state: string, over: number}} position A seatPosition() result.
 * @return {string} Within licence, Over licence by N, Not counted or Unknown.
 * @spec openspec/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
 */
export function seatStateLabel(position) {
	switch (position?.state) {
		case SEAT_STATE.WITHIN:
			return t('stackiq', 'Within licence')
		case SEAT_STATE.OVER:
			return t('stackiq', 'Over licence by {count}', {
				count: Number(position.over).toLocaleString(),
			})
		case SEAT_STATE.NOT_COUNTED:
			return t('stackiq', 'Not counted')
		default:
			return t('stackiq', 'Unknown')
	}
}
