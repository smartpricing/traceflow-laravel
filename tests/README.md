# TraceFlow SDK Tests

Questa directory contiene i test per il TraceFlow SDK.

## Test Suites

### 1. Unit Tests (`tests/Unit/`)
Test che non richiedono il server TraceFlow. Testano la logica interna dell'SDK.

**Esegui solo i test unitari:**
```bash
php vendor/bin/phpunit --testsuite Unit
```

### 2. Integration Tests (`tests/Feature/`)
Test che richiedono il server TraceFlow in esecuzione su `localhost:3009`.

**Esegui i test di integrazione:**
```bash
php vendor/bin/phpunit --testsuite Integration
```

### 3. Resilience Tests
Test di resilienza che verificano il comportamento dell'SDK in condizioni di errore.

**Esegui i test di resilienza:**
```bash
php vendor/bin/phpunit --testsuite Resilience
```

## Esecuzione Rapida

```bash
# Solo unit test (nessun server richiesto)
php vendor/bin/phpunit --testsuite Unit

# Tutti i test
php vendor/bin/phpunit

# Con output dettagliato
php vendor/bin/phpunit --testdox
```

## Variabili d'Ambiente

```env
TRACEFLOW_ENDPOINT=http://localhost:3009
TRACEFLOW_API_KEY=your-api-key-here
SKIP_INTEGRATION_TESTS=false
```

## Statistiche

```
├─ Unit Tests:          29 test ✅
├─ Integration Tests:   32 test ⚠️  (richiedono server)
├─ Resilience Tests:    13 test ✅
└─ TOTAL:              74 test
```
