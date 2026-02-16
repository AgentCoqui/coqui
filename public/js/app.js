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

    /** POST with FormData (for file uploads — no JSON content-type). */
    async upload(path, formData) {
        const res = await fetch(this.base + path, {
            method: 'POST',
            body: formData,
        });
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

// ─── Toast notification ────────────────────────────────────────────────────────

function showToast(message, type = 'success') {
    const existing = document.querySelector('.coqui-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = `coqui-toast coqui-toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 2500);
}

// ─── Global Alpine Data: app ──────────────────────────────────────────────────

document.addEventListener('alpine:init', () => {
    Alpine.data('app', () => ({
        currentView: 'dashboard',
        sidebarCollapsed: false,
        connectionStatus: 'Connecting…',
        workspacePath: '',
        _pendingSessionId: null,
        _viewsLoaded: new Set(),

        get viewTitle() {
            const titles = {
                dashboard: 'Dashboard',
                sessions: 'Sessions',
                audit: 'Audit Log',
                settings: 'Settings',
                files: 'Workspace Files',
                docs: 'Documentation',
            };
            return titles[this.currentView] || '';
        },

        async init() {
            // Apply saved preferences before first paint
            if (typeof coquiPrefs !== 'undefined') {
                coquiPrefs.apply();
            }

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
                const sessionId = e.detail;
                this._pendingSessionId = sessionId;
                this.currentView = 'sessions';
            });
        },

        navigate(view) {
            this.currentView = view;
            // Resize charts when switching back to dashboard
            if (view === 'dashboard') {
                this.$nextTick(() => {
                    if (typeof resizeAllCharts === 'function') resizeAllCharts();
                });
            }
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
        _loaded: false,

        formatNumber,
        formatLatency,

        async load() {
            this._loaded = true;
            try {
                const [statsRes, modelRes] = await Promise.all([
                    api.get('/stats', { period: this.period }),
                    api.get('/models', { period: this.period }),
                ]);
                this.stats = statsRes;
                this.models = modelRes;

                // Load charts after a tick so canvas is rendered
                this.$nextTick(() => this.loadCharts());
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
        _loaded: false,

        formatNumber,
        formatLatency,
        formatDate,
        renderMarkdown,
        parseTools,

        async load() {
            if (!this._loaded) {
                this._loaded = true;

                // Listen for navigate-session events from audit log
                window.addEventListener('navigate-session', (e) => {
                    const sessionId = e.detail;
                    if (sessionId) {
                        this.$nextTick(() => this.selectSession(sessionId));
                    }
                });
            }

            try {
                const data = await api.get('/sessions', { page: this.page, limit: 25 });
                this.sessions = data.data || [];
                this.totalPages = data.total_pages || 1;
                this.filterSessions();
            } catch (e) {
                console.error('Sessions load error:', e);
            }

            // Check for pending session navigation from app store
            this.$nextTick(() => {
                const appData = Alpine.$data(document.querySelector('[x-data="app"]'));
                if (appData && appData._pendingSessionId) {
                    const sid = appData._pendingSessionId;
                    appData._pendingSessionId = null;
                    this.selectSession(sid);
                }
            });
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
        _loaded: false,
        periods: [
            { value: '24h', label: '24h' },
            { value: '7d', label: '7 days' },
            { value: '30d', label: '30 days' },
            { value: 'all', label: 'All time' },
        ],

        formatDate,

        async load() {
            this._loaded = true;
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
        configSaveStatus: '',
        envSaveStatus: '',
        _configEditor: null,
        _envEditor: null,
        _loaded: false,

        async load() {
            this._loaded = true;
            try {
                const data = await api.get('/config');
                this.configPath = 'openclaw.json';

                // Initialize CodeMirror for config
                this.$nextTick(() => {
                    const content = JSON.stringify(data.config || data, null, 2);
                    if (!this._configEditor) {
                        this._configEditor = createCodeMirrorEditor(
                            'configEditor',
                            content,
                            'application/json',
                        );
                        if (this._configEditor) {
                            // Ctrl+S / Cmd+S to save
                            this._configEditor.setOption('extraKeys', {
                                'Ctrl-S': () => this.saveConfig(),
                                'Cmd-S': () => this.saveConfig(),
                            });
                            this._configEditor.on('change', () => {
                                this.configDirty = true;
                                this.configSaveStatus = '';
                            });
                        }
                    } else {
                        this._configEditor.setValue(content);
                    }
                });

                // Load credentials — backend returns { credentials: [{key, is_set}] }
                const creds = await api.get('/credentials');
                this.credentials = creds.credentials || [];
            } catch (e) {
                console.error('Settings load error:', e);
            }
        },

        loadEnv() {
            api.get('/env').then(data => {
                this.$nextTick(() => {
                    if (!this._envEditor) {
                        this._envEditor = createCodeMirrorEditor('envEditor', data.content || '', 'properties');
                        if (this._envEditor) {
                            // Ctrl+S / Cmd+S to save
                            this._envEditor.setOption('extraKeys', {
                                'Ctrl-S': () => this.saveEnv(),
                                'Cmd-S': () => this.saveEnv(),
                            });
                            this._envEditor.on('change', () => {
                                this.envDirty = true;
                                this.envSaveStatus = '';
                            });
                            // Refresh after tab becomes visible
                            setTimeout(() => this._envEditor.refresh(), 50);
                        }
                    } else {
                        this._envEditor.setValue(data.content || '');
                        setTimeout(() => this._envEditor.refresh(), 50);
                    }
                });
            }).catch(e => {
                console.error('Env load error:', e);
            });
        },

        async saveConfig() {
            if (!this._configEditor) return;
            try {
                const raw = this._configEditor.getValue();
                const parsed = JSON.parse(raw);
                await api.put('/config', parsed);
                this.configDirty = false;
                this.configSaveStatus = 'saved';
                showToast('Config saved successfully');
                setTimeout(() => { this.configSaveStatus = ''; }, 2500);
            } catch (e) {
                this.configSaveStatus = 'error';
                showToast('Error saving config: ' + e.message, 'error');
            }
        },

        async addCredential() {
            if (!this.newCredKey || !this.newCredValue) return;
            try {
                await api.post('/credentials', { key: this.newCredKey, value: this.newCredValue });
                this.credentials.push({ key: this.newCredKey, is_set: true });
                this.newCredKey = '';
                this.newCredValue = '';
                showToast('Credential added');
            } catch (e) {
                showToast('Error setting credential: ' + e.message, 'error');
            }
        },

        async deleteCredential(key) {
            if (!confirm('Delete credential ' + key + '?')) return;
            try {
                await api.del('/credentials/' + encodeURIComponent(key));
                this.credentials = this.credentials.filter(c => c.key !== key);
                showToast('Credential deleted');
            } catch (e) {
                showToast('Error deleting credential: ' + e.message, 'error');
            }
        },

        async saveEnv() {
            if (!this._envEditor) return;
            try {
                await api.put('/env', { content: this._envEditor.getValue() });
                this.envDirty = false;
                this.envSaveStatus = 'saved';
                showToast('.env saved successfully');
                setTimeout(() => { this.envSaveStatus = ''; }, 2500);
            } catch (e) {
                this.envSaveStatus = 'error';
                showToast('Error saving .env: ' + e.message, 'error');
            }
        },
    }));

    // ─── Files View ───────────────────────────────────────────────────────

    Alpine.data('filesView', () => ({
        tree: [],
        selectedFile: null,
        fileInfo: {},
        fileDirty: false,
        fileSaveStatus: '',
        _fileEditor: null,
        _changeHandler: null,
        _loaded: false,

        formatBytes,

        async load() {
            this._loaded = true;
            try {
                const data = await api.get('/files/tree');
                this.tree = data.tree || data || [];
            } catch (e) {
                console.error('File tree load error:', e);
            }
        },

        async openFile(path) {
            this.selectedFile = path;
            this.fileSaveStatus = '';
            try {
                const data = await api.get('/files/read', { path });
                this.fileInfo = data;
                if (data.binary) return;

                this.$nextTick(() => {
                    const mode = getEditorMode(path);
                    // Always recreate the editor — CM5 mode switching between
                    // complex modes (e.g. PHP/htmlmixed) causes null-state crashes.
                    this._fileEditor = createCodeMirrorEditor('fileEditor', data.content || '', mode);
                    if (this._fileEditor) {
                        this._fileEditor.setOption('extraKeys', {
                            'Ctrl-S': () => this.saveFile(),
                            'Cmd-S': () => this.saveFile(),
                        });
                        this._changeHandler = () => {
                            this.fileDirty = true;
                            this.fileSaveStatus = '';
                        };
                        this._fileEditor.on('change', this._changeHandler);
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
                this.fileSaveStatus = 'saved';
                showToast('File saved successfully');
                setTimeout(() => { this.fileSaveStatus = ''; }, 2500);
            } catch (e) {
                this.fileSaveStatus = 'error';
                showToast('Error saving file: ' + e.message, 'error');
            }
        },
    }));

    // ─── Documentation View ───────────────────────────────────────────────

    Alpine.data('docsView', () => ({
        docs: [],
        selectedDoc: null,
        docHtml: '',
        _loaded: false,

        renderMarkdown,

        async load() {
            if (this._loaded) return;
            this._loaded = true;

            try {
                const data = await api.get('/docs');
                this.docs = data.files || [];

                // Auto-load README.md
                if (this.docs.length > 0) {
                    const readme = this.docs.find(d => d.name === 'README.md') || this.docs[0];
                    this.selectDoc(readme.name);
                }
            } catch (e) {
                console.error('Docs load error:', e);
            }
        },

        async selectDoc(name) {
            this.selectedDoc = name;
            try {
                const data = await api.get('/docs/' + encodeURIComponent(name));
                this.docHtml = renderMarkdown(data.content || '');
            } catch (e) {
                this.docHtml = '<p class="text-muted">Failed to load document.</p>';
            }
        },
    }));

    // ─── Preferences View (inline in settings) ───────────────────────────

    Alpine.data('preferencesView', () => ({
        fontSize: 'medium',
        colorScheme: 'default',
        wallpapers: [],
        selectedWallpaper: '',
        rotateWallpaper: false,
        fpsEnabled: false,
        fpsShowFps: true,
        fpsShowMs: false,
        fpsShowMb: false,

        init() {
            // Load preferences from localStorage
            if (typeof coquiPrefs !== 'undefined') {
                this.fontSize = coquiPrefs.get('fontSize') || 'medium';
                this.colorScheme = coquiPrefs.get('colorScheme') || 'default';
                this.selectedWallpaper = coquiPrefs.get('wallpaper') || '';
                this.rotateWallpaper = coquiPrefs.get('rotateWallpaper') === 'true';
                this.fpsEnabled = coquiPrefs.get('fpsEnabled') === 'true';
                this.fpsShowFps = coquiPrefs.get('fpsShowFps') !== 'false';
                this.fpsShowMs = coquiPrefs.get('fpsShowMs') === 'true';
                this.fpsShowMb = coquiPrefs.get('fpsShowMb') === 'true';
            }
            this.loadWallpapers();
        },

        async loadWallpapers() {
            try {
                const data = await api.get('/wallpapers');
                this.wallpapers = data.wallpapers || [];
            } catch { /* wallpapers not available */ }
        },

        setFontSize(size) {
            this.fontSize = size;
            coquiPrefs.set('fontSize', size);
            coquiPrefs.applyFontSize(size);
        },

        setColorScheme(scheme) {
            this.colorScheme = scheme;
            coquiPrefs.set('colorScheme', scheme);
            coquiPrefs.applyColorScheme(scheme);
        },

        setWallpaper(name) {
            this.selectedWallpaper = name;
            coquiPrefs.set('wallpaper', name);
            coquiPrefs.applyWallpaper(name);
        },

        setRotateWallpaper(enabled) {
            this.rotateWallpaper = enabled;
            coquiPrefs.set('rotateWallpaper', String(enabled));
        },

        toggleFps(enabled) {
            this.fpsEnabled = enabled;
            coquiPrefs.set('fpsEnabled', String(enabled));
            if (enabled) {
                coquiPrefs.initStats({
                    fps: this.fpsShowFps,
                    ms: this.fpsShowMs,
                    mb: this.fpsShowMb,
                });
            } else {
                coquiPrefs.destroyStats();
            }
        },

        toggleFpsPanel(panel, enabled) {
            this['fpsShow' + panel.charAt(0).toUpperCase() + panel.slice(1)] = enabled;
            coquiPrefs.set('fpsShow' + panel.charAt(0).toUpperCase() + panel.slice(1), String(enabled));
            if (this.fpsEnabled) {
                coquiPrefs.destroyStats();
                coquiPrefs.initStats({
                    fps: this.fpsShowFps,
                    ms: this.fpsShowMs,
                    mb: this.fpsShowMb,
                });
            }
        },

        async uploadWallpaper(event) {
            const file = event.target.files?.[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('wallpaper', file);

            try {
                await api.upload('/wallpapers', formData);
                showToast('Wallpaper uploaded');
                await this.loadWallpapers();
            } catch (e) {
                showToast('Upload failed: ' + e.message, 'error');
            }
            event.target.value = '';
        },

        async deleteWallpaper(name) {
            if (!confirm('Delete wallpaper ' + name + '?')) return;
            try {
                await api.del('/wallpapers/' + encodeURIComponent(name));
                if (this.selectedWallpaper === name) {
                    this.setWallpaper('');
                }
                await this.loadWallpapers();
                showToast('Wallpaper deleted');
            } catch (e) {
                showToast('Error: ' + e.message, 'error');
            }
        },
    }));
});
