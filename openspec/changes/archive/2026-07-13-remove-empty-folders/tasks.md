## 1. Automated Cleanup

- [x] 1.1 Execute cleanup command to find and remove all empty directories under `frontend/lib/features`
- [x] 1.2 Verify that no source code files or non-empty directories were accidentally deleted

## 2. API Integration & Dummy Data Audit

- [x] 2.1 Search frontend codebase (`frontend/lib/features`) for hardcoded text, mock lists, or dummy states.
- [x] 2.2 Cross-reference identified dummy data locations with existing endpoints in `docs/api/endpoints.md`.
- [x] 2.3 Update UI widgets and state management (e.g. Blocs, Providers) to fetch data from real API endpoints using `ApiClient`.
- [x] 2.4 Test the updated UI to verify that data loads correctly from the backend database.
