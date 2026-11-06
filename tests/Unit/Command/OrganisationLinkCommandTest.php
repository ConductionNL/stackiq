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
 * The command checks the link, says what keeps the old uuid, asks for
 * confirmation, and reports the outcome.
 *
 * @covers \OCA\Stackiq\Command\OrganisationLinkCommand
 *
 * @spec openspec/specs/organisatie-service/spec.md
 */
class OrganisationLinkCommandTest extends TestCase {

	/**
	 * Each refusal the check gives, and what the console says.
	 *
	 * @return array<string, array{0: string|RuntimeException, 1: string}>
	 */
	public static function refusals(): array {
		return [
			'no organisatie'       => ['no-organisatie', 'No organisatie has this uuid.'],
			'already linked'       => ['already-linked', 'already has an OpenRegister organisation'],
			'no organisation'      => ['no-organisation', 'No OpenRegister organisation has this uuid.'],
			'organisation in use'  => ['organisation-in-use', 'belongs to another organisatie'],
			'default organisation' => ['default-organisation', 'default organisation, which is never linked'],
			'default unknown'      => ['default-unknown', 'cannot say which organisation is its default'],
			'failure'              => [new RuntimeException('db down'), 'Could not link: db down'],
		];
	}//end refusals()

	/**
	 * A refused link exits 1, asks nothing and never links.
	 *
	 * @param string|RuntimeException $refusal What the check answers.
	 * @param string                  $text    Text the console shows.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testARefusedLinkIsNeverMade(string|RuntimeException $refusal, string $text): void {
		$sync  = $this->createMock(OrganizationSyncService::class);
		$check = $sync->expects(self::once())->method('checkOrganisationLink')->with('org-uuid', 'or-uuid');
		if ($refusal instanceof RuntimeException) {
			$check->willThrowException($refusal);
		} else {
			$check->willReturn($refusal);
		}

		$sync->expects(self::never())->method('linkOrganisationEntity');

		$tester = $this->tester(sync: $sync);
		$tester->execute(['organisatie' => 'org-uuid', 'organisation' => 'or-uuid', '--yes' => true]);

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString($text, $tester->getDisplay());
		self::assertStringNotContainsString('keep the old uuid', $tester->getDisplay());
	}//end testARefusedLinkIsNeverMade()

	/**
	 * How the administrator answers, and whether the link is made.
	 *
	 * @return array<string, array{0: array, 1: array<int, string>, 2: bool, 3: string}>
	 */
	public static function answers(): array {
		return [
			'--yes'                => [['--yes' => true], [], true, 'Linked.'],
			'confirmed'            => [[], ['y'], true, 'Linked.'],
			'declined'             => [[], ['n'], false, 'Not linked.'],
			'no answer'            => [[], [''], false, 'Not linked.'],
		];
	}//end answers()

	/**
	 * An allowed link names what keeps the old uuid and is made only once confirmed.
	 *
	 * @param array              $options The command options.
	 * @param array<int, string> $inputs  What the administrator types.
	 * @param bool               $linked  Whether the link is made.
	 * @param string             $text    Text the console shows.
	 *
	 * @return void
	 *
	 * @dataProvider answers
	 */
	public function testAnAllowedLinkWarnsAndAsks(array $options, array $inputs, bool $linked, string $text): void {
		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->method('checkOrganisationLink')->with('org-uuid', 'or-uuid')->willReturn(null);
		$link = $sync->expects($linked === true ? self::once() : self::never())->method('linkOrganisationEntity');
		$link->with('org-uuid', 'or-uuid')->willReturn('linked');

		$tester = $this->tester(sync: $sync);
		$tester->setInputs($inputs);
		$tester->execute(array_merge(['organisatie' => 'org-uuid', 'organisation' => 'or-uuid'], $options));

		$display = $tester->getDisplay();
		self::assertSame($linked === true ? 0 : 1, $tester->getStatusCode());
		self::assertStringContainsString($text, $display);
		self::assertStringContainsString('The OpenRegister organisation or-uuid will take the uuid org-uuid.', $display);
		foreach (['@self.organisation', 'active organisation of its members', 'parent of its child organisations', 'other apps'] as $reference) {
			self::assertStringContainsString($reference, $display);
		}
	}//end testAnAllowedLinkWarnsAndAsks()

	/**
	 * Without a terminal and without --yes nothing is linked.
	 *
	 * @return void
	 */
	public function testANonInteractiveRunNeedsYes(): void {
		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->method('checkOrganisationLink')->willReturn(null);
		$sync->expects(self::never())->method('linkOrganisationEntity');

		$tester = $this->tester(sync: $sync);
		$tester->execute(['organisatie' => 'org-uuid', 'organisation' => 'or-uuid'], ['interactive' => false]);

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('run again with --yes', $tester->getDisplay());
	}//end testANonInteractiveRunNeedsYes()

	/**
	 * A failing link after confirmation exits 1.
	 *
	 * @return void
	 */
	public function testAFailingLinkIsReported(): void {
		$sync = $this->createMock(OrganizationSyncService::class);
		$sync->method('checkOrganisationLink')->willReturn(null);
		$sync->method('linkOrganisationEntity')->willThrowException(new RuntimeException('db down'));

		$tester = $this->tester(sync: $sync);
		$tester->execute(['organisatie' => 'org-uuid', 'organisation' => 'or-uuid', '--yes' => true]);

		self::assertSame(1, $tester->getStatusCode());
		self::assertStringContainsString('Could not link: db down', $tester->getDisplay());
	}//end testAFailingLinkIsReported()

	/**
	 * The command over a container that answers the sync service.
	 *
	 * @param OrganizationSyncService $sync The sync service double.
	 *
	 * @return CommandTester
	 */
	private function tester(OrganizationSyncService $sync): CommandTester {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(OrganizationSyncService::class)->willReturn($sync);
		return new CommandTester(new OrganisationLinkCommand($container));
	}//end tester()
}//end class
