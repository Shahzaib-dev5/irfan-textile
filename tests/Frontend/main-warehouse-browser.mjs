// Uses PHPUnit's synthetic opening form. No live transaction is submitted.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join, extname, sep } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const fixtures = join(root, 'storage/framework/testing/main-warehouse-browser');
const publicRoot = join(root, 'public');
const browser = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe'].find(existsSync);
assert.ok(browser);
assert.ok(existsSync(join(fixtures, 'create.html')), 'Run MainWarehouseTest with MAIN_WAREHOUSE_BROWSER_FIXTURES=1.');
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const server = createServer((req, res) => {
    const pathname = new URL(req.url, 'http://127.0.0.1').pathname;
    if (pathname === '/create.html') {
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        res.end(readFileSync(join(fixtures, 'create.html'), 'utf8').replace(/https?:\/\/[^/"\s<>]+/g, origin)); return;
    }
    const file = resolve(publicRoot, '.' + decodeURIComponent(pathname));
    if (!file.startsWith(publicRoot + sep) || !existsSync(file)) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2' })[extname(file)] || 'application/octet-stream' }); res.end(readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:' + server.address().port;
const profile = join(fixtures, 'chromium-' + Date.now()); mkdirSync(profile, { recursive: true });
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-first-run', '--disable-extensions', '--disable-background-networking', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
let browserLog = ''; child.stderr.on('data', chunk => browserLog += chunk.toString());
let socket;
const deadline = setTimeout(() => { console.error('Browser timeout: '+browserLog.slice(-1500)); child.kill(); socket?.close(); server.closeAllConnections(); server.close(); process.exitCode = 1; }, 60000);
try {
    const active = join(profile, 'DevToolsActivePort');
    for (let i = 0; i < 150 && !existsSync(active); i++) await pause(100);
    assert.ok(existsSync(active), 'Browser failed to start');
    const port = readFileSync(active, 'utf8').split('\n')[0].trim();
    const pages = await fetch('http://127.0.0.1:' + port + '/json/list').then(r => r.json());
    socket = new WebSocket(pages.find(p => p.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let next = 1; const pending = new Map(); const errors = [];
    socket.onmessage = event => {
        const m = JSON.parse(event.data);
        if (m.id) { const p = pending.get(m.id); pending.delete(m.id); if(p) m.error ? p.reject(new Error(JSON.stringify(m.error))) : p.resolve(m.result); }
        if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text);
    };
    const send = (method, params = {}) => new Promise((resolve, reject) => { const id = next++; pending.set(id,{resolve,reject}); socket.send(JSON.stringify({id,method,params})); });
    const evaluate = async expression => { const r = await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true}); assert.ok(!r.exceptionDetails, JSON.stringify(r.exceptionDetails)); return r.result.value; };
    await send('Page.enable'); await send('Runtime.enable');
    await send('Page.navigate',{url:origin+'/create.html'});
    for(let i=0;i<100 && !await evaluate(`!!document.querySelector('select[name="lines[0][warehouse_location_id]"]')`);i++) await pause(100);
    await evaluate(`window.stockForm=document.querySelector('form[action$="/inventory/documents"]')`);
    await evaluate(`window.storeSelect=stockForm.querySelector('select[name="lines[0][warehouse_location_id]"]'); window.storeOptions=[...storeSelect.options].filter(o=>o.value); storeSelect.value=storeOptions[0].value; storeSelect.dispatchEvent(new Event('change'));`);
    assert.deepEqual(await evaluate('storeOptions.map(o=>o.textContent)'), ['Main Warehouse','Second Warehouse']);
    const first = await evaluate('new FormData(stockForm).get("lines[0][warehouse_location_id]")');
    assert.ok(Number(first)>0, 'Selected warehouse is submitted');
    await evaluate('[...stockForm.querySelectorAll("button")].find(b=>b.textContent.trim()==="Add line").click()');
    await pause(150);
    await evaluate(`const second=stockForm.querySelector('select[name="lines[1][warehouse_location_id]"]'); second.value=storeOptions[1].value; second.dispatchEvent(new Event('change'));`);
    assert.notEqual(await evaluate('new FormData(stockForm).get("lines[1][warehouse_location_id]")'), first);
    assert.equal(await evaluate('new FormData(stockForm).get("lines[0][warehouse_location_id]")'),first);
    for (const [name,width,height,mobile] of [['desktop',1440,1100,false],['mobile',390,844,true]]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile});
        await evaluate('document.documentElement.classList.add("sidebar-collapsed"); stockForm.scrollIntoView()');
        assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), name+' overflow');
        const capture = await send('Page.captureScreenshot',{format:'png'});
        writeFileSync(join(fixtures,name+'.png'),Buffer.from(capture.data,'base64'));
    }
    assert.deepEqual(errors,[]);
    console.log('Warehouse browser passed: multiple warehouse choices, independent row destinations, no internal location choices, desktop/mobile layout and no JS exceptions.');
} finally { clearTimeout(deadline); socket?.close(); child.kill(); server.closeAllConnections(); server.close(); }
