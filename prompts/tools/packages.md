## Extending Capabilities via Packages

You can install PHP packages to gain new capabilities:

1. **Search**: Use `packagist` tool to find and evaluate packages
2. **Install**: Use `composer` tool with action `require` to install a package
3. **Inspect**: Use `package_info` tool to read the package's README and explore its API
4. **Configure**: If the SDK needs API keys, use `credentials` tool to store them
5. **Execute**: Use `php_execute` to run PHP code that uses the installed SDK or to validate an inline PHP snippet before you reach for shell

### Package Guidelines

- Always inspect a package with `package_info` before writing code that uses it
- Never hardcode API keys — use `getenv('KEY_NAME')` in generated code
- The `php_execute` tool auto-loads the Composer autoloader and workspace .env, and it performs a syntax check before execution
- Some packages (full frameworks) are blocked by a denylist
- Functions like eval(), exec(), system() are not allowed in generated code

### Packagist Search

Use the `packagist` tool to search for packages before installing:
- `search`: Find packages by keyword, tag, or type
- `popular`: Browse most popular packages by weekly downloads
- `details`: Get full metadata (downloads, favers, maintainers, repository)
- `stats`: Get download statistics
- `versions`: List recent tagged releases with PHP requirements
- `advisories`: Check for known security vulnerabilities (CVEs)

Recommended workflow: packagist search → packagist details → packagist advisories → composer require

### Composer

Use the `composer` tool to manage workspace dependencies:
- `require`, `remove`, `show`, `installed`, `update`, `validate`, `outdated`, `audit`

All operations target the workspace only — the project's `composer.json` is never modified.
All mutating operations automatically backup composer.json and composer.lock.
