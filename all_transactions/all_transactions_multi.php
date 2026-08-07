<?php
  ini_set('display_errors', 0);
  error_reporting(0);

  require_once __DIR__ . '/../../ots/constant.php';

  if (session_status() != PHP_SESSION_ACTIVE) {
    session_start();
  }
  $_SESSION[GN_LAST_ACTIVE] = time();

  require_once __DIR__ . '/../../ots/session_handler.php';

  if (!isset($_SESSION[GC_LOGIN_COOKIE]))
  {
    header("Location: ../login.php");
    exit;
  }

  require_once __DIR__ . '/../lib/bootstrap.php';
  require_once __DIR__ . '/../lib/auth.php';
  build_user_context_from_ots();
  if (is_admin()) {
    if (!isset($_SESSION[GN_CHURCH_ID]) || $_SESSION[GN_CHURCH_ID] <= 0) {
      $_SESSION[GN_CHURCH_ID] = 1;
    }
  } else {
    $acs = get_accessible_church_ids();
    if (empty($acs)) {
      header("Location: ../login.php");
      exit;
    }
    $selected = intval($_SESSION['revizor_selected_church'] ?? 0);
    if ($selected <= 0 || !in_array($selected, $acs, true)) {
      unset($_SESSION['revizor_selected_church'], $_SESSION['revizor_selected_church_name']);
      if (count($acs) === 1) {
        set_selected_church_session($acs[0]);
        $selected = intval($acs[0]);
      } else {
        header("Location: ../select-church.php?redirect=all_transactions/all_transactions_multi.php");
        exit;
      }
    }
    $_SESSION[GN_CHURCH_ID] = $selected;
  }

  // A Webix keretrendszer betöltése (abszolút /ots/ útvonalakkal, mert nincs virtuális host)
  $ots_root = '/ots';
  $mc_skin = GetCurrentSkin();
  $mc_lang = GetCurrentLanguage();
  $skin_css = GetSkinCSS();
  echo '<!DOCTYPE html>' . "\n";
  echo '<html lang="' . $mc_lang . '">' . "\n";
  echo '  <head>' . "\n";
  echo '  <meta name="robots" content="noindex" />' . "\n";
  echo '  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />' . "\n";
  echo '  <title>🕵️ Revizor Asszisztens 1.0 – OTS Tranzakciók</title>' . "\n";
  echo '  <script type="text/javascript">webix_skin = "' . $mc_skin . '";</script>' . "\n";
  echo '  <script src="' . $ots_root . '/ots_icons.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/3rdparty/webix/' . $skin_css . '.css">' . "\n";
  echo '  <script src="' . $ots_root . '/3rdparty/webix/webix.min.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <script src="' . $ots_root . '/3rdparty/webix/i18n/' . $mc_lang . '.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/3rdparty/fontawesome/css/fontawesome.min.css">' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/3rdparty/fontawesome/css/solid.min.css">' . "\n";
  echo '  <script type="text/javascript">top.gc_DisplayLanguage = "' . $mc_lang . '";</script>' . "\n";
  echo '  <script src="' . $ots_root . '/i18n/' . $mc_lang . '.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <script src="' . $ots_root . '/language.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/css/ots.css">' . "\n";
  echo '  <link rel="stylesheet" href="' . $ots_root . '/css/ots_' . $mc_skin . '.css">' . "\n";
  echo '  <script src="' . $ots_root . '/penztar_utils.js" type="text/javascript" charset="utf-8"></script>' . "\n";
  echo '  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n";
  echo '</head>' . "\n";
  echo '<body>' . "\n";
?>
<div class="d-flex justify-content-between align-items-center mb-3 px-3 py-2 bg-white rounded border shadow-sm flex-wrap gap-2" style="font-family:sans-serif;">
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="../index.php" class="btn btn-outline-secondary btn-sm">🏠 Kezdőlap</a>
        <span class="fw-bold">🕵️ Revizor Asszisztens 1.0</span>
        <span class="text-muted mx-1">|</span>
        <span class="text-muted">OTS Tranzakciók</span>
    </div>
    <div class="d-flex align-items-center gap-1">
        <a href="../help.php" class="btn btn-outline-primary btn-sm">❓ Súgó</a>
        <?php render_dev_toggle(); ?>
        <?php render_user_badge(); ?>
        <a href="../logout.php" class="btn btn-outline-danger btn-sm">Kilépés</a>
    </div>
