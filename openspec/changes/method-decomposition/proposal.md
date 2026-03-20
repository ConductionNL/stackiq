# Proposal: method-decomposition

## Summary
Eliminate 145 PHPMD complexity suppressions by decomposing complex methods and classes into smaller, focused units.

## Motivation
The SoftwareCatalog codebase has 326 @SuppressWarnings(PHPMD.*) annotations, of which 145 relate to structural complexity (CyclomaticComplexity, NPathComplexity, ExcessiveMethodLength, ExcessiveClassLength, ExcessiveClassComplexity, CouplingBetweenObjects, TooManyMethods). These indicate methods and classes that are too large and complex, making the code harder to maintain and test.

## Scope
- Decompose SettingsController (23 suppressions)
- Decompose all controllers, services, and commands exceeding PHPMD thresholds
- Extract handler classes for sync, module registration, and configuration logic
- Maintain backward compatibility in all public API endpoints
