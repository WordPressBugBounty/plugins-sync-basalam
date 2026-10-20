// Product request/response shapes reuse basalam-core-fallback.openapi.json.
// x-test-* headers are local failure controls, not provider API features.
const http = require('http');
const requests = [];
const counts = new Map();

http.createServer(async (req, res) => {
  const reply = (status, body) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(body));
  };
  const path = new URL(req.url, 'http://fixture.test').pathname;
  if (path === '/__requests') return reply(200, requests);
  if (req.method !== 'PATCH' || !/^\/v(?:1|4)\/(?:products\/\d+(?:\/variations\/\d+)?|vendors\/\d+\/products(?:\/batch-updates)?)$/.test(path)) {
    return reply(501, { message: 'Outside this product-update fixture.' });
  }
  const key = req.headers['x-test-case'];
  const count = (counts.get(key) || 0) + 1;
  counts.set(key, count);
  const sequence = JSON.parse(req.headers['x-test-statuses'] || '[]');
  const status = Number(sequence[count - 1] ?? req.headers[count === 1 ? 'x-test-first-status' : 'x-test-next-status'] ?? 200);
  requests.push({ test: key, method: req.method, path: req.url, body: req.body, status,
    origin: req.headers['x-test-origin-host'], ordinal: count });
  if (req.headers['x-test-delay']) await new Promise(resolve => setTimeout(resolve, Number(req.headers['x-test-delay'])));
  if (status >= 400) {
    if (req.headers['x-test-error-body'] === 'html') {
      res.writeHead(status, { 'Content-Type': 'text/html' });
      return res.end('<html><body>Fixture rejection</body></html>');
    }
    if (req.headers['x-test-error-body'] === 'malformed') {
      res.writeHead(status, { 'Content-Type': 'application/json' });
      return res.end('{invalid json');
    }
    const field = req.headers['x-test-error-field'] || 'description';
    return reply(status, { messages: [{ fields: [field], message: field === 'sku' ? 'SKU already exists' : 'Fixture validation error',
      snippet: 'Fixture <em>forbidden</em> description' }] });
  }
  if (status === 202) return reply(202, req.body.data.map(item => ({ id: Number(item.id), has_error: false, error_message: null, is_product_for_revision: false })));
  const id = Number(/\/products\/(\d+)/.exec(path)?.[1] || 123);
  return reply(200, { id, sku: req.headers['x-test-omit-sku'] === '1' ? null : req.body.sku ?? null,
    variants: req.headers['x-test-variants'] === '1' ? (req.body.variants || []).map((variant, index) => ({
      id: id * 10 + index + 1 + count * Number(req.headers['x-test-variant-id-step'] || 0),
      properties: (variant.properties || []).map(property => ({ property: { title: property.property }, value: { title: property.value } })),
    })) : [] });
}).listen(process.env.PORT);
