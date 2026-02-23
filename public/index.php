<?php
/**
 * Card Graph — Front Controller
 *
 * All /api/* requests are routed here by .htaccess.
 * Non-API requests are served as static files or fall through to app.html.
 */

require_once __DIR__ . '/../src/bootstrap.php';

$router = new Router();

// === Auth routes (no auth required) ===
$router->post('/api/auth/login', ['AuthController', 'login'], false);

// === Auth routes (auth required) ===
$router->post('/api/auth/logout', ['AuthController', 'logout']);
$router->get('/api/auth/me', ['AuthController', 'me']);

// === CSV Uploads ===
$router->post('/api/uploads/earnings', ['UploadController', 'earnings']);
$router->post('/api/uploads/payouts', ['UploadController', 'payouts']);
$router->get('/api/uploads', ['UploadController', 'list']);

// === Dashboard ===
$router->get('/api/dashboard/summary', ['DashboardController', 'summary']);
$router->get('/api/dashboard/trends', ['DashboardController', 'trends']);

// === Line Items ===
$router->get('/api/line-items', ['LineItemController', 'index']);
$router->get('/api/line-items/{id}', ['LineItemController', 'show']);
$router->put('/api/line-items/{id}/status', ['StatusController', 'update']);
$router->get('/api/livestreams', ['LineItemController', 'livestreams']);

// === Item Costs ===
$router->get('/api/costs', ['CostController', 'index']);
$router->post('/api/costs', ['CostController', 'store']);
$router->put('/api/costs/{id}', ['CostController', 'update']);
$router->delete('/api/costs/{id}', ['CostController', 'destroy']);

// === Payouts ===
$router->get('/api/payouts', ['PayoutController', 'index']);
$router->post('/api/payouts', ['PayoutController', 'store']);
$router->put('/api/payouts/{id}', ['PayoutController', 'update']);
$router->delete('/api/payouts/{id}', ['PayoutController', 'destroy']);

// === Top Buyers ===
$router->get('/api/top-buyers/livestreams', ['TopBuyersController', 'livestreams']);
$router->get('/api/top-buyers', ['TopBuyersController', 'index']);

// === Financial Summary ===
$router->get('/api/financial-summary/overview', ['FinancialSummaryController', 'overview']);
$router->get('/api/financial-summary/costs', ['FinancialSummaryController', 'listCosts']);
$router->post('/api/financial-summary/costs', ['FinancialSummaryController', 'createCost']);
$router->put('/api/financial-summary/costs/{id}', ['FinancialSummaryController', 'updateCost']);
$router->delete('/api/financial-summary/costs/{id}', ['FinancialSummaryController', 'deleteCost']);

// === eBay Transactions ===
$router->get('/api/ebay/summary', ['EbayController', 'summary']);
$router->get('/api/ebay/orders', ['EbayController', 'listOrders']);
$router->get('/api/ebay/orders/{id}', ['EbayController', 'showOrder']);
$router->put('/api/ebay/orders/{id}', ['EbayController', 'updateOrder']);
$router->delete('/api/ebay/orders/{id}', ['EbayController', 'deleteOrder']);
$router->post('/api/ebay/import', ['EbayController', 'importEmails']);

// === Status Types ===
$router->get('/api/statuses', ['StatusController', 'index']);

// === User Management (admin) ===
$router->get('/api/users', ['UserController', 'index']);
$router->post('/api/users', ['UserController', 'store']);
$router->put('/api/users/{id}', ['UserController', 'update']);

// === Cost Matrix (admin) ===
$router->get('/api/cost-matrix/rules', ['CostMatrixController', 'listRules']);
$router->post('/api/cost-matrix/rules', ['CostMatrixController', 'createRule']);
$router->put('/api/cost-matrix/rules/{id}', ['CostMatrixController', 'updateRule']);
$router->delete('/api/cost-matrix/rules/{id}', ['CostMatrixController', 'deleteRule']);
$router->get('/api/cost-matrix/livestreams', ['CostMatrixController', 'livestreams']);
$router->get('/api/cost-matrix/auction-summary', ['CostMatrixController', 'auctionSummary']);
$router->post('/api/cost-matrix/preview', ['CostMatrixController', 'preview']);
$router->post('/api/cost-matrix/apply', ['CostMatrixController', 'apply']);
$router->post('/api/cost-matrix/clear', ['CostMatrixController', 'clear']);

// === Analytics ===
$router->get('/api/analytics/metrics',           ['AnalyticsController', 'listMetrics']);
$router->put('/api/analytics/metrics/{id}',       ['AnalyticsController', 'updateMetric']);
$router->get('/api/analytics/milestones',         ['AnalyticsController', 'listMilestones']);
$router->post('/api/analytics/milestones',        ['AnalyticsController', 'createMilestone']);
$router->put('/api/analytics/milestones/{id}',    ['AnalyticsController', 'updateMilestone']);
$router->delete('/api/analytics/milestones/{id}', ['AnalyticsController', 'deleteMilestone']);
$router->get('/api/analytics/actuals',            ['AnalyticsController', 'getActuals']);
$router->get('/api/analytics/forecast',           ['AnalyticsController', 'getForecast']);
$router->get('/api/analytics/pacing',             ['AnalyticsController', 'getPacing']);

