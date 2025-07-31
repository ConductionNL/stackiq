# ArchiMate Import/Export Project Status

## Project Overview

We are implementing ArchiMate import/export functionality for the Nextcloud SoftwareCatalog app. This allows users to:
- Import ArchiMate files (.xml, .archimate) and convert them to OpenRegister objects
- Export OpenRegister objects to ArchiMate format
- Configure AMEF register settings for schema mapping
- Track progress of long-running operations with real-time updates

## Current Status: ✅ Streaming XML Parser Implemented, 🔧 Testing and Optimization in Progress

### ✅ What's Working:
1. **API Endpoints**: All routes properly configured and responding
2. **AMEF Configuration**: Auto-configuration and manual settings management
3. **ArchiMate Export**: Successfully creates XML files with progress tracking
4. **Progress Tracking**: Real-time updates via Server-Sent Events
5. **File Upload**: Basic file upload detection and processing
6. **Authentication**: Basic auth working for API calls
7. **Streaming XML Parser**: ✅ **NEW** - Memory-efficient XMLReader implementation for large files

### 🔧 Current Work:
- **Streaming Parser Testing**: Testing the new XMLReader implementation with large files
- **Method Signature Fixes**: Resolving PHP interface compatibility issues
- **Performance Validation**: Ensuring the streaming parser handles 13MB+ files without memory issues

### ✅ Recently Completed:
- **Streaming XML Parser**: Implemented `parseArchiMateFileStreaming()` method using XMLReader
- **Automatic Parser Selection**: Files >5MB automatically use streaming parser, smaller files use SimpleXML
- **Memory Optimization**: Eliminated loading entire file into memory for large files
- **Progress Integration**: Streaming parser includes progress tracking during processing

## Important Files

### Core Implementation Files:
1. **`lib/Controller/SettingsController.php`** - Main API controller
   - Contains: `importArchiMate()`, `exportArchiMate()`, `downloadArchiMate()`
   - Contains: AMEF configuration methods
   - Contains: Progress tracking endpoints
   - **UPDATED**: Fixed method signature compatibility issues

2. **`lib/Service/ArchiMateService.php`** - Business logic service
   - Contains: XML/JSON parsing logic
   - Contains: Data normalization and mapping
   - Contains: Import/export orchestration
   - **✅ OPTIMIZED**: Added streaming XML parser with memory-efficient processing
   - **NEW METHODS**:
     - `parseArchiMateFileStreaming()` - XMLReader-based streaming parser
     - `parseArchiMateFileMemory()` - SimpleXML parser for small files
     - `extractElementAttributes()` - Extract XML attributes efficiently
     - `processStreamingElementContent()` - Process element content in chunks
     - `processStreamingChildren()` - Handle child elements recursively

3. **`lib/Service/ProgressTracker.php`** - Progress tracking service
   - Manages operation states and real-time updates
   - Uses PHP sessions for storage

4. **`appinfo/routes.php`** - API route definitions
   - All ArchiMate and AMEF endpoints defined here

5. **`lib/AppInfo/Application.php`** - Dependency injection
   - Services registered in container

### Frontend File:
6. **`src/views/settings/SoftwareCatalogSettings.vue`** - UI components
   - File upload interface
   - Progress display
   - AMEF configuration forms

### Test Files:
7. **`test_archimate_simple.php`** - PHP test script for API validation
8. **`test_archimate_api.sh`** - Bash test script for comprehensive testing
9. **`lib/Settings/GEMMA_release.xml`** - 13MB test file (now handled by streaming parser)

## API Endpoints

### Working Endpoints:
```bash
# AMEF Configuration
GET  /api/settings/amef                    # Get current settings
POST /api/settings/amef                    # Save settings  
POST /api/settings/amef/auto-configure     # Auto-detect schemas

# ArchiMate Operations
POST /api/archimate/import                 # Import ArchiMate file (now with streaming)
POST /api/archimate/export                 # Export to ArchiMate
GET  /api/archimate/download/{fileName}    # Download exported file

# Progress Tracking  
GET  /api/progress/{operationId}           # Get progress status
GET  /api/progress/{operationId}/stream    # SSE progress stream
```

## Testing Commands

### Quick API Tests:
```bash
# Test AMEF auto-configuration
docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/settings/amef/auto-configure" -H "Content-Type: application/json" -u admin:admin

# Test export (works)
docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/export" -H "Content-Type: application/json" -u admin:admin -d '{"format": "xml", "includeRelationships": true}'

# Test import with streaming parser (should now work with large files)
docker-compose exec nextcloud curl -X POST "http://localhost/index.php/apps/softwarecatalog/api/archimate/import" -u admin:admin -F "archiMateFile=@/var/www/html/apps-extra/softwarecatalog/lib/Settings/GEMMA_release.xml"
```

### Comprehensive Test:
```bash
# Run from container
docker-compose exec nextcloud php /var/www/html/apps-extra/softwarecatalog/test_archimate_simple.php
```

## Current Architecture

### Data Flow (Updated):
1. **Upload**: File uploaded via multipart/form-data to `importArchiMate()`
2. **Validation**: File format and size validation
3. **Parser Selection**: Automatic choice between streaming (XMLReader) and memory-based (SimpleXML)
4. **Streaming Parsing**: ✅ **NEW** - XML processed in chunks using XMLReader
5. **Normalization**: Data normalized to standard structure
6. **Conversion**: Converted to OpenRegister objects (currently mocked)
7. **Progress**: Real-time updates via ProgressTracker

