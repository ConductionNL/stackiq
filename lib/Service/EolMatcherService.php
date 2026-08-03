<?php
/**
 * EOL Matcher Service.
 *
 * Pure matching/stamping logic for `eol-feed-integration`. Compares a
 * `moduleVersie.versie` string against the `cycle` values of a mapped
 * module's `eolCycle` rows (already read from OpenRegister by the caller —
 * this service performs no I/O of its own, OCP or otherwise) and decides,
 * conservatively, whether to stamp `datumEindeOndersteuning` from the
 * matched cycle. A stamp is only produced on an unambiguous single-candidate
 * match at the most-specific matching level; ties and no-matches are left
 * untouched. Every stamp carries the complete `moduleVersie` object forward
 * (OpenRegister's `saveObject` is PUT-semantic — omitted properties would be
 * nulled), plus the two provenance fields.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-version-matching-is-conservative-and-unambiguous-only
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-stamping-preserves-every-other-field-and-records-provenance
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

/**
 * Conservative version-prefix matcher between `moduleVersie.versie` and
 * `eolCycle.cycle` values, plus PUT-semantic stamp construction.
 *
 * Deliberately has zero OCP/OpenRegister dependencies: every method takes
 * and returns plain PHP arrays/scalars so it is unit-testable with fixture
 * cycle arrays alone (design.md "Nextcloud Integration" — "pure matching +
 * stamping logic, unit-testable with fixture cycle arrays and no OCP
 * dependencies").
 */
class EolMatcherService
{
    /**
     * Match a module version string against a set of candidate EOL cycles.
     *
     * Splits `$versie` and each candidate `cycle` value on `.` and treats a
     * cycle as matching when one of the two segment lists is a prefix of the
     * other (either direction — a longer `versie` matching a shorter, more
     * general `cycle`, or a shorter `versie` matching a longer, more
     * specific `cycle`). The match "depth" is the number of matching leading
     * segments (i.e. the length of the shorter of the two lists when every
     * segment up to that length is equal). Only the candidate(s) at the
     * greatest depth are considered; a stamp is produced only when exactly
     * one candidate remains at that depth.
     *
     * @param string $versie The `moduleVersie.versie` string to match.
     * @param array  $cycles The mapped module's `eolCycle` rows (each an
     *                       array with at least a `cycle` key).
     *
     * @return array|null The single unambiguous matching cycle row, or null
     *                     when zero or more than one candidate share the
     *                     greatest match depth.
     *
     * @spec openspec/specs/eol-feed-integration/spec.md#requirement-version-matching-is-conservative-and-unambiguous-only
     */
    public function matchVersion(string $versie, array $cycles): ?array
    {
        $versie = trim($versie);
        if ($versie === '') {
            return null;
        }

        $bestDepth  = 0;
        $candidates = [];

        foreach ($cycles as $cycle) {
            $cycleValue = trim((string) ($cycle['cycle'] ?? ''));
            if ($cycleValue === '') {
                continue;
            }

            $depth = $this->matchDepth(versie: $versie, cycle: $cycleValue);
            if ($depth === null) {
                continue;
            }

            if ($depth > $bestDepth) {
                $bestDepth  = $depth;
                $candidates = [$cycle];
            } else if ($depth === $bestDepth) {
                $candidates[] = $cycle;
            }
        }//end foreach

        if ($bestDepth === 0 || count($candidates) !== 1) {
            // Zero candidates, or an ambiguous tie at the most-specific
            // level — never guess (design.md Decision 2).
            return null;
        }

        return $candidates[0];
    }//end matchVersion()

