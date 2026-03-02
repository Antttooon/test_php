<?php

declare(strict_types=1);

final class ReportsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function reportByDay(string $date): array
    {
        $sql = <<<SQL
WITH events AS (
    SELECT
        id,
        id_posla,
        id_aktivnosti,
        id_radnika,
        ime_radnika,
        TIMESTAMP(datum, vreme) AS event_dt
    FROM test_log_r
    WHERE id_aktivnosti IN (2, 3, 6)
      AND id_posla IS NOT NULL
),
end_events AS (
    SELECT
        e.id AS end_id,
        e.id_posla,
        e.id_radnika,
        e.ime_radnika,
        e.event_dt AS end_dt
    FROM events e
    WHERE e.id_aktivnosti = 6
),
paired AS (
    SELECT
        ee.id_radnika,
        ee.ime_radnika,
        ee.end_dt,
        (
            SELECT MAX(s.event_dt)
            FROM events s
            WHERE s.id_posla = ee.id_posla
              AND s.id_aktivnosti = 2
              AND s.event_dt < ee.end_dt
              AND NOT EXISTS (
                    SELECT 1
                    FROM events c
                    WHERE c.id_posla = ee.id_posla
                      AND c.id_aktivnosti = 3
                      AND c.event_dt > s.event_dt
                      AND c.event_dt < ee.end_dt
              )
        ) AS start_dt
    FROM end_events ee
)
SELECT
    id_radnika,
    MAX(ime_radnika) AS ime_radnika,
    SUM(TIMESTAMPDIFF(SECOND, start_dt, end_dt)) AS total_seconds
FROM paired
WHERE start_dt IS NOT NULL
  AND DATE(end_dt) = :report_date
GROUP BY id_radnika
ORDER BY id_radnika ASC
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['report_date' => $date]);
        $rows = $stmt->fetchAll();

        return array_map(static function (array $row): array {
            $seconds = (int) $row['total_seconds'];
            return [
                'id_radnika' => (string) $row['id_radnika'],
                'ime_radnika' => (string) $row['ime_radnika'],
                'seconds' => $seconds,
                'duration' => self::secondsToDuration($seconds),
            ];
        }, $rows);
    }

    public function availablePeriod(): ?array
    {
        $sql = <<<SQL
SELECT
    MIN(datum) AS date_from,
    MAX(datum) AS date_to
FROM test_log_r
WHERE datum IS NOT NULL
SQL;

        $stmt = $this->pdo->query($sql);
        $row = $stmt->fetch();

        if (!is_array($row) || $row['date_from'] === null || $row['date_to'] === null) {
            return null;
        }

        return [
            'from' => (string) $row['date_from'],
            'to' => (string) $row['date_to'],
        ];
    }

    public function reportByWorker(string $workerId, string $fromDate, string $toDate): array
    {
        $sql = <<<SQL
WITH events AS (
    SELECT
        id,
        id_posla,
        id_aktivnosti,
        id_radnika,
        ime_radnika,
        TIMESTAMP(datum, vreme) AS event_dt
    FROM test_log_r
    WHERE id_aktivnosti IN (2, 3, 6)
      AND id_posla IS NOT NULL
),
end_events AS (
    SELECT
        e.id AS end_id,
        e.id_posla,
        e.id_radnika,
        e.ime_radnika,
        e.event_dt AS end_dt
    FROM events e
    WHERE e.id_aktivnosti = 6
),
paired AS (
    SELECT
        ee.id_radnika,
        ee.ime_radnika,
        ee.end_dt,
        (
            SELECT MAX(s.event_dt)
            FROM events s
            WHERE s.id_posla = ee.id_posla
              AND s.id_aktivnosti = 2
              AND s.event_dt < ee.end_dt
              AND NOT EXISTS (
                    SELECT 1
                    FROM events c
                    WHERE c.id_posla = ee.id_posla
                      AND c.id_aktivnosti = 3
                      AND c.event_dt > s.event_dt
                      AND c.event_dt < ee.end_dt
              )
        ) AS start_dt
    FROM end_events ee
)
SELECT
    DATE(end_dt) AS work_date,
    MAX(ime_radnika) AS ime_radnika,
    SUM(TIMESTAMPDIFF(SECOND, start_dt, end_dt)) AS total_seconds
FROM paired
WHERE start_dt IS NOT NULL
  AND id_radnika = :worker_id
  AND DATE(end_dt) BETWEEN :date_from AND :date_to
GROUP BY DATE(end_dt)
ORDER BY DATE(end_dt) ASC
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'worker_id' => $workerId,
            'date_from' => $fromDate,
            'date_to' => $toDate,
        ]);
        $rows = $stmt->fetchAll();

        return array_map(static function (array $row) use ($workerId): array {
            $seconds = (int) $row['total_seconds'];
            return [
                'id_radnika' => $workerId,
                'ime_radnika' => (string) $row['ime_radnika'],
                'date' => (string) $row['work_date'],
                'seconds' => $seconds,
                'duration' => self::secondsToDuration($seconds),
            ];
        }, $rows);
    }

    private static function secondsToDuration(int $seconds): string
    {
        if ($seconds < 0) {
            $seconds = 0;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
}
