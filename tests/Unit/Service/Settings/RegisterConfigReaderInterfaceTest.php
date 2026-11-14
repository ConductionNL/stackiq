<?php

/**
 * Tests for the register configuration reader interface.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service\Settings;

use OCA\Stackiq\BackgroundJob\OrganizationContactSyncJob;
use OCA\Stackiq\Controller\ContactpersonenController;
use OCA\Stackiq\EventListener\AnonymousRegistrationListener;
use OCA\Stackiq\EventListener\ContactPersonOrganisationListener;
use OCA\Stackiq\Repair\BackfillContractApprovalState;
use OCA\Stackiq\Repair\BackfillModuleVersionPublication;
use OCA\Stackiq\Repair\MigrateContactsToNc;
use OCA\Stackiq\Service\Settings\ObjectTypeLocatorInterface;
use OCA\Stackiq\Service\Settings\RegisterConfigReaderInterface;
use OCA\Stackiq\Service\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The settings service is the register configuration reader, and the classes
 * that only read configuration depend on the reader instead of the service.
 *
 * @coversNothing
 *
 * @spec openspec/specs/settings-service/spec.md
 */
class RegisterConfigReaderInterfaceTest extends TestCase {

	/**
	 * The settings service implements the reader and, through it, the object type locator.
	 *
	 * @return void
	 */
	public function testTheSettingsServiceIsTheReader(): void {
		$implemented = class_implements(SettingsService::class);

		self::assertContains(needle: RegisterConfigReaderInterface::class, haystack: $implemented);
		self::assertContains(needle: ObjectTypeLocatorInterface::class, haystack: $implemented);
	}//end testTheSettingsServiceIsTheReader()

	/**
	 * The reader declares exactly the lookups its consumers call.
	 *
	 * @return void
	 */
	public function testTheReaderDeclaresTheConfigurationLookups(): void {
		$methods = array_map(
			static fn ($method) => $method->getName(),
			(new ReflectionClass(RegisterConfigReaderInterface::class))->getMethods()
		);
		sort($methods);

		self::assertSame(
			expected: [
				'getAmefConfig',
				'getObjectService',
				'getRegisterIdForObjectType',
				'getSchemaIdForObjectType',
				'getVoorzieningenConfig',
				'getVoorzieningenRegisterId',
			],
			actual: $methods
		);
	}//end testTheReaderDeclaresTheConfigurationLookups()

	/**
	 * The classes that only read configuration.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function readerConsumers(): array {
		return [
			'OrganizationContactSyncJob'        => [OrganizationContactSyncJob::class],
			'ContactpersonenController'         => [ContactpersonenController::class],
			'AnonymousRegistrationListener'     => [AnonymousRegistrationListener::class],
			'ContactPersonOrganisationListener' => [ContactPersonOrganisationListener::class],
			'BackfillContractApprovalState'     => [BackfillContractApprovalState::class],
			'BackfillModuleVersionPublication'  => [BackfillModuleVersionPublication::class],
			'MigrateContactsToNc'               => [MigrateContactsToNc::class],
		];
	}//end readerConsumers()

	/**
	 * A configuration reading class asks for the reader, not the settings service.
	 *
	 * @param class-string $class The consumer class.
	 *
	 * @return void
	 */
	#[DataProvider('readerConsumers')]
	public function testTheConsumerAsksForTheReader(string $class): void {
		$types = [];
		foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			if ($type instanceof ReflectionNamedType) {
				$types[] = $type->getName();
			}
		}

		self::assertContains(needle: RegisterConfigReaderInterface::class, haystack: $types);
		self::assertNotContains(needle: SettingsService::class, haystack: $types);
	}//end testTheConsumerAsksForTheReader()
}//end class
