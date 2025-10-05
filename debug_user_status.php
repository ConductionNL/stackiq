<?php
/**
 * Debug script to check user status in Nextcloud
 */

// Get the contactpersoon ID from the API response
$contactpersoonId = 'e8ba22ba-35ab-4b59-a097-91df6fa2cd3d';

echo "=== DEBUG USER STATUS ===\n";
echo "Contactpersoon ID: $contactpersoonId\n\n";

// Simulate the ContactpersoonService logic
try {
    // This would be in the Docker container context
    echo "1. Checking if contactpersoon exists in database...\n";
    
    // Simulate database query
    echo "   - Would query openregister_objects table\n";
    echo "   - Looking for contactpersoon with ID: $contactpersoonId\n\n";
    
    echo "2. Checking if contactpersoon has username...\n";
    echo "   - Would check if data.username exists\n";
    echo "   - Current API shows: hasUser: false, username: ''\n\n";
    
    echo "3. If no username, userInfo should be:\n";
    echo "   - hasUser: false\n";
    echo "   - username: ''\n";
    echo "   - groups: []\n";
    echo "   - disabled: false (default for non-users)\n\n";
    
    echo "4. ISSUE IDENTIFIED:\n";
    echo "   - API returns disabled: false because hasUser: false\n";
    echo "   - But user might actually be disabled in Nextcloud\n";
    echo "   - We need to check if username exists but user is disabled\n\n";
    
    echo "5. POSSIBLE SOLUTIONS:\n";
    echo "   a) Check if contactpersoon.data.username exists but user is disabled\n";
    echo "   b) Verify the user was actually disabled via our disable endpoint\n";
    echo "   c) Check if there's a mismatch between data.username and actual Nextcloud user\n\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "=== END DEBUG ===\n";
?>
