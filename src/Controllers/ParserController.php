<?php
/**
 * Card Graph — Parser Support Tables Controller
 *
 * CRUD for parser reference data: Players, Teams, Card Makers, Styles, Specialties.
 * MLB API integration for stats refresh.
 * All endpoints require admin role.
 */
class ParserController
{
    // ─── Players ───────────────────────────────────────────────────

    public function listPlayers(array $params = []): void
    {
        Auth::requireAdmin();
        $pdo = cg_db();

        $pdo->exec("SET SESSION group_concat_max_len = 10000");

        $stmt = $pdo->query(
            "SELECT p.*,
                    t.team_name, t.abbreviation AS team_abbreviation, t.mlb_id AS team_mlb_id,
                    t.league AS team_league, t.division AS team_division,
                    ps.current_season_stats, ps.previous_season_stats, ps.last_season_stats, ps.overall_stats,
                    ps.last_updated AS stats_last_updated,
                    ps.stats_changed_at,
                    GROUP_CONCAT(
                        CONCAT(pn.nickname_id, '::', pn.nickname)
                        ORDER BY pn.nickname_id SEPARATOR '||'
                    ) AS nicknames_raw
             FROM CG_Players p
             LEFT JOIN CG_Teams t ON t.team_id = p.current_team_id
             LEFT JOIN CG_PlayerStatistics ps ON ps.player_id = p.player_id
             LEFT JOIN CG_PlayerNicknames pn ON pn.player_id = p.player_id
             GROUP BY p.player_id
             ORDER BY p.last_name, p.first_name"
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['nicknames'] = [];
            if (!empty($row['nicknames_raw'])) {
                $pairs = explode('||', $row['nicknames_raw']);
                foreach ($pairs as $pair) {
                    [$id, $name] = explode('::', $pair, 2);
                    $row['nicknames'][] = ['nickname_id' => (int)$id, 'nickname' => $name];
                }
            }
            unset($row['nicknames_raw']);

            // Decode JSON stats
            $row['current_season_stats'] = $row['current_season_stats']
                ? json_decode($row['current_season_stats'], true) : null;
            $row['previous_season_stats'] = $row['previous_season_stats']
                ? json_decode($row['previous_season_stats'], true) : null;
            $row['last_season_stats'] = $row['last_season_stats']
                ? json_decode($row['last_season_stats'], true) : null;
            $row['overall_stats'] = $row['overall_stats']
                ? json_decode($row['overall_stats'], true) : null;
        }

        jsonResponse(['data' => $rows]);
    }

    public function createPlayer(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();

        $firstName = trim($body['first_name'] ?? '');
        $lastName  = trim($body['last_name'] ?? '');
        if (empty($firstName) || empty($lastName)) {
            jsonError('First name and last name are required', 400);
        }

        $position = trim($body['primary_position'] ?? '') ?: null;
        $bats     = trim($body['bats'] ?? '') ?: null;
        $throwsH  = trim($body['throws_hand'] ?? '') ?: null;
        $teamId   = !empty($body['current_team_id']) ? (int)$body['current_team_id'] : null;
        $milbLevel = trim($body['minor_league_level'] ?? '') ?: null;
        $rank     = !empty($body['prospect_rank']) ? (int)$body['prospect_rank'] : null;
        $popularity = !empty($body['popularity_score']) ? (int)$body['popularity_score'] : null;
        $draftYear   = !empty($body['draft_year']) ? (int)$body['draft_year'] : null;
        $draftRound  = trim($body['draft_round'] ?? '') ?: null;
        $draftPick   = !empty($body['draft_pick']) ? (int)$body['draft_pick'] : null;
        $draftStatus = trim($body['draft_status'] ?? '') ?: null;

        $pdo = cg_db();
        $stmt = $pdo->prepare(
            "INSERT INTO CG_Players (first_name, last_name, primary_position, bats, throws_hand, current_team_id,
                    minor_league_level, prospect_rank, popularity_score, draft_year, draft_round, draft_pick, draft_status)
             VALUES (:first, :last, :pos, :bats, :throws, :team, :milb, :rank, :pop, :dy, :dr, :dp, :ds)"
        );
        $stmt->execute([
            ':first'  => $firstName,
            ':last'   => $lastName,
            ':pos'    => $position,
            ':bats'   => $bats,
            ':throws' => $throwsH,
            ':team'   => $teamId,
            ':milb'   => $milbLevel,
            ':rank'   => $rank,
            ':pop'    => $popularity,
            ':dy'     => $draftYear,
            ':dr'     => $draftRound,
            ':dp'     => $draftPick,
            ':ds'     => $draftStatus,
        ]);

