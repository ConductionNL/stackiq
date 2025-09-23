<?php
/**
 * Test script for OrganizationContactSyncJob
 */

require_once '/var/www/html/lib/base.php';

try {
    echo "Starting OrganizationContactSyncJob test...\n";
    
    // Get services from container
    $jobList              = \OC::$server->get('OCP\BackgroundJob\IJobList');
    $timeFactory          = \OC::$server->get('OCP\AppFramework\Utility\ITimeFactory');
    $organisatieService   = \OC::$server->get(\OCA\SoftwareCatalog\Service\OrganisatieService::class);
    $contactpersoonService= \OC::$server->get(\OCA\SoftwareCatalog\Service\ContactpersoonService::class);
    $emailService         = \OC::$server->get(\OCA\SoftwareCatalog\Service\SymfonyEmailService::class);
    $config               = \OC::$server->get('OCP\IAppConfig');
    $logger               = \OC::$server->get(\Psr\Log\LoggerInterface::class);
    $settingsService      = \OC::$server->get(\OCA\SoftwareCatalog\Service\SettingsService::class);
    $db                   = \OC::$server->get(\OCP\IDBConnection::class);
    $contactPersonHandler = \OC::$server->get(\OCA\SoftwareCatalog\Service\SoftwareCatalogue\ContactPersonHandler::class);
    
    // Create OrganizationSyncService
    $organizationSyncService = new \OCA\SoftwareCatalog\Service\OrganizationSyncService(
        organisatieService:   $organisatieService,
        contactpersoonService:$contactpersoonService,
        emailService:         $emailService,
        config:               $config,
        logger:               $logger,
        settingsService:      $settingsService,
        db:                   $db,
        contactpersonHandler: $contactPersonHandler,
    );
    
    // Create job instance
    $job = new \OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob(
        $timeFactory,
        $organizationSyncService
    );
    
    echo "Job instance created, executing...\n";
    
    // Execute the job
    $job->run($jobList, null);
    
    echo "Job executed successfully!\n";
    
} catch (\Exception $e) {
    echo "Error executing job: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
} 