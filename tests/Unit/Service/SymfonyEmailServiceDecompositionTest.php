<?php

/**
 * Unit tests for the decomposed SymfonyEmailService helpers.
 *
 * Covers method-decomposition task 7.5 (extract renderTemplate /
 * resolveSender from sendTemplatedEmail / sendEmail).
 *
 * @category  Test
 * @package   OCA\SoftwareCatalog\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7-5
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Tests\Unit\Service;

use OCA\SoftwareCatalog\Service\SettingsService;
use OCA\SoftwareCatalog\Service\SymfonyEmailService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the private helpers extracted from SymfonyEmailService.
 *
 * @category Test
 * @package  OCA\SoftwareCatalog\Tests\Unit\Service
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-7-5
 */
class SymfonyEmailServiceDecompositionTest extends TestCase
{

    /**
     * Build a service with stub collaborators. SettingsService is a real
     * partial-mock so the helpers can ask it for email settings.
     *
     * @param array $emailSettings Settings returned from getEmailSettings()
     *
     * @return SymfonyEmailService
     */
    private function makeService(array $emailSettings): SymfonyEmailService
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('getEmailSettings')->willReturn($emailSettings);

        return new SymfonyEmailService(
            $this->createMock(IAppConfig::class),
            $this->createMock(LoggerInterface::class),
            $settings,
        );

    }//end makeService()


    /**
     * renderTemplate prefers a custom template override from email
     * settings over the built-in default and substitutes variables in.
     *
     * @return void
     */
    public function testRenderTemplateUsesCustomOverride(): void
    {
        $service = $this->makeService(
            [
                'templates' => [
                    'organization_registration' => '<p>Welcome, {{ organization.name }}.</p>',
                ],
            ]
        );

        $reflection = new \ReflectionMethod($service, 'renderTemplate');
        $reflection->setAccessible(true);

        $rendered = $reflection->invoke(
            $service,
            'organization_registration',
            ['organization' => ['name' => 'Acme BV']]
        );

        $this->assertStringContainsString('Acme BV', $rendered);
        $this->assertStringStartsWith('<p>Welcome,', $rendered);

    }//end testRenderTemplateUsesCustomOverride()


    /**
     * renderTemplate falls through to the built-in default template when
     * no override is configured.
     *
     * @return void
     */
    public function testRenderTemplateFallsBackToDefault(): void
    {
        $service    = $this->makeService(['templates' => []]);
        $reflection = new \ReflectionMethod($service, 'renderTemplate');
        $reflection->setAccessible(true);

        $rendered = $reflection->invoke($service, 'organization_registration', []);

        // The default templates are non-empty HTML — just assert we got
        // something resembling HTML back, not an exception.
        $this->assertNotSame('', $rendered);
        $this->assertIsString($rendered);

    }//end testRenderTemplateFallsBackToDefault()


    /**
     * resolveSender returns the configured sender details and transport
     * type as a tuple.
     *
     * @return void
     */
    public function testResolveSenderReturnsConfiguredTuple(): void
    {
        $service = $this->makeService(
            [
                'senderEmail'   => 'no-reply@example.org',
                'senderName'    => 'Catalog Bot',
                'transportType' => 'sendgrid',
            ]
        );

        $reflection = new \ReflectionMethod($service, 'resolveSender');
        $reflection->setAccessible(true);

        [$senderEmail, $senderName, $transport] = $reflection->invoke($service);

        $this->assertSame('no-reply@example.org', $senderEmail);
        $this->assertSame('Catalog Bot', $senderName);
        $this->assertSame('sendgrid', $transport);

    }//end testResolveSenderReturnsConfiguredTuple()


    /**
     * resolveSender falls back to defaults when settings are empty.
     *
     * @return void
     */
    public function testResolveSenderUsesDefaultsWhenSettingsEmpty(): void
    {
        $service    = $this->makeService([]);
        $reflection = new \ReflectionMethod($service, 'resolveSender');
        $reflection->setAccessible(true);

        [$senderEmail, $senderName, $transport] = $reflection->invoke($service);

        $this->assertNotSame('', $senderEmail);
        $this->assertNotSame('', $senderName);
        $this->assertSame('smtp', $transport);

    }//end testResolveSenderUsesDefaultsWhenSettingsEmpty()


}//end class
