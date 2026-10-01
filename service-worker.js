const CACHE='okoume-v11';
const ASSETS=['/','/index.html','/app.js','/styles.css','/gallery.css','/trust.css','/commerce.css','/manifest.webmanifest','/icon.svg'];
const NETWORK_FIRST=new Set(['/','/index.html','/app.js','/commerce.css']);
const saveResponse=(cache,request,response)=>{if(response.ok&&response.type==='basic'&&!/no-store/i.test(response.headers.get('Cache-Control')||''))cache.put(request,response.clone());return response};
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS)).then(()=>self.skipWaiting())));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{
  if(event.request.method!=='GET')return;
  const url=new URL(event.request.url);
  const cacheable=url.origin===self.location.origin&&!url.search&&ASSETS.includes(url.pathname);
  if(!cacheable)return;
  event.respondWith(caches.open(CACHE).then(cache=>{
    if(NETWORK_FIRST.has(url.pathname))return fetch(event.request).then(response=>saveResponse(cache,event.request,response)).catch(()=>cache.match(event.request));
    return cache.match(event.request).then(cached=>cached||fetch(event.request).then(response=>saveResponse(cache,event.request,response)));
  }));
});
