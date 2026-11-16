// Export synthetic HTML with PARTY_OPENING_BROWSER_FIXTURES=1 PartyOpeningBalanceTest.
// Only fixture pages are loaded. No application database or real form is submitted.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join, extname, sep } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const fixtures = join(root, 'storage/framework/testing/party-opening-browser');
const publicRoot = join(root, 'public');
const browser = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe'].find(existsSync);
assert.ok(browser, 'A Chromium browser is required.');
assert.ok(existsSync(join(fixtures, 'create.html')), 'Export the PHPUnit fixture first.');
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const server = createServer((req, res) => {
    const pathname = new URL(req.url, 'http://127.0.0.1').pathname;
    if (pathname === '/create.html') {
        const html = readFileSync(join(fixtures, 'create.html'), 'utf8').replace(/https?:\/\/[^/"\s<>]+/g, origin);
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' }); res.end(html); return;
    }
    const file = resolve(publicRoot, '.' + decodeURIComponent(pathname));
    if (!file.startsWith(publicRoot + sep) || !existsSync(file)) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': ({ '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2' })[extname(file)] || 'application/octet-stream' });
    res.end(readFileSync(file));
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = 'http://127.0.0.1:' + server.address().port;
const profile = join(fixtures, 'chromium-' + Date.now());
mkdirSync(profile, { recursive: true });
const child = spawn(browser, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
let socket;
let browserLog = '';
child.stderr.on('data', chunk => browserLog += chunk.toString());
const deadline = setTimeout(() => { child.kill(); server.closeAllConnections(); server.close(); socket?.close(); console.error('Browser test timed out. ' + browserLog.slice(-1000)); process.exitCode = 1; }, 60000);
try {
    const active = join(profile, 'DevToolsActivePort');
    for (let i = 0; i < 150 && !existsSync(active); i++) await pause(100);
    assert.ok(existsSync(active), 'Browser did not start. ' + browserLog.slice(-1000));
    const port = readFileSync(active, 'utf8').split('\n')[0].trim();
    const pages = await fetch('http://127.0.0.1:' + port + '/json/list').then(response => response.json());
    socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    const pending = new Map();
    let next = 1;
    const errors = [];
    socket.onmessage = event => {
        const message = JSON.parse(event.data);
        if (message.id) { const request = pending.get(message.id); pending.delete(message.id); if (request) message.error ? request.reject(new Error(JSON.stringify(message.error))) : request.resolve(message.result); }
        if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    };
    const send = (method, params = {}) => new Promise((resolve, reject) => { const id = next++; pending.set(id, { resolve, reject }); socket.send(JSON.stringify({ id, method, params })); });
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    await send('Page.enable'); await send('Runtime.enable');
    await send('Page.navigate', { url: origin + '/create.html' });
    for (let i = 0; i < 100 && !await evaluate("document.readyState === 'complete' && !!document.querySelector('[data-party-opening]')"); i++) await pause(100);
    await evaluate("window.openingForm = document.querySelector('[data-party-opening]').closest('form'); window.setDirection = value => { const input = document.querySelector('[data-opening-direction]'); input.value = value; input.dispatchEvent(new Event('change')); }; window.openingFields = document.querySelector('[data-opening-fields]'); window.openingRole = document.querySelector('[data-opening-role]');");
    assert.equal(await evaluate("getComputedStyle(openingFields).display"), 'none');
    assert.deepEqual(await evaluate("[...new FormData(openingForm).keys()].filter(key => key.startsWith('opening['))"), ['opening[idempotency_key]', 'opening[direction]']);
    await evaluate("setDirection('receive')");
    assert.notEqual(await evaluate('getComputedStyle(openingFields).display'), 'none');
    assert.equal(await evaluate('openingRole.value'), 'customer');
    assert.deepEqual(await evaluate("[...openingRole.options].filter(option => option.value && !option.disabled).map(option => option.value)"), ['customer']);
    await evaluate("document.querySelector('[name=\"opening[amount]\"]').value='1000'; document.querySelector('[name=\"opening[reference]\"]').value='OLD-INV-001'; document.querySelector('[name=\"opening[due_on]\"]').value='2026-01-05';");
    assert.equal(await evaluate("new FormData(openingForm).get('opening[amount]')"), '1000');
    await evaluate("setDirection('pay')");
    assert.equal(await evaluate('openingRole.value'), '');
    assert.deepEqual(await evaluate("[...openingRole.options].filter(option => option.value && !option.disabled).map(option => option.value)"), ['supplier', 'vendor']);
    await evaluate("const input = document.querySelector('[name=\"roles[]\"][value=\"supplier\"]'); input.checked = false; input.dispatchEvent(new Event('change'));");
    assert.equal(await evaluate('openingRole.value'), 'vendor');
    await evaluate("setDirection('none')");
    assert.equal(await evaluate('openingFields.disabled'), true);
    assert.equal(await evaluate("new FormData(openingForm).has('opening[amount]')"), false);
    await evaluate("setDirection('receive'); document.documentElement.classList.add('sidebar-collapsed');");
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1100, deviceScaleFactor: 1, mobile: false });
    await evaluate("document.querySelector('[data-party-opening]').scrollIntoView({block:'center'})");
    await send('Page.captureScreenshot', { format: 'png' }).then(result => writeFileSync(join(fixtures, 'desktop.png'), Buffer.from(result.data, 'base64')));
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
    await evaluate("document.querySelector('[data-party-opening]').scrollIntoView({block:'start'})");
    const widths = await evaluate('({content:document.documentElement.scrollWidth,viewport:window.innerWidth})');
    assert.ok(widths.content <= widths.viewport + 1, 'Mobile overflow: ' + JSON.stringify(widths));
    await send('Page.captureScreenshot', { format: 'png' }).then(result => writeFileSync(join(fixtures, 'mobile.png'), Buffer.from(result.data, 'base64')));
    assert.deepEqual(errors, []);
    console.log('Party opening browser passed: optional fields, role/direction matching, payloads, desktop/mobile layout; no JS exceptions.');
} finally {
    clearTimeout(deadline); socket?.close(); child.kill(); server.closeAllConnections(); server.close();
}
