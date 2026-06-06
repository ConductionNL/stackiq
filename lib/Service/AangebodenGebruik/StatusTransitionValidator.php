<?php

/**
 * Status Transition Validator for AangebodenGebruik
 *
 * Centralises allowed status transitions for AangebodenGebruik objects, extracted
 * from AangebodenGebruikController and AangebodenGebruikService to reduce
 * CyclomaticComplexity on those classes.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\AangebodenGebruik
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\AangebodenGebruik;

/**
 * Validates and documents allowed status transitions for AangebodenGebruik objects.
 *
 * Placed in lib/Service/AangebodenGebruik/ per design.md Q4 so it can be
 * shared between the controller and the service layer.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7
 */
class StatusTransitionValidator
{

    /**
     * Allowed status transitions: current → allowed next statuses.
     *
     * @var array<string,string[]>
     */
    private const TRANSITION_MAP = [
        'aangevraagd' => ['goedgekeurd', 'afgewezen'],
        'goedgekeurd' => ['actief', 'ingetrokken'],
        'actief'      => ['inactief', 'ingetrokken'],
        'inactief'    => ['actief', 'ingetrokken'],
        'afgewezen'   => ['aangevraagd'],
        'ingetrokken' => ['aangevraagd'],
    ];

    /**
     * Check whether a status transition is allowed.
     *
     * @param string $currentStatus The current status of the object.
     * @param string $newStatus     The requested new status.
     *
     * @return bool True when the transition is allowed, false otherwise.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-7
     */
    public function isAllowed(string $currentStatus, string $newStatus): bool
    {
        $allowed = self::TRANSITION_MAP[strtolower($currentStatus)] ?? [];
        return in_array(needle: strtolower($newStatus), haystack: $allowed, strict: true);

    }//end isAllowed()

    /**
     * Return the allowed next statuses for a given current status.
     *
     * @param string $currentStatus The current status.
     *
     * @return string[] Allowed next statuses (empty if current status is unknown).
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-7
     */
    public function getAllowedTransitions(string $currentStatus): array
    {
        return self::TRANSITION_MAP[strtolower($currentStatus)] ?? [];

    }//end getAllowedTransitions()

    /**
     * Return a human-readable error message for a disallowed transition.
     *
     * @param string $currentStatus The current status.
     * @param string $newStatus     The attempted new status.
     *
     * @return string An English-language error message suitable for API responses.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-7
     */
    public function buildErrorMessage(string $currentStatus, string $newStatus): string
    {
        $allowed = $this->getAllowedTransitions(currentStatus: $currentStatus);

        if (empty($allowed) === true) {
            return sprintf(
                'Unknown current status "%s". Cannot transition to "%s".',
                $currentStatus,
                $newStatus
            );
        }

        return sprintf(
            'Transition from "%s" to "%s" is not allowed. Allowed transitions: %s.',
            $currentStatus,
            $newStatus,
            implode(', ', $allowed)
        );

    }//end buildErrorMessage()
}//end class
