/* Presentation-only dismissal. Payment state and reconciliation stay untouched. */
window.createWoosalamPaymentFeedback = ctx => {
  'use strict';
  const duration = 8000;
  const later = ctx.setTimer || setTimeout, cancel = ctx.clearTimer || clearTimeout;
  const now = ctx.now || Date.now;
  let storage;
  try { storage = ctx.storage || window.sessionStorage; } catch {}
  let timer, current = '', marker = '', fingerprint = '', deadline = 0, payment = null;
  const saved = () => { try { return storage?.getItem(marker) === fingerprint; } catch { return false; } };
  const shouldShow = () => ctx.shouldShow?.(payment) !== false;
  function dismiss() {
    cancel(timer);
    if (!payment) return;
    ctx.state.paymentNoticeHidden = true;
    try { storage?.setItem(marker, fingerprint); } catch {}
    ctx.onChange();
  }
  function tick() {
    if (!payment || ctx.state.paymentNoticeHidden) return;
    // Every outcome is temporary; recovery stays available in payment details.
    if (!ctx.isVisible() || ctx.isInteracting()) deadline = now() + duration;
    if (now() >= deadline) return dismiss();
    timer = later(tick, Math.min(1000, deadline - now()));
  }
  function arm() {
    cancel(timer);
    deadline = now() + duration;
    if (payment && !ctx.state.paymentNoticeHidden) timer = later(tick, 1000);
  }
  function sync(next, {notify = true} = {}) {
    payment = next;
    if (!next) { reset(); return; }
    marker = `woosalam-payment-toast:${ctx.getScope()}:${next.key || 'unassigned'}`;
    fingerprint = JSON.stringify([next.status, next.failure_code, next.failure_message, next.review_reason]);
    const signature = marker + fingerprint;
    if (signature === current) return;
    current = signature;
    // Loading an old result establishes a silent baseline. A later outcome
    // change can still notify, without replaying it on every tab visit.
    ctx.state.paymentNoticeHidden = !notify || !shouldShow() || saved();
    arm();
    ctx.onChange();
  }
  function show() {
    if (!payment || !shouldShow()) return;
    ctx.state.paymentNoticeHidden = false;
    try { storage?.removeItem(marker); } catch {}
    arm();
    ctx.onChange();
  }
  function reset() {
    cancel(timer);
    current = marker = fingerprint = '';
    payment = null;
    ctx.state.paymentNoticeHidden = false;
    ctx.onChange();
  }
  return {sync, dismiss, show, reset};
};
