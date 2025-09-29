# Software Catalogus

[![License: EUPL](https://img.shields.io/badge/License-EUPL-blue.svg)](https://opensource.org/licenses/EUPL)
[![Nextcloud](https://img.shields.io/badge/Nextcloud-Compatible-brightgreen)](https://nextcloud.com/)
[![Version](https://img.shields.io/badge/version-0.1.1-blue)]()

The **Software Catalogus** is a Nextcloud app that provides a powerful framework for managing and synchronizing software catalogs in an open data ecosystem. This app enables organizations to keep their software data up-to-date, facilitates collaboration, and promotes transparency through open data practices.

## Features

- 🔄 **Synchronize Software Data**: Automatically synchronize your software data across multiple catalogs.
- 📡 **Automatic Publication**: Publish and update software catalog information seamlessly.
- 🏢 **Organization Management**: Enhanced organization synchronization and management capabilities.
- 📊 **API Integration**: Comprehensive API for aangeboden gebruik and organization workflows.
- 🆓 **Open Source**: Licensed under the [EUPL](https://opensource.org/licenses/EUPL).

## Requirements

- PHP 8.0 or higher
- PostgreSQL 10+, SQLite, or MySQL 8.0+
- Nextcloud version 28 to 30
- System Cron is required for the app to function properly

## Installation

To install the Software Catalogus app, follow these steps:

1. **Download the App:**
   Download the latest release from the [GitHub repository](https://github.com/ConductionNL/SoftwareCatalogus/releases).

2. **Upload the App:**
   Upload the app to the `apps` directory of your Nextcloud installation.

3. **Enable the App:**
   Go to the "Apps" section in your Nextcloud instance and enable the **Software Catalogus** app.

4. **Configure System Cron:**
   Ensure that the System Cron is properly configured on your server to allow the app to function optimally.

## Core Features

### 🚀 Automatic User Management
- **User Creation**: Automatic Nextcloud account creation from contactgegevens objects
- **Username Generation**: Smart username creation from name fields (voornaam.achternaam)
- **Profile Synchronization**: User data kept in sync with OpenRegister

### 👥 Advanced Group Management
- **Role-Based Groups**: Automatic assignment to groups based on user roles (beheerder, inkoper)
- **Organization Groups**: Each organization gets its own group with automatic member assignment
- **Special Groups**: The 'ambtenaar' group is available for manual assignment (no longer automatically assigned)
- **Dynamic Updates**: Group memberships automatically updated when roles change

### 🏢 Organizational Hierarchy
- **Auto-Beheerder Assignment**: First user in organization automatically becomes beheerder
- **Manager Relationships**: Beheerders become managers for their organization's users
- **Hierarchy Management**: Multiple beheerders supported with seniority-based primary manager
- **Organization Groups**: Automatic group creation and management for each organization

### ⚡ Event-Driven Processing
- **Real-Time Updates**: Processes changes immediately via OpenRegister events
- **Multiple Event Types**: Handles creation, updates, deletion, locking, and reversion
- **Error Recovery**: Comprehensive error handling with detailed logging
- **Type Safety**: Robust handling of schema ID mismatches and data validation

## Documentation

Comprehensive documentation is available in the `docs/` directory:

### For Users and Administrators
- **[📖 User Guide](docs/USER_GUIDE.md)** - Complete guide for end users and system administrators
- **[⚙️ Configuration Guide](docs/CONFIGURATION.md)** - Setup instructions and troubleshooting

### For Developers and Integrators  
- **[🏗️ Architecture Documentation](docs/ARCHITECTURE.md)** - System design and component overview
- **[👥 Group Management Guide](docs/GROUP_MANAGEMENT.md)** - Detailed explanation of group logic
- **[🔌 API Reference](docs/API_REFERENCE.md)** - Technical API documentation

### Quick Start
1. **Install Prerequisites**: Ensure OpenRegister app is installed and enabled
2. **Configure Schemas**: Set up schema mappings in Admin Settings → Software Catalogus
3. **Test Processing**: Create a contactgegevens object in OpenRegister to verify automatic user creation
4. **Monitor Groups**: Check that users are assigned to appropriate groups

## Usage

Once installed, the Software Catalogus app will:

- **Automatic Processing**: Listen for OpenRegister events and process users/organizations automatically
- **Admin Interface**: Provide configuration interface in Admin Settings → Software Catalogus
- **Group Management**: Handle all user group assignments and organizational hierarchy
- **Manager Relationships**: Establish and maintain manager-subordinate relationships
