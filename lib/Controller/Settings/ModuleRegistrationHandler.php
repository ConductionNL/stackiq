<?php

/**
 * Module Registration Handler for SettingsController
 *
 * Extracted from SettingsController to reduce ExcessiveClassLength, TooManyMethods,
 * and CouplingBetweenObjects on that controller.
 *
 * @category  Handler
 * @package   OCA\SoftwareCatalog\Controller\Settings
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://codeberg.org/Conduction/SoftwareCatalog
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\SoftwareCatalog\Controller\Settings;

use OCA\SoftwareCatalog\Service\ModuleRegistrationService;
use Psr\Log\LoggerInterface;

/**
 * Handles module registration operations for the SettingsController.
 *
 * Inject this handler into SettingsController to replace direct calls to
 * ModuleRegistrationService from within controller action methods.
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-3
 */
class ModuleRegistrationHandler
{
    /**
     * Constructor.
     *
     * @param ModuleRegistrationService $registrationSvc The module registration service.
     * @param LoggerInterface           $logger          Logger instance.
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-3
     */
    public function __construct(
        private readonly ModuleRegistrationService $registrationSvc,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handle a module registration request.
     *
     * @param array<string,mixed> $input Raw input from the HTTP request.
     *
     * @return array<string,mixed> Result with 'success' (bool) and 'message' (string).
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-3
     */
    public function handle(array $input): array
    {
        $validated = $this->validateModuleInput(input: $input);

        if (empty($validated['errors']) === false) {
            return [
                'success' => false,
                'message' => 'Validation failed: '.implode(', ', $validated['errors']),
                'errors'  => $validated['errors'],
            ];
        }

        $resolved = $this->resolveModuleDependencies(input: $validated['data']);
        return $this->persistModules(resolved: $resolved);

    }//end handle()

    /**
     * Validate module registration input.
     *
     * @param array<string,mixed> $input Raw input data.
     *
     * @return array<string,mixed> Validated data plus 'errors' array.
     */
    private function validateModuleInput(array $input): array
    {
        $errors = [];
        $data   = $input;

        if (isset($input['modules']) === false || is_array($input['modules']) === false) {
            $errors[] = 'modules field is required and must be an array.';
        }

        return ['data' => $data, 'errors' => $errors];

    }//end validateModuleInput()

    /**
     * Resolve module dependencies from the input data.
     *
     * @param array<string,mixed> $input Validated input data.
     *
     * @return array<string,mixed> Input enriched with dependency resolution.
     */
    private function resolveModuleDependencies(array $input): array
    {
        $modules = $input['modules'] ?? [];

        $this->logger->debug(
            'ModuleRegistrationHandler: Resolving dependencies for modules',
            ['count' => count($modules)]
        );

        // Allow the registration service to resolve inter-module dependencies.
        $input['resolvedModules'] = $modules;

        return $input;

    }//end resolveModuleDependencies()

    /**
     * Persist the resolved modules via ModuleRegistrationService.
     *
     * @param array<string,mixed> $resolved Resolved module data.
     *
     * @return array<string,mixed> Result with 'success', 'message', and 'registered' count.
     */
    private function persistModules(array $resolved): array
    {
        try {
            $modules = $resolved['resolvedModules'] ?? [];

            $registered = 0;
            foreach ($modules as $moduleData) {
                // ModuleRegistrationService handles object-based registration;
                // convert array data to stdClass for compatibility.
                $moduleObject = (object) $moduleData;
                $this->registrationSvc->handleModuleRegistration($moduleObject);
                $registered++;
            }

            $this->logger->info(
                'ModuleRegistrationHandler: Modules registered',
                ['count' => $registered]
            );

            return [
                'success'    => true,
                'message'    => sprintf('%d module(s) registered successfully.', $registered),
                'registered' => $registered,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'ModuleRegistrationHandler: Failed to register modules',
                ['exception' => $e->getMessage()]
            );

            return [
                'success' => false,
                'message' => 'Failed to register modules: '.$e->getMessage(),
            ];
        }//end try

    }//end persistModules()
}//end class