</div>
<style>
.export_page_title .webix_el_box {
  font-size: 20px;
  font-weight: 700;
  text-align: center;
  letter-spacing: 0;
}
</style>
<script type="text/javascript">
function cleanExcelText(value) {
  if (typeof value !== "string") return value;
  return value.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, " ");
}

function cleanExcelFormulaText(value) {
  value = cleanExcelText(String(value || ""));
  return /^[=+\-@]/.test(value) ? "'" + value : value;
}

function escapeCell(value) {
  value = value == null ? "" : String(value);
  if (webix.template && webix.template.escape) {
    return webix.template.escape(value);
  }
  return value.replace(/[&<>"']/g, function(ch) {
    return {"&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;"}[ch];
  });
}

function escapeAttr(value) {
  return String(value == null ? "" : value)
    .replace(/&/g, "&amp;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function cleanTableDataForExcel(table) {
  var fields = ["RECEIPT_NUMBER", "DECISION_NUMBER", "DATETIME", "DESCRIPTION", "NAME", "EDITOR"];
  table.data.each(function(row) {
    for (var i = 0; i < fields.length; i++) {
      if (row[fields[i]]) row[fields[i]] = cleanExcelFormulaText(row[fields[i]]);
    }
  });
}

function formatDateYMD(value) {
  if (!value) value = new Date();
  if (value instanceof Date) {
    return webix.Date.dateToStr("%Y-%m-%d")(value);
  }
  return String(value).substring(0, 10).replace(/[\.\/]/g, "-");
}

function formatDateDots(value) {
  if (!value) return "";
  if (value instanceof Date) {
    return webix.Date.dateToStr("%Y.%m.%d")(value);
  }
  return String(value).substring(0, 10).replace(/[-\/]/g, ".");
}

function safeFileNamePart(value) {
  return cleanExcelText(String(value || "Gyulekezet"))
    .replace(/[\\\/:*?"<>|]+/g, "_")
    .replace(/\s+/g, "_")
    .replace(/^_+|_+$/g, "") || "Gyulekezet";
}

function getCookieValue(name) {
  var cookies = document.cookie ? document.cookie.split(";") : [];
  var prefix = name + "=";
  for (var i = 0; i < cookies.length; i++) {
    var part = cookies[i].replace(/^\s+/, "");
    if (part.indexOf(prefix) === 0) {
      return decodeURIComponent(part.substring(prefix.length));
    }
  }
  return "";
}

function setCookieValue(name, value, days) {
  var expires = new Date();
  expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
  document.cookie = name + "=" + encodeURIComponent(value) + "; expires=" + expires.toUTCString() + "; path=/; SameSite=Lax";
}

function setCookieValueHours(name, value, hours) {
  var expires = new Date();
  expires.setTime(expires.getTime() + (hours * 60 * 60 * 1000));
  document.cookie = name + "=" + encodeURIComponent(value) + "; expires=" + expires.toUTCString() + "; path=/; SameSite=Lax";
}

function dateFromYMD(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value || "")) return null;
  var parts = value.split("-");
  return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
}

function savedStartDate() {
  return dateFromYMD(getCookieValue("OTS_BANK_EXPORT_V2_START_DATE"));
}

function savedChurchId(defaultChurchId) {
  var churchId = getCookieValue("OTS_ALL_TRANSACTIONS_MULTI_CHURCH_ID");
  return /^\d+$/.test(churchId || "") ? Number(churchId) : defaultChurchId;
}

function savedFlow() {
  var flow = getCookieValue("OTS_ALL_TRANSACTIONS_MULTI_FLOW");
  return /^(bank|cash|both)$/.test(flow || "") ? flow : "bank";
}

function rememberShortSelections(values) {
  setCookieValueHours("OTS_ALL_TRANSACTIONS_MULTI_CHURCH_ID", values.church_id || "", 2);
  setCookieValueHours("OTS_ALL_TRANSACTIONS_MULTI_FLOW", values.flow || "bank", 2);
}

function showLoadError(err, url) {
  var status = err && err.status ? " HTTP " + err.status : "";
  var message = "Az adatok letöltése nem sikerült." + status + " Kérjük, frissítsd az oldalt vagy jelezd az üzemeltetőnek.";
  webix.message({type: "error", text: cleanExcelText(message), expire: 15000});
}

function flowLabel(flow) {
  if (flow === "cash") return "Keszpenz";
  if (flow === "both") return "Bank_es_keszpenz";
  return "Bank";
}

function getCleanExportRows(table) {
  var rows = [];
  table.eachRow(function(rowId) {
    var row = table.getItem(rowId);
    rows.push({
      RECEIPT_NUMBER: cleanExcelFormulaText(row.RECEIPT_NUMBER || ""),
      DECISION_NUMBER: cleanExcelFormulaText(row.DECISION_NUMBER || ""),
      DATETIME: cleanExcelFormulaText(formatDateDots(row.DATETIME)),
      FLOW: cleanExcelText(row.FLOW || ""),
      DESCRIPTION: cleanExcelFormulaText(row.DESCRIPTION || ""),
      NAME: cleanExcelFormulaText(row.NAME || ""),
      SUMAMOUNT: isNaN(Number(row.SUMAMOUNT)) ? 0 : Number(row.SUMAMOUNT),
      EDITOR: cleanExcelFormulaText(row.EDITOR || ""),
      balance: isNaN(Number(row.balance)) ? 0 : Number(row.balance)
    });
  });
  return rows;
}

function exportCleanExcel(filename) {
  var tempId = "data_table_excel_export";
  if ($$(tempId)) {
    $$(tempId).destructor();
  }

  var temp = webix.ui({
    view: "datatable",
    id: tempId,
    hidden: true,
    columns: [
      { id: "RECEIPT_NUMBER", header: "Bizonylatszám", width: 120 },
      { id: "DECISION_NUMBER", header: "Biz. határozati szám", width: 150 },
      { id: "DATETIME", header: "Dátum", width: 100 },
      { id: "FLOW", header: "Forgalom", width: 110 },
      { id: "DESCRIPTION", header: "Partner / Megjegyzes", width: 320 },
      { id: "NAME", header: "Tipus", width: 180 },
      { id: "SUMAMOUNT", header: "Osszeg", width: 120, exportType: "number", exportFormat: "#,##0" },
      { id: "EDITOR", header: "Rogzitette", width: 150 },
      { id: "balance", header: "Egyenleg", width: 120, exportType: "number", exportFormat: "#,##0" }
    ],
    data: getCleanExportRows($$("data_table"))
  });

  webix.toExcel(temp, {
    filename: filename,
    name: "Tranzakciok",
    rawValues: true
  });

  window.setTimeout(function() {
    if ($$(tempId)) {
      $$(tempId).destructor();
    }
  }, 2000);
}

webix.ui.datafilter.rowCount = {
  refresh: function(master, node, config) {
    var count = master.count();
    var total = master.data && master.data.pull ? Object.keys(master.data.pull).length : count;
    node.innerHTML = "<div style='text-align:left; font-weight:bold; padding-left:10px;'>Képernyőn lévő sorok: " + count + " (Letöltött rekordok: " + total + ")</div>";
  },
  render: function(master, config) {
    return "<div style='text-align:left; font-weight:bold; padding-left:10px;'>Képernyőn lévő sorok: 0</div>";
  }
};

webix.ui.datafilter.lastBalance = {
  refresh: function(master, node, config) {
    var lastId = master.getLastId();
    var lastVal = 0;
    if (lastId) {
      var item = master.getItem(lastId);
      if (item && item.balance !== undefined) lastVal = item.balance;
    }
    node.innerHTML = webix.i18n.intFormat(lastVal);
  },
  render: function(master, config) {
    return "0";
  }
};

// Több kulcsszavas AND szűrő a Partner / Megjegyzés oszlophoz.
function splitMultiFilterTokens(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/\b(vagy|or)\b/g, " ")
    .split(/[\s,;|]+/)
    .filter(function(token) {
      return token.length > 0;
    });
}

