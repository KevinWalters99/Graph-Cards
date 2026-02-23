/**
 * Card Graph — Parser Support Tables (Maintenance sub-tab)
 * Nested sub-tabs: Players, Teams, Makers, Styles, Specialties
 * Features: client-side sort/filter, MLB API stats refresh, stats detail modal
 */
var ParserAdmin = {
    initialized: false,
    currentSubTab: 'players',

    // ─── Data & state per entity ──────────────────────────────────
    _playersData: [], _playerSearch: '', _playerSort: { key: 'last_name', dir: 'asc' }, _playerPage: 1,
    _playerFilters: { position: '', team: '', division: '', level: '', status: '', draftStatus: '' },
    _teamsData: [],   _teamSearch: '',   _teamSort: { key: 'team_name', dir: 'asc' },   _teamPage: 1,
    _teamFilters: { division: '' },
    _makersData: [],  _makerSearch: '',  _makerSort: { key: 'name', dir: 'asc' },        _makerPage: 1,
    _stylesData: [],  _styleSearch: '',  _styleSort: { key: 'style_name', dir: 'asc' },  _stylePage: 1,
    _specialtiesData: [], _specSearch: '', _specSort: { key: 'name', dir: 'asc' },       _specPage: 1,
    _teamsListCache: null,
    _debounceTimer: null,

    // ─── Init ─────────────────────────────────────────────────────

    init: function() {
        if (!this.initialized) {
            var container = document.getElementById('maint-panel-parser');
            if (!container) return;

            var parts = [];

            // Refresh panel
            parts.push('<div class="parser-refresh-panel" id="parser-refresh-panel">');
            parts.push('<div id="parser-refresh-status"><span class="text-muted">Loading refresh status...</span></div>');
            parts.push('<div style="display:flex;gap:8px;flex-shrink:0;">');
            parts.push('<button class="btn btn-primary btn-sm" id="btn-refresh-standings">Update Standings</button>');
            parts.push('<button class="btn btn-primary btn-sm" id="btn-refresh-rosters">Update Rosters &amp; Stats</button>');
            parts.push('<button class="btn btn-secondary btn-sm" id="btn-refresh-lastseason">Update Last Season</button>');
            parts.push('<button class="btn btn-success btn-sm" id="btn-refresh-milb">Import MiLB Rosters</button>');
            parts.push('<button class="btn btn-info btn-sm" id="btn-refresh-historical">Import Historical Stars</button>');
            parts.push('<button class="btn btn-secondary btn-sm" id="btn-backfill-draft">Backfill Draft Info</button>');
            parts.push('<button class="btn btn-secondary btn-sm" id="btn-backfill-status">Backfill Draft Status</button>');
            parts.push('<button class="btn btn-warning btn-sm" id="btn-import-popularity">Import Popularity Rankings</button>');
            parts.push('</div>');
            parts.push('</div>');
            parts.push('<div id="parser-refresh-progress" style="display:none;padding:6px 12px;background:#e8f4fd;border-radius:4px;margin-bottom:8px;font-size:13px;"></div>');

            // Sub-tabs
            parts.push('<div class="parser-sub-tabs" id="parser-sub-tabs">');
            parts.push('<button class="sub-tab active" data-parser-subtab="players">Players</button>');
            parts.push('<button class="sub-tab" data-parser-subtab="teams">Teams</button>');
            parts.push('<button class="sub-tab" data-parser-subtab="makers">Makers</button>');
            parts.push('<button class="sub-tab" data-parser-subtab="styles">Styles</button>');
            parts.push('<button class="sub-tab" data-parser-subtab="specialties">Specialties</button>');
            parts.push('</div>');
            parts.push('<div id="parser-panel-players" class="sub-panel"></div>');
            parts.push('<div id="parser-panel-teams" class="sub-panel" style="display:none;"></div>');
            parts.push('<div id="parser-panel-makers" class="sub-panel" style="display:none;"></div>');
            parts.push('<div id="parser-panel-styles" class="sub-panel" style="display:none;"></div>');
            parts.push('<div id="parser-panel-specialties" class="sub-panel" style="display:none;"></div>');
            container.innerHTML = parts.join('\n');

            var self = this;
            var tabs = document.querySelectorAll('#parser-sub-tabs .sub-tab');
            for (var i = 0; i < tabs.length; i++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        self.switchSubTab(btn.getAttribute('data-parser-subtab'));
                    });
                })(tabs[i]);
            }

            // Refresh buttons
            document.getElementById('btn-refresh-standings').addEventListener('click', function() { self.refreshStandings(); });
            document.getElementById('btn-refresh-rosters').addEventListener('click', function() { self.startRosterRefresh('current'); });
            document.getElementById('btn-refresh-lastseason').addEventListener('click', function() { self.startRosterRefresh('lastseason'); });
            document.getElementById('btn-refresh-milb').addEventListener('click', function() { self.startMilbRefresh(); });
            document.getElementById('btn-refresh-historical').addEventListener('click', function() { self.startHistoricalImport(); });
            document.getElementById('btn-backfill-draft').addEventListener('click', function() { self.startDraftBackfill(); });
            document.getElementById('btn-backfill-status').addEventListener('click', function() { self.startDraftStatusBackfill(); });
            document.getElementById('btn-import-popularity').addEventListener('click', function() { self.importPopularityRankings(); });

            this.initialized = true;
        }

        this.loadRefreshStatus();
        this.loadTeamsCache();
        this.switchSubTab(this.currentSubTab);
    },

    switchSubTab: function(name) {
        this.currentSubTab = name;

        var tabs = document.querySelectorAll('#parser-sub-tabs .sub-tab');
        for (var i = 0; i < tabs.length; i++) {
            var active = tabs[i].getAttribute('data-parser-subtab') === name;
            if (active) tabs[i].classList.add('active');
            else tabs[i].classList.remove('active');
        }

        var panels = ['players', 'teams', 'makers', 'styles', 'specialties'];
        for (var j = 0; j < panels.length; j++) {
            var el = document.getElementById('parser-panel-' + panels[j]);
            if (el) el.style.display = panels[j] === name ? '' : 'none';
        }

        if (name === 'players') this.loadPlayers();
        if (name === 'teams') this.loadTeams();
        if (name === 'makers') this.loadMakers();
        if (name === 'styles') this.loadStyles();
        if (name === 'specialties') this.loadSpecialties();
    },

    // ─── Refresh Status ───────────────────────────────────────────

    loadRefreshStatus: function() {
        var allTypes = [
            { key: 'team_standings',    label: 'Standings' },
            { key: 'team_rosters',      label: 'MLB Rosters' },
            { key: 'last_season_stats', label: 'Last Season' },
            { key: 'milb_rosters',      label: 'MiLB Rosters' },
            { key: 'historical_players', label: 'Historical Stars' }
        ];
        API.get('/api/parser/refresh/status').then(function(result) {
            var el = document.getElementById('parser-refresh-status');
            if (!el) return;
            var items = result.data || [];
            // Build lookup by data_type
            var lookup = {};
            for (var i = 0; i < items.length; i++) {
                lookup[items[i].data_type] = items[i];
            }
            var html = [];
            for (var j = 0; j < allTypes.length; j++) {
                var t = allTypes[j];
                var item = lookup[t.key];
                var time = (item && item.last_completed) ? App.formatDatetime(item.last_completed) : 'Never';
                var count = (item && item.last_records_updated) ? ' (' + item.last_records_updated + ')' : '';
                var running = (item && item.currently_running)
                    ? ' <span class="status-badge status-pending">Running</span>' : '';
                var cls = (item && item.last_completed) ? '' : ' style="opacity:0.5"';
                html.push('<span class="parser-refresh-item"' + cls + '>' + t.label + ': <strong>' + time + '</strong>'
                    + count + running + '</span>');
            }
            el.innerHTML = html.join(' &nbsp;|&nbsp; ');
        }).catch(function() {});
    },

    refreshStandings: function() {
        var self = this;
        var btn = document.getElementById('btn-refresh-standings');
        btn.disabled = true; btn.textContent = 'Updating...';

        API.post('/api/parser/refresh/standings', {}).then(function(result) {
            btn.disabled = false; btn.textContent = 'Update Standings';
            App.toast(result.message, 'success');
            self.loadRefreshStatus();
            if (self.currentSubTab === 'teams') self.loadTeams();
        }).catch(function(err) {
            btn.disabled = false; btn.textContent = 'Update Standings';
            App.toast(err.message || 'Standings refresh failed', 'error');
        });
    },

    startRosterRefresh: function(mode) {
        var self = this;
        var btnId = mode === 'lastseason' ? 'btn-refresh-lastseason' : 'btn-refresh-rosters';
        var btn = document.getElementById(btnId);
        var progressEl = document.getElementById('parser-refresh-progress');
        btn.disabled = true;
        btn.textContent = 'Refreshing...';
        progressEl.style.display = '';
        progressEl.textContent = 'Starting...';

        var postData = { batch_offset: 0 };
        if (mode === 'lastseason') {
            postData.stats_season = String(parseInt(new Date().getFullYear()) - 1);
            postData.stats_field = 'last_season_stats';
            postData.data_type = 'last_season_stats';
        }

        function doBatch(offset) {
            postData.batch_offset = offset;
            API.post('/api/parser/refresh/rosters', postData).then(function(result) {
                progressEl.textContent = result.message
                    + (result.players_updated ? ' (' + result.players_updated + ' players)' : '');
                if (result.errors && result.errors.length > 0) {
                    progressEl.textContent += ' [' + result.errors.length + ' errors]';
                }
                if (!result.complete) {
                    doBatch(result.next_offset);
                } else {
                    btn.disabled = false;
                    btn.textContent = mode === 'lastseason' ? 'Update Last Season' : 'Update Rosters & Stats';
                    progressEl.style.display = 'none';
                    App.toast('Refresh complete', 'success');
                    self.loadRefreshStatus();
                    if (self.currentSubTab === 'players') self.loadPlayers();
                    if (self.currentSubTab === 'teams') self.loadTeams();
                }
            }).catch(function(err) {
                btn.disabled = false;
                btn.textContent = mode === 'lastseason' ? 'Update Last Season' : 'Update Rosters & Stats';
                var errMsg = err.message || 'refresh failed';
                // Clean up HTML/timeout errors to be user-friendly
                if (errMsg.indexOf('<!DOCTYPE') !== -1 || errMsg.indexOf('Unexpected token') !== -1) {
                    errMsg = 'Request timed out — try again (batch will resume where it left off)';
                }
                progressEl.textContent = 'Error: ' + errMsg;
                App.toast(errMsg, 'error');
                // Auto-hide the progress error after 5 seconds
                setTimeout(function() { progressEl.style.display = 'none'; }, 5000);
                self.loadRefreshStatus();
            });
        }

        doBatch(0);
    },

    startMilbRefresh: function() {
        var self = this;
        var btn = document.getElementById('btn-refresh-milb');
        var progressEl = document.getElementById('parser-refresh-progress');
        btn.disabled = true;
        btn.textContent = 'Importing...';
        progressEl.style.display = '';
        progressEl.textContent = 'Starting MiLB roster import...';

        function doBatch(offset) {
            API.post('/api/parser/refresh/milb-rosters', { batch_offset: offset }).then(function(result) {
                progressEl.textContent = result.message
                    + (result.players_updated ? ' (' + result.players_updated + ' players)' : '');
                if (result.errors && result.errors.length > 0) {
                    progressEl.textContent += ' [' + result.errors.length + ' errors]';
                }
                if (!result.complete) {
                    doBatch(result.next_offset);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Import MiLB Rosters';
                    progressEl.style.display = 'none';
                    App.toast('MiLB roster import complete', 'success');
                    self.loadRefreshStatus();
                    if (self.currentSubTab === 'players') self.loadPlayers();
                }
            }).catch(function(err) {
                btn.disabled = false;
                btn.textContent = 'Import MiLB Rosters';
                var errMsg = err.message || 'MiLB import failed';
                if (errMsg.indexOf('<!DOCTYPE') !== -1 || errMsg.indexOf('Unexpected token') !== -1) {
                    errMsg = 'Request timed out — try again (will resume)';
                }
                progressEl.textContent = 'Error: ' + errMsg;
                App.toast(errMsg, 'error');
                setTimeout(function() { progressEl.style.display = 'none'; }, 5000);
                self.loadRefreshStatus();
            });
        }

        doBatch(0);
    },

    startHistoricalImport: function() {
        var self = this;
        var btn = document.getElementById('btn-refresh-historical');
        var progressEl = document.getElementById('parser-refresh-progress');
        btn.disabled = true;
        btn.textContent = 'Gathering...';
        progressEl.style.display = '';
        progressEl.textContent = 'Fetching award recipients (HOF, MVP, Cy Young, ROY)...';

        // Phase 1: Gather notable player IDs from awards
        API.post('/api/parser/refresh/historical', { phase: 'gather' }).then(function(result) {
            var allPlayers = result.players || [];
            var totalNew = result.new_players || 0;
            var totalFound = result.total_found || 0;
            var alreadyExist = result.already_exist || 0;

            if (totalNew === 0) {
                btn.disabled = false;
                btn.textContent = 'Import Historical Stars';
                progressEl.textContent = 'All ' + totalFound + ' notable players already in database (' + alreadyExist + ' existing)';
                App.toast('No new historical players to import', 'info');
                // Finalize the log
                API.post('/api/parser/refresh/historical', { phase: 'import', players: [] });
                setTimeout(function() { progressEl.style.display = 'none'; }, 5000);
                self.loadRefreshStatus();
                return;
            }

            progressEl.textContent = 'Found ' + totalNew + ' new players (of ' + totalFound + ' notable). Importing...';
            btn.textContent = 'Importing...';

            // Phase 2: Import in batches of 30
            var batchSize = 30;
            var totalImported = 0;

            function doImportBatch(offset) {
                var batch = allPlayers.slice(offset, offset + batchSize);
                if (batch.length === 0) {
                    // Done — finalize log
                    API.post('/api/parser/refresh/historical', { phase: 'import', players: [] }).then(function() {
                        btn.disabled = false;
                        btn.textContent = 'Import Historical Stars';
                        progressEl.style.display = 'none';
                        App.toast('Imported ' + totalImported + ' historical players', 'success');
                        self.loadRefreshStatus();
                        if (self.currentSubTab === 'players') self.loadPlayers();
                    });
                    return;
                }

                API.post('/api/parser/refresh/historical', { phase: 'import', players: batch }).then(function(batchResult) {
                    totalImported += batchResult.players_updated || 0;
                    progressEl.textContent = 'Imported ' + totalImported + ' of ' + totalNew + ' players...';
                    doImportBatch(offset + batchSize);
                }).catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = 'Import Historical Stars';
                    var errMsg = err.message || 'Import failed';
                    if (errMsg.indexOf('<!DOCTYPE') !== -1 || errMsg.indexOf('Unexpected token') !== -1) {
                        errMsg = 'Request timed out — try again';
                    }
                    progressEl.textContent = 'Error at batch ' + (offset + 1) + ': ' + errMsg;
                    App.toast(errMsg, 'error');
                    setTimeout(function() { progressEl.style.display = 'none'; }, 5000);
                    self.loadRefreshStatus();
                });
            }

            doImportBatch(0);
        }).catch(function(err) {
            btn.disabled = false;
            btn.textContent = 'Import Historical Stars';
            var errMsg = err.message || 'Gather phase failed';
            if (errMsg.indexOf('<!DOCTYPE') !== -1 || errMsg.indexOf('Unexpected token') !== -1) {
                errMsg = 'Request timed out during award fetching — try again';
            }
            progressEl.textContent = 'Error: ' + errMsg;
            App.toast(errMsg, 'error');
            setTimeout(function() { progressEl.style.display = 'none'; }, 5000);
            self.loadRefreshStatus();
        });
    },

    startDraftBackfill: function() {
        var self = this;
        var btn = document.getElementById('btn-backfill-draft');
        var progressEl = document.getElementById('parser-refresh-progress');
        btn.disabled = true;
        btn.textContent = 'Backfilling...';
        progressEl.style.display = '';
        progressEl.textContent = 'Fetching draft info from MLB API...';

        function doBatch(offset) {
            API.post('/api/parser/refresh/backfill-draft', { batch_offset: offset }).then(function(result) {
                progressEl.textContent = result.message
                    + (result.players_updated ? ' (' + result.players_updated + ' drafted)' : '');
                if (!result.complete) {
                    doBatch(result.next_offset);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Backfill Draft Info';
                    progressEl.style.display = 'none';
                    App.toast('Draft info backfill complete', 'success');
                    self.loadRefreshStatus();
                    if (self.currentSubTab === 'players') self.loadPlayers();
                }
            }).catch(function(err) {
                btn.disabled = false;
                btn.textContent = 'Backfill Draft Info';
                var errMsg = err.message || 'Draft backfill failed';
                if (errMsg.indexOf('<!DOCTYPE') !== -1 || errMsg.indexOf('Unexpected token') !== -1) {
                    errMsg = 'Request timed out — try again (will resume)';
                }
                progressEl.textContent = 'Error: ' + errMsg;
                App.toast(errMsg, 'error');
                setTimeout(function() { progressEl.style.display = 'none'; }, 5000);
            });
        }

        doBatch(0);
    },

    startDraftStatusBackfill: function() {
        var self = this;
        var btn = document.getElementById('btn-backfill-status');
        var progressEl = document.getElementById('parser-refresh-progress');
        btn.disabled = true;
        btn.textContent = 'Backfilling...';
        progressEl.style.display = '';
        progressEl.textContent = 'Determining draft status from MLB API...';

        function doBatch(offset) {
            API.post('/api/parser/refresh/backfill-status', { batch_offset: offset }).then(function(result) {
                progressEl.textContent = result.message
                    + (result.players_updated ? ' (' + result.players_updated + ' classified)' : '');
                if (!result.complete) {
                    doBatch(result.next_offset);
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Backfill Draft Status';
                    progressEl.style.display = 'none';
                    App.toast('Draft status backfill complete', 'success');
                    if (self.currentSubTab === 'players') self.loadPlayers();
                }
            }).catch(function(err) {
                btn.disabled = false;
                btn.textContent = 'Backfill Draft Status';
                var errMsg = err.message || 'Draft status backfill failed';
                if (errMsg.indexOf('<!DOCTYPE') !== -1 || errMsg.indexOf('Unexpected token') !== -1) {
                    errMsg = 'Request timed out — try again (will resume)';
                }
                progressEl.textContent = 'Error: ' + errMsg;
                App.toast(errMsg, 'error');
                setTimeout(function() { progressEl.style.display = 'none'; }, 5000);
            });
        }

        doBatch(0);
    },

    importPopularityRankings: function() {
        var self = this;
        var btn = document.getElementById('btn-import-popularity');
        btn.disabled = true;
        btn.textContent = 'Importing...';

        API.post('/api/parser/refresh/popularity', {}).then(function(result) {
            btn.disabled = false;
            btn.textContent = 'Import Popularity Rankings';
            var msg = result.message || 'Popularity rankings updated';
            if (result.unmatched && result.unmatched.length > 0) {
                msg += ' | Unmatched: ' + result.unmatched.join(', ');
            }
            App.toast(msg, 'success');
            if (self.currentSubTab === 'players') self.loadPlayers();
        }).catch(function(err) {
            btn.disabled = false;
            btn.textContent = 'Import Popularity Rankings';
            App.toast(err.message || 'Popularity import failed', 'error');
        });
    },

    // ─── Teams cache (for player form dropdown) ───────────────────

    loadTeamsCache: function() {
        var self = this;
        API.get('/api/parser/teams').then(function(result) {
            self._teamsListCache = (result.data || []).sort(function(a, b) {
                return (a.abbreviation || a.team_name).localeCompare(b.abbreviation || b.team_name);
            });
        }).catch(function() {});
    },

    // ─── Shared sort/filter/paginate helpers ──────────────────────

    _sortData: function(data, key, dir) {
        return data.slice().sort(function(a, b) {
            var aVal = a[key]; var bVal = b[key];
            if (aVal == null) aVal = '';
            if (bVal == null) bVal = '';
            // Numeric sort for rank, id fields
            var aNum = parseFloat(aVal); var bNum = parseFloat(bVal);
            if (!isNaN(aNum) && !isNaN(bNum) && String(aVal).match(/^[\d.]+$/)) {
                return dir === 'asc' ? aNum - bNum : bNum - aNum;
            }
            aVal = String(aVal).toLowerCase(); bVal = String(bVal).toLowerCase();
            if (aVal < bVal) return dir === 'asc' ? -1 : 1;
            if (aVal > bVal) return dir === 'asc' ? 1 : -1;
            return 0;
        });
    },

    _filterData: function(data, search, fields) {
        if (!search) return data;
        var terms = search.toLowerCase().split(/\s+/);
        return data.filter(function(row) {
            var text = '';
            for (var i = 0; i < fields.length; i++) {
                var v = row[fields[i]];
                if (Array.isArray(v)) {
                    v = v.map(function(x) { return x.nickname || x.alias_name || ''; }).join(' ');
                }
                text += ' ' + (v || '');
            }
            text = text.toLowerCase();
            for (var j = 0; j < terms.length; j++) {
                if (text.indexOf(terms[j]) === -1) return false;
            }
            return true;
        });
    },

    _pageData: function(data, page, perPage) {
        var start = (page - 1) * perPage;
        return data.slice(start, start + perPage);
    },

    _buildSearchBar: function(addBtnId, addBtnLabel, searchId, placeholder, filters) {
        var html = '<div class="parser-search-bar">';
        html += '<button class="btn btn-success btn-sm" id="' + addBtnId + '">' + addBtnLabel + '</button>';
        if (filters && filters.length) {
            for (var i = 0; i < filters.length; i++) {
                var f = filters[i];
                html += '<select id="' + f.id + '" class="parser-filter-select">';
                html += '<option value="">' + f.placeholder + '</option>';
                for (var j = 0; j < f.options.length; j++) {
                    var opt = f.options[j];
                    var sel = f.value === opt ? ' selected' : '';
                    html += '<option value="' + this.escHtml(opt) + '"' + sel + '>' + this.escHtml(opt) + '</option>';
                }
                html += '</select>';
            }
        }
        html += '<div style="flex:1;"></div>';
        html += '<input type="text" id="' + searchId + '" placeholder="' + placeholder + '">';
        html += '</div>';
        return html;
    },

    // ─── Players ──────────────────────────────────────────────────

    loadPlayers: function() {
        var self = this;
        API.get('/api/parser/players').then(function(result) {
            var data = result.data || [];
            // Compute full_division from team association
            for (var i = 0; i < data.length; i++) {
                data[i].full_division = (data[i].team_league && data[i].team_division)
                    ? data[i].team_league + ' ' + data[i].team_division : '';
            }
            self._playersData = data;
            self._playerPage = 1;
            self._renderPlayersShell();
            self._renderPlayersTable();
        }).catch(function() {
            document.getElementById('parser-panel-players').innerHTML =
                '<p class="text-muted">Unable to load players.</p>';
        });
    },

    _getPlayerFilterOptions: function(field) {
        var vals = {};
        for (var i = 0; i < this._playersData.length; i++) {
            var v = this._playersData[i][field];
            if (v) vals[v] = true;
        }
        return Object.keys(vals).sort();
    },

    _getPlayerLevelOptions: function() {
        // Fixed order: MLB first, then descending MiLB levels
        var order = ['MLB', 'AAA', 'AA', 'A+', 'A', 'Rookie', 'DSL'];
        var present = {};
        for (var i = 0; i < this._playersData.length; i++) {
            var lvl = this._playersData[i].minor_league_level || (this._playersData[i].current_team_id ? 'MLB' : '');
            if (lvl) present[lvl] = true;
        }
        return order.filter(function(l) { return present[l]; });
    },

    _renderPlayersShell: function() {
        var self = this;
        var container = document.getElementById('parser-panel-players');
        var filters = [
            { id: 'parser-player-pos-filter', placeholder: 'All Positions', options: self._getPlayerFilterOptions('primary_position'), value: self._playerFilters.position },
            { id: 'parser-player-team-filter', placeholder: 'All Teams', options: self._getPlayerFilterOptions('team_abbreviation'), value: self._playerFilters.team },
            { id: 'parser-player-div-filter', placeholder: 'All Divisions', options: self._getPlayerFilterOptions('full_division'), value: self._playerFilters.division },
            { id: 'parser-player-level-filter', placeholder: 'All Levels', options: self._getPlayerLevelOptions(), value: self._playerFilters.level },
            { id: 'parser-player-status-filter', placeholder: 'All Status', options: ['Active', 'Retired', 'Inactive'], value: self._playerFilters.status },
            { id: 'parser-player-draft-filter', placeholder: 'All Draft', options: ['Drafted', 'Intl FA', 'Undrafted', 'Pre-Draft', 'Unknown'], value: self._playerFilters.draftStatus }
        ];
        container.innerHTML = self._buildSearchBar('btn-add-player', 'Add Player', 'parser-player-search', 'Search players...', filters)
            + '<div id="parser-players-table"></div>';

        document.getElementById('btn-add-player').addEventListener('click', function() { self.showPlayerForm(); });
        var searchEl = document.getElementById('parser-player-search');
        searchEl.value = self._playerSearch;
        searchEl.addEventListener('input', function() {
            clearTimeout(self._debounceTimer);
            self._debounceTimer = setTimeout(function() {
                self._playerSearch = searchEl.value.trim();
                self._playerPage = 1;
                self._renderPlayersTable();
            }, 200);
        });

        // Filter event listeners
        var posFilter = document.getElementById('parser-player-pos-filter');
        var teamFilter = document.getElementById('parser-player-team-filter');
        var divFilter = document.getElementById('parser-player-div-filter');
        if (posFilter) posFilter.addEventListener('change', function() {
            self._playerFilters.position = posFilter.value;
            self._playerPage = 1; self._renderPlayersTable();
        });
        if (teamFilter) teamFilter.addEventListener('change', function() {
            self._playerFilters.team = teamFilter.value;
            self._playerPage = 1; self._renderPlayersTable();
        });
        if (divFilter) divFilter.addEventListener('change', function() {
            self._playerFilters.division = divFilter.value;
            self._cascadePlayerTeamOptions();
            self._playerPage = 1; self._renderPlayersTable();
        });
        var levelFilter = document.getElementById('parser-player-level-filter');
        if (levelFilter) levelFilter.addEventListener('change', function() {
            self._playerFilters.level = levelFilter.value;
            self._cascadePlayerTeamOptions();
            self._playerPage = 1; self._renderPlayersTable();
        });
        var statusFilter = document.getElementById('parser-player-status-filter');
        if (statusFilter) statusFilter.addEventListener('change', function() {
            self._playerFilters.status = statusFilter.value;
            self._playerPage = 1; self._renderPlayersTable();
        });
        var draftFilter = document.getElementById('parser-player-draft-filter');
        if (draftFilter) draftFilter.addEventListener('change', function() {
            self._playerFilters.draftStatus = draftFilter.value;
            self._playerPage = 1; self._renderPlayersTable();
        });
    },

    _cascadePlayerTeamOptions: function() {
        var self = this;
        var teamSelect = document.getElementById('parser-player-team-filter');
        if (!teamSelect) return;

        // Get valid teams from players matching current division + level filters
        var validTeams = {};
        for (var i = 0; i < self._playersData.length; i++) {
            var row = self._playersData[i];
            if (self._playerFilters.division && row.full_division !== self._playerFilters.division) continue;
            if (self._playerFilters.level) {
                var rowLevel = row.minor_league_level || (row.current_team_id ? 'MLB' : '');
                if (rowLevel !== self._playerFilters.level) continue;
            }
            if (row.team_abbreviation) validTeams[row.team_abbreviation] = true;
        }
        var teamList = Object.keys(validTeams).sort();

        // Rebuild options
        var html = '<option value="">All Teams</option>';
        for (var j = 0; j < teamList.length; j++) {
            var sel = self._playerFilters.team === teamList[j] ? ' selected' : '';
            html += '<option value="' + self.escHtml(teamList[j]) + '"' + sel + '>' + self.escHtml(teamList[j]) + '</option>';
        }
        teamSelect.innerHTML = html;

        // If current team selection is no longer valid, clear it
        if (self._playerFilters.team && !validTeams[self._playerFilters.team]) {
            self._playerFilters.team = '';
            teamSelect.value = '';
        }
    },

    _applyPlayerFilters: function(data) {
        var f = this._playerFilters;
        return data.filter(function(row) {
            if (f.position && row.primary_position !== f.position) return false;
            if (f.team && row.team_abbreviation !== f.team) return false;
            if (f.division && row.full_division !== f.division) return false;
            if (f.level) {
                var rowLevel = row.minor_league_level || (row.current_team_id ? 'MLB' : '');
                if (rowLevel !== f.level) return false;
            }
            if (f.status) {
                var statusLabel = row.is_active == 2 ? 'Retired' : (row.is_active == 1 ? 'Active' : 'Inactive');
                if (statusLabel !== f.status) return false;
            }
            if (f.draftStatus && (row.draft_status || '') !== f.draftStatus) return false;
            return true;
        });
    },

    _renderPlayersTable: function() {
        var self = this;
        var filtered = self._applyPlayerFilters(self._playersData);
        filtered = self._filterData(filtered, self._playerSearch,
            ['first_name', 'last_name', 'primary_position', 'team_abbreviation', 'team_name', 'nicknames', 'minor_league_level']);
        var sorted = self._sortData(filtered, self._playerSort.key, self._playerSort.dir);
        var perPage = 50;
        var paged = self._pageData(sorted, self._playerPage, perPage);

        DataTable.render(document.getElementById('parser-players-table'), {
            columns: [
                { key: 'last_name', label: 'Last Name' },
                { key: 'first_name', label: 'First Name' },
                { key: 'primary_position', label: 'Pos' },
                {
                    key: 'bats', label: 'B/T',
                    render: function(row) {
                        var b = row.bats || '-';
                        var t = row.throws_hand || '-';
                        return '<span class="parser-stat">' + b + '/' + t + '</span>';
                    }
                },
                {
                    key: 'team_abbreviation', label: 'Team',
                    render: function(row) {
                        if (!row.team_abbreviation) return '<span class="text-muted">-</span>';
                        var logo = row.team_mlb_id
                            ? '<img class="parser-team-logo" src="/img/teams/' + row.team_mlb_id + '.png" alt="">'
                            : '';
                        return logo + '<span title="' + self.escHtml(row.team_name || '') + '">' + self.escHtml(row.team_abbreviation) + '</span>';
                    }
                },
                {
                    key: 'minor_league_level', label: 'Level',
                    render: function(row) {
                        if (row.minor_league_level) return '<span class="parser-tag">' + self.escHtml(row.minor_league_level) + '</span>';
                        return row.current_team_id ? 'MLB' : '<span class="text-muted">-</span>';
                    }
                },
                {
                    key: 'prospect_rank', label: 'Rank',
                    render: function(row) {
                        return row.prospect_rank ? '#' + row.prospect_rank : '<span class="text-muted">-</span>';
                    }
                },
                {
                    key: 'popularity_score', label: 'Pop',
                    render: function(row) {
                        return row.popularity_score ? '#' + row.popularity_score : '<span class="text-muted">-</span>';
                    }
                },
                {
                    key: 'draft_year', label: 'Draft',
                    render: function(row) {
                        if (row.draft_year) {
                            var parts = [row.draft_year];
                            if (row.draft_round) parts.push('Rd ' + row.draft_round);
                            if (row.draft_pick) parts.push('#' + row.draft_pick);
                            return '<span class="parser-stat">' + parts.join(' ') + '</span>';
                        }
                        if (row.draft_status) {
                            var cls = 'draft-status-tag';
                            if (row.draft_status === 'Intl FA') cls += ' draft-intl';
                            else if (row.draft_status === 'Pre-Draft') cls += ' draft-pre';
                            else if (row.draft_status === 'Undrafted') cls += ' draft-undrafted';
                            return '<span class="' + cls + '">' + self.escHtml(row.draft_status) + '</span>';
                        }
                        return '<span class="text-muted">-</span>';
                    }
                },
                {
                    key: 'stats_summary', label: 'Key Stats', sortable: false,
                    render: function(row) { return self._formatPlayerStatsSummary(row); }
                },
                {
                    key: 'trend', label: 'Trend', sortable: false,
                    render: function(row) { return self._formatPlayerTrend(row); }
                },
                {
                    key: 'nicknames', label: 'Nicknames', sortable: false,
                    render: function(row) {
                        if (!row.nicknames || row.nicknames.length === 0) return '<span class="text-muted">-</span>';
                        var tags = [];
                        for (var i = 0; i < row.nicknames.length; i++) {
                            tags.push('<span class="parser-tag">' + self.escHtml(row.nicknames[i].nickname) + '</span>');
                        }
                        return tags.join(' ');
                    }
                },
                {
                    key: 'is_active', label: 'Status',
                    render: function(row) {
                        if (row.is_active == 2) return '<span class="status-badge status-retired">Retired</span>';
                        if (row.is_active == 1) return '<span class="status-badge status-completed">Active</span>';
                        return '<span class="status-badge status-cancelled">Inactive</span>';
                    }
                },
                {
                    key: 'actions', label: '', sortable: false,
                    render: function(row) {
                        var wrap = document.createElement('span');
                        wrap.style.display = 'flex'; wrap.style.gap = '4px';

                        var statsBtn = document.createElement('button');
                        statsBtn.className = 'btn btn-info btn-sm';
                        statsBtn.textContent = 'Stats';
                        statsBtn.addEventListener('click', function(e) { e.stopPropagation(); self.showStatsModal(row); });

                        var nickBtn = document.createElement('button');
                        nickBtn.className = 'btn btn-primary btn-sm';
                        nickBtn.textContent = 'Nick';
                        nickBtn.addEventListener('click', function(e) { e.stopPropagation(); self.showNicknameModal(row); });

                        var editBtn = document.createElement('button');
                        editBtn.className = 'btn btn-secondary btn-sm';
                        editBtn.textContent = 'Edit';
                        editBtn.addEventListener('click', function(e) { e.stopPropagation(); self.showPlayerForm(row); });

                        var delBtn = document.createElement('button');
                        delBtn.className = 'btn btn-danger btn-sm';
                        delBtn.textContent = 'Del';
                        delBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            if (confirm('Delete ' + row.first_name + ' ' + row.last_name + '?')) self.deletePlayer(row.player_id);
                        });

                        wrap.appendChild(statsBtn);
                        wrap.appendChild(nickBtn);
                        wrap.appendChild(editBtn);
                        wrap.appendChild(delBtn);
                        return wrap;
                    }
                }
            ],
            data: paged,
            total: filtered.length,
            page: self._playerPage,
            perPage: perPage,
            sortKey: self._playerSort.key,
            sortDir: self._playerSort.dir,
            onSort: function(key) {
                if (self._playerSort.key === key) {
                    self._playerSort.dir = self._playerSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    self._playerSort.key = key; self._playerSort.dir = 'asc';
                }
                self._renderPlayersTable();
            },
            onPage: function(page) {
                self._playerPage = page;
                self._renderPlayersTable();
            }
        });
    },

    _formatPlayerStatsSummary: function(row) {
        var stats = row.current_season_stats || row.overall_stats;
        if (!stats) return '<span class="text-muted">-</span>';
        if (stats.type === 'pitching') {
            return '<span class="parser-stat">' + stats.w + '-' + stats.l + ' | ' + stats.era + ' ERA | ' + stats.k + ' K</span>';
        }
        return '<span class="parser-stat">' + stats.avg + ' | ' + stats.hr + ' HR | ' + stats.rbi + ' RBI</span>';
    },

    _formatPlayerTrend: function(row) {
        var current = row.current_season_stats;
        var previous = row.previous_season_stats;

        // Need both current and previous snapshots to show trend
        if (!current || !previous) return '<span class="text-muted">-</span>';
        if (current.type !== previous.type) return '<span class="text-muted">-</span>';

        var diff = 0;
        if (current.type === 'pitching') {
            // For pitchers: lower ERA = better = up arrow
            var curEra = parseFloat(current.era) || 0;
            var prevEra = parseFloat(previous.era) || 0;
            diff = prevEra - curEra; // positive means improvement
        } else {
            // For hitters: higher OPS = better; fall back to AVG
            var curVal = parseFloat(current.ops) || parseFloat(current.avg) || 0;
            var prevVal = parseFloat(previous.ops) || parseFloat(previous.avg) || 0;
            diff = curVal - prevVal; // positive means improvement
        }

        if (diff > 0) {
            return '<span class="trend-up" title="Trending up">&#9650;</span>';
        } else if (diff < 0) {
            return '<span class="trend-down" title="Trending down">&#9660;</span>';
        }
        return '<span class="text-muted">&#8212;</span>';
    },

    showStatsModal: function(player) {
        var self = this;
        var p = [];
        p.push('<div class="modal-header">');
        p.push('<h2>Stats: ' + self.escHtml(player.first_name) + ' ' + self.escHtml(player.last_name) + '</h2>');
        p.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        p.push('</div>');
        p.push('<div class="modal-body">');

        if (player.team_abbreviation) {
            p.push('<p><strong>Team:</strong> ' + self.escHtml(player.team_name) + ' (' + self.escHtml(player.team_abbreviation) + ')</p>');
        }
        if (player.primary_position) {
            p.push('<p><strong>Position:</strong> ' + self.escHtml(player.primary_position) + '</p>');
        }

        var sections = [
            { label: 'Current Season', data: player.current_season_stats },
            { label: 'Last Season', data: player.last_season_stats },
            { label: 'Career', data: player.overall_stats }
        ];

        for (var i = 0; i < sections.length; i++) {
            var sec = sections[i];
            p.push('<h3 style="margin-top:16px;font-size:14px;color:#555;">' + sec.label + '</h3>');
            if (!sec.data) {
                p.push('<p class="text-muted">No data available</p>');
                continue;
            }
            if (sec.data.type === 'pitching') {
                p.push('<table class="data-table" style="font-size:13px;"><thead><tr>');
                p.push('<th>W</th><th>L</th><th>ERA</th><th>K</th><th>WHIP</th><th>IP</th><th>SV</th><th>G</th>');
                p.push('</tr></thead><tbody><tr>');
                p.push('<td>' + sec.data.w + '</td><td>' + sec.data.l + '</td><td>' + sec.data.era + '</td>');
                p.push('<td>' + sec.data.k + '</td><td>' + sec.data.whip + '</td><td>' + sec.data.ip + '</td>');
                p.push('<td>' + sec.data.sv + '</td><td>' + sec.data.g + '</td>');
                p.push('</tr></tbody></table>');
            } else {
                p.push('<table class="data-table" style="font-size:13px;"><thead><tr>');
                p.push('<th>AVG</th><th>HR</th><th>RBI</th><th>OPS</th><th>H</th><th>AB</th><th>SB</th><th>G</th>');
                p.push('</tr></thead><tbody><tr>');
                p.push('<td>' + sec.data.avg + '</td><td>' + sec.data.hr + '</td><td>' + sec.data.rbi + '</td>');
                p.push('<td>' + sec.data.ops + '</td><td>' + sec.data.h + '</td><td>' + sec.data.ab + '</td>');
                p.push('<td>' + sec.data.sb + '</td><td>' + sec.data.g + '</td>');
                p.push('</tr></tbody></table>');
            }
        }

        if (player.stats_last_updated) {
            p.push('<p class="text-muted" style="margin-top:12px;font-size:12px;">Last updated: ' + App.formatDatetime(player.stats_last_updated) + '</p>');
        }

        p.push('</div>');
        p.push('<div class="modal-footer">');
        p.push('<button class="btn btn-secondary" onclick="App.closeModal()">Close</button>');
        p.push('</div>');

        App.openModal(p.join('\n'));
    },

    showPlayerForm: function(existing) {
        var self = this;
        var isEdit = !!existing;
        var positions = ['C', '1B', '2B', '3B', 'SS', 'LF', 'CF', 'RF', 'OF', 'DH', 'SP', 'RP', 'P', 'IF', 'UT'];
        var levels = ['', 'AAA', 'AA', 'A+', 'A', 'Rookie', 'DSL'];

        var p = [];
        p.push('<div class="modal-header">');
        p.push('<h2>' + (isEdit ? 'Edit Player' : 'Add Player') + '</h2>');
        p.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        p.push('</div>');
        p.push('<div class="modal-body">');

        p.push('<div class="form-row">');
        p.push('<div class="form-group"><label>First Name *</label>');
        p.push('<input type="text" id="player-first-name" value="' + (existing ? self.escHtml(existing.first_name) : '') + '">');
        p.push('</div>');
        p.push('<div class="form-group"><label>Last Name *</label>');
        p.push('<input type="text" id="player-last-name" value="' + (existing ? self.escHtml(existing.last_name) : '') + '">');
        p.push('</div>');
        p.push('</div>');

        p.push('<div class="form-row">');
        p.push('<div class="form-group"><label>Position</label><select id="player-position">');
        p.push('<option value="">-- Select --</option>');
        for (var i = 0; i < positions.length; i++) {
            var sel = existing && existing.primary_position === positions[i] ? ' selected' : '';
            p.push('<option value="' + positions[i] + '"' + sel + '>' + positions[i] + '</option>');
        }
        p.push('</select></div>');

        // Bats
        var bVal = existing ? (existing.bats || '') : '';
        p.push('<div class="form-group"><label>Bats</label><select id="player-bats">');
        p.push('<option value=""' + (!bVal ? ' selected' : '') + '>—</option>');
        p.push('<option value="R"' + (bVal === 'R' ? ' selected' : '') + '>R</option>');
        p.push('<option value="L"' + (bVal === 'L' ? ' selected' : '') + '>L</option>');
        p.push('<option value="S"' + (bVal === 'S' ? ' selected' : '') + '>S (Switch)</option>');
        p.push('</select></div>');

        // Throws
        var tVal = existing ? (existing.throws_hand || '') : '';
        p.push('<div class="form-group"><label>Throws</label><select id="player-throws">');
        p.push('<option value=""' + (!tVal ? ' selected' : '') + '>—</option>');
        p.push('<option value="R"' + (tVal === 'R' ? ' selected' : '') + '>R</option>');
        p.push('<option value="L"' + (tVal === 'L' ? ' selected' : '') + '>L</option>');
        p.push('</select></div>');
        p.push('</div>');

        p.push('<div class="form-row">');
        // Team dropdown
        p.push('<div class="form-group"><label>Current Team</label><select id="player-team">');
        p.push('<option value="">-- None --</option>');
        if (self._teamsListCache) {
            for (var t = 0; t < self._teamsListCache.length; t++) {
                var tm = self._teamsListCache[t];
                var tSel = existing && existing.current_team_id == tm.team_id ? ' selected' : '';
                p.push('<option value="' + tm.team_id + '"' + tSel + '>'
                    + (tm.abbreviation || '') + ' - ' + self.escHtml(tm.team_name) + '</option>');
            }
        }
        p.push('</select></div>');
        p.push('</div>');

        p.push('<div class="form-row">');
        // MiLB Level
        p.push('<div class="form-group"><label>MiLB Level</label><select id="player-milb-level">');
        p.push('<option value="">-- MLB / None --</option>');
        for (var lv = 0; lv < levels.length; lv++) {
            if (!levels[lv]) continue;
            var lvSel = existing && existing.minor_league_level === levels[lv] ? ' selected' : '';
            p.push('<option value="' + levels[lv] + '"' + lvSel + '>' + levels[lv] + '</option>');
        }
        p.push('</select></div>');

        // Prospect Rank
        p.push('<div class="form-group"><label>Prospect Rank</label>');
        p.push('<input type="number" id="player-rank" min="1" max="200" value="'
            + (existing && existing.prospect_rank ? existing.prospect_rank : '') + '" placeholder="Top 100">');
        p.push('</div>');
        p.push('</div>');

        // Draft info
        p.push('<div class="form-row">');
        p.push('<div class="form-group"><label>Draft Year</label>');
        p.push('<input type="number" id="player-draft-year" min="1900" max="2030" value="'
            + (existing && existing.draft_year ? existing.draft_year : '') + '" placeholder="e.g. 2018">');
        p.push('</div>');
        p.push('<div class="form-group"><label>Draft Round</label>');
        p.push('<input type="text" id="player-draft-round" maxlength="5" value="'
            + (existing && existing.draft_round ? self.escHtml(existing.draft_round) : '') + '" placeholder="e.g. 1">');
        p.push('</div>');
        p.push('<div class="form-group"><label>Draft Pick #</label>');
        p.push('<input type="number" id="player-draft-pick" min="1" value="'
            + (existing && existing.draft_pick ? existing.draft_pick : '') + '" placeholder="Overall pick">');
        p.push('</div>');
        p.push('<div class="form-group"><label>Draft Status</label><select id="player-draft-status">');
        var ds = existing ? (existing.draft_status || '') : '';
        p.push('<option value=""' + (!ds ? ' selected' : '') + '>—</option>');
        p.push('<option value="Drafted"' + (ds === 'Drafted' ? ' selected' : '') + '>Drafted</option>');
        p.push('<option value="Intl FA"' + (ds === 'Intl FA' ? ' selected' : '') + '>Intl FA</option>');
        p.push('<option value="Undrafted"' + (ds === 'Undrafted' ? ' selected' : '') + '>Undrafted</option>');
        p.push('<option value="Pre-Draft"' + (ds === 'Pre-Draft' ? ' selected' : '') + '>Pre-Draft</option>');
        p.push('</select></div>');
        p.push('</div>');

        p.push('<div class="form-row">');
        // Popularity Score
        p.push('<div class="form-group"><label>Popularity Score</label>');
        p.push('<input type="number" id="player-popularity" min="1" value="'
            + (existing && existing.popularity_score ? existing.popularity_score : '') + '" placeholder="1 = most popular">');
        p.push('</div>');

        if (isEdit) {
            p.push('<div class="form-group"><label>Status</label><select id="player-active">');
            p.push('<option value="1"' + (existing.is_active == 1 ? ' selected' : '') + '>Active</option>');
            p.push('<option value="2"' + (existing.is_active == 2 ? ' selected' : '') + '>Retired</option>');
            p.push('<option value="0"' + (existing.is_active == 0 ? ' selected' : '') + '>Inactive</option>');
            p.push('</select></div>');
        }
        p.push('</div>');

        p.push('</div>');
        p.push('<div class="modal-footer">');
        p.push('<button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>');
        p.push('<button class="btn btn-primary" id="player-save-btn">' + (isEdit ? 'Save Changes' : 'Create Player') + '</button>');
        p.push('</div>');

        App.openModal(p.join('\n'));

        document.getElementById('player-save-btn').addEventListener('click', function() {
            var data = {
                first_name: document.getElementById('player-first-name').value.trim(),
                last_name: document.getElementById('player-last-name').value.trim(),
                primary_position: document.getElementById('player-position').value,
                bats: document.getElementById('player-bats').value || null,
                throws_hand: document.getElementById('player-throws').value || null,
                current_team_id: document.getElementById('player-team').value || null,
                minor_league_level: document.getElementById('player-milb-level').value || null,
                prospect_rank: document.getElementById('player-rank').value || null,
                popularity_score: document.getElementById('player-popularity').value || null,
                draft_year: document.getElementById('player-draft-year').value || null,
                draft_round: document.getElementById('player-draft-round').value || null,
                draft_pick: document.getElementById('player-draft-pick').value || null,
                draft_status: document.getElementById('player-draft-status').value || null
            };
            if (!data.first_name || !data.last_name) { App.toast('First and last name are required', 'error'); return; }

            var activeEl = document.getElementById('player-active');
            if (activeEl) data.is_active = parseInt(activeEl.value);

            var promise = isEdit
                ? API.put('/api/parser/players/' + existing.player_id, data)
                : API.post('/api/parser/players', data);

            promise.then(function() {
                App.toast(isEdit ? 'Player updated' : 'Player created', 'success');
                App.closeModal();
                self.loadPlayers();
            }).catch(function(err) { App.toast(err.message, 'error'); });
        });
    },

    deletePlayer: function(playerId) {
        var self = this;
        API.del('/api/parser/players/' + playerId).then(function() {
            App.toast('Player deleted', 'success');
            self.loadPlayers();
        }).catch(function(err) { App.toast(err.message, 'error'); });
    },

    showNicknameModal: function(player) {
        var self = this;
        var p = [];
        p.push('<div class="modal-header">');
        p.push('<h2>Nicknames: ' + self.escHtml(player.first_name) + ' ' + self.escHtml(player.last_name) + '</h2>');
        p.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        p.push('</div>');
        p.push('<div class="modal-body">');

        if (player.nicknames && player.nicknames.length > 0) {
            p.push('<div class="parser-child-list">');
            for (var j = 0; j < player.nicknames.length; j++) {
                var nn = player.nicknames[j];
                p.push('<div class="parser-child-row">');
                p.push('<span class="parser-tag">' + self.escHtml(nn.nickname) + '</span>');
                p.push('<button class="btn btn-danger btn-sm btn-del-nn" data-nid="' + nn.nickname_id + '">Remove</button>');
                p.push('</div>');
            }
            p.push('</div>');
        } else {
            p.push('<p class="text-muted">No nicknames yet.</p>');
        }

        p.push('<div class="form-group" style="margin-top:16px;"><label>Add Nickname</label>');
        p.push('<div style="display:flex;gap:8px;">');
        p.push('<input type="text" id="new-nickname" placeholder="e.g., The Kid" style="flex:1;">');
        p.push('<button class="btn btn-success" id="btn-save-nn">Add</button>');
        p.push('</div></div>');

        p.push('</div>');
        p.push('<div class="modal-footer">');
        p.push('<button class="btn btn-secondary" onclick="App.closeModal()">Close</button>');
        p.push('</div>');

        App.openModal(p.join('\n'));

        document.getElementById('btn-save-nn').addEventListener('click', function() {
            var nickname = document.getElementById('new-nickname').value.trim();
            if (!nickname) { App.toast('Enter a nickname', 'error'); return; }
            API.post('/api/parser/players/' + player.player_id + '/nicknames', { nickname: nickname })
                .then(function() {
                    App.toast('Nickname added', 'success');
                    API.get('/api/parser/players').then(function(result) {
                        self._playersData = result.data || [];
                        var updated = self._playersData.find(function(p) { return p.player_id == player.player_id; });
                        if (updated) self.showNicknameModal(updated);
                        self._renderPlayersTable();
                    });
                }).catch(function(err) { App.toast(err.message, 'error'); });
        });

        var delBtns = document.querySelectorAll('.btn-del-nn');
        for (var k = 0; k < delBtns.length; k++) {
            (function(btn) {
                btn.addEventListener('click', function() {
                    API.del('/api/parser/nicknames/' + btn.getAttribute('data-nid')).then(function() {
                        App.toast('Nickname removed', 'success');
                        API.get('/api/parser/players').then(function(result) {
                            self._playersData = result.data || [];
                            var updated = self._playersData.find(function(p) { return p.player_id == player.player_id; });
                            if (updated) self.showNicknameModal(updated);
                            self._renderPlayersTable();
                        });
                    }).catch(function(err) { App.toast(err.message, 'error'); });
                });
            })(delBtns[k]);
        }
    },

    // ─── Teams ────────────────────────────────────────────────────

    loadTeams: function() {
        var self = this;
        API.get('/api/parser/teams').then(function(result) {
            var data = result.data || [];
            // Compute full_division for sorting
            for (var i = 0; i < data.length; i++) {
                data[i].full_division = (data[i].league && data[i].division)
                    ? data[i].league + ' ' + data[i].division : '';
            }
            self._teamsData = data;
            self._teamPage = 1;
            self._renderTeamsShell();
            self._renderTeamsTable();
        }).catch(function() {
            document.getElementById('parser-panel-teams').innerHTML =
                '<p class="text-muted">Unable to load teams.</p>';
        });
    },

    _getTeamDivisionOptions: function() {
        var divs = {};
        for (var i = 0; i < this._teamsData.length; i++) {
            var d = this._teamsData[i].full_division;
            if (d) divs[d] = true;
        }
        return Object.keys(divs).sort();
    },

    _renderTeamsShell: function() {
        var self = this;
        var container = document.getElementById('parser-panel-teams');
        var filters = [
            { id: 'parser-team-div-filter', placeholder: 'All Divisions', options: self._getTeamDivisionOptions(), value: self._teamFilters.division }
        ];
        container.innerHTML = self._buildSearchBar('btn-add-team', 'Add Team', 'parser-team-search', 'Search teams...', filters)
            + '<div id="parser-teams-table"></div>';

        document.getElementById('btn-add-team').addEventListener('click', function() { self.showTeamForm(); });

        var searchEl = document.getElementById('parser-team-search');
        searchEl.value = self._teamSearch;
        searchEl.addEventListener('input', function() {
            clearTimeout(self._debounceTimer);
            self._debounceTimer = setTimeout(function() {
                self._teamSearch = searchEl.value.trim();
                self._teamPage = 1;
                self._renderTeamsTable();
            }, 200);
        });

        var divFilter = document.getElementById('parser-team-div-filter');
        if (divFilter) {
            divFilter.addEventListener('change', function() {
                self._teamFilters.division = divFilter.value;
                self._teamPage = 1;
                self._renderTeamsTable();
            });
        }
    },

    _applyTeamFilters: function(data) {
        var f = this._teamFilters;
        if (!f.division) return data;
        return data.filter(function(row) {
            return row.full_division === f.division;
        });
    },

    _renderTeamsTable: function() {
        var self = this;
        var filtered = self._applyTeamFilters(self._teamsData);
        filtered = self._filterData(filtered, self._teamSearch,
            ['team_name', 'city', 'abbreviation', 'full_division', 'aliases']);
        var sorted = self._sortData(filtered, self._teamSort.key, self._teamSort.dir);
        var perPage = 50;
        var paged = self._pageData(sorted, self._teamPage, perPage);

        DataTable.render(document.getElementById('parser-teams-table'), {
            columns: [
                {
                    key: 'abbreviation', label: 'Abbr',
                    render: function(row) {
                        var logo = row.mlb_id
                            ? '<img class="parser-team-logo" src="/img/teams/' + row.mlb_id + '.png" alt="">'
                            : '';
                        return logo + self.escHtml(row.abbreviation || '-');
                    }
                },
                { key: 'team_name', label: 'Team Name' },
                { key: 'city', label: 'City' },
                {
                    key: 'full_division', label: 'Division',
                    format: function(val) { return val || '-'; }
                },
                {
                    key: 'record', label: 'Record', sortable: false,
                    render: function(row) { return self._formatTeamRecord(row.current_season_stats); }
                },
                {
                    key: 'last_record', label: 'Last Season', sortable: false,
                    render: function(row) { return self._formatTeamRecord(row.last_season_stats); }
                },
                {
                    key: 'aliases', label: 'Aliases', sortable: false,
                    render: function(row) {
                        if (!row.aliases || row.aliases.length === 0) return '<span class="text-muted">-</span>';
                        var tags = [];
                        for (var i = 0; i < row.aliases.length; i++) {
                            tags.push('<span class="parser-tag">' + self.escHtml(row.aliases[i].alias_name) + '</span>');
                        }
                        return tags.join(' ');
                    }
                },
                {
                    key: 'is_active', label: 'Active',
                    render: function(row) {
                        return row.is_active == 1
                            ? '<span class="status-badge status-completed">Active</span>'
                            : '<span class="status-badge status-cancelled">Inactive</span>';
                    }
                },
                {
                    key: 'actions', label: '', sortable: false,
                    render: function(row) {
                        var wrap = document.createElement('span');
                        wrap.style.display = 'flex'; wrap.style.gap = '4px';

                        var aliasBtn = document.createElement('button');
                        aliasBtn.className = 'btn btn-primary btn-sm'; aliasBtn.textContent = 'Aliases';
                        aliasBtn.addEventListener('click', function(e) { e.stopPropagation(); self.showAliasModal(row); });

                        var editBtn = document.createElement('button');
                        editBtn.className = 'btn btn-secondary btn-sm'; editBtn.textContent = 'Edit';
                        editBtn.addEventListener('click', function(e) { e.stopPropagation(); self.showTeamForm(row); });

                        var delBtn = document.createElement('button');
                        delBtn.className = 'btn btn-danger btn-sm'; delBtn.textContent = 'Del';
                        delBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            if (confirm('Delete ' + row.team_name + '?')) self.deleteTeam(row.team_id);
                        });

                        wrap.appendChild(aliasBtn); wrap.appendChild(editBtn); wrap.appendChild(delBtn);
                        return wrap;
                    }
                }
            ],
            data: paged,
            total: filtered.length,
            page: self._teamPage,
            perPage: perPage,
            sortKey: self._teamSort.key,
            sortDir: self._teamSort.dir,
            onSort: function(key) {
                if (self._teamSort.key === key) {
                    self._teamSort.dir = self._teamSort.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    self._teamSort.key = key; self._teamSort.dir = 'asc';
                }
                self._renderTeamsTable();
            },
            onPage: function(page) { self._teamPage = page; self._renderTeamsTable(); }
        });
    },

    _formatTeamRecord: function(stats) {
        if (!stats) return '<span class="text-muted">-</span>';
        var record = stats.wins + '-' + stats.losses;
        var pct = stats.winning_percentage || '-';
        var gb = stats.games_back === '-' ? '-' : stats.games_back + ' GB';
        return '<span class="parser-stat">' + record + ' (' + pct + ') | ' + gb
            + (stats.streak ? ' | ' + stats.streak : '') + '</span>';
    },

    showTeamForm: function(existing) {
        var self = this;
        var isEdit = !!existing;

        var p = [];
        p.push('<div class="modal-header">');
        p.push('<h2>' + (isEdit ? 'Edit Team' : 'Add Team') + '</h2>');
        p.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        p.push('</div>');
        p.push('<div class="modal-body">');

        p.push('<div class="form-row">');
        p.push('<div class="form-group"><label>Team Name *</label>');
        p.push('<input type="text" id="team-name" value="' + (existing ? self.escHtml(existing.team_name) : '') + '">');
        p.push('</div>');
        p.push('<div class="form-group"><label>City</label>');
        p.push('<input type="text" id="team-city" value="' + (existing ? self.escHtml(existing.city || '') : '') + '">');
        p.push('</div>');
        p.push('</div>');

        if (isEdit) {
            p.push('<div class="form-group"><label>Active</label><select id="team-active">');
            p.push('<option value="1"' + (existing.is_active == 1 ? ' selected' : '') + '>Active</option>');
            p.push('<option value="0"' + (existing.is_active == 0 ? ' selected' : '') + '>Inactive</option>');
            p.push('</select></div>');
        }

        p.push('</div>');
        p.push('<div class="modal-footer">');
        p.push('<button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>');
        p.push('<button class="btn btn-primary" id="team-save-btn">' + (isEdit ? 'Save Changes' : 'Create Team') + '</button>');
        p.push('</div>');

        App.openModal(p.join('\n'));

        document.getElementById('team-save-btn').addEventListener('click', function() {
            var data = {
                team_name: document.getElementById('team-name').value.trim(),
                city: document.getElementById('team-city').value.trim()
            };
            if (!data.team_name) { App.toast('Team name is required', 'error'); return; }

            var activeEl = document.getElementById('team-active');
            if (activeEl) data.is_active = parseInt(activeEl.value);

            var promise = isEdit
                ? API.put('/api/parser/teams/' + existing.team_id, data)
                : API.post('/api/parser/teams', data);

            promise.then(function() {
                App.toast(isEdit ? 'Team updated' : 'Team created', 'success');
                App.closeModal();
                self.loadTeams();
            }).catch(function(err) { App.toast(err.message, 'error'); });
        });
    },

    deleteTeam: function(teamId) {
        var self = this;
        API.del('/api/parser/teams/' + teamId).then(function() {
            App.toast('Team deleted', 'success');
            self.loadTeams();
        }).catch(function(err) { App.toast(err.message, 'error'); });
    },

    showAliasModal: function(team) {
        var self = this;
        var p = [];
        p.push('<div class="modal-header">');
        p.push('<h2>Aliases: ' + self.escHtml(team.team_name) + '</h2>');
        p.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        p.push('</div>');
        p.push('<div class="modal-body">');

        if (team.aliases && team.aliases.length > 0) {
            p.push('<div class="parser-child-list">');
            for (var j = 0; j < team.aliases.length; j++) {
                var al = team.aliases[j];
                p.push('<div class="parser-child-row">');
                p.push('<span class="parser-tag">' + self.escHtml(al.alias_name) + '</span>');
                p.push('<button class="btn btn-danger btn-sm btn-del-alias" data-aid="' + al.alias_id + '">Remove</button>');
                p.push('</div>');
            }
            p.push('</div>');
        } else {
            p.push('<p class="text-muted">No aliases yet.</p>');
        }

        p.push('<div class="form-group" style="margin-top:16px;"><label>Add Alias</label>');
        p.push('<div style="display:flex;gap:8px;">');
        p.push('<input type="text" id="new-alias" placeholder="e.g., Yankees, Bombers" style="flex:1;">');
        p.push('<button class="btn btn-success" id="btn-save-alias">Add</button>');
        p.push('</div></div>');

        p.push('</div>');
        p.push('<div class="modal-footer">');
        p.push('<button class="btn btn-secondary" onclick="App.closeModal()">Close</button>');
        p.push('</div>');

        App.openModal(p.join('\n'));

        document.getElementById('btn-save-alias').addEventListener('click', function() {
            var aliasName = document.getElementById('new-alias').value.trim();
            if (!aliasName) { App.toast('Enter an alias', 'error'); return; }
            API.post('/api/parser/teams/' + team.team_id + '/aliases', { alias_name: aliasName })
                .then(function() {
                    App.toast('Alias added', 'success');
                    API.get('/api/parser/teams').then(function(result) {
                        self._teamsData = result.data || [];
                        var updated = self._teamsData.find(function(t) { return t.team_id == team.team_id; });
                        if (updated) self.showAliasModal(updated);
                        self._renderTeamsTable();
                    });
                }).catch(function(err) { App.toast(err.message, 'error'); });
        });

        var delBtns = document.querySelectorAll('.btn-del-alias');
        for (var k = 0; k < delBtns.length; k++) {
            (function(btn) {
                btn.addEventListener('click', function() {
                    API.del('/api/parser/aliases/' + btn.getAttribute('data-aid')).then(function() {
                        App.toast('Alias removed', 'success');
                        API.get('/api/parser/teams').then(function(result) {
                            self._teamsData = result.data || [];
                            var updated = self._teamsData.find(function(t) { return t.team_id == team.team_id; });
                            if (updated) self.showAliasModal(updated);
                            self._renderTeamsTable();
                        });
                    }).catch(function(err) { App.toast(err.message, 'error'); });
                });
            })(delBtns[k]);
        }
    },

    // ─── Card Makers ──────────────────────────────────────────────

    loadMakers: function() {
        var self = this;
        API.get('/api/parser/makers').then(function(result) {
            self._makersData = result.data || [];
            self._makerPage = 1;
            self._renderSimpleShell('makers', 'btn-add-maker', 'Add Maker', 'parser-maker-search', 'Search makers...');
            self._renderSimpleTable('makers');
        }).catch(function() {
            document.getElementById('parser-panel-makers').innerHTML = '<p class="text-muted">Unable to load makers.</p>';
        });
    },

    loadStyles: function() {
        var self = this;
        API.get('/api/parser/styles').then(function(result) {
            self._stylesData = result.data || [];
            self._stylePage = 1;
            self._renderSimpleShell('styles', 'btn-add-style', 'Add Style', 'parser-style-search', 'Search styles...');
            self._renderSimpleTable('styles');
        }).catch(function() {
            document.getElementById('parser-panel-styles').innerHTML = '<p class="text-muted">Unable to load styles.</p>';
        });
    },

    loadSpecialties: function() {
        var self = this;
        API.get('/api/parser/specialties').then(function(result) {
            self._specialtiesData = result.data || [];
            self._specPage = 1;
            self._renderSimpleShell('specialties', 'btn-add-specialty', 'Add Specialty', 'parser-spec-search', 'Search specialties...');
            self._renderSimpleTable('specialties');
        }).catch(function() {
            document.getElementById('parser-panel-specialties').innerHTML = '<p class="text-muted">Unable to load specialties.</p>';
        });
    },

    _simpleConfig: {
        makers:      { api: '/api/parser/makers',      nameField: 'name',       label: 'Maker',     idField: 'maker_id',     dataKey: '_makersData',      searchKey: '_makerSearch', sortKey: '_makerSort', pageKey: '_makerPage' },
        styles:      { api: '/api/parser/styles',       nameField: 'style_name', label: 'Style',     idField: 'style_id',     dataKey: '_stylesData',      searchKey: '_styleSearch', sortKey: '_styleSort', pageKey: '_stylePage' },
        specialties: { api: '/api/parser/specialties',  nameField: 'name',       label: 'Specialty', idField: 'specialty_id', dataKey: '_specialtiesData', searchKey: '_specSearch',  sortKey: '_specSort',  pageKey: '_specPage' }
    },

    _renderSimpleShell: function(type, addBtnId, addBtnLabel, searchId, placeholder) {
        var self = this;
        var cfg = self._simpleConfig[type];
        var container = document.getElementById('parser-panel-' + type);
        container.innerHTML = self._buildSearchBar(addBtnId, addBtnLabel, searchId, placeholder)
            + '<div id="parser-' + type + '-table"></div>';

        document.getElementById(addBtnId).addEventListener('click', function() { self.showSimpleForm(type, null); });
        var searchEl = document.getElementById(searchId);
        searchEl.value = self[cfg.searchKey];
        searchEl.addEventListener('input', function() {
            clearTimeout(self._debounceTimer);
            self._debounceTimer = setTimeout(function() {
                self[cfg.searchKey] = searchEl.value.trim();
                self[cfg.pageKey] = 1;
                self._renderSimpleTable(type);
            }, 200);
        });
    },

    _renderSimpleTable: function(type) {
        var self = this;
        var cfg = self._simpleConfig[type];
        var data = self[cfg.dataKey];
        var search = self[cfg.searchKey];
        var sort = self[cfg.sortKey];
        var page = self[cfg.pageKey];
        var perPage = 50;

        var filtered = self._filterData(data, search, [cfg.nameField]);
        var sorted = self._sortData(filtered, sort.key, sort.dir);
        var paged = self._pageData(sorted, page, perPage);

        DataTable.render(document.getElementById('parser-' + type + '-table'), {
            columns: [
                { key: cfg.nameField, label: 'Name' },
                {
                    key: 'is_active', label: 'Active',
                    render: function(row) {
                        return row.is_active == 1
                            ? '<span class="status-badge status-completed">Active</span>'
                            : '<span class="status-badge status-cancelled">Inactive</span>';
                    }
                },
                {
                    key: 'actions', label: '', sortable: false,
                    render: function(row) { return self._simpleActionBtns(type, row); }
                }
            ],
            data: paged,
            total: filtered.length,
            page: page,
            perPage: perPage,
            sortKey: sort.key,
            sortDir: sort.dir,
            onSort: function(key) {
                if (sort.key === key) { sort.dir = sort.dir === 'asc' ? 'desc' : 'asc'; }
                else { sort.key = key; sort.dir = 'asc'; }
                self._renderSimpleTable(type);
            },
            onPage: function(pg) { self[cfg.pageKey] = pg; self._renderSimpleTable(type); }
        });
    },

    _simpleActionBtns: function(type, row) {
        var self = this;
        var cfg = self._simpleConfig[type];
        var wrap = document.createElement('span');
        wrap.style.display = 'flex'; wrap.style.gap = '4px';

        var editBtn = document.createElement('button');
        editBtn.className = 'btn btn-secondary btn-sm'; editBtn.textContent = 'Edit';
        editBtn.addEventListener('click', function(e) { e.stopPropagation(); self.showSimpleForm(type, row); });

        var delBtn = document.createElement('button');
        delBtn.className = 'btn btn-danger btn-sm'; delBtn.textContent = 'Del';
        delBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            var nameVal = row[cfg.nameField];
            if (confirm('Delete ' + nameVal + '?')) self._deleteSimple(type, row[cfg.idField]);
        });

        wrap.appendChild(editBtn); wrap.appendChild(delBtn);
        return wrap;
    },

    showSimpleForm: function(type, existing) {
        var self = this;
        var cfg = self._simpleConfig[type];
        var isEdit = !!existing;
        var currentValue = existing ? (existing[cfg.nameField] || '') : '';

        var p = [];
        p.push('<div class="modal-header">');
        p.push('<h2>' + (isEdit ? 'Edit ' : 'Add ') + cfg.label + '</h2>');
        p.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        p.push('</div>');
        p.push('<div class="modal-body">');
        p.push('<div class="form-group"><label>Name *</label>');
        p.push('<input type="text" id="simple-name" value="' + self.escHtml(currentValue) + '">');
        p.push('</div>');
        if (isEdit) {
            p.push('<div class="form-group"><label>Active</label><select id="simple-active">');
            p.push('<option value="1"' + (existing.is_active == 1 ? ' selected' : '') + '>Active</option>');
            p.push('<option value="0"' + (existing.is_active == 0 ? ' selected' : '') + '>Inactive</option>');
            p.push('</select></div>');
        }
        p.push('</div>');
        p.push('<div class="modal-footer">');
        p.push('<button class="btn btn-secondary" onclick="App.closeModal()">Cancel</button>');
        p.push('<button class="btn btn-primary" id="simple-save-btn">' + (isEdit ? 'Save Changes' : 'Create ' + cfg.label) + '</button>');
        p.push('</div>');

        App.openModal(p.join('\n'));

        document.getElementById('simple-save-btn').addEventListener('click', function() {
            var name = document.getElementById('simple-name').value.trim();
            if (!name) { App.toast('Name is required', 'error'); return; }

            var data = {};
            data[cfg.nameField] = name;
            var activeEl = document.getElementById('simple-active');
            if (activeEl) data.is_active = parseInt(activeEl.value);

            var promise = isEdit
                ? API.put(cfg.api + '/' + existing[cfg.idField], data)
                : API.post(cfg.api, data);

            promise.then(function() {
                App.toast(cfg.label + (isEdit ? ' updated' : ' created'), 'success');
                App.closeModal();
                self._reloadSimple(type);
            }).catch(function(err) { App.toast(err.message, 'error'); });
        });
    },

    _deleteSimple: function(type, id) {
        var self = this;
        var cfg = self._simpleConfig[type];
        API.del(cfg.api + '/' + id).then(function() {
            App.toast(cfg.label + ' deleted', 'success');
            self._reloadSimple(type);
        }).catch(function(err) { App.toast(err.message, 'error'); });
    },

    _reloadSimple: function(type) {
        if (type === 'makers') this.loadMakers();
        else if (type === 'styles') this.loadStyles();
        else if (type === 'specialties') this.loadSpecialties();
    },

    // ─── Utility ──────────────────────────────────────────────────

    escHtml: function(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
