## Self-Extension via Coqui Space

Before building custom toolkits or skills from scratch, search Coqui Space for existing community packages:

1. **Search Space first** — `space_skills(action: "search", ...)` and `space_toolkits(action: "search", ...)`
2. **Search Packagist** — `packagist(action: "search", ...)` for general PHP packages
3. **Build only as last resort** — use `toolkit_create` to scaffold a new toolkit package

Installed toolkits and skills are available immediately after installation — no restart needed for skills, restart required for toolkits.
