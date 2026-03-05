-- Индексы для ускорения отчётов (report/day и report/worker).
-- Выполнить на проде один раз: mysql -u... -p test_php < task/add_indexes.sql

-- Фильтр по типу активности и id_posla (CTE events)
ALTER TABLE test_log_r
  ADD INDEX idx_aktivnosti_posla (id_aktivnosti, id_posla);

-- Фильтр по дате в отчётах (WHERE DATE(end_dt) = ... / BETWEEN)
ALTER TABLE test_log_r
  ADD INDEX idx_datum (datum);

-- Фильтр по работнику (report/worker: id_radnika = :worker_id)
ALTER TABLE test_log_r
  ADD INDEX idx_radnika (id_radnika);

-- Составной индекс для поиска пар start(2)/correction(3)/end(6) по id_posla и времени
ALTER TABLE test_log_r
  ADD INDEX idx_posla_aktivnosti_datum_vreme (id_posla, id_aktivnosti, datum, vreme);
