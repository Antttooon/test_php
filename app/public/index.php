<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/ReportsService.php';

function isValidDate(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
}

function queryParam(string $name): ?string
{
    $value = $_GET[$name] ?? null;
    if (!is_string($value)) {
        return null;
    }
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($uri, '/api/')) {
    try {
        $service = new ReportsService(Db::pdo());
    } catch (Throwable $e) {
        Http::serverError('Database connection failed');
        exit;
    }

    try {
        if ($uri === '/api/meta/period') {
            $period = $service->availablePeriod();
            Http::json([
                'ok' => true,
                'meta' => [
                    'report' => 'period',
                ],
                'period' => $period,
            ]);
            exit;
        }

        if ($uri === '/api/report/day') {
            $date = queryParam('date');
            if ($date === null || !isValidDate($date)) {
                Http::badRequest('Invalid or missing `date` (expected YYYY-MM-DD).');
                exit;
            }

            $rows = $service->reportByDay($date);
            Http::json([
                'ok' => true,
                'meta' => [
                    'report' => 'day',
                    'date' => $date,
                ],
                'rows' => $rows,
            ]);
            exit;
        }

        if ($uri === '/api/report/worker') {
            $workerId = queryParam('id');
            $from = queryParam('from');
            $to = queryParam('to');

            if ($workerId === null || strlen($workerId) > 10) {
                Http::badRequest('Invalid or missing `id` (max 10 chars).');
                exit;
            }

            if ($from === null || $to === null || !isValidDate($from) || !isValidDate($to)) {
                Http::badRequest('Invalid or missing `from`/`to` (expected YYYY-MM-DD).');
                exit;
            }

            if ($from > $to) {
                Http::badRequest('`from` must be less than or equal to `to`.');
                exit;
            }

            $rows = $service->reportByWorker($workerId, $from, $to);
            Http::json([
                'ok' => true,
                'meta' => [
                    'report' => 'worker',
                    'id_radnika' => $workerId,
                    'from' => $from,
                    'to' => $to,
                ],
                'rows' => $rows,
            ]);
            exit;
        }

        Http::json([
            'ok' => false,
            'error' => 'Unknown API route.',
        ], 404);
        exit;
    } catch (Throwable $e) {
        Http::serverError();
        exit;
    }
}

$htmlPath = __DIR__ . '/index.html';
if (is_file($htmlPath)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($htmlPath);
    exit;
}

http_response_code(404);
echo 'index.html not found';