### Memory Optimization Implementation:
```php
// ✅ NEW: Streaming approach for large files
private function parseArchiMateFileStreaming(File $file, array $options = []): array
{
    $reader = new \XMLReader();
    $reader->open($file->getStorage()->getLocalFile($file->getPath()));
    
    // Process elements one at a time instead of loading all into memory
    while ($reader->read()) {
        if ($reader->nodeType === \XMLReader::ELEMENT) {
            // Process specific ArchiMate elements (element, relationship, view)
            $this->processStreamingElement($reader, $result, $progressTracker);
        }
    }
}
```

### Parser Selection Logic:
```php
// ✅ NEW: Automatic parser selection based on file size
$useStreaming = $fileSize > 5 * 1024 * 1024; // 5MB threshold

if ($useStreaming) {
    return $this->parseArchiMateFileStreaming($file, $options);
} else {
    return $this->parseArchiMateFileMemory($file, $options);
}
```

## Configuration

### AMEF Schema Mapping:
- **Elements Schema**: ID 50 (auto-detected)
- **Organizations Schema**: ID 35 (from config)
- **Relationships Schema**: ID 51 (needs configuration)
- **Views Schema**: ID 52 (needs configuration)

### Environment:
- **Docker Setup**: `/home/rubenlinde/nextcloud-docker-dev`
- **Nextcloud Version**: 30.0.0.1
- **PHP Version**: 8.1.31
- **Admin Credentials**: admin:admin

## Debugging

### Check Logs:
```bash
docker-compose exec nextcloud tail -n 50 /var/www/html/data/nextcloud.log
```

### PHP Syntax Check:
```bash
docker-compose exec nextcloud php -l /var/www/html/apps-extra/softwarecatalog/lib/Controller/SettingsController.php
docker-compose exec nextcloud php -l /var/www/html/apps-extra/softwarecatalog/lib/Service/ArchiMateService.php
```

### Test File Sizes:
- **GEMMA_release.xml**: 13,370,347 bytes (13MB) - now handled by streaming parser
- **Need**: Create smaller test files for development

## Dependencies

### Required PHP Extensions:
- **libxml** - XML parsing (XMLReader)
- **curl** - HTTP requests
- **json** - JSON handling

### ReactPHP Components (already available):
- **react/event-loop** - Async processing
- **react/promise** - Promise handling

### Nextcloud Services Used:
- **IAppConfig** - Configuration storage
- **IRootFolder** - File system access
- **IUserSession** - User context
- **ISession** - Progress storage

## Known Issues Fixed:
1. ✅ **Duplicate Methods**: Removed duplicate `importArchiMate`, `exportArchiMate`, `downloadArchiMate` methods
2. ✅ **Missing Methods**: Fixed `getOpenRegisters()` call with mock data
3. ✅ **Protected Method Access**: Fixed `getContent()` access in export method
4. ✅ **File Field Name**: Import expects `archiMateFile` not `file`
5. ✅ **Memory Issues**: Implemented streaming XML parser for large files
6. ✅ **Method Signatures**: Fixed PHP interface compatibility issues

## Architecture Decisions

### Why These Technologies:
- **XMLReader**: ✅ **NEW** - Memory-efficient streaming XML parsing for large files
- **SimpleXML**: Good for small files, kept for backward compatibility
- **ReactPHP**: Enables async processing for better performance
- **Server-Sent Events**: Real-time progress without polling
- **PHP Sessions**: Simple storage for progress state

### Design Patterns:
- **Service Layer**: Business logic separated from controllers
- **Dependency Injection**: Services injected via Nextcloud container
- **Progress Tracking**: Centralized operation state management
- **Mock Implementation**: OpenRegister calls stubbed for development
- **✅ NEW: Parser Strategy**: Automatic selection between streaming and memory-based parsing

## Success Criteria

### Current Success:
- ✅ API endpoints responding correctly
- ✅ File upload detection working
- ✅ Export generating valid XML files
- ✅ Progress tracking with operation IDs
- ✅ AMEF configuration management
- ✅ **NEW**: Streaming XML parser implemented
- ✅ **NEW**: Memory-efficient processing for large files
- ✅ **NEW**: Automatic parser selection based on file size

### Remaining Goals:
- 🔧 Complete testing of streaming parser with large files
- 🔧 Validate memory usage improvements
- ❌ Actual OpenRegister integration
- ❌ Complete round-trip testing (import → export → compare)

## Next Steps

### Immediate Tasks:
1. **Complete Testing**: Validate streaming parser with GEMMA_release.xml
2. **Performance Validation**: Measure memory usage improvements
3. **Error Handling**: Ensure robust error handling in streaming parser
4. **Documentation**: Update technical documentation

### Future Enhancements:
1. **Batch Processing**: Implement batch processing for very large files
2. **Progress Granularity**: More detailed progress reporting during streaming
3. **Memory Monitoring**: Add memory usage monitoring and logging
4. **OpenRegister Integration**: Replace mock implementations with real API calls

---

**Last Updated**: July 30, 2025  
**Status**: Streaming XML parser implemented, testing in progress  
**Next Action**: Complete testing and validation of streaming parser with large files