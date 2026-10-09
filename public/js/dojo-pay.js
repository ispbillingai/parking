/*
 * Dojo card payment for the kiosks (cashier-pay, pos-pay, daily-ticket).
 * Same flow as the Focacciami cashier:
 *   start → poll every poll_ms showing the terminal's own prompt → done / failed.
 *   Signature fallback asks the operator (Dojo accepts on its own after 80 s,
 *   so the popup counts down). Cancel works until a card is presented; after
 *   that Dojo refuses and we keep polling, because the sale may still complete.
 *
 * DojoPay.run({ body, pollMs, amountText, i18n, onDone(r), onFail(msg) })
 *   body is sent to api/dojo-pay.php with each action ({kind:'session', pin,
 *   amount_cents} or {kind:'daily', phone, email, name}).
 */
(function () {
  const CSS = `
.dojo-ov{position:fixed;inset:0;background:rgba(5,8,20,.78);backdrop-filter:blur(6px);display:none;align-items:center;justify-content:center;z-index:1000;padding:16px}
.dojo-ov.on{display:flex}
.dojo-box{width:100%;max-width:480px;border:1px solid var(--border,rgba(255,255,255,.1));border-radius:22px;padding:28px;text-align:center;
  background:linear-gradient(180deg,#141b3a,#0f1530);color:var(--text,#e7ecf5);box-shadow:0 24px 60px rgba(0,0,0,.5)}
.dojo-box h2{margin:0 0 6px;font-size:22px}
.dojo-amt{font-size:44px;font-weight:800;margin:10px 0;font-variant-numeric:tabular-nums}
.dojo-prompt{font-size:17px;min-height:48px;color:var(--accent,#5eead4);margin:8px 0 16px}
.dojo-muted{color:var(--muted,#9aa4bf);font-size:14px}
.dojo-err{color:var(--err,#f87171);font-size:14px;min-height:18px}
.dojo-row{display:flex;gap:10px;margin-top:16px}
.dojo-row button{flex:1}
.dojo-spin{width:42px;height:42px;margin:6px auto 4px;border-radius:50%;border:4px solid rgba(255,255,255,.12);border-top-color:var(--accent,#5eead4);animation:dojospin 1s linear infinite}
@keyframes dojospin{to{transform:rotate(360deg)}}`;

  let ui = null;
  function build(i18n) {
    if (ui) return ui;
    const st = document.createElement('style'); st.textContent = CSS; document.head.appendChild(st);
    const wrap = document.createElement('div');
    wrap.innerHTML = `
<div class="dojo-ov" id="dojoOv"><div class="dojo-box">
  <div class="dojo-spin"></div>
  <h2></h2>
  <div class="dojo-amt" id="dojoAmt"></div>
  <div class="dojo-prompt" id="dojoPrompt"></div>
  <button type="button" class="danger" id="dojoCancel" style="width:100%"></button>
</div></div>
<div class="dojo-ov" id="dojoSig" style="z-index:1001"><div class="dojo-box">
  <h2 id="dojoSigTitle"></h2>
  <div class="dojo-amt" id="dojoSigAmt" style="font-size:34px"></div>
  <p id="dojoSigQ"></p>
  <p class="dojo-muted" id="dojoSigCount"></p>
  <p class="dojo-err" id="dojoSigErr"></p>
  <div class="dojo-row">
    <button type="button" class="danger" id="dojoSigNo"></button>
    <button type="button" class="primary" id="dojoSigYes"></button>
  </div>
</div></div>`;
    document.body.appendChild(wrap);
    const $ = id => document.getElementById(id);
    wrap.querySelector('#dojoOv h2').textContent = i18n.pay_by_dojo;
    $('dojoCancel').textContent = i18n.cancel;
    $('dojoSigTitle').textContent = i18n.sig_title;
    $('dojoSigQ').textContent = i18n.sig_question;
    $('dojoSigNo').textContent = i18n.sig_reject;
    $('dojoSigYes').textContent = i18n.sig_accept;
    ui = { $, ov: $('dojoOv'), sig: $('dojoSig') };
    return ui;
  }

  function run(opts) {
    const i18n = opts.i18n, u = build(i18n), $ = u.$;
    const pollMs = Math.max(500, opts.pollMs || 1500);
    let timer = null, active = false;
    let sigDeadline = 0, sigTick = null, sigAnswered = false;

    const post = async (action, extra) => {
      const res = await fetch('api/dojo-pay.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
        body: JSON.stringify(Object.assign({}, opts.body, { action }, extra || {})),
      });
      return res.json();
    };
    const prompt = code => (code && i18n.prompts[code])
      || (code ? code.replace(/([a-z])([A-Z])/g, '$1 $2') : i18n.follow_terminal);

    function sigOpen(secondsLeft) {
      if (sigAnswered) return;   // answer sent, waiting for Dojo's result
      if (typeof secondsLeft === 'number') sigDeadline = Date.now() + secondsLeft * 1000;
      if (!u.sig.classList.contains('on')) {
        $('dojoSigErr').textContent = '';
        $('dojoSigYes').disabled = $('dojoSigNo').disabled = false;
        u.sig.classList.add('on');
      }
      if (!sigTick) sigTick = setInterval(sigCount, 500);
      sigCount();
    }
    function sigCount() {
      const s = Math.max(0, Math.ceil((sigDeadline - Date.now()) / 1000));
      $('dojoSigCount').textContent = s > 0 ? i18n.sig_countdown.replace('%s', s) : i18n.sig_auto;
    }
    function sigClose() {
      if (sigTick) { clearInterval(sigTick); sigTick = null; }
      sigAnswered = false;
      u.sig.classList.remove('on');
    }
    function finish() {
      active = false;
      if (timer) { clearTimeout(timer); timer = null; }
      sigClose();
      u.ov.classList.remove('on');
    }
    function fail(msg) {
      finish();
      if (msg === 'signature_rejected') msg = i18n.sig_rejected;
      opts.onFail(msg || '');
    }

    async function poll() {
      if (!active) return;
      try {
        const r = await post('poll');
        if (r.state === 'done') { finish(); return opts.onDone(r); }
        if (r.state === 'failed' || !r.ok) return fail(r.error);
        if (r.state === 'signature') {
          // Keep polling while the popup is up: if Dojo's 80 s run out it
          // accepts by itself and the next poll closes the popup with the result.
          $('dojoPrompt').textContent = i18n.prompts.SignatureVerificationRequired;
          $('dojoCancel').disabled = true;
          sigOpen(r.seconds_left);
        } else {
          sigClose();
          $('dojoPrompt').textContent = prompt(r.prompt);
        }
      } catch (e) { $('dojoPrompt').textContent = e.message; }
      if (active) timer = setTimeout(poll, pollMs);
    }

    $('dojoSigYes').onclick = () => answer(true);
    $('dojoSigNo').onclick  = () => answer(false);
    async function answer(accepted) {
      $('dojoSigYes').disabled = $('dojoSigNo').disabled = true;
      $('dojoSigErr').textContent = '';
      try {
        const r = await post('signature', { accepted });
        if (!r.ok) {   // e.g. network: let the operator try again before the deadline
          $('dojoSigErr').textContent = r.error || i18n.failed;
          $('dojoSigYes').disabled = $('dojoSigNo').disabled = false;
          return;
        }
        sigAnswered = true;
        if (sigTick) { clearInterval(sigTick); sigTick = null; }
        u.sig.classList.remove('on');
        $('dojoPrompt').textContent = i18n.working;
      } catch (e) {
        $('dojoSigErr').textContent = e.message;
        $('dojoSigYes').disabled = $('dojoSigNo').disabled = false;
      }
      // The poll loop is still running: accepted → Captured, rejected → failed.
    }

    $('dojoCancel').onclick = async () => {
      $('dojoCancel').disabled = true;
      $('dojoPrompt').textContent = i18n.cancelling;
      try {
        const r = await post('cancel');
        // Refused = card already presented: the sale may still complete, so keep polling.
        if (!r.ok) $('dojoPrompt').textContent = i18n.cancel_refused;
      } catch (e) { $('dojoPrompt').textContent = e.message; }
      $('dojoCancel').disabled = false;
    };

    (async () => {
      $('dojoAmt').textContent = $('dojoSigAmt').textContent = opts.amountText || '';
      $('dojoPrompt').textContent = i18n.starting;
      $('dojoCancel').disabled = false;
      sigClose();
      u.ov.classList.add('on');
      try {
        const r = await post('start');
        if (!r.ok) return fail(r.error);
        if (r.amount_cents && opts.formatAmount) {
          $('dojoAmt').textContent = $('dojoSigAmt').textContent = opts.formatAmount(r.amount_cents);
        }
        active = true;
        $('dojoPrompt').textContent = i18n.follow_terminal;
        timer = setTimeout(poll, pollMs);
      } catch (e) { fail(e.message); }
    })();
  }

  window.DojoPay = { run };
})();
