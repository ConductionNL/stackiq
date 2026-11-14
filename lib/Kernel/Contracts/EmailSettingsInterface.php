<?php

/**
 * Contract of EmailSettings
 *
 * The public surface of EmailSettings that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Stackiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Stackiq\Kernel\Contracts;

/**
 * Contract of EmailSettings.
 *
 * @category Kernel
 * @package  OCA\Stackiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailSettingsInterface {

	/**
	 * Get all email templates with error handling
	 * This handles template iteration and individual failures
	 *
	 * @return array All email templates
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getAllEmailTemplates(): array;

	/**
	 * Gets default email template content
	 *
	 * @param string $templateName The template name
	 *
	 * @return string Default template content
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getDefaultEmailTemplate(string $templateName): string;

	/**
	 * Gets email configuration settings
	 *
	 * WARNING: returns REAL secret values — it is what SymfonyEmailService uses to send
	 * mail. Never hand the result straight to an HTTP response; run it through
	 * redactEmailSecrets() first.
	 *
	 * @return array Email settings configuration
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailSettings(): array;

	/**
	 * Gets email template content for a specific template
	 *
	 * @param string $templateName The template name (organization_registration, organization_activation, user_creation)
	 *
	 * @return string The template content
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailTemplate(string $templateName): string;

	/**
	 * Gets available email template variables for a specific template
	 *
	 * @param string $templateName The template name
	 *
	 * @return array Available template variables
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function getEmailTemplateVariables(string $templateName): array;

	/**
	 * Redact every secret in an email-settings array for an HTTP response.
	 *
	 * `getEmailSettings()` itself must keep returning the REAL values — SymfonyEmailService
	 * reads it to actually send mail — so redaction belongs at the response boundary, not
	 * in the getter. A set secret becomes the mask; an unset one stays empty, so the UI can
	 * still tell "configured" from "not configured" without ever receiving the value.
	 *
	 * @param array<string, mixed> $settings Raw settings from getEmailSettings().
	 *
	 * @return array<string, mixed> The settings, safe to return over HTTP.
	 *
	 * @spec openspec/specs/settings-service/spec.md
	 */
	public function redactEmailSecrets(array $settings): array;

	/**
	 * Updates email configuration settings
	 *
	 * @param array $emailSettings Email settings to update
	 *
	 * @return array Updated email settings
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateEmailSettings(array $emailSettings): array;

	/**
	 * Updates email template content
	 *
	 * @param string $templateName The template name
	 * @param string $templateContent The template content
	 *
	 * @return bool True if update was successful
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function updateEmailTemplate(string $templateName, string $templateContent): bool;
}//end interface
