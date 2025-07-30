# Manual Configuration Commands for SoftwareCatalog Environments

Based on the log analysis, the schema configurations are empty. Here are the commands to manually configure both environments.

## Recent Improvements Made

**Backend Improvements:**
- Enhanced error handling in SettingsController with specific error types
- Added health check endpoint (`/api/health`) for better diagnostics
- Improved Application boot process with better initialization tracking
- More robust auto-configuration process with enhanced logging

**Frontend Improvements:**
- Added "Save Schema Values" button specifically for Voorzieningen register
- Better error handling for empty API responses (`{"message":""}`)
- Enhanced console logging to help identify root causes
- Fallback defaults to keep the UI functional even when APIs fail

## Test Environment (softwarecatalogus.test.commonground.nu)

### 1. Check Current Status
```bash
curl -u 'info@conduction.nl:YguxP(7bl=5v@N6u' \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.test.commonground.nu/index.php/apps/softwarecatalog/api/settings/debug'
```

### 2. Check Version Information
```bash
curl -u 'info@conduction.nl:YguxP(7bl=5v@N6u' \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.test.commonground.nu/index.php/apps/softwarecatalog/api/settings/version'
```

### 3. Reset Auto-Configuration (if needed)
```bash
curl -u 'info@conduction.nl:YguxP(7bl=5v@N6u' \
  -X POST \
  -H 'Content-Type: application/json' \
  -d '{"resetConfiguration": true}' \
  'https://softwarecatalogus.test.commonground.nu/index.php/apps/softwarecatalog/api/settings/reset-auto-config'
```

### 4. Force Import Configuration
```bash
curl -u 'info@conduction.nl:YguxP(7bl=5v@N6u' \
  -X POST \
  -H 'Content-Type: application/json' \
  -d '{"force": true}' \
  'https://softwarecatalogus.test.commonground.nu/index.php/apps/softwarecatalog/api/settings/import'
```

### 5. Trigger Auto-Configuration
```bash
curl -u 'info@conduction.nl:YguxP(7bl=5v@N6u' \
  -X POST \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.test.commonground.nu/index.php/apps/softwarecatalog/api/settings/auto-configure'
```

### 6. Initialize Settings
```bash
curl -u 'info@conduction.nl:YguxP(7bl=5v@N6u' \
  -X POST \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.test.commonground.nu/index.php/apps/softwarecatalog/api/settings/initialize'
```

### 7. Verify Configuration
```bash
curl -u 'info@conduction.nl:YguxP(7bl=5v@N6u' \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.test.commonground.nu/index.php/apps/softwarecatalog/api/settings/status'
```

## Accept Environment (softwarecatalogus.accept.commonground.nu)

### 1. Check Current Status
```bash
curl -u 'info@conduction.nl:QEbHYoOR5bz_wB)d' \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.accept.commonground.nu/index.php/apps/softwarecatalog/api/settings/debug'
```

### 2. Check Version Information
```bash
curl -u 'info@conduction.nl:QEbHYoOR5bz_wB)d' \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.accept.commonground.nu/index.php/apps/softwarecatalog/api/settings/version'
```

### 3. Reset Auto-Configuration (if needed)
```bash
curl -u 'info@conduction.nl:QEbHYoOR5bz_wB)d' \
  -X POST \
  -H 'Content-Type: application/json' \
  -d '{"resetConfiguration": true}' \
  'https://softwarecatalogus.accept.commonground.nu/index.php/apps/softwarecatalog/api/settings/reset-auto-config'
```

### 4. Force Import Configuration
```bash
curl -u 'info@conduction.nl:QEbHYoOR5bz_wB)d' \
  -X POST \
  -H 'Content-Type: application/json' \
  -d '{"force": true}' \
  'https://softwarecatalogus.accept.commonground.nu/index.php/apps/softwarecatalog/api/settings/import'
```

### 5. Trigger Auto-Configuration
```bash
curl -u 'info@conduction.nl:QEbHYoOR5bz_wB)d' \
  -X POST \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.accept.commonground.nu/index.php/apps/softwarecatalog/api/settings/auto-configure'
```

### 6. Initialize Settings
```bash
curl -u 'info@conduction.nl:QEbHYoOR5bz_wB)d' \
  -X POST \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.accept.commonground.nu/index.php/apps/softwarecatalog/api/settings/initialize'
```

### 7. Verify Configuration
```bash
curl -u 'info@conduction.nl:QEbHYoOR5bz_wB)d' \
  -H 'Content-Type: application/json' \
  'https://softwarecatalogus.accept.commonground.nu/index.php/apps/softwarecatalog/api/settings/status'
```

## Expected Results

After running these commands, you should see:

1. **Version info** showing current app version and configuration status
2. **Import results** confirming the softwarecatalogus_register.json was imported
3. **Auto-configuration results** showing found schemas and registers
4. **Status check** showing schemas are configured with actual IDs (not empty)
5. **Frontend interface** should now show the dropdowns populated with schema options
6. **New "Save Schema Values" button** should be available for Voorzieningen register

## Troubleshooting

If you get `{"message":""}` responses, it means there's still an exception being caught. In that case:

1. Check the Nextcloud logs for the full error
2. Verify OpenRegister is installed and enabled
3. Verify the softwarecatalogus_register.json file exists in `lib/Settings/`
4. Run the health check endpoint when available

## Manual Schema Configuration (if auto-config fails)

If auto-configuration fails, you can manually set the schema IDs by finding them in OpenRegister and then using:

```bash
curl -u 'USERNAME:PASSWORD' \
  -X POST \
  -H 'Content-Type: application/json' \
  -d '{
    "voorzieningen_organisatie_register": "REGISTER_ID",
    "voorzieningen_organisatie_schema": "SCHEMA_ID",
    "voorzieningen_contactpersoon_register": "REGISTER_ID", 
    "voorzieningen_contactpersoon_schema": "SCHEMA_ID"
  }' \
  'https://ENVIRONMENT_URL/index.php/apps/softwarecatalog/api/settings'
```