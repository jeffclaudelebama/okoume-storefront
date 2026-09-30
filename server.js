const http = require('http');
const fs = require('fs');
const path = require('path');

const root = __dirname;
const port = Number(process.env.PORT) || 4173;
const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.svg': 'image/svg+xml', '.json': 'application/json; charset=utf-8', '.webmanifest': 'application/manifest+json; charset=utf-8' };
const publicFiles = new Set(['index.html', 'app.js', 'styles.css', 'gallery.css', 'trust.css', 'commerce.css', 'manifest.webmanifest', 'icon.svg', 'product-placeholder.svg', 'service-worker.js']);

http.createServer((req, res) => {
  let requested;
  try { requested = decodeURIComponent(new URL(req.url, 'http://127.0.0.1').pathname); } catch { return res.writeHead(400).end('Bad request'); }
  const relative = requested === '/' ? 'index.html' : requested.replace(/^[/\\]+/, '');
  if (!publicFiles.has(relative)) return res.writeHead(404).end('Not found');
  const file = path.resolve(root, relative);
  if (!file.startsWith(root + path.sep)) return res.writeHead(403).end('Forbidden');
  fs.readFile(file, (error, content) => {
    if (error) return res.writeHead(404).end('Not found');
    res.writeHead(200, { 'Content-Type': types[path.extname(file)] || 'application/octet-stream' });
    res.end(content);
  });
}).listen(port, '127.0.0.1', () => console.log(`OKOUMÉ preview: http://127.0.0.1:${port}`));
