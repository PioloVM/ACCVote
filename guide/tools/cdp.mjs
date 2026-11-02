/**
 * Minimal headless-Chrome driver for the guide QA suite.
 *
 * No dependencies: Node 24 ships a global WebSocket and fetch. The driver
 * talks Chrome DevTools Protocol directly so the tests exercise the same
 * browser features a visitor uses.
 */

import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { setTimeout as sleep } from 'node:timers/promises';

const CHROME_CANDIDATES = [
    process.env.CHROME_PATH,
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    join(process.env.LOCALAPPDATA || '', 'Google/Chrome/Application/chrome.exe'),
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
].filter(Boolean);

function chromePath() {
    for (const candidate of CHROME_CANDIDATES) {
        if (existsSync(candidate)) { return candidate; }
    }
    throw new Error('Chrome was not found. Set CHROME_PATH to the browser binary.');
}

async function waitForEndpoint(port, attempts = 60) {
    for (let i = 0; i < attempts; i += 1) {
        try {
            const response = await fetch(`http://127.0.0.1:${port}/json/version`);
            if (response.ok) { return; }
        } catch (error) { /* browser still starting */ }
        await sleep(200);
    }
    throw new Error(`Chrome did not expose the debugging port ${port}.`);
}

class Connection {
    constructor(ws, label) {
        this.ws = ws;
        this.label = label;
        this.nextId = 0;
        this.pending = new Map();
        this.listeners = new Map();
        ws.addEventListener('message', (event) => this.receive(event));
    }

    static async open(url, label) {
        const ws = new WebSocket(url);
        await new Promise((resolve, reject) => {
            ws.addEventListener('open', resolve, { once: true });
            ws.addEventListener('error', () => reject(new Error(`Could not open ${label} websocket.`)), { once: true });
        });
        return new Connection(ws, label);
    }

    receive(event) {
        let message;
        try { message = JSON.parse(event.data); } catch (error) { return; }
        if (message.id && this.pending.has(message.id)) {
            const { resolve, reject } = this.pending.get(message.id);
            this.pending.delete(message.id);
            if (message.error) {
                reject(new Error(`${this.label} ${message.method || ''}: ${JSON.stringify(message.error)}`));
            } else {
                resolve(message.result);
            }
            return;
        }
        const handlers = this.listeners.get(message.method) || [];
        handlers.forEach((handler) => handler(message.params || {}));
    }

    on(method, handler) {
        if (!this.listeners.has(method)) { this.listeners.set(method, []); }
        this.listeners.get(method).push(handler);
    }

    send(method, params = {}) {
        const id = ++this.nextId;
        this.ws.send(JSON.stringify({ id, method, params }));
        return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
    }

    close() {
        try { this.ws.close(); } catch (error) { /* already closed */ }
    }
}

export async function launch(options = {}) {
    const port = options.port || 9333;
    const profile = options.profile || join(tmpdir(), `guide-cdp-${process.pid}-${Date.now()}`);
    const chrome = spawn(chromePath(), [
        '--headless=new',
        '--disable-gpu',
        '--hide-scrollbars',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-background-timer-throttling',
        '--disable-renderer-backgrounding',
        `--remote-debugging-port=${port}`,
        `--user-data-dir=${profile}`,
        '--window-size=1440,1000',
        'about:blank',
    ], { stdio: 'ignore' });

    await waitForEndpoint(port);
    const version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json();
    const browser = await Connection.open(version.webSocketDebuggerUrl, 'browser');

    const session = {
        port,
        browser,
        closed: false,
        async page(url, pageOptions = {}) {
            return openPage(port, url, pageOptions);
        },
        async close() {
            if (session.closed) { return; }
            session.closed = true;
            browser.close();
            chrome.kill();
            for (let i = 0; i < 20; i += 1) {
                try { rmSync(profile, { recursive: true, force: true }); break; }
                catch (error) { await sleep(100); }
            }
        },
    };
    return session;
}