function multiTextFilterCompare(value, filter, item) {
  var tokens = splitMultiFilterTokens(filter);
  if (tokens.length === 0) return true;

  var hay = (value == null ? "" : String(value)).toLowerCase();
  for (var i = 0; i < tokens.length; i++) {
    if (hay.indexOf(tokens[i]) === -1) return false;
  }
  return true;
}

webix.ui.datafilter.multiTextFilter = webix.extend({
  refresh: function(master, node, config) {
    config.compare = multiTextFilterCompare;
    return webix.ui.datafilter.textFilter.refresh.call(this, master, node, config);
  },
  compare: function(value, filter, item) {
    var tokens = splitMultiFilterTokens(filter);
    if (tokens.length === 0) return true;

    var hay = (value == null ? "" : String(value)).toLowerCase();
    for (var i = 0; i < tokens.length; i++) {
      if (hay.indexOf(tokens[i]) === -1) return false;
    }
    return true;
  }
}, webix.ui.datafilter.textFilter);

function updateNavLabel() {
  var table = $$("data_table");
  if (!table) return;
  var selected = table.getSelectedId();
  var all = table.order ? table.order : [];
  if (all.length === 0) { $$("nav_label").setValue("0 / 0"); return; }
  var idx = selected ? all.indexOf(selected) + 1 : 0;
  $$("nav_label").setValue(idx + " / " + all.length);
}

