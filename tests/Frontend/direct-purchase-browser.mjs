// Uses PHPUnit's synthetic opening form. No live transaction is submitted.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join, extname, sep } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const fixtures = join(root, 'storage/framework/testing/direct-purchase-browser');
const publicRoot = join(root, 'public');
const browser = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe'].find(existsSync);
assert.ok(browser);
assert.ok(existsSync(join(fixtures, 'create.html')), 'Run DirectFabricPurchaseTest with DIRECT_PURCHASE_BROWSER_FIXTURES=1.');
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
    for(let i=0;i<100 && !await evaluate(`!!document.querySelector('select[name="lines[0][mode]"]')`);i++) await pause(100);
    await evaluate(`window.form=document.querySelector('form[action$="/inventory/direct-fabric-purchase"]'); window.choose=(name,value)=>{const el=form.elements.namedItem(name);el.value=value;el.dispatchEvent(new Event(el.tagName==='SELECT'?'change':'input',{bubbles:true}));}; choose('lines[0][mode]','new');`);
    await pause(150);
    await evaluate(`choose('lines[0][new_item][name]','Lawn front back');choose('lines[0][new_item][sku]','BROWSER-LAWN');window.unitSelect=form.elements.namedItem('lines[0][unit_id]');window.ids=Object.fromEntries([...unitSelect.options].filter(o=>o.value).map(o=>[o.textContent.split(' — ')[0],o.value]));`);
    assert.deepEqual(Object.keys(await evaluate('ids')).sort(),['M','PCS','SET','THAAN']);
    await evaluate(`choose('lines[0][unit_id]',ids.SET);choose('lines[0][quantity]','120');choose('lines[0][rate_unit_id]',ids.THAAN);choose('lines[0][rate_quantity]','10');choose('lines[0][unit_cost]','12000');choose('lines[0][thaan_count]','10');choose('lines[0][set_count]','120');choose('lines[0][measured_meters]','600');`);
    await pause(150);
    assert.equal(await evaluate('Alpine.$data(form).total()'),120000);
    assert.equal(await evaluate(`new FormData(form).get('lines[0][new_item][name]')`),'Lawn front back');
    assert.equal(await evaluate(`new FormData(form).has('supplier_invoice_number')`),false);
    await evaluate(`form.querySelector('input[name="has_invoice"]').click()`);
    await pause(150);
    assert.equal(await evaluate(`!!form.querySelector('input[name="supplier_invoice_number"]')`),true);
    await evaluate(`form.querySelector('input[name="has_invoice"]').click();[...form.querySelectorAll('button')].find(b=>b.textContent.trim()==='Add line').click()`);
    await pause(150);
    assert.equal(await evaluate(`form.elements.namedItem('lines[1][unit_id]').value`),'');
    assert.equal(await evaluate(`new FormData(form).get('lines[0][quantity]')`),'120');
    for(const [name,width,height,mobile] of [['desktop',1440,1100,false],['mobile',390,844,true]]) {
        await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile});
        await evaluate('document.documentElement.classList.add("sidebar-collapsed");form.scrollIntoView()');
        assert.ok(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),name+' overflow');
        const capture=await send('Page.captureScreenshot',{format:'png'});
        writeFileSync(join(fixtures,name+'.png'),Buffer.from(capture.data,'base64'));
    }
    assert.deepEqual(errors,[]);
    console.log('Direct purchase browser passed: inline item, measurement options, rate total, optional invoice, independent lines, desktop/mobile.');
} finally { clearTimeout(deadline); socket?.close(); child.kill(); server.closeAllConnections(); server.close(); }
