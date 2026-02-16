# Coqui Dashboard

A single-page web dashboard for monitoring Coqui agent sessions, token usage, tool audit logs, and workspace files.

## Architecture

The dashboard is a standalone PHP application in the `public/` directory with its own `composer.json`. It reads the Coqui SQLite database (read-only) and workspace files.

**No build step.** No Node.js. No bundlers. All frontend assets are vendored locally.

### Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Frontend framework | Alpine.js | 3.14.9 |
| Charts | Chart.js | 4.5.1 |
| Code editor | CodeMirror 5 | 5.65.21 |
| Markdown | marked.js | 15.0.12 |
| Syntax highlighting | highlight.js | 11.11.1 |
| FPS counter | stats.js | 0.17.0 |
| Backend router | bramus/router | ^1.6 |
| Database | SQLite via PDO | — |
| PHP | 8.4 CLI | — |

### Directory Structure

```
public/
├── index.html              # SPA shell (Alpine.js views)
├── router.php              # PHP built-in server router + API dispatch
├── composer.json            # Dashboard PHP dependencies
├── css/
│   └── theme.css           # shadcn-inspired theme (dark + light)
├── js/
│   ├── app.js              # API client, Alpine stores, format helpers
│   ├── charts.js           # Chart.js rendering (token + tool charts)
│   ├── editor.js           # CodeMirror editor factory (sync)
│   ├── preferences.js      # Preference manager (localStorage + apply)
│   └── session.js          # Session detail view helpers
├── vendor/                  # Vendored JS/CSS libraries (committed to git)
│   ├── alpinejs/
│   ├── chart.js/
│   ├── highlight.js/
│   ├── marked/
│   ├── codemirror5/
│   └── stats/
└── src/
    ├── Controller/
    │   ├── ApiController.php       # Stats, sessions, turns, audit endpoints
    │   ├── ConfigController.php    # openclaw.json + .env + credentials
    │   ├── DocsController.php      # Markdown docs from docs/ directory
    │   ├── FileController.php      # Workspace file browse/read/write
    │   └── WallpaperController.php # Wallpaper upload/serve/delete
    └── Service/
        └── DashboardQueryService.php  # Read-only SQLite queries
```

## Running

```bash
cd public/
composer install
php -S localhost:8080 router.php
```

The dashboard reads the SQLite database at `.workspace/data/coqui.db` and the config at `openclaw.json` (both relative to the project root).

## API Reference

All endpoints return JSON. Errors return `{ "error": "message" }` with appropriate HTTP status codes.

### Health

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/health` | Connection status, workspace path, config availability |

### Dashboard Stats

| Method | Path | Query Params | Description |
|--------|------|-------------|-------------|
| GET | `/api/stats` | `period` (24h, 7d, 30d, all) | Aggregate stats: sessions, tokens, turns, latency |
| GET | `/api/tokens` | `period` | Token usage over time (bucketed by period) |
| GET | `/api/tools` | `period` | Tool usage frequency (sorted by count) |
| GET | `/api/models` | `period` | Per-model usage breakdown |
| GET | `/api/filters` | — | Available filter options for audit log |

### Sessions

| Method | Path | Query Params | Description |
|--------|------|-------------|-------------|
| GET | `/api/sessions` | `page`, `limit` | Paginated session list |
| GET | `/api/sessions/{id}` | — | Single session details |
| GET | `/api/sessions/{id}/turns` | — | Turns for a session |
| GET | `/api/sessions/{id}/turns/{turnId}` | — | Single turn details |
| GET | `/api/sessions/{id}/messages` | — | Messages for a session |
| GET | `/api/sessions/{id}/child-runs` | — | Child agent runs |

### Audit Log

| Method | Path | Query Params | Description |
|--------|------|-------------|-------------|
| GET | `/api/audit` | `page`, `limit`, `action`, `tool`, `period` | Paginated audit entries |

### Configuration

| Method | Path | Body | Description |
|--------|------|------|-------------|
| GET | `/api/config` | — | Read openclaw.json |
| PUT | `/api/config` | JSON object | Write openclaw.json |
| GET | `/api/env` | — | Read .workspace/.env |
| PUT | `/api/env` | `{ "content": "..." }` | Write .workspace/.env |

### Credentials

| Method | Path | Body | Description |
|--------|------|------|-------------|
| GET | `/api/credentials` | — | List credential keys + set status |
| POST | `/api/credentials` | `{ "key": "...", "value": "..." }` | Set a credential |
| DELETE | `/api/credentials/{key}` | — | Delete a credential |

### Files

| Method | Path | Query Params / Body | Description |
|--------|------|-------------------|-------------|
| GET | `/api/files` | `path` | List directory contents |
| GET | `/api/files/tree` | — | Full workspace file tree |
| GET | `/api/files/read` | `path` | Read file content |
| PUT | `/api/files/write` | `{ "path": "...", "content": "..." }` | Write file content |

### Documentation

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/docs` | List markdown files from docs/ directory |
| GET | `/api/docs/{name}` | Read a specific markdown file |

