<?php

/**
 * Tests for the organisation link command.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Command
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Command;

use OCA\Stackiq\Command\OrganisationLinkCommand;
use OCA\Stackiq\Service\OrganizationSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command passes both uuids on and reports the outcome.
 *
 * @covers \OCA\Stackiq\Command\OrganisationLinkCommand
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */
class OrganisationLinkCommandTest extends TestCase {

	/**
	 * Each outcome, its exit code and what the console says.
	 *
	 * @return array<string, array{0: string|RuntimeException, 1: int, 2: string}>
	 */
	public static function outcomes(): array {
		return [
			'linked'              => ['linked', 0, 'Linked.'],
			'no organisatie'      => ['no-organisatie', 1, 'No organisatie has this uuid.'],
			'already linked'      => ['already-linked', 1, 'already has an OpenRegister organisation'],
			'no organisation'     => ['no-organisation', 1, 'No OpenRegister organisation has this uuid.'],
			'organisation in use' => ['organisation-in-use', 1, 'belongs to another organisatie'],
			'failure'             => [new RuntimeException('db down'), 1, 'Could not link: db down'],
		];
	}//end outcomes()

	/**
	 * The outcome is reported with its exit code, for the uuids given.
	 *
	 * @param string|RuntimeException $outcome What the sync service answers.
	 * @param int                     $code    The exit code.
	 * @param string                  $text    Text the console shows.
	 *
	 * @return void
	 *
	 * @dataProvider outcomes
	 */
	public function testTheOutcomeIsReported(string|RuntimeException $outcome, int $code, string $text): void {
		$sync = $this->createMock(OrganizationSyncService::class);
		$call = $sync->expects(self::once())->method('linkOrganisationEntity')->with('org-uuid', 'or-uuid');
		if ($outcome instanceof RuntimeException) {
			$call->willThrowException($outcome);
		} else {
			$call->willReturn($outcome);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(OrganizationSyncService::class)->willReturn($sync);

		$tester = new CommandTester(new OrganisationLinkCommand($container));
		$tester->execute(['organisatie' => 'org-uuid', 'organisation' => 'or-uuid']);

		self::assertSame($code, $tester->getStatusCode());
		self::assertStringContainsString($text, $tester->getDisplay());
	}//end testTheOutcomeIsReported()
}//end class