async function openPage(port, url, options = {}) {
    const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
    const connection = await Connection.open(target.webSocketDebuggerUrl, 'page');
    const errors = [];
    const requests = [];
    const requestUrls = new Map();

    connection.on('Runtime.consoleAPICalled', (params) => {
        if (params.type !== 'error' && params.type !== 'assert') { return; }
        const text = (params.args || [])
            .map((arg) => arg.value !== undefined ? String(arg.value) : (arg.description || arg.type))
            .join(' ');
        errors.push(`console.${params.type}: ${text}`);
    });
    connection.on('Runtime.exceptionThrown', (params) => {
        const details = params.exceptionDetails || {};
        errors.push(`exception: ${details.exception?.description || details.text || 'unknown exception'}`);
    });
    connection.on('Log.entryAdded', (params) => {
        const entry = params.entry || {};
        if (entry.level !== 'error' || entry.source === 'network') { return; }
        errors.push(`${entry.source || 'log'}: ${entry.text || ''}`);
    });
    connection.on('Network.requestWillBeSent', (params) => {
        requestUrls.set(params.requestId, params.request?.url || '');
        requests.push(params.request?.url || '');
    });
    connection.on('Network.responseReceived', (params) => {
        const status = params.response?.status || 0;
        if (status < 400) { return; }
        errors.push(`http ${status}: ${params.response?.url || requestUrls.get(params.requestId) || params.requestId}`);
    });
    connection.on('Network.loadingFailed', (params) => {
        if (params.errorText === 'net::ERR_ABORTED') { return; }
        const url = requestUrls.get(params.requestId) || params.requestId;
        errors.push(`network: ${params.errorText} ${url}`);
    });

    const page = {
        connection,
        errors,
        requests,
        async send(method, params = {}) { return connection.send(method, params); },
        async goto(nextUrl, waitForLoad = true) {
            const loaded = new Promise((resolve) => {
                const handler = () => { connection.off?.('Page.loadEventFired', handler); resolve(); };
                connection.on('Page.loadEventFired', handler);
            });
            await connection.send('Page.navigate', { url: nextUrl });
            if (waitForLoad) {
                await Promise.race([loaded, sleep(options.loadTimeout || 15000)]);
            }
            return page;
        },
        async evalJs(expression) {
            const result = await connection.send('Runtime.evaluate', {
                expression,
                awaitPromise: true,
                returnByValue: true,
                userGesture: true,
            });
            if (result.exceptionDetails) {
                const details = result.exceptionDetails;
                throw new Error(details.exception?.description || details.text || 'Evaluation failed.');
            }
            return result.result?.value;
        },
        async waitFor(expression, timeout = options.waitTimeout || 12000, interval = 150) {
            const started = Date.now();
            let lastError = null;
            while (Date.now() - started < timeout) {
                try {
                    const value = await page.evalJs(expression);
                    if (value) { return value; }
                } catch (error) { lastError = error; }
                await sleep(interval);
            }
            throw new Error(`Timed out waiting for: ${expression}${lastError ? ` (last error: ${lastError.message})` : ''}`);
        },
        async shot(file, shotOptions = {}) {
            const params = { format: shotOptions.format || 'png' };
            if (params.format !== 'png' && shotOptions.quality) { params.quality = shotOptions.quality; }
            if (shotOptions.fullPage) {
                const metrics = await connection.send('Page.getLayoutMetrics');
                const height = Math.ceil(metrics.cssContentSize?.height || options.height || 1000);
                params.clip = {
                    x: 0,
                    y: 0,
                    width: options.width || 1440,
                    height: Math.min(height, 8000),
                    scale: 1,
                };
                params.captureBeyondViewport = true;
            }
            const result = await connection.send('Page.captureScreenshot', params);
            mkdirSync(dirname(file), { recursive: true });
            writeFileSync(file, Buffer.from(result.data, 'base64'));
            return file;
        },
        async viewport(width, height) {
            await connection.send('Emulation.setDeviceMetricsOverride', {
                width,
                height,
                deviceScaleFactor: 1,
                mobile: width <= 480,
            });
        },
        async close() {
            connection.close();
            try { await fetch(`http://127.0.0.1:${port}/json/close/${target.id}`); } catch (error) { /* already gone */ }
        },
    };

    await connection.send('Page.enable');
    await connection.send('Runtime.enable');
    await connection.send('Log.enable');
    await connection.send('Network.enable');
    await connection.send('Emulation.setDeviceMetricsOverride', {
        width: options.width || 1440,
        height: options.height || 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });

    if (url) { await page.goto(url); }
    return page;
}

export { sleep };
