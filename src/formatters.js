// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// App cell formatters, handed to CnAppRoot as `formatters` and resolved by
// name from a manifest column's `formatter`. CnAppRoot spreads these OVER the
// library built-ins, so a name here must never equal a built-in's name:
// tests/vitest/connectionRegistry.spec.js holds that.

import { friaStatus } from './utils/aiAct.js'

export default {
	// The FRIA column of the AI systems list (landscape-ai-system-inventory).
	friaStatus,
}
