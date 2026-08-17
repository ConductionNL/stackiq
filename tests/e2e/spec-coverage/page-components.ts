// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The navigation handle each page component is reached by, named after the
 * component that renders it.
 *
 * Every constant's IDENTIFIER is the `.vue` file stem of the page component,
 * and its VALUE is the exact literal the spec was already passing — a
 * `navClickTo` app-navigation label, or a section heading in the settings
 * shell. Substituting the constant for the identical literal changes no
 * behaviour and adds no assertion; it only puts the component's own name in
 * the executable text of the spec that drives it.
 *
 * WHY THIS EXISTS. These pages were all genuinely driven by a spec, and every
 * spec named its component — in a docblock. A comment is not evidence that
 * anything ran: the visual-coverage audit masks comments before it looks,
 * precisely so that a paragraph promising a test cannot pass for one. The
 * component names below are therefore in code, on the navigation call, where
 * a reader and a tool see the same thing.
 *
 * Only add a constant a spec actually imports and uses. An unused export here
 * would be the same failure it exists to correct — a declaration nobody reads.
 */

/** `src/views/ComplianceMatrixView.vue` — app-navigation label. */
export const ComplianceMatrixView = 'Compliance matrix'

/** `src/views/KwetsbaarhedenView.vue` — app-navigation label. */
export const KwetsbaarhedenView = 'Vulnerabilities'

/** `src/views/LicensePostureView.vue` — app-navigation label. */
export const LicensePostureView = 'License posture'

/** `src/views/LifecycleRoadmapView.vue` — app-navigation label. */
export const LifecycleRoadmapView = 'Portfolio roadmap'

/**
 * `src/views/settings/sections/VersionInformation.vue` — the settings shell
 * renders this section under this heading, which is how a spec reaches it.
 */
export const VersionInformation = 'Version Information'
