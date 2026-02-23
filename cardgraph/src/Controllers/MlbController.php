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

        $cacheKey = "mlb_schedule_{$yesterday}_{$tomorrow}";
        $cached   = $this->getCache($cacheKey);

        // Use shorter TTL if live games detected
        $hasLive = $cached && $this->hasLiveGames($cached);
        $ttl     = $hasLive ? self::LIVE_TTL : self::SCHEDULE_TTL;

        if (!$cached || $this->isCacheExpired($cacheKey, $ttl)) {
            $url = "https://statsapi.mlb.com/api/v1/schedule"
                 . "?sportId=1&startDate={$yesterday}&endDate={$tomorrow}"
                 . "&hydrate=broadcasts(all),linescore,team";

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

        return [
            'gamePk'         => $g['gamePk'] ?? null,
            'gameType'       => $gameType,
            'gameTypeLabel'  => self::GAME_TYPE_LABELS[$gameType] ?? $gameType,
            'startTime'      => $startTime,
            'status'         => $detailed,
            'statusCode'     => $status['statusCode'] ?? '',
            'isFinal'        => $isFinal,
            'isLive'         => $isLive,
            'isScheduled'    => $isScheduled,
            'currentInning'  => $currentInning,
            'inningState'    => $inningState,
            'inningOrdinal'  => $inningOrdinal,
            'outs'           => $outs,
            'away'           => $away,
            'home'           => $home,
            'broadcasts'     => $tvChannels,
            'venue'          => $g['venue']['name'] ?? '',
            'innings'        => $innings,
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

            $currentMatchup = [
                'batter'  => [
                    'name'     => $batter['fullName'] ?? '',
                    'id'       => $batter['id'] ?? null,
                    'position' => $batterPos,
                ],
                'pitcher' => [
                    'name'     => $pitcher['fullName'] ?? '',
                    'id'       => $pitcher['id'] ?? null,
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
                'name'          => $player['person']['fullName'] ?? '',
                'id'            => $pid,
                'inningsPitched' => $stats['inningsPitched'] ?? '-',
                'hits'          => (int)($stats['hits'] ?? 0),
                'runs'          => (int)($stats['runs'] ?? 0),
                'earnedRuns'    => (int)($stats['earnedRuns'] ?? 0),
                'walks'         => (int)($stats['baseOnBalls'] ?? 0),
                'strikeOuts'    => (int)($stats['strikeOuts'] ?? 0),
                'pitchCount'    => (int)($stats['numberOfPitches'] ?? 0),
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

            $result[] = [
                'name'         => $player['person']['fullName'] ?? '',
                'id'           => $player['person']['id'] ?? 0,
                'position'     => $pos,
                'battingOrder' => $bo,
                'lineupSpot'   => (int)floor($bo / 100),
                'isSub'        => ($bo % 100) > 0,
                'atBats'       => (int)($stats['atBats'] ?? 0),
                'runs'         => (int)($stats['runs'] ?? 0),
                'hits'         => (int)($stats['hits'] ?? 0),
                'doubles'      => (int)($stats['doubles'] ?? 0),
                'triples'      => (int)($stats['triples'] ?? 0),
                'homeRuns'     => (int)($stats['homeRuns'] ?? 0),
                'rbi'          => (int)($stats['rbi'] ?? 0),
                'walks'        => (int)($stats['baseOnBalls'] ?? 0),
                'strikeOuts'   => (int)($stats['strikeOuts'] ?? 0),
                'stolenBases'  => (int)($stats['stolenBases'] ?? 0),
                'avg'          => $stats['avg'] ?? '-',
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
