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

// === Maintenance ===
$router->get('/api/maintenance/upload-log', ['MaintenanceController', 'uploadLog']);

// === Health check (no auth) ===
$router->get('/api/health', ['MaintenanceController', 'health'], false);

$router->dispatch();
