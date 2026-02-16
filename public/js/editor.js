/**
 * Coqui Dashboard — Monaco Editor Factory
 *
 * Uses AMD loader (CDNJS) to load Monaco Editor on demand.
 * Provides dark-themed editor instances for config, env, and file editing.
 */

let monacoLoaded = false;
let monacoLoadPromise = null;

/**
 * Ensure Monaco Editor is loaded via AMD require.
 * Returns a Promise that resolves when monaco is globally available.
 */
function loadMonaco() {
    if (monacoLoaded && typeof monaco !== 'undefined') {
        return Promise.resolve();
    }

    if (monacoLoadPromise) {
        return monacoLoadPromise;
    }

    monacoLoadPromise = new Promise((resolve, reject) => {
        if (typeof require === 'undefined' || !require.config) {
            console.warn('Monaco AMD loader not available');
            reject(new Error('Monaco AMD loader not found'));
            return;
        }

        require.config({
            paths: {
                vs: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.52.2/min/vs',
            },
        });

        require(['vs/editor/editor.main'], () => {
            monacoLoaded = true;

            // Define the Coqui dark theme
            monaco.editor.defineTheme('coqui-dark', {
                base: 'vs-dark',
                inherit: true,
                rules: [
                    { token: 'comment', foreground: '6a9955', fontStyle: 'italic' },
                    { token: 'keyword', foreground: '569cd6' },
                    { token: 'string', foreground: 'ce9178' },
                    { token: 'number', foreground: 'b5cea8' },
                    { token: 'type', foreground: '4ec9b0' },
                    { token: 'variable', foreground: '9cdcfe' },
                    { token: 'function', foreground: 'dcdcaa' },
                ],
                colors: {
                    'editor.background': '#0a0a0f',
                    'editor.foreground': '#fafafa',
                    'editor.lineHighlightBackground': '#ffffff08',
                    'editor.selectionBackground': '#264f78',
                    'editorCursor.foreground': '#3b82f6',
                    'editorLineNumber.foreground': '#4a4a5a',
                    'editorLineNumber.activeForeground': '#a1a1aa',
                    'editor.inactiveSelectionBackground': '#3a3d41',
                    'editorIndentGuide.background1': '#1e1e2e',
                    'editorWidget.background': '#0a0a0f',
                    'editorWidget.border': '#27272a',
                    'input.background': '#18181b',
                    'input.border': '#27272a',
                    'input.foreground': '#fafafa',
                    'scrollbar.shadow': '#00000000',
                    'scrollbarSlider.activeBackground': '#3b82f660',
                    'scrollbarSlider.background': '#3b82f630',
                    'scrollbarSlider.hoverBackground': '#3b82f640',
                },
            });

            resolve();
        });
    });

    return monacoLoadPromise;
}

/**
 * Create a Monaco Editor instance in the given container.
 *
 * @param {string} containerId  DOM id of the container element
 * @param {string} value        Initial editor content
 * @param {string} language     Language mode (e.g. 'json', 'ini', 'php', 'javascript')
 * @returns {object|null}       Monaco editor instance, or null if Monaco not loaded yet
 */
function createMonacoEditor(containerId, value = '', language = 'plaintext') {
    const container = document.getElementById(containerId);
    if (!container) return null;

    // Clear previous editor
    container.innerHTML = '';

    // If Monaco is already loaded, create synchronously
    if (monacoLoaded && typeof monaco !== 'undefined') {
        return monaco.editor.create(container, editorOptions(value, language));
    }

    // Otherwise, load Monaco first, then create
    let editorInstance = null;

    loadMonaco().then(() => {
        editorInstance = monaco.editor.create(container, editorOptions(value, language));
        // Store reference on the container so callers can access it
        container._monacoEditor = editorInstance;
    }).catch((e) => {
        console.warn('Monaco Editor unavailable, falling back to textarea:', e);
        // Fallback: plain textarea
        container.innerHTML = `<textarea style="width:100%;height:100%;background:var(--background);color:var(--foreground);border:none;resize:none;padding:1rem;font-family:'JetBrains Mono',monospace;font-size:13px;">${escapeHtml(value)}</textarea>`;
    });

    return editorInstance;
}

/**
 * Standard editor options for all Coqui editors.
 */
function editorOptions(value, language) {
    return {
        value,
        language,
        theme: 'coqui-dark',
        fontSize: 13,
        fontFamily: "'JetBrains Mono', 'Fira Code', 'Cascadia Code', monospace",
        fontLigatures: true,
        lineNumbers: 'on',
        minimap: { enabled: false },
        scrollBeyondLastLine: false,
        wordWrap: 'on',
        automaticLayout: true,
        renderLineHighlight: 'line',
        cursorBlinking: 'smooth',
        cursorSmoothCaretAnimation: 'on',
        smoothScrolling: true,
        padding: { top: 12, bottom: 12 },
        bracketPairColorization: { enabled: true },
        guides: { indentation: true, bracketPairs: true },
        tabSize: 4,
        insertSpaces: true,
        folding: true,
        foldingStrategy: 'indentation',
        overviewRulerBorder: false,
        scrollbar: {
            verticalScrollbarSize: 8,
            horizontalScrollbarSize: 8,
        },
    };
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
