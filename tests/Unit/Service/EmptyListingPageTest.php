<?php

/**
 * Characterisation test for the empty listing pages of AanbodService,
 * AangebodenGebruikService and ListingResponder.
 *
 * Pins the exact page, key order included: getAanbod without an active
 * organisation and when OpenRegister is missing, getGebruiksWhereAfnemer
 * without an active organisation, the empty koppelingen page with and
 * without an error, and the controllers' empty listing.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use OCA\Stackiq\Controller\ListingResponder;
use OCA\Stackiq\Service\AanbodService;
use OCA\Stackiq\Service\AangebodenGebruikService;
use OCP\App\IAppManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Characterisation of the empty listing pages.
 *
 * @spec openspec/specs/aangeboden-gebruik-api/spec.md
 */
class EmptyListingPageTest extends TestCase {

	/**
	 * The page every empty listing starts from.
	 */
	private const PAGE = ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'limit' => 20, 'offset' => 0];

	/**
	 * GetAanbod without an active organisation answers the empty page with a message.
	 *
	 * @return void
	 */
	public function testAanbodWithoutAnOrganisation(): void {
		$service = $this->service(class: AanbodService::class, installed: true);
		$this->assertSame(expected: self::PAGE + ['message' => 'No current organization available'], actual: $service->getAanbod());

	}//end testAanbodWithoutAnOrganisation()

	/**
	 * GetAanbod without OpenRegister answers the empty page with the error.
	 *
	 * @return void
	 */
	public function testAanbodWithoutOpenRegister(): void {
		$service = $this->service(class: AanbodService::class, installed: false);
		$this->assertSame(
			expected: self::PAGE + ['error' => 'Failed to retrieve aanbod: OpenRegister app is not installed'],
			actual: $service->getAanbod()
		);

	}//end testAanbodWithoutOpenRegister()

	/**
	 * GetGebruiksWhereAfnemer without an active organisation answers the empty page with a message.
	 *
	 * @return void
	 */
	public function testAfnemerWithoutAnOrganisation(): void {
		$service = $this->service(class: AangebodenGebruikService::class, installed: true);
		$this->assertSame(expected: self::PAGE + ['message' => 'No current organization available'], actual: $service->getGebruiksWhereAfnemer());

	}//end testAfnemerWithoutAnOrganisation()

	/**
	 * The empty koppelingen page, with and without an error.
	 *
	 * @return void
	 */
	public function testTheEmptyKoppelingenPage(): void {
		$service = $this->service(class: AangebodenGebruikService::class, installed: true);
		$page    = new \ReflectionMethod(objectOrMethod: AangebodenGebruikService::class, method: 'emptyKoppelingenPage');
		$this->assertSame(expected: self::PAGE, actual: $page->invoke($service, null));
		$this->assertSame(expected: self::PAGE + ['error' => 'UUID is required'], actual: $page->invoke($service, 'UUID is required'));

	}//end testTheEmptyKoppelingenPage()

	/**
	 * The controllers' empty listing.
	 *
	 * @return void
	 */
	public function testTheControllersEmptyListing(): void {
		$responder = new ListingResponder(logger: new NullLogger(), request: $this->createMock(originalClassName: IRequest::class));
		$this->assertSame(expected: self::PAGE, actual: $responder->emptyListing());

	}//end testTheControllersEmptyListing()

	/**
	 * A service without a session, with or without OpenRegister installed.
	 *
	 * @param class-string $class     The service.
	 * @param bool         $installed Whether OpenRegister is installed.
	 *
	 * @return object
	 */
	private function service(string $class, bool $installed): object {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($installed === true ? ['openregister'] : ['files']);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($this->createMock(originalClassName: \OCA\OpenRegister\Contract\ObjectServiceInterface::class));
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$service = (new \ReflectionClass(objectOrClass: $class))->newInstanceWithoutConstructor();
		foreach (['appManager' => $appManager, 'container' => $container, 'userSession' => $session, 'logger' => new NullLogger()] as $prop => $value) {
			(new \ReflectionProperty(class: $class, property: $prop))->setValue($service, $value);
		}

		return $service;

	}//end service()

}//end class
