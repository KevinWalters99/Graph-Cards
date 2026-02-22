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

        $innings = [];
        foreach ($linescore['innings'] ?? [] as $inn) {
            $innings[] = [
                'num'  => $inn['num'] ?? 0,
                'away' => $inn['away']['runs'] ?? null,
                'home' => $inn['home']['runs'] ?? null,
            ];
        }

        $teams = $linescore['teams'] ?? [];

        return [
            'innings'        => $innings,
            'awayTotal'      => ['runs' => $teams['away']['runs'] ?? 0, 'hits' => $teams['away']['hits'] ?? 0, 'errors' => $teams['away']['errors'] ?? 0],
            'homeTotal'      => ['runs' => $teams['home']['runs'] ?? 0, 'hits' => $teams['home']['hits'] ?? 0, 'errors' => $teams['home']['errors'] ?? 0],
            'currentInning'  => $linescore['currentInning'] ?? null,
            'inningState'    => $linescore['inningState'] ?? null,
            'outs'           => $linescore['outs'] ?? null,
            'status'         => $gameData['status']['detailedState'] ?? '',
        ];
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
