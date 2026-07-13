## Why

The `frontend` directory contains many empty scaffolding folders (e.g., `lib/pos/data/local`, `lib/pos/domain/entities`, `lib/product/data/datasouces`) that add noise to the codebase. Additionally, several parts of the frontend currently rely on hardcoded dummy text instead of real data from the backend APIs. Cleaning up unused folders and replacing dummy data with actual API integrations will make the application functional and maintainable.

## What Changes

- Remove unused empty folders across all feature modules in the `frontend/lib/features` directory.
- Audit frontend pages and widgets to identify hardcoded dummy text.
- Replace identified dummy text with real data fetched from existing backend APIs.

## Capabilities

### New Capabilities
- `api-integration-audit`: Audit and integrate real API data to replace dummy text in the frontend.

### Modified Capabilities
None.

## Impact

- Improved codebase navigation.
- The frontend will display real data from the database, ensuring end-to-end functionality.
