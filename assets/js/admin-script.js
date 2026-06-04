jQuery(function ($) {
  let isSyncRunning = false;
  let skipLogLines = [];
  const SKIP_LOG_UI_MAX = 500;

  function escapeHtml(text) {
    return $("<div>")
      .text(text == null ? "" : String(text))
      .html();
  }

  function renderMessage(message, success) {
    $("#sai-ajax-result").html(
      '<div style="padding:12px;border-radius:8px;line-height:1.8;background:' +
        (success
          ? "#ecfdf5;color:#065f46;border:1px solid #a7f3d0"
          : "#fef2f2;color:#991b1b;border:1px solid #fecaca") +
        ';">' +
        message +
        "</div>",
    );
  }

  function resetReport() {
    skipLogLines = [];
    $("#sai-report-result").html(
      '<p class="sai-report-empty">در حال آماده‌سازی همگام‌سازی…</p>',
    );
  }

  function appendSkipLogBatch(batch) {
    if (!Array.isArray(batch) || batch.length === 0) {
      return;
    }

    batch.forEach(function (entry) {
      if (!entry || !entry.line_fa) {
        return;
      }
      skipLogLines.push(entry);
    });
  }

  function renderSkipLogList(options) {
    options = options || {};
    const hidden = parseInt(options.skip_log_hidden || 0, 10);
    const total = parseInt(
      options.skip_log_total != null ? options.skip_log_total : skipLogLines.length,
      10,
    );

    if (skipLogLines.length === 0) {
      return "";
    }

    const displayLines = skipLogLines.slice(0, SKIP_LOG_UI_MAX);
    let html =
      '<div class="sai-skip-log">' +
      '<p class="sai-skip-log-title">جزئیات رد شده (' +
      escapeHtml(total) +
      ")</p>" +
      '<ul class="sai-skip-log-list">';

    displayLines.forEach(function (entry) {
      const code = entry.error_code || "";
      const message = entry.error_message || "";
      const english =
        code + (code && message ? ": " : "") + (code ? message : message);

      html +=
        "<li>" +
        escapeHtml(entry.line_fa) +
        (english
          ? ' <code dir="ltr" class="sai-skip-log-code">' +
            escapeHtml(english) +
            "</code>"
          : "") +
        "</li>";
    });

    html += "</ul>";

    if (hidden > 0) {
      html +=
        '<p class="sai-skip-log-more">… و ' +
        escapeHtml(hidden) +
        " مورد دیگر در فایل لاگ</p>";
    }

    html += "</div>";

    return html;
  }

  function renderReportStatRow(label, value, extraClass) {
    const cls = extraClass ? ' class="' + extraClass + '"' : "";
    return (
      "<li" +
      cls +
      "><span>" +
      escapeHtml(label) +
      '</span><strong dir="ltr">' +
      escapeHtml(value) +
      "</strong></li>"
    );
  }

  function renderReport(stats, options) {
    options = options || {};
    const mode = options.mode || "sync";
    const finished = !!options.finished;

    if (mode === "remediation") {
      const errors = Array.isArray(options.errors) ? options.errors : [];
      let html =
        '<div class="sai-report-panel">' +
        '<div class="sai-report-title">' +
        escapeHtml(options.title || "گزارش تبدیل variation") +
        "</div>" +
        '<ul class="sai-report-stats">' +
        renderReportStatRow(
          "تبدیل شده",
          stats.converted || 0,
          "sai-stat-created",
        ) +
        renderReportStatRow(
          "رد شده",
          stats.skipped || 0,
          "sai-stat-skipped",
        );

      if (errors.length > 0) {
        html += renderReportStatRow(
          "تعداد خطا",
          errors.length,
          "sai-stat-skipped",
        );
      }

      html += "</ul>";

      if (errors.length > 0) {
        html +=
          '<p class="sai-report-note">نمونه خطاها:</p><ul class="sai-report-errors">';
        errors.slice(0, 5).forEach(function (err) {
          html += "<li>" + escapeHtml(err) + "</li>";
        });
        if (errors.length > 5) {
          html += "<li>…</li>";
        }
        html += "</ul>";
      }

      html += "</div>";
      $("#sai-report-result").html(html);
      return;
    }

    const total = parseInt(stats.total || 0, 10);
    const processed = parseInt(stats.processed || 0, 10);
    const created = parseInt(stats.created || 0, 10);
    const updated = parseInt(stats.updated || 0, 10);
    const skipped = parseInt(stats.skipped || 0, 10);

    const title = finished
      ? "همگام‌سازی کامل شد"
      : "در حال همگام‌سازی…";

    let note = "";
    if (finished && skipped > 0) {
      note =
        '<p class="sai-report-note">رد شده: job یا variationهایی که وارد نشدند (مثلاً attribute رنگ/سایز در ووکامرس پیدا نشد).</p>';
    }

    $("#sai-report-result").html(
      '<div class="sai-report-panel">' +
        '<div class="sai-report-title">' +
        escapeHtml(title) +
        "</div>" +
        '<ul class="sai-report-stats">' +
        renderReportStatRow("مجموع (job)", total) +
        renderReportStatRow("پردازش‌شده", processed) +
        renderReportStatRow("ساخته‌شده", created, "sai-stat-created") +
        renderReportStatRow("آپدیت‌شده", updated, "sai-stat-updated") +
        renderReportStatRow("رد شده", skipped, "sai-stat-skipped") +
        "</ul>" +
        note +
        renderSkipLogList(options) +
        "</div>",
    );
  }

  function renderProgress(stats) {
    const total = parseInt(stats.total || 0, 10);
    const processed = parseInt(stats.processed || 0, 10);

    const percent =
      total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

    $("#sai-ajax-result").html(
      '<div class="sai-status-panel">' +
        '<div class="sai-status-title">در حال همگام‌سازی محصولات…</div>' +
        '<div class="sai-status-progress-text">' +
        escapeHtml(processed) +
        " / " +
        escapeHtml(total) +
        " job (" +
        escapeHtml(percent) +
        "%)</div>" +
        '<div class="sai-status-progress-bar">' +
        '<div class="sai-status-progress-fill" style="width:' +
        percent +
        '%;"></div>' +
        "</div>" +
        "</div>",
    );

    renderReport(stats, {
      finished: false,
      skip_log_total: skipLogLines.length,
      skip_log_hidden: Math.max(0, skipLogLines.length - SKIP_LOG_UI_MAX),
    });
  }

  function setButtonsDisabled(disabled) {
    $(
      "#sai-manual-sync, #sai-test-connection, #sai-remediate-variations, #sai-refresh-token",
    ).prop("disabled", disabled);
  }

  $("#sai-refresh-token").on("click", function () {
    if (isSyncRunning) {
      renderMessage(
        "همگام‌سازی در حال انجام است. لطفاً تا پایان آن صبر کنید.",
        false,
      );
      return;
    }

    const baseUrl = $("#sai_api_base_url").val();

    if (!baseUrl || String(baseUrl).trim() === "") {
      renderMessage("لطفاً آدرس API را وارد کنید.", false);
      return;
    }

    setButtonsDisabled(true);
    renderMessage("در حال دریافت توکن جدید...", true);

    $.post(
      saiAdmin.ajaxUrl,
      {
        action: "sai_refresh_token",
        nonce: saiAdmin.nonce,
        sai_api_base_url: baseUrl,
      },
      function (response) {
        setButtonsDisabled(false);

        if (response && response.success && response.data && response.data.token) {
          $("#sai_fixed_token").val(response.data.token);
          renderMessage(
            escapeHtml(response.data.message || "توکن جدید دریافت و ذخیره شد."),
            true,
          );
          return;
        }

        renderMessage(
          escapeHtml(
            (response && response.data && response.data.message) ||
              "دریافت توکن ناموفق بود",
          ),
          false,
        );
      },
    ).fail(function (xhr, status) {
      setButtonsDisabled(false);

      let msg = "درخواست دریافت توکن ناموفق بود";

      if (status) {
        msg += " (" + escapeHtml(status) + ")";
      }

      if (
        xhr &&
        xhr.responseJSON &&
        xhr.responseJSON.data &&
        xhr.responseJSON.data.message
      ) {
        msg += "<br>" + escapeHtml(xhr.responseJSON.data.message);
      }

      renderMessage(msg, false);
    });
  });

  $("#sai-test-connection").on("click", function () {
    if (isSyncRunning) {
      renderMessage(
        "همگام‌سازی در حال انجام است. لطفاً تا پایان آن صبر کنید.",
        false,
      );
      return;
    }

    renderMessage("در حال بررسی ارتباط...", true);

    $.post(
      saiAdmin.ajaxUrl,
      {
        action: "sai_test_connection",
        nonce: saiAdmin.nonce,
      },
      function (response) {
        if (response && response.success) {
          renderMessage(
            "ارتباط برقرار است<br>تعداد آیتم های موجود: " +
              escapeHtml(response.data.count || 0),
            true,
          );
        } else {
          renderMessage(
            escapeHtml(
              (response && response.data && response.data.message) ||
                "عدم ارتباط با سرور لطفا، برای رفع مشکل با تیم پشتیانی در تماس باشید",
            ),
            false,
          );
        }
      },
    ).fail(function (xhr, status) {
      renderMessage(
        "عدم ارتباط با سرور" + (status ? " (" + escapeHtml(status) + ")" : ""),
        false,
      );
    });
  });

  $("#sai-manual-sync").on("click", function () {
    if (isSyncRunning) {
      renderMessage("همگام سازی در حال اجرا است، لطفا صبر کنید...", false);
      return;
    }

    isSyncRunning = true;
    setButtonsDisabled(true);
    resetReport();

    let offset = 0;
    const limit = 20;

    let total = 0;
    let processed = 0;
    let created = 0;
    let updated = 0;
    let skipped = 0;

    function getSyncStats() {
      return {
        total: total,
        processed: processed,
        created: created,
        updated: updated,
        skipped: skipped,
      };
    }

    function finishSync(message, success, showFinalReport) {
      isSyncRunning = false;
      setButtonsDisabled(false);
      renderMessage(message, success);
      if (showFinalReport) {
        renderReport(getSyncStats(), {
          finished: true,
          skip_log_total: skipLogLines.length,
          skip_log_hidden: Math.max(0, skipLogLines.length - SKIP_LOG_UI_MAX),
        });
      }
    }

    function runBatch() {
      if (offset === 0) {
        renderMessage("دریافت محصولات از API و ساخت حافظه پنهان...", true);
        renderReport(getSyncStats(), { finished: false });
      } else {
        renderProgress(getSyncStats());
      }

      $.post(
        saiAdmin.ajaxUrl,
        {
          action: "sai_manual_sync",
          nonce: saiAdmin.nonce,
          offset: offset,
          limit: limit,
        },
        function (response) {
          if (!response || !response.success) {
            const errorMessage =
              response && response.data && response.data.message
                ? response.data.message
                : "عدم همگام سازی";
            finishSync(escapeHtml(errorMessage), false, processed > 0 || created > 0);
            return;
          }

          const data = response.data || {};

          total = parseInt(data.total || total || 0, 10);
          processed += parseInt(data.processed || 0, 10);
          created += parseInt(data.created || 0, 10);
          updated += parseInt(data.updated || 0, 10);
          skipped += parseInt(data.skipped || 0, 10);

          appendSkipLogBatch(data.skip_log_batch);

          renderProgress(getSyncStats());

          if (data.skip_log_total != null) {
            renderReport(getSyncStats(), {
              finished: false,
              skip_log_total: parseInt(data.skip_log_total, 10),
              skip_log_hidden: parseInt(data.skip_log_hidden || 0, 10),
            });
          }

          if (data.has_more) {
            offset = parseInt(data.next_offset || offset + limit, 10);

            setTimeout(function () {
              runBatch();
            }, 150);

            return;
          }

          finishSync("همگام‌سازی با موفقیت تمام شد.", true, true);
        },
      ).fail(function (xhr, status) {
        let msg = "درخواست AJAX ناموفق یا منقضی شده است";

        if (status) {
          msg += " (" + escapeHtml(status) + ")";
        }

        if (
          xhr &&
          xhr.responseJSON &&
          xhr.responseJSON.data &&
          xhr.responseJSON.data.message
        ) {
          msg += "<br>" + escapeHtml(xhr.responseJSON.data.message);
        }

        finishSync(msg, false, processed > 0 || created > 0);
      });
    }

    runBatch();
  });

  $("#sai-remediate-variations").on("click", function () {
    if (isSyncRunning) {
      renderMessage(
        "همگام‌سازی در حال انجام است. لطفاً تا پایان آن صبر کنید.",
        false,
      );
      return;
    }

    isSyncRunning = true;
    setButtonsDisabled(true);
    renderMessage("در حال تبدیل محصولات simple جاافتاده به variation...", true);

    $.post(
      saiAdmin.ajaxUrl,
      {
        action: "sai_remediate_variations",
        nonce: saiAdmin.nonce,
      },
      function (response) {
        isSyncRunning = false;
        setButtonsDisabled(false);

        if (!response || !response.success) {
          renderMessage(
            escapeHtml(
              (response && response.data && response.data.message) ||
                "عملیات remediation ناموفق بود",
            ),
            false,
          );
          return;
        }

        const data = response.data || {};
        const errors = Array.isArray(data.errors) ? data.errors : [];

        renderReport(
          {
            converted: data.converted || 0,
            skipped: data.skipped || 0,
          },
          {
            mode: "remediation",
            title: "تبدیل variationهای جاافتاده",
            errors: errors,
          },
        );

        renderMessage(
          errors.length === 0
            ? "عملیات remediation با موفقیت انجام شد."
            : "عملیات انجام شد؛ برخی موارد خطا داشتند.",
          errors.length === 0,
        );
      },
    ).fail(function (xhr, status) {
      isSyncRunning = false;
      setButtonsDisabled(false);

      let msg = "درخواست AJAX ناموفق بود";

      if (status) {
        msg += " (" + escapeHtml(status) + ")";
      }

      if (
        xhr &&
        xhr.responseJSON &&
        xhr.responseJSON.data &&
        xhr.responseJSON.data.message
      ) {
        msg += "<br>" + escapeHtml(xhr.responseJSON.data.message);
      }

      renderMessage(msg, false);
    });
  });
});
