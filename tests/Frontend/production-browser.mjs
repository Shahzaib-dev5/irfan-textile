// Build assets first, export with PRODUCTION_BROWSER_FIXTURES=1 PHPUnit workspace tests, then run this script.
// Uses synthetic test HTML only; never signs in to or writes to the application DB.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join, extname, sep } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const fixtures = join(root, 'storage/framework/testing/production-browser');
const publicRoot = join(root, 'public');
const browser = process.env.PRODUCTION_BROWSER || [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
].find(existsSync);
assert.ok(browser, 'Set PRODUCTION_BROWSER to a compatible Chromium executable.');
for (const kind of ['overview','bom','batch','report','show','progress']) assert.ok(existsSync(join(fixtures,kind+'.html')), 'Export PHPUnit browser fixtures first: '+kind);
const pause = (ms) => new Promise((r) => setTimeout(r,ms));
const server = createServer((req,res) => {
    const pathname = new URL(req.url,'http://127.0.0.1').pathname;
    const fixture = /^\/(overview|bom|batch|report|show|progress)\.html$/.exec(pathname);
    if (fixture) {
        const origin='http://127.0.0.1:'+server.address().port;
        const html=readFileSync(join(fixtures,fixture[1]+'.html'),'utf8').replace(/https?:\/\/[^/"\s<>]+/g,origin);
        res.writeHead(200,{'content-type':'text/html; charset=utf-8'}); res.end(html); return;
    }
    const path=resolve(publicRoot,'.'+decodeURIComponent(pathname));
    if (!path.startsWith(publicRoot+sep) || !existsSync(path)) { res.writeHead(404);res.end();return; }
    res.writeHead(200,{'content-type':({'.js':'text/javascript','.css':'text/css','.woff2':'font/woff2','.woff':'font/woff'})[extname(path)]||'application/octet-stream'});
    res.end(readFileSync(path));
});
await new Promise((r)=>server.listen(0,'127.0.0.1',r));
const origin='http://127.0.0.1:'+server.address().port;
const profile=join(fixtures,'chromium-'+Date.now()); mkdirSync(profile,{recursive:true});
const child=spawn(browser,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-extensions','--disable-background-networking','--disable-component-update','--remote-debugging-port=0','--user-data-dir='+profile,'about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
let socket; let browserLog='';
child.stderr.on('data', chunk=>{browserLog+=chunk.toString();});
const deadline=setTimeout(()=>{console.error('Browser smoke timed out. '+browserLog.slice(-2000)); child.kill(); server.closeAllConnections(); server.close(); process.exitCode=1; if(socket)socket.close();},60000);
try {
    const active=join(profile,'DevToolsActivePort');
    for(let i=0;i<200&&!existsSync(active);i++) await pause(100);
    assert.ok(existsSync(active),'Chromium did not start.');
    const port=readFileSync(active,'utf8').split('\n')[0].trim();
    console.log('Chromium debugger ready on '+port);
    const pages=await fetch('http://127.0.0.1:'+port+'/json/list',{signal:AbortSignal.timeout(10000)}).then(r=>r.json());
    socket=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);
    await Promise.race([new Promise((r,j)=>{socket.onopen=r;socket.onerror=j;}),pause(10000).then(()=>{throw new Error('Debugger socket timeout');})]);
    console.log('Debugger connected');
    const pending=new Map(); let next=1; const errors=[];
    socket.onmessage=(event)=>{
        const m=JSON.parse(event.data);
        if(m.id){const p=pending.get(m.id);pending.delete(m.id);if(p){if(m.error)p.reject(new Error(JSON.stringify(m.error)));else p.resolve(m.result);}}
        if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text+': '+(m.params.exceptionDetails.exception?.description||''));
        if(m.method==='Runtime.consoleAPICalled'&&['error','warning'].includes(m.params.type))errors.push(m.params.args.map(a=>a.value||a.description||'').join(' '));
    };
    const send=(method,params={})=>new Promise((resolve,reject)=>{const id=next++;const timeout=setTimeout(()=>{pending.delete(id);reject(new Error('Debugger command timeout: '+method));},10000);pending.set(id,{resolve:(r)=>{clearTimeout(timeout);resolve(r);},reject:(e)=>{clearTimeout(timeout);reject(e);}});socket.send(JSON.stringify({id,method,params}));});
    const evaluate=async(expression)=>{const r=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw new Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;};
    const waitFor=async(expression)=>{for(let i=0;i<100;i++){if(await evaluate(expression))return;await pause(100);}throw new Error('Browser timeout: '+expression);};
    console.log('Browser protocol: '+JSON.stringify(await send('Browser.getVersion')));
    await send('Page.enable');await send('Runtime.enable');
    await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    const open=async(kind)=>{
        console.log('Checking '+kind);
        await send('Page.navigate',{url:origin+'/'+kind+'.html'});
        await waitFor(`location.pathname==='/${kind}.html' && document.readyState==='complete' && window.Alpine && document.querySelector('#production-sidebar-links')`);
        if(kind==='report') await waitFor("Alpine.$data(document.querySelector('form[x-data]')).materials.length===1");
    };
    const input=async(name,value)=>{
        await evaluate(`(()=>{const el=document.getElementsByName(${JSON.stringify(name)})[0];if(!el)throw Error('Missing input');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event(el.tagName==='SELECT'?'change':'input',{bubbles:true}));})()`);
        await pause(50);
    };
    const state=async(expression)=>evaluate(`(()=>{const f=Alpine.$data(document.querySelector('form[x-data]'));return ${expression};})()`);
    const choose=async(type)=>{await evaluate(`document.querySelector('input[type=radio][value="${type}"]').click()`);await pause(100);};
    const data=async()=>evaluate("Object.fromEntries(new FormData(document.querySelector('form[x-data]')))");
    const mobile=async(kind)=>{
        await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false}).then(r=>writeFileSync(join(fixtures,kind+'-desktop.png'),Buffer.from(r.data,'base64')));
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await pause(100);
        const width=await evaluate('({content:document.documentElement.scrollWidth,viewport:window.innerWidth})');
        assert.ok(width.content<=width.viewport+1,kind+' overflows mobile width: '+JSON.stringify(width));
        await evaluate("document.querySelector('[aria-label=\"Open navigation\"]').click()");await pause(100);
        assert.ok(await evaluate("Alpine.$data(document.body).sidebarOpen"));
        // Synthetic URLs are not Production routes, so explicitly open the category first.
        await evaluate("(()=>{const b=document.querySelector('[aria-controls=\"production-sidebar-links\"]');if(b.getAttribute('aria-expanded')!=='true')b.click();})()");await pause(200);
        assert.notEqual(await evaluate("getComputedStyle(document.querySelector('#production-sidebar-links')).display"),'none');
        await evaluate("document.querySelector('[aria-controls=\"production-sidebar-links\"]').click()");await pause(200);
        assert.equal(await evaluate("getComputedStyle(document.querySelector('#production-sidebar-links')).display"),'none');
        await evaluate("document.querySelector('[aria-controls=\"production-sidebar-links\"]').click()");await pause(200);
        await evaluate("Alpine.$data(document.body).sidebarOpen=false");await pause(200);
        await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false}).then(r=>writeFileSync(join(fixtures,kind+'-mobile.png'),Buffer.from(r.data,'base64')));
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    };
    for(const kind of ['overview','bom','batch','show','progress']) {
        await open(kind);
        if(kind==='batch') assert.ok(await evaluate("[...document.querySelectorAll('button')].some(b=>b.textContent.includes('Confirm production batch'))"));
        await mobile(kind);
    }
    await open('report');
    assert.ok(await evaluate("[...document.querySelectorAll('button')].some(b=>b.textContent.includes('Confirm production report'))"));
    await evaluate("(()=>{const f=Alpine.$data(document.querySelector('form[x-data]'));f.materials[0].quantity='300';f.add('materials');f.add('wastage');f.wastage[0].material_index='1';f.remove('materials',0);})()");
    await pause(100);
    assert.equal(await evaluate("Alpine.$data(document.querySelector('form[x-data]')).wastage[0].material_index"),'0');
    assert.equal(await evaluate("document.getElementsByName('materials[0][quantity]').length"),1);
    await evaluate("(()=>{const f=Alpine.$data(document.querySelector('form[x-data]'));f.outputs[0].cost_allocation_fraction='0.5';f.add('outputs');f.outputs[1].cost_allocation_fraction='0.5';})()");
    await pause(100);
    assert.ok(await evaluate("document.body.innerText.includes('Cost shares total: 1')"));
    await mobile('report');
    assert.deepEqual(errors,[],'Browser JavaScript errors');
    console.log('PASS all six Production screens: desktop/mobile, nested sidebar, confirmation buttons, dynamic input/output/loss references and no browser JavaScript errors');
} catch(error) {
    if(browserLog)console.error(browserLog.slice(-3000));
    throw error;
} finally {
    if(socket?.readyState===1) { socket.send(JSON.stringify({id:999999,method:'Browser.close'})); socket.close(); }
    clearTimeout(deadline); child.kill(); server.closeAllConnections(); server.close();
}
