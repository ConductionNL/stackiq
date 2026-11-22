// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { translate as t } from '@nextcloud/l10n'
import { isHeldBack } from '../../utils/peerUsage.js'

/**
 * A peer application with its count, or "fewer than 3" when the endpoint
 * holds the count back.
 *
 * @param {object} application A peer application.
 * @return {string} The label.
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
 */
export function peerLabel(application) {
	const name = String(application?.moduleName || t('stackiq', 'Unnamed application'))
	if (isHeldBack(application)) {
		return t('stackiq', '{name} (fewer than {min})', {
			name,
			min: Number(application?.fewerThan || 3),
		})
	}
	return t('stackiq', '{name} ({count})', { name, count: application.count })
}