// === PayPal ===
$router->get('/api/paypal/transactions',        ['PayPalController', 'listTransactions']);
$router->get('/api/paypal/summary',             ['PayPalController', 'getSummary']);
$router->get('/api/paypal/types',               ['PayPalController', 'getTypes']);
$router->get('/api/paypal/transactions/{id}',   ['PayPalController', 'getTransaction']);
$router->delete('/api/paypal/transactions/{id}',['PayPalController', 'deleteTransaction']);
$router->post('/api/paypal/allocations',        ['PayPalController', 'assignTransaction']);
$router->put('/api/paypal/allocations/{id}',    ['PayPalController', 'updateAllocation']);
$router->delete('/api/paypal/allocations/{id}', ['PayPalController', 'deleteAllocation']);
$router->post('/api/paypal/auto-assign',        ['PayPalController', 'autoAssign']);
$router->post('/api/paypal/lock',               ['PayPalController', 'lockAllocations']);
$router->post('/api/paypal/unlock',             ['PayPalController', 'unlockAllocations']);
$router->get('/api/paypal/assignments/summary', ['PayPalController', 'getAssignmentSummary']);
$router->get('/api/paypal/sources-summary',     ['PayPalController', 'getSourcesSummary']);
$router->get('/api/paypal/sources-list',        ['PayPalController', 'getSourcesList']);
$router->post('/api/uploads/paypal',            ['UploadController', 'paypal']);

// === Alerts & Notifications ===
$router->get('/api/alerts',                  ['AlertController', 'listAlerts']);
$router->post('/api/alerts',                 ['AlertController', 'createAlert']);
$router->get('/api/alerts/active',           ['AlertController', 'getActiveAlerts']);
$router->get('/api/alerts/scroll',           ['AlertController', 'getScrollSettings']);
$router->put('/api/alerts/scroll',           ['AlertController', 'updateScrollSettings']);
$router->get('/api/alerts/scroll/data',      ['AlertController', 'getScrollData']);
$router->put('/api/alerts/{id}',             ['AlertController', 'updateAlert']);
$router->delete('/api/alerts/{id}',          ['AlertController', 'deleteAlert']);
$router->put('/api/alerts/{id}/toggle',      ['AlertController', 'toggleAlert']);
$router->post('/api/alerts/{id}/dismiss',    ['AlertController', 'dismissAlert']);

// === Transcription ===
$router->get('/api/transcription/settings',             ['TranscriptionController', 'getSettings']);
$router->put('/api/transcription/settings',             ['TranscriptionController', 'updateSettings']);
$router->get('/api/transcription/sessions',             ['TranscriptionController', 'listSessions']);
$router->post('/api/transcription/sessions',            ['TranscriptionController', 'createSession']);
$router->get('/api/transcription/sessions/{id}',        ['TranscriptionController', 'getSession']);
$router->put('/api/transcription/sessions/{id}',        ['TranscriptionController', 'updateSession']);
$router->delete('/api/transcription/sessions/{id}',     ['TranscriptionController', 'deleteSession']);
$router->post('/api/transcription/sessions/{id}/start', ['TranscriptionController', 'startSession']);
$router->post('/api/transcription/sessions/{id}/stop',  ['TranscriptionController', 'stopSession']);
$router->post('/api/transcription/sessions/{id}/cancel',['TranscriptionController', 'cancelSession']);
$router->get('/api/transcription/sessions/{id}/status', ['TranscriptionController', 'getSessionStatus']);
$router->get('/api/transcription/sessions/{id}/logs',   ['TranscriptionController', 'getSessionLogs']);
$router->get('/api/transcription/env-check',            ['TranscriptionController', 'envCheck']);
$router->post('/api/transcription/scheduler-tick',      ['TranscriptionController', 'schedulerTick'], false);
$router->post('/api/transcription/cleanup',              ['TranscriptionController', 'cleanupExpired'], false);
$router->post('/api/transcription/docker-build',        ['TranscriptionController', 'dockerBuild'], false);
$router->get('/api/transcription/docker-build-status',  ['TranscriptionController', 'dockerBuildStatus'], false);

