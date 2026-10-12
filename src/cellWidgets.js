// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// App cell widgets, handed to CnAppRoot as `cellWidgets` and resolved by id
// from a manifest column's `widget`. The library's own widgets (badge,
// fkResolve, link, avatar, date) are handled before this registry, so an id
// here must not equal one of theirs.

import ContactNameCell from './components/cells/ContactNameCell.vue'

export default {
	// The owner column of the usage list (landscape-usage-registration).
	contactName: ContactNameCell,
}
