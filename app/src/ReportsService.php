<?php

declare(strict_types=1);

final class ReportsService
{
    private const DEFAULT_CUTOFF_TIME = '06:00:00';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Returns the factory work day (radni dan) for the given timestamp.
     * Work day runs 06:00–05:59 next day; times before 06:00 belong to the previous calendar day.
     */
    private static function timestampToWorkDay(\DateTimeImmutable $dt): string
    {
        $cutoffTime = $_ENV['WORKDAY_CUTOFF_TIME'] ?? getenv('WORKDAY_CUTOFF_TIME') ?: self::DEFAULT_CUTOFF_TIME;
        $time = $dt->format('H:i:s');
        if ($time < $cutoffTime) {
            return $dt->modify('-1 day')->format('Y-m-d');
        }
        return $dt->format('Y-m-d');
    }

    /**
     * Fetch raw events (activity 2, 3, 6) in the given calendar date range (inclusive).
     *
     * @return list<array{id_posla: int, id_aktivnosti: int, id_radnika: string, ime_radnika: string, event_dt: string}>
     */
    private function fetchEvents(string $dateFrom, string $dateTo): array
    {
        $sql = <<<SQL
            SELECT
                id_posla,
                id_aktivnosti,
                id_radnika,
                ime_radnika,
                TIMESTAMP(datum, vreme) AS event_dt
            FROM test_log_r
            WHERE id_aktivnosti IN (2, 3, 6)
              AND id_posla IS NOT NULL
              AND datum BETWEEN :date_from AND :date_to
            ORDER BY id_posla, event_dt
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): array {
            return [
                'id_posla' => (int) $row['id_posla'],
                'id_aktivnosti' => (int) $row['id_aktivnosti'],
                'id_radnika' => (string) $row['id_radnika'],
                'ime_radnika' => (string) $row['ime_radnika'],
                'event_dt' => (string) $row['event_dt'],
            ];
        }, $rows);
    }

    /**
     * Build (start_dt, end_dt) pairs per job with correction logic; expand to all workers involved (start and end).
     *
     * @param list<array{id_posla: int, id_aktivnosti: int, id_radnika: string, ime_radnika: string, event_dt: string}> $events
     * @return list<array{id_radnika: string, ime_radnika: string, id_posla: int, start_dt: string, end_dt: string}>
     */
    private function buildIntervals(array $events): array
    {
        $byPosla = [];
        foreach ($events as $e) {
            $byPosla[$e['id_posla']][] = $e;
        }
        $intervals = [];
        foreach ($byPosla as $idPosla => $list) {
            $ends = [];
            foreach ($list as $e) {
                if ($e['id_aktivnosti'] === 6) {
                    $ends[] = ['dt' => $e['event_dt'], 'id_radnika' => $e['id_radnika'], 'ime_radnika' => $e['ime_radnika']];
                }
            }
            foreach ($ends as $end) {
                $endDt = $end['dt'];
                $validStart = null;
                $startWorker = null;
                foreach ($list as $e) {
                    if ($e['id_aktivnosti'] !== 2 || $e['event_dt'] >= $endDt) {
                        continue;
                    }
                    $hasCorrection = false;
                    foreach ($list as $c) {
                        if ($c['id_aktivnosti'] === 3 && $c['event_dt'] > $e['event_dt'] && $c['event_dt'] < $endDt) {
                            $hasCorrection = true;
                            break;
                        }
                    }
                    if (!$hasCorrection && ($validStart === null || $e['event_dt'] > $validStart)) {
                        $validStart = $e['event_dt'];
                        $startWorker = ['id_radnika' => $e['id_radnika'], 'ime_radnika' => $e['ime_radnika']];
                    }
                }
                if ($validStart === null) {
                    continue;
                }
                $workers = [$startWorker];
                if ($startWorker['id_radnika'] !== $end['id_radnika'] || $startWorker['ime_radnika'] !== $end['ime_radnika']) {
                    $workers[] = ['id_radnika' => $end['id_radnika'], 'ime_radnika' => $end['ime_radnika']];
                }
                foreach ($workers as $w) {
                    $intervals[] = [
                        'id_radnika' => $w['id_radnika'],
                        'ime_radnika' => $w['ime_radnika'],
                        'id_posla' => $idPosla,
                        'start_dt' => $validStart,
                        'end_dt' => $endDt,
                    ];
                }
            }
        }
        return $intervals;
    }

    /**
     * Merge overlapping intervals and return total seconds.
     *
     * @param list<array{start_dt: string, end_dt: string}> $intervals
     */
    private function mergeIntervalSeconds(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }
        usort($intervals, static function (array $a, array $b): int {
            return strcmp($a['start_dt'], $b['start_dt']);
        });
        $merged = [];
        $curStart = $intervals[0]['start_dt'];
        $curEnd = $intervals[0]['end_dt'];
        for ($i = 1; $i < count($intervals); $i++) {
            $nextStart = $intervals[$i]['start_dt'];
            $nextEnd = $intervals[$i]['end_dt'];
            if ($nextStart <= $curEnd) {
                if ($nextEnd > $curEnd) {
                    $curEnd = $nextEnd;
                }
            } else {
                $merged[] = ['start_dt' => $curStart, 'end_dt' => $curEnd];
                $curStart = $nextStart;
                $curEnd = $nextEnd;
            }
        }
        $merged[] = ['start_dt' => $curStart, 'end_dt' => $curEnd];
        $total = 0;
        foreach ($merged as $m) {
            $s = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m['start_dt']);
            $e = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $m['end_dt']);
            if ($s !== false && $e !== false) {
                $total += $e->getTimestamp() - $s->getTimestamp();
            }
        }
        return $total;
    }

    public function reportByDay(string $date): array
    {
        $from = (new \DateTimeImmutable($date . ' 00:00:00'))->modify('-1 day')->format('Y-m-d');
        $to = (new \DateTimeImmutable($date . ' 00:00:00'))->modify('+1 day')->format('Y-m-d');
        $events = $this->fetchEvents($from, $to);
        $intervals = $this->buildIntervals($events);
        $byWorkerPosla = [];
        foreach ($intervals as $iv) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $iv['start_dt']);
            if ($dt === false) {
                continue;
            }
            $workDay = self::timestampToWorkDay($dt);
            if ($workDay !== $date) {
                continue;
            }
            $key = $iv['id_radnika'] . "\0" . $iv['id_posla'];
            if (!isset($byWorkerPosla[$key])) {
                $byWorkerPosla[$key] = [
                    'id_radnika' => $iv['id_radnika'],
                    'ime_radnika' => $iv['ime_radnika'],
                    'id_posla' => $iv['id_posla'],
                    'intervals' => [],
                ];
            }
            $byWorkerPosla[$key]['intervals'][] = ['start_dt' => $iv['start_dt'], 'end_dt' => $iv['end_dt']];
        }
        $result = [];
        foreach ($byWorkerPosla as $data) {
            $seconds = $this->mergeIntervalSeconds($data['intervals']);
            $result[] = [
                'id_radnika' => $data['id_radnika'],
                'ime_radnika' => $data['ime_radnika'],
                'id_posla' => $data['id_posla'],
                'seconds' => $seconds,
                'duration' => self::secondsToDuration($seconds),
            ];
        }
        usort($result, static function (array $a, array $b): int {
            $c = strcmp($a['id_radnika'], $b['id_radnika']);
            return $c !== 0 ? $c : $a['id_posla'] <=> $b['id_posla'];
        });
        return $result;
    }

    /**
     * Reportable date range: from one day before first datum (so work started before 06:00
     * on the first data day is attributed to that earlier work day) through last datum.
     */
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

        $firstDatum = (string) $row['date_from'];
        $reportFrom = (new \DateTimeImmutable($firstDatum . ' 00:00:00'))->modify('-1 day')->format('Y-m-d');

        return [
            'from' => $reportFrom,
            'to' => (string) $row['date_to'],
        ];
    }

    public function reportByWorker(string $workerId, string $fromDate, string $toDate): array
    {
        $from = (new \DateTimeImmutable($fromDate . ' 00:00:00'))->modify('-1 day')->format('Y-m-d');
        $to = (new \DateTimeImmutable($toDate . ' 00:00:00'))->modify('+1 day')->format('Y-m-d');
        $events = $this->fetchEvents($from, $to);
        $intervals = $this->buildIntervals($events);
        $byWorkDayPosla = [];
        foreach ($intervals as $iv) {
            if ($iv['id_radnika'] !== $workerId) {
                continue;
            }
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $iv['start_dt']);
            if ($dt === false) {
                continue;
            }
            $workDay = self::timestampToWorkDay($dt);
            if ($workDay < $fromDate || $workDay > $toDate) {
                continue;
            }
            $key = $workDay . "\0" . $iv['id_posla'];
            if (!isset($byWorkDayPosla[$key])) {
                $byWorkDayPosla[$key] = [
                    'ime_radnika' => $iv['ime_radnika'],
                    'date' => $workDay,
                    'id_posla' => $iv['id_posla'],
                    'intervals' => [],
                ];
            }
            $byWorkDayPosla[$key]['intervals'][] = ['start_dt' => $iv['start_dt'], 'end_dt' => $iv['end_dt']];
        }
        $result = [];
        foreach ($byWorkDayPosla as $data) {
            $seconds = $this->mergeIntervalSeconds($data['intervals']);
            $result[] = [
                'id_radnika' => $workerId,
                'ime_radnika' => $data['ime_radnika'],
                'date' => $data['date'],
                'id_posla' => $data['id_posla'],
                'seconds' => $seconds,
                'duration' => self::secondsToDuration($seconds),
            ];
        }
        usort($result, static function (array $a, array $b): int {
            $c = strcmp($a['date'], $b['date']);
            return $c !== 0 ? $c : $a['id_posla'] <=> $b['id_posla'];
        });
        return $result;
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
