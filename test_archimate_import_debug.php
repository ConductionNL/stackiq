<?php

/**
 * ArchiMate Import Debug Script
 * 
 * This script tests the ArchiMate import process step by step to identify
 * why objects aren't being saved to the database.
 * 
 * @category Testing
 * @package  OCA\SoftwareCatalog
 * @version  1.0.0
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 */

// Test script to debug ArchiMate import issues
echo "=== ArchiMate Import Debug Script ===\n\n";

// Step 1: Test API endpoint directly
echo "Step 1: Testing API endpoint...\n";
$testCommand = "docker exec -it master-nextcloud-1 curl -X POST 'http://localhost/index.php/apps/softwarecatalog/api/archimate/import' " .
               "-u admin:admin " .
               "-F 'archiMateFile=@/var/www/html/data/admin/files/GEMMA_release.xml' " .
               "-F 'updateExisting=true' " .
               "-F 'preserveIds=true' " .
               "-F 'processingMode=speed' " .
               "-v 2>&1";

echo "Running: $testCommand\n";
$output = shell_exec($testCommand);
echo "API Response:\n$output\n\n";

// Step 2: Check configuration
echo "Step 2: Checking configuration...\n";
$configCommands = [
    "docker exec -u 33 master-nextcloud-1 php occ config:app:get softwarecatalog amef_config",
    "docker exec -u 33 master-nextcloud-1 php occ config:app:get softwarecatalog amef_register_id",
    "docker exec -u 33 master-nextcloud-1 php occ config:app:get softwarecatalog amef_elements_schema",
    "docker exec -u 33 master-nextcloud-1 php occ config:app:get softwarecatalog amef_organizations_schema",
    "docker exec -u 33 master-nextcloud-1 php occ config:app:get softwarecatalog amef_relationships_schema",
    "docker exec -u 33 master-nextcloud-1 php occ config:app:get softwarecatalog amef_views_schema"
];

foreach ($configCommands as $command) {
    echo "Running: $command\n";
    $result = shell_exec($command);
    echo "Result: $result\n";
}

// Step 3: Check if OpenRegister app is enabled
echo "\nStep 3: Checking OpenRegister app status...\n";
$openregisterCheck = "docker exec -u 33 master-nextcloud-1 php occ app:list | grep openregister";
echo "Running: $openregisterCheck\n";
$openregisterStatus = shell_exec($openregisterCheck);
echo "OpenRegister status: $openregisterStatus\n";

// Step 4: Check database for existing objects
echo "\nStep 4: Checking database for existing objects...\n";
$dbCommands = [
    "docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e 'SELECT COUNT(*) as total_objects FROM oc_openregister_objects;'",
    "docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e 'SELECT register, COUNT(*) as count FROM oc_openregister_objects GROUP BY register;'",
    "docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -e 'SELECT archimate_type, COUNT(*) as count FROM oc_openregister_objects WHERE archimate_type IS NOT NULL GROUP BY archimate_type;'"
];

foreach ($dbCommands as $command) {
    echo "Running: $command\n";
    $result = shell_exec($command);
    echo "Result: $result\n";
}

// Step 5: Check import status
echo "\nStep 5: Checking import status...\n";
$statusCommand = "docker exec -u 33 master-nextcloud-1 php occ config:app:get softwarecatalog archimate_import_status";
echo "Running: $statusCommand\n";
$status = shell_exec($statusCommand);
echo "Import status: $status\n";

// Step 6: Check logs for errors
echo "\nStep 6: Checking recent logs for errors...\n";
$logCommands = [
    "docker logs master-nextcloud-1 --tail 50 | grep -E 'ArchiMate|SoftwareCatalog|Error|Exception'",
    "docker logs master-nextcloud-1 --tail 50 | grep -E 'ObjectService|saveObjects|batch save'",
    "docker logs master-nextcloud-1 --tail 50 | grep -E 'schema_id|register_id|AMEF'"
];

foreach ($logCommands as $command) {
    echo "Running: $command\n";
    $result = shell_exec($command);
    echo "Log results: $result\n";
}

// Step 7: Test file existence and size
echo "\nStep 7: Checking test file...\n";
$fileCommands = [
    "docker exec master-nextcloud-1 ls -la /var/www/html/data/admin/files/GEMMA_release.xml",
    "docker exec master-nextcloud-1 file /var/www/html/data/admin/files/GEMMA_release.xml",
    "docker exec master-nextcloud-1 head -20 /var/www/html/data/admin/files/GEMMA_release.xml"
];

foreach ($fileCommands as $command) {
    echo "Running: $command\n";
    $result = shell_exec($command);
    echo "File info: $result\n";
}

echo "\n=== Debug script completed ===\n";
echo "Check the output above for any issues with:\n";
echo "1. API endpoint response\n";
echo "2. Configuration values\n";
echo "3. OpenRegister app status\n";
echo "4. Database objects\n";
echo "5. Import status\n";
echo "6. Error logs\n";
echo "7. Test file\n";