        jsonResponse(['player_id' => (int)$pdo->lastInsertId(), 'message' => 'Player created'], 201);
    }

    public function updatePlayer(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $body = getJsonBody();

        $sets = [];
        $bind = [':id' => $id];

        if (isset($body['first_name'])) {
            $sets[] = 'first_name = :first_name';
            $bind[':first_name'] = trim($body['first_name']);
        }
        if (isset($body['last_name'])) {
            $sets[] = 'last_name = :last_name';
            $bind[':last_name'] = trim($body['last_name']);
        }
        if (array_key_exists('primary_position', $body)) {
            $sets[] = 'primary_position = :pos';
            $bind[':pos'] = trim($body['primary_position']) ?: null;
        }
        if (array_key_exists('bats', $body)) {
            $sets[] = 'bats = :bats';
            $bind[':bats'] = trim($body['bats']) ?: null;
        }
        if (array_key_exists('throws_hand', $body)) {
            $sets[] = 'throws_hand = :throws';
            $bind[':throws'] = trim($body['throws_hand']) ?: null;
        }
        if (isset($body['is_active'])) {
            $sets[] = 'is_active = :active';
            $bind[':active'] = (int)$body['is_active'];
        }
        if (array_key_exists('current_team_id', $body)) {
            $sets[] = 'current_team_id = :team';
            $bind[':team'] = !empty($body['current_team_id']) ? (int)$body['current_team_id'] : null;
        }
        if (array_key_exists('minor_league_level', $body)) {
            $sets[] = 'minor_league_level = :milb';
            $bind[':milb'] = trim($body['minor_league_level']) ?: null;
        }
        if (array_key_exists('prospect_rank', $body)) {
            $sets[] = 'prospect_rank = :rank';
            $bind[':rank'] = !empty($body['prospect_rank']) ? (int)$body['prospect_rank'] : null;
        }
        if (array_key_exists('popularity_score', $body)) {
            $sets[] = 'popularity_score = :pop';
            $bind[':pop'] = !empty($body['popularity_score']) ? (int)$body['popularity_score'] : null;
        }
        if (array_key_exists('draft_year', $body)) {
            $sets[] = 'draft_year = :dy';
            $bind[':dy'] = !empty($body['draft_year']) ? (int)$body['draft_year'] : null;
        }
        if (array_key_exists('draft_round', $body)) {
            $sets[] = 'draft_round = :dr';
            $bind[':dr'] = trim($body['draft_round'] ?? '') ?: null;
        }
        if (array_key_exists('draft_pick', $body)) {
            $sets[] = 'draft_pick = :dp';
            $bind[':dp'] = !empty($body['draft_pick']) ? (int)$body['draft_pick'] : null;
        }
        if (array_key_exists('draft_status', $body)) {
            $sets[] = 'draft_status = :ds';
            $bind[':ds'] = trim($body['draft_status'] ?? '') ?: null;
        }

        if (empty($sets)) {
            jsonError('No fields to update', 400);
        }

        $sql  = "UPDATE CG_Players SET " . implode(', ', $sets) . " WHERE player_id = :id";
        $stmt = cg_db()->prepare($sql);
        $stmt->execute($bind);

        if ($stmt->rowCount() === 0) {
            jsonError('Player not found', 404);
        }

        jsonResponse(['message' => 'Player updated']);
    }

    public function deletePlayer(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $stmt = cg_db()->prepare("DELETE FROM CG_Players WHERE player_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Player not found', 404);
        }

        jsonResponse(['message' => 'Player deleted']);
    }

    // ─── Player Nicknames ──────────────────────────────────────────

    public function addNickname(array $params = []): void
    {
        Auth::requireAdmin();
        $playerId = (int)($params['id'] ?? 0);
        $body     = getJsonBody();
        $nickname = trim($body['nickname'] ?? '');

        if (empty($nickname)) {
            jsonError('Nickname is required', 400);
        }

        $pdo = cg_db();

        $check = $pdo->prepare("SELECT 1 FROM CG_Players WHERE player_id = :id");
        $check->execute([':id' => $playerId]);
        if (!$check->fetch()) {
            jsonError('Player not found', 404);
        }

        $dup = $pdo->prepare(
            "SELECT 1 FROM CG_PlayerNicknames WHERE player_id = :pid AND nickname = :nn"
        );
        $dup->execute([':pid' => $playerId, ':nn' => $nickname]);
        if ($dup->fetch()) {
            jsonError('This nickname already exists for this player', 409);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO CG_PlayerNicknames (player_id, nickname) VALUES (:pid, :nn)"
        );
        $stmt->execute([':pid' => $playerId, ':nn' => $nickname]);

        jsonResponse(['nickname_id' => (int)$pdo->lastInsertId(), 'message' => 'Nickname added'], 201);
    }

    public function updateNickname(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $body = getJsonBody();
        $nickname = trim($body['nickname'] ?? '');

        if (empty($nickname)) {
            jsonError('Nickname is required', 400);
        }

        $stmt = cg_db()->prepare(
            "UPDATE CG_PlayerNicknames SET nickname = :nn WHERE nickname_id = :id"
        );
        $stmt->execute([':nn' => $nickname, ':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Nickname not found', 404);
        }

        jsonResponse(['message' => 'Nickname updated']);
    }

    public function deleteNickname(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $stmt = cg_db()->prepare("DELETE FROM CG_PlayerNicknames WHERE nickname_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Nickname not found', 404);
        }

        jsonResponse(['message' => 'Nickname removed']);
    }

    // ─── Teams ─────────────────────────────────────────────────────

    public function listTeams(array $params = []): void
    {
        Auth::requireAdmin();
        $pdo = cg_db();

        $pdo->exec("SET SESSION group_concat_max_len = 10000");

        $stmt = $pdo->query(
            "SELECT t.*,
                    ts.current_season_stats, ts.last_season_stats,
                    ts.last_updated AS stats_last_updated,
                    GROUP_CONCAT(
                        CONCAT(ta.alias_id, '::', ta.alias_name)
                        ORDER BY ta.alias_id SEPARATOR '||'
                    ) AS aliases_raw
             FROM CG_Teams t
             LEFT JOIN CG_TeamStatistics ts ON ts.team_id = t.team_id
             LEFT JOIN CG_TeamAliases ta ON ta.team_id = t.team_id
             GROUP BY t.team_id
             ORDER BY t.team_name"
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['aliases'] = [];
            if (!empty($row['aliases_raw'])) {
                $pairs = explode('||', $row['aliases_raw']);
                foreach ($pairs as $pair) {
                    [$id, $name] = explode('::', $pair, 2);
                    $row['aliases'][] = ['alias_id' => (int)$id, 'alias_name' => $name];
                }
            }
            unset($row['aliases_raw']);

            // Decode JSON stats
            $row['current_season_stats'] = $row['current_season_stats']
                ? json_decode($row['current_season_stats'], true) : null;
            $row['last_season_stats'] = $row['last_season_stats']
                ? json_decode($row['last_season_stats'], true) : null;
        }

        jsonResponse(['data' => $rows]);
    }

    public function createTeam(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();

        $teamName = trim($body['team_name'] ?? '');
        if (empty($teamName)) {
            jsonError('Team name is required', 400);
        }

        $city = trim($body['city'] ?? '') ?: null;

        $pdo = cg_db();
        $stmt = $pdo->prepare(
            "INSERT INTO CG_Teams (team_name, city) VALUES (:name, :city)"
        );
        $stmt->execute([':name' => $teamName, ':city' => $city]);

        jsonResponse(['team_id' => (int)$pdo->lastInsertId(), 'message' => 'Team created'], 201);
    }

    public function updateTeam(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $body = getJsonBody();

        $sets = [];
        $bind = [':id' => $id];

        if (isset($body['team_name'])) {
            $sets[] = 'team_name = :name';
            $bind[':name'] = trim($body['team_name']);
        }
        if (array_key_exists('city', $body)) {
            $sets[] = 'city = :city';
            $bind[':city'] = trim($body['city']) ?: null;
        }
        if (isset($body['is_active'])) {
            $sets[] = 'is_active = :active';
            $bind[':active'] = (int)$body['is_active'];
        }

        if (empty($sets)) {
            jsonError('No fields to update', 400);
        }

        $sql  = "UPDATE CG_Teams SET " . implode(', ', $sets) . " WHERE team_id = :id";
        $stmt = cg_db()->prepare($sql);
        $stmt->execute($bind);

        if ($stmt->rowCount() === 0) {
            jsonError('Team not found', 404);
        }

        jsonResponse(['message' => 'Team updated']);
    }

    public function deleteTeam(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $stmt = cg_db()->prepare("DELETE FROM CG_Teams WHERE team_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Team not found', 404);
        }

        jsonResponse(['message' => 'Team deleted']);
    }

    // ─── Team Aliases ──────────────────────────────────────────────

    public function addAlias(array $params = []): void
    {
        Auth::requireAdmin();
        $teamId   = (int)($params['id'] ?? 0);
        $body     = getJsonBody();
        $aliasName = trim($body['alias_name'] ?? '');

        if (empty($aliasName)) {
            jsonError('Alias name is required', 400);
        }

        $pdo = cg_db();

        $check = $pdo->prepare("SELECT 1 FROM CG_Teams WHERE team_id = :id");
        $check->execute([':id' => $teamId]);
        if (!$check->fetch()) {
            jsonError('Team not found', 404);
        }

        $dup = $pdo->prepare(
            "SELECT 1 FROM CG_TeamAliases WHERE team_id = :tid AND alias_name = :name"
        );
        $dup->execute([':tid' => $teamId, ':name' => $aliasName]);
        if ($dup->fetch()) {
            jsonError('This alias already exists for this team', 409);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO CG_TeamAliases (team_id, alias_name) VALUES (:tid, :name)"
        );
        $stmt->execute([':tid' => $teamId, ':name' => $aliasName]);

        jsonResponse(['alias_id' => (int)$pdo->lastInsertId(), 'message' => 'Alias added'], 201);
    }

    public function updateAlias(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $body = getJsonBody();
        $aliasName = trim($body['alias_name'] ?? '');

        if (empty($aliasName)) {
            jsonError('Alias name is required', 400);
        }

        $stmt = cg_db()->prepare(
            "UPDATE CG_TeamAliases SET alias_name = :name WHERE alias_id = :id"
        );
        $stmt->execute([':name' => $aliasName, ':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Alias not found', 404);
        }

        jsonResponse(['message' => 'Alias updated']);
    }

    public function deleteAlias(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $stmt = cg_db()->prepare("DELETE FROM CG_TeamAliases WHERE alias_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Alias not found', 404);
        }

        jsonResponse(['message' => 'Alias removed']);
    }

    // ─── Card Makers ───────────────────────────────────────────────

    public function listMakers(array $params = []): void
    {
        Auth::requireAdmin();
        $stmt = cg_db()->query("SELECT * FROM CG_CardMakers ORDER BY name");
        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function createMaker(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();
        $name = trim($body['name'] ?? '');
        if (empty($name)) {
            jsonError('Name is required', 400);
        }

        $pdo = cg_db();
        $check = $pdo->prepare("SELECT 1 FROM CG_CardMakers WHERE name = :name");
        $check->execute([':name' => $name]);
        if ($check->fetch()) {
            jsonError('A maker with this name already exists', 409);
        }

        $stmt = $pdo->prepare("INSERT INTO CG_CardMakers (name) VALUES (:name)");
        $stmt->execute([':name' => $name]);

        jsonResponse(['maker_id' => (int)$pdo->lastInsertId(), 'message' => 'Maker created'], 201);
    }

    public function updateMaker(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $body = getJsonBody();

        $sets = [];
        $bind = [':id' => $id];

        if (isset($body['name'])) {
            $sets[] = 'name = :name';
            $bind[':name'] = trim($body['name']);
        }
        if (isset($body['is_active'])) {
            $sets[] = 'is_active = :active';
            $bind[':active'] = (int)$body['is_active'];
        }

        if (empty($sets)) {
            jsonError('No fields to update', 400);
        }

        $sql  = "UPDATE CG_CardMakers SET " . implode(', ', $sets) . " WHERE maker_id = :id";
        $stmt = cg_db()->prepare($sql);
        $stmt->execute($bind);

        if ($stmt->rowCount() === 0) {
            jsonError('Maker not found', 404);
        }

        jsonResponse(['message' => 'Maker updated']);
    }

    public function deleteMaker(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $stmt = cg_db()->prepare("DELETE FROM CG_CardMakers WHERE maker_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Maker not found', 404);
        }

        jsonResponse(['message' => 'Maker deleted']);
    }

    // ─── Card Styles ───────────────────────────────────────────────

    public function listStyles(array $params = []): void
    {
        Auth::requireAdmin();
        $stmt = cg_db()->query("SELECT * FROM CG_CardStyles ORDER BY style_name");
        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function createStyle(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();
        $name = trim($body['style_name'] ?? '');
        if (empty($name)) {
            jsonError('Style name is required', 400);
        }

        $pdo = cg_db();
        $check = $pdo->prepare("SELECT 1 FROM CG_CardStyles WHERE style_name = :name");
        $check->execute([':name' => $name]);
        if ($check->fetch()) {
            jsonError('A style with this name already exists', 409);
        }

        $stmt = $pdo->prepare("INSERT INTO CG_CardStyles (style_name) VALUES (:name)");
        $stmt->execute([':name' => $name]);

        jsonResponse(['style_id' => (int)$pdo->lastInsertId(), 'message' => 'Style created'], 201);
    }

    public function updateStyle(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $body = getJsonBody();

        $sets = [];
        $bind = [':id' => $id];

        if (isset($body['style_name'])) {
            $sets[] = 'style_name = :name';
            $bind[':name'] = trim($body['style_name']);
        }
        if (isset($body['is_active'])) {
            $sets[] = 'is_active = :active';
            $bind[':active'] = (int)$body['is_active'];
        }

        if (empty($sets)) {
            jsonError('No fields to update', 400);
        }

        $sql  = "UPDATE CG_CardStyles SET " . implode(', ', $sets) . " WHERE style_id = :id";
        $stmt = cg_db()->prepare($sql);
        $stmt->execute($bind);

        if ($stmt->rowCount() === 0) {
            jsonError('Style not found', 404);
        }

        jsonResponse(['message' => 'Style updated']);
    }

    public function deleteStyle(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $stmt = cg_db()->prepare("DELETE FROM CG_CardStyles WHERE style_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Style not found', 404);
        }

        jsonResponse(['message' => 'Style deleted']);
    }

    // ─── Card Specialties ──────────────────────────────────────────

    public function listSpecialties(array $params = []): void
    {
        Auth::requireAdmin();
        $stmt = cg_db()->query("SELECT * FROM CG_CardSpecialties ORDER BY name");
        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function createSpecialty(array $params = []): void
    {
        Auth::requireAdmin();
        $body = getJsonBody();
        $name = trim($body['name'] ?? '');
        if (empty($name)) {
            jsonError('Name is required', 400);
        }

        $pdo = cg_db();
        $check = $pdo->prepare("SELECT 1 FROM CG_CardSpecialties WHERE name = :name");
        $check->execute([':name' => $name]);
        if ($check->fetch()) {
            jsonError('A specialty with this name already exists', 409);
        }

        $stmt = $pdo->prepare("INSERT INTO CG_CardSpecialties (name) VALUES (:name)");
        $stmt->execute([':name' => $name]);

        jsonResponse(['specialty_id' => (int)$pdo->lastInsertId(), 'message' => 'Specialty created'], 201);
    }

    public function updateSpecialty(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $body = getJsonBody();

        $sets = [];
        $bind = [':id' => $id];

        if (isset($body['name'])) {
            $sets[] = 'name = :name';
            $bind[':name'] = trim($body['name']);
        }
        if (isset($body['is_active'])) {
            $sets[] = 'is_active = :active';
            $bind[':active'] = (int)$body['is_active'];
        }

        if (empty($sets)) {
            jsonError('No fields to update', 400);
        }

        $sql  = "UPDATE CG_CardSpecialties SET " . implode(', ', $sets) . " WHERE specialty_id = :id";
        $stmt = cg_db()->prepare($sql);
        $stmt->execute($bind);

        if ($stmt->rowCount() === 0) {
            jsonError('Specialty not found', 404);
        }

        jsonResponse(['message' => 'Specialty updated']);
    }

    public function deleteSpecialty(array $params = []): void
    {
        Auth::requireAdmin();
        $id   = (int)($params['id'] ?? 0);
        $stmt = cg_db()->prepare("DELETE FROM CG_CardSpecialties WHERE specialty_id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            jsonError('Specialty not found', 404);
        }

        jsonResponse(['message' => 'Specialty deleted']);
    }

    // ─── MLB Stats Refresh ─────────────────────────────────────────

    /**
     * Get last refresh status for all data types.
     */
    public function getRefreshStatus(array $params = []): void
    {
        Auth::requireAdmin();
        $pdo = cg_db();

        $stmt = $pdo->query(
            "SELECT r1.data_type,
                    (SELECT r2.completed_at FROM CG_DataRefreshLog r2
                     WHERE r2.data_type = r1.data_type AND r2.status = 'completed'
                     ORDER BY r2.completed_at DESC LIMIT 1) AS last_completed,
                    (SELECT r3.started_at FROM CG_DataRefreshLog r3
                     WHERE r3.data_type = r1.data_type AND r3.status = 'running'
                     ORDER BY r3.started_at DESC LIMIT 1) AS currently_running,
                    (SELECT r4.records_updated FROM CG_DataRefreshLog r4
                     WHERE r4.data_type = r1.data_type AND r4.status = 'completed'
                     ORDER BY r4.completed_at DESC LIMIT 1) AS last_records_updated
             FROM CG_DataRefreshLog r1
             GROUP BY r1.data_type
             ORDER BY r1.data_type"
        );

        jsonResponse(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    /**
     * Refresh team standings from MLB Stats API.
     */
    public function refreshTeamStandings(array $params = []): void
    {
        Auth::requireAdmin();
        set_time_limit(90);

        $pdo = cg_db();
        $currentSeason = date('Y');
        $lastSeason = (int)$currentSeason - 1;

        // Log the refresh attempt
        $pdo->prepare(
            "INSERT INTO CG_DataRefreshLog (data_type, status, triggered_by) VALUES ('team_standings', 'running', 'manual')"
        )->execute();
        $logId = (int)$pdo->lastInsertId();

        try {
            // Build mlb_id → team_id map
            $teamMap = [];
            $teams = $pdo->query("SELECT team_id, mlb_id FROM CG_Teams WHERE mlb_id IS NOT NULL");
            foreach ($teams as $t) {
                $teamMap[(int)$t['mlb_id']] = (int)$t['team_id'];
            }

            $updated = 0;

            // Fetch current season standings
            $url = "https://statsapi.mlb.com/api/v1/standings?leagueId=103,104&season={$currentSeason}";
            $json = $this->mlbApiFetch($url);
            $data = json_decode($json, true);

            foreach ($data['records'] ?? [] as $division) {
                foreach ($division['teamRecords'] ?? [] as $record) {
                    $mlbTeamId = (int)$record['team']['id'];
                    if (!isset($teamMap[$mlbTeamId])) continue;

                    $ourTeamId = $teamMap[$mlbTeamId];
                    $stats = $this->extractStandingsStats($record);

                    $pdo->prepare(
                        "INSERT INTO CG_TeamStatistics (team_id, current_season_stats, last_updated)
                         VALUES (:tid, :stats, NOW())
                         ON DUPLICATE KEY UPDATE current_season_stats = VALUES(current_season_stats), last_updated = NOW()"
                    )->execute([':tid' => $ourTeamId, ':stats' => json_encode($stats)]);
                    $updated++;
                }
            }

            // Also fetch last season standings
            $urlLast = "https://statsapi.mlb.com/api/v1/standings?leagueId=103,104&season={$lastSeason}";
            $jsonLast = $this->mlbApiFetch($urlLast);
            $dataLast = json_decode($jsonLast, true);
            $lastUpdated = 0;

            foreach ($dataLast['records'] ?? [] as $division) {
                foreach ($division['teamRecords'] ?? [] as $record) {
                    $mlbTeamId = (int)$record['team']['id'];
                    if (!isset($teamMap[$mlbTeamId])) continue;

                    $ourTeamId = $teamMap[$mlbTeamId];
                    $stats = $this->extractStandingsStats($record);

                    $pdo->prepare(
                        "UPDATE CG_TeamStatistics SET last_season_stats = :stats, last_updated = NOW() WHERE team_id = :tid"
                    )->execute([':stats' => json_encode($stats), ':tid' => $ourTeamId]);
                    $lastUpdated++;
                }
            }

            $pdo->prepare(
                "UPDATE CG_DataRefreshLog SET status = 'completed', completed_at = NOW(), records_updated = :cnt WHERE refresh_id = :id"
            )->execute([':cnt' => $updated + $lastUpdated, ':id' => $logId]);

            jsonResponse([
                'message' => "Standings updated: {$updated} current ({$currentSeason}), {$lastUpdated} last season ({$lastSeason})",
                'records_updated' => $updated + $lastUpdated,
            ]);

        } catch (\Throwable $e) {
            $pdo->prepare(
                "UPDATE CG_DataRefreshLog SET status = 'failed', completed_at = NOW(), error_message = :err WHERE refresh_id = :id"
            )->execute([':err' => $e->getMessage(), ':id' => $logId]);
            jsonError('Standings refresh failed: ' . $e->getMessage(), 500);
        }
    }

    private function extractStandingsStats(array $record): array
    {
        return [
            'wins'               => (int)$record['wins'],
            'losses'             => (int)$record['losses'],
            'winning_percentage' => $record['winningPercentage'] ?? '-',
            'games_back'         => $record['gamesBack'] ?? '-',
            'division_rank'      => (int)($record['divisionRank'] ?? 0),
            'run_differential'   => (int)($record['runDifferential'] ?? 0),
            'streak'             => $record['streak']['streakCode'] ?? null,
        ];
    }

    /**
     * Refresh team rosters + player stats from MLB Stats API.
     * Processes in batches of 2 teams per request. Frontend calls repeatedly.
     * Small batch size avoids PHP-FPM/Nginx timeout (60s).
     */
    public function refreshTeamRosters(array $params = []): void
    {
        Auth::requireAdmin();
        set_time_limit(60);

        $body = getJsonBody();
        $batchOffset = (int)($body['batch_offset'] ?? 0);
        $batchSize   = 2;
        $statsSeason = $body['stats_season'] ?? date('Y');
        $statsField  = ($body['stats_field'] ?? 'current_season_stats');
        $dataType    = ($body['data_type'] ?? 'team_rosters');

        // Only allow known fields
        if (!in_array($statsField, ['current_season_stats', 'last_season_stats'], true)) {
            $statsField = 'current_season_stats';
        }

        $pdo = cg_db();

        // Get teams with MLB IDs
        $teams = $pdo->query(
            "SELECT team_id, mlb_id, team_name FROM CG_Teams WHERE mlb_id IS NOT NULL AND is_active = 1 ORDER BY team_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $totalTeams = count($teams);
        $batch = array_slice($teams, $batchOffset, $batchSize);

        if (empty($batch)) {
            // Finalize the running log entry
            $logStmt = $pdo->query(
                "SELECT refresh_id FROM CG_DataRefreshLog WHERE data_type = '{$dataType}' AND status = 'running' ORDER BY started_at DESC LIMIT 1"
            );
            $logId = $logStmt->fetchColumn();
            if ($logId) {
                $pdo->prepare(
                    "UPDATE CG_DataRefreshLog SET status = 'completed', completed_at = NOW() WHERE refresh_id = :id"
                )->execute([':id' => $logId]);
            }
            jsonResponse(['message' => 'All teams processed', 'complete' => true, 'total' => $totalTeams, 'processed' => $batchOffset]);
            return;
        }

        // On first batch, create log entry
        if ($batchOffset === 0) {
            $pdo->prepare(
                "INSERT INTO CG_DataRefreshLog (data_type, status, triggered_by) VALUES (:dt, 'running', 'manual')"
            )->execute([':dt' => $dataType]);
        }

        $playersUpdated = 0;
        $errors = [];

        foreach ($batch as $team) {
            try {
                $url = "https://statsapi.mlb.com/api/v1/teams/{$team['mlb_id']}/roster"
                     . "?rosterType=active&hydrate=person(stats(type=[season,career],"
                     . "group=[hitting,pitching],season={$statsSeason}),draft)";

                $json = $this->mlbApiFetch($url);
                $rosterData = json_decode($json, true);

                foreach ($rosterData['roster'] ?? [] as $entry) {
                    $person = $entry['person'] ?? [];
                    $mlbPlayerId = (int)($person['id'] ?? 0);
                    if (!$mlbPlayerId) continue;

                    $fullName = $person['fullName'] ?? '';
                    $nameParts = explode(' ', $fullName, 2);
                    $firstName = $nameParts[0] ?? '';
                    $lastName  = $nameParts[1] ?? '';
                    $position  = $entry['position']['abbreviation'] ?? null;
                    $bats      = $person['batSide']['code'] ?? null;
                    $throwsH   = $person['pitchHand']['code'] ?? null;

                    // Extract draft info (use most recent MLB draft entry)
                    $draftYear = null; $draftRound = null; $draftPick = null;
                    $drafts = $person['drafts'] ?? [];
                    if (!empty($drafts)) {
                        $lastDraft = $drafts[count($drafts) - 1];
                        $draftYear  = isset($lastDraft['year']) ? (int)$lastDraft['year'] : null;
                        $draftRound = $lastDraft['pickRound'] ?? null;
                        $draftPick  = isset($lastDraft['pickNumber']) ? (int)$lastDraft['pickNumber'] : null;
                    }

                    // Determine draft_status
                    if (!empty($drafts)) {
                        $draftStatus = 'Drafted';
                    } else {
                        $debutDate = $person['mlbDebutDate'] ?? null;
                        $debutYear = $debutDate ? (int)substr($debutDate, 0, 4) : null;
                        $birthCountry = $person['birthCountry'] ?? 'USA';
                        if ($debutYear && $debutYear < 1965) {
                            $draftStatus = 'Pre-Draft';
                        } elseif ($birthCountry !== 'USA') {
                            $draftStatus = 'Intl FA';
                        } else {
                            $draftStatus = 'Undrafted';
                        }
                    }

                    // Detect active status from API
                    $apiActive = $person['active'] ?? true;
                    $activeStatus = $apiActive ? 1 : 2;

                    // Match player: by mlb_id first, then by name
                    $playerId = $pdo->prepare("SELECT player_id FROM CG_Players WHERE mlb_id = :mid");
                    $playerId->execute([':mid' => $mlbPlayerId]);
                    $pid = $playerId->fetchColumn();

                    if (!$pid) {
                        $nameMatch = $pdo->prepare(
                            "SELECT player_id FROM CG_Players WHERE first_name = :fn AND last_name = :ln LIMIT 1"
                        );
                        $nameMatch->execute([':fn' => $firstName, ':ln' => $lastName]);
                        $pid = $nameMatch->fetchColumn();
                    }

                    if ($pid) {
                        // Update existing player — only overwrite draft/active if we have data
                        $pdo->prepare(
                            "UPDATE CG_Players SET mlb_id = :mid, current_team_id = :tid,
                                    primary_position = COALESCE(:pos, primary_position),
                                    bats = COALESCE(:bats, bats),
                                    throws_hand = COALESCE(:throws, throws_hand),
                                    draft_year = COALESCE(:dy, draft_year),
                                    draft_round = COALESCE(:dr, draft_round),
                                    draft_pick = COALESCE(:dp, draft_pick),
                                    draft_status = COALESCE(:ds, draft_status),
                                    is_active = CASE WHEN is_active = 2 THEN 2 ELSE :active END
                             WHERE player_id = :pid"
                        )->execute([':mid' => $mlbPlayerId, ':tid' => $team['team_id'], ':pos' => $position,
                                    ':bats' => $bats, ':throws' => $throwsH,
                                    ':dy' => $draftYear, ':dr' => $draftRound, ':dp' => $draftPick,
                                    ':ds' => $draftStatus, ':active' => $activeStatus, ':pid' => $pid]);
                    } else {
                        // Insert new player discovered from roster
                        $pdo->prepare(
                            "INSERT INTO CG_Players (mlb_id, first_name, last_name, primary_position, bats, throws_hand,
                                    current_team_id, draft_year, draft_round, draft_pick, draft_status, is_active)
                             VALUES (:mid, :fn, :ln, :pos, :bats, :throws, :tid, :dy, :dr, :dp, :ds, :active)"
                        )->execute([':mid' => $mlbPlayerId, ':fn' => $firstName, ':ln' => $lastName,
                                    ':pos' => $position, ':bats' => $bats, ':throws' => $throwsH,
                                    ':tid' => $team['team_id'],
                                    ':dy' => $draftYear, ':dr' => $draftRound, ':dp' => $draftPick,
                                    ':ds' => $draftStatus, ':active' => $activeStatus]);
                        $pid = (int)$pdo->lastInsertId();
                    }

                    // Extract stats — use the group matching the player's position
                    // to avoid overwriting hitting stats with pitching (or vice versa)
                    $seasonStats = null;
                    $careerStats = null;
                    $isPitcher = in_array($position, ['SP', 'RP', 'P', 'CL'], true);
                    $preferredGroup = $isPitcher ? 'pitching' : 'hitting';

                    foreach ($person['stats'] ?? [] as $statGroup) {
                        $type  = $statGroup['type']['displayName'] ?? '';
                        $group = $statGroup['group']['displayName'] ?? '';
                        $splits = $statGroup['splits'] ?? [];

                        // Only use the stats group that matches the player's role
                        if (strtolower($group) !== $preferredGroup) continue;

                        if (!empty($splits)) {
                            $s = $splits[0]['stat'] ?? [];
                            if ($type === 'season' || $type === 'statsSingleSeason') {
                                $seasonStats = $this->extractRelevantStats($s, $group, $position);
                            } elseif ($type === 'career' || $type === 'careerRegularSeason') {
                                $careerStats = $this->extractRelevantStats($s, $group, $position);
                            }
                        }
                    }

                    // Upsert player statistics — save previous stats for trend tracking
                    $newSeasonJson = $seasonStats ? json_encode($seasonStats) : null;
                    $upsertSql = "INSERT INTO CG_PlayerStatistics (player_id, {$statsField}, overall_stats, last_updated)
                                  VALUES (:pid, :season, :career, NOW())
                                  ON DUPLICATE KEY UPDATE
                                      previous_season_stats = IF(:season2 IS NOT NULL AND {$statsField} IS NOT NULL AND {$statsField} != :season3, {$statsField}, previous_season_stats),
                                      stats_changed_at = IF(:season4 IS NOT NULL AND {$statsField} IS NOT NULL AND {$statsField} != :season5, NOW(), stats_changed_at),
                                      {$statsField} = VALUES({$statsField}),
                                      overall_stats = COALESCE(VALUES(overall_stats), overall_stats),
                                      last_updated = NOW()";
                    $pdo->prepare($upsertSql)->execute([
                        ':pid'     => $pid,
                        ':season'  => $newSeasonJson,
                        ':career'  => $careerStats ? json_encode($careerStats) : null,
                        ':season2' => $newSeasonJson,
                        ':season3' => $newSeasonJson,
                        ':season4' => $newSeasonJson,
                        ':season5' => $newSeasonJson,
                    ]);

                    $playersUpdated++;
                }
            } catch (\Throwable $e) {
                $errors[] = "{$team['team_name']}: {$e->getMessage()}";
            }
        }

        // Update log with running count
        $logStmt = $pdo->query(
            "SELECT refresh_id FROM CG_DataRefreshLog WHERE data_type = '{$dataType}' AND status = 'running' ORDER BY started_at DESC LIMIT 1"
        );
        $logId = $logStmt->fetchColumn();
        if ($logId) {
            $pdo->prepare(
                "UPDATE CG_DataRefreshLog SET records_updated = records_updated + :cnt WHERE refresh_id = :id"
            )->execute([':cnt' => $playersUpdated, ':id' => $logId]);
        }

        $nextOffset = $batchOffset + $batchSize;
        $isComplete = $nextOffset >= $totalTeams;

        if ($isComplete && $logId) {
            $status = empty($errors) ? 'completed' : 'completed';
            $pdo->prepare(
                "UPDATE CG_DataRefreshLog SET status = :st, completed_at = NOW(), error_message = :err WHERE refresh_id = :id"
            )->execute([
                ':st'  => $status,
                ':err' => empty($errors) ? null : implode('; ', $errors),
                ':id'  => $logId,
            ]);
        }

        jsonResponse([
            'message'         => "Processed teams " . ($batchOffset + 1) . "-" . min($nextOffset, $totalTeams) . " of {$totalTeams}",
            'complete'        => $isComplete,
            'next_offset'     => $nextOffset,
            'total'           => $totalTeams,
            'players_updated' => $playersUpdated,
            'errors'          => $errors,
        ]);
    }

    /**
     * Extract the relevant stats from an MLB API stat object.
     */
    private function extractRelevantStats(array $raw, string $group, ?string $position): array
    {
        $isPitcher = in_array($position, ['SP', 'RP', 'P', 'CL'], true)
                  || strtolower($group) === 'pitching';

        if ($isPitcher) {
            return [
                'type' => 'pitching',
                'w'    => (int)($raw['wins'] ?? 0),
                'l'    => (int)($raw['losses'] ?? 0),
                'era'  => $raw['era'] ?? '-',
                'k'    => (int)($raw['strikeOuts'] ?? 0),
                'whip' => $raw['whip'] ?? '-',
                'ip'   => $raw['inningsPitched'] ?? '0.0',
                'g'    => (int)($raw['gamesPlayed'] ?? 0),
                'sv'   => (int)($raw['saves'] ?? 0),
            ];
        }

        return [
            'type' => 'hitting',
            'avg'  => $raw['avg'] ?? '-',
            'hr'   => (int)($raw['homeRuns'] ?? 0),
            'rbi'  => (int)($raw['rbi'] ?? 0),
            'ops'  => $raw['ops'] ?? '-',
            'sb'   => (int)($raw['stolenBases'] ?? 0),
            'g'    => (int)($raw['gamesPlayed'] ?? 0),
            'h'    => (int)($raw['hits'] ?? 0),
            'ab'   => (int)($raw['atBats'] ?? 0),
        ];
    }

    // ─── Minor League Roster Refresh ────────────────────────────

    /**
     * Refresh minor league rosters from MLB Stats API.
     * Fetches rosters for AAA, AA, A+, A teams and maps players to their parent MLB team.
     * Batched: processes 2 MiLB teams per request. Frontend calls repeatedly.
     */
    public function refreshMinorLeagueRosters(array $params = []): void
    {
        Auth::requireAdmin();
        set_time_limit(60);

        $body    = getJsonBody();
        $batchOffset = (int)($body['batch_offset'] ?? 0);
        $batchSize   = 2;
        $season  = (int)($body['season'] ?? date('Y'));
        $pdo     = cg_db();

        // sportId → minor_league_level
        $levelMap = [11 => 'AAA', 12 => 'AA', 13 => 'A+', 14 => 'A'];

        // Fetch all MiLB teams from API (one lightweight call each request)
        $url = "https://statsapi.mlb.com/api/v1/teams?sportIds=11,12,13,14&season={$season}";
        $json = $this->mlbApiFetch($url);
        $teamsApiData = json_decode($json, true);

        $milbTeams = [];
        foreach ($teamsApiData['teams'] ?? [] as $team) {
            $sportId = (int)($team['sport']['id'] ?? 0);
            if (!isset($levelMap[$sportId])) continue;
            $milbTeams[] = [
                'id'        => (int)$team['id'],
                'name'      => $team['name'] ?? '',
                'sport_id'  => $sportId,
                'level'     => $levelMap[$sportId],
                'parent_id' => (int)($team['parentOrgId'] ?? 0),
            ];
        }

        $totalTeams = count($milbTeams);
        $batch = array_slice($milbTeams, $batchOffset, $batchSize);

        if (empty($batch)) {
            $logStmt = $pdo->query(
                "SELECT refresh_id FROM CG_DataRefreshLog WHERE data_type = 'milb_rosters' AND status = 'running' ORDER BY started_at DESC LIMIT 1"
            );
            $logId = $logStmt->fetchColumn();
            if ($logId) {
                $pdo->prepare("UPDATE CG_DataRefreshLog SET status = 'completed', completed_at = NOW() WHERE refresh_id = :id")
                    ->execute([':id' => $logId]);
            }
            jsonResponse(['message' => 'All MiLB teams processed', 'complete' => true, 'total' => $totalTeams, 'processed' => $batchOffset]);
            return;
        }

        if ($batchOffset === 0) {
            $pdo->prepare(
                "INSERT INTO CG_DataRefreshLog (data_type, status, triggered_by) VALUES ('milb_rosters', 'running', 'manual')"
            )->execute();
        }

        // Build MLB parent org → our CG_Teams.team_id map
        $teamMap = [];
        $rows = $pdo->query("SELECT team_id, mlb_id FROM CG_Teams WHERE mlb_id IS NOT NULL");
        foreach ($rows as $r) {
            $teamMap[(int)$r['mlb_id']] = (int)$r['team_id'];
        }

        $playersUpdated = 0;
        $errors = [];

        foreach ($batch as $mt) {
            try {
                $parentTeamId = $teamMap[$mt['parent_id']] ?? null;
                if (!$parentTeamId) continue;

                $rosterUrl = "https://statsapi.mlb.com/api/v1/teams/{$mt['id']}/roster"
                    . "?rosterType=active&season={$season}"
                    . "&hydrate=person(stats(type=[season,career],group=[hitting,pitching],season={$season},sportId={$mt['sport_id']}))";

                $rosterJson = $this->mlbApiFetch($rosterUrl);
                $rosterData = json_decode($rosterJson, true);

                foreach ($rosterData['roster'] ?? [] as $entry) {
                    $person = $entry['person'] ?? [];
                    $mlbPid = (int)($person['id'] ?? 0);
                    if (!$mlbPid) continue;

                    $fullName = $person['fullName'] ?? '';
                    $parts    = explode(' ', $fullName, 2);
                    $first    = $parts[0] ?? '';
                    $last     = $parts[1] ?? '';
                    $pos      = $entry['position']['abbreviation'] ?? null;

                    // Match by mlb_id then by name
                    $stmt = $pdo->prepare("SELECT player_id FROM CG_Players WHERE mlb_id = :mid");
                    $stmt->execute([':mid' => $mlbPid]);
                    $pid = $stmt->fetchColumn();

                    if (!$pid) {
                        $nm = $pdo->prepare("SELECT player_id FROM CG_Players WHERE first_name = :fn AND last_name = :ln LIMIT 1");
                        $nm->execute([':fn' => $first, ':ln' => $last]);
                        $pid = $nm->fetchColumn();
                    }

                    if ($pid) {
                        $pdo->prepare(
                            "UPDATE CG_Players SET mlb_id = :mid, current_team_id = :tid,
                                    primary_position = COALESCE(:pos, primary_position),
                                    minor_league_level = :lvl
                             WHERE player_id = :pid"
                        )->execute([':mid' => $mlbPid, ':tid' => $parentTeamId, ':pos' => $pos, ':lvl' => $mt['level'], ':pid' => $pid]);
                    } else {
                        $pdo->prepare(
                            "INSERT INTO CG_Players (mlb_id, first_name, last_name, primary_position, current_team_id, minor_league_level)
                             VALUES (:mid, :fn, :ln, :pos, :tid, :lvl)"
                        )->execute([':mid' => $mlbPid, ':fn' => $first, ':ln' => $last, ':pos' => $pos, ':tid' => $parentTeamId, ':lvl' => $mt['level']]);
                        $pid = (int)$pdo->lastInsertId();
                    }

                    // Extract stats
                    $seasonStats = null;
                    $careerStats = null;
                    $isPitcher = in_array($pos, ['SP', 'RP', 'P', 'CL'], true);
                    $prefGroup = $isPitcher ? 'pitching' : 'hitting';

                    foreach ($person['stats'] ?? [] as $sg) {
                        $type   = $sg['type']['displayName'] ?? '';
                        $group  = $sg['group']['displayName'] ?? '';
                        $splits = $sg['splits'] ?? [];
                        if (strtolower($group) !== $prefGroup) continue;
                        if (!empty($splits)) {
                            $s = $splits[0]['stat'] ?? [];
                            if ($type === 'season' || $type === 'statsSingleSeason') {
                                $seasonStats = $this->extractRelevantStats($s, $group, $pos);
                            } elseif ($type === 'career' || $type === 'careerRegularSeason') {
                                $careerStats = $this->extractRelevantStats($s, $group, $pos);
                            }
                        }
                    }

                    $pdo->prepare(
                        "INSERT INTO CG_PlayerStatistics (player_id, current_season_stats, overall_stats, last_updated)
                         VALUES (:pid, :season, :career, NOW())
                         ON DUPLICATE KEY UPDATE
                             current_season_stats = COALESCE(VALUES(current_season_stats), current_season_stats),
                             overall_stats = COALESCE(VALUES(overall_stats), overall_stats),
                             last_updated = NOW()"
                    )->execute([
                        ':pid'    => $pid,
                        ':season' => $seasonStats ? json_encode($seasonStats) : null,
                        ':career' => $careerStats ? json_encode($careerStats) : null,
                    ]);

                    $playersUpdated++;
                }
            } catch (\Throwable $e) {
                $errors[] = "{$mt['name']}: {$e->getMessage()}";
            }
        }

        // Update running log count
        $logStmt = $pdo->query(
            "SELECT refresh_id FROM CG_DataRefreshLog WHERE data_type = 'milb_rosters' AND status = 'running' ORDER BY started_at DESC LIMIT 1"
        );
        $logId = $logStmt->fetchColumn();
        if ($logId) {
            $pdo->prepare("UPDATE CG_DataRefreshLog SET records_updated = records_updated + :cnt WHERE refresh_id = :id")
                ->execute([':cnt' => $playersUpdated, ':id' => $logId]);
        }

        $nextOffset = $batchOffset + $batchSize;
        $isComplete = $nextOffset >= $totalTeams;

        if ($isComplete && $logId) {
            $pdo->prepare(
                "UPDATE CG_DataRefreshLog SET status = 'completed', completed_at = NOW(), error_message = :err WHERE refresh_id = :id"
            )->execute([':err' => empty($errors) ? null : implode('; ', $errors), ':id' => $logId]);
        }

        jsonResponse([
            'message'         => "MiLB teams " . ($batchOffset + 1) . "-" . min($nextOffset, $totalTeams) . " of {$totalTeams}",
            'complete'        => $isComplete,
            'next_offset'     => $nextOffset,
            'total'           => $totalTeams,
            'players_updated' => $playersUpdated,
            'errors'          => $errors,
        ]);
    }

    // ─── Historical / Notable Players Import ─────────────────────

    /**
     * Import historical/notable players from MLB Stats API using award recipients.
     * Phase 'gather': Fetches HOF, MVP, Cy Young, ROY recipients → returns unique player list.
     * Phase 'import': Receives batch of player IDs, fetches career stats, upserts into DB.
     */
    public function refreshHistoricalPlayers(array $params = []): void
    {
        Auth::requireAdmin();
        set_time_limit(120);

        $body  = getJsonBody();
        $phase = $body['phase'] ?? 'gather';
        $pdo   = cg_db();

        if ($phase === 'gather') {
            $awards = ['MLBHOF', 'ALMVP', 'NLMVP', 'ALCY', 'NLCY', 'ALROY', 'NLROY'];
            $playerMap = []; // mlb_id => info

            // Valid position abbreviations (filter out managers, umpires, executives)
            $validPositions = ['C','1B','2B','3B','SS','LF','CF','RF','OF','DH','SP','RP','P','IF','UT','TWP'];

            foreach ($awards as $awardCode) {
                try {
                    $url  = "https://statsapi.mlb.com/api/v1/awards/{$awardCode}/recipients";
                    $json = $this->mlbApiFetch($url);
                    $data = json_decode($json, true);

                    foreach ($data['awards'] ?? [] as $entry) {
                        $player = $entry['player'] ?? [];
                        $mlbId  = (int)($player['id'] ?? 0);
                        if (!$mlbId) continue;

                        // Filter out non-player inductees
                        $posAbbr = $player['primaryPosition']['abbreviation'] ?? null;
                        if ($posAbbr && !in_array($posAbbr, $validPositions, true)) continue;

                        $teamMlbId = (int)($entry['team']['id'] ?? 0);

                        if (!isset($playerMap[$mlbId])) {
                            $playerMap[$mlbId] = [
                                'mlb_id'      => $mlbId,
                                'name'        => $player['nameFirstLast'] ?? ($player['fullName'] ?? ''),
                                'team_mlb_id' => $teamMlbId,
                                'position'    => $posAbbr,
                                'awards'      => [],
                            ];
                        }
                        $playerMap[$mlbId]['awards'][] = $awardCode;

                        // Prefer HOF team association over others
                        if ($awardCode === 'MLBHOF' && $teamMlbId) {
                            $playerMap[$mlbId]['team_mlb_id'] = $teamMlbId;
                        }
                    }
                } catch (\Throwable $e) {
                    // Skip failed award fetch, continue with others
                }
            }

            // Exclude players already in our DB (by mlb_id)
            $existingIds = [];
            $stmt = $pdo->query("SELECT mlb_id FROM CG_Players WHERE mlb_id IS NOT NULL");
            while ($row = $stmt->fetch()) {
                $existingIds[(int)$row['mlb_id']] = true;
            }

            $newPlayers = [];
            foreach ($playerMap as $mlbId => $p) {
                if (!isset($existingIds[$mlbId])) {
                    $newPlayers[] = $p;
                }
            }

            // Start log
            $pdo->prepare(
                "INSERT INTO CG_DataRefreshLog (data_type, status, triggered_by) VALUES ('historical_players', 'running', 'manual')"
            )->execute();

            jsonResponse([
                'phase'         => 'gather',
                'total_found'   => count($playerMap),
                'new_players'   => count($newPlayers),
                'already_exist' => count($playerMap) - count($newPlayers),
                'players'       => array_values($newPlayers),
            ]);
            return;
        }

        // ── Phase 'import' ─────────────────────────────────────
        $players = $body['players'] ?? [];

        if (empty($players)) {
            // Finalize log
            $logStmt = $pdo->query(
                "SELECT refresh_id FROM CG_DataRefreshLog WHERE data_type = 'historical_players' AND status = 'running' ORDER BY started_at DESC LIMIT 1"
            );
            $logId = $logStmt->fetchColumn();
            if ($logId) {
                $pdo->prepare("UPDATE CG_DataRefreshLog SET status = 'completed', completed_at = NOW() WHERE refresh_id = :id")
                    ->execute([':id' => $logId]);
            }
            jsonResponse(['phase' => 'import', 'complete' => true, 'players_updated' => 0]);
            return;
        }

        // Build mlb_id → our team_id map
        $teamMap = [];
        $rows = $pdo->query("SELECT team_id, mlb_id FROM CG_Teams WHERE mlb_id IS NOT NULL");
        foreach ($rows as $r) {
            $teamMap[(int)$r['mlb_id']] = (int)$r['team_id'];
        }

        // Batch-fetch player details + career stats from MLB API
        $mlbIds = array_map(function ($p) { return (int)$p['mlb_id']; }, $players);
        $idsStr = implode(',', $mlbIds);

        try {
            $url  = "https://statsapi.mlb.com/api/v1/people?personIds={$idsStr}"
                   . "&hydrate=stats(type=career,group=[hitting,pitching]),draft";
            $json = $this->mlbApiFetch($url);
            $apiData = json_decode($json, true);
        } catch (\Throwable $e) {
            jsonError('Failed to fetch player details: ' . $e->getMessage(), 500);
            return;
        }

        // Build lookup from frontend-provided data
        $inputMap = [];
        foreach ($players as $p) {
            $inputMap[(int)$p['mlb_id']] = $p;
        }

        $updated = 0;
        foreach ($apiData['people'] ?? [] as $person) {
            $mlbId = (int)($person['id'] ?? 0);
            if (!$mlbId) continue;

            $firstName  = $person['firstName'] ?? '';
            $lastName   = $person['lastName'] ?? '';
            $pos        = $person['primaryPosition']['abbreviation'] ?? null;
            $teamMlbId  = $inputMap[$mlbId]['team_mlb_id'] ?? 0;
            $ourTeamId  = $teamMap[$teamMlbId] ?? null;
            $isRetired  = !($person['active'] ?? false);

            // Extract draft info
            $draftYear = null; $draftRound = null; $draftPick = null;
            $drafts = $person['drafts'] ?? [];
            if (!empty($drafts)) {
                $lastDraft = $drafts[count($drafts) - 1];
                $draftYear  = isset($lastDraft['year']) ? (int)$lastDraft['year'] : null;
                $draftRound = $lastDraft['pickRound'] ?? null;
                $draftPick  = isset($lastDraft['pickNumber']) ? (int)$lastDraft['pickNumber'] : null;
            }

            // Determine draft_status
            if (!empty($drafts)) {
                $draftStatus = 'Drafted';
            } else {
                $debutDate = $person['mlbDebutDate'] ?? null;
                $debutYr   = $debutDate ? (int)substr($debutDate, 0, 4) : null;
                $birthCtry = $person['birthCountry'] ?? 'USA';
                if ($debutYr && $debutYr < 1965) {
                    $draftStatus = 'Pre-Draft';
                } elseif ($birthCtry !== 'USA') {
                    $draftStatus = 'Intl FA';
                } else {
                    $draftStatus = 'Undrafted';
                }
            }

            // Match existing player
            $stmt = $pdo->prepare("SELECT player_id FROM CG_Players WHERE mlb_id = :mid");
            $stmt->execute([':mid' => $mlbId]);
            $pid = $stmt->fetchColumn();

            if (!$pid) {
                $nm = $pdo->prepare("SELECT player_id FROM CG_Players WHERE first_name = :fn AND last_name = :ln LIMIT 1");
                $nm->execute([':fn' => $firstName, ':ln' => $lastName]);
                $pid = $nm->fetchColumn();
            }

            $activeStatus = $isRetired ? 2 : 1;

            if ($pid) {
                $pdo->prepare(
                    "UPDATE CG_Players SET mlb_id = :mid,
                            current_team_id = COALESCE(:tid, current_team_id),
                            primary_position = COALESCE(:pos, primary_position),
                            draft_year = COALESCE(:dy, draft_year),
                            draft_round = COALESCE(:dr, draft_round),
                            draft_pick = COALESCE(:dp, draft_pick),
                            draft_status = COALESCE(:ds, draft_status),
                            is_active = :active
                     WHERE player_id = :pid"
                )->execute([':mid' => $mlbId, ':tid' => $ourTeamId, ':pos' => $pos,
                            ':dy' => $draftYear, ':dr' => $draftRound, ':dp' => $draftPick,
                            ':ds' => $draftStatus, ':active' => $activeStatus, ':pid' => $pid]);
            } else {
                $pdo->prepare(
                    "INSERT INTO CG_Players (mlb_id, first_name, last_name, primary_position, current_team_id,
                            draft_year, draft_round, draft_pick, draft_status, is_active)
                     VALUES (:mid, :fn, :ln, :pos, :tid, :dy, :dr, :dp, :ds, :active)"
                )->execute([':mid' => $mlbId, ':fn' => $firstName, ':ln' => $lastName, ':pos' => $pos,
                            ':tid' => $ourTeamId, ':dy' => $draftYear, ':dr' => $draftRound, ':dp' => $draftPick,
                            ':ds' => $draftStatus, ':active' => $activeStatus]);
                $pid = (int)$pdo->lastInsertId();
            }

            // Extract career stats
            $careerStats = null;
            $isPitcher = in_array($pos, ['SP', 'RP', 'P', 'CL'], true);
            $prefGroup = $isPitcher ? 'pitching' : 'hitting';

            foreach ($person['stats'] ?? [] as $sg) {
                $type   = $sg['type']['displayName'] ?? '';
                $group  = $sg['group']['displayName'] ?? '';
                $splits = $sg['splits'] ?? [];
                if (strtolower($group) !== $prefGroup) continue;
                if (($type === 'career' || $type === 'careerRegularSeason') && !empty($splits)) {
                    $s = $splits[0]['stat'] ?? [];
                    $careerStats = $this->extractRelevantStats($s, $group, $pos);
                }
            }

            if ($careerStats) {
                $pdo->prepare(
                    "INSERT INTO CG_PlayerStatistics (player_id, overall_stats, last_updated)
                     VALUES (:pid, :stats, NOW())
                     ON DUPLICATE KEY UPDATE overall_stats = VALUES(overall_stats), last_updated = NOW()"
                )->execute([':pid' => $pid, ':stats' => json_encode($careerStats)]);
            }

            $updated++;
        }

        // Update running log
        $logStmt = $pdo->query(
            "SELECT refresh_id FROM CG_DataRefreshLog WHERE data_type = 'historical_players' AND status = 'running' ORDER BY started_at DESC LIMIT 1"
        );
        $logId = $logStmt->fetchColumn();
        if ($logId) {
            $pdo->prepare("UPDATE CG_DataRefreshLog SET records_updated = records_updated + :cnt WHERE refresh_id = :id")
                ->execute([':cnt' => $updated, ':id' => $logId]);
        }

        jsonResponse([
            'phase'           => 'import',
            'complete'        => false,
            'players_updated' => $updated,
        ]);
    }

    // ─── Shared Helpers ─────────────────────────────────────────

    /**
     * Fetch JSON from the MLB Stats API.
     * Uses system curl since PHP-FPM on Synology lacks openssl/curl extensions.
     */
    /**
     * Backfill draft info for players with mlb_id but no draft_year.
     * Processes in batches of 50 per request.
     */
    public function backfillDraftInfo(array $params = []): void
    {
        Auth::requireAdmin();
        set_time_limit(60);
        $pdo  = cg_db();
        $body = getJsonBody();
        $batchOffset = (int)($body['batch_offset'] ?? 0);
        $batchSize   = 50;

        // Get players needing draft info
        $stmt = $pdo->query(
            "SELECT player_id, mlb_id FROM CG_Players
             WHERE mlb_id IS NOT NULL AND draft_year IS NULL
             ORDER BY player_id"
        );
        $allPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = count($allPlayers);

        if ($total === 0 || $batchOffset >= $total) {
            jsonResponse(['message' => 'Draft backfill complete', 'complete' => true, 'total' => $total, 'updated' => 0]);
            return;
        }

        $batch = array_slice($allPlayers, $batchOffset, $batchSize);
        $mlbIds = array_map(function ($p) { return (int)$p['mlb_id']; }, $batch);
        $idsStr = implode(',', $mlbIds);

        // Build player_id lookup by mlb_id
        $pidMap = [];
        foreach ($batch as $p) {
            $pidMap[(int)$p['mlb_id']] = (int)$p['player_id'];
        }

        try {
            $url  = "https://statsapi.mlb.com/api/v1/people?personIds={$idsStr}&hydrate=draft";
            $json = $this->mlbApiFetch($url);
            $apiData = json_decode($json, true);
        } catch (\Throwable $e) {
            jsonError('MLB API error: ' . $e->getMessage(), 500);
            return;
        }

        $updated = 0;
        foreach ($apiData['people'] ?? [] as $person) {
            $mlbId = (int)($person['id'] ?? 0);
            if (!$mlbId || !isset($pidMap[$mlbId])) continue;

            $drafts = $person['drafts'] ?? [];
            if (empty($drafts)) continue;

            // Use the most recent (last) draft entry
            $lastDraft = $drafts[count($drafts) - 1];
            $draftYear  = isset($lastDraft['year']) ? (int)$lastDraft['year'] : null;
            $draftRound = $lastDraft['pickRound'] ?? null;
            $draftPick  = isset($lastDraft['pickNumber']) ? (int)$lastDraft['pickNumber'] : null;

            if (!$draftYear) continue;

            $pdo->prepare(
                "UPDATE CG_Players SET draft_year = :dy, draft_round = :dr, draft_pick = :dp WHERE player_id = :pid"
            )->execute([':dy' => $draftYear, ':dr' => $draftRound, ':dp' => $draftPick, ':pid' => $pidMap[$mlbId]]);
            $updated++;
        }

        $nextOffset = $batchOffset + $batchSize;
        $isComplete = $nextOffset >= $total;

        jsonResponse([
            'message'         => "Processed " . min($nextOffset, $total) . " of {$total} players",
            'complete'        => $isComplete,
            'next_offset'     => $nextOffset,
            'players_updated' => $updated,
            'total'           => $total,
        ]);
    }

    /**
     * Populate popularity_score from MLB Network Top 100 Players for 2025.
     * Matches by last_name + first_name against CG_Players.
     */
    public function importPopularityRankings(array $params = []): void
    {
        Auth::requireAdmin();
        $pdo = cg_db();

        // MLB Network Top 100 Players Right Now for 2025 (from mlb.com)
        $rankings = [
            1   => ['Shohei', 'Ohtani'],
            2   => ['Aaron', 'Judge'],
            3   => ['Bobby', 'Witt Jr.'],
            4   => ['Juan', 'Soto'],
            5   => ['Mookie', 'Betts'],
            6   => ['Francisco', 'Lindor'],
            7   => ['Yordan', 'Alvarez'],
            8   => ['Freddie', 'Freeman'],
            9   => ['Jose', 'Ramirez'],
            10  => ['Gunnar', 'Henderson'],
            11  => ['Tarik', 'Skubal'],
            12  => ['Bryce', 'Harper'],
            13  => ['Vladimir', 'Guerrero Jr.'],
            14  => ['Kyle', 'Tucker'],
            15  => ['Paul', 'Skenes'],
            16  => ['Ronald', 'Acuna Jr.'],
            17  => ['Corey', 'Seager'],
            18  => ['Ketel', 'Marte'],
            19  => ['Zack', 'Wheeler'],
            20  => ['Chris', 'Sale'],
            21  => ['Rafael', 'Devers'],
            22  => ['Fernando', 'Tatis Jr.'],
            23  => ['Julio', 'Rodriguez'],
            24  => ['Jackson', 'Merrill'],
            25  => ['Corbin', 'Burnes'],
            26  => ['Gerrit', 'Cole'],
            27  => ['Jarren', 'Duran'],
            28  => ['William', 'Contreras'],
            29  => ['Manny', 'Machado'],
            30  => ['Jose', 'Altuve'],
            31  => ['Elly', 'De La Cruz'],
            32  => ['Corbin', 'Carroll'],
            33  => ['Austin', 'Riley'],
            34  => ['Matt', 'Olson'],
            35  => ['Trea', 'Turner'],
            36  => ['Blake', 'Snell'],
            37  => ['Alex', 'Bregman'],
            38  => ['Matt', 'Chapman'],
            39  => ['Mike', 'Trout'],
            40  => ['Jackson', 'Chourio'],
            41  => ['Willy', 'Adames'],
            42  => ['Carlos', 'Correa'],
            43  => ['Cole', 'Ragans'],
            44  => ['Max', 'Fried'],
            45  => ['Framber', 'Valdez'],
            46  => ['Brent', 'Rooker'],
            47  => ['Marcell', 'Ozuna'],
            48  => ['Christian', 'Walker'],
            49  => ['Pete', 'Alonso'],
            50  => ['Logan', 'Webb'],
            51  => ['Logan', 'Gilbert'],
            52  => ['Teoscar', 'Hernandez'],
            53  => ['Anthony', 'Santander'],
            54  => ['Riley', 'Greene'],
            55  => ['Dylan', 'Cease'],
            56  => ['Garrett', 'Crochet'],
            57  => ['Emmanuel', 'Clase'],
            58  => ['Adley', 'Rutschman'],
            59  => ['Cal', 'Raleigh'],
            60  => ['Will', 'Smith'],
            61  => ['Christian', 'Yelich'],
            62  => ['Marcus', 'Semien'],
            63  => ['Yoshinobu', 'Yamamoto'],
            64  => ['Shota', 'Imanaga'],
            65  => ['Kyle', 'Schwarber'],
            66  => ['Steven', 'Kwan'],
            67  => ['Michael', 'Harris II'],
            68  => ['Byron', 'Buxton'],
            69  => ['Michael', 'King'],
            70  => ['Hunter', 'Greene'],
            71  => ['Tyler', 'Glasnow'],
            72  => ['Cody', 'Bellinger'],
            73  => ['Seiya', 'Suzuki'],
            74  => ['Seth', 'Lugo'],
            75  => ['George', 'Kirby'],
            76  => ['Zac', 'Gallen'],
            77  => ['Devin', 'Williams'],
            78  => ['Mason', 'Miller'],
            79  => ['Salvador', 'Perez'],
            80  => ['J.T.', 'Realmuto'],
            81  => ['Mark', 'Vientos'],
            82  => ['Royce', 'Lewis'],
            83  => ['Luis', 'Arraez'],
            84  => ['Jurickson', 'Profar'],
            85  => ['Isaac', 'Paredes'],
            86  => ['Willson', 'Contreras'],
            87  => ['Bryce', 'Miller'],
            88  => ['Justin', 'Steele'],
            89  => ['Kerry', 'Carpenter'],
            90  => ['Lawrence', 'Butler'],
            91  => ['Brandon', 'Nimmo'],
            92  => ['Ian', 'Happ'],
            93  => ['Dansby', 'Swanson'],
            94  => ['Masyn', 'Winn'],
            95  => ['Nolan', 'Arenado'],
            96  => ['Paul', 'Goldschmidt'],
            97  => ['Wyatt', 'Langford'],
            98  => ['James', 'Wood'],
            99  => ['Jacob', 'deGrom'],
            100 => ['Roki', 'Sasaki'],
        ];

        // Clear all existing popularity scores first
        $pdo->exec("UPDATE CG_Players SET popularity_score = NULL");

        // Prepare lookup: match by first_name + last_name (case-insensitive)
        $stmt = $pdo->prepare(
            "UPDATE CG_Players SET popularity_score = :rank
             WHERE LOWER(first_name) = LOWER(:first) AND LOWER(last_name) = LOWER(:last)
             LIMIT 1"
        );

        $matched = 0;
        $unmatched = [];
        foreach ($rankings as $rank => [$first, $last]) {
            $stmt->execute([':rank' => $rank, ':first' => $first, ':last' => $last]);
            if ($stmt->rowCount() > 0) {
                $matched++;
            } else {
                $unmatched[] = "{$rank}. {$first} {$last}";
            }
        }

        jsonResponse([
            'message'   => "Popularity rankings updated: {$matched}/100 players matched",
            'matched'   => $matched,
            'total'     => 100,
            'unmatched' => $unmatched,
        ]);
    }

    /**
     * Backfill draft_status for players missing it.
     * Uses MLB API birthCountry + mlbDebutDate to classify:
     *   Drafted   = has draft data (already set by migration)
     *   Pre-Draft = debuted before the 1965 MLB Draft
     *   Intl FA   = birthCountry outside USA (international free agent)
     *   Undrafted  = USA-born, post-draft era, never drafted
     */
    public function backfillDraftStatus(array $params = []): void
    {
        Auth::requireAdmin();
        set_time_limit(60);
        $pdo  = cg_db();
        $body = getJsonBody();
        $batchOffset = (int)($body['batch_offset'] ?? 0);
        $batchSize   = 50;

        // Players needing draft_status: have mlb_id but no draft_status yet
        $stmt = $pdo->query(
            "SELECT player_id, mlb_id FROM CG_Players
             WHERE mlb_id IS NOT NULL AND (draft_status IS NULL OR draft_status = '')
             ORDER BY player_id"
        );
        $allPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = count($allPlayers);

        if ($total === 0 || $batchOffset >= $total) {
            // Also set any remaining players without mlb_id
            $pdo->exec("UPDATE CG_Players SET draft_status = 'Unknown' WHERE draft_status IS NULL AND mlb_id IS NULL");
            jsonResponse(['message' => 'Draft status backfill complete', 'complete' => true, 'total' => $total, 'updated' => 0]);
            return;
        }

        $batch = array_slice($allPlayers, $batchOffset, $batchSize);
        $mlbIds = array_map(function ($p) { return (int)$p['mlb_id']; }, $batch);
        $idsStr = implode(',', $mlbIds);

        $pidMap = [];
        foreach ($batch as $p) {
            $pidMap[(int)$p['mlb_id']] = (int)$p['player_id'];
        }

        try {
            $url  = "https://statsapi.mlb.com/api/v1/people?personIds={$idsStr}&hydrate=draft";
            $json = $this->mlbApiFetch($url);
            $apiData = json_decode($json, true);
        } catch (\Throwable $e) {
            jsonError('MLB API error: ' . $e->getMessage(), 500);
            return;
        }

        $updated = 0;
        $updateStmt = $pdo->prepare(
            "UPDATE CG_Players SET draft_status = :status,
                    draft_year = COALESCE(draft_year, :dy),
                    draft_round = COALESCE(draft_round, :dr),
                    draft_pick = COALESCE(draft_pick, :dp)
             WHERE player_id = :pid"
        );

        foreach ($apiData['people'] ?? [] as $person) {
            $mlbId = (int)($person['id'] ?? 0);
            if (!$mlbId || !isset($pidMap[$mlbId])) continue;

            $drafts       = $person['drafts'] ?? [];
            $birthCountry = $person['birthCountry'] ?? 'USA';
            $debutDate    = $person['mlbDebutDate'] ?? null;
            $debutYear    = $debutDate ? (int)substr($debutDate, 0, 4) : null;

            $draftYear = null;
            $draftRound = null;
            $draftPick = null;

            if (!empty($drafts)) {
                // Player was drafted
                $status = 'Drafted';
                $lastDraft = $drafts[count($drafts) - 1];
                $draftYear  = isset($lastDraft['year']) ? (int)$lastDraft['year'] : null;
                $draftRound = $lastDraft['pickRound'] ?? null;
                $draftPick  = isset($lastDraft['pickNumber']) ? (int)$lastDraft['pickNumber'] : null;
            } elseif ($debutYear && $debutYear < 1965) {
                // Pre-draft era (MLB Draft started in 1965)
                $status = 'Pre-Draft';
            } elseif ($birthCountry !== 'USA') {
                // International free agent
                $status = 'Intl FA';
            } else {
                // USA-born, post-draft era, not drafted
                $status = 'Undrafted';
            }

            $updateStmt->execute([
                ':status' => $status,
                ':dy'     => $draftYear,
                ':dr'     => $draftRound,
                ':dp'     => $draftPick,
                ':pid'    => $pidMap[$mlbId],
            ]);
            $updated++;
        }

        // Handle any in this batch that the API didn't return (rare)
        foreach ($pidMap as $mlbId => $playerId) {
            $check = $pdo->prepare("SELECT draft_status FROM CG_Players WHERE player_id = :pid");
            $check->execute([':pid' => $playerId]);
            if (empty($check->fetchColumn())) {
                $pdo->prepare("UPDATE CG_Players SET draft_status = 'Unknown' WHERE player_id = :pid")
                    ->execute([':pid' => $playerId]);
            }
        }

        $nextOffset = $batchOffset + $batchSize;
        $isComplete = $nextOffset >= $total;

        jsonResponse([
            'message'         => "Processed " . min($nextOffset, $total) . " of {$total} players",
            'complete'        => $isComplete,
            'next_offset'     => $nextOffset,
            'players_updated' => $updated,
            'total'           => $total,
        ]);
    }

    private function mlbApiFetch(string $url): string
    {
        // -g disables curl globbing (treats [] literally in URLs)
        // -sS is silent but shows errors
        // -k allows self-signed certs
        $cmd = '/usr/bin/curl -g -sS -k --max-time 20 ' . escapeshellarg($url) . ' 2>&1';
        $result = shell_exec($cmd);

        if ($result === null || $result === '') {
            throw new \RuntimeException("curl returned empty for: {$url}");
        }

        // Check if curl returned an error message instead of JSON
        if (strpos($result, 'curl:') === 0) {
            throw new \RuntimeException("curl error: " . trim($result));
        }

        // Validate JSON
        $decoded = json_decode($result, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON from MLB API: " . substr($result, 0, 200));
        }

        return $result;
    }
}
