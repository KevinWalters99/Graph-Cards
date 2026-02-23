<?php
/**
 * Card Graph — MLB Schedule & Scores Controller
 *
 * Provides game schedules, live scores, standings, and game detail
 * from the free MLB Stats API. Uses file-based caching to avoid
 * excessive API calls.
 */
class MlbController
{
    private const CACHE_DIR = __DIR__ . '/../../storage/cache/';
    private const SCHEDULE_TTL = 300;  // 5 minutes
    private const LIVE_TTL = 60;       // 1 minute when games in progress
    private const PROFILE_TTL = 86400; // 24 hours — team info rarely changes
    private const POSTSEASON_TTL = 3600;  // 1 hour for completed seasons
    private const POSTSEASON_LIVE_TTL = 300; // 5 minutes during active postseason
    private const PROFILES_FILE = __DIR__ . '/../../storage/team_profiles.json';

    private const GAME_TYPE_LABELS = [
        'S' => 'Spring Training',
        'E' => 'Exhibition',
        'R' => 'Regular Season',
        'F' => 'Wild Card',
        'D' => 'Division Series',
        'L' => 'League Championship',
        'W' => 'World Series',
        'A' => 'All-Star Game',
        'C' => 'Championship',
    ];

    // ─── Schedule ─────────────────────────────────────────────────

    public function getSchedule(array $params = []): void
    {
        Auth::getUserId();

        $centerDate = $_GET['date'] ?? date('Y-m-d');
        $yesterday  = date('Y-m-d', strtotime($centerDate . ' -1 day'));
        $tomorrow   = date('Y-m-d', strtotime($centerDate . ' +1 day'));

        $sportId = (int)($_GET['sport_id'] ?? 1);
        if (!in_array($sportId, [1, 11, 12, 13, 14])) $sportId = 1;

        $cacheKey = "mlb_schedule_{$sportId}_{$yesterday}_{$tomorrow}";
        $cached   = $this->getCache($cacheKey);

        // Use shorter TTL if live games detected
        $hasLive = $cached && $this->hasLiveGames($cached);
        $ttl     = $hasLive ? self::LIVE_TTL : self::SCHEDULE_TTL;

        if (!$cached || $this->isCacheExpired($cacheKey, $ttl)) {
            $url = "https://statsapi.mlb.com/api/v1/schedule"
                 . "?sportId={$sportId}&startDate={$yesterday}&endDate={$tomorrow}"
                 . "&hydrate=broadcasts(all),linescore,team,decisions,probablePitcher,person";

            try {
                $json   = $this->mlbApiFetch($url);
                $cached = json_decode($json, true);
                $this->setCache($cacheKey, $cached);
            } catch (\Throwable $e) {
                // If API fails but we have stale cache, use it
                if ($cached) {
                    // use stale cache
                } else {
                    jsonError('MLB API unavailable: ' . $e->getMessage(), 502);
                    return;
                }
            }
        }

        $teamMap = $this->getTeamMap();
        $result  = $this->transformSchedule($cached, $teamMap, $yesterday, $centerDate, $tomorrow);

        // Optional: filter to a single team
        $filterTeam = (int)($_GET['team_id'] ?? 0);
        if ($filterTeam > 0) {
            foreach ($result as &$day) {
                $day['games'] = array_values(array_filter($day['games'], function ($g) use ($filterTeam) {
                    return ($g['away']['mlb_id'] === $filterTeam || $g['home']['mlb_id'] === $filterTeam);
                }));
            }
            unset($day);
        }

        jsonResponse($result);
    }

    // ─── Game Detail ──────────────────────────────────────────────

    public function getGameDetail(array $params = []): void
    {
        Auth::getUserId();

        $gamePk = (int)($params['id'] ?? 0);
        if (!$gamePk) {
            jsonError('Game ID required', 400);
            return;
        }

        $cacheKey = "mlb_game_{$gamePk}";
        $cached   = $this->getCache($cacheKey);

        if (!$cached || $this->isCacheExpired($cacheKey, self::LIVE_TTL)) {
            $url = "https://statsapi.mlb.com/api/v1.1/game/{$gamePk}/feed/live";
            try {
                $json   = $this->mlbApiFetch($url);
                $cached = json_decode($json, true);
                $this->setCache($cacheKey, $cached);
            } catch (\Throwable $e) {
                jsonError('Failed to fetch game data', 502);
                return;
            }
        }

        $result = $this->transformGameDetail($cached);
        jsonResponse($result);
    }

    // ─── Standings ────────────────────────────────────────────────

    public function getStandings(array $params = []): void
    {
        Auth::getUserId();
        $pdo = cg_db();

        $stmt = $pdo->query(
            "SELECT t.team_id, t.team_name, t.abbreviation, t.mlb_id, t.league, t.division,
                    ts.current_season_stats
             FROM CG_Teams t
             LEFT JOIN CG_TeamStatistics ts ON ts.team_id = t.team_id
             WHERE t.is_active = 1 AND t.mlb_id IS NOT NULL
             ORDER BY t.league, t.division, t.team_name"
        );

        $divisions = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats = $row['current_season_stats'] ? json_decode($row['current_season_stats'], true) : null;
            $league   = $row['league'] ?: 'Unknown';
            $division = $row['division'] ?: 'Unknown';
            $key = "{$league} {$division}";

            $divisions[$key][] = [
                'team_name'    => $row['team_name'],
                'abbreviation' => $row['abbreviation'],
                'mlb_id'       => (int)$row['mlb_id'],
                'league'       => $league,
                'division'     => $division,
                'logoUrl'      => "/img/teams/{$row['mlb_id']}.png",
                'wins'         => (int)($stats['wins'] ?? 0),
                'losses'       => (int)($stats['losses'] ?? 0),
                'pct'          => $stats['winning_percentage'] ?? '.000',
                'gb'           => $stats['games_back'] ?? '-',
                'streak'       => $stats['streak'] ?? '-',
                'runDiff'      => (int)($stats['run_differential'] ?? 0),
                'divRank'      => (int)($stats['division_rank'] ?? 0),
            ];
        }

        // Sort each division by division_rank
        foreach ($divisions as &$teams) {
            usort($teams, function ($a, $b) {
                return $a['divRank'] - $b['divRank'];
            });
        }