// === Table Transcriptions (parse transcript text → card records) ===
$router->post('/api/transcription/sessions/{id}/parse',           ['TranscriptionController', 'parseSession']);
$router->get('/api/transcription/sessions/{id}/records',          ['TranscriptionController', 'listRecords']);
$router->put('/api/transcription/records/{id}',                   ['TranscriptionController', 'updateRecord']);
$router->delete('/api/transcription/records/{id}',                ['TranscriptionController', 'deleteRecord']);
$router->get('/api/transcription/sessions/{id}/transcript-text',  ['TranscriptionController', 'getTranscriptText']);
$router->get('/api/transcription/sessions/{id}/parse-runs',       ['TranscriptionController', 'listParseRuns']);
$router->post('/api/transcription/sessions/{id}/transcribe',     ['TranscriptionController', 'transcribeSession']);

// === Parser Support Tables (admin) ===
$router->get('/api/parser/players',                     ['ParserController', 'listPlayers']);
$router->post('/api/parser/players',                    ['ParserController', 'createPlayer']);
$router->put('/api/parser/players/{id}',                ['ParserController', 'updatePlayer']);
$router->delete('/api/parser/players/{id}',             ['ParserController', 'deletePlayer']);
$router->post('/api/parser/players/{id}/nicknames',     ['ParserController', 'addNickname']);
$router->put('/api/parser/nicknames/{id}',              ['ParserController', 'updateNickname']);
$router->delete('/api/parser/nicknames/{id}',           ['ParserController', 'deleteNickname']);

$router->get('/api/parser/teams',                       ['ParserController', 'listTeams']);
$router->post('/api/parser/teams',                      ['ParserController', 'createTeam']);
$router->put('/api/parser/teams/{id}',                  ['ParserController', 'updateTeam']);
$router->delete('/api/parser/teams/{id}',               ['ParserController', 'deleteTeam']);
$router->post('/api/parser/teams/{id}/aliases',         ['ParserController', 'addAlias']);
$router->put('/api/parser/aliases/{id}',                ['ParserController', 'updateAlias']);
$router->delete('/api/parser/aliases/{id}',             ['ParserController', 'deleteAlias']);

$router->get('/api/parser/makers',                      ['ParserController', 'listMakers']);
$router->post('/api/parser/makers',                     ['ParserController', 'createMaker']);
$router->put('/api/parser/makers/{id}',                 ['ParserController', 'updateMaker']);
$router->delete('/api/parser/makers/{id}',              ['ParserController', 'deleteMaker']);

$router->get('/api/parser/styles',                      ['ParserController', 'listStyles']);
$router->post('/api/parser/styles',                     ['ParserController', 'createStyle']);
$router->put('/api/parser/styles/{id}',                 ['ParserController', 'updateStyle']);
$router->delete('/api/parser/styles/{id}',              ['ParserController', 'deleteStyle']);

$router->get('/api/parser/specialties',                 ['ParserController', 'listSpecialties']);
$router->post('/api/parser/specialties',                ['ParserController', 'createSpecialty']);
$router->put('/api/parser/specialties/{id}',            ['ParserController', 'updateSpecialty']);
$router->delete('/api/parser/specialties/{id}',         ['ParserController', 'deleteSpecialty']);

// === Parser Stats Refresh ===
$router->get('/api/parser/refresh/status',              ['ParserController', 'getRefreshStatus']);
$router->post('/api/parser/refresh/standings',          ['ParserController', 'refreshTeamStandings']);
$router->post('/api/parser/refresh/rosters',            ['ParserController', 'refreshTeamRosters']);
$router->post('/api/parser/refresh/milb-rosters',       ['ParserController', 'refreshMinorLeagueRosters']);
$router->post('/api/parser/refresh/historical',         ['ParserController', 'refreshHistoricalPlayers']);
$router->post('/api/parser/refresh/backfill-draft',    ['ParserController', 'backfillDraftInfo']);
$router->post('/api/parser/refresh/popularity',        ['ParserController', 'importPopularityRankings']);
$router->post('/api/parser/refresh/backfill-status',   ['ParserController', 'backfillDraftStatus']);

// === MLB ===
$router->get('/api/mlb/schedule',       ['MlbController', 'getSchedule']);
$router->get('/api/mlb/game/{id}',      ['MlbController', 'getGameDetail']);
$router->get('/api/mlb/standings',       ['MlbController', 'getStandings']);
$router->get('/api/mlb/wild-card',       ['MlbController', 'getWildCardStandings']);
$router->get('/api/mlb/team-profile',    ['MlbController', 'getTeamProfile']);
$router->get('/api/mlb/team-affiliates', ['MlbController', 'getTeamAffiliates']);
$router->get('/api/mlb/team-roster',     ['MlbController', 'getTeamRoster']);
$router->get('/api/mlb/postseason',      ['MlbController', 'getPostseason']);

// === MiLB ===
$router->get('/api/milb/standings',      ['MlbController', 'getMilbStandings']);
$router->get('/api/milb/teams',          ['MlbController', 'getMilbTeams']);

// === Maintenance ===
$router->get('/api/maintenance/upload-log', ['MaintenanceController', 'uploadLog']);

// === Health check (no auth) ===
$router->get('/api/health', ['MaintenanceController', 'health'], false);

$router->dispatch();
