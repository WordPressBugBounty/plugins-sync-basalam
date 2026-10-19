// Stateful stand-in derived from docs/contracts/salam-pay.openapi.json.
// Numeric success 3 is retained from the supplied Salam Pay plugin. Control
// routes and other status labels are test fixtures, not provider endpoints.
const http = require('http');
const enumValue = (value, name) => ({ value, name, description: name });
const server = http.createServer(async (req, res) => {
  const path = new URL(req.url, 'http://localhost').pathname;
  const body = req.body || {};
  let state = await pm.state.get('salam-pay:state') || { payments: {}, refunds: {}, calls: [], mode: '' };
  const send = (status, value) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(value));
  };
  if (path === '/__control' && req.method === 'POST') {
    if (body.reset) state = { payments: {}, refunds: {}, calls: [], mode: '' };
    if (Object.hasOwn(body, 'mode')) state.mode = body.mode;
    if (body.payment) Object.assign(state.payments[body.reference_id] || {}, body.payment);
    if (body.refund) Object.assign(state.refunds[body.reference_id] || {}, body.refund);
    if (body.seed_payment) state.payments[body.seed_payment.reference_id] = body.seed_payment;
    if (body.seed_refund) state.refunds[body.seed_refund.reference_id] = body.seed_refund;
    await pm.state.set('salam-pay:state', state);
    return send(200, { ok: true });
  }
  if (path === '/__state') return send(200, state);
  if (req.headers['x-client-secret'] !== 'local-salam-pay-fixture') return send(401, { detail: 'Invalid client' });
  state.calls.push({ method: req.method, path, body });
  await pm.state.set('salam-pay:state', state);
  if (state.mode === 'unavailable') return send(503, { detail: 'Unavailable' });
  if (state.mode === 'html') {
    res.writeHead(502, { 'Content-Type': 'text/html' });
    return res.end('<html>Upstream unavailable</html>');
  }
  if (req.method === 'POST' && path === '/public-api/v1/sessions') {
    if (!Number.isSafeInteger(body.amount) || body.amount <= 1000 || !Number.isSafeInteger(body.reference_id)
      || typeof body.callback_url !== 'string' || !body.callback_url.includes('salampay')) return send(422, { detail: 'Invalid session' });
    if (state.mode === 'reject') return send(422, { detail: 'Rejected' });
    if (state.payments[body.reference_id]) return send(409, { detail: 'Duplicate reference' });
    const sum = body.items.reduce((total, item) => total + item.unit_price * item.quantity, 0) - body.discount;
    if (sum !== body.amount) return send(422, { detail: 'Basket sum differs from amount' });
    const session = { ...body, id: body.reference_id + 100000, key: 'fixture-' + body.reference_id,
      status: enumValue(1, 'PENDING'), expires_at: new Date(Date.now() + 3600000).toISOString() };
    state.payments[body.reference_id] = { id: body.reference_id + 200000, user_id: 1,
      reference_id: body.reference_id, amount: body.amount, callback_url: body.callback_url,
      customer: { mobile: body.mobile }, payment_methods: [], status: enumValue(1, 'PENDING'),
      key: 'pay-fixture', transactions: [], created_at: 1, updated_at: 1, session_id: session.id, session };
    await pm.state.set('salam-pay:state', state);
    if (state.mode === 'session500') return send(500, { detail: 'Response lost after creation' });
    if (state.mode === 'invalid-json') { res.writeHead(200); return res.end('broken'); }
    return send(200, { session, url: 'https://salampay.basalam.com/payment-interface/' + session.key });
  }
  let match = path.match(/^\/public-api\/v1\/payments\/references\/(\d+)$/);
  if (req.method === 'GET' && match) {
    const payment = state.payments[match[1]];
    return payment ? send(200, payment) : send(404, { detail: 'Not found' });
  }
  if (req.method === 'POST' && path === '/public-api/v1/refunds') {
    const pay = state.payments[body.pay_reference_id];
    if (!pay || !Number.isSafeInteger(body.reference_id) || body.amount <= 0 || body.amount > pay.amount
      || typeof body.description !== 'string') return send(422, { detail: 'Invalid refund' });
    if (state.refunds[body.reference_id]) return send(409, { detail: 'Duplicate reference' });
    state.refunds[body.reference_id] = { reference_id: body.reference_id, amount: body.amount,
      pay_id: pay.id, pay: { id: pay.id, reference_id: pay.reference_id, amount: pay.amount },
      final_status: enumValue(1, 'PENDING') };
    await pm.state.set('salam-pay:state', state);
    if (state.mode === 'refund500') return send(500, { detail: 'Response lost after refund' });
    return send(200, {}); // POST body is intentionally unspecified in the upstream schema.
  }
  match = path.match(/^\/public-api\/v1\/refunds\/(\d+)\/status$/);
  if (req.method === 'GET' && match) {
    const refund = state.refunds[match[1]];
    if (!refund) return send(404, { detail: 'Not found' });
    return send(200, { final_status: refund.final_status, adjustment: {
      id: 1, user_id: 1, ...refund, reference_type: enumValue(1, 'REFUND'),
      status: refund.final_status, action: enumValue(1, 'REFUND'), original_reference_id: refund.reference_id,
      description: 'Test refund', transactions: [], created_at: 1, updated_at: 1,
    } });
  }
  return send(501, { detail: 'Endpoint outside this integration contract' });
});
server.listen(process.env.PORT || 4900);
