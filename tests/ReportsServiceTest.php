<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require __DIR__ . '/../app/src/ReportsService.php';

final class ReportsServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Emulate MySQL TIMESTAMP(datum, vreme) in SQLite for tests.
        $this->pdo->sqliteCreateFunction(
            'TIMESTAMP',
            static function (string $date, string $time): string {
                return $date . ' ' . $time;
            },
            2
        );

        $this->pdo->exec("
            CREATE TABLE test_log_r (
                id INTEGER PRIMARY KEY,
                id_posla INTEGER,
                id_aktivnosti INTEGER,
                datum TEXT,
                vreme TEXT,
                id_radnika TEXT,
                ime_radnika TEXT
            )
        ");
    }

    private function insertRow(
        int $id,
        ?int $idPosla,
        int $idAktivnosti,
        string $date,
        string $time,
        string $workerId,
        string $workerName
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO test_log_r (id, id_posla, id_aktivnosti, datum, vreme, id_radnika, ime_radnika)
            VALUES (:id, :id_posla, :id_aktivnosti, :datum, :vreme, :id_radnika, :ime_radnika)
        ");
        $stmt->execute([
            'id'            => $id,
            'id_posla'      => $idPosla,
            'id_aktivnosti' => $idAktivnosti,
            'datum'         => $date,
            'vreme'         => $time,
            'id_radnika'    => $workerId,
            'ime_radnika'   => $workerName,
        ]);
    }

    public function testReportByWorkerMergesOverlappingJobsFor0075On2025_11_03(): void
    {
        // Data for worker 0075 on 2025-11-03 (simplified from dump):
        // I1: 190066  16:30:23 -> 21:57:37
        // I2: 190072  16:30:29 -> 21:57:53
        // I3: 190073  17:33:34 -> 21:58:07
        // I4: 190065  19:49:57 -> 21:57:04

        $workerId = '0075';
        $workerName = 'radnik_75';
        $date = '2025-11-03';

        // Starts
        $this->insertRow(380, 190066, 2, $date, '16:30:23', $workerId, $workerName);
        $this->insertRow(381, 190072, 2, $date, '16:30:29', $workerId, $workerName);
        $this->insertRow(382, 190073, 2, $date, '17:33:34', $workerId, $workerName);
        $this->insertRow(390, 190065, 2, $date, '19:49:57', $workerId, $workerName);

        // Ends
        $this->insertRow(510, 190065, 6, $date, '21:57:04', $workerId, $workerName);
        $this->insertRow(517, 190066, 6, $date, '21:57:37', $workerId, $workerName);
        $this->insertRow(522, 190072, 6, $date, '21:57:53', $workerId, $workerName);
        $this->insertRow(525, 190073, 6, $date, '21:58:07', $workerId, $workerName);

        $_ENV['WORKDAY_CUTOFF_TIME'] = '06:00:00';

        $service = new ReportsService($this->pdo);
        $rows = $service->reportByWorker($workerId, $date, $date);

        // Exactly one row for 2025-11-03
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame($workerId, $row['id_radnika']);
        $this->assertSame($workerName, $row['ime_radnika']);
        $this->assertSame($date, $row['date']);

        // Merged interval: 16:30:23–21:58:07 = 19 664 seconds = 05:27:44
        $this->assertSame(19664, $row['seconds']);
        $this->assertSame('05:27:44', $row['duration']);
    }

    public function testReportByWorkerMergesOverlapsFor0016SyntheticDay(): void
    {
        // Synthetic but illustrative example for 0016:
        // job A: 08:00–10:00
        // job B: 09:00–11:00 (overlaps with A)
        // job C: 15:00–16:00 (separate block)
        //
        // Union = [08:00, 11:00] + [15:00, 16:00] = 3h + 1h = 4h = 14 400 seconds.

        $workerId = '0016';
        $workerName = 'radnik_16';
        $date = '2025-11-03';

        // Starts
        $this->insertRow(1, 300001, 2, $date, '08:00:00', $workerId, $workerName); // A start
        $this->insertRow(2, 300002, 2, $date, '09:00:00', $workerId, $workerName); // B start
        $this->insertRow(3, 300003, 2, $date, '15:00:00', $workerId, $workerName); // C start

        // Ends
        $this->insertRow(4, 300001, 6, $date, '10:00:00', $workerId, $workerName); // A end
        $this->insertRow(5, 300002, 6, $date, '11:00:00', $workerId, $workerName); // B end
        $this->insertRow(6, 300003, 6, $date, '16:00:00', $workerId, $workerName); // C end

        $_ENV['WORKDAY_CUTOFF_TIME'] = '06:00:00';

        $service = new ReportsService($this->pdo);
        $rows = $service->reportByWorker($workerId, $date, $date);

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame($workerId, $row['id_radnika']);
        $this->assertSame($workerName, $row['ime_radnika']);
        $this->assertSame($date, $row['date']);

        $this->assertSame(14400, $row['seconds']);
        $this->assertSame('04:00:00', $row['duration']);
    }

    public function testCrossMidnightIntervalAssignedToStartWorkDay(): void
    {
        // Worker 0099: job started at 22:00 and finished at 02:00 next calendar day.
        // With cutoff 06:00 this all counts as a single work day of the start date (2025-11-20).

        $workerId = '0099';
        $workerName = 'radnik_99';

        // Start: 2025-11-20 22:00:00
        $this->insertRow(100, 400001, 2, '2025-11-20', '22:00:00', $workerId, $workerName);
        // End:   2025-11-21 02:00:00
        $this->insertRow(101, 400001, 6, '2025-11-21', '02:00:00', $workerId, $workerName);

        $_ENV['WORKDAY_CUTOFF_TIME'] = '06:00:00';

        $service = new ReportsService($this->pdo);
        $rows = $service->reportByWorker($workerId, '2025-11-20', '2025-11-21');

        // Single day — 2025-11-20.
        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertSame('2025-11-20', $row['date']);

        // 22:00:00 -> 02:00:00 = 4 hours = 14 400 seconds
        $this->assertSame(14400, $row['seconds']);
        $this->assertSame('04:00:00', $row['duration']);
    }

    public function testJobStartedByOneWorkerFinishedByAnotherCountsForBoth(): void
    {
        // One id_posla, two workers:
        // worker A starts, worker B finishes; both should get full duration credited.

        $jobId = 500001;

        // worker A
        $this->insertRow(200, $jobId, 2, '2025-11-10', '08:00:00', '0001', 'radnik_1');

        // worker B
        $this->insertRow(201, $jobId, 6, '2025-11-10', '10:00:00', '0002', 'radnik_2');

        $_ENV['WORKDAY_CUTOFF_TIME'] = '06:00:00';

        $service = new ReportsService($this->pdo);

        $rowsA = $service->reportByWorker('0001', '2025-11-10', '2025-11-10');
        $rowsB = $service->reportByWorker('0002', '2025-11-10', '2025-11-10');

        $this->assertCount(1, $rowsA);
        $this->assertCount(1, $rowsB);

        $this->assertSame(7200, $rowsA[0]['seconds']);
        $this->assertSame('02:00:00', $rowsA[0]['duration']);

        $this->assertSame(7200, $rowsB[0]['seconds']);
        $this->assertSame('02:00:00', $rowsB[0]['duration']);
    }
}

