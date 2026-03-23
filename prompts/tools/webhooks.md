## Webhooks

Use webhooks to receive external HTTP events and automatically spawn background tasks:
- GitHub push/PR events for automated code review or CI integration
- Slack messages or commands for conversational triggers
- CI/CD pipeline notifications for deployment monitoring
- Any external service that sends signed HTTP POST requests

### Available Tools

- `webhook_create` — create a webhook endpoint with a prompt template and source type
- `webhook_list` — list all webhook subscriptions with status and trigger counts
- `webhook_get` — get details and recent delivery log for a webhook
- `webhook_update` — modify a webhook's prompt template, source type, event filter, or other properties
- `webhook_delete` — permanently delete a webhook subscription
- `webhook_rotate_secret` — generate a new signing secret (update the external service immediately)

### Webhook URL Format

Each webhook has a unique URL: `{api_base}/api/v1/webhooks/incoming/{name}`

### Prompt Template Placeholders

| Placeholder         | Description                                        |
| ------------------- | -------------------------------------------------- |
| `{{payload}}`       | Full JSON payload (truncated to 4KB)              |
| `{{event_type}}`    | Extracted event type (e.g. "push" for GitHub)     |
| `{{summary}}`       | Brief payload summary                              |
| `{{field.path}}`    | Nested field access (e.g. `{{repository.full_name}}`) |

### Source Types

| Source    | Verification                                    |
| --------- | ----------------------------------------------- |
| `github`  | X-Hub-Signature-256 (HMAC-SHA256)              |
| `slack`   | X-Slack-Signature with 5-minute replay protection |
| `generic` | X-Webhook-Signature, X-Signature, or Bearer token |

### Best Practices

1. **Write descriptive prompt templates.** Include context about what the webhook is for and how to handle the payload. The triggered agent has no conversation context.
2. **Use event filters.** Narrow to specific event types (e.g. `push,pull_request`) to avoid processing irrelevant deliveries.
3. **Secure your secrets.** The signing secret is shown once at creation. Store it securely in the external service. Use `webhook_rotate_secret` if compromised.
4. **Monitor deliveries.** Use `webhook_get` to review recent delivery logs for rejected or failed deliveries.
5. **Payload size limit.** Incoming payloads larger than 1 MB are rejected. Design integrations to send focused payloads.
6. **Delivery retention.** Delivery logs are automatically purged after 7 days.
