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
    currentView: 'schedule',   // 'schedule', 'team-profile', or 'postseason'
    selectedTeamId: 145,        // Default: Chicago White Sox
    teamsList: null,            // Cached team list for dropdown
    postseasonData: null,
    postseasonTab: null,        // 'last-season' or 'current-season'
    postseasonLoading: false,

    // MiLB state
    isMilb: false,
    milbLevel: 11,              // Default AAA (sportId)
    milbInitialized: false,
    milbTeamsList: null,
    milbSelectedTeamId: null,
    milbCurrentView: 'schedule',
    milbCurrentDate: null,
    milbLiveTimer: null,
    milbPostseasonData: null,
    milbPostseasonTab: null,

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
        } else if (this.currentView === 'postseason') {
            this.switchView('postseason');
        } else {
            this.loadSchedule(this.currentDate);
            this.loadStandings();
            this.loadWildCard();
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
        html.push('<button class="sub-tab" data-subtab="postseason">Postseason</button>');
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
        html.push('<div class="mlb-standings-section mlb-wildcard-section">');
        html.push('<h2 class="mlb-section-title">Wild Card Standings</h2>');
        html.push('<div id="mlb-wildcard" class="mlb-standings-container"></div>');
        html.push('</div>');
        html.push('</div>'); // end mlb-panel-schedule

        // Panel 2: Team Profile
        html.push('<div id="mlb-panel-team-profile" class="sub-panel" style="display:none;">');
        html.push('<div id="mlb-team-profile-content"></div>');
        html.push('</div>');

        // Panel 3: Postseason
        html.push('<div id="mlb-panel-postseason" class="sub-panel" style="display:none;">');
        html.push('<div class="mlb-ps-inner-tabs" id="mlb-ps-tabs">');
        html.push('<button class="mlb-ps-tab active" data-pstab="last-season">Last Season</button>');
        html.push('<button class="mlb-ps-tab" data-pstab="current-season">Current Season</button>');
        html.push('</div>');
        html.push('<div id="mlb-postseason-content"></div>');
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
        this.renderScheduleInto('mlb-schedule', data);
    },

    renderScheduleInto: function(containerId, data) {
        var container = document.getElementById(containerId);
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
            // Base diamond
            p.push(this.renderBaseDiamond(game.onFirst, game.onSecond, game.onThird));
        } else if (game.isFinal) {
            p.push('<span class="mlb-status-text mlb-status-final">' + this.escHtml(game.status) + '</span>');
        } else {
            p.push('<span class="mlb-status-text mlb-status-scheduled">' + this.escHtml(game.startTime || game.status) + '</span>');
        }
        p.push('</div>');

        // Away team row — batting if Top of inning
        var awayBatting = game.isLive && game.inningState === 'Top';
        p.push(this.renderTeamRow(game.away, game.isFinal || game.isLive, awayBatting));

        // Home team row — batting if Bottom of inning
        var homeBatting = game.isLive && game.inningState === 'Bottom';
        p.push(this.renderTeamRow(game.home, game.isFinal || game.isLive, homeBatting));

        // Decisions (W/L/S for Final games)
        if (game.isFinal && game.decisions) {
            var dec = game.decisions;
            var decParts = [];
            if (dec.winner) decParts.push('<span class="mlb-decision-w">W: ' + this.escHtml(dec.winner) + this.handBadge(dec.winnerHand) + '</span>');
            if (dec.loser) decParts.push('<span class="mlb-decision-l">L: ' + this.escHtml(dec.loser) + this.handBadge(dec.loserHand) + '</span>');
            if (dec.save) decParts.push('<span class="mlb-decision-sv">SV: ' + this.escHtml(dec.save) + this.handBadge(dec.saveHand) + '</span>');
            if (decParts.length > 0) {
                p.push('<div class="mlb-decisions">' + decParts.join('&nbsp;&nbsp;') + '</div>');
            }
        }

        // Probable pitchers (for Scheduled games)
        if (game.isScheduled && game.probablePitchers) {
            var prob = game.probablePitchers;
            if (prob.away || prob.home) {
                var probParts = [];
                if (prob.away) {
                    probParts.push(this.escHtml((game.away.abbreviation || '') + ': ' + prob.away) + this.handBadge(prob.awayHand));
                }
                if (prob.home) {
                    probParts.push(this.escHtml((game.home.abbreviation || '') + ': ' + prob.home) + this.handBadge(prob.homeHand));
                }
                p.push('<div class="mlb-probables">' + probParts.join(' vs ') + '</div>');
            }
        }

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

    renderTeamRow: function(team, showScore, isBatting) {
        var p = [];
        var isWinner = team.isWinner;
        var cls = 'mlb-team-row';
        if (isWinner) cls += ' mlb-winner';
        if (isBatting) cls += ' mlb-batting';
        p.push('<div class="' + cls + '">');

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

    renderBaseDiamond: function(first, second, third) {
        var empty = '#ccc';
        var occupied = '#ff9800';
        var f1 = first ? occupied : empty;
        var f2 = second ? occupied : empty;
        var f3 = third ? occupied : empty;
        return '<svg class="mlb-diamond" width="28" height="28" viewBox="0 0 28 28">'
            + '<rect x="18" y="11" width="7" height="7" transform="rotate(45 21.5 14.5)" fill="' + f1 + '"/>'
            + '<rect x="11" y="4" width="7" height="7" transform="rotate(45 14.5 7.5)" fill="' + f2 + '"/>'
            + '<rect x="4" y="11" width="7" height="7" transform="rotate(45 7.5 14.5)" fill="' + f3 + '"/>'
            + '</svg>';
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
        var leagueLogos = { 'AL': 'mlb_al_logo', 'NL': 'mlb_nl_logo' };

        for (var li = 0; li < leagueNames.length; li++) {
            var lg = leagueNames[li];
            html.push('<div class="mlb-standings-league">');
            html.push('<h3 class="mlb-league-title">');
            html.push('<img class="mlb-standings-league-logo" src="/img/leagues/' + leagueLogos[lg] + '.svg" alt="" onerror="this.style.display=\'none\'">');
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

    // ─── Wild Card Standings ────────────────────────────────────

    loadWildCard: function() {
        var self = this;
        API.get('/api/mlb/wild-card').then(function(data) {
            self.renderWildCard(data.standings || []);
        }).catch(function() {
            var el = document.getElementById('mlb-wildcard');
            if (el) el.innerHTML = '<p class="text-muted">Unable to load wild card standings</p>';
        });
    },

    renderWildCard: function(standings) {
        var container = document.getElementById('mlb-wildcard');
        if (!container) return;

        if (!standings || standings.length === 0) {
            container.innerHTML = '<p class="text-muted">Wild card standings not available yet</p>';
            return;
        }

        var html = [];
        for (var li = 0; li < standings.length; li++) {
            var league = standings[li];
            var leagueName = league.league || 'Unknown';
            var isAL = leagueName.indexOf('American') >= 0;
            var leagueLogo = '/img/leagues/' + (isAL ? 'mlb_al_logo' : 'mlb_nl_logo') + '.svg';

            html.push('<div class="mlb-standings-league">');
            html.push('<h3 class="mlb-league-title">');
            html.push('<img class="mlb-standings-league-logo" src="' + leagueLogo + '" alt="" onerror="this.style.display=\'none\'">');
            html.push(this.escHtml(leagueName) + ' Wild Card</h3>');

            html.push('<table class="mlb-standings-table mlb-wildcard-table">');
            html.push('<thead><tr>');
            html.push('<th class="mlb-st-num" style="width:30px;">WC#</th>');
            html.push('<th class="mlb-st-team">Team</th>');
            html.push('<th class="mlb-st-num">W</th>');
            html.push('<th class="mlb-st-num">L</th>');
            html.push('<th class="mlb-st-num">PCT</th>');
            html.push('<th class="mlb-st-num">WCGB</th>');
            html.push('<th class="mlb-st-num">STRK</th>');
            html.push('</tr></thead><tbody>');

            var teams = league.teams || [];
            for (var t = 0; t < teams.length; t++) {
                var tm = teams[t];
                var qualified = tm.wcRank <= 3;
                var rowClass = qualified ? 'mlb-wc-qualified' : '';
                if (tm.eliminated) rowClass += ' mlb-wc-eliminated';

                html.push('<tr class="' + rowClass + '">');
                html.push('<td class="mlb-st-num">' + tm.wcRank + '</td>');
                html.push('<td class="mlb-st-team">');
                html.push('<img class="mlb-st-logo" src="' + tm.logoUrl + '" alt="" onerror="this.style.display=\'none\'">');
                html.push('<span>' + this.escHtml(tm.name) + '</span>');
                html.push('</td>');
                html.push('<td class="mlb-st-num">' + tm.wins + '</td>');
                html.push('<td class="mlb-st-num">' + tm.losses + '</td>');
                html.push('<td class="mlb-st-num">' + tm.pct + '</td>');
                html.push('<td class="mlb-st-num">' + tm.gb + '</td>');
                html.push('<td class="mlb-st-num">' + this.escHtml(tm.streak) + '</td>');
                html.push('</tr>');
            }

            html.push('</tbody></table>');
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

        // Decisions (W/L/S)
        if (isFinal && d.decisions) {
            var dec = d.decisions;
            var decParts = [];
            if (dec.winner) decParts.push('<span class="mlb-decision-w">W: ' + this.escHtml(dec.winner.name || dec.winner) + this.handBadge(dec.winner.hand) + '</span>');
            if (dec.loser) decParts.push('<span class="mlb-decision-l">L: ' + this.escHtml(dec.loser.name || dec.loser) + this.handBadge(dec.loser.hand) + '</span>');
            if (dec.save) decParts.push('<span class="mlb-decision-sv">SV: ' + this.escHtml(dec.save.name || dec.save) + this.handBadge(dec.save.hand) + '</span>');
            if (decParts.length > 0) {
                h.push('<div class="mlb-box-decisions">' + decParts.join('&nbsp;&nbsp;&nbsp;') + '</div>');
            }
        }

        // Batting / Pitching matchup (side-by-side)
        if (d.currentMatchup) {
            var m = d.currentMatchup;
            h.push('<div class="mlb-box-matchup' + (isFinal ? ' mlb-box-matchup-final' : '') + '">');

            // Left: Batter
            h.push('<div class="mlb-box-matchup-side">');
            h.push('<span class="mlb-box-player-label">Batting</span> ');
            h.push('<span class="mlb-box-player-name">' + this.escHtml(m.batter.name) + this.handBadge(m.batter.batSide) + '</span>');
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

            // Right: Pitcher
            h.push('<div class="mlb-box-matchup-side">');
            h.push('<span class="mlb-box-player-label">Pitching</span> ');
            h.push('<span class="mlb-box-player-name">' + this.escHtml(m.pitcher.name) + this.handBadge(m.pitcher.pitchHand) + '</span>');
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

            var hrClass = b.homeRuns > 0 ? ' mlb-hr-highlight' : '';
            h.push('<tr class="' + (b.isSub ? 'mlb-box-batter-sub' : '') + hrClass + '">');
            h.push('<td class="mlb-box-batter-order">' + showSpot + '</td>');
            h.push('<td class="mlb-box-batter-name">' + (b.isSub ? '&nbsp;&nbsp;' : '') + this.escHtml(b.name) + this.handBadge(b.batSide) + (b.homeRuns > 0 ? ' <span class="mlb-hr-badge">' + b.homeRuns + ' HR</span>' : '') + '</td>');
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

        // Home Runs summary
        var hrBatters = [];
        for (var i = 0; i < batters.length; i++) {
            if (batters[i].homeRuns > 0) {
                var hrText = this.escHtml(batters[i].name) + ' ' + batters[i].homeRuns;
                if (batters[i].seasonHomeRuns) hrText += ' (' + batters[i].seasonHomeRuns + ')';
                hrBatters.push(hrText);
            }
        }
        if (hrBatters.length > 0) {
            h.push('<div class="mlb-hr-summary">HR: ' + hrBatters.join(', ') + '</div>');
        }

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
            h.push('<td class="mlb-box-pitcher-name">' + this.escHtml(p.name) + this.handBadge(p.pitchHand) + '</td>');
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
        var panelPostseason = document.getElementById('mlb-panel-postseason');
        if (!panelSchedule || !panelProfile || !panelPostseason) return;

        panelSchedule.style.display = 'none';
        panelProfile.style.display = 'none';
        panelPostseason.style.display = 'none';

        if (view === 'team-profile') {
            panelProfile.style.display = '';
            this.loadTeamProfile(this.selectedTeamId);
        } else if (view === 'postseason') {
            panelPostseason.style.display = '';
            this.loadPostseason();
        } else {
            panelSchedule.style.display = '';
            this.loadSchedule(this.currentDate);
            this.loadStandings();
            this.loadWildCard();
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
            API.get('/api/mlb/schedule?team_id=' + mlbId),
            API.get('/api/mlb/team-roster?team_id=' + mlbId)
        ]).then(function(results) {
            self.renderTeamProfilePage(results[0], results[1], results[2], results[3]);
        }).catch(function(err) {
            container.innerHTML = '<p style="padding:20px;color:#c62828;">Failed to load team profile: ' + (err.message || 'Unknown error') + '</p>';
        });
    },

    renderTeamProfilePage: function(profile, affiliatesData, scheduleData, rosterData) {
        var container = document.getElementById('mlb-team-profile-content');
        if (!container) return;

        var h = [];
        var p = profile;
        var logoLarge = p.logoLargeUrl || p.logoUrl || '';
        var logoSmall = p.logoUrl || '';
        var leagueId = p.leagueId || 103;
        var leagueLogo = '/img/leagues/' + (leagueId === 103 ? 'mlb_al_logo' : 'mlb_nl_logo') + '.svg';

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
            if (s.address) {
                h.push('<div class="mlb-profile-info-row"><span class="mlb-profile-info-label">Address</span><span>' + this.escHtml(s.address) + '</span></div>');
            }
            if (s.phone) {
                h.push('<div class="mlb-profile-info-row"><span class="mlb-profile-info-label">Phone</span><span><a href="tel:' + this.escHtml(s.phone.replace(/[^\d+]/g, '')) + '">' + this.escHtml(s.phone) + '</a></span></div>');
            }
            if (s.website) {
                h.push('<div class="mlb-profile-info-row"><span class="mlb-profile-info-label">Website</span><span><a href="' + this.escHtml(s.website) + '" target="_blank" rel="noopener">' + this.escHtml(s.website.replace('https://www.', '')) + '</a></span></div>');
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

        // ── Championships section ──
        if (p.championships) {
            var champ = p.championships;
            var hasAny = (champ.worldSeries && champ.worldSeries.length > 0)
                      || (champ.pennants && champ.pennants.length > 0)
                      || (champ.divisionTitles && champ.divisionTitles.length > 0);

            h.push('<div class="mlb-profile-section mlb-championships">');
            h.push('<div class="mlb-profile-section-title">Championships &amp; Titles</div>');

            if (!hasAny) {
                h.push('<p class="text-muted">No championship history yet</p>');
            } else {
                if (champ.worldSeries && champ.worldSeries.length > 0) {
                    h.push('<div class="mlb-trophy-row">');
                    h.push('<span class="mlb-trophy-icon mlb-trophy-gold">&#127942;</span>');
                    h.push('<div class="mlb-trophy-info">');
                    h.push('<div class="mlb-trophy-label">World Series (' + champ.worldSeries.length + ')</div>');
                    h.push('<div class="mlb-trophy-years">' + champ.worldSeries.join(', ') + '</div>');
                    h.push('</div></div>');
                }
                if (champ.pennants && champ.pennants.length > 0) {
                    h.push('<div class="mlb-trophy-row">');
                    h.push('<span class="mlb-trophy-icon mlb-trophy-silver">&#127941;</span>');
                    h.push('<div class="mlb-trophy-info">');
                    h.push('<div class="mlb-trophy-label">League Pennants (' + champ.pennants.length + ')</div>');
                    h.push('<div class="mlb-trophy-years">' + champ.pennants.join(', ') + '</div>');
                    h.push('</div></div>');
                }
                if (champ.divisionTitles && champ.divisionTitles.length > 0) {
                    h.push('<div class="mlb-trophy-row">');
                    h.push('<span class="mlb-trophy-icon mlb-trophy-bronze">&#127944;</span>');
                    h.push('<div class="mlb-trophy-info">');
                    h.push('<div class="mlb-trophy-label">Division Titles (' + champ.divisionTitles.length + ')</div>');
                    h.push('<div class="mlb-trophy-years">' + champ.divisionTitles.join(', ') + '</div>');
                    h.push('</div></div>');
                }
            }
            h.push('</div>');
        }

        // ── Games section (horizontal: 3 columns) ──
        h.push('<div class="mlb-profile-section">');
        h.push('<div class="mlb-profile-section-title">Recent &amp; Upcoming Games</div>');
        h.push(this.renderTeamScheduleSection(scheduleData));
        h.push('</div>');

        // ── Roster section ──
        var roster = (rosterData && rosterData.roster) ? rosterData.roster : [];
        if (roster.length > 0) {
            var pitchers = roster.filter(function(r) { return r.posType === 'Pitcher'; });
            var posPlayers = roster.filter(function(r) { return r.posType !== 'Pitcher'; });

            h.push('<div class="mlb-profile-section">');
            h.push('<div class="mlb-profile-section-title">Active Roster (' + roster.length + ')</div>');

            // Pitchers table
            if (pitchers.length > 0) {
                h.push('<div class="mlb-roster-group-header">Pitchers (' + pitchers.length + ')</div>');
                h.push('<table class="mlb-roster-table">');
                h.push('<thead><tr><th>#</th><th>Name</th><th>Pos</th><th>B/T</th><th>Age</th><th>ERA</th><th>W-L</th><th>K</th><th>IP</th></tr></thead><tbody>');
                for (var ri = 0; ri < pitchers.length; ri++) {
                    var rp = pitchers[ri];
                    var s = rp.stats || {};
                    h.push('<tr>');
                    h.push('<td>' + this.escHtml(rp.number) + '</td>');
                    h.push('<td>' + this.escHtml(rp.name) + '</td>');
                    h.push('<td>' + this.escHtml(rp.position) + '</td>');
                    h.push('<td>' + this.escHtml(rp.bats + '/' + rp.throws) + '</td>');
                    h.push('<td>' + (rp.age || '') + '</td>');
                    h.push('<td>' + (s.era || '-') + '</td>');
                    h.push('<td>' + (s.wins !== undefined ? s.wins + '-' + (s.losses || 0) : '-') + '</td>');
                    h.push('<td>' + (s.strikeOuts || '-') + '</td>');
                    h.push('<td>' + (s.inningsPitched || '-') + '</td>');
                    h.push('</tr>');
                }
                h.push('</tbody></table>');
            }

            // Position players table
            if (posPlayers.length > 0) {
                h.push('<div class="mlb-roster-group-header">Position Players (' + posPlayers.length + ')</div>');
                h.push('<table class="mlb-roster-table">');
                h.push('<thead><tr><th>#</th><th>Name</th><th>Pos</th><th>B/T</th><th>Age</th><th>AVG</th><th>HR</th><th>RBI</th><th>OPS</th></tr></thead><tbody>');
                for (var ri = 0; ri < posPlayers.length; ri++) {
                    var rp = posPlayers[ri];
                    var s = rp.stats || {};
                    h.push('<tr>');
                    h.push('<td>' + this.escHtml(rp.number) + '</td>');
                    h.push('<td>' + this.escHtml(rp.name) + '</td>');
                    h.push('<td>' + this.escHtml(rp.position) + '</td>');
                    h.push('<td>' + this.escHtml(rp.bats + '/' + rp.throws) + '</td>');
                    h.push('<td>' + (rp.age || '') + '</td>');
                    h.push('<td>' + (s.avg || '-') + '</td>');
                    h.push('<td>' + (s.homeRuns !== undefined ? s.homeRuns : '-') + '</td>');
                    h.push('<td>' + (s.rbi !== undefined ? s.rbi : '-') + '</td>');
                    h.push('<td>' + (s.ops || '-') + '</td>');
                    h.push('</tr>');
                }
                h.push('</tbody></table>');
            }

            h.push('</div>');
        }

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
                self.filterTeamsByDivision(divSelect.value, self.selectedTeamId);
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
        // If selected team not in filtered list, select first and auto-load
        if (!hasSelected && filtered.length > 0) {
            select.options[0].selected = true;
            var autoId = parseInt(select.options[0].value);
            if (autoId && autoId !== self.selectedTeamId) {
                select.onchange = function() {
                    self.loadTeamProfile(parseInt(select.value));
                };
                this.renderDivisionIcons(divFilter);
                self.loadTeamProfile(autoId);
                return;
            }
        }

        select.onchange = function() {
            self.loadTeamProfile(parseInt(select.value));
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

    // ─── Postseason Bracket ──────────────────────────────────────

    loadPostseason: function() {
        if (this.postseasonLoading) return;

        var self = this;
        var container = document.getElementById('mlb-postseason-content');
        if (!container) return;

        // Wire up inner tab clicks (once)
        if (!this._psTabsWired) {
            this._psTabsWired = true;
            var tabs = document.querySelectorAll('#mlb-ps-tabs .mlb-ps-tab');
            for (var i = 0; i < tabs.length; i++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        var all = document.querySelectorAll('#mlb-ps-tabs .mlb-ps-tab');
                        for (var j = 0; j < all.length; j++) all[j].classList.remove('active');
                        btn.classList.add('active');
                        self.postseasonTab = btn.dataset.pstab;
                        self.postseasonData = null;
                        self.loadPostseason();
                    });
                })(tabs[i]);
            }
        }

        if (!this.postseasonTab) this.postseasonTab = 'last-season';

        var now = new Date();
        var currentYear = now.getFullYear();
        var season = this.postseasonTab === 'last-season' ? currentYear - 1 : currentYear;

        if (this.postseasonData && this.postseasonData.season === season) {
            this.renderBracketView(this.postseasonData);
            return;
        }

        container.innerHTML = '<div style="padding:40px;text-align:center;color:#888;">Loading postseason bracket...</div>';
        this.postseasonLoading = true;

        API.get('/api/mlb/postseason?season=' + season).then(function(data) {
            self.postseasonLoading = false;
            self.postseasonData = data;
            self.renderBracketView(data);
        }).catch(function(err) {
            self.postseasonLoading = false;
            container.innerHTML = '<p style="padding:20px;color:#c62828;">Failed to load postseason data: ' + (err.message || 'Unknown error') + '</p>';
        });
    },

    renderBracketView: function(data) {
        if (!data.hasStarted) {
            this.renderPrePostseason(data);
        } else {
            this.renderBracket(data);
        }
    },

    renderBracket: function(data) {
        var container = document.getElementById('mlb-postseason-content');
        if (!container) return;

        var h = [];
        var rounds = data.rounds;

        h.push('<div class="mlb-ps-bracket-title">' + data.season + ' MLB Postseason</div>');

        if (data.isComplete && rounds.worldSeries && rounds.worldSeries.winnerId) {
            h.push(this.renderChampion(rounds.worldSeries));
        }

        h.push('<div class="mlb-ps-bracket">');

        // Col 1: AL Wild Card
        h.push('<div class="mlb-ps-col mlb-ps-col-wc">');
        h.push('<div class="mlb-ps-col-header">AL Wild Card</div>');
        h.push(this.renderByeTeams(data, 'AL'));
        h.push(this.renderSeriesList(rounds.wildCard.AL));
        h.push('</div>');

        // Col 2: AL Division Series
        h.push('<div class="mlb-ps-col mlb-ps-col-ds">');
        h.push('<div class="mlb-ps-col-header">AL Div Series</div>');
        h.push(this.renderSeriesList(rounds.divSeries.AL));
        h.push('</div>');

        // Col 3: ALCS
        h.push('<div class="mlb-ps-col mlb-ps-col-lcs">');
        h.push('<div class="mlb-ps-col-header">ALCS</div>');
        h.push(this.renderSeriesList(rounds.lcs.AL ? [rounds.lcs.AL] : []));
        h.push('</div>');

        // Col 4: World Series
        h.push('<div class="mlb-ps-col mlb-ps-col-ws">');
        h.push('<div class="mlb-ps-col-header">World Series</div>');
        h.push(this.renderSeriesList(rounds.worldSeries ? [rounds.worldSeries] : []));
        h.push('</div>');

        // Col 5: NLCS
        h.push('<div class="mlb-ps-col mlb-ps-col-lcs">');
        h.push('<div class="mlb-ps-col-header">NLCS</div>');
        h.push(this.renderSeriesList(rounds.lcs.NL ? [rounds.lcs.NL] : []));
        h.push('</div>');

        // Col 6: NL Division Series
        h.push('<div class="mlb-ps-col mlb-ps-col-ds">');
        h.push('<div class="mlb-ps-col-header">NL Div Series</div>');
        h.push(this.renderSeriesList(rounds.divSeries.NL));
        h.push('</div>');

        // Col 7: NL Wild Card
        h.push('<div class="mlb-ps-col mlb-ps-col-wc">');
        h.push('<div class="mlb-ps-col-header">NL Wild Card</div>');
        h.push(this.renderByeTeams(data, 'NL'));
        h.push(this.renderSeriesList(rounds.wildCard.NL));
        h.push('</div>');

        h.push('</div>');
        container.innerHTML = h.join('');
    },

    renderByeTeams: function(data, league) {
        var seeds = data.seeds[league] || {};
        var byeTeams = [];
        var keys = Object.keys(seeds);
        for (var i = 0; i < keys.length; i++) {
            if (seeds[keys[i]] <= 2) {
                byeTeams.push({ mlbId: parseInt(keys[i]), seed: seeds[keys[i]] });
            }
        }
        byeTeams.sort(function(a, b) { return a.seed - b.seed; });
        if (byeTeams.length === 0) return '';

        var teams = data.playoffTeams[league] || [];
        var h = [];

        for (var i = 0; i < byeTeams.length; i++) {
            var bt = byeTeams[i];
            var team = null;
            for (var j = 0; j < teams.length; j++) {
                if (teams[j].mlb_id === bt.mlbId) { team = teams[j]; break; }
            }
            if (!team) continue;

            h.push('<div class="mlb-ps-bye">');
            h.push('<div class="mlb-ps-bye-inner">');
            h.push('<span class="mlb-ps-seed">' + bt.seed + '</span>');
            h.push('<img class="mlb-ps-team-logo" src="/img/teams/' + bt.mlbId + '.png" alt="" onerror="this.style.display=\'none\'">');
            h.push('<span class="mlb-ps-team-abbr">' + this.escHtml(team.abbreviation || team.name) + '</span>');
            h.push('<span class="mlb-ps-bye-label">BYE</span>');
            h.push('</div></div>');
        }

        return h.join('');
    },

    renderSeriesList: function(seriesArr) {
        if (!seriesArr || seriesArr.length === 0) {
            return '<div class="mlb-ps-matchup mlb-ps-tbd"><div class="mlb-ps-tbd-label">TBD</div></div>';
        }
        var h = [];
        for (var i = 0; i < seriesArr.length; i++) {
            h.push(this.renderMatchupCard(seriesArr[i]));
        }
        return h.join('');
    },

    renderMatchupCard: function(series) {
        if (!series) return '<div class="mlb-ps-matchup mlb-ps-tbd"><div class="mlb-ps-tbd-label">TBD</div></div>';

        var h = [];
        var isComplete = series.status === 'complete';
        var cardClass = 'mlb-ps-matchup';
        if (isComplete) cardClass += ' mlb-ps-complete';

        h.push('<div class="' + cardClass + '">');

        if (series.description) {
            h.push('<div class="mlb-ps-series-label">' + this.escHtml(series.description) + '</div>');
        }

        // Top team
        var topIsWinner = series.winnerId && series.topTeam && series.winnerId === series.topTeam.mlb_id;
        var topElim = isComplete && series.winnerId && series.topTeam && series.winnerId !== series.topTeam.mlb_id;
        h.push(this.renderBracketTeamSlot(series.topTeam, series.topWins, topIsWinner, topElim));

        h.push('<div class="mlb-ps-matchup-divider"></div>');

        // Bottom team
        var btmIsWinner = series.winnerId && series.bottomTeam && series.winnerId === series.bottomTeam.mlb_id;
        var btmElim = isComplete && series.winnerId && series.bottomTeam && series.winnerId !== series.bottomTeam.mlb_id;
        h.push(this.renderBracketTeamSlot(series.bottomTeam, series.bottomWins, btmIsWinner, btmElim));

        if (series.topWins || series.bottomWins) {
            h.push('<div class="mlb-ps-series-score">' + (series.topWins || 0) + '-' + (series.bottomWins || 0) + '</div>');
        }

        h.push('</div>');
        return h.join('');
    },

    renderBracketTeamSlot: function(team, wins, isWinner, isEliminated) {
        if (!team) {
            return '<div class="mlb-ps-team-slot mlb-ps-team-tbd"><span class="mlb-ps-team-abbr">TBD</span></div>';
        }

        var h = [];
        var cls = 'mlb-ps-team-slot';
        if (isWinner) cls += ' mlb-ps-winner';
        if (isEliminated) cls += ' mlb-ps-eliminated';

        h.push('<div class="' + cls + '">');
        h.push('<span class="mlb-ps-seed">' + (team.seed || '') + '</span>');
        var logo = team.logoUrl || '/img/teams/' + (team.mlb_id || '') + '.png';
        h.push('<img class="mlb-ps-team-logo" src="' + logo + '" alt="" onerror="this.style.display=\'none\'">');
        h.push('<span class="mlb-ps-team-abbr">' + this.escHtml(team.abbreviation || team.name || 'TBD') + '</span>');
        if (team.isWildCard) {
            h.push('<span class="mlb-ps-wc-badge">WC</span>');
        }
        h.push('<span class="mlb-ps-wins">' + (wins || 0) + '</span>');
        h.push('</div>');

        return h.join('');
    },

    renderChampion: function(wsSeries) {
        if (!wsSeries || !wsSeries.winner) return '';
        var w = wsSeries.winner;

        var h = [];
        h.push('<div class="mlb-ps-champion">');
        h.push('<div class="mlb-ps-champion-trophy">&#127942;</div>');
        var logo = w.logoUrl || '/img/teams/' + (w.mlb_id || '') + '.png';
        h.push('<img class="mlb-ps-champion-logo" src="' + logo + '" alt="" onerror="this.style.display=\'none\'">');
        h.push('<div class="mlb-ps-champion-name">' + this.escHtml(w.name || w.abbreviation || '') + '</div>');
        h.push('<div class="mlb-ps-champion-label">World Series Champions</div>');

        var topW = wsSeries.topWins || 0;
        var btmW = wsSeries.bottomWins || 0;
        var score = topW + '-' + btmW;
        if (wsSeries.winnerId === (wsSeries.bottomTeam || {}).mlb_id) {
            score = btmW + '-' + topW;
        }
        h.push('<div class="mlb-ps-champion-score">' + score + '</div>');
        h.push('</div>');
        return h.join('');
    },

    renderPrePostseason: function(data) {
        var container = document.getElementById('mlb-postseason-content');
        if (!container) return;

        var h = [];
        h.push('<div class="mlb-ps-bracket-title">' + data.season + ' Playoff Picture</div>');

        var pt = data.playoffTeams || {};
        var leagues = ['AL', 'NL'];
        var leagueLabels = { 'AL': 'American League', 'NL': 'National League' };
        var leagueLogos = { 'AL': '/img/leagues/mlb_al_logo.svg', 'NL': '/img/leagues/mlb_nl_logo.svg' };

        h.push('<div class="mlb-ps-preseason">');

        for (var li = 0; li < leagues.length; li++) {
            var lg = leagues[li];
            var teams = pt[lg] || [];

            h.push('<div class="mlb-ps-preseason-league">');
            h.push('<div class="mlb-ps-preseason-header">');
            h.push('<img class="mlb-ps-preseason-league-logo" src="' + leagueLogos[lg] + '" alt="" onerror="this.style.display=\'none\'">');
            h.push('<span>' + leagueLabels[lg] + '</span>');
            h.push('</div>');

            var clinched = [], inHunt = [], eliminated = [];
            for (var i = 0; i < teams.length; i++) {
                if (teams[i].clinched) clinched.push(teams[i]);
                else if (teams[i].eliminated) eliminated.push(teams[i]);
                else inHunt.push(teams[i]);
            }

            clinched.sort(function(a, b) { return (a.divRank || 99) - (b.divRank || 99) || (b.wins || 0) - (a.wins || 0); });
            inHunt.sort(function(a, b) { return (b.wins || 0) - (a.wins || 0); });

            if (clinched.length > 0) {
                h.push('<div class="mlb-ps-preseason-group-label">Clinched</div>');
                for (var i = 0; i < clinched.length; i++) h.push(this.renderPreTeamRow(clinched[i], i + 1));
            }

            if (inHunt.length > 0) {
                h.push('<div class="mlb-ps-preseason-group-label">In the Hunt</div>');
                for (var i = 0; i < inHunt.length; i++) h.push(this.renderPreTeamRow(inHunt[i], null));
            }

            if (clinched.length === 0 && inHunt.length === 0 && eliminated.length === 0) {
                teams.sort(function(a, b) { return (b.wins || 0) - (a.wins || 0); });
                h.push('<div class="mlb-ps-preseason-group-label">Teams</div>');
                for (var i = 0; i < teams.length; i++) h.push(this.renderPreTeamRow(teams[i], i + 1));
            }

            h.push('</div>');
        }

        h.push('</div>');
        container.innerHTML = h.join('');
    },

    renderPreTeamRow: function(team, seed) {
        var h = [];
        h.push('<div class="mlb-ps-pre-team">');
        if (seed) h.push('<span class="mlb-ps-seed">' + seed + '</span>');
        h.push('<img class="mlb-ps-team-logo" src="/img/teams/' + (team.mlb_id || '') + '.png" alt="" onerror="this.style.display=\'none\'">');
        h.push('<span class="mlb-ps-pre-team-name">' + this.escHtml(team.name || team.abbreviation || '') + '</span>');
        h.push('<span class="mlb-ps-pre-record">' + (team.wins || 0) + '-' + (team.losses || 0) + '</span>');
        if (team.clinchType) {
            var bc = team.clinchType === 'DIV' ? 'mlb-ps-clinch-div' : 'mlb-ps-clinch-wc';
            h.push('<span class="mlb-ps-clinch-badge ' + bc + '">' + this.escHtml(team.clinchType) + '</span>');
        }
        if (seed && seed <= 2) h.push('<span class="mlb-ps-bye-badge">BYE</span>');
        h.push('</div>');
        return h.join('');
    },

    // ─── MiLB ─────────────────────────────────────────────────

    MILB_LEVELS: [
        { sportId: 11, label: 'AAA' },
        { sportId: 12, label: 'AA' },
        { sportId: 13, label: 'High-A' },
        { sportId: 14, label: 'Single-A' }
    ],

    initMilb: function() {
        this.isMilb = true;
        var panel = document.getElementById('tab-milb');
        if (!panel) return;

        if (!this.milbCurrentDate) this.milbCurrentDate = this.todayStr();

        if (!this.milbInitialized) {
            this.milbInitialized = true;
            this.renderMilbSkeleton(panel);
        }

        if (this.milbCurrentView === 'team-profile') {
            this.switchMilbView('team-profile');
        } else if (this.milbCurrentView === 'postseason') {
            this.switchMilbView('postseason');
        } else {
            this.loadMilbSchedule(this.milbCurrentDate);
            this.loadMilbStandings();
        }
    },

    renderMilbSkeleton: function(panel) {
        var html = [];
        html.push('<div class="page-header"><h1>MiLB</h1></div>');

        // Sub-tabs
        html.push('<div class="sub-tabs" id="milb-sub-tabs">');
        html.push('<button class="sub-tab active" data-subtab="schedule">Scores &amp; Schedule</button>');
        html.push('<button class="sub-tab" data-subtab="team-profile">Team Profile</button>');
        html.push('<button class="sub-tab" data-subtab="postseason">Postseason</button>');
        html.push('</div>');

        // Panel 1: Schedule
        html.push('<div id="milb-panel-schedule" class="sub-panel">');
        html.push(this.renderLevelPills('milb-sched-pills'));
        html.push('<div class="mlb-date-nav">');
        html.push('<button class="btn btn-secondary btn-sm" id="milb-prev">&larr; Prev</button>');
        html.push('<span class="mlb-date-label" id="milb-date-label"></span>');
        html.push('<button class="btn btn-secondary btn-sm" id="milb-next">Next &rarr;</button>');
        html.push('<button class="btn btn-primary btn-sm" id="milb-today" style="margin-left:12px;">Today</button>');
        html.push('</div>');
        html.push('<div id="milb-schedule"></div>');
        html.push('<div class="mlb-standings-section">');
        html.push('<h2 class="mlb-section-title">Standings</h2>');
        html.push('<div id="milb-standings" class="mlb-standings-container"></div>');
        html.push('</div>');
        html.push('</div>');

        // Panel 2: Team Profile
        html.push('<div id="milb-panel-team-profile" class="sub-panel" style="display:none;">');
        html.push(this.renderLevelPills('milb-profile-pills'));
        html.push('<div id="milb-team-profile-content"></div>');
        html.push('</div>');

        // Panel 3: Postseason
        html.push('<div id="milb-panel-postseason" class="sub-panel" style="display:none;">');
        html.push(this.renderLevelPills('milb-ps-pills'));
        html.push('<div class="mlb-ps-inner-tabs" id="milb-ps-tabs">');
        html.push('<button class="mlb-ps-tab active" data-pstab="last-season">Last Season</button>');
        html.push('<button class="mlb-ps-tab" data-pstab="current-season">Current Season</button>');
        html.push('</div>');
        html.push('<div id="milb-postseason-content"></div>');
        html.push('</div>');

        panel.innerHTML = html.join('');

        // Wire sub-tabs
        var self = this;
        var tabs = document.querySelectorAll('#milb-sub-tabs .sub-tab');
        for (var i = 0; i < tabs.length; i++) {
            (function(btn) {
                btn.addEventListener('click', function() {
                    self.switchMilbView(btn.dataset.subtab);
                });
            })(tabs[i]);
        }

        // Wire date nav
        document.getElementById('milb-prev').addEventListener('click', function() { self.milbNavDate(-1); });
        document.getElementById('milb-next').addEventListener('click', function() { self.milbNavDate(1); });
        document.getElementById('milb-today').addEventListener('click', function() {
            self.milbCurrentDate = self.todayStr();
            self.loadMilbSchedule(self.milbCurrentDate);
        });

        // Wire all level pills
        this.wireLevelPills();
    },

    renderLevelPills: function(id) {
        var h = ['<div class="milb-level-pills" id="' + id + '">'];
        for (var i = 0; i < this.MILB_LEVELS.length; i++) {
            var lv = this.MILB_LEVELS[i];
            var active = lv.sportId === this.milbLevel ? ' active' : '';
            h.push('<button class="milb-level-pill' + active + '" data-sportid="' + lv.sportId + '">' + lv.label + '</button>');
        }
        h.push('</div>');
        return h.join('');
    },

    wireLevelPills: function() {
        var self = this;
        var pills = document.querySelectorAll('.milb-level-pill');
        for (var i = 0; i < pills.length; i++) {
            (function(btn) {
                btn.addEventListener('click', function() {
                    self.switchMilbLevel(parseInt(btn.dataset.sportid));
                });
            })(pills[i]);
        }
    },

    switchMilbLevel: function(sportId) {
        this.milbLevel = sportId;
        this.milbTeamsList = null;
        this.milbPostseasonData = null;

        // Update all pill groups
        var pills = document.querySelectorAll('.milb-level-pill');
        for (var i = 0; i < pills.length; i++) {
            pills[i].classList.toggle('active', parseInt(pills[i].dataset.sportid) === sportId);
        }

        // Reload current view
        if (this.milbCurrentView === 'team-profile') {
            this.milbSelectedTeamId = null;
            this.loadMilbTeamProfile(null);
        } else if (this.milbCurrentView === 'postseason') {
            this.loadMilbPostseason();
        } else {
            this.loadMilbSchedule(this.milbCurrentDate);
            this.loadMilbStandings();
        }
    },

    switchMilbView: function(view) {
        this.milbCurrentView = view;
        var tabs = document.querySelectorAll('#milb-sub-tabs .sub-tab');
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].dataset.subtab === view);
        }

        var panelSchedule = document.getElementById('milb-panel-schedule');
        var panelProfile = document.getElementById('milb-panel-team-profile');
        var panelPostseason = document.getElementById('milb-panel-postseason');
        if (!panelSchedule || !panelProfile || !panelPostseason) return;

        panelSchedule.style.display = 'none';
        panelProfile.style.display = 'none';
        panelPostseason.style.display = 'none';

        if (view === 'team-profile') {
            panelProfile.style.display = '';
            this.loadMilbTeamProfile(this.milbSelectedTeamId);
        } else if (view === 'postseason') {
            panelPostseason.style.display = '';
            this.loadMilbPostseason();
        } else {
            panelSchedule.style.display = '';
            this.loadMilbSchedule(this.milbCurrentDate);
            this.loadMilbStandings();
        }
    },

    milbNavDate: function(delta) {
        var parts = this.milbCurrentDate.split('-');
        var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        d.setDate(d.getDate() + delta);
        this.milbCurrentDate = d.getFullYear() + '-' +
            String(d.getMonth() + 1).padStart(2, '0') + '-' +
            String(d.getDate()).padStart(2, '0');
        this.loadMilbSchedule(this.milbCurrentDate);
    },

    // ─── MiLB Loaders ────────────────────────────────────────────

    loadMilbSchedule: function(date) {
        var self = this;
        var label = document.getElementById('milb-date-label');
        if (label) label.textContent = this.formatDateLabel(date);

        API.get('/api/mlb/schedule?date=' + date + '&sport_id=' + this.milbLevel).then(function(data) {
            self.renderScheduleInto('milb-schedule', data);
            self.checkForMilbLiveGames(data);
        }).catch(function(err) {
            var el = document.getElementById('milb-schedule');
            if (el) el.innerHTML = '<p class="text-muted" style="padding:16px;">Unable to load schedule</p>';
        });
    },

    loadMilbStandings: function() {
        var self = this;
        API.get('/api/milb/standings?sport_id=' + this.milbLevel).then(function(data) {
            self.renderStandingsGeneric('milb-standings', data.divisions || {});
        }).catch(function() {
            var el = document.getElementById('milb-standings');
            if (el) el.innerHTML = '<p class="text-muted">Unable to load standings</p>';
        });
    },

    renderStandingsGeneric: function(containerId, divisions) {
        var container = document.getElementById(containerId);
        if (!container) return;

        var keys = Object.keys(divisions);
        if (keys.length === 0) {
            container.innerHTML = '<p class="text-muted" style="padding:16px;">No standings data available</p>';
            return;
        }

        var html = [];
        for (var i = 0; i < keys.length; i++) {
            var divName = keys[i];
            var teams = divisions[divName];
            if (!teams || teams.length === 0) continue;

            var divIcons = '';
            for (var ti = 0; ti < teams.length; ti++) {
                divIcons += '<img class="mlb-standings-div-icon" src="' + teams[ti].logoUrl + '" alt="" onerror="this.style.display=\'none\'">';
            }

            html.push('<table class="mlb-standings-table">');
            html.push('<thead>');
            html.push('<tr class="mlb-division-header"><th colspan="7">' + this.escHtml(divName) + '&nbsp;&nbsp;' + divIcons + '</th></tr>');
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

        container.innerHTML = html.join('');
    },

    checkForMilbLiveGames: function(data) {
        var hasLive = false;
        var dates = Object.keys(data);
        for (var i = 0; i < dates.length; i++) {
            var section = data[dates[i]];
            if (!section || !section.games) continue;
            for (var j = 0; j < section.games.length; j++) {
                if (section.games[j].isLive) { hasLive = true; break; }
            }
            if (hasLive) break;
        }

        if (hasLive && !this.milbLiveTimer) {
            this.startMilbLivePolling();
        } else if (!hasLive && this.milbLiveTimer) {
            this.stopMilbLivePolling();
        }
    },

    startMilbLivePolling: function() {
        var self = this;
        this.milbLiveTimer = setInterval(function() {
            var panel = document.getElementById('tab-milb');
            if (panel && panel.classList.contains('active')) {
                self.loadMilbSchedule(self.milbCurrentDate);
            }
        }, 60000);
    },

    stopMilbLivePolling: function() {
        if (this.milbLiveTimer) {
            clearInterval(this.milbLiveTimer);
            this.milbLiveTimer = null;
        }
    },

    // ─── MiLB Team Profile ───────────────────────────────────────

    loadMilbTeamProfile: function(mlbId) {
        var container = document.getElementById('milb-team-profile-content');
        if (!container) return;

        // Build dropdown if needed
        if (!this.milbTeamsList) {
            var self = this;
            container.innerHTML = '<div style="padding:40px;text-align:center;color:#888;">Loading teams...</div>';
            API.get('/api/milb/teams?sport_id=' + this.milbLevel).then(function(data) {
                self.milbTeamsList = data.teams || [];
                self.renderMilbTeamSelector(container, mlbId);
            }).catch(function() {
                container.innerHTML = '<p class="text-muted" style="padding:16px;">Unable to load teams</p>';
            });
            return;
        }

        this.renderMilbTeamSelector(container, mlbId);
    },

    renderMilbTeamSelector: function(container, mlbId) {
        var self = this;
        var teams = this.milbTeamsList;
        if (!teams || teams.length === 0) {
            container.innerHTML = '<p class="text-muted" style="padding:16px;">No teams available</p>';
            return;
        }

        // Get unique leagues for filter
        var leagueMap = {};
        for (var i = 0; i < teams.length; i++) {
            if (teams[i].league) leagueMap[teams[i].league] = true;
        }
        var leagues = Object.keys(leagueMap).sort();

        // If no team selected, pick first
        if (!mlbId) mlbId = teams[0].mlb_id;
        this.milbSelectedTeamId = mlbId;

        // Find selected team's league for filter
        var selectedLeague = 'All';
        for (var i = 0; i < teams.length; i++) {
            if (teams[i].mlb_id === mlbId) { selectedLeague = teams[i].league; break; }
        }

        // Build selector HTML
        var h = [];
        h.push('<div class="mlb-profile-header" style="margin-bottom:16px;">');
        h.push('<select id="milb-league-filter" class="mlb-dropdown">');
        h.push('<option value="All">All Leagues</option>');
        for (var i = 0; i < leagues.length; i++) {
            h.push('<option value="' + this.escHtml(leagues[i]) + '"' + (leagues[i] === selectedLeague ? ' selected' : '') + '>' + this.escHtml(leagues[i]) + '</option>');
        }
        h.push('</select>');
        h.push('<select id="milb-team-dropdown" class="mlb-dropdown" style="margin-left:8px;"></select>');
        h.push('</div>');
        h.push('<div id="milb-team-detail"></div>');
        container.innerHTML = h.join('');

        // Wire league filter
        var leagueSelect = document.getElementById('milb-league-filter');
        leagueSelect.onchange = function() {
            self.filterMilbTeams(leagueSelect.value, self.milbSelectedTeamId);
        };

        this.filterMilbTeams(selectedLeague, mlbId);
    },

    filterMilbTeams: function(leagueFilter, selectedMlbId) {
        var self = this;
        var select = document.getElementById('milb-team-dropdown');
        if (!select || !this.milbTeamsList) return;

        var filtered = this.milbTeamsList;
        if (leagueFilter && leagueFilter !== 'All') {
            filtered = this.milbTeamsList.filter(function(t) { return t.league === leagueFilter; });
        }

        select.innerHTML = '';
        var hasSelected = false;
        for (var i = 0; i < filtered.length; i++) {
            var t = filtered[i];
            var opt = document.createElement('option');
            opt.value = t.mlb_id;
            opt.textContent = t.name;
            if (t.mlb_id === selectedMlbId) { opt.selected = true; hasSelected = true; }
            select.appendChild(opt);
        }

        if (!hasSelected && filtered.length > 0) {
            select.options[0].selected = true;
            selectedMlbId = parseInt(select.options[0].value);
        }

        select.onchange = function() {
            var id = parseInt(select.value);
            self.milbSelectedTeamId = id;
            self.fetchMilbTeamProfile(id);
        };

        this.milbSelectedTeamId = selectedMlbId;
        this.fetchMilbTeamProfile(selectedMlbId);
    },

    fetchMilbTeamProfile: function(mlbId) {
        if (!mlbId) return;
        var detail = document.getElementById('milb-team-detail');
        if (!detail) return;
        detail.innerHTML = '<div style="padding:40px;text-align:center;color:#888;">Loading team profile...</div>';

        var self = this;
        Promise.all([
            API.get('/api/mlb/team-profile?team_id=' + mlbId),
            API.get('/api/mlb/team-affiliates?team_id=' + mlbId),
            API.get('/api/mlb/schedule?team_id=' + mlbId + '&sport_id=' + this.milbLevel),
            API.get('/api/mlb/team-roster?team_id=' + mlbId)
        ]).then(function(results) {
            self.renderMilbTeamDetail(detail, results[0], results[1], results[2], results[3]);
        }).catch(function(err) {
            detail.innerHTML = '<p style="padding:20px;color:#c62828;">Failed to load team profile</p>';
        });
    },

    renderMilbTeamDetail: function(container, profile, affiliatesData, scheduleData, rosterData) {
        // Reuse the MLB team profile rendering into a different container
        var origContainer = document.getElementById('mlb-team-profile-content');
        // Temporarily set the target container, render, then restore
        var h = [];
        var p = profile;

        h.push('<div class="mlb-profile-card">');

        // Header
        h.push('<div class="mlb-profile-header-info">');
        if (p.logoUrl) {
            h.push('<img class="mlb-profile-logo" src="' + p.logoUrl + '" alt="" onerror="this.style.display=\'none\'">');
        }
        h.push('<div>');
        h.push('<h2 class="mlb-profile-name">' + this.escHtml(p.name || '') + '</h2>');
        var details = [];
        if (p.league) details.push(this.escHtml(p.league));
        if (p.division) details.push(this.escHtml(p.division));
        if (p.venue) details.push(this.escHtml(p.venue));
        if (details.length) h.push('<div class="mlb-profile-details">' + details.join(' &bull; ') + '</div>');
        if (p.parentOrgName) h.push('<div class="mlb-profile-details" style="margin-top:4px;">Parent: ' + this.escHtml(p.parentOrgName) + '</div>');
        h.push('</div></div>');

        // Roster
        var roster = rosterData.roster || [];
        if (roster.length > 0) {
            h.push('<div class="mlb-profile-section">');
            h.push('<h3 class="mlb-profile-section-title" onclick="Mlb.toggleSection(this)" style="cursor:pointer;">Roster (' + roster.length + ') ▾</h3>');
            h.push('<div class="mlb-profile-section-body">');
            h.push('<table class="mlb-roster-table"><thead><tr>');
            h.push('<th>#</th><th>Name</th><th>Pos</th><th>B/T</th><th>Age</th>');
            h.push('</tr></thead><tbody>');
            for (var i = 0; i < roster.length; i++) {
                var pl = roster[i];
                h.push('<tr>');
                h.push('<td>' + this.escHtml(pl.number || '') + '</td>');
                h.push('<td>' + this.escHtml(pl.name || '') + '</td>');
                h.push('<td>' + this.escHtml(pl.position || '') + '</td>');
                h.push('<td>' + this.escHtml((pl.batSide || '') + '/' + (pl.throwHand || '')) + '</td>');
                h.push('<td>' + (pl.age || '') + '</td>');
                h.push('</tr>');
            }
            h.push('</tbody></table></div></div>');
        }

        // Recent games
        if (scheduleData) {
            var games = [];
            var dateKeys = Object.keys(scheduleData).sort().reverse();
            for (var i = 0; i < dateKeys.length; i++) {
                var section = scheduleData[dateKeys[i]];
                if (section && section.games) {
                    for (var j = 0; j < section.games.length; j++) {
                        games.push(section.games[j]);
                    }
                }
            }
            if (games.length > 0) {
                h.push('<div class="mlb-profile-section">');
                h.push('<h3 class="mlb-profile-section-title" onclick="Mlb.toggleSection(this)" style="cursor:pointer;">Recent Games ▾</h3>');
                h.push('<div class="mlb-profile-section-body"><div class="mlb-games-grid">');
                for (var i = 0; i < Math.min(games.length, 6); i++) {
                    h.push(this.renderGameCard(games[i]));
                }
                h.push('</div></div></div>');
            }
        }

        h.push('</div>');
        container.innerHTML = h.join('');
    },

    // ─── MiLB Postseason ─────────────────────────────────────────

    loadMilbPostseason: function() {
        var self = this;
        var content = document.getElementById('milb-postseason-content');
        if (!content) return;

        // Wire inner tabs (once)
        if (!this._milbPsTabsWired) {
            this._milbPsTabsWired = true;
            var btns = document.querySelectorAll('#milb-ps-tabs .mlb-ps-tab');
            for (var i = 0; i < btns.length; i++) {
                (function(btn) {
                    btn.addEventListener('click', function() {
                        self.milbPostseasonTab = btn.dataset.pstab;
                        var allBtns = document.querySelectorAll('#milb-ps-tabs .mlb-ps-tab');
                        for (var j = 0; j < allBtns.length; j++) {
                            allBtns[j].classList.toggle('active', allBtns[j] === btn);
                        }
                        self.milbPostseasonData = null;
                        self.loadMilbPostseason();
                    });
                })(btns[i]);
            }
        }

        if (!this.milbPostseasonTab) this.milbPostseasonTab = 'last-season';

        var now = new Date();
        var currentYear = now.getFullYear();
        var season = this.milbPostseasonTab === 'last-season' ? currentYear - 1 : currentYear;

        if (this.milbPostseasonData && this.milbPostseasonData.season === season && this.milbPostseasonData.sportId === this.milbLevel) {
            this.renderMilbPostseason(content, this.milbPostseasonData);
            return;
        }

        content.innerHTML = '<div style="padding:40px;text-align:center;color:#888;">Loading postseason...</div>';

        API.get('/api/mlb/postseason?season=' + season + '&sport_id=' + this.milbLevel).then(function(data) {
            data.season = season;
            data.sportId = self.milbLevel;
            self.milbPostseasonData = data;
            self.renderMilbPostseason(content, data);
        }).catch(function() {
            content.innerHTML = '<p class="text-muted" style="padding:16px;">Unable to load postseason data</p>';
        });
    },

    renderMilbPostseason: function(container, data) {
        var series = data.series || [];
        if (series.length === 0) {
            container.innerHTML = '<p class="text-muted" style="padding:24px;">No postseason data available for ' + (data.season || '') + '</p>';
            return;
        }

        var h = [];
        h.push('<h3 style="margin:16px 0;">' + (data.season || '') + ' Postseason</h3>');

        for (var i = 0; i < series.length; i++) {
            var s = series[i];
            if (!s.matchups || s.matchups.length === 0) continue;

            h.push('<div style="margin-bottom:16px;">');
            h.push('<h4 style="margin:8px 0;color:#555;">' + this.escHtml(s.roundName || s.gameType || '') + '</h4>');
            h.push('<div class="mlb-games-grid">');
            for (var j = 0; j < s.matchups.length; j++) {
                var m = s.matchups[j];
                h.push(this.renderMilbMatchup(m));
            }
            h.push('</div></div>');
        }

        container.innerHTML = h.join('');
    },

    renderMilbMatchup: function(matchup) {
        var h = [];
        h.push('<div class="mlb-game-card" style="cursor:default;">');
        h.push('<div class="mlb-game-status"><span class="mlb-status-text">' + this.escHtml(matchup.seriesStatus || '') + '</span></div>');

        var teams = [matchup.away, matchup.home];
        for (var i = 0; i < teams.length; i++) {
            var t = teams[i];
            if (!t) continue;
            var isWinner = t.wins > (i === 0 ? (matchup.home ? matchup.home.wins : 0) : (matchup.away ? matchup.away.wins : 0));
            h.push('<div class="mlb-team-row' + (isWinner ? ' mlb-winner' : '') + '">');
            if (t.logoUrl) {
                h.push('<img class="mlb-team-logo" src="' + t.logoUrl + '" alt="" onerror="this.style.display=\'none\'">');
            }
            h.push('<span class="mlb-team-name">' + this.escHtml(t.abbreviation || t.name || '') + '</span>');
            h.push('<span class="mlb-team-score">' + (t.wins !== undefined ? t.wins : '') + '</span>');
            h.push('</div>');
        }

        h.push('</div>');
        return h.join('');
    },

    // ─── Helpers ───────────────────────────────────────────────

    handLabel: function(code) {
        if (code === 'L') return 'LH';
        if (code === 'R') return 'RH';
        if (code === 'S') return 'SH';
        return '';
    },

    handBadge: function(code) {
        var label = this.handLabel(code);
        if (!label) return '';
        return ' <span class="mlb-hand-badge">' + label + '</span>';
    },

    escHtml: function(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};
