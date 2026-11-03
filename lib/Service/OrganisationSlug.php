<?php

/**
 * The slug OpenRegister gives an organisation name.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

/**
 * Mirrors OpenRegister's OrganisationService::generateSlug(): lower case, runs of
 * other characters as one hyphen, no hyphen at either end, at most 100 characters.
 * It also names OpenRegister's exception for a taken slug.
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */
class OrganisationSlug {

	/**
	 * OpenRegister's exception for a new organisation whose slug is taken (openregister#5462).
	 * Named as a string: older OpenRegister releases do not have the class.
	 */
	public const CONFLICT_EXCEPTION = 'OCA\\OpenRegister\\Exception\\OrganisationSlugConflictException';

	/**
	 * OpenRegister keeps the first 100 characters of a slug.
	 */
	private const MAX_LENGTH = 100;

	/**
	 * The slug of an organisation name.
	 *
	 * @param string $name The organisation name.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/specs/organisatie-service/spec.md
	 */
	public function fromName(string $name): string {
		$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
		return substr($slug, 0, self::MAX_LENGTH);
	}//end fromName()
}//end class