### Wallpapers

| Method | Path | Body | Description |
|--------|------|------|-------------|
| GET | `/api/wallpapers` | — | List available wallpapers |
| POST | `/api/wallpapers` | multipart/form-data (`wallpaper` field) | Upload a wallpaper image |
| GET | `/api/wallpapers/{name}/file` | — | Serve wallpaper image (binary) |
| DELETE | `/api/wallpapers/{name}` | — | Delete a wallpaper |

## Frontend Architecture

### Alpine.js Stores

Each view has its own Alpine.js data store registered via `Alpine.data()`:

| Store | Purpose |
|-------|---------|
| `app` | Global state: current view, sidebar, connection status, navigation |
| `dashboardView` | Stats cards, charts, period selection |
| `sessionsView` | Session list, filtering, turn timeline |
| `auditView` | Audit log table, filters, pagination, export |
| `settingsView` | Config/env editors (CodeMirror), credentials CRUD |
| `filesView` | File tree, file editor (CodeMirror), save |
| `docsView` | Documentation browser, markdown rendering |
| `preferencesView` | Font size, color scheme, wallpaper, FPS counter |

### Lazy Loading

Views other than Dashboard use `x-effect` to defer their initial `load()` call until the user navigates to them. Each store has a `_loaded` flag to prevent redundant API calls.

### Preferences

User preferences are stored in `localStorage` under the key `coqui-prefs` and applied immediately via CSS custom properties / data attributes:

| Preference | Values | Mechanism |
|-----------|--------|-----------|
| Font size | small, medium, large, x-large | `data-font-size` attribute on `<html>` |
| Color scheme | default (dark), light | `data-theme="light"` on `<html>` |
| Wallpaper | filename or empty | CSS `--wallpaper-url` variable on `<body>` |
| FPS counter | boolean | stats.js panels appended to DOM |

### Vendored Assets

All external JavaScript and CSS libraries are committed to `public/vendor/` for offline operation. No CDN dependencies at runtime.

To update a vendored library:

```bash
# Example: update Chart.js
curl -sL "https://cdn.jsdelivr.net/npm/chart.js@NEW_VERSION/dist/chart.umd.min.js" \
  -o public/vendor/chart.js/chart.umd.min.js
```

For CodeMirror 5, download the zip and extract:

```bash
curl -sL "https://codemirror.net/5/codemirror.zip" -o /tmp/codemirror5.zip
unzip /tmp/codemirror5.zip -d /tmp/codemirror5
cp -r /tmp/codemirror5/codemirror-NEW_VERSION/lib public/vendor/codemirror5/
cp -r /tmp/codemirror5/codemirror-NEW_VERSION/mode public/vendor/codemirror5/
cp -r /tmp/codemirror5/codemirror-NEW_VERSION/theme public/vendor/codemirror5/
cp -r /tmp/codemirror5/codemirror-NEW_VERSION/addon public/vendor/codemirror5/
```

## Security

- File operations are sandboxed to `.workspace/` via `realpath()` validation
- Wallpaper uploads validate file extension and size (10MB max)
- `.env` file permissions are set to `0600` after write
- No authentication (intended for local development use only)
- CORS headers allow all origins (local dev server)