webix.ready(function(){
  // Egyedi Webix felület az exportáláshoz
  webix.ui({
    rows: [
      {
        view: "label",
        id: "page_title",
        label: "OTS Tranzakciók Lekérdezése",
        align: "center",
        height: 44,
        css: "export_page_title"
      },
      {
        view: "form",
        id: "export_form",
        padding: 15,
        elements: [
          {
            cols: [
              { view: "datepicker", label: "Kezdő dátum:", name: "start_date", format: "%Y.%m.%d", stringResult: true, value: savedStartDate(), labelWidth: 105, width: 220 },
              { view: "datepicker", label: "Befejező dátum:", name: "end_date", format: "%Y.%m.%d", stringResult: true, value: new Date(), labelWidth: 115, width: 230 },
              { view: "combo", label: "Gyülekezet:", name: "church_id", options: "/ots/church_for_combo.php?userole=1", value: savedChurchId(<?php echo intval(!is_admin() && !empty($_SESSION['revizor_selected_church']) ? $_SESSION['revizor_selected_church'] : (isset($_SESSION[GN_CHURCH_ID]) ? $_SESSION[GN_CHURCH_ID] : 0)); ?>), labelWidth: 90, width: 270<?= !is_admin() ? ', readonly:true' : '' ?> },
              { view: "segmented", label: "Forgalom:", name: "flow", value: savedFlow(), options: [
                  { id: "bank", value: "<span class='webix_icon fas fa-university' title='Bank'></span>" },
                  { id: "cash", value: "<span class='webix_icon fas fa-money-bill-wave' title='Készpénz'></span>" },
                  { id: "both", value: "<span class='webix_icon fas fa-university' title='Mindkettő'></span> <span class='webix_icon fas fa-money-bill-wave' title='Mindkettő'></span>" }
                ], labelWidth: 80, width: 180 },
              {}, // üres hely
              { view: "button", value: "Szűrők törlése", width: 115, click: function() {
                  var table = $$("data_table");
                  table.eachColumn(function(id, col) {
                      var filter = table.getFilter(id);
                      if (filter) filter.value = "";
                  });
                  table.filterByAll();
              }},
              { view: "button", value: "Lekérdezés", css: "webix_primary", width: 115, click: function() {
                  var vals = $$("export_form").getValues();

                  if (!vals.start_date || !vals.church_id) {
                    webix.message({type: "error", text: "Kérjük, add meg a gyülekezetet és a kezdő dátumot!"});
                    return;
                  }

                  webix.message("Adatok lekérése folyamatban...");
                  $$("data_table").clearAll();

                  var start_str = formatDateYMD(vals.start_date);
                  var end_str = formatDateYMD(vals.end_date);
                  var flow = vals.flow || "bank";
                  var data_url = "all_transactions_multi_dt.php?start=" + encodeURIComponent(start_str) + "&end=" + encodeURIComponent(end_str) + "&church_id=" + encodeURIComponent(vals.church_id) + "&flow=" + encodeURIComponent(flow);
                  setCookieValue("OTS_BANK_EXPORT_V2_START_DATE", start_str, 365);
                  rememberShortSelections(vals);
                  $$("data_table").load(data_url)
                  .fail(function(err) {
                      showLoadError(err, data_url);
                  });
              }},
              { view: "button", value: "Exportálás Excelbe", width: 155, click: function() {
                  var vals = $$("export_form").getValues();
                  if ($$("data_table").count() === 0) {
                    webix.message({type: "error", text: "Nincs mit exportálni! Először futtass egy lekérdezést."});
                    return;
                  }
                  var start_str = vals.start_date ? formatDateYMD(vals.start_date) : 'kezdet';
                  var end_str = formatDateYMD(vals.end_date);
                  var flow = vals.flow || "bank";
                  var church_combo = $$("export_form").elements.church_id;
                  var church_name = church_combo ? church_combo.getText() : vals.church_id;
                  var church_prefix = safeFileNamePart(church_name);
                  cleanTableDataForExcel($$("data_table"));
                  exportCleanExcel(church_prefix + "_OTS_" + flowLabel(flow) + "_Tranzakciok_" + start_str + "_tol_" + end_str);
              }}
            ]
          }
        ]
      },
      {
        view: "toolbar",
        padding: 4,
        elements: [
          {},
          {
            view: "button", type: "icon", icon: "fas fa-chevron-left", width: 40,
            click: function() {
              var table = $$("data_table");
              var selected = table.getSelectedId();
              if (!selected) { table.select(table.getFirstId()); return; }
              var prev = table.getPrevId(selected);
              if (prev) { table.select(prev); table.showItem(prev); updateNavLabel(); }
            }
          },
          {
            view: "label", id: "nav_label", label: "0 / 0", align: "center", width: 100
          },
          {
            view: "button", type: "icon", icon: "fas fa-chevron-right", width: 40,
            click: function() {
              var table = $$("data_table");
              var selected = table.getSelectedId();
              if (!selected) { table.select(table.getFirstId()); return; }
              var next = table.getNextId(selected);
              if (next) { table.select(next); table.showItem(next); updateNavLabel(); }
            }
          },
          {}
        ]
      },
      {
        // Adatok megjelenítése
        view: "datatable",
        id: "data_table",
        autoConfig: true,
        footer: true,
        select: "row",
        columns: [
            { id: "RECORD_ID", header: "", width: 0, hidden: true },
            { id: "actions", header: "", width: 45, template: function(obj) {
                if (obj.VIA_BANK === 0) {
                    return "<span class='cash-check-btn' onclick='openCashAudit(" + obj.RECORD_ID + ",\"" + escapeAttr(obj.DATETIME) + "\"," + (obj.SUMAMOUNT || 0) + ",\"" + escapeAttr(obj.RECEIPT_NUMBER || "") + "\",\"" + escapeAttr(obj.DESCRIPTION || "") + "\")' style='cursor:pointer;' title='Készpénz ellenőrzés'>🔍</span>";
                }
                return "";
            }, css: { "text-align": "center" } },
            { id: "RECEIPT_NUMBER", header: ["Bizonylatszám", { content: "textFilter" }], width: 120, sort: "string", template: function(obj) { return escapeCell(obj.RECEIPT_NUMBER); }, footer: "" },
            { id: "DECISION_NUMBER", header: ["Biz. határozati szám", { content: "textFilter" }], width: 150, sort: "string", template: function(obj) { return escapeCell(obj.DECISION_NUMBER); }, footer: "" },
            { id: "DATETIME", header: ["Dátum", { content: "textFilter" }], width: 105, sort: "string", template: function(obj) { return escapeCell(formatDateDots(obj.DATETIME)); }, footer: "" },
            { id: "FLOW", header: ["Forgalom", { content: "selectFilter" }], width: 120, sort: "string", template: function(obj) { return escapeCell(obj.FLOW); }, footer: "" },
            { id: "DESCRIPTION", header: ["Partner / Megjegyzés", { content: "multiTextFilter", placeholder: "szó vagy másik" }], fillspace: true, sort: "string", template: function(obj) { return escapeCell(obj.DESCRIPTION); }, footer: "" },
            { id: "NAME", header: ["Típus", { content: "textFilter" }], width: 220, sort: "string", template: function(obj) { return escapeCell(obj.NAME); }, footer: "" },
            { id: "SUMAMOUNT", header: ["Összeg", { content: "textFilter" }], width: 150, sort: "int", format: webix.i18n.intFormat, css: { "text-align": "right" }, exportType: "number", exportFormat: "#,##0", footer: { content: "summColumn", css: { "text-align": "right" } } },
            { id: "EDITOR", header: ["Rögzítette", { content: "selectFilter" }], width: 150, sort: "string", template: function(obj) { return escapeCell(obj.EDITOR); }, footer: "" },
            { id: "balance", header: ["Egyenleg", ""], width: 150, sort: "int", format: webix.i18n.intFormat, css: { "text-align": "right" }, exportType: "number", exportFormat: "#,##0", footer: { content: "lastBalance", css: { "text-align": "right" } } }
        ],
        on: {
            onAfterLoad: function() {
                this.sort("DATETIME", "asc", "date");
                updateNavLabel();
            },
            onSelectChange: function() {
                updateNavLabel();
            }
        }
      }
    ]
  });

  webix.delay(function() {
    var form = $$("export_form");
    if (!form) return;

    var church = form.elements.church_id;
    var flow = form.elements.flow;
    if (church) {
      church.attachEvent("onChange", function() {
        rememberShortSelections(form.getValues());
      });
    }
    if (flow) {
      flow.attachEvent("onChange", function() {
        rememberShortSelections(form.getValues());
      });
    }
  }, null, null, 100);

  // record_id URL param kezelése — OTS-ből ugrás
  var urlRecordId = (new URLSearchParams(window.location.search)).get('record_id');
  var urlChurchId = (new URLSearchParams(window.location.search)).get('church_id');
  if (urlRecordId) {
    var now = new Date();
    var start = new Date(now.getFullYear(), 0, 1);
    var form = $$("export_form");
    if (form) {
      var vals = { start_date: start, end_date: now };
      if (urlChurchId) vals.church_id = urlChurchId;
      form.setValues(vals);
      webix.delay(function() {
        var btn = form.queryView({ view: "button", value: "Lekérdezés" });
        if (btn) btn.callEvent("onItemClick", []);
      }, null, null, 500);
    }
    // Adatok betöltődése után keressük a record_id-t
    var dt = $$("data_table");
    if (dt) {
      dt.attachEvent("onAfterLoad", function() {
        var found = dt.find(function(obj) { return String(obj.RECORD_ID) === urlRecordId; });
        if (found.length > 0) {
          dt.select(found[0].id);
          dt.showItem(found[0].id);
          updateNavLabel();
          webix.message({ type: "info", text: "Rekord #" + urlRecordId + " kiválasztva" });
        } else {
          webix.message({ type: "error", text: "Rekord #" + urlRecordId + " nem található az időablakban" });
        }
      });
    }
  }
});

