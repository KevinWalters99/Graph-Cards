/**
 * Card Graph — Scheduled Sessions Tab
 * Simplified session management in primary nav.
 */
var Sessions = {
    initialized: false,

    init: function() {
        var panel = document.getElementById('tab-sessions');
        if (!panel) return;

        if (!this.initialized) {
            panel.innerHTML =
                '<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">' +
                    '<h1>Scheduled Sessions</h1>' +
                    '<button class="btn btn-success btn-sm" id="ss-new-btn">Schedule Session</button>' +
                '</div>' +
                '<div id="ss-grid-area"></div>';

            var self = this;
            document.getElementById('ss-new-btn').addEventListener('click', function() {
                self.showSessionForm();
            });
            this.initialized = true;
        }
        this.loadSessions();
    },

    loadSessions: function() {
        var self = this;
        API.get('/api/transcription/sessions', { per_page: 50 }).then(function(result) {
            self.renderCards(result.data || []);
        }).catch(function() {
            document.getElementById('ss-grid-area').innerHTML =
                '<p class="text-muted" style="padding:24px;">Unable to load sessions.</p>';
        });
    },

    renderCards: function(sessions) {
        var area = document.getElementById('ss-grid-area');
        if (!area) return;

        if (sessions.length === 0) {
            area.innerHTML = '<div class="empty-state" style="padding:40px 0;text-align:center;">' +
                '<p class="text-muted">No sessions scheduled yet. Click "Schedule Session" to get started.</p></div>';
            return;
        }

        var self = this;
        var html = ['<div class="ss-grid">'];

        for (var i = 0; i < sessions.length; i++) {
            var s = sessions[i];
            var totalSegs = parseInt(s.total_segments) || 0;
            var txComplete = parseInt(s.tx_complete) || 0;
            var hasParse = parseInt(s.has_ai_parse) || 0;
            var hasAlign = parseInt(s.has_alignment) || 0;

            var isTranscribed = totalSegs > 0 && txComplete === totalSegs;
            var isFullyComplete = isTranscribed && hasParse && hasAlign;

            var cardClass = 'ss-card' + (isFullyComplete ? ' ss-card-complete' : '');

            html.push('<div class="' + cardClass + '">');

            // Header: name + status badge
            html.push('<div class="ss-card-header">');
            html.push('<div class="ss-card-name" title="' + self._escAttr(s.auction_name) + '">' + self._escHtml(s.auction_name) + '</div>');
            html.push('<span class="status-badge status-' + s.status + '">' + s.status + '</span>');
            html.push('</div>');

            // Meta: date, segments, duration
            var dur = parseInt(s.total_duration_sec) > 0 ? self._formatDuration(parseInt(s.total_duration_sec)) : '';
            html.push('<div class="ss-card-meta">');
            html.push('<span>' + self._formatDate(s.scheduled_start) + '</span>');
            if (totalSegs > 0) html.push('<span>' + totalSegs + ' segments</span>');
            if (dur) html.push('<span>' + dur + '</span>');
            html.push('</div>');

            // Pipeline status indicators
            if (totalSegs > 0 || s.status === 'complete' || s.status === 'stopped') {
                html.push('<div class="ss-indicators">');

                // Transcribed
                var txDot = isTranscribed ? 'ss-dot-green' : 'ss-dot-red';
                var txLabel = isTranscribed ? txComplete + '/' + totalSegs : (totalSegs > 0 ? txComplete + '/' + totalSegs : 'None');
                html.push('<span class="ss-indicator"><span class="ss-dot ' + txDot + '"></span> Transcribed ' + txLabel + '</span>');

                // Parsed
                var parseDot = hasParse ? 'ss-dot-green' : 'ss-dot-red';
                var parseLabel = hasParse ? (parseInt(s.total_records) || 0) + ' cards' : 'No';
                html.push('<span class="ss-indicator"><span class="ss-dot ' + parseDot + '"></span> Parsed ' + parseLabel + '</span>');

                // Aligned
                var alignDot = hasAlign ? 'ss-dot-green' : 'ss-dot-red';
                var alignLabel = hasAlign ? (parseInt(s.aligned_count) || 0) + ' matched' : 'No';
                html.push('<span class="ss-indicator"><span class="ss-dot ' + alignDot + '"></span> Aligned ' + alignLabel + '</span>');

                html.push('</div>');
            }

            // Complete badge or actions
            if (isFullyComplete) {
                html.push('<div class="ss-complete-badge">\u2713 All Steps Complete</div>');
            } else {
                html.push('<div class="ss-card-actions">');
                html.push(self._buildActions(s));
                html.push('</div>');
            }

            html.push('</div>'); // end card
        }

        html.push('</div>'); // end grid
        area.innerHTML = html.join('');

        // Attach action handlers
        var btns = area.querySelectorAll('[data-ss-action]');
        for (var j = 0; j < btns.length; j++) {
            (function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var action = btn.getAttribute('data-ss-action');
                    var id = parseInt(btn.getAttribute('data-session-id'));
                    self._handleAction(action, id);
                });
            })(btns[j]);
        }
    },

    _buildActions: function(s) {
        var btns = [];
        var id = s.session_id;

        if (s.status === 'scheduled') {
            btns.push('<button class="btn btn-success btn-sm" data-ss-action="start" data-session-id="' + id + '">Start</button>');
            btns.push('<button class="btn btn-secondary btn-sm" data-ss-action="edit" data-session-id="' + id + '">Edit</button>');
            btns.push('<button class="btn btn-danger btn-sm" data-ss-action="delete" data-session-id="' + id + '">Delete</button>');
        } else if (s.status === 'recording' || s.status === 'processing') {
            btns.push('<button class="btn btn-primary btn-sm" data-ss-action="monitor" data-session-id="' + id + '">Monitor</button>');
            btns.push('<button class="btn btn-warning btn-sm" data-ss-action="stop" data-session-id="' + id + '">Stop</button>');
        } else {
            // complete/stopped/error — no editing once session has run
            btns.push('<button class="btn btn-secondary btn-sm" data-ss-action="view" data-session-id="' + id + '">View in Transcription</button>');
        }
        return btns.join('');
    },

    _handleAction: function(action, id) {
        var self = this;
        switch (action) {
            case 'start':
                if (!confirm('Start recording session #' + id + '?')) return;
                API.post('/api/transcription/sessions/' + id + '/start').then(function() {
                    App.toast('Session started', 'success');
                    self.loadSessions();
                }).catch(function(err) { App.toast(err.message, 'error'); });
                break;
            case 'stop':
                if (!confirm('Stop recording? Transcription of completed segments will continue.')) return;
                API.post('/api/transcription/sessions/' + id + '/stop').then(function() {
                    App.toast('Stop signal sent', 'success');
                    self.loadSessions();
                }).catch(function(err) { App.toast(err.message, 'error'); });
                break;
            case 'delete':
                if (!confirm('Delete session #' + id + '?\n\nThis will remove all recordings, transcripts, and log data. This cannot be undone.')) return;
                API.del('/api/transcription/sessions/' + id).then(function() {
                    App.toast('Session deleted', 'success');
                    self.loadSessions();
                }).catch(function(err) { App.toast(err.message, 'error'); });
                break;
            case 'edit':
                self.showSessionForm(id);
                break;
            case 'monitor':
                // Navigate to Maintenance → Transcription and trigger monitor
                App.switchTab('maintenance');
                setTimeout(function() {
                    Maintenance.switchSubTab('transcription');
                    setTimeout(function() {
                        if (typeof Transcription !== 'undefined') {
                            Transcription.showSessionMonitor(id);
                        }
                    }, 300);
                }, 200);
                break;
            case 'view':
                // Navigate to Maintenance → Transcription
                App.switchTab('maintenance');
                setTimeout(function() {
                    Maintenance.switchSubTab('transcription');
                }, 200);
                break;
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
            parts.push('<input type="text" id="ss-form-name" value="' + self._escAttr(ex.auction_name || '') + '" placeholder="e.g. Friday Night Cards">');
            parts.push('</div>');

            parts.push('<div class="form-group">');
            parts.push('<label>Auction URL *</label>');
            parts.push('<input type="text" id="ss-form-url" value="' + self._escAttr(ex.auction_url || '') + '" placeholder="https://www.whatnot.com/live/...">');
            parts.push('</div>');

            parts.push('<div class="form-group">');
            parts.push('<label>Scheduled Start *</label>');
            parts.push('<input type="datetime-local" id="ss-form-start" value="' + self._toLocalDatetime(ex.scheduled_start || '') + '">');
            parts.push('</div>');

            parts.push('<hr class="section-divider">');
            parts.push('<p class="section-title">Per-Session Overrides <small class="text-muted">(leave blank for global defaults)</small></p>');

            parts.push('<div class="form-row">');
            parts.push('<div class="form-group"><label>Segment Length (min)</label>');
            parts.push('<input type="number" id="ss-form-seg-len" value="' + (ex.override_segment_length || '') + '" min="5" max="60" placeholder="global">');
            parts.push('</div>');
            parts.push('<div class="form-group"><label>Silence Timeout (min)</label>');
            parts.push('<input type="number" id="ss-form-silence" value="' + (ex.override_silence_timeout || '') + '" min="1" max="30" placeholder="global">');
            parts.push('</div>');
            parts.push('</div>');

            parts.push('<div class="form-row">');
            parts.push('<div class="form-group"><label>Max Duration (hrs)</label>');
            parts.push('<input type="number" id="ss-form-max-dur" value="' + (ex.override_max_duration || '') + '" min="1" max="24" placeholder="global">');
            parts.push('</div>');
            parts.push('<div class="form-group"><label>CPU Limit</label>');
            parts.push('<input type="number" id="ss-form-cpu" value="' + (ex.override_cpu_limit || '') + '" min="1" max="3" placeholder="global">');
            parts.push('</div>');
            parts.push('</div>');

            parts.push('<div class="form-group"><label>Acquisition Mode</label>');
            parts.push('<select id="ss-form-acq">');
            parts.push('<option value="">Use Global Default</option>');
            parts.push('<option value="direct_stream"' + (ex.override_acquisition_mode === 'direct_stream' ? ' selected' : '') + '>Direct Stream</option>');
            parts.push('<option value="browser_automation"' + (ex.override_acquisition_mode === 'browser_automation' ? ' selected' : '') + '>Browser Automation</option>');
            parts.push('</select></div>');

            parts.push('</div>');
            parts.push('<div class="modal-footer">');
            parts.push('<button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>');
            parts.push('<button class="btn btn-primary" id="ss-form-save">' + (isEdit ? 'Save Changes' : 'Schedule') + '</button>');
            parts.push('</div>');

            App.openModal(parts.join('\n'));

            document.getElementById('ss-form-save').addEventListener('click', function() {
                self._saveSession(sessionId);
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

    _saveSession: function(sessionId) {
        var name = document.getElementById('ss-form-name').value.trim();
        var url = document.getElementById('ss-form-url').value.trim();
        var start = document.getElementById('ss-form-start').value;

        if (!name || !url || !start) {
            App.toast('Name, URL, and scheduled start are required', 'error');
            return;
        }

        var data = {
            auction_name: name,
            auction_url: url,
            scheduled_start: start
        };

        // Overrides
        var segLen = document.getElementById('ss-form-seg-len').value;
        var silence = document.getElementById('ss-form-silence').value;
        var maxDur = document.getElementById('ss-form-max-dur').value;
        var cpu = document.getElementById('ss-form-cpu').value;
        var acq = document.getElementById('ss-form-acq').value;
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
            App.closeModal();
            App.toast(sessionId ? 'Session updated' : 'Session scheduled', 'success');
            self.loadSessions();
        }).catch(function(err) {
            App.toast(err.message, 'error');
        });
    },

    // ─── Helpers ──────────────────────────────────────────────

    _escHtml: function(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    _escAttr: function(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    },

    _formatDate: function(dateStr) {
        if (!dateStr) return '-';
        try {
            var d = new Date(dateStr.replace(' ', 'T'));
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) +
                ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        } catch(e) { return dateStr; }
    },

    _formatDuration: function(sec) {
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        if (h > 0) return h + 'h ' + m + 'm';
        return m + 'm';
    },

    _toLocalDatetime: function(dateStr) {
        if (!dateStr) return '';
        try {
            var d = new Date(dateStr.replace(' ', 'T'));
            var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
                'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        } catch(e) { return ''; }
    }
};
