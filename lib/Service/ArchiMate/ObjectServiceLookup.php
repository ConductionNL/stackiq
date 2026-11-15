<?php

/**
 * Looks up OpenRegister's object service for the ArchiMate services.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\ArchiMate
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

namespace OCA\Stackiq\Service\ArchiMate;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister's object service, or null when OpenRegister is missing or the service cannot be resolved.
 *
 * @spec openspec/specs/archimate-import/spec.md
 */
final class ObjectServiceLookup {
	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Tells whether OpenRegister is installed.
	 * @param ContainerInterface $container  Holds the object service.
	 * @param LoggerInterface    $logger     Logs a failed lookup.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get ObjectService from container.
	 *
	 * @return ObjectServiceInterface|null ObjectService instance or null if not available
	 *
	 * @spec openspec/specs/archimate-import/spec.md
	 */
	public function find(): ?ObjectServiceInterface {
		if ($this->appManager->isInstalled(appId: 'openregister') === false) {
			return null;
		}

		try {
			return $this->container->get(ObjectService::class);
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to get ObjectService',
				[
					'error' => $e->getMessage(),
				]
			);
			return null;
		}
	}//end find()
}//end class
