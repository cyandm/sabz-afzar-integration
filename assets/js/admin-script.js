jQuery(function ($) {
  let isSyncRunning = false;

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

  function renderProgress(stats) {
    const total = parseInt(stats.total || 0, 10);
    const processed = parseInt(stats.processed || 0, 10);
    const created = parseInt(stats.created || 0, 10);
    const updated = parseInt(stats.updated || 0, 10);
    const skipped = parseInt(stats.skipped || 0, 10);

    const percent =
      total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;

    $("#sai-ajax-result").html(
      '<div style="padding:12px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0;">' +
        '<div style="font-weight:600;margin-bottom:8px;">در حال همگام‌سازی محصولات...</div>' +
        "<div>Processed: " +
        processed +
        " / " +
        total +
        " (" +
        percent +
        "%)</div>" +
        '<div style="margin-top:6px;">Created: ' +
        created +
        " | Updated: " +
        updated +
        " | Skipped: " +
        skipped +
        "</div>" +
        '<div style="margin-top:10px;height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden;">' +
        '<div style="height:10px;width:' +
        percent +
        '%;background:#2563eb;transition:width .25s ease;"></div>' +
        "</div>" +
        "</div>",
    );
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

    let offset = 0;
    const limit = 20;

    let total = 0;
    let processed = 0;
    let created = 0;
    let updated = 0;
    let skipped = 0;

    function finishSync(message, success) {
      isSyncRunning = false;
      setButtonsDisabled(false);
      renderMessage(message, success);
    }

    function runBatch() {
      if (offset === 0) {
        renderMessage("دریافت محصولات از API و ساخت حافظه پنهان...", true);
      } else {
        renderProgress({
          total: total,
          processed: processed,
          created: created,
          updated: updated,
          skipped: skipped,
        });
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
            finishSync(escapeHtml(errorMessage), false);
            return;
          }

          const data = response.data || {};

          total = parseInt(data.total || total || 0, 10);
          processed += parseInt(data.processed || 0, 10);
          created += parseInt(data.created || 0, 10);
          updated += parseInt(data.updated || 0, 10);
          skipped += parseInt(data.skipped || 0, 10);

          renderProgress({
            total: total,
            processed: processed,
            created: created,
            updated: updated,
            skipped: skipped,
          });

          if (data.has_more) {
            offset = parseInt(data.next_offset || offset + limit, 10);

            setTimeout(function () {
              runBatch();
            }, 150);

            return;
          }

          finishSync(
            "همگام سازی کامل شد" +
              "<br>مجموع: " +
              escapeHtml(total) +
              "<br>پردازش شده: " +
              escapeHtml(processed) +
              "<br>ساخته شده: " +
              escapeHtml(created) +
              "<br>آپدیت شده: " +
              escapeHtml(updated) +
              "<br>رد شده: " +
              escapeHtml(skipped),
            true,
          );
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

        finishSync(msg, false);
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
        let message =
          "تبدیل variationهای جاافتاده انجام شد" +
          "<br>تبدیل شده: " +
          escapeHtml(data.converted || 0) +
          "<br>رد شده: " +
          escapeHtml(data.skipped || 0);

        if (errors.length > 0) {
          message +=
            "<br>خطاها (" +
            escapeHtml(errors.length) +
            "):<br>" +
            errors
              .slice(0, 10)
              .map(function (error) {
                return escapeHtml(error);
              })
              .join("<br>");

          if (errors.length > 10) {
            message += "<br>...";
          }
        }

        renderMessage(message, errors.length === 0);
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