var _cashAuditRecordId = 0;
function openCashAudit(recordId, dateStr, amount, docNumber, descText) {
    _cashAuditRecordId = recordId;
    document.getElementById('ca_record_id').textContent = '#' + recordId;
    document.getElementById('ca_date').textContent = dateStr || '-';
    document.getElementById('ca_amount').textContent = Number(amount).toLocaleString('hu-HU') + ' Ft';
    // Dinamikus ellenőrző lista a tétel típusa szerint (bevétel / kiadás)
    var isExpense = Number(amount) < 0;
    document.querySelectorAll('#ca_checklist .checklist-item[data-req]').forEach(function(el) {
        var req = el.getAttribute('data-req');
        if (req === 'expense') el.style.display = isExpense ? '' : 'none';
        else if (req === 'income') el.style.display = isExpense ? 'none' : '';
    });
    var lblReceiver = document.getElementById('ca_lbl_signature_receiver');
    if (lblReceiver) lblReceiver.textContent = isExpense ? 'Felvevő aláírása' : 'Befizető aláírása';
    document.getElementById('ca_amount').className = amount < 0 ? 'fw-bold text-danger' : 'fw-bold text-success';
    document.getElementById('ca_doc').textContent = docNumber || '-';
    document.getElementById('ca_desc').textContent = descText || '-';
    document.getElementById('ca_save_msg').textContent = '';
    document.getElementById('ca_inspector').value = '';
    document.getElementById('ca_notes').value = '';
    var checkboxes = document.querySelectorAll('#ca_checklist input[type="checkbox"]');
    checkboxes.forEach(function(cb) { cb.checked = false; });
    document.getElementById('ca_checklist_spinner').style.display = 'block';
    document.getElementById('ca_checklist_body').style.display = 'none';
    var modal = new bootstrap.Modal(document.getElementById('cashAuditModal'));
    modal.show();
    var url = 'all_transactions_multi_cash_audit.php?record_id=' + recordId;
    fetch(url)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        document.getElementById('ca_checklist_spinner').style.display = 'none';
        document.getElementById('ca_checklist_body').style.display = 'block';
        if (data.status === 'OK' && data.data) {
            var d = data.data;
            document.getElementById('ca_inspector').value = d.inspector_name || '';
            document.getElementById('ca_notes').value = d.notes || '';
            var fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','receipt_number_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','stamp_ok','invoice_ok','tithe_card_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok'];
            fields.forEach(function(f) {
                var cb = document.getElementById('chk_' + f);
                if (cb) cb.checked = d[f] == 1;
            });
            if (d.checked_at) {
                document.getElementById('ca_save_msg').textContent = '✓ Mentve: ' + d.checked_at;
            }
        }
    })
    .catch(function() {
        document.getElementById('ca_checklist_spinner').style.display = 'none';
        document.getElementById('ca_checklist_body').style.display = 'block';
    });
}

