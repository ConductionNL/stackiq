<?php

/**
 * Sets the provider of the usages saved before it followed their application.
 *
 * @category  Repair
 * @package   OCA\Stackiq\Repair
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
 */

declare(strict_types=1);

namespace OCA\Stackiq\Repair;

use OCA\Stackiq\Service\UsageProviderSync;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Usages a municipality added on the usage form before UsageProviderListener
 * carry no provider, so their supplier does not see them. Idempotent; never throws.
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
 */
class BackfillUsageProvider implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param UsageProviderSync $sync   The provider writer.
	 * @param LoggerInterface   $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly UsageProviderSync $sync,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by `occ maintenance:repair`.
	 *
	 * @return string
	 *
	 * @spec exclude repair step plumbing.
	 */
	public function getName(): string {
		return 'Show suppliers the usages of their applications';
	}//end getName()

	/**
	 * Set the provider on every usage whose provider is not its application's supplier.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
	 */
	public function run(IOutput $output): void {
		try {
			$written = $this->sync->backfill();
		} catch (Throwable $exception) {
			$this->logger->warning('BackfillUsageProvider: nothing done', ['exception' => $exception->getMessage()]);
			$output->info('BackfillUsageProvider: could not read the usages; nothing done.');
			return;
		}

		$output->info(sprintf('BackfillUsageProvider: %d usage(s) now carry their application\'s supplier.', $written));
	}//end run()
}//end class
