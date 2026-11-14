<?php

/**
 * How an organisation name becomes a Nextcloud group name.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Stackiq
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Stackiq;

use OCA\Stackiq\Service\Stackiq\OrganizationHandler;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Stackiq\Service\Stackiq\OrganizationHandler
 */
class OrganizationHandlerGroupNameTest extends TestCase {

	/**
	 * The handler over mocked Nextcloud services.
	 *
	 * @return OrganizationHandler
	 */
	private function handler(): OrganizationHandler {
		return new OrganizationHandler(
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end handler()

	/**
	 * A name is lowercased, other characters become one underscore, and the edges are trimmed.
	 *
	 * @return void
	 */
	public function testANameIsSanitized(): void {
		self::assertSame('gemeente_t_veld', $this->handler()->sanitizeGroupName("  Gemeente 't Veld!! "));
	}//end testANameIsSanitized()

	/**
	 * A name with nothing usable left becomes a timestamped organisation group.
	 *
	 * @return void
	 */
	public function testANameWithNothingUsableGetsATimestampedName(): void {
		$before = time();
		$name = $this->handler()->sanitizeGroupName('!!! ???');
		$after = time();

		self::assertSame(1, preg_match('/^organization_(\d+)$/', $name, $match));
		self::assertGreaterThanOrEqual($before, (int) $match[1]);
		self::assertLessThanOrEqual($after, (int) $match[1]);
	}//end testANameWithNothingUsableGetsATimestampedName()
}//end class
