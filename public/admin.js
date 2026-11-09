(() => {
  "use strict";
  const dialog = document.getElementById("mfa-dialog");
  if (!dialog) return;
  const code = document.getElementById("mfa-code");
  const validation = document.getElementById("mfa-validation");
  const actionLabel = document.getElementById("mfa-action-label");
  const confirm = document.getElementById("mfa-confirm");
  const cancel = document.getElementById("mfa-cancel");
  let pendingForm = null;
  let pendingSubmitter = null;

  document.querySelectorAll("form[data-stepup]").forEach(form => {
    form.addEventListener("submit", event => {
      if (form.dataset.mfaApproved === "yes") return;
      if (!form.checkValidity()) return;
      event.preventDefault();
      pendingForm = form;
      pendingSubmitter = event.submitter;
      actionLabel.textContent = form.dataset.stepup || "Επιβεβαίωση αλλαγής";
      code.value = "";
      validation.hidden = true;
      if (typeof dialog.showModal === "function") {
        dialog.showModal();
        code.focus();
      } else {
        const entered = window.prompt("Κωδικός Google Authenticator (6 ψηφία):");
        if (/^[0-9]{6}$/.test(entered || "")) {
          code.value = entered;
          submitConfirmed();
        }
      }
    });
  });

  function submitConfirmed() {
    if (!pendingForm || !/^[0-9]{6}$/.test(code.value.trim())) {
      validation.hidden = false;
      code.focus();
      return;
    }
    const form = pendingForm;
    const submitter = pendingSubmitter;
    const otp = form.querySelector('input[name="otp"]');
    if (!otp) {
      validation.hidden = false;
      return;
    }
    otp.value = code.value.trim();
    form.dataset.mfaApproved = "yes";
    if (dialog.open) dialog.close();
    form.requestSubmit(submitter || undefined);
  }

  confirm.addEventListener("click", submitConfirmed);
  cancel.addEventListener("click", () => dialog.close());
  code.addEventListener("input", () => {
    code.value = code.value.replace(/[^0-9]/g, "").slice(0, 6);
    validation.hidden = true;
  });
  code.addEventListener("keydown", event => {
    if (event.key === "Enter") {
      event.preventDefault();
      submitConfirmed();
    }
  });
  dialog.addEventListener("close", () => {
    pendingForm = null;
    pendingSubmitter = null;
    code.value = "";
  });
})();
