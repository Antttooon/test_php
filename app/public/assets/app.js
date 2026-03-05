(function () {
  const SUPPORTED = ["en", "sr"];
  const DEFAULT_LANG = "sr";

  function getLang() {
    const params = new URLSearchParams(document.location.search);
    const lang = params.get("lang") || params.get("locale") || "";
    return SUPPORTED.includes(lang) ? lang : DEFAULT_LANG;
  }

  let currentLang = getLang();
  let messages = {};

  function interpolate(str, params) {
    if (!params || typeof params !== "object") return str;
    return str.replace(/\{\{(\w+)\}\}/g, (_, key) => (params[key] != null ? String(params[key]) : ""));
  }

  function t(key, params) {
    const raw = messages[key];
    return raw != null ? interpolate(raw, params) : key;
  }

  async function loadLocale(lang) {
    const res = await fetch("/assets/locales/" + lang + ".json");
    if (!res.ok) throw new Error("Locale failed to load");
    messages = await res.json();
    currentLang = lang;
    document.documentElement.lang = lang === "sr" ? "sr" : "en";
  }

  function applyLocale() {
    document.querySelectorAll("[data-i18n]").forEach((el) => {
      const key = el.getAttribute("data-i18n");
      if (key && el.tagName === "TITLE") {
        document.title = t(key);
      } else if (key) {
        el.textContent = t(key);
      }
    });
    document.querySelectorAll("[data-i18n-placeholder]").forEach((el) => {
      const key = el.getAttribute("data-i18n-placeholder");
      if (key) el.placeholder = t(key);
    });
  }

  function langParam() {
    return currentLang === DEFAULT_LANG ? "" : "&lang=" + encodeURIComponent(currentLang);
  }

  window.__i18n = { getLang: () => currentLang, t, loadLocale, applyLocale, langParam };
})();

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function renderTable(container, columns, rows) {
  const { t } = window.__i18n;
  if (!rows.length) {
    container.innerHTML = '<p class="muted">' + escapeHtml(t("noData")) + "</p>";
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
  const { t } = window.__i18n;
  if (!rows.length) {
    container.innerHTML = '<p class="muted">' + escapeHtml(t("noData")) + "</p>";
    return;
  }

  const head = [
    t("workerId"),
    t("workerName"),
    t("timeHms"),
    t("seconds"),
    t("action"),
  ].map((label) => `<th>${escapeHtml(label)}</th>`).join("");

  const body = rows.map((row) => `
    <tr class="clickable-row" tabindex="0" data-worker-id="${escapeHtml(row.id_radnika)}" data-worker-name="${escapeHtml(row.ime_radnika)}">
      <td>${escapeHtml(row.id_radnika)}</td>
      <td>${escapeHtml(row.ime_radnika)}</td>
      <td>${escapeHtml(row.duration)}</td>
      <td>${escapeHtml(row.seconds)}</td>
      <td><span class="context-chip">${escapeHtml(t("openByDays"))}</span></td>
    </tr>
  `).join("");

  const scrollClass = rows.length > 10 ? "table-scroll table-scroll--limited" : "table-scroll";
  container.innerHTML = `<div class="${scrollClass}"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`;
}

async function fetchReport(url) {
  const { langParam } = window.__i18n;
  const sep = url.includes("?") ? "&" : "?";
  const fullUrl = url + (langParam() ? sep + langParam().slice(1) : "");
  const response = await fetch(fullUrl);
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
  const { t } = window.__i18n;
  try {
    const data = await fetchReport("/api/meta/period");
    if (!data.period) {
      periodInfo.innerHTML = '<span class="muted">' + escapeHtml(t("periodNoData")) + "</span>";
      return;
    }

    const from = data.period.from;
    const to = data.period.to;
    periodInfo.innerHTML = t("periodRange", {
      from: "<strong>" + escapeHtml(from) + "</strong>",
      to: "<strong>" + escapeHtml(to) + "</strong>",
    });

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
    periodInfo.innerHTML = '<span class="error">' + escapeHtml(t("periodLoadError", { message: error.message })) + "</span>";
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

  const { t } = window.__i18n;
  dayResult.innerHTML = '<p class="muted">' + escapeHtml(t("loading")) + "</p>";
  try {
    const data = await fetchReport("/api/report/day?date=" + encodeURIComponent(date));
    renderDayTable(dayResult, data.rows);
  } catch (error) {
    dayResult.innerHTML = "<p class=\"error\">" + escapeHtml(error.message) + "</p>";
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

  const { t } = window.__i18n;
  workerResult.innerHTML = '<p class="muted">' + escapeHtml(t("loading")) + "</p>";
  try {
    const qs = new URLSearchParams({ id, from, to });
    const data = await fetchReport("/api/report/worker?" + qs.toString());
    renderTable(
      workerResult,
      [
        { key: "date", label: t("date") },
        { key: "id_radnika", label: t("workerId") },
        { key: "ime_radnika", label: t("workerName") },
        { key: "duration", label: t("timeHms") },
        { key: "seconds", label: t("seconds") },
      ],
      data.rows
    );
  } catch (error) {
    workerResult.innerHTML = "<p class=\"error\">" + escapeHtml(error.message) + "</p>";
  }
});

function openWorkerReportFromDayRow(workerId, workerName) {
  const { t } = window.__i18n;
  if (!lastDayReportDate) {
    return;
  }

  workerIdInput.value = workerId;
  workerFromInput.value = lastDayReportDate;
  workerToInput.value = lastDayReportDate;
  workerContext.innerHTML = t("selectedWorker", {
    name: "<strong>" + escapeHtml(workerName) + "</strong>",
    id: escapeHtml(workerId),
    date: "<strong>" + escapeHtml(lastDayReportDate) + "</strong>",
  });

  workerFromInput.scrollIntoView({ behavior: "smooth", block: "center" });

  window.setTimeout(() => {
    workerFromInput.focus();
    if (typeof workerFromInput.showPicker === "function") {
      workerFromInput.showPicker();
    }
  }, 250);
}

dayResult.addEventListener("click", (event) => {
  const row = event.target.closest("tr.clickable-row");
  if (!row) return;
  openWorkerReportFromDayRow(row.dataset.workerId || "", row.dataset.workerName || "");
});

dayResult.addEventListener("keydown", (event) => {
  if (event.key !== "Enter" && event.key !== " ") return;
  const row = event.target.closest("tr.clickable-row");
  if (!row) return;
  event.preventDefault();
  openWorkerReportFromDayRow(row.dataset.workerId || "", row.dataset.workerName || "");
});

document.getElementById("langEn").addEventListener("click", (e) => {
  e.preventDefault();
  const url = new URL(document.location.href);
  url.searchParams.set("lang", "en");
  document.location.href = url.pathname + "?" + url.searchParams.toString();
});

document.getElementById("langSr").addEventListener("click", (e) => {
  e.preventDefault();
  const url = new URL(document.location.href);
  url.searchParams.set("lang", "sr");
  document.location.href = url.pathname + "?" + url.searchParams.toString();
});

(async function init() {
  const { loadLocale, applyLocale } = window.__i18n;
  await loadLocale(window.__i18n.getLang());
  applyLocale();
  await loadPeriodInfo();
})();