function saveCashAudit() {
    var data = new FormData();
    data.append('record_id', _cashAuditRecordId);
    data.append('inspector_name', document.getElementById('ca_inspector').value);
    data.append('notes', document.getElementById('ca_notes').value);
    var fields = ['cash_voucher_ok','date_filled','amount_ok','description_ok','receipt_number_ok','signature_treasurer','signature_receiver','signature_authorizer','signature_auditor','stamp_ok','invoice_ok','tithe_card_ok','decision_number_ok','fund_designation_ok','supporting_doc_ok'];
    fields.forEach(function(f) {
        var cb = document.getElementById('chk_' + f);
        data.append(f, cb && cb.checked ? '1' : '0');
    });
    data.append('csrf_token', '<?= $_SESSION['csrf_token'] ?? '' ?>');
    document.getElementById('ca_save_msg').textContent = 'Mentés...';
    document.getElementById('ca_save_msg').className = 'me-2 text-muted';
    fetch('all_transactions_multi_cash_audit.php', { method: 'POST', body: data })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.status === 'OK') {
            document.getElementById('ca_save_msg').textContent = '✓ Mentve';
            document.getElementById('ca_save_msg').className = 'me-2 text-success';
        } else {
            document.getElementById('ca_save_msg').textContent = '✗ Hiba: ' + (result.message || 'ismeretlen');
            document.getElementById('ca_save_msg').className = 'me-2 text-danger';
        }
    })
    .catch(function() {
        document.getElementById('ca_save_msg').textContent = '✗ Hálózati hiba';
        document.getElementById('ca_save_msg').className = 'me-2 text-danger';
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Cash Audit Modal -->
<div class="modal fade" id="cashAuditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">🔍 Készpénz tétel ellenőrzés</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row mb-2 g-2">
          <div class="col-md-3"><strong>Rekord #:</strong> <span id="ca_record_id"></span></div>
          <div class="col-md-3"><strong>Dátum:</strong> <span id="ca_date"></span></div>
          <div class="col-md-3"><strong>Összeg:</strong> <span id="ca_amount"></span></div>
          <div class="col-md-3"><strong>Bizonylat:</strong> <span id="ca_doc"></span></div>
        </div>
        <div class="row mb-3">
          <div class="col-12"><strong>Leírás:</strong> <span id="ca_desc" class="text-muted"></span></div>
        </div>
        <hr>
        <div id="ca_checklist">
          <div id="ca_checklist_spinner" class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Adatok betöltése...</div>
          <div id="ca_checklist_body" style="display:none;">
            <div class="row g-2">
              <div class="col-md-6">
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_cash_voucher_ok" value="1"><label class="form-check-label" for="chk_cash_voucher_ok">Pénztárbizonylat rendben</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_date_filled" value="1"><label class="form-check-label" for="chk_date_filled">Dátum kitöltve</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_amount_ok" value="1"><label class="form-check-label" for="chk_amount_ok">Összeg pontos</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_description_ok" value="1"><label class="form-check-label" for="chk_description_ok">Megnevezés pontos</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_receipt_number_ok" value="1"><label class="form-check-label" for="chk_receipt_number_ok">Bizonylatszám szerepel</label></div></div>
              </div>
              <div class="col-md-6">
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_signature_treasurer" value="1"><label class="form-check-label" for="chk_signature_treasurer">Pénztáros aláírás</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_signature_receiver" value="1"><label class="form-check-label" for="chk_signature_receiver"><span id="ca_lbl_signature_receiver">Felvevő aláírása</span></label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_signature_authorizer" value="1"><label class="form-check-label" for="chk_signature_authorizer">Utalványozó/engedélyező</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_signature_auditor" value="1"><label class="form-check-label" for="chk_signature_auditor">Ellenőr aláírása</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_stamp_ok" value="1"><label class="form-check-label" for="chk_stamp_ok">Kiállító bélyegzője / gyülekezet neve</label></div></div>
                <div class="checklist-item py-1" data-req="expense"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_invoice_ok" value="1"><label class="form-check-label" for="chk_invoice_ok">Számla megvan</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_decision_number_ok" value="1"><label class="form-check-label" for="chk_decision_number_ok">Határozati szám</label></div></div>
                <div class="checklist-item py-1" data-req="income"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_tithe_card_ok" value="1"><label class="form-check-label" for="chk_tithe_card_ok">Tizedcédula megvan</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_fund_designation_ok" value="1"><label class="form-check-label" for="chk_fund_designation_ok">Pénzalap megjelölés helyes</label></div></div>
                <div class="checklist-item py-1" data-req="common"><div class="form-check"><input class="form-check-input" type="checkbox" id="chk_supporting_doc_ok" value="1"><label class="form-check-label" for="chk_supporting_doc_ok">Egyéb melléklet</label></div></div>
              </div>
            </div>
            <hr>
            <div class="mb-2">
              <label class="form-label"><strong>Megjegyzés:</strong></label>
              <textarea id="ca_notes" class="form-control" rows="2"></textarea>
            </div>
            <div class="mb-2">
              <label class="form-label"><strong>Ellenőr neve:</strong></label>
              <input type="text" id="ca_inspector" class="form-control">
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <span id="ca_save_msg" class="me-2 small"></span>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bezár</button>
        <button type="button" class="btn btn-primary" onclick="saveCashAudit()">💾 Mentés</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>
