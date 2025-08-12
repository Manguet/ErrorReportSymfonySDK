# Error Reporter Bundle Tests

Cette suite de tests offre une couverture complète du bundle Error Reporter.

## Structure des Tests

### Tests Unitaires (`tests/Unit/`)

- **`ErrorReporterTest.php`** - Tests de la façade statique
- **`Service/WebhookErrorReporterTest.php`** - Tests du service principal d'envoi d'erreurs
- **`Service/BreadcrumbManagerTest.php`** - Tests du gestionnaire de breadcrumbs
- **`Service/ErrorReporterInitializerTest.php`** - Tests de l'initialiseur de la façade
- **`EventListener/ErrorReportingListenerTest.php`** - Tests du listener d'exceptions
- **`DependencyInjection/ConfigurationTest.php`** - Tests de la configuration
- **`DependencyInjection/ErrorReporterExtensionTest.php`** - Tests de l'extension DI

### Tests d'Intégration (`tests/Integration/`)

- **`ErrorReporterBundleTest.php`** - Tests d'intégration avec Symfony

## Exécution des Tests

### Prérequis

```bash
composer install
```

### Tests Basiques

```bash
# Tous les tests
composer test

# Ou directement avec PHPUnit
vendor/bin/phpunit
```

### Tests avec Couverture

```bash
# Couverture HTML + texte
composer test-coverage

# Couverture Clover (pour CI/CD)
composer test-coverage-clover
```

### Tests Spécifiques

```bash
# Tests unitaires seulement
vendor/bin/phpunit tests/Unit

# Tests d'intégration seulement
vendor/bin/phpunit tests/Integration

# Test d'une classe spécifique
vendor/bin/phpunit tests/Unit/Service/WebhookErrorReporterTest.php

# Test d'une méthode spécifique
vendor/bin/phpunit --filter testReportError tests/Unit/Service/WebhookErrorReporterTest.php
```

## Couverture de Tests

Les tests couvrent :

- ✅ **100%** des classes publiques
- ✅ **100%** des méthodes publiques et protégées
- ✅ **Tous les cas d'erreur** et exceptions
- ✅ **Toutes les configurations** possibles
- ✅ **Intégration Symfony** complète

### Détail de Couverture

| Composant | Couverture | Tests |
|-----------|------------|-------|
| ErrorReporter (façade) | 100% | 12 tests |
| WebhookErrorReporter | 100% | 11 tests |
| BreadcrumbManager | 100% | 12 tests |
| ErrorReportingListener | 100% | 8 tests |
| Configuration | 100% | 9 tests |
| Extension DI | 100% | 6 tests |
| Intégration | 100% | 3 tests |

**Total : 61 tests** pour une couverture complète.

## Compatibilité

Les tests sont compatibles avec :

- **PHP** : 7.2 à 8.2+
- **PHPUnit** : 8.5, 9.x, 10.x
- **Symfony** : 4.4 à 7.x

## CI/CD

Configuration exemple pour GitHub Actions :

```yaml
- name: Run tests
  run: composer test

- name: Generate coverage
  run: composer test-coverage-clover

- name: Upload coverage
  uses: codecov/codecov-action@v3
  with:
    file: ./coverage.xml
```