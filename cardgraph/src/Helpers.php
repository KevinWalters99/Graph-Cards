<?php
/**
 * Card Graph — Helper Functions
 */

/**
 * Convert a UTC datetime string to CST (America/Chicago).
 */
function utcToCst(string $utcDatetime): string
{
    $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone('America/Chicago'));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Send a JSON response and exit.
 */
function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response and exit.
 */
function jsonError(string $message, int $statusCode = 400): void
{
    jsonResponse(['error' => $message], $statusCode);
}

/**
 * Get JSON body from a POST/PUT request.
 */
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return [];
    }
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON body', 400);
    }
    return $data;
}

/**
 * Sanitize a string for safe output/storage.
 */
function sanitize(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

/**
 * Parse a date string and return Y-m-d format, or null if invalid.
 */
function parseDate(?string $value): ?string
{
    if (empty($value)) {
        return null;
    }
    $value = trim($value);
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Parse a datetime string and return Y-m-d H:i:s format, or null if invalid.
 */
function parseDatetime(?string $value): ?string
{
    if (empty($value)) {
        return null;
    }
    $value = trim($value);
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Parse a decimal value from a CSV string.
 */
function parseDecimal(?string $value): ?float
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $cleaned = str_replace([',', '$', ' '], '', trim($value));
    return is_numeric($cleaned) ? (float) $cleaned : null;
}

/**
 * Build a paginated SQL query with WHERE clauses.
 *
 * @param string $baseQuery  Base SELECT ... FROM ... query
 * @param array  $conditions Array of ['clause' => 'col = :param', 'param' => ':param', 'value' => $val]
 * @param string $orderBy    ORDER BY clause (e.g., 'created_at DESC')
 * @param int    $page       Current page (1-based)
 * @param int    $perPage    Items per page
 * @return array ['query' => string, 'countQuery' => string, 'params' => array]
 */
function buildPaginatedQuery(string $baseQuery, array $conditions, string $orderBy, int $page, int $perPage): array
{
    $where = '';
    $params = [];

    $clauses = [];
    foreach ($conditions as $cond) {
        $clauses[] = $cond['clause'];
        $params[$cond['param']] = $cond['value'];
    }

    if (!empty($clauses)) {
        $where = ' WHERE ' . implode(' AND ', $clauses);
    }

    $countQuery = preg_replace('/^SELECT .+ FROM/', 'SELECT COUNT(*) as total FROM', $baseQuery) . $where;

    $offset = ($page - 1) * $perPage;
    $query = $baseQuery . $where . ' ORDER BY ' . $orderBy . " LIMIT {$perPage} OFFSET {$offset}";

    return [
        'query'      => $query,
        'countQuery' => $countQuery,
        'params'     => $params,
    ];
}

/**
 * Normalize a listing title by padding numbers after # to 4 digits.
 * e.g., "#1" -> "#0001", "#13" -> "#0013", "#134" -> "#0134"
 */
function normalizeTitle(?string $title): ?string
{
    if ($title === null || $title === '') {
        return $title;
    }
    return preg_replace_callback('/#(\d{1,3})(?=\D|$)/', function ($m) {
        return '#' . str_pad($m[1], 4, '0', STR_PAD_LEFT);
    }, $title);
}
