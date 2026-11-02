/**
 * Capture the recorded screens used by the offline fallback.
 *
 * Run from the repository root: node guide/tools/capture.mjs
 * Requires the app to be served at http://localhost/voting-app3/.
 * Sessions are opened through the local demo bridge, so no database row
 * is written at any point.
 */

import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, sleep } from './cdp.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, '..', 'screens');
const BASE = 'http://localhost/voting-app3/';
const GUIDE = `${BASE}guide/index.php`;
const WIDTH = 1440;
const HEIGHT = 900;

async function demo(page, role) {
    const result = await page.evalJs(`(async () => {
        const body = new FormData();
        body.append('role', ${JSON.stringify(role)});
        const response = await fetch('api/demo-login.php', {
            method: 'POST',
            headers: { 'X-GUIDE-DEMO': '1' },
            body,
            credentials: 'same-origin'
        });
        return { ok: response.ok, text: await response.text() };
    })()`);
    if (!result.ok) { throw new Error(`demo login ${role} failed: ${result.text}`); }
}

async function logout(page) {
    await page.evalJs(`fetch('api/logout.php', {
        method: 'POST',
        headers: { 'X-GUIDE-DEMO': '1' },
        credentials: 'same-origin'
    })`);
}

async function snap(session, url, name, prepare) {
    const page = await session.page(url, { width: WIDTH, height: HEIGHT, loadTimeout: 20000 });
    try {
        await page.waitFor(`document.readyState === 'complete'`);
        await sleep(500);
        if (prepare) {
            await page.evalJs(`(async () => { ${prepare} })()`);
        }
        await sleep(1400);
        const file = join(OUT, `${name}.webp`);
        await page.shot(file, { format: 'webp', quality: 82 });
        console.log(`  captured ${name}.webp`);
    } finally {
        await page.close();
    }
}

async function main() {
    mkdirSync(OUT, { recursive: true });
    const session = await launch({ port: 9339, width: WIDTH, height: HEIGHT });
    const guide = await session.page(GUIDE, { width: WIDTH, height: HEIGHT });
    try {
        await guide.waitFor(`document.readyState === 'complete'`);
        await guide.evalJs(`localStorage.setItem('guide.welcome.dismissed.v1', '1')`);

        console.log('== anonymous screens ==');
        await snap(session, `${BASE}landingpage.php`, 'landingpage');
        await snap(session, `${BASE}login.php`, 'login');
        await snap(session, `${BASE}student/register.php`, 'student-register');
        await snap(session, `${BASE}results.php`, 'results');
        await snap(session, `${BASE}admin/login.php`, 'admin-login');
        await snap(session, `${BASE}404.php`, '404');

        console.log('== student screens ==');
        await demo(guide, 'student');
        await snap(session, `${BASE}dashboard.php`, 'dashboard');
        await snap(session, `${BASE}dashboard.php?page=vote`, 'dashboard-vote');
        await snap(session, `${BASE}dashboard.php?page=candidates`, 'dashboard-candidates');
        await snap(session, `${BASE}dashboard.php?page=results`, 'dashboard-results');

        console.log('== admin screens ==');
        await logout(guide);
        await demo(guide, 'admin');
        await snap(session, `${BASE}admin/dashboard.php`, 'admin-dashboard');
        for (const panel of ['election', 'students', 'results', 'archive', 'logs', 'settings']) {
            await snap(
                session,
                `${BASE}admin/dashboard.php`,
                `admin-dashboard-${panel}`,
                `await showPanel(${JSON.stringify(panel)});`
            );
        }

        console.log(`Done. Screens are in ${OUT}`);
    } finally {
        await guide.close();
        await session.close();
    }
}

main().catch((error) => {
    console.error(`Capture failed: ${error.message}`);
    console.error(error.stack);
    process.exit(1);
});
