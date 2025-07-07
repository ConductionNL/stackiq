# SoftwareCatalog EventListener and Email System Testing

This README explains how to test the complete SoftwareCatalog EventListener functionality and email system using the provided test scripts.

## What Has Been Implemented

### ✅ Email System Integration
- **PhpEmailService** integrated into EventListener handlers
- **Organization registration emails** sent when organizations are created
- **Organization activation emails** sent when organizations are set to 'Actief'
- **User creation emails** sent when new users are created
- **Account suspension notifications** when contactgegevens are deleted
- **Email configuration UI** in admin settings
- **Template management** with Twig templating
- **Test functionality** for validating email sending

### ✅ EventListener Enhancements
- **Organization processing** with email notifications
- **User creation** with email notifications
- **Role-based group assignment** with dynamic updates
- **Organization-specific group assignment**
- **Role change handling** (adding/removing roles updates groups)
- **Account suspension** on contactgegevens deletion

## Test Scripts Overview

### 1. `run_all_tests.sh` - Master Test Runner
**Purpose**: Runs all test scenarios in the correct order
**Usage**: 
```bash
./run_all_tests.sh
```
**What it does**:
- Checks Docker environment
- Runs email system tests
- Runs main scenarios tests
- Runs role changes tests
- Provides comprehensive summary

### 2. `test_email_system.sh` - Email System Test
**Purpose**: Tests email configuration, templates, and sending functionality
**Usage**:
```bash
./test_email_system.sh
```
**What it tests**:
- Email configuration retrieval and updates
- Email template management
- Individual email type controls
- Test email sending
- PHP mail function availability

### 3. `test_scenarios.sh` - Main Scenarios Test
**Purpose**: Tests the complete workflow from organization creation to user management
**Usage**:
```bash
./test_scenarios.sh
```
**What it tests**:
- Organization creation (registration email)
- Organization activation (activation email + user creation)
- Contactgegevens processing
- User creation with email notifications
- Role assignments and group memberships
- Organization-specific group assignments

### 4. `test_role_changes.sh` - Role Changes Test
**Purpose**: Tests role assignments and group membership updates
**Usage**:
```bash
./test_role_changes.sh <ORGANIZATION_ID>
```
**What it tests**:
- Adding roles to users
- Removing roles from users
- Group membership updates
- Admin role assignments
- Account suspension on deletion

## How to Run Tests

### Prerequisites
1. Docker Compose environment running
2. Nextcloud container accessible
3. SoftwareCatalog app enabled
4. OpenRegister app enabled

### Quick Start
```bash
# Navigate to the SoftwareCatalog directory
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/softwarecatalog

# Run all tests
./run_all_tests.sh
```

### Individual Test Execution
```bash
# Test email system only
./test_email_system.sh

# Test main scenarios only
./test_scenarios.sh

# Test role changes (requires organization ID)
./test_role_changes.sh <ORG_ID>
```

## Expected Results

### Email System Tests
- ✅ Email configuration can be retrieved and updated
- ✅ Email templates can be managed
- ✅ Test emails can be sent
- ✅ Individual email types can be enabled/disabled

### Main Scenarios Tests
- ✅ Organization registration email sent on creation
- ✅ Organization activation email sent when set to 'Actief'
- ✅ User creation emails sent for new contactgegevens
- ✅ Users created in Nextcloud with correct details
- ✅ Users assigned to role-based groups
- ✅ Users assigned to organization-specific groups
- ✅ Contactpersonen array emptied after processing

### Role Changes Tests
- ✅ Users added to groups when roles are added
- ✅ Users removed from groups when roles are removed
- ✅ Admin roles assign appropriate admin groups
- ✅ User accounts disabled when contactgegevens deleted

## Verification Steps

### 1. Check Logs
```bash
# Check SoftwareCatalog logs for email sending
docker-compose exec nextcloud tail -f /var/www/html/data/nextcloud.log | grep -i "softwarecatalog"
```

### 2. Check User Creation
```bash
# List users to see if test users were created
docker-compose exec nextcloud occ user:list | grep -E "(test\.email|new\.user|role\.tester)"
```

### 3. Check User Groups
```bash
# Check specific user group memberships
docker-compose exec nextcloud occ user:info test.email
docker-compose exec nextcloud occ user:info new.user
```

### 4. Check Organization Groups
```bash
# List groups to see organization-specific groups
docker-compose exec nextcloud occ group:list | grep -i "test.*email"
```

### 5. Check Email Configuration
```bash
# Check email settings via API
docker-compose exec nextcloud curl -X GET "http://localhost/index.php/apps/softwarecatalog/api/settings/email" -H "Content-Type: application/json" -u admin:admin
```

## Troubleshooting

### Email Not Sending
1. Check if PHP mail() function is available:
   ```bash
   docker-compose exec nextcloud php -r "echo function_exists('mail') ? 'Available' : 'Not available';"
   ```

2. Check email configuration:
   ```bash
   # Via API
   docker-compose exec nextcloud curl -X GET "http://localhost/index.php/apps/softwarecatalog/api/settings/email" -u admin:admin
   ```

3. Enable test receiver override for safe testing

### EventListener Not Triggering
1. Check if SoftwareCatalog app is enabled:
   ```bash
   docker-compose exec nextcloud occ app:list | grep softwarecatalog
   ```

2. Check logs for errors:
   ```bash
   docker-compose exec nextcloud tail -n 50 /var/www/html/data/nextcloud.log | grep -i "error"
   ```

### User Creation Issues
1. Check if users actually exist:
   ```bash
   docker-compose exec nextcloud occ user:list
   ```

2. Check user details:
   ```bash
   docker-compose exec nextcloud occ user:info <username>
   ```

## Test Data Cleanup

After testing, you may want to clean up test data:

```bash
# Remove test users
docker-compose exec nextcloud occ user:delete test.email
docker-compose exec nextcloud occ user:delete new.user
docker-compose exec nextcloud occ user:delete role.tester

# Remove test groups (be careful not to remove system groups)
docker-compose exec nextcloud occ group:delete test_email_organization
```

## API Endpoints Tested

### Email System
- `GET /apps/softwarecatalog/api/settings/email` - Get email configuration
- `PUT /apps/softwarecatalog/api/settings/email` - Update email configuration
- `GET /apps/softwarecatalog/api/settings/email/template/{type}` - Get email template
- `PUT /apps/softwarecatalog/api/settings/email/template/{type}` - Update email template
- `POST /apps/softwarecatalog/api/settings/email/test` - Send test email

### OpenRegister Objects
- `POST /apps/openregister/api/objects/6/35` - Create organization
- `PUT /apps/openregister/api/objects/6/35/{id}` - Update organization
- `GET /apps/openregister/api/objects/6/35/{id}` - Get organization
- `POST /apps/openregister/api/objects/6/34` - Create contactgegevens
- `PUT /apps/openregister/api/objects/6/34/{id}` - Update contactgegevens
- `DELETE /apps/openregister/api/objects/6/34/{id}` - Delete contactgegevens

## Summary

The test scripts provide comprehensive coverage of:
1. **Email system functionality** - configuration, templates, sending
2. **Organization lifecycle** - creation, activation, processing
3. **User management** - creation, role assignment, group membership
4. **Role changes** - adding/removing roles, group updates
5. **Account lifecycle** - creation, updates, suspension

All email notifications are integrated and will be triggered automatically during the EventListener processing. The test scripts validate both the core functionality and the email notifications work correctly together. 