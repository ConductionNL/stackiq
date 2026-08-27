# Module Compliance Subscriber

## Overzicht

De Module Compliance Subscriber is een nieuwe functionaliteit in de Stackiq app die automatisch de `standaarden` property van module objecten synchroniseert op basis van gekoppelde compliance objecten.

## Functionaliteit

### Wat doet het?

1. **Luistert naar module updates**: De subscriber luistert naar `ObjectCreatedEvent` en `ObjectUpdatedEvent` voor module objecten
2. **Haalt compliance objecten op**: Voor elke module worden alle gekoppelde compliance objecten opgehaald
3. **Extracteert standaardversie UUIDs**: Van elk compliance object wordt de `standaardversie` UUID geëxtraheerd
4. **Synchroniseert standaarden**: De `standaarden` property van de module wordt bijgewerkt met alle unieke standaardversie UUIDs
5. **Slaat module opnieuw op**: Als de standaarden zijn gewijzigd, wordt de module automatisch opnieuw opgeslagen

### Architectuur

```
Module Update Event → ModuleComplianceSubscriber → ModuleComplianceService → ObjectService
```

## Bestanden

### 1. ModuleComplianceSubscriber.php
**Locatie**: `lib/EventListener/ModuleComplianceSubscriber.php`

De subscriber die luistert naar module events en de compliance service aanroept.

**Belangrijke methoden**:
- `handle(Event $event)`: Hoofdmethode die events verwerkt
- Controleert of het een module object betreft
- Roept de ModuleComplianceService aan

### 2. ModuleComplianceService.php
**Locatie**: `lib/Service/ModuleComplianceService.php`

De service die de logica bevat voor het synchroniseren van standaarden.

**Belangrijke methoden**:
- `handleModuleComplianceUpdate(object $moduleObject)`: Hoofdmethode voor compliance updates
- `getComplianceObjectsForModule(string $moduleUuid)`: Haalt compliance objecten op voor een module
- `extractStandaardversieUuids(array $complianceObjects)`: Extraheert standaardversie UUIDs
- `updateModuleStandaarden(object $moduleObject, array $standaardversieUuids)`: Werkt module standaarden bij

### 3. Application.php
**Locatie**: `lib/AppInfo/Application.php`

Registreert de subscriber en service in de Nextcloud container.

**Registraties**:
```php
// Subscriber registratie
$context->registerEventListener(ObjectCreatedEvent::class, ModuleComplianceSubscriber::class);
$context->registerEventListener(ObjectUpdatedEvent::class, ModuleComplianceSubscriber::class);

// Service registratie
$context->registerService(\OCA\Stackiq\Service\ModuleComplianceService::class, function ($container) {
    return new \OCA\Stackiq\Service\ModuleComplianceService(
        $container,
        $container->get(SettingsService::class),
        $container->get('Psr\Log\LoggerInterface')
    );
});
```

## Configuratie

### Vereiste Schemas

De functionaliteit vereist dat de volgende schemas zijn geconfigureerd:

1. **Module Schema**: `module_schema` in de voorzieningen configuratie
2. **Compliance Schema**: `compliancy_schema` in de voorzieningen configuratie

### Schema Eigenschappen

#### Module Schema
- `standaarden`: Array van strings (UUIDs van standaardversies)
- `compliancy`: Array van compliance objecten (related objects)

#### Compliance Schema
- `module`: Related object naar de module
- `standaardversie`: Related object naar de standaardversie (string UUID)

## Gebruik

### Automatische Synchronisatie

De synchronisatie gebeurt automatisch wanneer:

1. Een nieuwe module wordt aangemaakt
2. Een bestaande module wordt bijgewerkt
3. Compliance objecten worden toegevoegd/verwijderd/bijgewerkt

### Handmatige Test

Voor het testen van de functionaliteit is een test script beschikbaar:

```bash
# Eenvoudige service test
docker exec -u 33 master-nextcloud-1 php /var/www/html/apps-extra/stackiq/test_module_compliance_simple.php

# Volledige functionaliteit test (vereist geconfigureerde schemas)
docker exec -u 33 master-nextcloud-1 php /var/www/html/apps-extra/stackiq/test_module_compliance.php
```

## Logging

De functionaliteit logt uitgebreid voor debugging:

- **Debug level**: Schema matching, object counts, UUIDs
- **Info level**: Succesvolle verwerking, updates
- **Warning level**: Ontbrekende configuratie
- **Error level**: Exceptions en fouten

## Voorbeeld Workflow

1. **Module wordt aangemaakt** met lege `standaarden` array
2. **Compliance objecten worden toegevoegd** met verschillende `standaardversie` UUIDs
3. **Subscriber detecteert module update** en roept service aan
4. **Service haalt compliance objecten op** voor de module
5. **Standaardversie UUIDs worden geëxtraheerd** uit compliance objecten
6. **Module standaarden worden bijgewerkt** met de nieuwe UUIDs
7. **Module wordt opnieuw opgeslagen** met gesynchroniseerde standaarden

## Voordelen

- **Automatische synchronisatie**: Geen handmatige updates nodig
- **Consistente data**: Standaarden blijven altijd up-to-date
- **Performance**: Alleen updates wanneer nodig
- **Betrouwbaarheid**: Uitgebreide logging en error handling
- **Flexibiliteit**: Werkt met elke module en compliance configuratie

## Toekomstige Uitbreidingen

- **Bulk updates**: Voor het synchroniseren van meerdere modules tegelijk
- **Caching**: Voor betere performance bij grote datasets
- **Webhooks**: Voor externe notificaties van updates
- **Dashboard**: Voor het monitoren van synchronisatie status
