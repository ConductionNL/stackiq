<?php

/**
 * The shipped default e-mail templates and the variables each one offers.
 *
 * @category  Service
 * @package   OCA\Stackiq\Service\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/stackiq
 *
 * @spec openspec/specs/settings-service/spec.md
 */

declare(strict_types=1);

namespace OCA\Stackiq\Service\Settings;

/**
 * The shipped default e-mail templates and their variables.
 *
 * @category Service
 * @package  OCA\Stackiq\Service\Settings
 *
 * @spec openspec/specs/settings-service/spec.md
 */
final class EmailTemplateDefaults {
	/**
	 * The default content of each template, by template name.
	 */
	private const TEMPLATES = [
		'organization_registration' => '
<h1>Welkom bij de Software Catalogus!</h1>
<p>Beste {{ organization.name }},</p>
<p>Hartelijk welkom bij de Software Catalogus! Uw organisatie is succesvol geregistreerd.</p>
<p>Met de Software Catalogus kunt u:</p>
<ul>
    <li>Uw software overzichtelijk beheren</li>
    <li>Software delen met andere organisaties</li>
    <li>Ontdekken welke software andere organisaties gebruiken</li>
</ul>
<p>Uw organisatie heeft de status "{{ organization.beoordeling }}" en kan nu gebruik maken van alle functionaliteiten.</p>
<p>Heeft u vragen? Neem dan contact met ons op.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            ',
		'organization_activation' => '
<h1>Uw organisatie is geactiveerd!</h1>
<p>Beste {{ organization.name }},</p>
<p>Goed nieuws! Uw organisatie is zojuist geactiveerd in de Software Catalogus.</p>
<p>Dit betekent dat u nu volledig kunt deelnemen aan:</p>
<ul>
    <li>Het delen van software informatie</li>
    <li>Samenwerking met andere organisaties</li>
    <li>Toegang tot alle catalogus functionaliteiten</li>
</ul>
<p>Status: {{ organization.beoordeling }}</p>
<p>U kunt nu inloggen en gebruik maken van alle beschikbare functionaliteiten.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            ',
		'user_creation' => '
<h1>Welkom {{ user.name }}!</h1>
<p>Beste {{ user.name }},</p>
<p>Er is een gebruikersaccount voor u aangemaakt in de Software Catalogus.</p>
<p>Uw accountgegevens:</p>
<ul>
    <li>E-mailadres: {{ user.email }}</li>
    <li>Gebruikersnaam: {{ user.username }}</li>
    <li>Organisatie: {{ user.organization.name if user.organization }}</li>
</ul>
<p>U kunt nu inloggen op het platform en gebruik maken van alle functionaliteiten.</p>
<p>Heeft u vragen over uw account? Neem dan contact met ons op.</p>
<p>Met vriendelijke groet,<br>Het Software Catalogus Team</p>
            ',
	];

	/**
	 * The variables each template offers, by template name.
	 */
	private const VARIABLES = [
		'organization_registration' => [
			'organization.name' => 'Organization name',
			'organization.beoordeling' => 'Organization status (e.g., Actief)',
			'organization.type' => 'Organization type (e.g., Leverancier)',
			'organization.website' => 'Organization website',
		],
		'organization_activation' => [
			'organization.name' => 'Organization name',
			'organization.beoordeling' => 'Organization status (e.g., Actief)',
			'organization.type' => 'Organization type',
			'organization.website' => 'Organization website',
		],
		'user_creation' => [
			'user.name' => 'User display name',
			'user.email' => 'User email address',
			'user.username' => 'Username',
			'user.organization.name' => 'Organization name (if applicable)',
		],
	];


	/**
	 * The default content of a template, empty for an unknown template.
	 *
	 * @param string $templateName The template name.
	 *
	 * @return string
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function template(string $templateName): string {
		return self::TEMPLATES[$templateName] ?? '';
	}//end template()

	/**
	 * The variables a template offers, empty for an unknown template.
	 *
	 * @param string $templateName The template name.
	 *
	 * @return array
	 * @spec   openspec/specs/settings-service/spec.md
	 */
	public function variables(string $templateName): array {
		return self::VARIABLES[$templateName] ?? [];
	}//end variables()

}//end class
