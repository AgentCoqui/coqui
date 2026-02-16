/**
 * Coqui Dashboard — Core Application
 *
 * Alpine.js stores + API wrapper + global helpers.
 */

// ─── API Client ────────────────────────────────────────────────────────────────

const api = {
    base: '/api',

    async get(path, params = {}) {
        const url = new URL(this.base + path, location.origin);
        for (const [k, v] of Object.entries(params)) {
            if (v !== '' && v !== null && v !== undefined) url.searchParams.set(k, v);
        }
        const res = await fetch(url);
        if (!res.ok) throw new Error(`API ${res.status}: ${await res.text()}`);
        return res.json();
    },

    async post(path, body = {}) {
        const res = await fetch(this.base + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (!res.ok) throw new Error(`API ${res.status}: ${await res.text()}`);
        return res.json();
    },

    async put(path, body = {}) {
        const res = await fetch(this.base + path, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (!res.ok) throw new Error(`API ${res.status}: ${await res.text()}`);
        return res.json();
    },

    async del(path) {
        const res = await fetch(this.base + path, { method: 'DELETE' });
        if (!res.ok) throw new Error(`API ${res.status}: ${await res.text()}`);
        return res.json();
    },
};

// ─── Format helpers ────────────────────────────────────────────────────────────

function formatNumber(n) {
    if (n == null) return '—';
    n = Number(n);
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
    if (n >= 1_000) return (n / 1_000).toFixed(1) + 'K';
    return n.toLocaleString();
}

function formatLatency(ms) {
    if (ms == null) return '—';
    ms = Number(ms);
    if (ms >= 60_000) return (ms / 60_000).toFixed(1) + 'm';
    if (ms >= 1_000) return (ms / 1_000).toFixed(1) + 's';
    return Math.round(ms) + 'ms';
}

function formatDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    const diffMs = now - d;
    const diffMins = Math.floor(diffMs / 60_000);

    if (diffMins < 1) return 'just now';
    if (diffMins < 60) return diffMins + 'm ago';
    if (diffMins < 1440) return Math.floor(diffMins / 60) + 'h ago';
    if (diffMins < 10080) return Math.floor(diffMins / 1440) + 'd ago';

    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatBytes(bytes) {
    if (bytes == null) return '—';
    bytes = Number(bytes);
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function parseTools(toolsStr) {
    if (!toolsStr) return [];
    try { return JSON.parse(toolsStr); } catch { return toolsStr.split(',').map(s => s.trim()); }
}

function truncateArgs(args) {
    if (!args) return '—';
    if (args.length <= 80) return args;
    return args.substring(0, 80) + '…';
}

function renderMarkdown(text) {
    if (!text) return '';
    if (typeof marked !== 'undefined') {
        return marked.parse(text, {
            gfm: true,
            breaks: true,
            highlight: (code, lang) => {
                if (typeof hljs !== 'undefined' && lang && hljs.getLanguage(lang)) {
                    return hljs.highlight(code, { language: lang }).value;
                }
                return code;
            },
        });
    }
    // Fallback: escape HTML
    return text.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
}

// ─── Global Alpine Data: app ──────────────────────────────────────────────────

document.addEventListener('alpine:init', () => {
    Alpine.data('app', () => ({
        currentView: 'dashboard',
        sidebarCollapsed: false,
        connectionStatus: 'Connecting…',
        workspacePath: '',

        get viewTitle() {
            const titles = {
                dashboard: 'Dashboard',
                sessions: 'Sessions',
                audit: 'Audit Log',
                settings: 'Settings',
                files: 'Workspace Files',
            };
            return titles[this.currentView] || '';
        },

        async init() {
            // Verify API connectivity
            try {
                const data = await api.get('/health');
                this.connectionStatus = data.database === 'connected' ? 'Connected' : 'DB Unavailable';
                this.workspacePath = data.workspace_path || '.workspace/';
            } catch {
                this.connectionStatus = 'Disconnected';
            }

            // Listen for cross-view navigation (e.g. audit → session)
            window.addEventListener('navigate-session', (e) => {
                this.currentView = 'sessions';
            });
        },

        navigate(view) {
            this.currentView = view;
        },
    }));

    // ─── Dashboard View ───────────────────────────────────────────────────

    Alpine.data('dashboardView', () => ({
        period: '7d',
        periods: [
            { value: '24h', label: '24h' },
            { value: '7d', label: '7 days' },
            { value: '30d', label: '30 days' },
            { value: 'all', label: 'All time' },
        ],
        stats: {},
        models: [],

        formatNumber,
        formatLatency,

        async load() {
            try {
                const [statsRes, modelRes] = await Promise.all([
                    api.get('/stats', { period: this.period }),
                    api.get('/models', { period: this.period }),
                ]);
                this.stats = statsRes;
                this.models = modelRes;

                // Load charts
                await this.loadCharts();
            } catch (e) {
                console.error('Dashboard load error:', e);
            }
        },

        async loadCharts() {
            try {
                const [tokenData, toolData] = await Promise.all([
                    api.get('/tokens', { period: this.period }),
                    api.get('/tools', { period: this.period }),
                ]);
                renderTokenChart('tokenChart', tokenData);
                renderToolChart('toolChart', toolData);
            } catch (e) {
                console.error('Chart load error:', e);
            }
        },
    }));

    // ─── Sessions View ────────────────────────────────────────────────────

    Alpine.data('sessionsView', () => ({
        sessions: [],
        filteredSessions: [],
        selectedSession: null,
        turns: [],
        expandedTurn: null,
        searchQuery: '',
        page: 1,
        totalPages: 1,

        formatNumber,
        formatLatency,
        formatDate,
        renderMarkdown,
        parseTools,

        async load() {
            try {
                const data = await api.get('/sessions', { page: this.page, limit: 25 });
                this.sessions = data.data || [];
                this.totalPages = data.total_pages || 1;
                this.filterSessions();
            } catch (e) {
                console.error('Sessions load error:', e);
            }
        },

        filterSessions() {
            if (!this.searchQuery) {
                this.filteredSessions = this.sessions;
                return;
            }
            const q = this.searchQuery.toLowerCase();
            this.filteredSessions = this.sessions.filter(s =>
                s.id.toLowerCase().includes(q) ||
                (s.model && s.model.toLowerCase().includes(q))
            );
        },

        async selectSession(id) {
            try {
                const [session, turnsData] = await Promise.all([
                    api.get('/sessions/' + id),
                    api.get('/sessions/' + id + '/turns'),
                ]);
                this.selectedSession = session;
                this.turns = Array.isArray(turnsData) ? turnsData : (turnsData.data || turnsData.turns || []);
                this.expandedTurn = null;
            } catch (e) {
                console.error('Session load error:', e);
            }
        },

        toggleTurn(id) {
            this.expandedTurn = this.expandedTurn === id ? null : id;
        },

        prevPage() {
            if (this.page > 1) { this.page--; this.load(); }
        },
        nextPage() {
            if (this.page < this.totalPages) { this.page++; this.load(); }
        },
    }));

    // ─── Audit Log View ───────────────────────────────────────────────────

    Alpine.data('auditView', () => ({
        entries: [],
        page: 1,
        totalPages: 1,
        total: 0,
        filterAction: '',
        filterTool: '',
        availableTools: [],
        period: '7d',
        periods: [
            { value: '24h', label: '24h' },
            { value: '7d', label: '7 days' },
            { value: '30d', label: '30 days' },
            { value: 'all', label: 'All time' },
        ],

        formatDate,

        async load() {
            try {
                // Load filter options
                if (this.availableTools.length === 0) {
                    const opts = await api.get('/filters');
                    this.availableTools = opts.tools || [];
                }

                const data = await api.get('/audit', {
                    page: this.page,
                    limit: 50,
                    action: this.filterAction,
                    tool: this.filterTool,
                    period: this.period,
                });
                this.entries = data.data || [];
                this.totalPages = data.total_pages || 1;
                this.total = data.total || 0;
            } catch (e) {
                console.error('Audit load error:', e);
            }
        },

        actionBadgeClass(action) {
            const map = { approved: 'badge-success', denied: 'badge-warning', blocked: 'badge-destructive' };
            return map[action] || 'badge-default';
        },

        truncateArgs,

        exportJson() {
            const blob = new Blob([JSON.stringify(this.entries, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = 'audit-log.json'; a.click();
            URL.revokeObjectURL(url);
        },
    }));

    // ─── Settings View ────────────────────────────────────────────────────

    Alpine.data('settingsView', () => ({
        tab: 'config',
        configPath: '',
        configDirty: false,
        credentials: [],
        newCredKey: '',
        newCredValue: '',
        envDirty: false,
        _configEditor: null,
        _envEditor: null,

        async load() {
            try {
                const data = await api.get('/config');
                this.configPath = 'openclaw.json';
                // Initialize Monaco for config
                this.$nextTick(() => {
                    if (!this._configEditor) {
                        this._configEditor = createMonacoEditor('configEditor', JSON.stringify(data, null, 2), 'json');
                        if (this._configEditor) {
                            this._configEditor.onDidChangeModelContent(() => { this.configDirty = true; });
                        }
                    } else {
                        this._configEditor.setValue(JSON.stringify(data, null, 2));
                    }
                });

                // Load credentials
                const creds = await api.get('/credentials');
                this.credentials = creds.keys ? creds.keys.map(k => ({ key: k })) : [];
            } catch (e) {
                console.error('Settings load error:', e);
            }
        },

        async loadEnv() {
            try {
                const data = await api.get('/env');
                this.$nextTick(() => {
                    if (!this._envEditor) {
                        this._envEditor = createMonacoEditor('envEditor', data.content || '', 'ini');
                        if (this._envEditor) {
                            this._envEditor.onDidChangeModelContent(() => { this.envDirty = true; });
                        }
                    } else {
                        this._envEditor.setValue(data.content || '');
                    }
                });
            } catch (e) {
                console.error('Env load error:', e);
            }
        },

        async saveConfig() {
            if (!this._configEditor) return;
            try {
                const raw = this._configEditor.getValue();
                const parsed = JSON.parse(raw); // Validate JSON
                await api.put('/config', parsed);
                this.configDirty = false;
            } catch (e) {
                alert('Error saving config: ' + e.message);
            }
        },

        async addCredential() {
            if (!this.newCredKey || !this.newCredValue) return;
            try {
                await api.post('/credentials', { key: this.newCredKey, value: this.newCredValue });
                this.credentials.push({ key: this.newCredKey });
                this.newCredKey = '';
                this.newCredValue = '';
            } catch (e) {
                alert('Error setting credential: ' + e.message);
            }
        },

        async deleteCredential(key) {
            if (!confirm('Delete credential ' + key + '?')) return;
            try {
                await api.del('/credentials/' + encodeURIComponent(key));
                this.credentials = this.credentials.filter(c => c.key !== key);
            } catch (e) {
                alert('Error deleting credential: ' + e.message);
            }
        },

        async saveEnv() {
            if (!this._envEditor) return;
            try {
                await api.put('/env', { content: this._envEditor.getValue() });
                this.envDirty = false;
            } catch (e) {
                alert('Error saving .env: ' + e.message);
            }
        },
    }));

    // ─── Files View ───────────────────────────────────────────────────────

    Alpine.data('filesView', () => ({
        tree: [],
        selectedFile: null,
        fileInfo: {},
        fileDirty: false,
        _fileEditor: null,

        formatBytes,

        async load() {
            try {
                const data = await api.get('/files/tree');
                this.tree = data.tree || data || [];
            } catch (e) {
                console.error('File tree load error:', e);
            }
        },

        async openFile(path) {
            this.selectedFile = path;
            try {
                const data = await api.get('/files/read', { path });
                this.fileInfo = data;
                if (data.binary) return;

                this.$nextTick(() => {
                    const lang = data.language || 'plaintext';
                    if (!this._fileEditor) {
                        this._fileEditor = createMonacoEditor('fileEditor', data.content || '', lang);
                        if (this._fileEditor) {
                            this._fileEditor.onDidChangeModelContent(() => { this.fileDirty = true; });
                        }
                    } else {
                        const model = this._fileEditor.getModel();
                        if (model) monaco.editor.setModelLanguage(model, lang);
                        this._fileEditor.setValue(data.content || '');
                    }
                    this.fileDirty = false;
                });
            } catch (e) {
                console.error('File read error:', e);
                this.fileInfo = { error: e.message };
            }
        },

        async saveFile() {
            if (!this._fileEditor || !this.selectedFile) return;
            try {
                await api.put('/files/write', {
                    path: this.selectedFile,
                    content: this._fileEditor.getValue(),
                });
                this.fileDirty = false;
            } catch (e) {
                alert('Error saving file: ' + e.message);
            }
        },
    }));
});