    /**
     * Compute the number of matching leading dot-separated segments between
     * a module version string and a candidate cycle label.
     *
     * Returns null when the two diverge at any shared segment index (no
     * match at all), otherwise the count of segments compared — which is
     * the length of whichever of the two segment lists is shorter, since a
     * match requires every one of the shorter list's segments to equal the
     * corresponding segment of the longer list.
     *
     * @param string $versie The module version string.
     * @param string $cycle  The candidate cycle label.
     *
     * @return int|null The match depth, or null when the two do not match.
     */
    private function matchDepth(string $versie, string $cycle): ?int
    {
        $versieSegments = explode('.', $versie);
        $cycleSegments  = explode('.', $cycle);

        // Explode() always yields at least one segment, so $depth >= 1.
        $depth = min(count($versieSegments), count($cycleSegments));

        for ($i = 0; $i < $depth; $i++) {
            if ($versieSegments[$i] !== $cycleSegments[$i]) {
                return null;
            }
        }

        return $depth;
    }//end matchDepth()

    /**
     * Build the complete, PUT-semantic replacement object for a matched
     * `moduleVersie`.
     *
     * Copies every existing field on `$moduleVersie` forward unchanged and
     * only adds/overwrites `datumEindeOndersteuning`, `eolBron`, and
     * `eolBijgewerktOp` — OpenRegister's `saveObject` nulls any property
     * omitted from the payload, so the full object must always be the base.
     *
     * @param array  $moduleVersie The complete current `moduleVersie` object.
     * @param array  $matchedCycle The matched `eolCycle` row (must carry an
     *                             `eol` date string).
     * @param string $source       The provenance source identifier (e.g.
     *                             `endoflife.date`).
     * @param string $fetchedAt    The sync run's timestamp (ISO 8601).
     *
     * @return array The complete `moduleVersie` object with the stamp
     *               applied, ready to pass to `ObjectService::saveObject()`.
     *
     * @spec openspec/specs/eol-feed-integration/spec.md#requirement-stamping-preserves-every-other-field-and-records-provenance
     */
    public function buildStamp(array $moduleVersie, array $matchedCycle, string $source, string $fetchedAt): array
    {
        $stamped = $moduleVersie;
        $stamped['datumEindeOndersteuning'] = (string) ($matchedCycle['eol'] ?? '');
        $stamped['eolBron']         = $source;
        $stamped['eolBijgewerktOp'] = $fetchedAt;

        return $stamped;
    }//end buildStamp()

    /**
     * Match every `moduleVersie` of one module against its mapped cycles and
     * build the stamps for the unambiguous matches.
     *
     * A `moduleVersie` is skipped (left untouched) when: its `versie` is
     * empty, no cycle matches unambiguously, or the matched cycle's `eol`
     * value is empty (endoflife.date reports no scheduled EOL date yet for
     * that cycle — nothing informative to stamp).
     *
     * @param array  $moduleVersies The module's `moduleVersie` rows.
     * @param array  $cycles        The mapped module's `eolCycle` rows.
     * @param string $source        The provenance source identifier.
     * @param string $fetchedAt     The sync run's timestamp (ISO 8601).
     *
     * @return array{stamped: array, skipped: array} `stamped` holds the
     *                complete replacement objects ready to save; `skipped`
     *                holds the original, untouched `moduleVersie` rows.
     *
     * @spec openspec/specs/eol-feed-integration/spec.md#requirement-version-matching-is-conservative-and-unambiguous-only
     * @spec openspec/specs/eol-feed-integration/spec.md#requirement-stamping-preserves-every-other-field-and-records-provenance
     */
    public function matchModuleVersions(array $moduleVersies, array $cycles, string $source, string $fetchedAt): array
    {
        $stamped = [];
        $skipped = [];

        foreach ($moduleVersies as $moduleVersie) {
            $versie = (string) ($moduleVersie['versie'] ?? '');

            $matchedCycle = null;
            if ($versie !== '') {
                $matchedCycle = $this->matchVersion(versie: $versie, cycles: $cycles);
            }

            if ($matchedCycle === null || trim((string) ($matchedCycle['eol'] ?? '')) === '') {
                $skipped[] = $moduleVersie;
                continue;
            }

            $stamped[] = $this->buildStamp(
                moduleVersie: $moduleVersie,
                matchedCycle: $matchedCycle,
                source: $source,
                fetchedAt: $fetchedAt
            );
        }//end foreach

        return [
            'stamped' => $stamped,
            'skipped' => $skipped,
        ];
    }//end matchModuleVersions()
}//end class
