<?php

/**
 * Confirm that an application or a usage is current.
 *
 * Saves lastConfirmedAt as the server's now, through OpenRegister with RBAC on,
 * so only a user who may update the entry can confirm it. The save makes
 * OpenRegister rescore the entry, and its freshness rule goes back to full.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service;

use DateTimeInterface;
use OCA\Stackiq\Kernel\Contracts\EntryConfirmationServiceInterface;
use OCA\Stackiq\Service\Settings\CatalogueLocator;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sets lastConfirmedAt on one module or usage.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
 */
class EntryConfirmationService implements EntryConfirmationServiceInterface {
	/**
	 * The kinds of entry that can be confirmed, with the config key of their schema.
	 */
	public const KINDS = [
		'module' => 'module_schema',
		'usage' => 'gebruik_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param CatalogueLocator   $catalogue  The configured register and schemas.
	 * @param IAppManager        $appManager Whether OpenRegister is installed.
	 * @param ContainerInterface $container  Server container, for OpenRegister's ObjectService.
	 * @param ITimeFactory       $time       The clock.
	 * @param IL10N              $l10n       Localisation.
	 * @param LoggerInterface    $logger     Logger.
	 *
	 * @spec exclude pure framework plumbing: constructor dependency injection.
	 */
	public function __construct(
		private readonly CatalogueLocator $catalogue,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly ITimeFactory $time,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record that the entry is current: set its lastConfirmedAt to now, and nothing else.
	 *
	 * @param string $kind The kind of entry: module or usage.
	 * @param string $id   The entry's id.
	 *
	 * @return array{lastConfirmedAt: string} The moment stored.
	 *
	 * @throws EntryConfirmationRefused 404 for an unknown kind or an unreadable entry, 403 without the update right,
	 *                                  503 without OpenRegister or its configuration, 500 when the save fails.
	 *
	 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
	 */
	public function confirm(string $kind, string $id): array {
		$source = $this->source(kind: $kind);
		$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

		$entry = $this->find(objectService: $objectService, source: $source, id: $id);

		$record = $entry->getObject();
		if (is_array($record) === false) {
			$record = [];
		}

		unset($record['@self'], $record['id']);
		$now = $this->time->getDateTime()->format(DateTimeInterface::ATOM);
		$record['lastConfirmedAt'] = $now;

		try {
			$objectService->saveObject(
				object: $record,
				extend: [],
				register: $source['register'],
				schema: $source['schema'],
				uuid: $id,
				_rbac: true
			);
		} catch (\Throwable $e) {
			throw $this->refusalFor(e: $e, notAllowed: true);
		}

		return ['lastConfirmedAt' => $now];
	}//end confirm()

	/**
	 * The entry, read with RBAC on.
	 *
	 * @param object                             $objectService OpenRegister's ObjectService.
	 * @param array{register: int, schema: int} $source        The register and schema.
	 * @param string                             $id            The entry's id.
	 *
	 * @return object The entry.
	 *
	 * @throws EntryConfirmationRefused 404 when it is not there or not readable.
	 */
	private function find(object $objectService, array $source, string $id): object {
		try {
			$entry = $objectService->find(
				id: $id,
				register: $source['register'],
				schema: $source['schema'],
				_rbac: true
			);
		} catch (\Throwable $e) {
			throw $this->refusalFor(e: $e, notAllowed: false);
		}

		if (is_object($entry) === false) {
			throw new EntryConfirmationRefused(reason: 'not-found', status: 404);
		}

		return $entry;
	}//end find()

	/**
	 * The register and schema of a kind of entry.
	 *
	 * @param string $kind module or usage.
	 *
	 * @return array{register: int, schema: int} The ids.
	 *
	 * @throws EntryConfirmationRefused 404 for an unknown kind, 503 without OpenRegister or its configuration.
	 */
	private function source(string $kind): array {
		if (array_key_exists($kind, self::KINDS) === false) {
			throw new EntryConfirmationRefused(reason: 'not-found', status: 404);
		}

		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new EntryConfirmationRefused(reason: 'openregister-missing', status: 503);
		}

		$config   = $this->catalogue->getVoorzieningenConfig();
		$register = (int)($config['register'] ?? 0);
		$schema   = (int)($config[self::KINDS[$kind]] ?? 0);
		if ($register === 0 || $schema === 0) {
			throw new EntryConfirmationRefused(reason: 'not-configured', status: 503);
		}

		return ['register' => $register, 'schema' => $schema];
	}//end source()

	/**
	 * The refusal for an OpenRegister failure.
	 *
	 * A missing or unreadable entry is 404, so an id the user may not read
	 * does not confirm that it exists. A save the user may not make is 403.
	 *
	 * @param \Throwable $e          The failure.
	 * @param bool       $notAllowed Whether an authorisation failure means 403 (a save) rather than 404 (a read).
	 *
	 * @return EntryConfirmationRefused The refusal.
	 */
	private function refusalFor(\Throwable $e, bool $notAllowed): EntryConfirmationRefused {
		$class = get_class($e);
		if (str_ends_with($class, 'DoesNotExistException') === true) {
			return new EntryConfirmationRefused(reason: 'not-found', status: 404);
		}

		if (str_ends_with($class, 'NotAuthorizedException') === true || str_ends_with($class, 'ForbiddenException') === true) {
			if ($notAllowed === true) {
				return new EntryConfirmationRefused(
					reason: 'forbidden',
					status: 403,
					message: $this->l10n->t('You may not change this entry.')
				);
			}

			return new EntryConfirmationRefused(reason: 'not-found', status: 404);
		}

		$this->logger->warning('[EntryConfirmationService] The entry could not be confirmed: ' . $e->getMessage());
		return new EntryConfirmationRefused(
			reason: 'not-stored',
			status: 500,
			message: $this->l10n->t('The confirmation could not be stored.')
		);
	}//end refusalFor()
}//end class
