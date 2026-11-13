<?php

/**
 * Characterisation tests for the Amazon SES transport of MailTransportFactory.
 *
 * Pins the refusal without both keys and the DSN the transport is built
 * from (keys and region URL-encoded, the default region) before the DSN is
 * built in its own step.
 *
 * @category  Test
 * @package   OCA\Stackiq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/email-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Stackiq\Service\MailTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the SES transport.
 */
class MailTransportFactorySesTest extends TestCase {

	/**
	 * Create an SES transport through the public factory method.
	 *
	 * @param array           $settings The SES settings
	 * @param LoggerInterface $logger   The logger
	 *
	 * @return string The transport as a string
	 */
	private function ses(array $settings, LoggerInterface $logger): string {
		return (string)(new MailTransportFactory($logger))->create(['transportType' => 'ses'] + $settings);

	}//end ses()

	/**
	 * Both keys are required; the refusal is logged and rethrown.
	 *
	 * @return void
	 */
	public function testBothKeysAreRequired(): void {
		foreach ([['sesAccessKey' => 'AK'], ['sesSecretKey' => 'SK'], ['sesAccessKey' => '', 'sesSecretKey' => 'SK']] as $settings) {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->once())->method('error')->with(
				'Failed to create email transport',
				['transportType' => 'ses', 'error' => 'Amazon SES access key and secret key are required']
			);
			try {
				$this->ses($settings, $logger);
				$this->fail('Expected the SES transport to be refused');
			} catch (InvalidArgumentException $e) {
				$this->assertSame('Amazon SES access key and secret key are required', $e->getMessage());
			}
		}

	}//end testBothKeysAreRequired()

	/**
	 * The transport is built from the encoded keys and the region, us-east-1 by default.
	 *
	 * @return void
	 */
	public function testTheTransportCarriesTheKeyAndRegion(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->assertSame('ses+api://AK/1@eu-west-1', $this->ses(['sesAccessKey' => 'AK/1', 'sesSecretKey' => 's+k', 'sesRegion' => 'eu-west-1'], $logger));
		$this->assertSame('ses+api://AK@us-east-1', $this->ses(['sesAccessKey' => 'AK', 'sesSecretKey' => 'SK'], $logger));

	}//end testTheTransportCarriesTheKeyAndRegion()
}//end class
