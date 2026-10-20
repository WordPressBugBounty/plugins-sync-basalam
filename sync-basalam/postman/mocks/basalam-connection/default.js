// Transport fixture. Core route versions come from its published OpenAPI spec.
// Diagnostic query parameters are test controls, not provider API features.
const http = require('http');
const requests = [];
const webhooks = new Map();
let nextWebhookId = 701;

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://fixture.test');
  const path = url.pathname;
  const core = req.headers.host === 'core.basalam.com';
  const orderProcessing = req.headers.host === 'order-processing.basalam.com';
  const reply = (status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(body));
  };
  if (path === '/__requests') return reply(200, requests);
  requests.push({ method: req.method, path: req.url, headers: req.headers, body: req.body });
  if (url.searchParams.has('__redirect')) {
    res.writeHead(302, { Location: 'https://openapi.basalam.com/__unreachable' });
    return res.end();
  }
  if (url.searchParams.has('__timeout')) {
    await new Promise(resolve => setTimeout(resolve, 1200));
    return reply(200, {});
  }
  if (url.searchParams.has('__disconnect')) return req.socket.destroy();
  if (url.searchParams.has('__status')) return reply(Number(url.searchParams.get('__status')), {});
  if (['openapi.basalam.com', 'webhook.basalam.com'].includes(req.headers.host)) {
    if (path === '/v1/webhooks' && req.method === 'GET') return reply(200, { data: [...webhooks.values()] });
    const webhookId = /^\/v1\/webhooks\/(\d+)$/.exec(path)?.[1];
    if ((path === '/v1/webhooks' && req.method === 'POST') || (webhookId && req.method === 'PATCH')) {
      if (webhookId && !webhooks.has(Number(webhookId))) return reply(404, { detail: 'Webhook not found' });
      const body = req.body || {};
      const previous = webhookId ? webhooks.get(Number(webhookId)) : {};
      const id = webhookId ? Number(webhookId) : nextWebhookId++;
      const resource = { ...previous, id, service_id: 1,
        events: body.event_ids ? body.event_ids.map(id => ({ id })) : previous.events,
        request_headers: body.request_headers ? JSON.parse(body.request_headers) : previous.request_headers,
        request_method: body.request_method ?? previous.request_method,
        url: body.url ?? previous.url,
        is_active: body.is_active ?? previous.is_active ?? true,
      };
      webhooks.set(id, resource);
      return reply(200, resource);
    }
    if (webhookId && req.method === 'DELETE') {
      if (!webhooks.delete(Number(webhookId))) return reply(404, { detail: 'Webhook not found' });
      return reply(200, { id: Number(webhookId), deleted_at: '2026-10-20T10:00:00+00:00' });
    }
  }
  if (req.method === 'GET' && path === (orderProcessing ? '/v3/vendor-parcels' : '/v1/vendor-parcels')) {
    if (url.searchParams.has('__empty')) return reply(200, { data: [], next_cursor: null, previous_cursor: null });
    const nextPage = url.searchParams.has('cursor');
    // Synthetic values with the required ParcelSummaryResponse fields from the
    // provider contract. No production order/customer data is used.
    const time = '2026-10-19T10:00:00+00:00';
    const city = { id: 1, title: 'Test city', parent: { id: 2, title: 'Test province' } };
    const user = { id: 3, hash_id: 'fixture', name: 'Test user' };
    const method = { id: 1, title: 'Test shipping' };
    const parcel = {
      id: nextPage ? 1002 : 1001, total_items_price: 10000, created_at: time, updated_at: time, weight: 50,
      vendor: { id: 1, identifier: 'fixture', title: 'Test vendor', city, owner: user },
      order: { id: nextPage ? 2002 : 2001, paid_at: time, created_at: time,
        customer: { city, user, recipient: { name: 'Test recipient', mobile: '09120000000', postal_address: 'Test address', postal_code: '1111111111' } } },
      shipping_method: { current: method, default: method }, items: [],
    };
    return reply(200, { data: [parcel], next_cursor: nextPage ? null : 'opaque+/=?&%', previous_cursor: null });
  }
  if (core && path.startsWith('/v1/')) return reply(404, { detail: 'Not Found' });
  const product = core ? '/v4/products/123' : '/v1/products/123';
  if (['PATCH', 'GET'].includes(req.method) && path === product) return reply(200, { id: 123 });
  if (req.method === 'POST' && path === (core ? '/v4/vendors/1/products' : '/v1/vendors/1/products')) return reply(201, { id: 124 });
  if (req.method === 'PATCH' && path === (core ? '/v4/vendors/1/products' : '/v1/vendors/1/products/batch-updates')) {
    if (!Array.isArray(req.body?.data) || req.body.data.some(item => !Number.isInteger(item.id))) {
      return reply(422, { detail: 'A batch requires data containing product ids.' });
    }
    return reply(202, req.body.data.map((item, index) => ({
      id: item.id, is_product_for_revision: false,
      has_error: url.searchParams.has('__partial') && index === 1,
      error_message: url.searchParams.has('__partial') && index === 1 ? 'SKU already exists' : null,
    })));
  }
  if (req.method === 'GET' && path === (core ? '/v3/categories' : '/v1/categories')) return reply(200, { data: [] });
  if (core && req.method === 'GET' && ['/v3/categories/1/attributes', '/v3/vendors/1'].includes(path)) return reply(200, { data: [] });
  if (core && req.method === 'POST' && path === '/v3/vendors/1/discounts') return reply(200, {});
  return reply(501, { message: 'Outside this transport test fixture.' });
}).listen(process.env.PORT);
