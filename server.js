const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');

const root = __dirname;
const port = Number(process.env.PORT) || 4176;
const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml', '.json': 'application/json; charset=utf-8', '.webmanifest': 'application/manifest+json; charset=utf-8' };
const publicFiles = new Set(['index.html', 'app.js', 'styles.css', 'gallery.css', 'trust.css', 'commerce.css', 'brand-contrast.css', 'manifest.webmanifest', 'icon.svg', 'product-placeholder.svg', 'service-worker.js']);

function proxyProducts(res) {
  const upstream = https.request({
    hostname: 'okoumestore.find-gabon.com',
    path: '/wp-json/okoume/v1/products',
    method: 'GET',
    headers: { Accept: 'application/json', 'User-Agent': 'OKOUME-local-preview/1.0' },
  }, response => {
    res.writeHead(response.statusCode || 502, { 'Content-Type': response.headers['content-type'] || 'application/json', 'Cache-Control': 'no-store' });
    response.pipe(res);
  });
  upstream.on('error', () => res.writeHead(502, { 'Content-Type': 'application/json' }).end(JSON.stringify({ message: 'Catalogue indisponible temporairement.' })));
  upstream.end();
}

http.createServer((req, res) => {
  let requested;
  try { requested = decodeURIComponent(new URL(req.url, 'http://127.0.0.1').pathname); } catch { return res.writeHead(400).end('Bad request'); }
  if (requested === '/api/wp-json/okoume/v1/products') return proxyProducts(res);
  const relative = requested === '/' ? 'index.html' : requested.replace(/^[/\\]+/, '');
  const isBrandAsset = relative.startsWith('assets/') && /\.(?:png|jpe?g|webp|svg)$/i.test(relative);
  if (!publicFiles.has(relative) && !isBrandAsset) return res.writeHead(404).end('Not found');
  const file = path.resolve(root, relative);
  if (!file.startsWith(root + path.sep)) return res.writeHead(403).end('Forbidden');
  fs.readFile(file, (error, content) => {
    if (error) return res.writeHead(404).end('Not found');
    res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
    res.end(content);
  });
}).listen(port, '127.0.0.1', () => console.log(`OKOUMÉ preview: http://127.0.0.1:${port}`));
