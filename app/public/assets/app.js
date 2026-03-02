function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function renderTable(container, columns, rows) {
  if (!rows.length) {
    container.innerHTML = '<p class="muted">Нет данных за выбранные параметры.</p>';
    return;
  }

  const head = columns.map((c) => `<th>${escapeHtml(c.label)}</th>`).join("");
  const body = rows
    .map((row) => {
      const tds = columns
        .map((c) => `<td>${escapeHtml(row[c.key] ?? "")}</td>`)
        .join("");
      return `<tr>${tds}</tr>`;
    })
    .join("");

  const scrollClass = rows.length > 10 ? "table-scroll table-scroll--limited" : "table-scroll";
  container.innerHTML = `<div class="${scrollClass}"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
}

function renderDayTable(container, rows) {
  if (!rows.length) {
    container.innerHTML = '<p class="muted">Нет данных за выбранные параметры.</p>';
    return;
  }

  const head = [
    "ID работника",
    "Имя",
    "Время (HH:MM:SS)",
    "Секунды",
    "Действие",
  ].map((label) => `<th>${escapeHtml(label)}</th>`).join("");

  const body = rows.map((row) => `
    <tr class="clickable-row" tabindex="0" data-worker-id="${escapeHtml(row.id_radnika)}" data-worker-name="${escapeHtml(row.ime_radnika)}">
      <td>${escapeHtml(row.id_radnika)}</td>
      <td>${escapeHtml(row.ime_radnika)}</td>
      <td>${escapeHtml(row.duration)}</td>
      <td>${escapeHtml(row.seconds)}</td>
      <td><span class="context-chip">Открыть по дням</span></td>
    </tr>
  `).join("");

  const scrollClass = rows.length > 10 ? "table-scroll table-scroll--limited" : "table-scroll";
  container.innerHTML = `<div class="${scrollClass}"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
}

async function fetchReport(url) {
  const response = await fetch(url);
  const data = await response.json();
  if (!response.ok || !data.ok) {
    throw new Error(data.error || "Request failed");
  }
  return data;
}

const periodInfo = document.getElementById("periodInfo");
const dayDateInput = document.querySelector('#dayForm input[name="date"]');
const workerFromInput = document.querySelector('#workerForm input[name="from"]');
const workerToInput = document.querySelector('#workerForm input[name="to"]');

async function loadPeriodInfo() {
  try {
    const data = await fetchReport("/api/meta/period");
    if (!data.period) {
      periodInfo.innerHTML = '<span class="muted">Период данных: нет данных в таблице.</span>';
      return;
    }

    const from = data.period.from;
    const to = data.period.to;
    periodInfo.innerHTML = `Период данных: <strong>${escapeHtml(from)}</strong> - <strong>${escapeHtml(to)}</strong>`;

    dayDateInput.min = from;
    dayDateInput.max = to;
    if (!dayDateInput.value) {
      dayDateInput.value = to;
    }

    workerFromInput.min = from;
    workerFromInput.max = to;
    workerToInput.min = from;
    workerToInput.max = to;
    if (!workerFromInput.value) {
      workerFromInput.value = from;
    }
    if (!workerToInput.value) {
      workerToInput.value = to;
    }
  } catch (error) {
    periodInfo.innerHTML = `<span class="error">Не удалось загрузить период данных: ${escapeHtml(error.message)}</span>`;
  }
}

const dayForm = document.getElementById("dayForm");
const dayResult = document.getElementById("dayResult");
const workerContext = document.getElementById("workerContext");
const workerIdInput = document.querySelector('#workerForm input[name="id"]');
let lastDayReportDate = "";

dayForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(dayForm);
  const date = formData.get("date");
  lastDayReportDate = String(date);

  dayResult.innerHTML = '<p class="muted">Загрузка...</p>';
  try {
    const data = await fetchReport(`/api/report/day?date=${encodeURIComponent(date)}`);
    renderDayTable(dayResult, data.rows);
  } catch (error) {
    dayResult.innerHTML = `<p class="error">${escapeHtml(error.message)}</p>`;
  }
});

const workerForm = document.getElementById("workerForm");
const workerResult = document.getElementById("workerResult");
workerForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const formData = new FormData(workerForm);
  const id = formData.get("id");
  const from = formData.get("from");
  const to = formData.get("to");

  workerResult.innerHTML = '<p class="muted">Загрузка...</p>';
  try {
    const qs = new URLSearchParams({ id, from, to });
    const data = await fetchReport(`/api/report/worker?${qs.toString()}`);
    renderTable(
      workerResult,
      [
        { key: "date", label: "Дата" },
        { key: "id_radnika", label: "ID работника" },
        { key: "ime_radnika", label: "Имя" },
        { key: "duration", label: "Время (HH:MM:SS)" },
        { key: "seconds", label: "Секунды" },
      ],
      data.rows
    );
  } catch (error) {
    workerResult.innerHTML = `<p class="error">${escapeHtml(error.message)}</p>`;
  }
});

function openWorkerReportFromDayRow(workerId, workerName) {
  if (!lastDayReportDate) {
    return;
  }

  workerIdInput.value = workerId;
  workerFromInput.value = lastDayReportDate;
  workerToInput.value = lastDayReportDate;
  workerContext.innerHTML = `Выбран работник: <strong>${escapeHtml(workerName)}</strong> (${escapeHtml(workerId)}) за дату <strong>${escapeHtml(lastDayReportDate)}</strong>`;

  workerFromInput.scrollIntoView({ behavior: "smooth", block: "center" });

  // Small delay helps after smooth scroll; then focus date input and open picker when supported.
  window.setTimeout(() => {
    workerFromInput.focus();
    if (typeof workerFromInput.showPicker === "function") {
      workerFromInput.showPicker();
    }
  }, 250);
}

dayResult.addEventListener("click", (event) => {
  const row = event.target.closest("tr.clickable-row");
  if (!row) {
    return;
  }
  openWorkerReportFromDayRow(row.dataset.workerId || "", row.dataset.workerName || "");
});

dayResult.addEventListener("keydown", (event) => {
  if (event.key !== "Enter" && event.key !== " ") {
    return;
  }
  const row = event.target.closest("tr.clickable-row");
  if (!row) {
    return;
  }
  event.preventDefault();
  openWorkerReportFromDayRow(row.dataset.workerId || "", row.dataset.workerName || "");
});

loadPeriodInfo();
