<?php

/**
 * Profile Field Mapper for SoftwareCatalog
 *
 * Extracts field-mapping logic from UserProfileUpdatedEventListener to reduce
 * CyclomaticComplexity and ExcessiveMethodLength on the event listener.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service;

/**
 * Maps Nextcloud user profile field keys to SoftwareCatalog contactpersoon field names.
 *
 * Used by UserProfileUpdatedEventListener to delegate field-name resolution
 * without bloating the event-handler method.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-8
 */
class ProfileFieldMapper
{

    /**
     * Nextcloud profile key → contactpersoon object field mapping.
     *
     * @var array<string,string>
     */
    private const FIELD_MAP = [
        'email'        => 'e-mailadres',
        'displayname'  => 'naam',
        'phone'        => 'telefoonnummer',
        'address'      => 'adres',
        'website'      => 'website',
        'twitter'      => 'twitter',
        'organisation' => 'organisatie',
        'role'         => 'functie',
        'headline'     => 'aanhef',
        'biography'    => 'biografie',
    ];

    /**
     * Map a Nextcloud profile field key to the corresponding contactpersoon field name.
     *
     * @param string $ncKey The Nextcloud profile field key.
     *
     * @return string|null The contactpersoon field name, or null if not mapped.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-8
     */
    public function mapToContactField(string $ncKey): ?string
    {
        return self::FIELD_MAP[$ncKey] ?? null;

    }//end mapToContactField()

    /**
     * Build a contactpersoon update payload from a profile-change event payload.
     *
     * @param array<string,mixed> $profileData The profile-field data from the event.
     *
     * @return array<string,mixed> The contactpersoon fields to update (may be empty).
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-8
     */
    public function buildContactPayload(array $profileData): array
    {
        $payload = [];
        foreach ($profileData as $ncKey => $value) {
            $contactField = $this->mapToContactField(ncKey: $ncKey);
            if ($contactField !== null) {
                $payload[$contactField] = $value;
            }
        }

        return $payload;

    }//end buildContactPayload()

    /**
     * Return the full field map for inspection or testing.
     *
     * @return array<string,string> Map of Nextcloud keys to contactpersoon fields.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-8
     */
    public function getFieldMap(): array
    {
        return self::FIELD_MAP;

    }//end getFieldMap()
}//end class
