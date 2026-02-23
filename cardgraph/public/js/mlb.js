/**
 * Card Graph — MLB Scores & Schedule
 *
 * Shows game schedules with scores, team logos, broadcasts, standings,
 * and auto-refreshes live games every 60 seconds.
 */
var Mlb = {
    initialized: false,
    liveTimer: null,
    currentDate: null,   // YYYY-MM-DD being viewed
    currentView: 'schedule',   // 'schedule' or 'team-profile'
    selectedTeamId: 145,        // Default: Chicago White Sox
    teamsList: null,            // Cached team list for dropdown

    init: function() {
        var panel = document.getElementById('tab-mlb');
        if (!panel) return;

        this.currentDate = this.todayStr();

        if (!this.initialized) {
            this.initialized = true;
            this.renderSkeleton(panel);
        }

        // Restore to the correct view
        if (this.currentView === 'team-profile') {
            this.switchView('team-profile');
        } else {
            this.loadSchedule(this.currentDate);
            this.loadStandings();
        }
    },

    todayStr: function() {
        var d = new Date();
        return d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
    },

    renderSkeleton: function(panel) {
        var html = [];
        html.push('<div class="page-header"><h1>MLB</h1></div>');

        // Sub-tabs
        html.push('<div class="sub-tabs" id="mlb-sub-tabs">');
        html.push('<button class="sub-tab active" data-subtab="schedule">Scores &amp; Schedule</button>');
        html.push('<button class="sub-tab" data-subtab="team-profile">Team Profile</button>');
        html.push('</div>');

        // Panel 1: Schedule (existing content)
        html.push('<div id="mlb-panel-schedule" class="sub-panel">');
        html.push('<div class="mlb-date-nav">');
        html.push('<button class="btn btn-secondary btn-sm" id="mlb-prev">&larr; Prev</button>');
        html.push('<span class="mlb-date-label" id="mlb-date-label"></span>');
        html.push('<button class="btn btn-secondary btn-sm" id="mlb-next">Next &rarr;</button>');
        html.push('<button class="btn btn-primary btn-sm" id="mlb-today" style="margin-left:12px;">Today</button>');
        html.push('</div>');
        html.push('<div id="mlb-schedule"></div>');
        html.push('<div class="mlb-standings-section">');
        html.push('<h2 class="mlb-section-title">Standings</h2>');
        html.push('<div id="mlb-standings" class="mlb-standings-container"></div>');
        html.push('</div>');
        html.push('</div>'); // end mlb-panel-schedule

        // Panel 2: Team Profile
        html.push('<div id="mlb-panel-team-profile" class="sub-panel" style="display:none;">');
        html.push('<div id="mlb-team-profile-content"></div>');
        html.push('</div>');

        panel.innerHTML = html.join('');

        // Wire up sub-tab navigation
        var self = this;
        var tabs = document.querySelectorAll('#mlb-sub-tabs .sub-tab');
        for (var i = 0; i < tabs.length; i++) {
            (function(btn) {
                btn.addEventListener('click', function() {
                    self.switchView(btn.dataset.subtab);
                });
            })(tabs[i]);
        }

        // Wire up date nav buttons
        document.getElementById('mlb-prev').addEventListener('click', function() { self.navDate(-1); });
        document.getElementById('mlb-next').addEventListener('click', function() { self.navDate(1); });
        document.getElementById('mlb-today').addEventListener('click', function() {
            self.currentDate = self.todayStr();
            self.loadSchedule(self.currentDate);
        });
    },

    navDate: function(delta) {
        var parts = this.currentDate.split('-');
        var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        d.setDate(d.getDate() + delta);
        this.currentDate = d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
        this.loadSchedule(this.currentDate);
    },

    formatDateLabel: function(dateStr) {
        var parts = dateStr.split('-');
        var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var months = ['January', 'February', 'March', 'April', 'May', 'June',
                      'July', 'August', 'September', 'October', 'November', 'December'];
        var isToday = dateStr === this.todayStr();
        var prefix = isToday ? 'Today — ' : '';
        return prefix + days[d.getDay()] + ', ' + months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    },

    // ─── Schedule Loading ──────────────────────────────────────

    loadSchedule: function(date) {
        var self = this;
        var label = document.getElementById('mlb-date-label');
        if (label) label.textContent = this.formatDateLabel(date);

        API.get('/api/mlb/schedule?date=' + date).then(function(data) {
            self.renderSchedule(data);
            self.checkForLiveGames(data);
        }).catch(function(err) {
            var el = document.getElementById('mlb-schedule');
            if (el) el.innerHTML = '<p class="text-muted" style="padding:16px;">Unable to load schedule: ' + (err.message || 'Unknown error') + '</p>';
        });
    },

    buildDayLabel: function(dateStr) {
        var days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        var months = ['January', 'February', 'March', 'April', 'May', 'June',
                      'July', 'August', 'September', 'October', 'November', 'December'];
        var parts = dateStr.split('-');
        var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        var dayName = days[d.getDay()];
        var datePart = months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();

        // Only show Today/Yesterday/Tomorrow relative to the real current date
        var today = this.todayStr();
        var todayParts = today.split('-');
        var todayDate = new Date(parseInt(todayParts[0]), parseInt(todayParts[1]) - 1, parseInt(todayParts[2]));
        var yesterdayDate = new Date(todayDate); yesterdayDate.setDate(yesterdayDate.getDate() - 1);
        var tomorrowDate = new Date(todayDate); tomorrowDate.setDate(tomorrowDate.getDate() + 1);

        var yStr = yesterdayDate.getFullYear() + '-' + String(yesterdayDate.getMonth()+1).padStart(2,'0') + '-' + String(yesterdayDate.getDate()).padStart(2,'0');
        var tStr = tomorrowDate.getFullYear() + '-' + String(tomorrowDate.getMonth()+1).padStart(2,'0') + '-' + String(tomorrowDate.getDate()).padStart(2,'0');

        var prefix = '';
        if (dateStr === today) prefix = 'Today — ';
        else if (dateStr === yStr) prefix = 'Yesterday — ';
        else if (dateStr === tStr) prefix = 'Tomorrow — ';

        return prefix + dayName + ', ' + datePart;
    },

    renderSchedule: function(data) {
        var container = document.getElementById('mlb-schedule');
        if (!container) return;

        var html = [];
        var dates = Object.keys(data).sort();

        for (var i = 0; i < dates.length; i++) {
            var dateKey = dates[i];
            var section = data[dateKey];
            if (!section || !section.games) continue;

            var sectionLabel = this.buildDayLabel(section.date || dateKey);
            var gameCount = section.games.length;

            html.push('<div class="mlb-day-section">');
            html.push('<h3 class="mlb-day-header">' + this.escHtml(sectionLabel));
            html.push('&nbsp;&nbsp;<span class="mlb-game-count">' + gameCount + ' game' + (gameCount !== 1 ? 's' : '') + '</span>');
            html.push('</h3>');

            if (gameCount === 0) {
                html.push('<p class="text-muted" style="padding:8px 0;">No games scheduled</p>');
            } else {
                html.push('<div class="mlb-games-grid">');
                for (var j = 0; j < section.games.length; j++) {
                    html.push(this.renderGameCard(section.games[j]));
                }
                html.push('</div>');
            }
            html.push('</div>');
        }

        container.innerHTML = html.join('');
    },

    renderGameCard: function(game) {
        var p = [];
        p.push('<div class="mlb-game-card' + (game.isLive ? ' mlb-game-live' : '') + '"'
            + ' data-gamepk="' + (game.gamePk || '') + '"'
            + ' style="cursor:pointer;" onclick="Mlb.showBoxScore(' + (game.gamePk || 0) + ')">');

        // Game type badge (Spring Training, Exhibition, etc.)
        if (game.gameType && game.gameType !== 'R') {
            p.push('<div class="mlb-game-type">' + this.escHtml(game.gameTypeLabel) + '</div>');
        }

        // Status badge
        p.push('<div class="mlb-game-status">');
        if (game.isLive) {
            p.push('<span class="mlb-live-dot"></span>');
            var inningText = '';
            if (game.inningState && game.inningOrdinal) {
                var stateAbbr = game.inningState === 'Top' ? 'Top' :
                                game.inningState === 'Bottom' ? 'Bot' :
                                game.inningState === 'Middle' ? 'Mid' :
                                game.inningState === 'End' ? 'End' : game.inningState;
                inningText = stateAbbr + ' ' + game.inningOrdinal;
            }
            p.push('<span class="mlb-status-text mlb-status-live">' + (inningText || 'In Progress') + '</span>');
            if (game.outs !== null && game.outs !== undefined && game.inningState !== 'Middle' && game.inningState !== 'End') {
                p.push('<span class="mlb-outs">' + game.outs + ' out' + (game.outs !== 1 ? 's' : '') + '</span>');
            }
        } else if (game.isFinal) {
            p.push('<span class="mlb-status-text mlb-status-final">' + this.escHtml(game.status) + '</span>');
        } else {
            p.push('<span class="mlb-status-text mlb-status-scheduled">' + this.escHtml(game.startTime || game.status) + '</span>');
        }
        p.push('</div>');

        // Away team row
        p.push(this.renderTeamRow(game.away, game.isFinal || game.isLive));

        // Home team row
        p.push(this.renderTeamRow(game.home, game.isFinal || game.isLive));

        // Footer: broadcasts + venue
        var footerParts = [];
        if (game.broadcasts && game.broadcasts.length > 0) {
            footerParts.push('TV: ' + game.broadcasts.join(', '));
        }
        if (game.venue) {
            footerParts.push(game.venue);
        }
        if (footerParts.length > 0) {
            p.push('<div class="mlb-game-footer">' + this.escHtml(footerParts.join(' | ')) + '</div>');
        }

        p.push('</div>');
        return p.join('');
    },

    renderTeamRow: function(team, showScore) {
        var p = [];
        var isWinner = team.isWinner;
        p.push('<div class="mlb-team-row' + (isWinner ? ' mlb-winner' : '') + '">');

        // Logo
        if (team.logoUrl) {
            p.push('<img class="mlb-team-logo" src="' + team.logoUrl + '" alt="" onerror="this.style.display=\'none\'">');
        } else {
            p.push('<span class="mlb-team-logo-placeholder"></span>');
        }

        // Team name + record
        p.push('<span class="mlb-team-name">' + this.escHtml(team.abbreviation || team.name) + '</span>');
        if (team.record) {
            p.push('<span class="mlb-team-record">(' + this.escHtml(team.record) + ')</span>');
        }

        // Score
        if (showScore && team.score !== null && team.score !== undefined) {
            p.push('<span class="mlb-team-score' + (isWinner ? ' mlb-score-winner' : '') + '">' + team.score + '</span>');
        }

        p.push('</div>');
        return p.join('');
    },

    // ─── Live Polling ──────────────────────────────────────────

    checkForLiveGames: function(data) {
        var hasLive = false;
        var dates = Object.keys(data);
        for (var i = 0; i < dates.length; i++) {
            var section = data[dates[i]];
            if (!section || !section.games) continue;
            for (var j = 0; j < section.games.length; j++) {
                if (section.games[j].isLive) {
                    hasLive = true;
                    break;
                }
            }
            if (hasLive) break;
        }

        if (hasLive && !this.liveTimer) {
            this.startLivePolling();
        } else if (!hasLive && this.liveTimer) {
            this.stopLivePolling();
        }
    },

    startLivePolling: function() {
        var self = this;
        this.liveTimer = setInterval(function() {
            if (document.getElementById('tab-mlb') &&
                document.getElementById('tab-mlb').classList.contains('active')) {
                self.loadSchedule(self.currentDate);
            }
        }, 60000);
    },

    stopLivePolling: function() {
        if (this.liveTimer) {
            clearInterval(this.liveTimer);
            this.liveTimer = null;
        }
    },

    // ─── Standings ─────────────────────────────────────────────

    loadStandings: function() {
        var self = this;
        API.get('/api/mlb/standings').then(function(data) {
            self.renderStandings(data.divisions || {});
        }).catch(function() {
            var el = document.getElementById('mlb-standings');
            if (el) el.innerHTML = '<p class="text-muted">Unable to load standings</p>';
        });
    },

    renderStandings: function(divisions) {
        var container = document.getElementById('mlb-standings');
        if (!container) return;

        // Group by league
        var leagues = { 'AL': {}, 'NL': {} };
        var divOrder = ['East', 'Central', 'West'];
        var keys = Object.keys(divisions);

        for (var i = 0; i < keys.length; i++) {
            var key = keys[i]; // e.g. "AL East"
            var parts = key.split(' ');
            var league = parts[0];
            var division = parts.slice(1).join(' ');
            if (leagues[league]) {
                leagues[league][division] = divisions[key];
            }
        }

        var html = [];
        var leagueNames = ['AL', 'NL'];
        var leagueLabels = { 'AL': 'American League', 'NL': 'National League' };
        var leagueIds = { 'AL': 103, 'NL': 104 };

        for (var li = 0; li < leagueNames.length; li++) {
            var lg = leagueNames[li];
            html.push('<div class="mlb-standings-league">');
            html.push('<h3 class="mlb-league-title">');
            html.push('<img class="mlb-standings-league-logo" src="/img/leagues/' + leagueIds[lg] + '.svg" alt="" onerror="this.style.display=\'none\'">');
            html.push(leagueLabels[lg] + '</h3>');

            for (var di = 0; di < divOrder.length; di++) {
                var div = divOrder[di];
                var teams = leagues[lg][div];
                if (!teams || teams.length === 0) continue;

                // Build division team icon strip
                var divIcons = '';
                for (var ti = 0; ti < teams.length; ti++) {
                    divIcons += '<img class="mlb-standings-div-icon" src="' + teams[ti].logoUrl + '" alt="" onerror="this.style.display=\'none\'">';
                }

                html.push('<table class="mlb-standings-table">');
                html.push('<thead>');
                html.push('<tr class="mlb-division-header"><th colspan="7">' + lg + ' ' + div + '&nbsp;&nbsp;' + divIcons + '</th></tr>');
                html.push('<tr>');
                html.push('<th class="mlb-st-team">Team</th>');
                html.push('<th class="mlb-st-num">W</th>');
                html.push('<th class="mlb-st-num">L</th>');
                html.push('<th class="mlb-st-num">PCT</th>');
                html.push('<th class="mlb-st-num">GB</th>');
                html.push('<th class="mlb-st-num">STRK</th>');
                html.push('<th class="mlb-st-num">DIFF</th>');
                html.push('</tr>');
                html.push('</thead><tbody>');

                for (var t = 0; t < teams.length; t++) {
                    var tm = teams[t];
                    var diffClass = tm.runDiff > 0 ? 'mlb-pos' : (tm.runDiff < 0 ? 'mlb-neg' : '');
                    var diffStr = tm.runDiff > 0 ? '+' + tm.runDiff : String(tm.runDiff);
                    html.push('<tr>');
                    html.push('<td class="mlb-st-team">');
                    html.push('<img class="mlb-st-logo" src="' + tm.logoUrl + '" alt="" onerror="this.style.display=\'none\'">');
                    html.push('<span>' + this.escHtml(tm.abbreviation || tm.team_name) + '</span>');
                    html.push('</td>');
                    html.push('<td class="mlb-st-num">' + tm.wins + '</td>');
                    html.push('<td class="mlb-st-num">' + tm.losses + '</td>');
                    html.push('<td class="mlb-st-num">' + tm.pct + '</td>');
                    html.push('<td class="mlb-st-num">' + tm.gb + '</td>');
                    html.push('<td class="mlb-st-num">' + this.escHtml(tm.streak) + '</td>');
                    html.push('<td class="mlb-st-num ' + diffClass + '">' + diffStr + '</td>');
                    html.push('</tr>');
                }

                html.push('</tbody></table>');
            }

            html.push('</div>');
        }

        container.innerHTML = html.join('');
    },

    // ─── Box Score ──────────────────────────────────────────────

    showBoxScore: function(gamePk) {
        if (!gamePk) return;
        App.openModal('<div style="padding:40px;text-align:center;color:#888;">Loading box score...</div>');

        API.get('/api/mlb/game/' + gamePk).then(function(data) {
            var html = Mlb.renderBoxScoreModal(data);
            App.openModal(html);
        }).catch(function(err) {
            App.openModal('<div style="padding:40px;text-align:center;color:#c62828;">Failed to load box score</div>');
        });
    },

    renderBoxScoreModal: function(d) {
        var h = [];
        var awayAbbr = this.escHtml(d.awayAbbr || 'AWAY');
        var homeAbbr = this.escHtml(d.homeAbbr || 'HOME');
        var awayName = this.escHtml(d.awayTeam || '');
        var homeName = this.escHtml(d.homeTeam || '');
        var isFinal = d.isFinal || (d.status && d.status.indexOf('Final') === 0);
        var awayLogo = d.awayMlbId ? '/img/teams/' + d.awayMlbId + '.png' : '';
        var homeLogo = d.homeMlbId ? '/img/teams/' + d.homeMlbId + '.png' : '';

        // Header
        h.push('<div class="modal-header">');
        h.push('<h2>' + awayName + ' vs ' + homeName + '</h2>');
        h.push('<button class="modal-close" onclick="App.closeModal()">&times;</button>');
        h.push('</div>');

        h.push('<div class="mlb-box-body">');

        // Status
        if (d.status) {
            var statusClass = '';
            if (d.status === 'In Progress') statusClass = ' mlb-status-live';
            else if (d.status.indexOf('Final') === 0) statusClass = ' mlb-status-final';
            h.push('<div class="mlb-box-status' + statusClass + '">' + this.escHtml(d.status));
            if (d.inningState && d.currentInning && !isFinal) {
                var st = d.inningState === 'Top' ? 'Top' : d.inningState === 'Bottom' ? 'Bot' :
                         d.inningState === 'Middle' ? 'Mid' : d.inningState === 'End' ? 'End' : d.inningState;
                h.push(' &mdash; ' + st + ' ' + d.currentInning);
                if (d.outs !== null && d.outs !== undefined && d.inningState !== 'Middle' && d.inningState !== 'End') {
                    h.push(', ' + d.outs + ' out' + (d.outs !== 1 ? 's' : ''));
                }
            }
            h.push('</div>');
        }

        // Linescore table
        h.push('<div class="mlb-box-linescore-wrap">');
        h.push('<table class="mlb-box-linescore">');
        h.push('<thead><tr>');
        h.push('<th class="mlb-box-team-col"></th>');
        for (var i = 0; i < d.innings.length; i++) {
            h.push('<th>' + d.innings[i].num + '</th>');
        }
        h.push('<th class="mlb-box-rhe">R</th>');
        h.push('<th class="mlb-box-rhe">H</th>');
        h.push('<th class="mlb-box-rhe">E</th>');
        h.push('</tr></thead>');

        // Away row
        h.push('<tbody><tr>');
        h.push('<td class="mlb-box-team-col">');
        if (awayLogo) h.push('<img class="mlb-box-team-logo" src="' + awayLogo + '" alt="" onerror="this.style.display=\'none\'">');
        h.push('<strong>' + awayAbbr + '</strong></td>');
        for (var i = 0; i < d.innings.length; i++) {
            var val = d.innings[i].away;
            var display = (val !== null && val !== undefined) ? val : (isFinal ? 'X' : '');
            h.push('<td>' + display + '</td>');
        }
        var at = d.awayTotal || {};
        h.push('<td class="mlb-box-rhe"><strong>' + (at.runs || 0) + '</strong></td>');
        h.push('<td class="mlb-box-rhe">' + (at.hits || 0) + '</td>');
        h.push('<td class="mlb-box-rhe">' + (at.errors || 0) + '</td>');
        h.push('</tr>');

        // Home row
        h.push('<tr>');
        h.push('<td class="mlb-box-team-col">');
        if (homeLogo) h.push('<img class="mlb-box-team-logo" src="' + homeLogo + '" alt="" onerror="this.style.display=\'none\'">');
        h.push('<strong>' + homeAbbr + '</strong></td>');
        for (var i = 0; i < d.innings.length; i++) {
            var val = d.innings[i].home;
            var display = (val !== null && val !== undefined) ? val : (isFinal ? 'X' : '');
            h.push('<td>' + display + '</td>');
        }
        var ht = d.homeTotal || {};
        h.push('<td class="mlb-box-rhe"><strong>' + (ht.runs || 0) + '</strong></td>');
        h.push('<td class="mlb-box-rhe">' + (ht.hits || 0) + '</td>');
        h.push('<td class="mlb-box-rhe">' + (ht.errors || 0) + '</td>');
        h.push('</tr></tbody></table>');
        h.push('</div>');

        // Current / Last at bat matchup (compact single-line)
        if (d.currentMatchup) {
            var m = d.currentMatchup;
            var matchupLabel = isFinal ? 'Last At Bat' : 'Current At Bat';
            h.push('<div class="mlb-box-matchup' + (isFinal ? ' mlb-box-matchup-final' : '') + '">');
            h.push('<div class="mlb-box-matchup-title">' + matchupLabel + '</div>');

            // Batter line: Batting  Name  Pos  stats
            h.push('<div class="mlb-box-matchup-line">');
            h.push('<span class="mlb-box-player-label">Batting</span> ');
            h.push('<span class="mlb-box-player-name">' + this.escHtml(m.batter.name) + '</span>');
            if (m.batter.position) h.push('&nbsp;&nbsp;<span class="mlb-box-player-pos">' + this.escHtml(m.batter.position) + '</span>');
            if (m.batter.stats) {
                var bs = m.batter.stats;
                h.push('&nbsp;&nbsp;<span class="mlb-box-player-stat">'
                    + bs.hits + '-' + bs.atBats
                    + (bs.rbi ? ', ' + bs.rbi + ' RBI' : '')
                    + (bs.walks ? ', ' + bs.walks + ' BB' : '')
                    + '</span>');
            }
            h.push('</div>');

            // Pitcher line: Pitching  Name  stats
            h.push('<div class="mlb-box-matchup-line">');
            h.push('<span class="mlb-box-player-label">Pitching</span> ');
            h.push('<span class="mlb-box-player-name">' + this.escHtml(m.pitcher.name) + '</span>');
            if (m.pitcher.stats) {
                var ps = m.pitcher.stats;
                h.push('&nbsp;&nbsp;<span class="mlb-box-player-stat">'
                    + ps.inningsPitched + ' IP, '
                    + ps.strikeOuts + ' K, '
                    + ps.earnedRuns + ' ER'
                    + '</span>');
            }
            h.push('</div>');

            h.push('</div>');
        }

        // Batters section
        h.push(this.renderBattersSection(d.awayBatters || [], awayAbbr, awayLogo));
        h.push(this.renderBattersSection(d.homeBatters || [], homeAbbr, homeLogo));

        // Pitchers section (collapsible)
        h.push(this.renderPitchersSection(d.awayPitchers || [], awayAbbr, awayLogo));
        h.push(this.renderPitchersSection(d.homePitchers || [], homeAbbr, homeLogo));

        h.push('</div>'); // .mlb-box-body
        return h.join('');
    },

    renderBattersSection: function(batters, abbr, logoUrl) {
        if (!batters || batters.length === 0) return '';

        // Compute totals for summary
        var totals = { ab: 0, r: 0, h: 0, rbi: 0, bb: 0, k: 0 };
        for (var i = 0; i < batters.length; i++) {
            totals.ab += batters[i].atBats;
            totals.r += batters[i].runs;
            totals.h += batters[i].hits;
            totals.rbi += batters[i].rbi;
            totals.bb += batters[i].walks;
            totals.k += batters[i].strikeOuts;
        }

        var sectionId = 'mlb-batters-' + abbr.replace(/[^a-zA-Z]/g, '');
        var summary = totals.h + '-' + totals.ab + ', ' + totals.r + ' R, ' + totals.rbi + ' RBI, ' + totals.bb + ' BB, ' + totals.k + ' K';

        var h = [];
        h.push('<div class="mlb-box-batters">');
        h.push('<div class="mlb-box-section-title mlb-box-collapsible" onclick="Mlb.toggleSection(\'' + sectionId + '\', this)">');
        h.push('<span class="mlb-box-toggle">&#9654;</span> ');
        if (logoUrl) h.push('<img class="mlb-box-section-logo" src="' + logoUrl + '" alt="" onerror="this.style.display=\'none\'">');
        h.push(abbr + ' Batting');
        h.push('<span class="mlb-box-section-summary">' + summary + '</span>');
        h.push('</div>');
        h.push('<div id="' + sectionId + '" class="mlb-box-collapsible-body" style="display:none;">');
        h.push('<table class="mlb-box-batters-table">');
        h.push('<thead><tr>');
        h.push('<th class="mlb-box-batter-order">#</th>');
        h.push('<th class="mlb-box-batter-name">Batter</th>');
        h.push('<th class="mlb-box-batter-pos">Pos</th>');
        h.push('<th>AB</th><th>R</th><th>H</th><th>RBI</th><th>BB</th><th>K</th><th>AVG</th>');
        h.push('</tr></thead><tbody>');

        var prevSpot = 0;
        for (var i = 0; i < batters.length; i++) {
            var b = batters[i];
            var spotNum = b.lineupSpot;
            var showSpot = (!b.isSub && spotNum !== prevSpot) ? spotNum : '';
            if (!b.isSub) prevSpot = spotNum;

            h.push('<tr class="' + (b.isSub ? 'mlb-box-batter-sub' : '') + '">');
            h.push('<td class="mlb-box-batter-order">' + showSpot + '</td>');
            h.push('<td class="mlb-box-batter-name">' + (b.isSub ? '&nbsp;&nbsp;' : '') + this.escHtml(b.name) + '</td>');
            h.push('<td class="mlb-box-batter-pos">' + this.escHtml(b.position) + '</td>');
            h.push('<td>' + b.atBats + '</td>');
            h.push('<td>' + b.runs + '</td>');
            h.push('<td>' + b.hits + '</td>');
            h.push('<td>' + b.rbi + '</td>');
            h.push('<td>' + b.walks + '</td>');
            h.push('<td>' + b.strikeOuts + '</td>');
            h.push('<td>' + this.escHtml(b.avg) + '</td>');
            h.push('</tr>');
        }

        // Totals row
        h.push('<tr class="mlb-box-totals-row">');
        h.push('<td></td><td class="mlb-box-batter-name"><strong>Totals</strong></td><td></td>');
        h.push('<td><strong>' + totals.ab + '</strong></td>');
        h.push('<td><strong>' + totals.r + '</strong></td>');
        h.push('<td><strong>' + totals.h + '</strong></td>');
        h.push('<td><strong>' + totals.rbi + '</strong></td>');
        h.push('<td><strong>' + totals.bb + '</strong></td>');
        h.push('<td><strong>' + totals.k + '</strong></td>');
        h.push('<td></td>');
        h.push('</tr>');

        h.push('</tbody></table>');
        h.push('</div>');
        h.push('</div>');
        return h.join('');
    },

    renderPitchersSection: function(pitchers, abbr, logoUrl) {
        if (!pitchers || pitchers.length === 0) return '';

        // Build summary: total IP, K, ER, NP
        var totIP = 0; var totK = 0; var totER = 0; var totNP = 0; var totH = 0;
        for (var i = 0; i < pitchers.length; i++) {
            var ip = pitchers[i].inningsPitched;
            if (ip !== '-') {
                var parts = String(ip).split('.');
                var whole = parseInt(parts[0]) || 0;
                var frac = parseInt(parts[1]) || 0;
                totIP += whole * 3 + frac;
            }
            totK += pitchers[i].strikeOuts;
            totER += pitchers[i].earnedRuns;
            totNP += pitchers[i].pitchCount;
            totH += pitchers[i].hits;
        }
        var ipWhole = Math.floor(totIP / 3);
        var ipFrac = totIP % 3;
        var ipStr = ipWhole + (ipFrac > 0 ? '.' + ipFrac : '.0');

        var sectionId = 'mlb-pitchers-' + abbr.replace(/[^a-zA-Z]/g, '');
        var summary = pitchers.length + ' P, ' + ipStr + ' IP, ' + totH + ' H, ' + totER + ' ER, ' + totK + ' K, ' + totNP + ' pitches';

        var h = [];
        h.push('<div class="mlb-box-pitchers">');
        h.push('<div class="mlb-box-section-title mlb-box-collapsible" onclick="Mlb.toggleSection(\'' + sectionId + '\', this)">');
        h.push('<span class="mlb-box-toggle">&#9654;</span> ');
        if (logoUrl) h.push('<img class="mlb-box-section-logo" src="' + logoUrl + '" alt="" onerror="this.style.display=\'none\'">');
        h.push(abbr + ' Pitchers');
        h.push('<span class="mlb-box-section-summary">' + summary + '</span>');
        h.push('</div>');
        h.push('<div id="' + sectionId + '" class="mlb-box-collapsible-body" style="display:none;">');
        h.push('<table class="mlb-box-pitchers-table">');
        h.push('<thead><tr>');
        h.push('<th class="mlb-box-pitcher-name">Pitcher</th>');
        h.push('<th>IP</th><th>H</th><th>R</th><th>ER</th><th>BB</th><th>K</th><th>NP</th>');
        h.push('</tr></thead><tbody>');

        for (var i = 0; i < pitchers.length; i++) {
            var p = pitchers[i];
            h.push('<tr>');
            h.push('<td class="mlb-box-pitcher-name">' + this.escHtml(p.name) + '</td>');
            h.push('<td>' + p.inningsPitched + '</td>');
            h.push('<td>' + p.hits + '</td>');
            h.push('<td>' + p.runs + '</td>');
            h.push('<td>' + p.earnedRuns + '</td>');
            h.push('<td>' + p.walks + '</td>');
            h.push('<td>' + p.strikeOuts + '</td>');
            h.push('<td>' + p.pitchCount + '</td>');
            h.push('</tr>');
        }

        h.push('</tbody></table>');
        h.push('</div>');
        h.push('</div>');
        return h.join('');
    },

    toggleSection: function(id, el) {
        var body = document.getElementById(id);
        if (!body) return;
        var visible = body.style.display !== 'none';
        body.style.display = visible ? 'none' : '';
        var toggle = el.querySelector('.mlb-box-toggle');
        if (toggle) toggle.innerHTML = visible ? '&#9654;' : '&#9660;';
    },

    // ─── Team Profile ───────────────────────────────────────────

    // Division lookup: division key → { leagueId, leagueName, divisionShort }
    divisionMap: {
        'AL East':    { leagueId: 103, leagueName: 'American League', divisionShort: 'East' },
        'AL Central': { leagueId: 103, leagueName: 'American League', divisionShort: 'Central' },
        'AL West':    { leagueId: 103, leagueName: 'American League', divisionShort: 'West' },
        'NL East':    { leagueId: 104, leagueName: 'National League', divisionShort: 'East' },
        'NL Central': { leagueId: 104, leagueName: 'National League', divisionShort: 'Central' },
        'NL West':    { leagueId: 104, leagueName: 'National League', divisionShort: 'West' }
    },

    switchView: function(view) {
        this.currentView = view;
        var tabs = document.querySelectorAll('#mlb-sub-tabs .sub-tab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].dataset.subtab === view);
        }

        var panelSchedule = document.getElementById('mlb-panel-schedule');
        var panelProfile = document.getElementById('mlb-panel-team-profile');
        if (!panelSchedule || !panelProfile) return;

        if (view === 'team-profile') {
            panelSchedule.style.display = 'none';
            panelProfile.style.display = '';
            this.loadTeamProfile(this.selectedTeamId);
        } else {
            panelSchedule.style.display = '';
            panelProfile.style.display = 'none';
            this.loadSchedule(this.currentDate);
            this.loadStandings();
        }
    },

    loadTeamProfile: function(mlbId) {
        this.selectedTeamId = mlbId;
        var container = document.getElementById('mlb-team-profile-content');
        if (!container) return;
        container.innerHTML = '<div style="padding:40px;text-align:center;color:#888;">Loading team profile...</div>';

        var self = this;
        Promise.all([
            API.get('/api/mlb/team-profile?team_id=' + mlbId),
            API.get('/api/mlb/team-affiliates?team_id=' + mlbId),
            API.get('/api/mlb/schedule?team_id=' + mlbId)
        ]).then(function(results) {
            self.renderTeamProfilePage(results[0], results[1], results[2]);
            self.populateTeamDropdown(mlbId);
        }).catch(function(err) {
            container.innerHTML = '<p style="padding:20px;color:#c62828;">Failed to load team profile: ' + (err.message || 'Unknown error') + '</p>';
        });
    },

    renderTeamProfilePage: function(profile, affiliatesData, scheduleData) {
        var container = document.getElementById('mlb-team-profile-content');
        if (!container) return;

        var h = [];
        var p = profile;
        var logoLarge = p.logoLargeUrl || p.logoUrl || '';
        var logoSmall = p.logoUrl || '';
        var leagueId = p.leagueId || 103;
        var leagueLogo = '/img/leagues/' + leagueId + '.svg';

        // ── Selector row: Division filter + Team dropdown + Division team icons ──
        h.push('<div class="mlb-profile-selector">');
        h.push('<label><strong>Division:</strong></label>');
        h.push('<select id="mlb-division-filter" class="mlb-team-dropdown" style="min-width:180px;"></select>');
        h.push('<label><strong>Team:</strong></label>');
        h.push('<select id="mlb-team-dropdown" class="mlb-team-dropdown"></select>');
        h.push('<div id="mlb-division-icons" class="mlb-division-icons"></div>');
        h.push('</div>');

        // ── Header row: Team info (left) + Stadium (right) ──
        h.push('<div class="mlb-profile-header-row">');

        // Left: Team card
        h.push('<div class="mlb-profile-header">');
        h.push('<img class="mlb-profile-logo" src="' + this.escHtml(logoLarge) + '"'
            + ' onerror="this.src=\'' + this.escHtml(logoSmall) + '\'"'
            + ' alt="' + this.escHtml(p.name) + '">');
        h.push('<div class="mlb-profile-header-info">');
        h.push('<div class="mlb-profile-team-name">' + this.escHtml(p.name) + '</div>');
        // League with icon
        h.push('<div class="mlb-profile-league-row">');
        h.push('<img class="mlb-profile-league-icon" src="' + leagueLogo + '" alt="" onerror="this.style.display=\'none\'">');
        h.push('<span>' + this.escHtml(p.league || '') + '</span>');
        h.push('</div>');
        // Division with team icons strip
        if (p.division) {
            h.push('<div class="mlb-profile-league-row">');
            h.push('<span class="mlb-profile-division-badge">' + this.escHtml(p.division) + '</span>');
            h.push('<span id="mlb-profile-div-icons" class="mlb-profile-div-team-icons"></span>');
            h.push('</div>');
        }
        if (p.firstYearOfPlay) {
            h.push('<div class="mlb-profile-meta">Est. ' + this.escHtml(p.firstYearOfPlay) + '</div>');
        }
        h.push('</div>');
        h.push('</div>'); // end .mlb-profile-header

        // Right: Stadium card
        if (p.stadium) {
            var s = p.stadium;
            h.push('<div class="mlb-profile-stadium-card">');
            h.push('<div class="mlb-profile-section-title">Stadium</div>');
            h.push('<div class="mlb-profile-info-card">');
            h.push('<div class="mlb-profile-info-row"><span class="mlb-profile-info-label">Name</span><span>' + this.escHtml(s.name) + '</span></div>');
            h.push('<div class="mlb-profile-info-row"><span class="mlb-profile-info-label">Location</span><span>' + this.escHtml(s.location) + '</span></div>');
            if (s.capacity) {
                h.push('<div class="mlb-profile-info-row"><span class="mlb-profile-info-label">Capacity</span><span>' + Number(s.capacity).toLocaleString() + '</span></div>');
            }
            if (s.opened) {
                h.push('<div class="mlb-profile-info-row"><span class="mlb-profile-info-label">Opened</span><span>' + s.opened + '</span></div>');
            }
            if (s.previousNames && s.previousNames.length > 0) {
                h.push('<div class="mlb-profile-info-row"><span class="mlb-profile-info-label">Previous Names</span><span>' + this.escHtml(s.previousNames.join(', ')) + '</span></div>');
            }
            if (p.tvChannels && p.tvChannels.length > 0) {
                h.push('<div class="mlb-profile-info-row"><span class="mlb-profile-info-label">TV Broadcast</span><span>' + this.escHtml(p.tvChannels.join(', ')) + '</span></div>');
            }
            h.push('</div>');
            h.push('</div>'); // end .mlb-profile-stadium-card
        }

        h.push('</div>'); // end .mlb-profile-header-row

        // ── History section ──
        if (p.description) {
            h.push('<div class="mlb-profile-section">');
            h.push('<div class="mlb-profile-section-title">Team History</div>');
            var paragraphs = p.description.split('\n\n');
            for (var i = 0; i < paragraphs.length; i++) {
                if (paragraphs[i].trim()) {
                    h.push('<p class="mlb-profile-description">' + this.escHtml(paragraphs[i].trim()) + '</p>');
                }
            }
            h.push('</div>');
        }

        // ── Games section (horizontal: 3 columns) ──
        h.push('<div class="mlb-profile-section">');
        h.push('<div class="mlb-profile-section-title">Recent &amp; Upcoming Games</div>');
        h.push(this.renderTeamScheduleSection(scheduleData));
        h.push('</div>');

        // ── Tickets section ──
        if (p.ticketUrl) {
            h.push('<div class="mlb-profile-section mlb-profile-tickets">');
            h.push('<div class="mlb-profile-section-title">Tickets</div>');
            h.push('<p>Purchase tickets at <a href="' + this.escHtml(p.ticketUrl) + '" target="_blank" rel="noopener">' + this.escHtml(p.ticketUrl) + '</a></p>');
            h.push('</div>');
        }

        // ── Affiliates section ──
        var affiliates = affiliatesData.affiliates || [];
        if (affiliates.length > 0) {
            h.push('<div class="mlb-profile-section">');
            h.push('<div class="mlb-profile-section-title">Minor League Affiliates</div>');
            h.push('<div class="mlb-affiliates-grid">');
            for (var i = 0; i < affiliates.length; i++) {
                var a = affiliates[i];
                h.push('<div class="mlb-affiliate-card">');
                if (a.logoUrl) {
                    h.push('<img class="mlb-affiliate-logo" src="' + this.escHtml(a.logoUrl) + '" alt="" onerror="this.style.display=\'none\'">');
                }
                h.push('<div class="mlb-affiliate-info">');
                h.push('<div class="mlb-affiliate-level">' + this.escHtml(a.level) + '</div>');
                h.push('<div class="mlb-affiliate-name">' + this.escHtml(a.name) + '</div>');
                if (a.league) h.push('<div class="mlb-affiliate-detail">' + this.escHtml(a.league) + '</div>');
                if (a.venue) h.push('<div class="mlb-affiliate-detail">' + this.escHtml(a.venue) + '</div>');
                h.push('</div>');
                h.push('</div>');
            }
            h.push('</div>');
            h.push('</div>');
        }

        container.innerHTML = h.join('');

        // Populate dropdowns after DOM is ready
        this.populateTeamDropdown(this.selectedTeamId);
    },

    populateTeamDropdown: function(selectedMlbId) {
        var self = this;
        if (this.teamsList) {
            this.fillDropdowns(selectedMlbId);
            return;
        }
        API.get('/api/mlb/standings').then(function(data) {
            var teams = [];
            var divs = data.divisions || {};
            var keys = Object.keys(divs);
            for (var i = 0; i < keys.length; i++) {
                var divTeams = divs[keys[i]];
                for (var j = 0; j < divTeams.length; j++) {
                    teams.push({
                        mlb_id: divTeams[j].mlb_id,
                        name: divTeams[j].team_name,
                        abbreviation: divTeams[j].abbreviation,
                        division: keys[i]  // e.g. "AL Central"
                    });
                }
            }
            teams.sort(function(a, b) { return a.name.localeCompare(b.name); });
            self.teamsList = teams;
            self.fillDropdowns(selectedMlbId);
        }).catch(function() {});
    },

    fillDropdowns: function(selectedMlbId) {
        if (!this.teamsList) return;
        var self = this;

        // Find the selected team's division
        var selectedDiv = 'All';
        for (var i = 0; i < this.teamsList.length; i++) {
            if (String(this.teamsList[i].mlb_id) === String(selectedMlbId)) {
                selectedDiv = this.teamsList[i].division;
                break;
            }
        }

        // Build division filter
        var divSelect = document.getElementById('mlb-division-filter');
        if (divSelect) {
            var currentDivVal = divSelect.value || selectedDiv;
            divSelect.innerHTML = '';
            var divOpt = document.createElement('option');
            divOpt.value = 'All';
            divOpt.textContent = 'All Divisions';
            divSelect.appendChild(divOpt);

            var divKeys = Object.keys(this.divisionMap);
            for (var i = 0; i < divKeys.length; i++) {
                var opt = document.createElement('option');
                opt.value = divKeys[i];
                opt.textContent = divKeys[i];
                if (divKeys[i] === currentDivVal) opt.selected = true;
                divSelect.appendChild(opt);
            }

            divSelect.onchange = function() {
                self.filterTeamsByDivision(divSelect.value, selectedMlbId);
            };
        }

        // Fill team dropdown filtered by division
        this.filterTeamsByDivision(selectedDiv, selectedMlbId);
    },

    filterTeamsByDivision: function(divFilter, selectedMlbId) {
        var self = this;
        var select = document.getElementById('mlb-team-dropdown');
        if (!select || !this.teamsList) return;

        // Filter teams
        var filtered = this.teamsList;
        if (divFilter && divFilter !== 'All') {
            filtered = this.teamsList.filter(function(t) { return t.division === divFilter; });
        }

        select.innerHTML = '';
        var hasSelected = false;
        for (var i = 0; i < filtered.length; i++) {
            var t = filtered[i];
            var opt = document.createElement('option');
            opt.value = t.mlb_id;
            opt.textContent = t.name;
            if (String(t.mlb_id) === String(selectedMlbId)) {
                opt.selected = true;
                hasSelected = true;
            }
            select.appendChild(opt);
        }
        // If selected team not in filtered list, select first
        if (!hasSelected && filtered.length > 0) {
            select.options[0].selected = true;
        }

        select.onchange = function() {
            var newId = parseInt(select.value);
            self.loadTeamProfile(newId);
        };

        // Update division icons strip (right of dropdowns)
        this.renderDivisionIcons(divFilter);

        // Update division team icons in header
        this.renderHeaderDivIcons(divFilter, selectedMlbId);
    },

    renderDivisionIcons: function(divFilter) {
        var container = document.getElementById('mlb-division-icons');
        if (!container || !this.teamsList) return;

        var teams = this.teamsList;
        if (divFilter && divFilter !== 'All') {
            teams = this.teamsList.filter(function(t) { return t.division === divFilter; });
        } else {
            container.innerHTML = '';
            return;
        }

        var h = [];
        for (var i = 0; i < teams.length; i++) {
            h.push('<img class="mlb-division-icon" src="/img/teams/' + teams[i].mlb_id + '.png"'
                + ' alt="' + this.escHtml(teams[i].abbreviation) + '"'
                + ' title="' + this.escHtml(teams[i].name) + '"'
                + ' style="cursor:pointer;" onclick="Mlb.loadTeamProfile(' + teams[i].mlb_id + ')"'
                + ' onerror="this.style.display=\'none\'">');
        }
        container.innerHTML = h.join('');
    },

    renderHeaderDivIcons: function(divFilter, selectedMlbId) {
        var container = document.getElementById('mlb-profile-div-icons');
        if (!container || !this.teamsList) return;

        // Find the selected team's division
        var targetDiv = divFilter;
        if (!targetDiv || targetDiv === 'All') {
            for (var i = 0; i < this.teamsList.length; i++) {
                if (String(this.teamsList[i].mlb_id) === String(selectedMlbId)) {
                    targetDiv = this.teamsList[i].division;
                    break;
                }
            }
        }

        var teams = this.teamsList.filter(function(t) { return t.division === targetDiv; });
        var h = [];
        for (var i = 0; i < teams.length; i++) {
            h.push('<img class="mlb-profile-div-icon" src="/img/teams/' + teams[i].mlb_id + '.png"'
                + ' alt="' + this.escHtml(teams[i].abbreviation) + '"'
                + ' title="' + this.escHtml(teams[i].name) + '"'
                + ' onerror="this.style.display=\'none\'">');
        }
        container.innerHTML = h.join('');
    },

    renderTeamScheduleSection: function(data) {
        if (!data) return '<p class="text-muted">No schedule data available</p>';

        var dates = Object.keys(data).sort();
        var columns = [];

        for (var i = 0; i < dates.length; i++) {
            var section = data[dates[i]];
            if (!section) continue;
            var label = this.buildDayLabel(section.date || dates[i]);
            var games = section.games || [];

            var col = [];
            col.push('<div class="mlb-profile-games-col">');
            col.push('<div class="mlb-profile-games-col-header">' + this.escHtml(label) + '</div>');
            if (games.length === 0) {
                col.push('<p class="text-muted" style="padding:8px;font-size:13px;">No games</p>');
            } else {
                for (var j = 0; j < games.length; j++) {
                    col.push(this.renderGameCard(games[j]));
                }
            }
            col.push('</div>');
            columns.push(col.join(''));
        }

        if (columns.length === 0) {
            return '<p class="text-muted">No games scheduled for this period</p>';
        }
        return '<div class="mlb-profile-games-row">' + columns.join('') + '</div>';
    },

    // ─── Helpers ───────────────────────────────────────────────

    escHtml: function(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
