<?php
/**
 * Test script for OrganizationContactSyncJob
 */

require_once '/var/www/html/lib/base.php';

try {
    echo "Starting OrganizationContactSyncJob test...\n";
    
    // Get services from container
    $jobList = \OC::$server->get('OCP\BackgroundJob\IJobList');
    $timeFactory = \OC::$server->get('OCP\AppFramework\Utility\ITimeFactory');
    $softwareCatalogueService = \OC::$server->get('OCA\SoftwareCatalog\Service\SoftwareCatalogueService');
    $config = \OC::$server->get('OCP\IConfig');
    $logger = \OC::$server->get('Psr\Log\LoggerInterface');
    
    // Create OrganizationSyncService
    $organizationSyncService = new \OCA\SoftwareCatalog\Service\OrganizationSyncService(
        $softwareCatalogueService,
        $config,
        $logger
    );
    
    // Create job instance
    $job = new \OCA\SoftwareCatalog\BackgroundJob\OrganizationContactSyncJob(
        $timeFactory,
        $organizationSyncService
    );
    
    echo "Job instance created, executing...\n";
    
    // Execute the job
    $job->execute($jobList, null);
    
    echo "Job executed successfully!\n";
    
} catch (\Exception $e) {
    echo "Error executing job: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
} 