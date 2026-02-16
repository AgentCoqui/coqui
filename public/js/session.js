/**
 * Coqui Dashboard — Session / Markdown Rendering
 *
 * Configures marked.js with highlight.js for code blocks.
 * Provides rendering pipeline for session turn content.
 */

// ─── Configure marked.js ──────────────────────────────────────────────────────

if (typeof marked !== 'undefined') {
    marked.setOptions({
        gfm: true,
        breaks: true,
        pedantic: false,
    });

    // Custom renderer for code blocks with syntax highlighting
    const renderer = new marked.Renderer();

    renderer.code = function ({ text, lang }) {
        let highlighted = text;
        if (typeof hljs !== 'undefined') {
            if (lang && hljs.getLanguage(lang)) {
                try {
                    highlighted = hljs.highlight(text, { language: lang }).value;
                } catch { /* ignore */ }
            } else {
                try {
                    highlighted = hljs.highlightAuto(text).value;
                } catch { /* ignore */ }
            }
        }

        const langLabel = lang ? `<span class="code-lang">${lang}</span>` : '';
        return `<div class="code-block">
            <div class="code-block-header">${langLabel}<button class="copy-btn" onclick="copyCode(this)">Copy</button></div>
            <pre><code class="hljs${lang ? ' language-' + lang : ''}">${highlighted}</code></pre>
        </div>`;
    };

    renderer.table = function ({ header, rows }) {
        const headerHtml = header.map(cell =>
            `<th${cell.align ? ` style="text-align:${cell.align}"` : ''}>${cell.text}</th>`
        ).join('');
        const bodyHtml = rows.map(row =>
            '<tr>' + row.map(cell =>
                `<td${cell.align ? ` style="text-align:${cell.align}"` : ''}>${cell.text}</td>`
            ).join('') + '</tr>'
        ).join('');
        return `<div class="table-container"><table><thead><tr>${headerHtml}</tr></thead><tbody>${bodyHtml}</tbody></table></div>`;
    };

    marked.use({ renderer });
}

// ─── Copy code button handler ──────────────────────────────────────────────────

function copyCode(btn) {
    const pre = btn.closest('.code-block')?.querySelector('pre');
    if (!pre) return;

    const text = pre.textContent;
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = orig; }, 1500);
    });
}

// ─── Turn Rendering Helpers ────────────────────────────────────────────────────

/**
 * Render raw tool_calls JSON into a readable display.
 */
function renderToolCalls(toolCallsJson) {
    if (!toolCallsJson) return '';

    let calls;
    try {
        calls = typeof toolCallsJson === 'string' ? JSON.parse(toolCallsJson) : toolCallsJson;
    } catch {
        return `<pre class="text-xs text-muted">${toolCallsJson}</pre>`;
    }

    if (!Array.isArray(calls)) return '';

    return calls.map(call => {
        const name = call.function?.name || call.name || 'unknown';
        let args = call.function?.arguments || call.arguments || '';
        if (typeof args === 'string') {
            try { args = JSON.stringify(JSON.parse(args), null, 2); } catch { /* keep raw */ }
        } else {
            args = JSON.stringify(args, null, 2);
        }

        return `<div class="tool-call">
            <div class="tool-call-header">
                <span class="badge badge-info">${escapeHtml(name)}</span>
            </div>
            <pre class="text-xs"><code class="hljs language-json">${
                typeof hljs !== 'undefined'
                    ? hljs.highlight(args, { language: 'json' }).value
                    : escapeHtml(args)
            }</code></pre>
        </div>`;
    }).join('');
}

/**
 * Render a message from the messages table.
 */
function renderMessage(msg) {
    const roleBadge = {
        user: 'badge-info',
        assistant: 'badge-success',
        system: 'badge-warning',
        tool: 'badge-default',
    };

    const badge = roleBadge[msg.role] || 'badge-default';
    let content = '';

    if (msg.role === 'assistant' && msg.tool_calls) {
        content = renderToolCalls(msg.tool_calls);
    }

    if (msg.content) {
        if (msg.role === 'tool') {
            // Tool results — try to JSON-prettify
            let parsed;
            try { parsed = JSON.parse(msg.content); } catch { parsed = null; }
            if (parsed) {
                const pretty = JSON.stringify(parsed, null, 2);
                content += `<pre class="text-xs"><code class="hljs language-json">${
                    typeof hljs !== 'undefined'
                        ? hljs.highlight(pretty, { language: 'json' }).value
                        : escapeHtml(pretty)
                }</code></pre>`;
            } else {
                content += `<pre class="text-xs">${escapeHtml(msg.content)}</pre>`;
            }
        } else {
            content += renderMarkdown(msg.content);
        }
    }

    return `<div class="message message-${msg.role}">
        <div class="message-header">
            <span class="badge ${badge}">${msg.role}</span>
            <span class="text-xs text-muted">${formatDate(msg.created_at)}</span>
        </div>
        <div class="message-content markdown-content">${content}</div>
    </div>`;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
