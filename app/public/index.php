<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/I18n.php';
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
    $i18n = I18n::fromRequest();

    try {
        $service = new ReportsService(Db::pdo());
    } catch (Throwable $e) {
        Http::serverError($i18n->t('error.db_connection'));
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
                Http::badRequest($i18n->t('error.invalid_date'));
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
                Http::badRequest($i18n->t('error.invalid_id'));
                exit;
            }

            if ($from === null || $to === null || !isValidDate($from) || !isValidDate($to)) {
                Http::badRequest($i18n->t('error.invalid_from_to'));
                exit;
            }

            if ($from > $to) {
                Http::badRequest($i18n->t('error.from_gt_to'));
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
            'error' => $i18n->t('error.unknown_route'),
        ], 404);
        exit;
    } catch (Throwable $e) {
        Http::serverError($i18n->t('error.server'));
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
