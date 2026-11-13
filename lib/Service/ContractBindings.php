<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Stackiq\Service
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

namespace OCA\Stackiq\Service;

use OCA\Stackiq\Kernel\Contracts\AanbodServiceInterface;
use OCA\Stackiq\Kernel\Contracts\AangebodenGebruikServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ArchiMateImportControlInterface;
use OCA\Stackiq\Kernel\Contracts\ArchiMateServiceInterface;
use OCA\Stackiq\Kernel\Contracts\DataQualityReportServiceInterface;
use OCA\Stackiq\Kernel\Contracts\DemoDataServiceInterface;
use OCA\Stackiq\Kernel\Contracts\EmailSettingsInterface;
use OCA\Stackiq\Kernel\Contracts\FacetServiceInterface;
use OCA\Stackiq\Kernel\Contracts\GebruikServiceInterface;
use OCA\Stackiq\Kernel\Contracts\IntakeServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ItsmExchangeServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ItsmFileImportServiceInterface;
use OCA\Stackiq\Kernel\Contracts\MaintenanceRecipientServiceInterface;
use OCA\Stackiq\Kernel\Contracts\MergeOrganisatieServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ModerationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ModuleVersionPublicationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\OrganisationReportAccessInterface;
use OCA\Stackiq\Kernel\Contracts\PortfolioReportServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ProgressTrackerInterface;
use OCA\Stackiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ReferenceComponentCoverageServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ReviewAggregateServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ReviewServiceInterface;
use OCA\Stackiq\Kernel\Contracts\RoleGroupSettingsInterface;
use OCA\Stackiq\Kernel\Contracts\SbomImportServiceInterface;
use OCA\Stackiq\Kernel\Contracts\ViewServiceInterface;
use OCA\Stackiq\Service\Settings\ArchiMateImportControl;
use OCA\Stackiq\Service\Settings\EmailSettings;
use OCA\Stackiq\Service\Settings\RoleGroupSettings;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Stackiq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Stackiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/stackiq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec openspec/specs/method-decomposition/spec.md
 */
final class ContractBindings {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/method-decomposition/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AanbodServiceInterface::class, AanbodService::class);
		$context->registerServiceAlias(AangebodenGebruikServiceInterface::class, AangebodenGebruikService::class);
		$context->registerServiceAlias(ArchiMateImportControlInterface::class, ArchiMateImportControl::class);
		$context->registerServiceAlias(ArchiMateServiceInterface::class, ArchiMateService::class);
		$context->registerServiceAlias(DataQualityReportServiceInterface::class, DataQualityReportService::class);
		$context->registerServiceAlias(DemoDataServiceInterface::class, DemoDataService::class);
		$context->registerServiceAlias(EmailSettingsInterface::class, EmailSettings::class);
		$context->registerServiceAlias(FacetServiceInterface::class, FacetService::class);
		$context->registerServiceAlias(GebruikServiceInterface::class, GebruikService::class);
		$context->registerServiceAlias(IntakeServiceInterface::class, IntakeService::class);
		$context->registerServiceAlias(ItsmExchangeServiceInterface::class, ItsmExchangeService::class);
		$context->registerServiceAlias(ItsmFileImportServiceInterface::class, ItsmFileImportService::class);
		$context->registerServiceAlias(MaintenanceRecipientServiceInterface::class, MaintenanceRecipientService::class);
		$context->registerServiceAlias(MergeOrganisatieServiceInterface::class, MergeOrganisatieService::class);
		$context->registerServiceAlias(ModerationServiceInterface::class, ModerationService::class);
		$context->registerServiceAlias(ModuleVersionPublicationServiceInterface::class, ModuleVersionPublicationService::class);
		$context->registerServiceAlias(OrganisationReportAccessInterface::class, OrganisationReportAccess::class);
		$context->registerServiceAlias(PortfolioReportServiceInterface::class, PortfolioReportService::class);
		$context->registerServiceAlias(ProgressTrackerInterface::class, ProgressTracker::class);
		$context->registerServiceAlias(PublicationServiceInterface::class, PublicationService::class);
		$context->registerServiceAlias(ReferenceComponentCoverageServiceInterface::class, ReferenceComponentCoverageService::class);
		$context->registerServiceAlias(ReviewAggregateServiceInterface::class, ReviewAggregateService::class);
		$context->registerServiceAlias(ReviewServiceInterface::class, ReviewService::class);
		$context->registerServiceAlias(RoleGroupSettingsInterface::class, RoleGroupSettings::class);
		$context->registerServiceAlias(SbomImportServiceInterface::class, SbomImportService::class);
		$context->registerServiceAlias(ViewServiceInterface::class, ViewService::class);
	}//end register()
}//end class
