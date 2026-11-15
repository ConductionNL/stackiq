<?php

/**
 * Profile contact sync run.
 *
 * The state of one sync of a user profile update onto its contactpersoon,
 * built and read only by UserProfileUpdatedEventListener.
 *
 * @category  EventListener
 * @package   OCA\Stackiq\EventListener
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\EventListener;

use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use Psr\Log\LoggerInterface;

/**
 * One profile-to-contactpersoon sync: the update, where its contactpersoon lives and what is patched.
 *
 * @category EventListener
 * @package  OCA\Stackiq\EventListener
 */
final class ProfileContactSync {

	/**
	 * The profile update being synced.
	 *
	 * @var UserProfileUpdatedEvent
	 */
	public UserProfileUpdatedEvent $event;

	/**
	 * The logger of the sync.
	 *
	 * @var LoggerInterface
	 */
	public LoggerInterface $logger;

	/**
	 * The Nextcloud user id of the update.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * The voorzieningen configuration, with the register and contactpersoon schema.
	 *
	 * @var array<string, mixed>
	 */
	public array $voorzieningenConfig = [];

	/**
	 * The current data of the contactpersoon found.
	 *
	 * @var array<string, mixed>
	 */
	public array $contactData = [];

	/**
	 * The fields to patch onto the contactpersoon.
	 *
	 * @var array<string, mixed>
	 */
	public array $patch = [];

	/**
	 * The configured register.
	 *
	 * @return mixed The register, or '' when not configured.
	 */
	public function register(): mixed {
		return ($this->voorzieningenConfig['register'] ?? '');
	}//end register()

	/**
	 * The configured contactpersoon schema.
	 *
	 * @return mixed The schema, or '' when not configured.
	 */
	public function schema(): mixed {
		return ($this->voorzieningenConfig['contactpersoon_schema'] ?? '');
	}//end schema()

	/**
	 * The @self register/schema filter of the contactpersoon searches.
	 *
	 * @return array{register: int, schema: int}
	 */
	public function selfQuery(): array {
		return [
			'register' => (int)$this->register(),
			'schema' => (int)$this->schema(),
		];
	}//end selfQuery()
}//end class
