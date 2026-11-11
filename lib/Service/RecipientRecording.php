<?php

/**
 * Recipient recording
 *
 * One maintenance window whose owners MaintenanceRecipientService records,
 * built and read only by that service.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * A maintenance window, its data, the product it names and the object service to write it with.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-003-the-owners-of-every-usage-are-notified
 */
final class RecipientRecording {

	/**
	 * The maintenance window.
	 *
	 * @var ObjectEntityInterface
	 */
	public ObjectEntityInterface $window;

	/**
	 * The window's data.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * The id of the product the window is for.
	 *
	 * @var string
	 */
	public string $moduleId = '';

	/**
	 * OpenRegister's object service.
	 *
	 * @var ObjectServiceInterface
	 */
	public ObjectServiceInterface $objectService;
}//end class
