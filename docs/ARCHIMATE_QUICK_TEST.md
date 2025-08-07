# ArchiMate Quick Test Reference

## 🚀 One-Line Full Test

```bash
cd /home/rubenlinde/nextcloud-docker-dev && docker-compose exec nextcloud php /var/www/html/apps-extra/softwarecatalog/compare_archimate.php
```

This command will:
- ✅ Import the GEMMA_release.xml file
- ✅ Export the imported data
- ✅ Compare original vs exported XML
- ✅ Report any differences found
- ✅ Save detailed report to `/tmp/archimate_comparison_report.json`

## 📋 Quick Commands

### Import Test
```bash
docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/import" -H "Content-Type: application/json" -u admin:admin -d '{"file_path": "/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml"}'
```

### Export Test
```bash
docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/export" -H "Content-Type: application/json" -u admin:admin -d '{}' > /tmp/test_export.xml
```

### Database Inspection
```bash
docker-compose exec nextcloud php /var/www/html/apps-extra/softwarecatalog/debug_db.php
```

### Clear Status
```bash
docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/import/cancel" -u admin:admin
```

## 🎯 Expected Results

### Perfect Round-Trip
```
=== COMPARISON REPORT ===
Total differences found: 0
Elements compared: 2765
Relationships compared: 5696
Views compared: 242
Property definitions compared: 77
Folders compared: X

🎉 NO DIFFERENCES FOUND! Perfect round-trip compatibility achieved!
```

### Import Success
```json
{
  "success": true,
  "statistics": {
    "elements": {"created": 2765, "updated": 0, "skipped": 0},
    "organizations": {"created": 71, "updated": 0, "skipped": 0},
    "relationships": {"created": 5696, "updated": 0, "skipped": 0},
    "views": {"created": 242, "updated": 0, "skipped": 0},
    "property_definitions": {"created": 77, "updated": 0, "skipped": 0}
  }
}
```

## 🔧 Troubleshooting

### If Import Fails
1. Check file exists: `docker-compose exec nextcloud ls -la /var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml`
2. Clear status: `docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/import/cancel" -u admin:admin`
3. Check logs: `docker-compose exec nextcloud tail -n 50 /var/www/html/data/nextcloud.log`

### If Export is Empty
1. Verify import worked: `docker-compose exec nextcloud php /var/www/html/apps-extra/softwarecatalog/debug_db.php`
2. Check object counts in database
3. Re-run import if needed

### If Comparison Shows Differences
1. Check specific sections (elements, relationships, etc.)
2. Look for patterns in the differences
3. Verify property definitions are correct
4. Check source/target values in relationships

## 📊 File Sizes Reference

- **GEMMA_release.xml**: ~13MB (254,814 lines)
- **Exported XML**: Should be similar size
- **Comparison report**: JSON file with detailed differences

## 🔍 Manual Inspection Commands

```bash
# Check first 50 lines of export
docker-compose exec nextcloud head -n 50 /tmp/test_export.xml

# Count elements
docker-compose exec nextcloud grep -c '<element ' /tmp/test_export.xml

# Count relationships  
docker-compose exec nextcloud grep -c '<relationship ' /tmp/test_export.xml

# Check specific element
docker-compose exec nextcloud grep -A 10 'id-009fa62f25844aa3a87d252bf2b6bb0c' /tmp/test_export.xml
```
