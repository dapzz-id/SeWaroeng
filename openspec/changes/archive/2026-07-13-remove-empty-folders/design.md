## Context

The `frontend` codebase uses a feature-based folder structure, which has resulted in many empty directories (e.g., `data/local`, `domain/entities`). Additionally, during initial development, many UI components were built using hardcoded dummy text to visualize the layout. Now that backend APIs are available, we need to connect these UI components to real data.

## Goals / Non-Goals

**Goals:**
- Identify and remove all completely empty directories within the `frontend/lib/features` directory tree.
- Audit the frontend to find UI components displaying dummy text.
- Connect these components to existing backend API endpoints via `api_client.dart` or appropriate Repositories.

**Non-Goals:**
- Deleting any folder that contains files (even if the files are just stubs).
- Building new backend APIs (assuming the required APIs already exist).

## Decisions

- **Automated Removal:** Use a safe automated script/command to identify and delete empty directories.
- **API Integration:** Utilize the existing repository pattern and `ApiClient` to fetch real data from the backend. We will replace static `Text()` strings and mock lists with data provided by `FutureBuilder`, `Bloc`, or Provider states hooked to the backend.

## Risks / Trade-offs

- **Risk**: Deleting a folder that a developer was about to put a file into.
  - **Mitigation**: The folders can easily be recreated if needed.
- **Risk**: Missing dummy text that might end up in production.
  - **Mitigation**: Perform a thorough codebase search (e.g., searching for "dummy", "lorem", or known mock data patterns) and review the UI manually.
