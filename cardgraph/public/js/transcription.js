/**
 * Card Graph — Transcription Admin
 *
 * Maintenance sub-tab for managing audio recording & transcription sessions.
 * Settings, environment check, session CRUD, lifecycle control, and live monitor.
 */
var TranscriptionAdmin = {
    initialized: false,
    pollTimer: null,
    monitorSessionId: null,
    segmentLengthMin: 15,
    activeSegStarted: null,
    serverTimeDelta: 0,
    currentSubTab: 'audio',

    init: function() {
        var panel = document.getElementById('maint-panel-transcription');
        if (!panel) return;

        if (!this.initialized) {
            var html = [];

            // Sub-tab bar: Audio | Table
            html.push('<div class="tx-sub-tabs" id="tx-sub-tabs">');
            html.push('<button class="tx-sub-tab active" data-subtab="audio">Audio</button>');
            html.push('<button class="tx-sub-tab" data-subtab="table">Table</button>');
            html.push('</div>');

            // Audio sub-panel (existing transcription content)
            html.push('<div id="tx-panel-audio" class="tx-sub-panel">');
            html.push('<div id="tx-sections">');

            // Section 1: Environment Check
            html.push('<div style="margin-bottom:24px;">');
            html.push('<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">');
            html.push('<h3 style="font-size:16px;font-weight:700;color:#1a1a2e;">Environment Check</h3>');
            html.push('<button class="btn btn-secondary btn-sm" id="tx-env-check-btn">Run Check</button>');
            html.push('</div>');
            html.push('<div id="tx-env-results"></div>');
            html.push('</div>');

            // Section 2: Settings
            html.push('<div style="margin-bottom:24px;">');
            html.push('<h3 style="font-size:16px;font-weight:700;color:#1a1a2e;margin-bottom:12px;">Settings</h3>');
            html.push('<div id="tx-settings-form"></div>');
            html.push('</div>');

            // Section 3: Sessions
            html.push('<div>');
            html.push('<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">');
            html.push('<h3 style="font-size:16px;font-weight:700;color:#1a1a2e;">Sessions</h3>');
            html.push('<button class="btn btn-success btn-sm" id="tx-new-session-btn">Schedule Session</button>');
            html.push('</div>');
            html.push('<div id="tx-sessions-area"></div>');
            html.push('</div>');

            html.push('</div>');

            // Monitor view (hidden by default)
            html.push('<div id="tx-monitor-view" style="display:none;"></div>');
            html.push('</div>'); // end tx-panel-audio

            // Table sub-panel (new — rendered by TableTranscriptionAdmin)
            html.push('<div id="tx-panel-table" class="tx-sub-panel" style="display:none;"></div>');

            panel.innerHTML = html.join('\n');

            var self = this;
            document.getElementById('tx-env-check-btn').addEventListener('click', function() {
                self.runEnvCheck();
            });
            document.getElementById('tx-new-session-btn').addEventListener('click', function() {
                self.showSessionForm();
            });

            // Sub-tab click handlers
            document.querySelectorAll('#tx-sub-tabs .tx-sub-tab').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    self.switchSubTab(btn.dataset.subtab);
                });
            });

            this.initialized = true;
        }

        this.switchSubTab(this.currentSubTab);
    },

    switchSubTab: function(name) {
        this.currentSubTab = name;

        // Toggle button active state
        document.querySelectorAll('#tx-sub-tabs .tx-sub-tab').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.subtab === name);
        });

        // Toggle panel visibility
        var audioPanel = document.getElementById('tx-panel-audio');
        var tablePanel = document.getElementById('tx-panel-table');
        if (audioPanel) audioPanel.style.display = (name === 'audio') ? '' : 'none';
        if (tablePanel) tablePanel.style.display = (name === 'table') ? '' : 'none';

        // Load appropriate content
        if (name === 'audio') {
            this.loadSettings();
            this.loadSessions();
        } else if (name === 'table') {
            if (typeof TableTranscriptionAdmin !== 'undefined') {
                TableTranscriptionAdmin.init();
            }
        }
    },

    // ─── Settings ─────────────────────────────────────────────

    loadSettings: function() {
        var self = this;
        API.get('/api/transcription/settings').then(function(result) {
            self.renderSettingsForm(result.data);
        }).catch(function() {
            document.getElementById('tx-settings-form').innerHTML =
                '<p class="text-muted">Unable to load settings.</p>';
        });
    },

    renderSettingsForm: function(s) {
        var self = this;
        var html = [];
        html.push('<div class="tx-settings-grid">');

        // A. Recording
        html.push('<div class="tx-settings-card"><h4>A. Recording</h4>');
        html.push(this.settingField('Segment Length', 'number', 'tx-seg-len', s.segment_length_minutes, '5–60 min', 5, 60));
        html.push(this.settingSelect('Sample Rate', 'tx-sample-rate', s.sample_rate, [
            ['8000', '8 kHz'], ['16000', '16 kHz'], ['22050', '22 kHz']
        ]));
        html.push(this.settingSelect('Channels', 'tx-channels', s.audio_channels, [
            ['mono', 'Mono'], ['stereo', 'Stereo']
        ]));
        html.push(this.settingSelect('Format', 'tx-format', s.audio_format, [
            ['wav', 'WAV'], ['flac', 'FLAC']
        ]));
        html.push('</div>');

        // B. Silence Detection
        html.push('<div class="tx-settings-card"><h4>B. Silence Detection</h4>');
        html.push(this.settingField('Threshold (dBFS)', 'number', 'tx-silence-thresh', s.silence_threshold_dbfs, '-60 to -30', -60, -30));
        html.push(this.settingField('Timeout (min)', 'number', 'tx-silence-timeout', s.silence_timeout_minutes, '1–30 min', 1, 30));
        html.push('</div>');

        // C. Duration
        html.push('<div class="tx-settings-card"><h4>C. Max Duration</h4>');
        html.push(this.settingField('Max Hours', 'number', 'tx-max-hours', s.max_session_hours, '1–24 hrs', 1, 24));
        html.push('</div>');

        // D. Transcription
        html.push('<div class="tx-settings-card"><h4>D. Transcription</h4>');
        html.push(this.settingField('CPU Cores', 'number', 'tx-cpu-cores', s.max_cpu_cores, '1–3 (never 4)', 1, 3));
        html.push(this.settingSelect('Whisper Model', 'tx-whisper-model', s.whisper_model, [
            ['tiny', 'Tiny — 39M (fastest)'],
            ['base', 'Base — 74M (default)'],
            ['small', 'Small — 244M (recommended)'],
            ['medium', 'Medium — 769M (slower)'],
            ['large', 'Large — 1.5B (slowest)']
        ]));
        html.push(this.settingSelect('Priority', 'tx-priority', s.priority_mode, [
            ['low', 'Low'], ['normal', 'Normal']
        ]));
        html.push('</div>');

        // E. Storage
        html.push('<div class="tx-settings-card"><h4>E. Storage</h4>');
        html.push('<div class="tx-field-row">');
        html.push('<span class="tx-field-label">Archive Dir</span>');
        html.push('<input type="text" id="tx-archive-dir" value="' + this.escAttr(s.base_archive_dir) + '" style="width:260px;">');
        html.push('</div>');
        html.push(this.settingSelect('Folder Structure', 'tx-folder-struct', s.folder_structure, [
            ['year-based', 'Year-Based'], ['flat', 'Flat']
        ]));
        html.push(this.settingField('Min Free Disk (GB)', 'number', 'tx-min-disk', s.min_free_disk_gb, '1–50 GB', 1, 50));
        html.push(this.settingField('Auto-Delete After (days)', 'number', 'tx-retention-days', s.audio_retention_days, '7–365 days', 7, 365));
        html.push('</div>');

        // F. Acquisition
        html.push('<div class="tx-settings-card"><h4>F. Acquisition Mode</h4>');
        html.push(this.settingSelect('Mode', 'tx-acq-mode', s.acquisition_mode, [
            ['direct_stream', 'Direct Stream'], ['browser_automation', 'Browser Automation']
        ]));
        html.push('</div>');

        html.push('</div>');

        // Save button
        html.push('<div style="text-align:right;margin-bottom:24px;">');
        html.push('<button class="btn btn-primary" id="tx-save-settings">Save Settings</button>');
        html.push('</div>');

        document.getElementById('tx-settings-form').innerHTML = html.join('\n');

        document.getElementById('tx-save-settings').addEventListener('click', function() {
            self.saveSettings();
        });
    },

    settingField: function(label, type, id, value, hint, min, max) {
        return '<div class="tx-field-row">' +
            '<span class="tx-field-label">' + label + '</span>' +
            '<input type="' + type + '" id="' + id + '" value="' + value + '"' +
            (min !== undefined ? ' min="' + min + '"' : '') +
            (max !== undefined ? ' max="' + max + '"' : '') +
            '>' +
            (hint ? '<span class="tx-field-hint">' + hint + '</span>' : '') +
            '</div>';
    },

    settingSelect: function(label, id, current, options) {
        var opts = '';
        for (var i = 0; i < options.length; i++) {
            opts += '<option value="' + options[i][0] + '"' +
                (options[i][0] === current ? ' selected' : '') +
                '>' + options[i][1] + '</option>';
        }
        return '<div class="tx-field-row">' +
            '<span class="tx-field-label">' + label + '</span>' +
            '<select id="' + id + '">' + opts + '</select>' +
            '</div>';
    },

    saveSettings: function() {
        var data = {
            segment_length_minutes:  parseInt(document.getElementById('tx-seg-len').value) || 15,
            sample_rate:             document.getElementById('tx-sample-rate').value,
            audio_channels:          document.getElementById('tx-channels').value,
            audio_format:            document.getElementById('tx-format').value,
            silence_threshold_dbfs:  parseInt(document.getElementById('tx-silence-thresh').value) || -48,
            silence_timeout_minutes: parseInt(document.getElementById('tx-silence-timeout').value) || 10,
            max_session_hours:       parseInt(document.getElementById('tx-max-hours').value) || 10,
            max_cpu_cores:           parseInt(document.getElementById('tx-cpu-cores').value) || 2,
            whisper_model:           document.getElementById('tx-whisper-model').value,
            priority_mode:           document.getElementById('tx-priority').value,
            base_archive_dir:        document.getElementById('tx-archive-dir').value.trim(),
            folder_structure:        document.getElementById('tx-folder-struct').value,
            min_free_disk_gb:        parseInt(document.getElementById('tx-min-disk').value) || 5,
            audio_retention_days:    parseInt(document.getElementById('tx-retention-days').value) || 30,
            acquisition_mode:        document.getElementById('tx-acq-mode').value
        };

        API.put('/api/transcription/settings', data).then(function() {
            App.toast('Settings saved', 'success');
        }).catch(function(err) {
            App.toast(err.message || 'Failed to save settings', 'error');
        });
    },

    // ─── Environment Check ────────────────────────────────────

    runEnvCheck: function() {
        var container = document.getElementById('tx-env-results');
        container.innerHTML = '<p class="text-muted">Checking...</p>';

        API.get('/api/transcription/env-check').then(function(result) {
            var c = result.checks;
            var html = ['<div class="tx-env-grid">'];

            // Python
            html.push(TranscriptionAdmin.envItem(
                c.python.available, 'Python',
                c.python.available ? c.python.version : 'Not found'
            ));

            // ffmpeg
            html.push(TranscriptionAdmin.envItem(
                c.ffmpeg.available, 'ffmpeg',
                c.ffmpeg.available ? c.ffmpeg.version : 'Not found'
            ));

            // Whisper
            html.push(TranscriptionAdmin.envItem(
                c.whisper.available, 'Whisper',
                c.whisper.available ? 'v' + c.whisper.version : 'Not installed'
            ));

            // pymysql
            html.push(TranscriptionAdmin.envItem(
                c.pymysql.available, 'pymysql',
                c.pymysql.available ? 'v' + c.pymysql.version : 'Not installed'
            ));

            // Disk
            var diskOk = c.disk.available && c.disk.sufficient;
            html.push(TranscriptionAdmin.envItem(
                diskOk, 'Disk Space',
                c.disk.available ? c.disk.free_gb + ' GB free (min ' + c.disk.min_free_gb + ' GB)' : 'Cannot check',
                !diskOk && c.disk.available ? 'warn' : null
            ));

            // CPU
            html.push(TranscriptionAdmin.envItem(
                c.cpu.available, 'CPU Cores',
                c.cpu.available ? c.cpu.cores + ' cores' : 'Cannot detect'
            ));

            // Scripts
            var allScripts = c.scripts.manager && c.scripts.recorder && c.scripts.worker;
            var scriptInfo = [];
            if (!c.scripts.manager)  scriptInfo.push('manager missing');
            if (!c.scripts.recorder) scriptInfo.push('recorder missing');
            if (!c.scripts.worker)   scriptInfo.push('worker missing');
            if (c.scripts.browser_recorder === false) scriptInfo.push('docker/ dir missing');
            html.push(TranscriptionAdmin.envItem(
                allScripts, 'Python Scripts',
                allScripts ? 'All present' : scriptInfo.join(', ')
            ));

            // Docker (for browser_automation mode)
            if (c.docker) {
                html.push(TranscriptionAdmin.envItem(
                    c.docker.available, 'Docker',
                    c.docker.available ? c.docker.version : 'Not installed'
                ));
            }

            // Browser Recorder Image
            if (c.browser_recorder_image) {
                html.push(TranscriptionAdmin.envItem(
                    c.browser_recorder_image.available, 'Browser Recorder Image',
                    c.browser_recorder_image.available ? 'Image built' : 'Not built (run build.sh in tools/docker/)'
                ));
            }

            html.push('</div>');
            container.innerHTML = html.join('');
        }).catch(function(err) {
            container.innerHTML = '<p class="text-danger">Error: ' + (err.message || 'Failed') + '</p>';
        });
    },

    envItem: function(ok, name, detail, forceClass) {
        var cls = forceClass || (ok ? 'ok' : 'missing');
        var icon = ok ? '&#10003;' : '&#10007;';
        if (forceClass === 'warn') icon = '&#9888;';
        return '<div class="tx-env-item">' +
            '<div class="tx-env-icon ' + cls + '">' + icon + '</div>' +
            '<div class="tx-env-details">' +
            '<div class="tx-env-name">' + name + '</div>' +
            '<div class="tx-env-version">' + this.escHtml(detail) + '</div>' +
            '</div></div>';
    },

    // ─── Sessions ─────────────────────────────────────────────

    loadSessions: function() {
        var self = this;
        API.get('/api/transcription/sessions').then(function(result) {
            self.renderSessionsTable(result.data || []);
        }).catch(function() {
            document.getElementById('tx-sessions-area').innerHTML =
                '<p class="text-muted">Unable to load sessions.</p>';
        });
    },

    renderSessionsTable: function(sessions) {
        var self = this;
        var area = document.getElementById('tx-sessions-area');

        if (sessions.length === 0) {
            area.innerHTML = '<div class="empty-state"><p>No sessions scheduled yet.</p></div>';
            return;
        }

        var html = ['<div class="table-container"><table class="data-table">'];
        html.push('<thead><tr>');
        html.push('<th>Auction</th><th>Scheduled</th><th>Status</th><th>Segments</th><th>Transcribed</th><th>Duration</th><th>Actions</th>');
        html.push('</tr></thead><tbody>');

        for (var i = 0; i < sessions.length; i++) {
            var s = sessions[i];
            var statusCls = 'status-' + s.status;
            var dur = s.total_duration_sec > 0 ? this.formatDuration(parseInt(s.total_duration_sec)) : '-';

            // Transcription status
            var txComplete = parseInt(s.tx_complete) || 0;
            var txPending = parseInt(s.tx_pending) || 0;
            var txActive = parseInt(s.tx_active) || 0;
            var totalSegs = parseInt(s.total_segments) || 0;
            var txHtml = '-';
            if (totalSegs > 0) {
                var txColor = txComplete === totalSegs ? '#4caf50' : (txPending > 0 ? '#f57c00' : '#999');
                txHtml = '<span style="color:' + txColor + ';font-weight:600;">' + txComplete + '/' + totalSegs + '</span>';
                if (txActive > 0) txHtml += ' <span style="font-size:10px;color:#1565c0;">(active)</span>';
            }

            html.push('<tr>');
            html.push('<td>' + this.escHtml(s.auction_name) + '</td>');
            html.push('<td>' + App.formatDatetime(s.scheduled_start) + '</td>');
            html.push('<td><span class="status-badge ' + statusCls + '">' + s.status + '</span></td>');
            html.push('<td class="text-right">' + totalSegs + '</td>');
            html.push('<td class="text-center">' + txHtml + '</td>');
            html.push('<td>' + dur + '</td>');
            html.push('<td>' + this.sessionActions(s) + '</td>');
            html.push('</tr>');
        }

        html.push('</tbody></table></div>');
        area.innerHTML = html.join('');

        // Attach action handlers
        var btns = area.querySelectorAll('[data-tx-action]');
        for (var j = 0; j < btns.length; j++) {
            (function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var action = btn.getAttribute('data-tx-action');
                    var id = btn.getAttribute('data-session-id');
                    self.handleAction(action, parseInt(id));
                });
            })(btns[j]);
        }
    },

    sessionActions: function(s) {
        var btns = [];
        if (s.status === 'scheduled') {
            btns.push('<button class="btn btn-success btn-sm" data-tx-action="start" data-session-id="' + s.session_id + '">Start</button>');
            btns.push('<button class="btn btn-secondary btn-sm" data-tx-action="edit" data-session-id="' + s.session_id + '">Edit</button>');
            btns.push('<button class="btn btn-danger btn-sm" data-tx-action="delete" data-session-id="' + s.session_id + '">Delete</button>');
        } else if (s.status === 'recording' || s.status === 'processing') {
            btns.push('<button class="btn btn-primary btn-sm" data-tx-action="monitor" data-session-id="' + s.session_id + '">Monitor</button>');
            btns.push('<button class="btn btn-warning btn-sm" data-tx-action="stop" data-session-id="' + s.session_id + '">Stop</button>');
            btns.push('<button class="btn btn-danger btn-sm" data-tx-action="cancel" data-session-id="' + s.session_id + '">Cancel</button>');
        } else {
            btns.push('<button class="btn btn-secondary btn-sm" data-tx-action="view" data-session-id="' + s.session_id + '">View</button>');
            var txPending = parseInt(s.tx_pending) || 0;
            if (txPending > 0) {
                btns.push('<button class="btn btn-success btn-sm" data-tx-action="transcribe" data-session-id="' + s.session_id + '">Transcribe (' + txPending + ')</button>');
            }
            btns.push('<button class="btn btn-secondary btn-sm" data-tx-action="edit" data-session-id="' + s.session_id + '">Edit</button>');
            btns.push('<button class="btn btn-danger btn-sm" data-tx-action="delete" data-session-id="' + s.session_id + '">Delete</button>');
        }
        return btns.join(' ');
    },

    handleAction: function(action, id) {
        var self = this;
        switch (action) {
            case 'start':
                if (!confirm('Start recording session #' + id + '?')) return;
                API.post('/api/transcription/sessions/' + id + '/start').then(function() {
                    App.toast('Session started', 'success');
                    self.showSessionMonitor(id);
                }).catch(function(err) { App.toast(err.message, 'error'); });
                break;
            case 'stop':
                if (!confirm('Stop recording? Transcription of completed segments will continue.')) return;
                API.post('/api/transcription/sessions/' + id + '/stop').then(function() {
                    App.toast('Stop signal sent', 'success');
                }).catch(function(err) { App.toast(err.message, 'error'); });
                break;
            case 'cancel':
                if (!confirm('Cancel job? Both recording and transcription will stop.')) return;
                API.post('/api/transcription/sessions/' + id + '/cancel').then(function() {
                    App.toast('Cancel signal sent', 'success');
                }).catch(function(err) { App.toast(err.message, 'error'); });
                break;
            case 'delete':
                if (!confirm('Delete session #' + id + '?\n\nThis will remove all recordings, transcripts, and log data. This cannot be undone.')) return;
                API.del('/api/transcription/sessions/' + id).then(function() {
                    App.toast('Session deleted', 'success');
                    self.loadSessions();
                }).catch(function(err) { App.toast(err.message, 'error'); });
                break;
            case 'transcribe':
                if (!confirm('Transcribe pending audio segments for session #' + id + '?')) return;
                API.post('/api/transcription/sessions/' + id + '/transcribe').then(function(result) {
                    App.toast(result.message || 'Transcription started', 'success');
                    self.loadSessions();
                }).catch(function(err) {
                    App.toast(err.message || 'Failed to start transcription', 'error');
                });
                break;
            case 'edit':
                self.showSessionForm(id);
                break;
            case 'monitor':
            case 'view':
                self.showSessionMonitor(id);
                break;
        }
    },

    // ─── PC Worker Integration ─────────────────────────────────

    pcWorkerUrl: 'http://localhost:8891',
    pcPollTimer: null,
    pcLastStatus: null,

    checkPcWorker: function(sessionId) {
        var self = this;
        var dot = document.getElementById('tx-pc-dot');
        var state = document.getElementById('tx-pc-state');
        var controls = document.getElementById('tx-pc-controls');
        var detail = document.getElementById('tx-pc-detail');
        if (!dot || !state || !controls) return;

        fetch(self.pcWorkerUrl + '/status', {mode: 'cors'}).then(function(r) {
            return r.json();
        }).then(function(data) {
            dot.className = 'tx-pc-dot online';
            self.pcLastStatus = data.status;
            self.renderPcControls(data, sessionId, state, controls, detail);
        }).catch(function() {
            dot.className = 'tx-pc-dot offline';
            state.textContent = 'Not Running';
            state.className = 'tx-pc-state offline';
            self.pcLastStatus = 'offline';
            self.renderPcControlsOffline(sessionId, controls, detail);
        });
        // Always poll — picks up service if it comes online later
        self.startPcPolling(sessionId);
    },

    renderPcControls: function(data, sessionId, stateEl, controlsEl, detailEl) {
        var self = this;
        var status = data.status;
        var isActive = status === 'transcribing' || status === 'loading';
        var isStopping = status === 'stopping';

        if (status === 'idle') {
            stateEl.textContent = 'Ready';
            stateEl.className = 'tx-pc-state ready';
        } else if (status === 'loading') {
            stateEl.textContent = 'Loading model...';
            stateEl.className = 'tx-pc-state loading';
        } else if (isStopping) {
            stateEl.textContent = 'Finishing segment...';
            stateEl.className = 'tx-pc-state loading';
        } else if (status === 'transcribing') {
            stateEl.textContent = 'Transcribing';
            stateEl.className = 'tx-pc-state active';
        }

        // Model dropdown (disabled while active)
        var modelDisabled = (isActive || isStopping) ? ' disabled' : '';
        var toggleOn = isActive || isStopping;
        var toggleCls = isStopping ? 'tx-pc-toggle stopping' : toggleOn ? 'tx-pc-toggle on' : 'tx-pc-toggle';
        var toggleLabel = isStopping ? 'Finishing...' : toggleOn ? 'Stop' : 'Local Transcription Run';

        var nasDisabled = (isActive || isStopping) ? ' disabled' : '';
        controlsEl.innerHTML =
            '<button class="btn btn-sm btn-success" id="tx-nas-btn"' + nasDisabled + '>NAS Transcription Run</button>' +
            '<select id="tx-pc-model" class="tx-pc-select" style="margin-left:12px;"' + modelDisabled + '>' +
            '<option value="tiny">Tiny — 39M</option>' +
            '<option value="base">Base — 74M</option>' +
            '<option value="small">Small — 244M</option>' +
            '<option value="medium">Medium — 769M</option>' +
            '<option value="large">Large — 1.5B</option>' +
            '</select>' +
            '<button class="btn btn-sm ' + toggleCls + '" id="tx-pc-toggle" style="margin-left:4px;"' +
            (isStopping ? ' disabled' : '') + '>' + toggleLabel + '</button>';

        // Set model dropdown value
        var modelVal = isActive ? (data.model || 'small') : (data.loaded_model || 'small');
        var sel = document.getElementById('tx-pc-model');
        if (sel) sel.value = modelVal;

        // Toggle handler
        var toggleBtn = document.getElementById('tx-pc-toggle');
        if (toggleBtn && !isStopping) {
            toggleBtn.addEventListener('click', function() {
                if (isActive) {
                    self.stopPcWorker();
                } else {
                    var model = document.getElementById('tx-pc-model').value;
                    self.startPcWorker(sessionId, model);
                }
            });
        }

        // NAS button handler
        var nasBtn = document.getElementById('tx-nas-btn');
        if (nasBtn && !isActive && !isStopping) {
            nasBtn.addEventListener('click', function() {
                var model = document.getElementById('tx-pc-model').value;
                self.startNasTranscription(sessionId, model);
            });
        }

        // Detail line when active
        if (isActive && status === 'transcribing') {
            var seg = data.current_segment ? 'SEG ' + String(data.current_segment).padStart(3, '0') : '—';
            detailEl.innerHTML =
                '<span class="tx-pc-detail-item">Working: <strong>' + seg + '</strong></span>' +
                '<span class="tx-pc-detail-item">Done: <strong>' + (data.completed || 0) + '</strong></span>' +
                (data.errors > 0 ? '<span class="tx-pc-detail-item" style="color:#c62828;">Errors: ' + data.errors + '</span>' : '');
        } else {
            detailEl.innerHTML = '';
        }
    },

    renderPcControlsOffline: function(sessionId, controlsEl, detailEl) {
        var self = this;
        controlsEl.innerHTML =
            '<select id="tx-pc-model" class="tx-pc-select">' +
            '<option value="tiny">Tiny — 39M</option>' +
            '<option value="base">Base — 74M</option>' +
            '<option value="small">Small — 244M</option>' +
            '<option value="medium">Medium — 769M</option>' +
            '<option value="large" selected>Large — 1.5B</option>' +
            '</select>' +
            '<button class="btn btn-sm btn-success" id="tx-nas-run-btn" style="margin-left:8px;">NAS Transcription Run</button>' +
            '<button class="btn btn-sm btn-secondary" id="tx-local-run-btn" style="margin-left:8px;">Local Transcription Run</button>';

        if (detailEl) detailEl.innerHTML = '';

        document.getElementById('tx-nas-run-btn').addEventListener('click', function() {
            var model = document.getElementById('tx-pc-model').value;
            self.startNasTranscription(sessionId, model);
        });
        document.getElementById('tx-local-run-btn').addEventListener('click', function() {
            var model = document.getElementById('tx-pc-model').value;
            self.startPcWorker(sessionId, model);
        });
    },

    /** Check if all segments are truly transcribed (TX Complete == total) */
    _allTranscribed: function() {
        var grid = document.getElementById('tx-stat-grid');
        if (!grid) return false;
        var total = 0, txComplete = 0;
        var cards = grid.querySelectorAll('.tx-stat-card');
        for (var i = 0; i < cards.length; i++) {
            var label = cards[i].querySelector('.tx-stat-label');
            var val = cards[i].querySelector('.tx-stat-value');
            if (!label || !val) continue;
            if (label.textContent === 'Current Segment') total = parseInt(val.textContent) || 0;
            if (label.textContent === 'TX Complete') txComplete = parseInt(val.textContent) || 0;
        }
        return total > 0 && txComplete >= total;
    },

    startPcWorker: function(sessionId, model) {
        var self = this;

        if (self._allTranscribed()) {
            App.toast('All segments are already transcribed', 'info');
            return;
        }

        self.pcLastStatus = null;

        // Local ONLY — no NAS fallback (that's what the NAS button is for)
        fetch(self.pcWorkerUrl + '/start', {
            method: 'POST',
            mode: 'cors',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({session_id: sessionId, model: model})
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.ok) {
                App.toast('Transcribing with PC GPU (' + model + ')', 'success');
            } else {
                App.toast(data.error || 'Failed to start', 'error');
            }
        }).catch(function() {
            App.toast('PC Worker is not running. Use NAS Transcription Run instead.', 'error');
        });
    },

    startNasTranscription: function(sessionId, model) {
        var self = this;
        if (self._allTranscribed()) {
            App.toast('All segments are already transcribed', 'info');
            return;
        }
        var body = model ? { model: model } : {};
        API.post('/api/transcription/sessions/' + sessionId + '/transcribe', body).then(function(result) {
            App.toast(result.message || 'NAS transcription started', 'success');
        }).catch(function(err) {
            App.toast(err.message || 'Failed to start transcription', 'error');
        });
    },

    stopPcWorker: function() {
        var self = this;
        self.pcLastStatus = null;
        fetch(self.pcWorkerUrl + '/stop', {
            method: 'POST',
            mode: 'cors'
        }).then(function(r) { return r.json(); }).then(function(data) {
            App.toast('Stopping after current segment', 'info');
        }).catch(function() {
            App.toast('Cannot reach PC Worker', 'error');
        });
    },

    startPcPolling: function(sessionId) {
        this.stopPcPolling();
        var self = this;
        this.pcPollTimer = setInterval(function() {
            var dot = document.getElementById('tx-pc-dot');
            if (!dot) { self.stopPcPolling(); return; }

            fetch(self.pcWorkerUrl + '/status', {mode: 'cors'}).then(function(r) {
                return r.json();
            }).then(function(data) {
                dot.className = 'tx-pc-dot online';
                // Skip re-render if idle→idle (preserves dropdown selection)
                if (data.status === 'idle' && self.pcLastStatus === 'idle') return;
                self.pcLastStatus = data.status;
                var state = document.getElementById('tx-pc-state');
                var controls = document.getElementById('tx-pc-controls');
                var detail = document.getElementById('tx-pc-detail');
                if (state && controls) self.renderPcControls(data, sessionId, state, controls, detail);
            }).catch(function() {
                // Don't re-render if already offline (preserves dropdown selection)
                if (self.pcLastStatus === 'offline') return;
                self.pcLastStatus = 'offline';
                dot.className = 'tx-pc-dot offline';
                var state = document.getElementById('tx-pc-state');
                if (state) { state.textContent = 'Not Running'; state.className = 'tx-pc-state offline'; }
                var controls = document.getElementById('tx-pc-controls');
                var detail = document.getElementById('tx-pc-detail');
                if (controls) self.renderPcControlsOffline(sessionId, controls, detail);
            });
        }, 3000);
    },

    stopPcPolling: function() {
        if (this.pcPollTimer) {
            clearInterval(this.pcPollTimer);
            this.pcPollTimer = null;
        }
    },

    // ─── Session Form (Modal) ─────────────────────────────────

    showSessionForm: function(sessionId) {
        var self = this;
        var isEdit = !!sessionId;

        var buildForm = function(existing) {
            var ex = existing || {};
            var parts = [];
            parts.push('<div class="modal-header">');
            parts.push('<h2>' + (isEdit ? 'Edit Session' : 'Schedule Session') + '</h2>');
            parts.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
            parts.push('</div>');
            parts.push('<div class="modal-body">');

            parts.push('<div class="form-group">');
            parts.push('<label>Auction Name *</label>');
            parts.push('<input type="text" id="tx-sess-name" value="' + self.escAttr(ex.auction_name || '') + '" placeholder="e.g. Friday Night Cards">');
            parts.push('</div>');

            parts.push('<div class="form-group">');
            parts.push('<label>Auction URL *</label>');
            parts.push('<input type="text" id="tx-sess-url" value="' + self.escAttr(ex.auction_url || '') + '" placeholder="https://www.whatnot.com/live/...">');
            parts.push('</div>');

            parts.push('<div class="form-group">');
            parts.push('<label>Scheduled Start *</label>');
            parts.push('<input type="datetime-local" id="tx-sess-start" value="' + self.toLocalDatetime(ex.scheduled_start || '') + '">');
            parts.push('</div>');

            parts.push('<hr class="section-divider">');
            parts.push('<p class="section-title">Per-Session Overrides <small class="text-muted">(leave blank for global defaults)</small></p>');

            parts.push('<div class="form-row">');
            parts.push('<div class="form-group"><label>Segment Length (min)</label>');
            parts.push('<input type="number" id="tx-sess-seg-len" value="' + (ex.override_segment_length || '') + '" min="5" max="60" placeholder="global">');
            parts.push('</div>');
            parts.push('<div class="form-group"><label>Silence Timeout (min)</label>');
            parts.push('<input type="number" id="tx-sess-silence" value="' + (ex.override_silence_timeout || '') + '" min="1" max="30" placeholder="global">');
            parts.push('</div>');
            parts.push('</div>');

            parts.push('<div class="form-row">');
            parts.push('<div class="form-group"><label>Max Duration (hrs)</label>');
            parts.push('<input type="number" id="tx-sess-max-dur" value="' + (ex.override_max_duration || '') + '" min="1" max="24" placeholder="global">');
            parts.push('</div>');
            parts.push('<div class="form-group"><label>CPU Limit</label>');
            parts.push('<input type="number" id="tx-sess-cpu" value="' + (ex.override_cpu_limit || '') + '" min="1" max="3" placeholder="global">');
            parts.push('</div>');
            parts.push('</div>');

            parts.push('<div class="form-group"><label>Acquisition Mode</label>');
            parts.push('<select id="tx-sess-acq">');
            parts.push('<option value="">Use Global Default</option>');
            parts.push('<option value="direct_stream"' + (ex.override_acquisition_mode === 'direct_stream' ? ' selected' : '') + '>Direct Stream</option>');
            parts.push('<option value="browser_automation"' + (ex.override_acquisition_mode === 'browser_automation' ? ' selected' : '') + '>Browser Automation</option>');
            parts.push('</select></div>');

            parts.push('</div>');
            parts.push('<div class="modal-footer">');
            parts.push('<button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>');
            parts.push('<button class="btn btn-primary" id="tx-sess-save">' + (isEdit ? 'Save Changes' : 'Schedule') + '</button>');
            parts.push('</div>');

            App.openModal(parts.join('\n'));

            document.getElementById('tx-sess-save').addEventListener('click', function() {
                self.saveSession(sessionId);
            });
        };

        if (isEdit) {
            API.get('/api/transcription/sessions/' + sessionId).then(function(result) {
                buildForm(result.session);
            }).catch(function(err) { App.toast(err.message, 'error'); });
        } else {
            buildForm(null);
        }
    },

    saveSession: function(sessionId) {
        var name = document.getElementById('tx-sess-name').value.trim();
        var url = document.getElementById('tx-sess-url').value.trim();
        var start = document.getElementById('tx-sess-start').value;

        if (!name || !url || !start) {
            App.toast('Name, URL, and scheduled start are required', 'error');
            return;
        }

        var data = {
            auction_name: name,
            auction_url: url,
            scheduled_start: start
        };

        // Overrides (only include if set)
        var segLen = document.getElementById('tx-sess-seg-len').value;
        var silence = document.getElementById('tx-sess-silence').value;
        var maxDur = document.getElementById('tx-sess-max-dur').value;
        var cpu = document.getElementById('tx-sess-cpu').value;
        var acq = document.getElementById('tx-sess-acq').value;

        if (segLen) data.override_segment_length = parseInt(segLen);
        if (silence) data.override_silence_timeout = parseInt(silence);
        if (maxDur) data.override_max_duration = parseInt(maxDur);
        if (cpu) data.override_cpu_limit = parseInt(cpu);
        if (acq) data.override_acquisition_mode = acq;

        var self = this;
        var promise = sessionId
            ? API.put('/api/transcription/sessions/' + sessionId, data)
            : API.post('/api/transcription/sessions', data);

        promise.then(function() {
            App.toast(sessionId ? 'Session updated' : 'Session scheduled', 'success');
            App.closeModal();
            self.loadSessions();
        }).catch(function(err) {
            App.toast(err.message || 'Failed', 'error');
        });
    },

    // ─── Session Monitor ──────────────────────────────────────

    showSessionMonitor: function(sessionId) {
        this.monitorSessionId = sessionId;

        // Hide main sections, show monitor
        document.getElementById('tx-sections').style.display = 'none';
        var mv = document.getElementById('tx-monitor-view');
        mv.style.display = '';
        mv.innerHTML = '<p class="text-muted">Loading session...</p>';

        var self = this;
        API.get('/api/transcription/sessions/' + sessionId).then(function(result) {
            var s = result.session;
            var segments = result.segments || [];
            var logs = result.logs || [];

            // Store effective segment length for progress calculations
            self.segmentLengthMin = result.segment_length_min || 15;

            var html = ['<div class="tx-monitor">'];

            // Header
            html.push('<div class="tx-monitor-header">');
            html.push('<div>');
            html.push('<span class="tx-session-name">' + self.escHtml(s.auction_name) + '</span>');
            html.push(' <span class="status-badge status-' + s.status + '">' + s.status + '</span>');
            html.push('</div>');
            html.push('<div style="display:flex;align-items:center;gap:16px;">');
            html.push('<span class="tx-elapsed" id="tx-elapsed-display">00:00:00</span>');
            html.push('<div class="tx-monitor-controls">');
            if (s.status === 'recording' || s.status === 'processing') {
                html.push('<button class="btn btn-warning btn-sm" id="tx-mon-stop">Stop Recording</button>');
                html.push('<button class="btn btn-danger btn-sm" id="tx-mon-cancel">Cancel Job</button>');
            }
            var txPendingCount = self.countByStatus(segments, 'transcription_status', 'pending');
            if (txPendingCount > 0 && s.status !== 'recording') {
                html.push('<button class="btn btn-success btn-sm" id="tx-mon-transcribe">Transcribe (' + txPendingCount + ' pending)</button>');
            }
            html.push('<button class="btn btn-secondary btn-sm" id="tx-mon-back">Back to Sessions</button>');
            html.push('</div></div></div>');

            // Stats
            html.push('<div class="tx-stat-grid" id="tx-stat-grid">');
            html.push(self.statCard('Current Segment', segments.length || 0));
            html.push(self.statCard('Rec. Complete', self.countByStatus(segments, 'recording_status', 'complete')));
            html.push(self.statCard('TX Complete', self.countByStatus(segments, 'transcription_status', 'complete')));
            html.push(self.statCard('TX Pending', self.countByStatus(segments, 'transcription_status', 'pending')));
            html.push('</div>');

            // PC Worker panel
            html.push('<div class="tx-pc-worker" id="tx-pc-worker">');
            html.push('<div class="tx-pc-worker-inner">');
            html.push('<div class="tx-pc-status">');
            html.push('<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>');
            html.push('<span class="tx-pc-label">Transcription</span>');
            html.push('<span class="tx-pc-dot" id="tx-pc-dot"></span>');
            html.push('<span id="tx-pc-state" class="tx-pc-state">checking...</span>');
            html.push('</div>');
            html.push('<div class="tx-pc-controls" id="tx-pc-controls"></div>');
            html.push('</div>');
            html.push('<div id="tx-pc-detail" class="tx-pc-detail"></div>');
            html.push('</div>');

            // Segments
            html.push('<div class="tx-segments-list"><h4>Segments <span style="font-size:13px;font-weight:400;color:#888;">(' + self.segmentLengthMin + ' min each)</span></h4>');
            html.push('<div id="tx-segments-body">');
            if (segments.length === 0) {
                html.push('<div style="padding:16px;color:#999;">No segments yet.</div>');
            } else {
                for (var i = 0; i < segments.length; i++) {
                    html.push(self.segmentRow(segments[i]));
                }
            }
            html.push('</div></div>');

            // Logs
            html.push('<div class="tx-log-area"><h4>Event Log</h4>');
            html.push('<div id="tx-log-entries">');
            for (var j = logs.length - 1; j >= 0; j--) {
                html.push(self.logEntry(logs[j]));
            }
            if (logs.length === 0) {
                html.push('<div class="tx-log-entry"><span class="log-info">No events yet.</span></div>');
            }
            html.push('</div></div>');

            html.push('</div>');
            mv.innerHTML = html.join('');

            // Attach handlers
            var backBtn = document.getElementById('tx-mon-back');
            if (backBtn) backBtn.addEventListener('click', function() { self.closeMonitor(); });

            var stopBtn = document.getElementById('tx-mon-stop');
            if (stopBtn) stopBtn.addEventListener('click', function() {
                self.handleAction('stop', sessionId);
            });

            var cancelBtn = document.getElementById('tx-mon-cancel');
            if (cancelBtn) cancelBtn.addEventListener('click', function() {
                self.handleAction('cancel', sessionId);
            });

            var txBtn = document.getElementById('tx-mon-transcribe');
            if (txBtn) txBtn.addEventListener('click', function() {
                self.handleAction('transcribe', sessionId);
            });

            // Detect PC Worker service and update panel
            self.checkPcWorker(sessionId);

            // Set initial elapsed display from server data
            if (s.actual_start_time) {
                // Compute initial elapsed on load using getSessionStatus
                API.get('/api/transcription/sessions/' + sessionId + '/status').then(function(statusResult) {
                    var el = document.getElementById('tx-elapsed-display');
                    if (el) el.textContent = self.formatDuration(statusResult.elapsed_sec || 0);
                }).catch(function() {});
            }

            // Start polling if active
            if (s.status === 'recording' || s.status === 'processing') {
                self.startPolling(sessionId);
            }

        }).catch(function(err) {
            mv.innerHTML = '<p class="text-danger">Error: ' + (err.message || 'Failed to load') + '</p>' +
                '<button class="btn btn-secondary btn-sm" onclick="TranscriptionAdmin.closeMonitor()">Back</button>';
        });
    },

    closeMonitor: function() {
        this.stopPolling();
        this.stopPcPolling();
        this.monitorSessionId = null;
        document.getElementById('tx-sections').style.display = '';
        document.getElementById('tx-monitor-view').style.display = 'none';
        this.loadSessions();
    },

    startPolling: function(sessionId) {
        this.stopPolling();
        var self = this;
        this.pollTimer = setInterval(function() {
            self.pollSessionStatus(sessionId);
        }, 3000);
    },

    stopPolling: function() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
    },

    pollSessionStatus: function(sessionId) {
        var self = this;
        API.get('/api/transcription/sessions/' + sessionId + '/status').then(function(result) {
            var st = result.status;
            var seg = result.segments;

            // Update segment length and active segment timing from server
            if (result.segment_length_min) self.segmentLengthMin = result.segment_length_min;
            self.activeSegStarted = result.active_seg_started || null;
            if (result.server_time) {
                self.serverTimeDelta = Date.now() - new Date(result.server_time.replace(' ', 'T') + 'Z').getTime();
            }

            // Update elapsed from server
            var elapsed = result.elapsed_sec || 0;
            var el = document.getElementById('tx-elapsed-display');
            if (el) el.textContent = self.formatDuration(elapsed);

            // Update stat cards
            var grid = document.getElementById('tx-stat-grid');
            if (grid) {
                grid.innerHTML =
                    self.statCard('Current Segment', seg.total_segments || 0) +
                    self.statCard('Rec. Complete', seg.rec_complete || 0) +
                    self.statCard('TX Complete', seg.tx_complete || 0) +
                    self.statCard('TX Pending', seg.tx_pending || 0);
            }

            // If status changed to complete/stopped/error, stop polling and reload full view
            if (st === 'complete' || st === 'stopped' || st === 'error') {
                self.stopPolling();
                self.showSessionMonitor(sessionId); // reload full view
                return;
            }

            // Refresh segments list and logs
            self.refreshSegments(sessionId);
            self.refreshLogs(sessionId);

        }).catch(function() { /* silent poll failure */ });
    },

    refreshSegments: function(sessionId) {
        API.get('/api/transcription/sessions/' + sessionId).then(function(result) {
            var body = document.getElementById('tx-segments-body');
            if (!body) return;
            var segments = result.segments || [];
            if (segments.length === 0) {
                body.innerHTML = '<div style="padding:16px;color:#999;">No segments yet.</div>';
                return;
            }
            var html = [];
            for (var i = 0; i < segments.length; i++) {
                html.push(TranscriptionAdmin.segmentRow(segments[i]));
            }
            body.innerHTML = html.join('');
        }).catch(function() { /* silent */ });
    },

    refreshLogs: function(sessionId) {
        API.get('/api/transcription/sessions/' + sessionId + '/logs?per_page=20').then(function(result) {
            var entries = document.getElementById('tx-log-entries');
            if (!entries) return;
            var logs = result.data || [];
            var html = [];
            for (var j = logs.length - 1; j >= 0; j--) {
                html.push(TranscriptionAdmin.logEntry(logs[j]));
            }
            if (html.length === 0) {
                html.push('<div class="tx-log-entry"><span class="log-info">No events yet.</span></div>');
            }
            entries.innerHTML = html.join('');
            entries.scrollTop = entries.scrollHeight;
        }).catch(function() { /* silent */ });
    },

    // ─── Helpers ──────────────────────────────────────────────

    statCard: function(label, value) {
        return '<div class="tx-stat-card">' +
            '<div class="tx-stat-label">' + label + '</div>' +
            '<div class="tx-stat-value">' + value + '</div></div>';
    },

    segmentRow: function(seg) {
        var recCls = seg.recording_status;
        var txCls = seg.transcription_status;
        var progress = parseInt(seg.transcription_progress) || 0;
        var segLenSec = this.segmentLengthMin * 60;

        // Calculate real recording progress from started_at
        var recProgress = 0;
        var elapsedSec = 0;
        if (recCls === 'recording' && seg.started_at) {
            var startMs = new Date(seg.started_at.replace(' ', 'T') + 'Z').getTime();
            var nowMs = Date.now() - (this.serverTimeDelta || 0);
            elapsedSec = Math.max(0, Math.floor((nowMs - startMs) / 1000));
            recProgress = Math.min(99, Math.round((elapsedSec / segLenSec) * 100));
        } else if (recCls === 'complete') {
            elapsedSec = parseInt(seg.duration_seconds) || segLenSec;
            recProgress = 100;
        }

        var isTranscribing = txCls === 'transcribing';
        var barCls = txCls === 'complete' ? 'complete' :
                     isTranscribing ? 'transcribing animated' :
                     txCls === 'error' ? 'error' : 'recording';
        var barWidth = txCls === 'complete' ? 100 :
                       isTranscribing ? 100 :
                       recCls === 'complete' ? 100 :
                       recCls === 'recording' ? recProgress : 0;

        // Time label: elapsed / total for recording; duration for complete
        var timeLabel = '';
        if (recCls === 'recording') {
            timeLabel = this.formatDuration(elapsedSec) + ' / ' + this.formatDuration(segLenSec);
        } else if (recCls === 'complete' && elapsedSec > 0) {
            timeLabel = this.formatDuration(elapsedSec);
        }

        // Transcription status badge
        var txBadge = '';
        if (txCls === 'complete') {
            txBadge = '<span class="status-badge status-completed">complete</span>';
        } else if (isTranscribing) {
            txBadge = '<span class="status-badge status-pending" style="min-width:100px;">TRANSCRIBING</span>';
        } else if (txCls === 'error') {
            txBadge = '<span class="status-badge status-error">error</span>';
        } else {
            txBadge = '<span class="status-badge status-pending">' + txCls + '</span>';
        }

        return '<div class="tx-segment-row">' +
            '<span class="tx-segment-num">SEG ' + String(seg.segment_number).padStart(3, '0') + '</span>' +
            '<span class="status-badge status-' + recCls + '" style="min-width:80px;text-align:center;">' + recCls + '</span>' +
            '<div class="tx-segment-progress"><div class="tx-segment-progress-fill ' + barCls + '" style="width:' + barWidth + '%;"></div></div>' +
            '<span class="tx-seg-time">' + timeLabel + '</span>' +
            '<span class="tx-segment-status">' + txBadge + '</span>' +
            '</div>';
    },

    logEntry: function(log) {
        var time = log.created_at ? log.created_at.substring(11, 19) : '';
        var cls = 'log-' + log.log_level;
        return '<div class="tx-log-entry">' +
            '<span class="log-time">[' + time + ']</span> ' +
            '<span class="' + cls + '">[' + log.log_level.toUpperCase() + ']</span> ' +
            this.escHtml(log.message) +
            '</div>';
    },

    statusBadge: function(status) {
        return '<span class="status-badge status-' + status + '">' + status + '</span>';
    },

    formatDuration: function(totalSec) {
        var h = Math.floor(totalSec / 3600);
        var m = Math.floor((totalSec % 3600) / 60);
        var s = totalSec % 60;
        return String(h).padStart(2, '0') + ':' +
               String(m).padStart(2, '0') + ':' +
               String(s).padStart(2, '0');
    },

    countByStatus: function(segments, field, value) {
        var count = 0;
        for (var i = 0; i < segments.length; i++) {
            if (segments[i][field] === value) count++;
        }
        return count;
    },

    toLocalDatetime: function(dt) {
        if (!dt) return '';
        // Convert "2026-02-20 19:00:00" to "2026-02-20T19:00" for datetime-local input
        return dt.replace(' ', 'T').substring(0, 16);
    },

    escHtml: function(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    escAttr: function(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
};
