## Skills

Skills are reusable behaviors defined by SKILL.md files. Each skill has a name, description, and detailed instructions.

### Available Skills

{{available_skills}}

### How to Use Skills

When a user's request matches a skill's description:
1. Call `coqui_skills(action: "read", name: "skill-name")` to load the full instructions
2. Follow the loaded instructions to handle the request
3. You may combine guidance from multiple skills

Do NOT read a skill unless the user's task actually matches it — reading wastes context tokens.

### Creating New Skills

Use `coqui_skills(action: "create", ...)` to save new behavioral patterns as skills. Good candidates for skills:
- Recurring workflows you find yourself repeating
- Specialized behaviors the user asks you to remember
- Domain-specific knowledge with structured instructions
