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

    init: function() {
        var panel = document.getElementById('tab-mlb');
        if (!panel) return;

        this.currentDate = this.todayStr();

        if (!this.initialized) {
            this.initialized = true;
            this.renderSkeleton(panel);
        }

        this.loadSchedule(this.currentDate);
        this.loadStandings();
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

        // Date navigation
        html.push('<div class="mlb-date-nav">');
        html.push('<button class="btn btn-secondary btn-sm" id="mlb-prev">&larr; Prev</button>');
        html.push('<span class="mlb-date-label" id="mlb-date-label"></span>');
        html.push('<button class="btn btn-secondary btn-sm" id="mlb-next">Next &rarr;</button>');
        html.push('<button class="btn btn-primary btn-sm" id="mlb-today" style="margin-left:12px;">Today</button>');
        html.push('</div>');

        // Schedule sections
        html.push('<div id="mlb-schedule"></div>');

        // Standings
        html.push('<div class="mlb-standings-section">');
        html.push('<h2 class="mlb-section-title">Standings</h2>');
        html.push('<div id="mlb-standings" class="mlb-standings-container"></div>');
        html.push('</div>');

        panel.innerHTML = html.join('');

        // Wire up nav buttons
        var self = this;
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

    renderSchedule: function(data) {
        var container = document.getElementById('mlb-schedule');
        if (!container) return;

        var html = [];
        var dates = Object.keys(data).sort();

        for (var i = 0; i < dates.length; i++) {
            var dateKey = dates[i];
            var section = data[dateKey];
            if (!section || !section.games) continue;

            var sectionLabel = section.label || dateKey;
            var gameCount = section.games.length;

            html.push('<div class="mlb-day-section">');
            html.push('<h3 class="mlb-day-header">' + this.escHtml(sectionLabel));
            html.push('<span class="mlb-game-count">' + gameCount + ' game' + (gameCount !== 1 ? 's' : '') + '</span>');
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
        p.push('<div class="mlb-game-card' + (game.isLive ? ' mlb-game-live' : '') + '">');

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

        for (var li = 0; li < leagueNames.length; li++) {
            var lg = leagueNames[li];
            html.push('<div class="mlb-standings-league">');
            html.push('<h3 class="mlb-league-title">' + leagueLabels[lg] + '</h3>');

            for (var di = 0; di < divOrder.length; di++) {
                var div = divOrder[di];
                var teams = leagues[lg][div];
                if (!teams || teams.length === 0) continue;

                html.push('<table class="mlb-standings-table">');
                html.push('<thead>');
                html.push('<tr class="mlb-division-header"><th colspan="7">' + lg + ' ' + div + '</th></tr>');
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

    // ─── Helpers ───────────────────────────────────────────────

    escHtml: function(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
