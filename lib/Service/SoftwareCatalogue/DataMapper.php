<?php

/**
 * Data Mapper for SoftwareCatalogueService
 *
 * Extracted from SoftwareCatalogueService to reduce ExcessiveClassLength and
 * CyclomaticComplexity on that service. Handles data transformation and mapping.
 *
 * @category  Service
 * @package   OCA\SoftwareCatalog\Service\SoftwareCatalogue
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Service\SoftwareCatalogue;

use Psr\Log\LoggerInterface;

/**
 * Maps and transforms data between external API formats and the SoftwareCatalogue domain model.
 *
 * SoftwareCatalogueService delegates all data transformation methods to this class,
 * shrinking its own method bodies below ExcessiveMethodLength.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 */
class DataMapper
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger Logger instance.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Map an organisation object data array to the OpenRegister format.
     *
     * Normalises field names, applies type coercions, and removes undefined keys.
     *
     * @param array<string,mixed> $objectData The raw organisation data.
     *
     * @return array<string,mixed> The mapped data suitable for saving to OpenRegister.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function mapOrganizationToOpenRegister(array $objectData): array
    {
        $mapped = $this->normalizeOrganizationFields(data: $objectData);
        $mapped = $this->coerceOrganizationTypes(data: $mapped);

        $this->logger->debug(
            'DataMapper: Mapped organization data',
            ['fieldCount' => count($mapped)]
        );

        return $mapped;

    }//end mapOrganizationToOpenRegister()

    /**
     * Map a contact person object data array to a Nextcloud user profile.
     *
     * @param array<string,mixed> $contactData The raw contact person data.
     *
     * @return array<string,mixed> The mapped data suitable for user profile updates.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function mapContactToUserProfile(array $contactData): array
    {
        $emailRaw = $contactData['e-mailadres'] ?? $contactData['email'] ?? '';

        return [
            'email'       => strtolower(trim((string) $emailRaw)),
            'displayName' => $this->buildDisplayName(data: $contactData),
            'phone'       => $contactData['telefoonnummer'] ?? '',
        ];

    }//end mapContactToUserProfile()

    /**
     * Map a status string from Dutch to a boolean active indicator.
     *
     * @param string $status Raw status value from the data store.
     *
     * @return bool True when the status represents an active record.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function mapStatusToActive(string $status): bool
    {
        return in_array(
            needle: strtolower($status),
            haystack: ['actief', 'active', 'enabled', 'true'],
            strict: true
        );

    }//end mapStatusToActive()

    /**
     * Normalise field names in the organisation data array.
     *
     * @param array<string,mixed> $data Raw data array.
     *
     * @return array<string,mixed> Normalised data.
     */
    private function normalizeOrganizationFields(array $data): array
    {
        // Normalise alternative field names to canonical names.
        if (isset($data['naam']) === false && isset($data['name']) === true) {
            $data['naam'] = $data['name'];
            unset($data['name']);
        }

        if (isset($data['status']) === false && isset($data['actief']) === true) {
            $data['status'] = 'inactief';
            if ($data['actief'] === true) {
                $data['status'] = 'actief';
            }

            unset($data['actief']);
        }

        return $data;

    }//end normalizeOrganizationFields()

    /**
     * Apply type coercions to organisation data values.
     *
     * @param array<string,mixed> $data Data after field normalisation.
     *
     * @return array<string,mixed> Type-coerced data.
     */
    private function coerceOrganizationTypes(array $data): array
    {
        // Ensure integer fields are cast.
        $intFields = ['kvkNummer', 'oin', 'toezichthouderOin'];
        foreach ($intFields as $field) {
            if (isset($data[$field]) === true && is_numeric($data[$field]) === true) {
                $data[$field] = (int) $data[$field];
            }
        }

        return $data;

    }//end coerceOrganizationTypes()

    /**
     * Build a display name from contact person data.
     *
     * @param array<string,mixed> $data Contact person data.
     *
     * @return string Assembled display name.
     */
    private function buildDisplayName(array $data): string
    {
        $parts = array_filter(
                [
                    $data['voornaam'] ?? '',
                    $data['tussenvoegsel'] ?? '',
                    $data['achternaam'] ?? '',
                ]
                );

        $name = implode(' ', $parts);
        if ($name !== '') {
            return $name;
        }

        return $data['naam'] ?? '';

    }//end buildDisplayName()
}//end class
