/**
 * Card Graph — Table Transcription Admin
 *
 * Parses raw transcript text into structured card records using
 * player-anchored fuzzy matching against parser support tables.
 */
var TableTranscriptionAdmin = {
    initialized: false,
    selectedSessionId: null,
    currentRunId: null,
    records: [],
    expandedRow: null,

    // Reference data for edit dropdowns
    _players: null,
    _teams: null,
    _makers: null,
    _styles: null,
    _specialties: null,

    init: function() {
        var panel = document.getElementById('tx-panel-table');
        if (!panel) return;

        if (!this.initialized) {
            this.renderSkeleton(panel);
            this.initialized = true;
        }

        this.loadSessions();
    },

    renderSkeleton: function(panel) {
        var html = [];

        html.push('<div class="tt-container">');

        // Header
        html.push('<h3 style="font-size:16px;font-weight:700;color:#1a1a2e;margin-bottom:16px;">Table Transcriptions</h3>');

        // Session bar
        html.push('<div class="tt-session-bar">');
        html.push('<label style="font-weight:600;margin-right:8px;">Session:</label>');
        html.push('<select id="tt-session-select" class="form-select" style="max-width:400px;margin-right:12px;"><option value="">Loading...</option></select>');
        html.push('<button class="btn btn-primary btn-sm" id="tt-parse-btn" disabled>Parse Session</button>');
        html.push('<button class="btn btn-success btn-sm" id="tt-transcribe-btn" disabled style="margin-left:8px;display:none;">Transcribe Audio</button>');
        html.push('<button class="btn btn-secondary btn-sm" id="tt-raw-text-btn" disabled style="margin-left:8px;">View Raw Text</button>');
        html.push('<span id="tt-tx-status" style="margin-left:12px;font-size:12px;color:#666;"></span>');
        html.push('</div>');

        // Parse run info
        html.push('<div id="tt-parse-info" class="tt-parse-info" style="display:none;"></div>');

        // Filters
        html.push('<div id="tt-filters" class="tt-filters" style="display:none;">');
        html.push('<label style="font-size:12px;margin-right:6px;">Min Confidence:</label>');
        html.push('<select id="tt-filter-conf" class="form-select form-select-sm" style="width:auto;margin-right:16px;">');
        html.push('<option value="">All</option><option value="0.3">0.30+</option><option value="0.5">0.50+</option><option value="0.7">0.70+</option>');
        html.push('</select>');
        html.push('<label style="font-size:12px;margin-right:6px;">Show:</label>');
        html.push('<select id="tt-filter-show" class="form-select form-select-sm" style="width:auto;">');
        html.push('<option value="all">All Records</option><option value="verified">Verified Only</option><option value="unverified">Unverified Only</option>');
        html.push('</select>');
        html.push('</div>');

        // Results table
        html.push('<div id="tt-results" class="tt-results"></div>');

        html.push('</div>');

        panel.innerHTML = html.join('\n');

        var self = this;
        document.getElementById('tt-session-select').addEventListener('change', function() {
            self.onSessionChange(this.value);
        });
        document.getElementById('tt-parse-btn').addEventListener('click', function() {
            self.parseSession();
        });
        document.getElementById('tt-transcribe-btn').addEventListener('click', function() {
            self.transcribeAudio();
        });
        document.getElementById('tt-raw-text-btn').addEventListener('click', function() {
            self.showRawText();
        });
        document.getElementById('tt-filter-conf').addEventListener('change', function() {
            self.applyFilters();
        });
        document.getElementById('tt-filter-show').addEventListener('change', function() {
            self.applyFilters();
        });
    },

    // ─── Sessions ─────────────────────────────────────────────

    _sessions: [],

    loadSessions: function() {
        var self = this;
        API.get('/api/transcription/sessions', { per_page: 50 }).then(function(result) {
            self._sessions = result.data || [];
            var select = document.getElementById('tt-session-select');
            var opts = '<option value="">— Select a session —</option>';
            self._sessions.forEach(function(s) {
                var txComplete = parseInt(s.tx_complete) || 0;
                var txPending = parseInt(s.tx_pending) || 0;
                var totalSegs = parseInt(s.total_segments) || 0;

                var label = 'S' + s.session_id + ' — ' + self.escHtml(s.auction_name);
                if (totalSegs > 0) {
                    label += ' (' + txComplete + '/' + totalSegs + ' transcribed)';
                }
                if (txPending > 0) label += ' [needs transcription]';
                var selected = (self.selectedSessionId && parseInt(s.session_id) === self.selectedSessionId) ? ' selected' : '';
                opts += '<option value="' + s.session_id + '"' + selected + '>' + label + '</option>';
            });
            select.innerHTML = opts;

            if (self.selectedSessionId) {
                self.updateTranscribeButton();
                self.loadParseRuns(self.selectedSessionId);
            }
        }).catch(function(err) {
            document.getElementById('tt-session-select').innerHTML =
                '<option value="">Error loading sessions</option>';
        });
    },

    onSessionChange: function(sessionId) {
        this.selectedSessionId = sessionId ? parseInt(sessionId) : null;
        this.currentRunId = null;
        this.records = [];
        this.expandedRow = null;

        var parseBtn = document.getElementById('tt-parse-btn');
        var rawBtn = document.getElementById('tt-raw-text-btn');
        parseBtn.disabled = !this.selectedSessionId;
        rawBtn.disabled = !this.selectedSessionId;

        this.updateTranscribeButton();

        if (this.selectedSessionId) {
            this.loadParseRuns(this.selectedSessionId);
        } else {
            document.getElementById('tt-parse-info').style.display = 'none';
            document.getElementById('tt-filters').style.display = 'none';
            document.getElementById('tt-results').innerHTML = '';
        }
    },

    /**
     * Show/hide the Transcribe button + status based on selected session's segment counts.
     */
    updateTranscribeButton: function() {
        var txBtn = document.getElementById('tt-transcribe-btn');
        var txStatus = document.getElementById('tt-tx-status');
        if (!txBtn || !txStatus) return;

        if (!this.selectedSessionId) {
            txBtn.style.display = 'none';
            txStatus.textContent = '';
            return;
        }

        // Find the session data
        var session = null;
        for (var i = 0; i < this._sessions.length; i++) {
            if (parseInt(this._sessions[i].session_id) === this.selectedSessionId) {
                session = this._sessions[i];
                break;
            }
        }

        if (!session) {
            txBtn.style.display = 'none';
            txStatus.textContent = '';
            return;
        }

        var txComplete = parseInt(session.tx_complete) || 0;
        var txPending = parseInt(session.tx_pending) || 0;
        var txActive = parseInt(session.tx_active) || 0;
        var totalSegs = parseInt(session.total_segments) || 0;

        if (txPending > 0) {
            txBtn.style.display = '';
            txBtn.disabled = false;
            txBtn.textContent = 'Transcribe Audio (' + txPending + ' pending)';
            txStatus.innerHTML = '<span style="color:#f57c00;">' + txComplete + '/' + totalSegs + ' segments transcribed</span>';
        } else if (txActive > 0) {
            txBtn.style.display = '';
            txBtn.disabled = true;
            txBtn.textContent = 'Transcribing...';
            txStatus.innerHTML = '<span style="color:#1565c0;">' + txComplete + '/' + totalSegs + ' transcribed (' + txActive + ' in progress)</span>';
        } else if (totalSegs > 0) {
            txBtn.style.display = 'none';
            txStatus.innerHTML = '<span style="color:#4caf50;">' + txComplete + '/' + totalSegs + ' segments transcribed</span>';
        } else {
            txBtn.style.display = 'none';
            txStatus.textContent = '';
        }
    },

    // ─── Parse Runs ───────────────────────────────────────────

    loadParseRuns: function(sessionId) {
        var self = this;
        API.get('/api/transcription/sessions/' + sessionId + '/parse-runs').then(function(result) {
            var runs = result.data || [];
            if (runs.length === 0) {
                document.getElementById('tt-parse-info').style.display = 'none';
                document.getElementById('tt-filters').style.display = 'none';
                document.getElementById('tt-results').innerHTML =
                    '<p class="text-muted" style="padding:24px 0;">No parse runs yet. Click "Parse Session" to extract card records from the transcript.</p>';
                return;
            }

            // Show latest run
            var latest = runs[0];
            self.currentRunId = parseInt(latest.run_id);
            self.renderParseInfo(latest, runs);
            self.loadRecords(sessionId, self.currentRunId);
        }).catch(function() {
            document.getElementById('tt-parse-info').innerHTML =
                '<span class="text-danger">Failed to load parse runs.</span>';
            document.getElementById('tt-parse-info').style.display = '';
        });
    },

    renderParseInfo: function(run, allRuns) {
        var infoDiv = document.getElementById('tt-parse-info');
        var h = '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';

        // Run selector if multiple runs exist
        if (allRuns && allRuns.length > 1) {
            h += '<select id="tt-run-select" class="form-select form-select-sm" style="width:auto;">';
            var self = this;
            allRuns.forEach(function(r) {
                var sel = parseInt(r.run_id) === parseInt(run.run_id) ? ' selected' : '';
                h += '<option value="' + r.run_id + '"' + sel + '>Run #' + r.run_id + '</option>';
            });
            h += '</select>';
        } else {
            h += '<span style="font-weight:600;">Run #' + run.run_id + '</span>';
        }

        h += '<span>' + this.escHtml(run.started_at || '') + '</span>';
        h += '<span>' + (run.total_records || 0) + ' records</span>';
        h += '<span class="tt-confidence-high" style="padding:2px 8px;border-radius:4px;">' + (run.high_confidence || 0) + ' high</span>';
        h += '<span class="tt-confidence-low" style="padding:2px 8px;border-radius:4px;">' + (run.low_confidence || 0) + ' low</span>';

        if (run.status === 'error') {
            h += '<span class="text-danger" style="font-size:12px;">Error: ' + this.escHtml(run.error_message || 'Unknown') + '</span>';
        }

        h += '</div>';
        infoDiv.innerHTML = h;
        infoDiv.style.display = '';

        // Wire run selector
        var runSelect = document.getElementById('tt-run-select');
        if (runSelect) {
            var self = this;
            runSelect.addEventListener('change', function() {
                self.currentRunId = parseInt(this.value);
                self.loadRecords(self.selectedSessionId, self.currentRunId);
            });
        }
    },

    // ─── Records ──────────────────────────────────────────────

    loadRecords: function(sessionId, runId) {
        var self = this;
        var params = { run_id: runId };

        API.get('/api/transcription/sessions/' + sessionId + '/records', params).then(function(result) {
            self.records = result.data || [];
            document.getElementById('tt-filters').style.display = '';
            self.applyFilters();
        }).catch(function(err) {
            document.getElementById('tt-results').innerHTML =
                '<p class="text-danger">Failed to load records: ' + self.escHtml(err.message) + '</p>';
        });
    },

    applyFilters: function() {
        var minConf = parseFloat(document.getElementById('tt-filter-conf').value) || 0;
        var showFilter = document.getElementById('tt-filter-show').value;

        var filtered = this.records.filter(function(r) {
            if (parseFloat(r.confidence) < minConf) return false;
            if (showFilter === 'verified' && !parseInt(r.is_verified)) return false;
            if (showFilter === 'unverified' && parseInt(r.is_verified)) return false;
            return true;
        });

        this.renderRecordsTable(filtered);
    },

    renderRecordsTable: function(records) {
        var container = document.getElementById('tt-results');

        if (records.length === 0) {
            container.innerHTML = '<p class="text-muted" style="padding:24px 0;">No records match the current filters.</p>';
            return;
        }

        var self = this;
        var h = '<table class="tt-records-table"><thead><tr>';
        h += '<th style="width:40px;">#</th>';
        h += '<th style="width:70px;">Time</th>';
        h += '<th style="width:50px;">Lot</th>';
        h += '<th>Player</th>';
        h += '<th style="width:60px;">Team</th>';
        h += '<th>Maker</th>';
        h += '<th>Style</th>';
        h += '<th>Description</th>';
        h += '<th style="width:60px;">Conf</th>';
        h += '<th style="width:30px;"></th>';
        h += '</tr></thead><tbody>';

        records.forEach(function(rec) {
            var rid = rec.record_id;
            var conf = parseFloat(rec.confidence);
            var confClass = conf >= 0.7 ? 'tt-confidence-high' : (conf >= 0.4 ? 'tt-confidence-med' : 'tt-confidence-low');

            // Build description chips
            var desc = [];
            if (parseInt(rec.is_rookie)) desc.push('<span class="tt-chip tt-chip-rookie">Rookie</span>');
            if (parseInt(rec.is_autograph)) desc.push('<span class="tt-chip tt-chip-auto">Auto</span>');
            if (parseInt(rec.is_relic)) desc.push('<span class="tt-chip tt-chip-relic">Relic</span>');
            if (parseInt(rec.is_giveaway)) desc.push('<span class="tt-chip tt-chip-give">Giveaway</span>');
            if (rec.raw_parallel) desc.push('<span class="tt-chip">' + self.escHtml(rec.raw_parallel) + '</span>');
            if (rec.raw_card_number) desc.push('<span class="tt-chip">' + self.escHtml(rec.raw_card_number) + '</span>');
            if (rec.specialty_name) desc.push('<span class="tt-chip">' + self.escHtml(rec.specialty_name) + '</span>');

            var verifiedIcon = parseInt(rec.is_verified) ? ' <span title="Verified" style="color:#4caf50;">&#10003;</span>' : '';

            // Team with logo
            var teamHtml = '';
            if (rec.team_mlb_id) {
                teamHtml += '<img class="tt-team-logo" src="/img/teams/' + rec.team_mlb_id + '.png" alt="">';
            }
            teamHtml += self.escHtml(rec.team_abbr || rec.team_name || '-');

            // Format estimated time (show just HH:MM:SS)
            var timeStr = '-';
            if (rec.estimated_at) {
                var d = new Date(rec.estimated_at.replace(' ', 'T'));
                if (!isNaN(d.getTime())) {
                    timeStr = d.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit', second:'2-digit', hour12:true});
                }
            }

            h += '<tr class="tt-record-row" data-record-id="' + rid + '">';
            h += '<td>' + rec.sequence_number + '</td>';
            h += '<td class="tt-time-cell">' + timeStr + '</td>';
            h += '<td>' + (rec.lot_number || '-') + '</td>';
            h += '<td>' + self.escHtml(rec.player_name || rec.raw_player || '???') + verifiedIcon + '</td>';
            h += '<td>' + teamHtml + '</td>';
            h += '<td>' + self.escHtml(rec.maker_name || rec.raw_maker || '-') + '</td>';
            h += '<td>' + self.escHtml(rec.style_name || rec.raw_style || '-') + '</td>';
            h += '<td>' + (desc.length ? desc.join(' ') : '-') + '</td>';
            h += '<td><span class="tt-confidence ' + confClass + '">' + conf.toFixed(2) + '</span></td>';
            h += '<td style="cursor:pointer;font-size:16px;" class="tt-expand-toggle" data-rid="' + rid + '">&#9660;</td>';
            h += '</tr>';

            // Expandable detail row (hidden)
            h += '<tr class="tt-expand-row" id="tt-expand-' + rid + '" style="display:none;"><td colspan="10">';
            h += '<div class="tt-expand-content" id="tt-expand-content-' + rid + '"></div>';
            h += '</td></tr>';
        });

        h += '</tbody></table>';
        container.innerHTML = h;

        // Wire expand toggles
        container.querySelectorAll('.tt-expand-toggle').forEach(function(el) {
            el.addEventListener('click', function(e) {
                e.stopPropagation();
                self.toggleExpand(parseInt(el.dataset.rid));
            });
        });
        container.querySelectorAll('.tt-record-row').forEach(function(el) {
            el.addEventListener('click', function() {
                self.toggleExpand(parseInt(el.dataset.recordId));
            });
        });
    },

    // ─── Expand/Collapse Row Detail ──────────────────────────

    toggleExpand: function(recordId) {
        var expandRow = document.getElementById('tt-expand-' + recordId);
        if (!expandRow) return;

        if (this.expandedRow === recordId) {
            expandRow.style.display = 'none';
            this.expandedRow = null;
            return;
        }

        // Collapse previous
        if (this.expandedRow) {
            var prev = document.getElementById('tt-expand-' + this.expandedRow);
            if (prev) prev.style.display = 'none';
        }

        this.expandedRow = recordId;
        expandRow.style.display = '';

        // Find record data
        var rec = null;
        for (var i = 0; i < this.records.length; i++) {
            if (parseInt(this.records[i].record_id) === recordId) {
                rec = this.records[i];
                break;
            }
        }

        if (!rec) return;
        this.renderExpandedDetail(recordId, rec);
    },

    renderExpandedDetail: function(recordId, rec) {
        var self = this;
        var container = document.getElementById('tt-expand-content-' + recordId);
        if (!container) return;

        var h = '';

        // Raw text excerpt
        if (rec.raw_text_excerpt) {
            h += '<div class="tt-raw-excerpt">';
            h += '<strong style="font-size:11px;color:#666;">Raw Text Excerpt:</strong><br>';
            h += '<div class="tt-excerpt-text">' + self.escHtml(rec.raw_text_excerpt) + '</div>';
            h += '</div>';
        }

        // Edit form
        h += '<div class="tt-edit-grid">';

        // Player select (type-ahead later, for now just show current)
        h += '<div class="tt-edit-field">';
        h += '<label>Player</label>';
        h += '<input type="text" class="form-control form-control-sm" id="tt-edit-player-' + recordId + '" value="' + self.escAttr(rec.player_name || rec.raw_player || '') + '" readonly style="background:#f5f5f5;">';
        h += '<span style="font-size:10px;color:#999;">ID: ' + (rec.player_id || 'none') + '</span>';
        h += '</div>';

        // Lot number
        h += '<div class="tt-edit-field">';
        h += '<label>Lot #</label>';
        h += '<input type="number" class="form-control form-control-sm" id="tt-edit-lot-' + recordId + '" value="' + (rec.lot_number || '') + '">';
        h += '</div>';

        // Parallel
        h += '<div class="tt-edit-field">';
        h += '<label>Parallel/Color</label>';
        h += '<input type="text" class="form-control form-control-sm" id="tt-edit-parallel-' + recordId + '" value="' + self.escAttr(rec.raw_parallel || '') + '">';
        h += '</div>';

        // Card number
        h += '<div class="tt-edit-field">';
        h += '<label>Card #</label>';
        h += '<input type="text" class="form-control form-control-sm" id="tt-edit-cardnum-' + recordId + '" value="' + self.escAttr(rec.raw_card_number || '') + '">';
        h += '</div>';

        // Notes
        h += '<div class="tt-edit-field" style="grid-column: span 2;">';
        h += '<label>Notes</label>';
        h += '<textarea class="form-control form-control-sm" id="tt-edit-notes-' + recordId + '" rows="2">' + self.escHtml(rec.notes || '') + '</textarea>';
        h += '</div>';

        h += '</div>';

        // Attribute toggles
        h += '<div class="tt-attr-chips">';
        h += self.attrToggle(recordId, 'rookie', 'Rookie', parseInt(rec.is_rookie));
        h += self.attrToggle(recordId, 'autograph', 'Auto', parseInt(rec.is_autograph));
        h += self.attrToggle(recordId, 'relic', 'Relic', parseInt(rec.is_relic));
        h += self.attrToggle(recordId, 'giveaway', 'Giveaway', parseInt(rec.is_giveaway));
        h += self.attrToggle(recordId, 'verified', 'Verified', parseInt(rec.is_verified));
        h += '</div>';

        // Action buttons
        h += '<div style="margin-top:12px;display:flex;gap:8px;">';
        h += '<button class="btn btn-primary btn-sm" id="tt-save-' + recordId + '">Save</button>';
        h += '<button class="btn btn-danger btn-sm" id="tt-delete-' + recordId + '">Delete</button>';
        h += '</div>';

        container.innerHTML = h;

        // Wire save/delete
        document.getElementById('tt-save-' + recordId).addEventListener('click', function() {
            self.saveRecord(recordId);
        });
        document.getElementById('tt-delete-' + recordId).addEventListener('click', function() {
            if (confirm('Delete this record?')) {
                self.deleteRecord(recordId);
            }
        });
    },

    attrToggle: function(recordId, field, label, isChecked) {
        var checked = isChecked ? ' checked' : '';
        return '<label class="tt-toggle-label">'
             + '<input type="checkbox" id="tt-attr-' + field + '-' + recordId + '"' + checked + '> '
             + label + '</label>';
    },

    // ─── CRUD ────────────────────────────────────────────────

    parseSession: function() {
        if (!this.selectedSessionId) return;

        var self = this;
        var btn = document.getElementById('tt-parse-btn');
        btn.disabled = true;
        btn.textContent = 'Parsing...';

        API.post('/api/transcription/sessions/' + this.selectedSessionId + '/parse').then(function(result) {
            btn.disabled = false;
            btn.textContent = 'Parse Session';

            App.toast('Parsed ' + result.total_records + ' records (' + result.high_confidence + ' high confidence)', 'success');

            self.currentRunId = result.run_id;
            self.loadParseRuns(self.selectedSessionId);
        }).catch(function(err) {
            btn.disabled = false;
            btn.textContent = 'Parse Session';
            App.toast('Parse failed: ' + err.message, 'error');
        });
    },

    transcribeAudio: function() {
        if (!this.selectedSessionId) return;
        if (!confirm('Start Whisper transcription for pending audio segments?')) return;

        var self = this;
        var btn = document.getElementById('tt-transcribe-btn');
        btn.disabled = true;
        btn.textContent = 'Starting...';

        API.post('/api/transcription/sessions/' + this.selectedSessionId + '/transcribe').then(function(result) {
            App.toast(result.message || 'Transcription started', 'success');
            btn.disabled = true;
            btn.textContent = 'Transcribing...';
            // Refresh session data after a brief delay to pick up status change
            setTimeout(function() { self.loadSessions(); }, 3000);
        }).catch(function(err) {
            App.toast(err.message || 'Failed to start transcription', 'error');
            btn.disabled = false;
            btn.textContent = 'Transcribe Audio';
        });
    },

    saveRecord: function(recordId) {
        var data = {
            lot_number: document.getElementById('tt-edit-lot-' + recordId).value || null,
            raw_parallel: document.getElementById('tt-edit-parallel-' + recordId).value || null,
            raw_card_number: document.getElementById('tt-edit-cardnum-' + recordId).value || null,
            notes: document.getElementById('tt-edit-notes-' + recordId).value || null,
            is_rookie: document.getElementById('tt-attr-rookie-' + recordId).checked ? 1 : 0,
            is_autograph: document.getElementById('tt-attr-autograph-' + recordId).checked ? 1 : 0,
            is_relic: document.getElementById('tt-attr-relic-' + recordId).checked ? 1 : 0,
            is_giveaway: document.getElementById('tt-attr-giveaway-' + recordId).checked ? 1 : 0,
            is_verified: document.getElementById('tt-attr-verified-' + recordId).checked ? 1 : 0,
        };

        var self = this;
        API.put('/api/transcription/records/' + recordId, data).then(function() {
            App.toast('Record updated', 'success');
            // Refresh the records to pick up changes
            self.loadRecords(self.selectedSessionId, self.currentRunId);
        }).catch(function(err) {
            App.toast('Save failed: ' + err.message, 'error');
        });
    },

    deleteRecord: function(recordId) {
        var self = this;
        API.del('/api/transcription/records/' + recordId).then(function() {
            App.toast('Record deleted', 'success');
            self.expandedRow = null;
            self.loadRecords(self.selectedSessionId, self.currentRunId);
        }).catch(function(err) {
            App.toast('Delete failed: ' + err.message, 'error');
        });
    },

    // ─── Raw Text Modal ──────────────────────────────────────

    showRawText: function() {
        if (!this.selectedSessionId) return;

        var self = this;
        API.get('/api/transcription/sessions/' + this.selectedSessionId + '/transcript-text').then(function(result) {
            var segments = result.segments || [];
            var h = '<div style="max-height:70vh;overflow-y:auto;padding:20px;">';
            h += '<h3 style="margin-bottom:16px;">Raw Transcript — Session ' + result.session_id + '</h3>';
            h += '<p style="font-size:12px;color:#666;margin-bottom:16px;">Total: ' + (result.total_chars || 0).toLocaleString() + ' characters across ' + segments.length + ' segments</p>';

            segments.forEach(function(seg) {
                h += '<div style="margin-bottom:16px;">';
                h += '<div style="font-weight:600;font-size:12px;color:#1565c0;margin-bottom:4px;">Segment ' + seg.segment_number + '</div>';
                h += '<div class="tt-excerpt-text" style="font-size:12px;max-height:200px;overflow-y:auto;">' + self.escHtml(seg.text) + '</div>';
                h += '</div>';
            });

            h += '</div>';
            App.openModal(h);
        }).catch(function(err) {
            App.toast('Failed to load transcript: ' + err.message, 'error');
        });
    },

    // ─── Helpers ─────────────────────────────────────────────

    escHtml: function(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    },

    escAttr: function(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
};
