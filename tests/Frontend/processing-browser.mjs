// Run after PROCESSING_BROWSER_FIXTURES=1 PHPUnit workspace tests and npm run build.
// Uses synthetic test HTML only; never signs in to or writes to the application DB.
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync, readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { resolve, join, extname, sep } from 'node:path';

const root = resolve(import.meta.dirname, '../..');
const fixtures = join(root, 'storage/framework/testing/processing-browser');
const publicRoot = join(root, 'public');
const browser = process.env.PROCESSING_BROWSER || [
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
].find(existsSync);
assert.ok(browser, 'Set PROCESSING_BROWSER to a compatible Chromium executable.');
for (const kind of ['overview','job','dispatch','receipt','bill']) assert.ok(existsSync(join(fixtures,kind+'.html')), 'Export PHPUnit browser fixtures first: '+kind);
const pause = (ms) => new Promise((r) => setTimeout(r,ms));
const server = createServer((req,res) => {
    const pathname = new URL(req.url,'http://127.0.0.1').pathname;
    const fixture = /^\/(overview|job|dispatch|receipt|bill)\.html$/.exec(pathname);
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
    const open=async(kind)=>{console.log('Checking '+kind);await send('Page.navigate',{url:origin+'/'+kind+'.html'});await waitFor("document.readyState==='complete' && window.Alpine && document.querySelector('#processing-sidebar-links')");if(['dispatch','receipt','bill'].includes(kind))await waitFor("document.getElementsByName('lines[0]["+({dispatch:'source_stock_position_id',receipt:'processing_dispatch_line_id',bill:'processing_receipt_line_id'})[kind]+"]')[0]?.options.length>1");};
    const input=async(name,value)=>{await evaluate(`(()=>{const el=document.getElementsByName(${JSON.stringify(name)})[0];if(!el)throw Error('Missing input');el.value=${JSON.stringify(value)};el.dispatchEvent(new Event(el.tagName==='SELECT'?'change':'input',{bubbles:true}));})()`);await pause(50);};
    await open('overview'); assert.ok(await evaluate("document.body.innerText.includes('Before sending fabric')"));
    await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false}).then(r=>writeFileSync(join(fixtures,'overview.png'),Buffer.from(r.data,'base64')));
    await open('job');assert.ok(await evaluate("!!document.getElementsByName('lines[0][vendor_service_id]')[0]"));
    for(const kind of ['dispatch','receipt','bill']){
        await open(kind);
        assert.ok(await evaluate("!!document.querySelector('button[name=intent][value=confirm]')"),'Confirm button missing');
        assert.ok(await evaluate("document.querySelector('button[name=intent][value=save]').textContent.includes('Save for later')"));

        const key=({dispatch:'source_stock_position_id',receipt:'processing_dispatch_line_id',bill:'processing_receipt_line_id'})[kind];
        const option=await evaluate(`document.getElementsByName('lines[0][${key}]')[0].options[1].value`);
        await input('lines[0]['+key+']',option);
        if(kind==='dispatch'){
            await input('lines[0][quantity]','20');
            await evaluate("[...document.querySelectorAll('button')].find(b=>b.textContent.trim()==='Add line').click()");
            await waitFor("document.querySelectorAll('fieldset').length===2");
            await evaluate("document.querySelector('fieldset button').click()");
            await waitFor("document.querySelectorAll('fieldset').length===1");
            assert.ok(await evaluate("!!document.getElementsByName('lines[0][source_stock_position_id]')[0]"));
        } else if(kind==='receipt'){
            for(const [name,value] of Object.entries({source_quantity:'40',output_quantity:'38',normal_loss_quantity:'2',abnormal_loss_quantity:'0'}))await input('lines[0]['+name+']',value);
            const p=await evaluate("(()=>{const f=Alpine.$data(document.querySelector('form[x-data]'));return f.preview(f.lines[0]);})()");
            assert.equal(p.difference,0);assert.equal(p.remaining,60);assert.equal(p.charge,76);
        } else {
            await input('lines[0][quantity]','20');assert.equal(await evaluate("Alpine.$data(document.querySelector('form[x-data]')).total()"),40);
        }
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        await pause(100);
        const width=await evaluate('({content:document.documentElement.scrollWidth,viewport:window.innerWidth})');
        assert.ok(width.content<=width.viewport+1,kind+' overflows mobile width: '+JSON.stringify(width));
        await evaluate("document.querySelector('[aria-label=\"Open navigation\"]').click()");
        await pause(100); assert.ok(await evaluate("Alpine.$data(document.body).sidebarOpen"));
        await evaluate("document.querySelector('[aria-controls=\"processing-sidebar-links\"]').click()");await pause(50);
        assert.equal(await evaluate("getComputedStyle(document.querySelector('#processing-sidebar-links')).display"),'none');
        await evaluate("document.querySelector('[aria-controls=\"processing-sidebar-links\"]').click()");await pause(50);
        assert.notEqual(await evaluate("getComputedStyle(document.querySelector('#processing-sidebar-links')).display"),'none');
        await evaluate("Alpine.$data(document.body).sidebarOpen=false"); await pause(200);
        await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false}).then(r=>writeFileSync(join(fixtures,kind+'-mobile.png'),Buffer.from(r.data,'base64')));
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        console.log('PASS '+kind+': Alpine choices, edits, estimates and mobile navigation');
    }
    assert.deepEqual(errors,[],'Browser JavaScript errors');
    console.log('PASS overview/job screens and no browser JavaScript errors');
} catch(error) {
    if(browserLog)console.error(browserLog.slice(-3000));
    throw error;
} finally {
    if(socket?.readyState===1) { socket.send(JSON.stringify({id:999999,method:'Browser.close'})); socket.close(); }
    clearTimeout(deadline); child.kill(); server.closeAllConnections(); server.close();
}
