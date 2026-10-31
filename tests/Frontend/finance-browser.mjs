// Build assets first, export with FINANCE_BROWSER_FIXTURES=1 PHPUnit workspace tests, then run this script.
// Uses synthetic test HTML only; never signs in to or writes to the application DB.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join, extname, sep } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const fixtures = join(root, 'storage/framework/testing/finance-browser');
const publicRoot = join(root, 'public');
const browser = process.env.FINANCE_BROWSER || [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
].find(existsSync);
assert.ok(browser, 'Set FINANCE_BROWSER to a compatible Chromium executable.');
for (const kind of ['overview','accounts','bank','show','supplier-credit','journal','cash_journal','opening','payment','receipt','settlement','reversal','trial','profit','balance','ledger','party','open','reconciliation']) assert.ok(existsSync(join(fixtures,kind+'.html')), 'Export PHPUnit browser fixtures first: '+kind);
const pause = (ms) => new Promise((r) => setTimeout(r,ms));
const server = createServer((req,res) => {
    const pathname = new URL(req.url,'http://127.0.0.1').pathname;
    if (pathname === '/sales/items') { res.writeHead(200,{'content-type':'application/json'});res.end(readFileSync(join(fixtures,'items.json')));return; }
    const fixture = /^\/(guided-payment|overview|accounts|bank|show|supplier-credit|journal|cash_journal|opening|payment|receipt|settlement|reversal|trial|profit|balance|ledger|party|open|reconciliation)\.html$/.exec(pathname);
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
const deadline=setTimeout(()=>{console.error('Browser smoke timed out. '+browserLog.slice(-2000)); child.kill(); server.closeAllConnections(); server.close(); process.exitCode=1; if(socket)socket.close();},120000);
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
        await waitFor(`location.pathname==='/${kind}.html' && document.readyState==='complete' && window.Alpine && document.querySelector('#finance-sidebar-links')`);

    };
    const mobile=async(kind)=>{
        await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false}).then(r=>writeFileSync(join(fixtures,kind+'-desktop.png'),Buffer.from(r.data,'base64')));
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await pause(100);
        const width=await evaluate('({content:document.documentElement.scrollWidth,viewport:window.innerWidth})');
        assert.ok(width.content<=width.viewport+1,kind+' overflows mobile width: '+JSON.stringify(width));
        await evaluate("document.querySelector('[aria-label=\"Open navigation\"]').click()");await pause(100);
        assert.ok(await evaluate("Alpine.$data(document.body).sidebarOpen"));
        // Synthetic URLs are not Wholesale Sales routes, so explicitly open the category first.
        await evaluate("(()=>{const b=document.querySelector('[aria-controls=\"finance-sidebar-links\"]');if(b.getAttribute('aria-expanded')!=='true')b.click();})()");await pause(200);
        assert.notEqual(await evaluate("getComputedStyle(document.querySelector('#finance-sidebar-links')).display"),'none');
        await evaluate("document.querySelector('[aria-controls=\"finance-sidebar-links\"]').click()");await pause(200);
        assert.equal(await evaluate("getComputedStyle(document.querySelector('#finance-sidebar-links')).display"),'none');
        await evaluate("document.querySelector('[aria-controls=\"finance-sidebar-links\"]').click()");await pause(200);
        await evaluate("Alpine.$data(document.body).sidebarOpen=false");await pause(200);
        await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false}).then(r=>writeFileSync(join(fixtures,kind+'-mobile.png'),Buffer.from(r.data,'base64')));
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
    };
    for(const kind of ['overview','accounts','bank','show','supplier-credit','journal','cash_journal','opening','payment','receipt','settlement','reversal','trial','profit','balance','ledger','party','open','reconciliation']) {
        await open(kind);
        if (['journal','opening','cash_journal'].includes(kind)) {
            await evaluate("(()=>{const f=Alpine.$data(document.querySelector('form[x-data]'));f.lines[0].debit='17';f.lines.push({ledger_account_id:'',debit:'25',credit:'0',party_role_id:'',due_on:''});f.lines.splice(1,1);})()");
            await pause(100);
            assert.equal(await evaluate("document.getElementsByName('lines[1][debit]')[0].value"),'25');
        }
        await mobile(kind);
    }
    await open('guided-payment');
    await evaluate(`(()=>{const f=Alpine.$data(document.querySelector('.finance[x-data]'));f.role=f.items.find(i=>i.debit).role;f.chooseRole();f.mode='settlement';f.switchMode();f.credit=f.credits[0].id;f.bill=f.bills[0].id;f.suggest();})()`);
    await pause(200);
    assert.equal(await evaluate("Alpine.$data(document.querySelector('.finance[x-data]')).applied"),'300.0000');
    const payload=await evaluate("Object.fromEntries(new FormData(document.querySelector('.finance form'))) ");
    assert.equal(payload.kind,'settlement');
    assert.equal(payload['allocations[0][amount]'],'300.0000');
    assert.ok(!('bank_account_id' in payload));
    assert.ok(!('amount' in payload));
    await evaluate(`(()=>{const f=Alpine.$data(document.querySelector('.finance[x-data]'));f.mode='money';f.switchMode();f.amount='400';f.bill=f.bills[0].id;f.suggest();})()`);
    await pause(200);
    const paymentPayload=await evaluate("Object.fromEntries(new FormData(document.querySelector('.finance form'))) ");
    assert.equal(paymentPayload.kind,'payment');
    assert.equal(paymentPayload.apply_amount,'400.0000');
    assert.ok(!('allocations[0][amount]' in paymentPayload));
    await mobile('guided-payment');
    assert.deepEqual(errors,[],'Browser JavaScript errors');
    console.log('PASS twenty Finance fixtures: desktop/mobile, guided advance/payment payloads, sidebar, journal line editing and no JavaScript errors');
} catch(error) {
    if(browserLog)console.error(browserLog.slice(-3000));
    throw error;
} finally {
    if(socket?.readyState===1) { socket.send(JSON.stringify({id:999999,method:'Browser.close'})); socket.close(); }
    clearTimeout(deadline); child.kill(); server.closeAllConnections(); server.close();
}