        jsonResponse(['divisions' => $divisions]);
    }

    // ─── Postseason Bracket ────────────────────────────────────────

    public function getPostseason(array $params = []): void
    {
        Auth::getUserId();

        $season = (int)($_GET['season'] ?? date('Y'));
        if ($season < 2022 || $season > (int)date('Y') + 1) {
            jsonError('Invalid season (2022+ only)', 400);
            return;
        }

        $sportId = (int)($_GET['sport_id'] ?? 1);
        if (!in_array($sportId, [1, 11, 12, 13, 14])) $sportId = 1;

        $isCurrentYear = ($season === (int)date('Y'));
        $ttl = $isCurrentYear ? self::POSTSEASON_LIVE_TTL : self::POSTSEASON_TTL;

        // 1. Fetch postseason series
        $seriesKey = "mlb_postseason_{$sportId}_{$season}";
        $seriesCached = $this->getCache($seriesKey);

        if (!$seriesCached || $this->isCacheExpired($seriesKey, $ttl)) {
            $url = "https://statsapi.mlb.com/api/v1/schedule/postseason/series"
                 . "?season={$season}&sportId={$sportId}";
            try {
                $json = $this->mlbApiFetch($url);
                $seriesCached = json_decode($json, true);
                $this->setCache($seriesKey, $seriesCached);
            } catch (\Throwable $e) {
                if (!$seriesCached) {
                    // No postseason data yet — return empty bracket
                    $seriesCached = ['series' => []];
                }
            }
        }

        // 2. Fetch standings for seed determination
        $standingsKey = "mlb_standings_season_{$season}";
        $standingsCached = $this->getCache($standingsKey);

        if (!$standingsCached || $this->isCacheExpired($standingsKey, $ttl)) {
            $url = "https://statsapi.mlb.com/api/v1/standings"
                 . "?leagueId=103,104&season={$season}"
                 . "&standingsTypes=regularSeason&hydrate=team";
            try {
                $json = $this->mlbApiFetch($url);
                $standingsCached = json_decode($json, true);
                $this->setCache($standingsKey, $standingsCached);
            } catch (\Throwable $e) {
                // Non-fatal
            }
        }

        $bracket = $this->transformPostseason($seriesCached, $standingsCached, $season);
        jsonResponse($bracket);
    }

    private function buildSeedMap(?array $standingsData): array
    {
        $seeds = ['AL' => [], 'NL' => []];
        if (!$standingsData) return $seeds;

        foreach ($standingsData['records'] ?? [] as $record) {
            $leagueId = (int)($record['league']['id'] ?? 0);
            $leagueKey = ($leagueId === 103) ? 'AL' : 'NL';

            $divWinners = [];
            $wildCards = [];

            foreach ($record['teamRecords'] ?? [] as $tr) {
                $teamId = (int)($tr['team']['id'] ?? 0);
                $divRank = (int)($tr['divisionRank'] ?? 99);
                $wins = (int)($tr['wins'] ?? 0);

                if ($divRank === 1) {
                    $divWinners[] = ['id' => $teamId, 'wins' => $wins];
                }
            }

            // Sort division winners by wins desc → seeds 1-3
            usort($divWinners, fn($a, $b) => $b['wins'] - $a['wins']);

            // Remaining teams that made playoffs are wild cards
            // Collect all non-div-winner teams sorted by wins desc
            $divWinnerIds = array_column($divWinners, 'id');
            foreach ($record['teamRecords'] ?? [] as $tr) {
                $teamId = (int)($tr['team']['id'] ?? 0);
                if (in_array($teamId, $divWinnerIds)) continue;
                $wins = (int)($tr['wins'] ?? 0);
                $wildCards[] = ['id' => $teamId, 'wins' => $wins];
            }
            usort($wildCards, fn($a, $b) => $b['wins'] - $a['wins']);

            $seed = 1;
            foreach ($divWinners as $dw) {
                $seeds[$leagueKey][$dw['id']] = $seed++;
            }
            // Only top 3 wild cards
            for ($i = 0; $i < min(3, count($wildCards)); $i++) {
                $seeds[$leagueKey][$wildCards[$i]['id']] = $seed++;
            }
        }

        return $seeds;
    }

    private function inferLeague(int $awayId, int $homeId, array $seedMap): string
    {
        if (isset($seedMap['AL'][$awayId]) || isset($seedMap['AL'][$homeId])) return 'AL';
        if (isset($seedMap['NL'][$awayId]) || isset($seedMap['NL'][$homeId])) return 'NL';

        // Fallback: query from DB
        $pdo = cg_db();
        $stmt = $pdo->prepare("SELECT league FROM CG_Teams WHERE mlb_id = ? LIMIT 1");
        $stmt->execute([$awayId]);
        $league = $stmt->fetchColumn();
        return ($league === 'AL') ? 'AL' : 'NL';
    }

    private function buildBracketTeam(int $teamId, array $teamMap, array $seedMap, ?string $league): array
    {
        $info = $teamMap[$teamId] ?? null;
        $seed = null;
        if ($league) {
            $seed = $seedMap[$league][$teamId] ?? null;
        }
        if ($seed === null) {
            // Check both leagues
            $seed = $seedMap['AL'][$teamId] ?? $seedMap['NL'][$teamId] ?? null;
        }

        return [
            'mlb_id'       => $teamId,
            'name'         => $info ? $info['name'] : 'TBD',
            'abbreviation' => $info ? $info['abbreviation'] : '???',
            'logoUrl'      => "/img/teams/{$teamId}.png",
            'seed'         => $seed,
            'isWildCard'   => ($seed !== null && $seed >= 4),
        ];
    }

    private function transformSeries(array $entry, array $teamMap, array $seedMap, string $league): array
    {
        $games = $entry['games'] ?? [];
        if (empty($games)) {
            return ['status' => 'scheduled', 'topTeam' => null, 'bottomTeam' => null,
                    'topWins' => 0, 'bottomWins' => 0, 'winnerId' => null, 'winner' => null,
                    'description' => '', 'gamesInSeries' => 0, 'games' => []];
        }

        $lastGame = end($games);

        $awayTeam = $lastGame['teams']['away'] ?? [];
        $homeTeam = $lastGame['teams']['home'] ?? [];
        $awayId = (int)($awayTeam['team']['id'] ?? 0);
        $homeId = (int)($homeTeam['team']['id'] ?? 0);

        // Series record from last game's leagueRecord
        $awayWins = (int)($awayTeam['leagueRecord']['wins'] ?? 0);
        $homeWins = (int)($homeTeam['leagueRecord']['wins'] ?? 0);
        $gamesInSeries = (int)($lastGame['gamesInSeries'] ?? 0);

        // Determine seeds for bracket placement
        $awaySeed = $seedMap[$league][$awayId] ?? $seedMap['AL'][$awayId] ?? $seedMap['NL'][$awayId] ?? 99;
        $homeSeed = $seedMap[$league][$homeId] ?? $seedMap['AL'][$homeId] ?? $seedMap['NL'][$homeId] ?? 99;

        // Higher seed (lower number) = topTeam
        if ($homeSeed <= $awaySeed) {
            $topId = $homeId; $bottomId = $awayId;
            $topWins = $homeWins; $bottomWins = $awayWins;
        } else {
            $topId = $awayId; $bottomId = $homeId;
            $topWins = $awayWins; $bottomWins = $homeWins;
        }

        // Determine winner
        $winnerId = null;
        $seriesStatus = 'in_progress';
        $maxWins = ($gamesInSeries > 0) ? (int)ceil($gamesInSeries / 2) : 1;

        if ($topWins >= $maxWins) {
            $winnerId = $topId;
            $seriesStatus = 'complete';
        } elseif ($bottomWins >= $maxWins) {
            $winnerId = $bottomId;
            $seriesStatus = 'complete';
        }

        // Check if all games are scheduled (no results yet)
        $allScheduled = true;
        foreach ($games as $g) {
            if (($g['status']['abstractGameState'] ?? '') !== 'Preview' &&
                ($g['status']['abstractGameState'] ?? '') !== 'Scheduled') {
                $allScheduled = false;
                break;
            }
        }
        if ($allScheduled && !$winnerId) $seriesStatus = 'scheduled';

        return [
            'description'   => $lastGame['seriesDescription'] ?? '',
            'gamesInSeries' => $gamesInSeries,
            'status'        => $seriesStatus,
            'topTeam'       => $this->buildBracketTeam($topId, $teamMap, $seedMap, $league),
            'bottomTeam'    => $this->buildBracketTeam($bottomId, $teamMap, $seedMap, $league),
            'topWins'       => $topWins,
            'bottomWins'    => $bottomWins,
            'winnerId'      => $winnerId,
            'winner'        => $winnerId ? $this->buildBracketTeam($winnerId, $teamMap, $seedMap, $league) : null,
            'games'         => array_map(function($g) {
                return [
                    'gamePk'    => $g['gamePk'] ?? null,
                    'gameNum'   => $g['seriesGameNumber'] ?? 0,
                    'awayId'    => (int)($g['teams']['away']['team']['id'] ?? 0),
                    'homeId'    => (int)($g['teams']['home']['team']['id'] ?? 0),
                    'awayScore' => $g['teams']['away']['score'] ?? null,
                    'homeScore' => $g['teams']['home']['score'] ?? null,
                    'status'    => $g['status']['abstractGameState'] ?? 'Scheduled',
                ];
            }, $games),
        ];
    }

    private function buildPlayoffTeamsList(?array $standingsData, array $teamMap): array
    {
        $result = ['AL' => [], 'NL' => []];
        if (!$standingsData) return $result;

        foreach ($standingsData['records'] ?? [] as $record) {
            $leagueId = (int)($record['league']['id'] ?? 0);
            $leagueKey = ($leagueId === 103) ? 'AL' : 'NL';

            foreach ($record['teamRecords'] ?? [] as $tr) {
                $teamId = (int)($tr['team']['id'] ?? 0);
                $info = $teamMap[$teamId] ?? null;
                $divRank = (int)($tr['divisionRank'] ?? 99);

                $result[$leagueKey][] = [
                    'mlb_id'       => $teamId,
                    'name'         => $info ? $info['name'] : ($tr['team']['name'] ?? ''),
                    'abbreviation' => $info ? $info['abbreviation'] : '',
                    'logoUrl'      => "/img/teams/{$teamId}.png",
                    'wins'         => (int)($tr['wins'] ?? 0),
                    'losses'       => (int)($tr['losses'] ?? 0),
                    'divRank'      => $divRank,
                    'division'     => $tr['team']['division']['name'] ?? '',
                    'clinched'     => (bool)($tr['clinched'] ?? false),
                    'clinchType'   => $tr['clinchIndicator'] ?? '',
                    'eliminated'   => ($tr['eliminationNumber'] ?? '') === 'E',
                ];
            }
        }

        return $result;
    }

    private function transformPostseason(?array $seriesData, ?array $standingsData, int $season): array
    {
        $teamMap = $this->getTeamMap();
        $seedMap = $this->buildSeedMap($standingsData);
        $playoffTeams = $this->buildPlayoffTeamsList($standingsData, $teamMap);

        $bracket = [
            'season'       => $season,
            'seeds'        => $seedMap,
            'playoffTeams' => $playoffTeams,
            'rounds'       => [
                'wildCard'    => ['AL' => [], 'NL' => []],
                'divSeries'   => ['AL' => [], 'NL' => []],
                'lcs'         => ['AL' => null, 'NL' => null],
                'worldSeries' => null,
            ],
            'hasStarted' => false,
            'isComplete' => false,
        ];

        foreach ($seriesData['series'] ?? [] as $entry) {
            $games = $entry['games'] ?? [];
            if (empty($games)) continue;

            $bracket['hasStarted'] = true;

            $firstGame = $games[0];
            $gameType = $firstGame['gameType'] ?? '';
            $awayId = (int)($firstGame['teams']['away']['team']['id'] ?? 0);
            $homeId = (int)($firstGame['teams']['home']['team']['id'] ?? 0);

            // World Series is cross-league
            if ($gameType === 'W') {
                $transformed = $this->transformSeries($entry, $teamMap, $seedMap, 'AL');
                $bracket['rounds']['worldSeries'] = $transformed;
                if ($transformed['winnerId']) {
                    $bracket['isComplete'] = true;
                }
                continue;
            }

            $league = $this->inferLeague($awayId, $homeId, $seedMap);
            $transformed = $this->transformSeries($entry, $teamMap, $seedMap, $league);

            switch ($gameType) {
                case 'F':
                    $bracket['rounds']['wildCard'][$league][] = $transformed;
                    break;
                case 'D':
                    $bracket['rounds']['divSeries'][$league][] = $transformed;
                    break;
                case 'L':
                    $bracket['rounds']['lcs'][$league] = $transformed;
                    break;
            }
        }

        return $bracket;
    }

    // ─── Wild Card Standings ───────────────────────────────────────

    public function getWildCardStandings(array $params = []): void
    {
        Auth::getUserId();

        $cacheKey = 'mlb_wildcard';
        $cached   = $this->getCache($cacheKey);

        if (!$cached || $this->isCacheExpired($cacheKey, self::SCHEDULE_TTL)) {
            $url = "https://statsapi.mlb.com/api/v1/standings?leagueId=103,104&season="
                 . date('Y') . "&standingsTypes=wildCard&hydrate=team";
            try {
                $json   = $this->mlbApiFetch($url);
                $cached = json_decode($json, true);
                $this->setCache($cacheKey, $cached);
            } catch (\Throwable $e) {
                if (!$cached) {
                    jsonError('MLB API unavailable: ' . $e->getMessage(), 502);
                    return;
                }
            }
        }

        $result = [];
        foreach ($cached['records'] ?? [] as $record) {
            $league = $record['league']['name'] ?? 'Unknown';
            $teams  = [];
            foreach ($record['teamRecords'] ?? [] as $tr) {
                $team = $tr['team'] ?? [];
                $teams[] = [
                    'mlb_id'     => (int)($team['id'] ?? 0),
                    'name'       => $team['name'] ?? '',
                    'wins'       => (int)($tr['wins'] ?? 0),
                    'losses'     => (int)($tr['losses'] ?? 0),
                    'pct'        => $tr['leagueRecord']['pct'] ?? '.000',
                    'gb'         => $tr['wildCardGamesBack'] ?? '-',
                    'wcRank'     => (int)($tr['wildCardRank'] ?? 0),
                    'streak'     => $tr['streak']['streakCode'] ?? '-',
                    'logoUrl'    => "/img/teams/" . ($team['id'] ?? 0) . ".png",
                    'eliminated' => ($tr['wildCardEliminationNumber'] ?? '') === 'E',
                ];
            }
            $result[] = ['league' => $league, 'teams' => $teams];
        }

        jsonResponse(['standings' => $result]);
    }

    // ─── MiLB Standings (API-based) ──────────────────────────────

    public function getMilbStandings(array $params = []): void
    {
        Auth::getUserId();

        $sportId = (int)($_GET['sport_id'] ?? 11);
        if (!in_array($sportId, [11, 12, 13, 14])) {
            jsonError('Invalid sport_id', 400);
            return;
        }

        $season   = date('Y');
        $cacheKey = "milb_standings_{$sportId}_{$season}";
        $cached   = $this->getCache($cacheKey);

        if (!$cached || $this->isCacheExpired($cacheKey, self::SCHEDULE_TTL)) {
            $url = "https://statsapi.mlb.com/api/v1/standings"
                 . "?sportId={$sportId}&season={$season}"
                 . "&standingsTypes=regularSeason&hydrate=team";
            try {
                $json   = $this->mlbApiFetch($url);
                $cached = json_decode($json, true);
                $this->setCache($cacheKey, $cached);
            } catch (\Throwable $e) {
                if (!$cached) {
                    jsonError('MLB API unavailable: ' . $e->getMessage(), 502);
                    return;
                }
            }
        }

        $divisions = [];
        foreach ($cached['records'] ?? [] as $record) {
            $divName = $record['division']['name'] ?? 'Unknown';
            foreach ($record['teamRecords'] ?? [] as $tr) {
                $team = $tr['team'] ?? [];
                $teamId = (int)($team['id'] ?? 0);
                $divisions[$divName][] = [
                    'team_name'    => $team['name'] ?? '',
                    'abbreviation' => $team['abbreviation'] ?? '',
                    'mlb_id'       => $teamId,
                    'logoUrl'      => "/img/teams/{$teamId}.png",
                    'wins'         => (int)($tr['wins'] ?? 0),
                    'losses'       => (int)($tr['losses'] ?? 0),
                    'pct'          => $tr['leagueRecord']['pct'] ?? '.000',
                    'gb'           => $tr['gamesBack'] ?? '-',
                    'streak'       => $tr['streak']['streakCode'] ?? '-',
                    'runDiff'      => (int)($tr['runDifferential'] ?? 0),
                    'divRank'      => (int)($tr['divisionRank'] ?? 0),
                ];
            }
        }

        foreach ($divisions as &$teams) {
            usort($teams, function ($a, $b) {
                return $a['divRank'] - $b['divRank'];
            });
        }

        jsonResponse(['divisions' => $divisions]);
    }

    // ─── MiLB Teams (API-based) ──────────────────────────────────

    public function getMilbTeams(array $params = []): void
    {
        Auth::getUserId();

        $sportId = (int)($_GET['sport_id'] ?? 11);
        if (!in_array($sportId, [11, 12, 13, 14])) {
            jsonError('Invalid sport_id', 400);
            return;
        }

        $season   = date('Y');
        $cacheKey = "milb_teams_{$sportId}";
        $cached   = $this->getCache($cacheKey);

        if (!$cached || $this->isCacheExpired($cacheKey, self::PROFILE_TTL)) {
            $url = "https://statsapi.mlb.com/api/v1/teams"
                 . "?sportId={$sportId}&season={$season}";
            try {
                $json   = $this->mlbApiFetch($url);
                $cached = json_decode($json, true);
                $this->setCache($cacheKey, $cached);
            } catch (\Throwable $e) {
                if (!$cached) {
                    jsonError('MLB API unavailable: ' . $e->getMessage(), 502);
                    return;
                }
            }
        }

        $teams = [];
        foreach ($cached['teams'] ?? [] as $t) {
            $teamId = (int)($t['id'] ?? 0);
            $teams[] = [
                'mlb_id'        => $teamId,
                'name'          => $t['name'] ?? '',
                'abbreviation'  => $t['abbreviation'] ?? '',
                'league'        => $t['league']['name'] ?? '',
                'division'      => $t['division']['name'] ?? '',
                'parentOrgName' => $t['parentOrgName'] ?? '',
                'logoUrl'       => "/img/teams/{$teamId}.png",
            ];
        }

        usort($teams, function ($a, $b) {
            return $a['name'] <=> $b['name'];
        });

        jsonResponse(['teams' => $teams]);
    }

    // ─── Team Profile ────────────────────────────────────────────

    public function getTeamProfile(array $params = []): void
    {
        Auth::getUserId();

        $teamId = (int)($_GET['team_id'] ?? 145);
        if ($teamId < 100 || $teamId > 999) {
            jsonError('Invalid team ID', 400);
            return;
        }

        $cacheKey = "mlb_team_{$teamId}";
        $apiData  = $this->getCache($cacheKey);

        if (!$apiData || $this->isCacheExpired($cacheKey, self::PROFILE_TTL)) {
            $url = "https://statsapi.mlb.com/api/v1/teams/{$teamId}";
            try {
                $json    = $this->mlbApiFetch($url);
                $apiData = json_decode($json, true);
                $this->setCache($cacheKey, $apiData);
            } catch (\Throwable $e) {
                if (!$apiData) {
                    jsonError('MLB API unavailable: ' . $e->getMessage(), 502);
                    return;
                }
            }
        }

        $team = $apiData['teams'][0] ?? [];

        // Load static profile data
        $profiles = [];
        if (file_exists(self::PROFILES_FILE)) {
            $profiles = json_decode(file_get_contents(self::PROFILES_FILE), true) ?: [];
        }
        $profile = $profiles[(string)$teamId] ?? [];

        jsonResponse([
            'mlb_id'          => $teamId,
            'name'            => $team['name'] ?? '',
            'shortName'       => $team['shortName'] ?? '',
            'abbreviation'    => $team['abbreviation'] ?? '',
            'locationName'    => $team['locationName'] ?? '',
            'firstYearOfPlay' => $team['firstYearOfPlay'] ?? '',
            'league'          => $team['league']['name'] ?? '',
            'leagueId'        => (int)($team['league']['id'] ?? 0),
            'division'        => $team['division']['name'] ?? '',
            'divisionId'      => (int)($team['division']['id'] ?? 0),
            'venue'           => $team['venue']['name'] ?? '',
            'logoUrl'         => "/img/teams/{$teamId}.png",
            'logoLargeUrl'    => "/img/teams/large/{$teamId}.png",
            'description'     => $profile['description'] ?? '',
            'stadium'         => $profile['stadium'] ?? null,
            'tvChannels'      => $profile['tvChannels'] ?? [],
            'ticketUrl'       => $profile['ticketUrl'] ?? '',
            'championships'   => $profile['championships'] ?? null,
        ]);
    }

    // ─── Team Affiliates ─────────────────────────────────────────

    public function getTeamAffiliates(array $params = []): void
    {
        Auth::getUserId();

        $teamId = (int)($_GET['team_id'] ?? 145);
        if ($teamId < 100 || $teamId > 999) {
            jsonError('Invalid team ID', 400);
            return;
        }

        $cacheKey = "mlb_affiliates_{$teamId}";
        $apiData  = $this->getCache($cacheKey);

        if (!$apiData || $this->isCacheExpired($cacheKey, self::PROFILE_TTL)) {
            $url = "https://statsapi.mlb.com/api/v1/teams/{$teamId}/affiliates";
            try {
                $json    = $this->mlbApiFetch($url);
                $apiData = json_decode($json, true);
                $this->setCache($cacheKey, $apiData);
            } catch (\Throwable $e) {
                if (!$apiData) {
                    jsonError('MLB API unavailable: ' . $e->getMessage(), 502);
                    return;
                }
            }
        }

        $sportLabels = [
            11 => 'Triple-A', 12 => 'Double-A', 13 => 'High-A',
            14 => 'Single-A', 15 => 'Rookie',   16 => 'Rookie',
        ];

        $affiliates = [];
        foreach ($apiData['teams'] ?? [] as $t) {
            $sportId = (int)($t['sport']['id'] ?? 0);
            if ($sportId === 1) continue; // Skip MLB parent
            $level = $sportLabels[$sportId] ?? ($t['sport']['name'] ?? 'Other');

            $affiliates[] = [
                'mlb_id'    => (int)($t['id'] ?? 0),
                'name'      => $t['name'] ?? '',
                'shortName' => $t['shortName'] ?? $t['name'] ?? '',
                'level'     => $level,
                'sportId'   => $sportId,
                'venue'     => $t['venue']['name'] ?? '',
                'league'    => $t['league']['name'] ?? '',
                'logoUrl'   => "/img/teams/" . ($t['id'] ?? 0) . ".png",
            ];
        }

        usort($affiliates, function ($a, $b) {
            return ($a['sportId'] ?: 99) - ($b['sportId'] ?: 99);
        });

        jsonResponse(['affiliates' => $affiliates]);
    }

    // ─── Team Roster ─────────────────────────────────────────────

    public function getTeamRoster(array $params = []): void
    {
        Auth::getUserId();

        $teamId = (int)($_GET['team_id'] ?? 145);
        if ($teamId < 100 || $teamId > 999) {
            jsonError('Invalid team ID', 400);
            return;
        }

        $cacheKey = "mlb_roster_{$teamId}";
        $cached   = $this->getCache($cacheKey);

        if (!$cached || $this->isCacheExpired($cacheKey, self::PROFILE_TTL)) {
            $url = "https://statsapi.mlb.com/api/v1/teams/{$teamId}/roster?rosterType=active&hydrate=person(stats(type=season))";
            try {
                $json   = $this->mlbApiFetch($url);
                $cached = json_decode($json, true);
                $this->setCache($cacheKey, $cached);
            } catch (\Throwable $e) {
                if (!$cached) {
                    jsonError('MLB API unavailable: ' . $e->getMessage(), 502);
                    return;
                }
            }
        }

        $roster = [];
        foreach ($cached['roster'] ?? [] as $p) {
            $person = $p['person'] ?? [];
            $pos    = $p['position'] ?? [];

            // Get season stats from first stat split
            $stats = [];
            foreach ($person['stats'] ?? [] as $statGroup) {
                $splits = $statGroup['splits'] ?? [];
                if (!empty($splits)) {
                    $stats = $splits[0]['stat'] ?? [];
                    break;
                }
            }

            $roster[] = [
                'id'       => (int)($person['id'] ?? 0),
                'name'     => $person['fullName'] ?? '',
                'number'   => $p['jerseyNumber'] ?? '',
                'position' => $pos['abbreviation'] ?? '',
                'posType'  => $pos['type'] ?? '',
                'bats'     => $person['batSide']['code'] ?? '',
                'throws'   => $person['pitchHand']['code'] ?? '',
                'age'      => (int)($person['currentAge'] ?? 0),
                'stats'    => $stats,
            ];
        }

        // Sort: Pitchers first (by number), then position players (by number)
        usort($roster, function ($a, $b) {
            $aIsP = $a['posType'] === 'Pitcher' ? 0 : 1;
            $bIsP = $b['posType'] === 'Pitcher' ? 0 : 1;
            if ($aIsP !== $bIsP) return $aIsP - $bIsP;
            return strcmp($a['number'], $b['number']);
        });

        jsonResponse(['roster' => $roster]);
    }

    // ─── Private Helpers ──────────────────────────────────────────

    private function getTeamMap(): array
    {
        $pdo  = cg_db();
        $stmt = $pdo->query("SELECT mlb_id, team_name, abbreviation FROM CG_Teams WHERE mlb_id IS NOT NULL");
        $map  = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['mlb_id']] = [
                'name'         => $row['team_name'],
                'abbreviation' => $row['abbreviation'],
            ];
        }
        return $map;
    }

    private function transformSchedule(array $apiData, array $teamMap, string $yesterday, string $today, string $tomorrow): array
    {
        $dayLabels = [
            $yesterday => 'Yesterday',
            $today     => 'Today',
            $tomorrow  => 'Tomorrow',
        ];

        $result = [];
        foreach ($dayLabels as $date => $label) {
            $result[$date] = [
                'date'  => $date,
                'label' => $label,
                'games' => [],
            ];
        }

        foreach ($apiData['dates'] ?? [] as $dateObj) {
            $date = $dateObj['date'] ?? '';
            if (!isset($result[$date])) continue;

            foreach ($dateObj['games'] ?? [] as $g) {
                $result[$date]['games'][] = $this->transformGame($g, $teamMap);
            }
        }

        return $result;
    }

    private function transformGame(array $g, array $teamMap): array
    {
        $status   = $g['status'] ?? [];
        $abstract = $status['abstractGameState'] ?? 'Scheduled';
        $detailed = $status['detailedState'] ?? $abstract;
        $gameType = $g['gameType'] ?? 'R';

        $isFinal      = $abstract === 'Final';
        $isLive        = $abstract === 'Live';
        $isScheduled   = !$isFinal && !$isLive;

        // Time conversion: UTC → CT
        $gameDate  = $g['gameDate'] ?? '';
        $startTime = '';
        if ($gameDate) {
            try {
                $dt = new DateTime($gameDate, new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone('America/Chicago'));
                $startTime = $dt->format('g:i A') . ' CT';
            } catch (\Throwable $e) {
                $startTime = '';
            }
        }

        // Linescore
        $linescore     = $g['linescore'] ?? [];
        $currentInning = $linescore['currentInning'] ?? null;
        $inningState   = $linescore['inningState'] ?? null;   // Top, Middle, Bottom, End
        $inningOrdinal = $linescore['currentInningOrdinal'] ?? null;
        $outs          = $linescore['outs'] ?? null;

        // Base runners (for live games)
        $offense  = $linescore['offense'] ?? [];
        $onFirst  = isset($offense['first']);
        $onSecond = isset($offense['second']);
        $onThird  = isset($offense['third']);
        $isTopInning = ($inningState === 'Top');

        // Innings detail
        $innings = [];
        foreach ($linescore['innings'] ?? [] as $inn) {
            $innings[] = [
                'away' => $inn['away']['runs'] ?? null,
                'home' => $inn['home']['runs'] ?? null,
            ];
        }

        // Teams
        $away = $this->transformTeamSide($g['teams']['away'] ?? [], $teamMap, $isFinal, $g);
        $home = $this->transformTeamSide($g['teams']['home'] ?? [], $teamMap, $isFinal, $g);

        // Mark winner
        if ($isFinal && $away['score'] !== null && $home['score'] !== null) {
            $away['isWinner'] = $away['score'] > $home['score'];
            $home['isWinner'] = $home['score'] > $away['score'];
        }

        // TV Broadcasts
        $tvChannels = [];
        foreach ($g['broadcasts'] ?? [] as $bc) {
            if (($bc['type'] ?? '') === 'TV') {
                $name = $bc['callSign'] ?? $bc['name'] ?? '';
                if ($name && !in_array($name, $tvChannels)) {
                    $tvChannels[] = $name;
                }
            }
        }

        // W/L/S Decisions (for Final games)
        $decisions = [];
        if ($isFinal && isset($g['decisions'])) {
            $dec = $g['decisions'];
            if (isset($dec['winner'])) {
                $decisions['winner'] = $dec['winner']['fullName'] ?? '';
                $decisions['winnerHand'] = $dec['winner']['pitchHand']['code'] ?? null;
            }
            if (isset($dec['loser'])) {
                $decisions['loser'] = $dec['loser']['fullName'] ?? '';
                $decisions['loserHand'] = $dec['loser']['pitchHand']['code'] ?? null;
            }
            if (isset($dec['save'])) {
                $decisions['save'] = $dec['save']['fullName'] ?? '';
                $decisions['saveHand'] = $dec['save']['pitchHand']['code'] ?? null;
            }
        }

        // Probable pitchers (for Scheduled games)
        $probables = [];
        if ($isScheduled) {
            $awayProb = $g['teams']['away']['probablePitcher'] ?? null;
            $homeProb = $g['teams']['home']['probablePitcher'] ?? null;
            if ($awayProb) {
                $probables['away'] = $awayProb['fullName'] ?? '';
                $probables['awayHand'] = $awayProb['pitchHand']['code'] ?? null;
            }
            if ($homeProb) {
                $probables['home'] = $homeProb['fullName'] ?? '';
                $probables['homeHand'] = $homeProb['pitchHand']['code'] ?? null;
            }
        }

        return [
            'gamePk'            => $g['gamePk'] ?? null,
            'gameType'          => $gameType,
            'gameTypeLabel'     => self::GAME_TYPE_LABELS[$gameType] ?? $gameType,
            'startTime'         => $startTime,
            'status'            => $detailed,
            'statusCode'        => $status['statusCode'] ?? '',
            'isFinal'           => $isFinal,
            'isLive'            => $isLive,
            'isScheduled'       => $isScheduled,
            'currentInning'     => $currentInning,
            'inningState'       => $inningState,
            'inningOrdinal'     => $inningOrdinal,
            'outs'              => $outs,
            'onFirst'           => $onFirst,
            'onSecond'          => $onSecond,
            'onThird'           => $onThird,
            'isTopInning'       => $isTopInning,
            'away'              => $away,
            'home'              => $home,
            'broadcasts'        => $tvChannels,
            'venue'             => $g['venue']['name'] ?? '',
            'innings'           => $innings,
            'decisions'         => $decisions,
            'probablePitchers'  => $probables,
        ];
    }

    private function transformTeamSide(array $side, array $teamMap, bool $isFinal, array $game): array
    {
        $teamId = (int)($side['team']['id'] ?? 0);
        $info   = $teamMap[$teamId] ?? null;

        $record = $side['leagueRecord'] ?? [];
        $w      = $record['wins'] ?? null;
        $l      = $record['losses'] ?? null;

        return [
            'mlb_id'       => $teamId,
            'name'         => $info ? $info['name'] : ($side['team']['name'] ?? 'TBD'),
            'abbreviation' => $info ? $info['abbreviation'] : ($side['team']['abbreviation'] ?? ''),
            'score'        => $side['score'] ?? null,
            'record'       => ($w !== null && $l !== null) ? "{$w}-{$l}" : '',
            'logoUrl'      => $teamId ? "/img/teams/{$teamId}.png" : null,
            'isWinner'     => false,
        ];
    }

    private function transformGameDetail(array $data): array
    {
        $gameData  = $data['gameData'] ?? [];
        $liveData  = $data['liveData'] ?? [];
        $linescore = $liveData['linescore'] ?? [];
        $boxscore  = $liveData['boxscore'] ?? [];
        $plays     = $liveData['plays'] ?? [];

        $innings = [];
        foreach ($linescore['innings'] ?? [] as $inn) {
            $innings[] = [
                'num'  => $inn['num'] ?? 0,
                'away' => $inn['away']['runs'] ?? null,
                'home' => $inn['home']['runs'] ?? null,
            ];
        }

        $teams = $linescore['teams'] ?? [];

        // Extract pitchers for each team from boxscore
        $awayPitchers = $this->extractPitchers($boxscore, 'away');
        $homePitchers = $this->extractPitchers($boxscore, 'home');

        // Current matchup (live games)
        $currentMatchup = null;
        $currentPlay = $plays['currentPlay'] ?? null;
        if ($currentPlay) {
            $matchup = $currentPlay['matchup'] ?? [];
            $batter  = $matchup['batter'] ?? [];
            $pitcher = $matchup['pitcher'] ?? [];
            // Look up batter position from boxscore
            $batterPos = '';
            if ($batterId = ($batter['id'] ?? null)) {
                foreach (['away', 'home'] as $s) {
                    $bp = $boxscore['teams'][$s]['players']["ID{$batterId}"] ?? null;
                    if ($bp) { $batterPos = $bp['position']['abbreviation'] ?? ''; break; }
                }
            }

            // Look up handedness from boxscore
            $batterHand = null;
            $pitcherHand = null;
            if ($batterId = ($batter['id'] ?? null)) {
                foreach (['away', 'home'] as $s) {
                    $bp = $boxscore['teams'][$s]['players']["ID{$batterId}"] ?? null;
                    if ($bp) { $batterHand = $bp['person']['batSide']['code'] ?? null; break; }
                }
            }
            if ($pitcherId = ($pitcher['id'] ?? null)) {
                foreach (['away', 'home'] as $s) {
                    $bp = $boxscore['teams'][$s]['players']["ID{$pitcherId}"] ?? null;
                    if ($bp) { $pitcherHand = $bp['person']['pitchHand']['code'] ?? null; break; }
                }
            }

            $currentMatchup = [
                'batter'  => [
                    'name'     => $batter['fullName'] ?? '',
                    'id'       => $batter['id'] ?? null,
                    'position' => $batterPos,
                    'batSide'  => $batterHand,
                ],
                'pitcher' => [
                    'name'      => $pitcher['fullName'] ?? '',
                    'id'        => $pitcher['id'] ?? null,
                    'pitchHand' => $pitcherHand,
                ],
            ];

            // Enrich with stats from boxscore
            if ($currentMatchup['batter']['id']) {
                $currentMatchup['batter']['stats'] = $this->getPlayerGameStats($boxscore, $currentMatchup['batter']['id'], 'batting');
            }
            if ($currentMatchup['pitcher']['id']) {
                $currentMatchup['pitcher']['stats'] = $this->getPlayerGameStats($boxscore, $currentMatchup['pitcher']['id'], 'pitching');
            }
        }

        // Team names + IDs
        $awayTeam  = $gameData['teams']['away']['name'] ?? '';
        $homeTeam  = $gameData['teams']['home']['name'] ?? '';
        $awayAbbr  = $gameData['teams']['away']['abbreviation'] ?? '';
        $homeAbbr  = $gameData['teams']['home']['abbreviation'] ?? '';
        $awayMlbId = (int)($gameData['teams']['away']['id'] ?? 0);
        $homeMlbId = (int)($gameData['teams']['home']['id'] ?? 0);

        $isFinal = ($gameData['status']['abstractGameState'] ?? '') === 'Final';

        // W/L/S Decisions — look up handedness from boxscore player data
        $decisions = [];
        $decData = $liveData['decisions'] ?? [];
        if ($decData) {
            foreach (['winner', 'loser', 'save'] as $decType) {
                if (!isset($decData[$decType])) continue;
                $decPerson = $decData[$decType];
                $hand = null;
                // Try to get pitchHand from boxscore player data
                $decId = $decPerson['id'] ?? null;
                if ($decId) {
                    foreach (['away', 'home'] as $s) {
                        $bp = $boxscore['teams'][$s]['players']["ID{$decId}"] ?? null;
                        if ($bp) { $hand = $bp['person']['pitchHand']['code'] ?? null; break; }
                    }
                }
                $decisions[$decType] = [
                    'name' => $decPerson['fullName'] ?? '',
                    'id'   => $decId,
                    'hand' => $hand,
                ];
            }
        }

        // Extract batters for each team from boxscore
        $awayBatters = $this->extractBatters($boxscore, 'away');
        $homeBatters = $this->extractBatters($boxscore, 'home');

        return [
            'innings'        => $innings,
            'awayTotal'      => ['runs' => $teams['away']['runs'] ?? 0, 'hits' => $teams['away']['hits'] ?? 0, 'errors' => $teams['away']['errors'] ?? 0],
            'homeTotal'      => ['runs' => $teams['home']['runs'] ?? 0, 'hits' => $teams['home']['hits'] ?? 0, 'errors' => $teams['home']['errors'] ?? 0],
            'currentInning'  => $linescore['currentInning'] ?? null,
            'inningState'    => $linescore['inningState'] ?? null,
            'outs'           => $linescore['outs'] ?? null,
            'status'         => $gameData['status']['detailedState'] ?? '',
            'isFinal'        => $isFinal,
            'awayTeam'       => $awayTeam,
            'homeTeam'       => $homeTeam,
            'awayAbbr'       => $awayAbbr,
            'homeAbbr'       => $homeAbbr,
            'awayMlbId'      => $awayMlbId,
            'homeMlbId'      => $homeMlbId,
            'awayPitchers'   => $awayPitchers,
            'homePitchers'   => $homePitchers,
            'awayBatters'    => $awayBatters,
            'homeBatters'    => $homeBatters,
            'currentMatchup' => $currentMatchup,
            'decisions'      => $decisions,
        ];
    }

    private function extractPitchers(array $boxscore, string $side): array
    {
        $teamBox    = $boxscore['teams'][$side] ?? [];
        $pitcherIds = $teamBox['pitchers'] ?? [];
        $players    = $teamBox['players'] ?? [];
        $result     = [];

        foreach ($pitcherIds as $pid) {
            $key    = "ID{$pid}";
            $player = $players[$key] ?? null;
            if (!$player) continue;

            $stats = $player['stats']['pitching'] ?? [];
            $result[] = [
                'name'           => $player['person']['fullName'] ?? '',
                'id'             => $pid,
                'pitchHand'      => $player['person']['pitchHand']['code'] ?? null,
                'inningsPitched' => $stats['inningsPitched'] ?? '-',
                'hits'           => (int)($stats['hits'] ?? 0),
                'runs'           => (int)($stats['runs'] ?? 0),
                'earnedRuns'     => (int)($stats['earnedRuns'] ?? 0),
                'walks'          => (int)($stats['baseOnBalls'] ?? 0),
                'strikeOuts'     => (int)($stats['strikeOuts'] ?? 0),
                'pitchCount'     => (int)($stats['numberOfPitches'] ?? 0),
            ];
        }

        return $result;
    }

    private function extractBatters(array $boxscore, string $side): array
    {
        $teamBox = $boxscore['teams'][$side] ?? [];
        $players = $teamBox['players'] ?? [];
        $result  = [];

        foreach ($players as $key => $player) {
            $bo = $player['battingOrder'] ?? null;
            if ($bo === null) continue;

            $bo    = (int)$bo;
            $stats = $player['stats']['batting'] ?? [];
            $pos   = $player['position']['abbreviation'] ?? '';

            // Season stats (for HR totals etc.)
            $seasonStats = $player['seasonStats']['batting'] ?? [];

            $result[] = [
                'name'           => $player['person']['fullName'] ?? '',
                'id'             => $player['person']['id'] ?? 0,
                'position'       => $pos,
                'batSide'        => $player['person']['batSide']['code'] ?? null,
                'battingOrder'   => $bo,
                'lineupSpot'     => (int)floor($bo / 100),
                'isSub'          => ($bo % 100) > 0,
                'atBats'         => (int)($stats['atBats'] ?? 0),
                'runs'           => (int)($stats['runs'] ?? 0),
                'hits'           => (int)($stats['hits'] ?? 0),
                'doubles'        => (int)($stats['doubles'] ?? 0),
                'triples'        => (int)($stats['triples'] ?? 0),
                'homeRuns'       => (int)($stats['homeRuns'] ?? 0),
                'rbi'            => (int)($stats['rbi'] ?? 0),
                'walks'          => (int)($stats['baseOnBalls'] ?? 0),
                'strikeOuts'     => (int)($stats['strikeOuts'] ?? 0),
                'stolenBases'    => (int)($stats['stolenBases'] ?? 0),
                'avg'            => $stats['avg'] ?? '-',
                'seasonHomeRuns' => (int)($seasonStats['homeRuns'] ?? 0),
            ];
        }

        // Sort by battingOrder so starters come first, subs after their spot
        usort($result, function ($a, $b) {
            return $a['battingOrder'] - $b['battingOrder'];
        });

        return $result;
    }

    private function getPlayerGameStats(array $boxscore, int $playerId, string $statType): array
    {
        foreach (['away', 'home'] as $side) {
            $key    = "ID{$playerId}";
            $player = $boxscore['teams'][$side]['players'][$key] ?? null;
            if (!$player) continue;

            $stats = $player['stats'][$statType] ?? [];
            if ($statType === 'batting') {
                return [
                    'atBats'     => (int)($stats['atBats'] ?? 0),
                    'hits'       => (int)($stats['hits'] ?? 0),
                    'runs'       => (int)($stats['runs'] ?? 0),
                    'rbi'        => (int)($stats['rbi'] ?? 0),
                    'walks'      => (int)($stats['baseOnBalls'] ?? 0),
                    'strikeOuts' => (int)($stats['strikeOuts'] ?? 0),
                    'avg'        => $stats['avg'] ?? '-',
                ];
            }
            if ($statType === 'pitching') {
                return [
                    'inningsPitched' => $stats['inningsPitched'] ?? '-',
                    'hits'           => (int)($stats['hits'] ?? 0),
                    'runs'           => (int)($stats['runs'] ?? 0),
                    'earnedRuns'     => (int)($stats['earnedRuns'] ?? 0),
                    'walks'          => (int)($stats['baseOnBalls'] ?? 0),
                    'strikeOuts'     => (int)($stats['strikeOuts'] ?? 0),
                    'pitchCount'     => (int)($stats['numberOfPitches'] ?? 0),
                ];
            }
        }
        return [];
    }

    private function hasLiveGames(array $apiData): bool
    {
        foreach ($apiData['dates'] ?? [] as $dateObj) {
            foreach ($dateObj['games'] ?? [] as $g) {
                if (($g['status']['abstractGameState'] ?? '') === 'Live') {
                    return true;
                }
            }
        }
        return false;
    }

    // ─── Cache ────────────────────────────────────────────────────

    private function getCache(string $key): ?array
    {
        $file = self::CACHE_DIR . $key . '.json';
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return $data ?: null;
    }

    private function setCache(string $key, array $data): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0755, true);
        }
        file_put_contents(self::CACHE_DIR . $key . '.json', json_encode($data));
    }

    private function isCacheExpired(string $key, int $ttl): bool
    {
        $file = self::CACHE_DIR . $key . '.json';
        if (!file_exists($file)) return true;
        return (time() - filemtime($file)) > $ttl;
    }

    // ─── MLB API ──────────────────────────────────────────────────

    private function mlbApiFetch(string $url): string
    {
        $cmd    = '/usr/bin/curl -g -sS -k --max-time 20 ' . escapeshellarg($url) . ' 2>&1';
        $result = shell_exec($cmd);

        if ($result === null || $result === '') {
            throw new \RuntimeException("curl returned empty for: {$url}");
        }
        if (strpos($result, 'curl:') === 0) {
            throw new \RuntimeException("curl error: " . trim($result));
        }

        $decoded = json_decode($result, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON from MLB API");
        }

        return $result;
    }
}
