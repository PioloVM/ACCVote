/**
 * Guide end-to-end QA.
 *
 * Run from the repository root: node guide/tools/qa.mjs
 * Requires the app to be served at http://localhost/voting-app3/.
 */

import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch, sleep } from './cdp.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, 'out');
const BASE = 'http://localhost/voting-app3/guide/';
const GUIDE_STATE_KEY = 'guide.welcome.dismissed.v1';

let failures = 0;
function check(label, condition, detail) {
    if (condition) {
        console.log(`  ok   ${label}`);
    } else {
        failures += 1;
        console.log(`  FAIL ${label}${detail ? ` — ${detail}` : ''}`);
    }
}

function framePathExpression() {
    return `(() => {
        const frame = document.getElementById('app-frame');
        if (!frame || !frame.contentWindow) { return ''; }
        try {
            const path = frame.contentWindow.location.pathname;
            return path && path.endsWith('landingpage.php') ? path : '';
        } catch (error) { return ''; }
    })()`;
}

async function walkChapter(page, chapterId) {
    const meta = await page.evalJs(`(() => {
        const data = window.GUIDE_DATA || {};
        const chapter = (data.chapters || []).find((entry) => entry.id === ${JSON.stringify(chapterId)});
        if (!chapter) { return null; }
        return {
            id: chapter.id,
            steps: (chapter.steps || []).map((step) => {
                const annotation = (data.annotations || {})[step.annotation] || {};
                return {
                    annotation: step.annotation,
                    title: annotation.title || '',
                    path: (annotation.match || {}).path || '',
                    selector: annotation.selector || '',
                };
            }),
        };
    })()`);
    check(`chapter ${chapterId} is present`, !!meta);
    if (!meta) { return; }

    const clicked = await page.evalJs(`(() => {
        const target = document.querySelector('.chapter-btn[data-chapter=${JSON.stringify(chapterId)}]');
        if (!target) { return false; }
        target.click();
        return true;
    })()`);
    check(`chapter ${chapterId} has a rail button`, clicked === true);
    if (!clicked) { return; }

    for (let index = 0; index < meta.steps.length; index += 1) {
        const step = meta.steps[index];
        const where = `${chapterId}/${step.annotation}`;
        if (index > 0) {
            await page.evalJs(`document.getElementById('nextStep').click()`);
        }

        let position = '';
        try {
            position = await page.waitFor(`(() => {
                const state = window.Guide && window.Guide.state;
                const bar = document.getElementById('stepPosition');
                return state && state.chapterId === ${JSON.stringify(chapterId)} && state.stepIndex === ${index}
                    ? (bar ? bar.textContent.trim() : 'ok')
                    : '';
            })()`, 25000);
        } catch (error) { /* reported below */ }
        check(`${where} step bar advances`, position === `${index + 1} of ${meta.steps.length}`, position);

        let title = '';
        try {
            title = await page.waitFor(`(() => {
                const drawer = document.getElementById('drawer');
                const heading = document.getElementById('drawerTitle');
                if (!drawer || !heading || drawer.getAttribute('aria-hidden') !== 'false') { return ''; }
                const current = heading.textContent.trim();
                return current === ${JSON.stringify(step.title)} ? current : '';
            })()`, 25000);
        } catch (error) {
            title = await page.evalJs(`document.getElementById('drawerTitle').textContent.trim()`);
        }
        check(`${where} opens its drawer`, title === step.title, title || 'no drawer title');

        let framePath = '';
        try {
            framePath = await page.waitFor(`(() => {
                const frame = document.getElementById('app-frame');
                const state = window.Guide && window.Guide.state;
                if (!frame || !frame.contentWindow || !state || !state.frameReady) { return ''; }
                try { return frame.contentWindow.location.pathname || ''; } catch (error) { return ''; }
            })()`, 25000);
        } catch (error) { /* reported below */ }
        check(`${where} loads ${step.path}`, framePath === step.path, framePath || 'no frame path');

        // A chapter step can always open its drawer from the rail, so also
        // prove that the annotation is reachable by clicking the live page:
        // a visible element with a matching selector must have a marker.
        // Hidden elements (the ballot modals) are skipped by design.
        let markerState = 'skip';
        try {
            markerState = await page.evalJs(`(() => {
                const frame = document.getElementById('app-frame');
                if (!frame || !frame.contentWindow) { return 'skip'; }
                let element = null;
                try {
                    element = frame.contentDocument.querySelector(${JSON.stringify(step.selector)});
                } catch (error) {
                    return 'skip';
                }
                if (!element) { return 'skip'; }
                const rect = element.getBoundingClientRect();
                return rect.width >= 2 && rect.height >= 2 ? 'visible' : 'skip';
            })()`);
        } catch (error) { markerState = 'skip'; }
        if (markerState === 'visible') {
            let hasMarker = '';
            try {
                hasMarker = await page.waitFor(`(() => {
                    const buttons = Array.from(document.querySelectorAll('#marker-layer .marker'));
                    return buttons.some((button) => button.title === ${JSON.stringify(step.title)}) ? 'ok' : '';
                })()`, 6000);
            } catch (error) { /* reported below */ }
            check(`${where} has a clickable marker`, hasMarker === 'ok', hasMarker || 'no marker');
        }

        await page.evalJs(`document.querySelector('.drawer-tabs button[data-tab="code"]').click()`);
        let codeInfo = null;
        try {
            codeInfo = await page.waitFor(`(() => {
                const body = document.getElementById('drawerBody');
                if (!body) { return null; }
                const blocks = body.querySelectorAll('.code-scroll pre');
                if (!blocks.length || (body.textContent || '').trim().length < 40) { return null; }
                const chip = document.getElementById('drawerFile');
                return {
                    blocks: blocks.length,
                    meta: Array.from(body.querySelectorAll('.code-meta')).map((node) => node.textContent).join(' '),
                    chip: chip ? chip.textContent.trim() : '',
                };
            })()`, 12000);
        } catch (error) { /* reported below */ }
        check(`${where} renders code`, !!codeInfo, codeInfo ? `${codeInfo.blocks} block(s)` : 'no code');
        if (codeInfo) {
            check(`${where} shows a resolved line range`, /L\d+–\d+/.test(codeInfo.meta), codeInfo.meta);
            check(`${where} names a source file`, /\.php/i.test(codeInfo.chip), codeInfo.chip);
        }
    }
}

async function waitFrame(page, selector, timeout = 15000) {
    try {
        await page.waitFor(`(() => {
            const frame = document.getElementById('app-frame');
            if (!frame || !frame.contentWindow) { return false; }
            try {
                return !!frame.contentDocument.querySelector(${JSON.stringify(selector)});
            } catch (error) { return false; }
        })()`, timeout);
        return true;
    } catch (error) {
        return false;
    }
}

async function frameEval(page, code) {
    return page.evalJs(`(() => {
        const frame = document.getElementById('app-frame');
        const win = frame && frame.contentWindow;
        if (!win) { return 'no frame'; }
        try {
            const script = win.document.createElement('script');
            script.textContent = ${JSON.stringify(`(() => { ${code} })();`)};
            (win.document.head || win.document.documentElement).appendChild(script);
            script.remove();
            return true;
        } catch (error) {
            return String(error);
        }
    })()`);
}

async function checkMarkerTitle(page, label, title) {
    let found = '';
    try {
        found = await page.waitFor(`(() => {
            const buttons = Array.from(document.querySelectorAll('#marker-layer .marker'));
            return buttons.some((button) => button.title === ${JSON.stringify(title)}) ? 'ok' : '';
        })()`, 8000);
    } catch (error) { /* reported below */ }
    check(`${label} has a clickable marker`, found === 'ok', found || 'no marker');
}

async function main() {
    mkdirSync(OUT, { recursive: true });    const session = await launch({ port: 9337, width: 1440, height: 1000 });
    const page = await session.page(BASE, { width: 1440, height: 1000 });
    try {
        await page.waitFor(`document.readyState === 'complete'`);
        await page.evalJs(`(() => {
            localStorage.setItem(${JSON.stringify(GUIDE_STATE_KEY)}, '1');
            const welcome = document.getElementById('welcome');
            if (welcome) { welcome.hidden = true; }
            return true;
        })()`);

        console.log('== live frame ==');
        const path = await page.waitFor(framePathExpression());
        check('guide iframe reaches landingpage.php', path === '/voting-app3/public/landingpage.php', path);

        const markerCount = await page.waitFor(
            `document.querySelectorAll('#marker-layer .marker').length > 0
                ? document.querySelectorAll('#marker-layer .marker').length
                : 0`
        );
        check('at least one annotation marker renders', markerCount >= 1, `${markerCount} markers`);

        console.log('== inspector ==');
        await page.evalJs(`document.querySelector('#marker-layer .marker').click()`);
        const title = await page.waitFor(`(() => {
            const drawer = document.getElementById('drawer');
            const heading = document.getElementById('drawerTitle');
            return drawer.getAttribute('aria-hidden') === 'false' && heading.textContent.trim()
                ? heading.textContent.trim()
                : '';
        })()`);
        check('marker click opens the drawer with the annotation title', /Hero/i.test(title), title);

        await page.evalJs(`document.querySelector('.drawer-tabs button[data-tab="code"]').click()`);
        await page.waitFor(`document.querySelectorAll('#drawerBody .code-scroll pre').length >= 2`);
        const codeText = await page.evalJs(`document.getElementById('drawerBody').textContent`);
        check('code tab renders the hero markup token', codeText.includes('section class="hero"'));
        check('code tab renders the election query token', codeText.includes('$elections'));
        const metaText = await page.evalJs(`Array.from(document.querySelectorAll('#drawerBody .code-meta')).map((n) => n.textContent).join(' | ')`);
        check('resolved line range is shown', /L\d+–\d+/.test(metaText), metaText);

        console.log('== inspect mode ==');
        await page.evalJs(`document.getElementById('drawerClose').click()`);
        await page.waitFor(`document.getElementById('drawer').getAttribute('aria-hidden') === 'true'`);
        await sleep(300);
        let dispatched = false;
        let inspectTitle = '';
        for (let attempt = 0; attempt < 3 && !inspectTitle; attempt += 1) {
            dispatched = await page.evalJs(`(() => {
                const frame = document.getElementById('app-frame');
                const doc = frame.contentDocument;
                const element = doc && doc.querySelector('section.hero');
                if (!element) { return false; }
                element.dispatchEvent(new frame.contentWindow.MouseEvent('click', {
                    bubbles: true,
                    cancelable: true,
                    view: frame.contentWindow,
                }));
                return true;
            })()`);
            try {
                inspectTitle = await page.waitFor(`(() => {
                    const drawer = document.getElementById('drawer');
                    const heading = document.getElementById('drawerTitle');
                    return drawer.getAttribute('aria-hidden') === 'false' && heading.textContent.trim()
                        ? heading.textContent.trim()
                        : '';
                })()`, 4000);
            } catch (error) {
                await sleep(250);
            }
        }
        check('hero element exists in the live frame', dispatched === true);
        check('inspect click opens the same annotation drawer', inspectTitle === title, inspectTitle);

        await page.shot(join(OUT, 'desktop-1440.png'));

        console.log('== chapters: start, public, login, register ==');
        for (const chapterId of ['start-here', 'public-site', 'student-login', 'student-register', 'functions-reference', 'admin-login']) {
            await walkChapter(page, chapterId);
        }

        console.log('== mobile shell ==');
        await page.evalJs(`(() => {
            const drawer = document.getElementById('drawer');
            if (drawer && drawer.getAttribute('aria-hidden') === 'false') {
                document.getElementById('drawerClose').click();
            }
            return window.Guide.navigate({ path: '/public/landingpage.php' });
        })()`);
        await page.waitFor(framePathExpression());
        await page.waitFor(`document.getElementById('drawer').getAttribute('aria-hidden') === 'true'`);
        await page.viewport(390, 844);
        await page.evalJs(`window.dispatchEvent(new Event('resize'))`);
        await sleep(700);
        const mobileState = await page.evalJs(`(() => {
            const frame = document.getElementById('app-frame');
            return {
                width: frame ? frame.getBoundingClientRect().width : 0,
                topbar: !!document.getElementById('tour-topbar'),
                railToggleVisible: (() => {
                    const button = document.getElementById('railToggle');
                    if (!button) { return false; }
                    return getComputedStyle(button).display !== 'none';
                })(),
            };
        })()`);
        check('frame fits the 390px preview width', mobileState.width > 0 && mobileState.width <= 390, `${Math.round(mobileState.width)}px`);
        check('mobile keeps the rail toggle', mobileState.railToggleVisible === true);
        await page.shot(join(OUT, 'mobile-390.png'));

        console.log('== demo sessions ==');
        await page.viewport(1440, 1000);
        const studentPath = await page.evalJs(`window.Guide.loginAs('student')`).then(() => page.waitFor(`(() => {
            const frame = document.getElementById('app-frame');
            if (!frame || !frame.contentWindow) { return ''; }
            try {
                const path = frame.contentWindow.location.pathname;
                return path.endsWith('dashboard.php') ? path : '';
            } catch (error) { return ''; }
        })()`));
        check('demo student reaches the dashboard', studentPath === '/voting-app3/student/dashboard.php', studentPath);
        await page.waitFor(`document.querySelector('#rolePill').dataset.role === 'student'`);
        check('role pill reports the student session', true);

        console.log('== chapters: enrollment update (student session) ==');
        await walkChapter(page, 'student-update-account');

        console.log('== chapters: student portal (student session) ==');
        for (const chapterId of ['student-dashboard', 'student-candidates', 'student-results', 'frontend-architecture']) {
            await walkChapter(page, chapterId);
        }

        await page.evalJs(`window.Guide.logout()`);
        await page.waitFor(framePathExpression());
        check('logout returns to the landing page', true);

        await page.evalJs(`window.Guide.loginAs('admin')`);
        const adminPath = await page.waitFor(`(() => {
            const frame = document.getElementById('app-frame');
            if (!frame || !frame.contentWindow) { return ''; }
            try {
                const path = frame.contentWindow.location.pathname;
                return path.endsWith('admin/dashboard.php') ? path : '';
            } catch (error) { return ''; }
        })()`);
        check('demo admin reaches the admin dashboard', adminPath === '/voting-app3/admin/dashboard.php', adminPath);
        await page.waitFor(`document.querySelector('#rolePill').dataset.role === 'admin'`);
        check('role pill reports the admin session', true);

        console.log('== chapters: ballot preview (admin session) ==');
        await walkChapter(page, 'student-ballot');

        console.log('== chapters: admin (admin session) ==');
        for (const chapterId of ['admin-dashboard', 'admin-elections', 'admin-students', 'admin-results', 'admin-operations', 'school-years']) {
            await walkChapter(page, chapterId);
        }

        console.log('== admin states not reachable by chapter walking ==');
        await page.evalJs(`(() => {
            const drawer = document.getElementById('drawer');
            if (drawer && drawer.getAttribute('aria-hidden') === 'false') {
                document.getElementById('drawerClose').click();
            }
            return true;
        })()`);

        let call = await frameEval(page, `showPanel('election')`);
        check('admin can open the election panel', call === true, String(call));
        check('election list renders', await waitFrame(page, '#electionListView'));

        call = await frameEval(page, `openCreateElection()`);
        check('wizard step 1 opens', call === true && await waitFrame(page, '.type-choice-row'), String(call));
        await checkMarkerTitle(page, 'wizard step 1', 'Step 1 — type and identity');

        call = await frameEval(page, `selectType('SSG')`);
        check('wizard setup renders after type choice', call === true && await waitFrame(page, '#positionsTable'), String(call));
        await checkMarkerTitle(page, 'wizard positions', 'Positions table');

        call = await frameEval(page, `wizardStep = 2; renderWizard()`);
        check('wizard step 2 renders', call === true && await waitFrame(page, '.candidate-block'), String(call));
        await checkMarkerTitle(page, 'wizard candidates', 'Step 2 — candidates');
        await checkMarkerTitle(page, 'wizard save', 'Saving the election');

        call = await frameEval(page, `wizardDraft = null; wizardOriginalSnapshot = null;
            const wizardView = document.getElementById('electionWizardView');
            const listView = document.getElementById('electionListView');
            if (wizardView) { wizardView.style.display = 'none'; }
            if (listView) { listView.style.display = 'block'; }`);
        check('wizard can be closed without writing', call === true, String(call));

        call = await frameEval(page, `showPanel('students')`);
        check('admin can open the students panel', call === true, String(call));
        check('student table renders', await waitFrame(page, '.students-table'));

        call = await frameEval(page, `openRegisterStudent()`);
        check('student form opens', call === true && await waitFrame(page, '#studentFormView .wizard-topbar'), String(call));
        await checkMarkerTitle(page, 'student form', 'The student form');
        await checkMarkerTitle(page, 'student password', 'Passwords and resets');
        await frameEval(page, `cancelStudentForm()`);

        call = await frameEval(page, `showPanel('results')`);
        check('admin can open the results panel', call === true, String(call));
        check('results tabs render', await waitFrame(page, '.results-tab-row'));

        call = await frameEval(page, `(async () => {
            const list = await (await fetch(ELECTIONS_API)).json();
            const live = list.find((e) => e.status !== 'archived');
            resultsView = 'detail';
            // If every election is archived (right after "Next School Year"),
            // the detail list is empty by design — deep-link the first
            // election instead so the tally block and its markers are still tested.
            await renderResults(live ? null : (list[0] && list[0].id));
        })()`);
        check('results detail renders', call === true && await waitFrame(page, '.results-block'), String(call));
        await checkMarkerTitle(page, 'results detail', 'Candidate tallies');
        await checkMarkerTitle(page, 'results visibility', 'Student visibility');
        await checkMarkerTitle(page, 'results export', 'Exporting detailed results');

        call = await frameEval(page, `resultsView = 'reports'; renderResults()`);
        check('results reports render', call === true && await waitFrame(page, '#reportPreviewArea'), String(call));
        await checkMarkerTitle(page, 'results reports', 'Printable reports');
        await frameEval(page, `resultsView = 'overview'; renderResults()`);

        console.log('== system reference ==');
        await page.evalJs(`(() => {
            const drawer = document.getElementById('drawer');
            if (drawer && drawer.getAttribute('aria-hidden') === 'false') {
                document.getElementById('drawerClose').click();
            }
            return window.Guide.renderSystem('overview');
        })()`);
        await page.waitFor(`(() => {
            const view = document.getElementById('systemView');
            const body = document.getElementById('systemBody');
            return view && !view.hidden && body && (body.textContent || '').trim().length > 200;
        })()`);
        check('system overview renders content', true);

        for (const view of ['overview', 'architecture', 'flows', 'database', 'api', 'security', 'functions', 'defense', 'quality', 'glossary']) {
            const rendered = await page.evalJs(`(() => {
                window.Guide.renderSystem(${JSON.stringify(view)});
                const body = document.getElementById('systemBody');
                const tab = document.querySelector('#systemTabs button.active');
                return {
                    length: body ? (body.textContent || '').trim().length : 0,
                    tab: tab ? tab.getAttribute('data-system-tab') : '',
                };
            })()`);
            check(`system ${view} renders`, rendered.length > 40 && rendered.tab === view, `${rendered.length} chars, tab ${rendered.tab || 'none'}`);
        }

        const tableName = await page.evalJs(`(() => {
            window.Guide.renderSystem('database');
            const button = document.querySelector('#systemBody [data-system-entry^="table:"]');
            if (!button) { return ''; }
            const name = button.getAttribute('data-system-entry').slice(6);
            button.click();
            return name;
        })()`);
        check('database explorer lists a table', !!tableName, tableName);
        let tableChip = '';
        try {
            tableChip = await page.waitFor(`(() => {
                const drawer = document.getElementById('drawer');
                const chip = document.getElementById('drawerFile');
                if (!drawer || drawer.getAttribute('aria-hidden') !== 'false' || !chip) { return ''; }
                return /schema\\.sql/i.test(chip.textContent) ? chip.textContent.trim() : '';
            })()`, 15000);
        } catch (error) { /* reported below */ }
        check('table entry opens its schema source', /schema\.sql/i.test(tableChip), tableChip || 'no file chip');
        await page.evalJs(`document.getElementById('drawerClose').click()`);
        await page.waitFor(`document.getElementById('drawer').getAttribute('aria-hidden') === 'true'`);

        const endpoint = await page.evalJs(`(() => {
            window.Guide.renderSystem('api');
            const row = document.querySelector('#systemBody [data-system-entry^="endpoint:"]');
            if (!row) { return ''; }
            const value = row.getAttribute('data-system-entry');
            row.click();
            return value;
        })()`);
        check('API view lists an endpoint', !!endpoint, endpoint);
        let endpointChip = '';
        try {
            endpointChip = await page.waitFor(`(() => {
                const drawer = document.getElementById('drawer');
                const chip = document.getElementById('drawerFile');
                if (!drawer || drawer.getAttribute('aria-hidden') !== 'false' || !chip) { return ''; }
                return /\\.php/i.test(chip.textContent) ? chip.textContent.trim() : '';
            })()`, 15000);
        } catch (error) { /* reported below */ }
        check('endpoint opens its handler source', /\.php/i.test(endpointChip), endpointChip || 'no file chip');

        const fnName = await page.evalJs(`(() => {
            window.Guide.renderSystem('functions');
            const button = document.querySelector('#systemBody [data-system-entry^="function:"]');
            if (!button) { return ''; }
            const name = button.getAttribute('data-system-entry').slice(9);
            button.click();
            return name;
        })()`);
        check('functions view lists a helper', !!fnName, fnName);
        let fnChip = '';
        try {
            fnChip = await page.waitFor(`(() => {
                const drawer = document.getElementById('drawer');
                const chip = document.getElementById('drawerFile');
                if (!drawer || drawer.getAttribute('aria-hidden') !== 'false' || !chip) { return ''; }
                return /functions\\.php/i.test(chip.textContent) ? chip.textContent.trim() : '';
            })()`, 15000);
        } catch (error) { /* reported below */ }
        check('function entry opens its source', /functions\.php/i.test(fnChip), fnChip || 'no file chip');

        await page.evalJs(`(() => {
            const drawer = document.getElementById('drawer');
            if (drawer && drawer.getAttribute('aria-hidden') === 'false') {
                document.getElementById('drawerClose').click();
            }
            window.Guide.closeSystem();
        })()`);
        await page.waitFor(`document.getElementById('systemView').hidden === true`);
        check('system view closes back to the live frame', true);

        console.log('== defense kit ==');
        await page.evalJs(`window.Guide.renderSystem('defense')`);
        const defense = await page.evalJs(`(() => {
            const items = Array.from(document.querySelectorAll('#systemBody .qa-item'));
            const pitch = document.querySelector('#systemBody .defense-card');
            const demo = document.querySelectorAll('#systemBody .defense-demo li');
            return {
                items: items.length,
                pitch: pitch ? (pitch.textContent || '').trim().length : 0,
                demo: demo.length,
            };
        })()`);
        check('defense kit lists 20 questions', defense.items >= 20, `${defense.items} questions`);
        check('defense kit has a pitch and demo', defense.pitch > 200 && defense.demo >= 10, `${defense.pitch} chars, ${defense.demo} demo steps`);

        const qaSource = await page.evalJs(`(() => {
            const item = document.querySelector('#systemBody .qa-item');
            if (!item) { return false; }
            item.open = true;
            const button = item.querySelector('.qa-sources button');
            if (!button) { return false; }
            button.click();
            return true;
        })()`);
        let qaChip = '';
        try {
            qaChip = await page.waitFor(`(() => {
                const drawer = document.getElementById('drawer');
                const chip = document.getElementById('drawerFile');
                if (!drawer || drawer.getAttribute('aria-hidden') !== 'false' || !chip) { return ''; }
                return /\\.(php|sql|mjs)/i.test(chip.textContent) ? chip.textContent.trim() : '';
            })()`, 15000);
        } catch (error) { /* reported below */ }
        check('defense answer opens its source', qaSource === true && /\.(php|sql|mjs)/i.test(qaChip), qaChip || 'no file chip');
        await page.evalJs(`document.getElementById('drawerClose').click()`);
        await page.waitFor(`document.getElementById('drawer').getAttribute('aria-hidden') === 'true'`);
        await page.evalJs(`window.Guide.closeSystem()`);
        await page.waitFor(`document.getElementById('systemView').hidden === true`);

        console.log('== guided tour ==');
        const tourTotal = await page.evalJs(`(window.GUIDE_DATA.chapters || []).reduce((count, chapter) => count + ((chapter.steps || []).length), 0)`);
        await page.evalJs(`window.Guide.startTour()`);
        await page.waitFor(`document.getElementById('stepPosition').textContent.trim() === '1 of ${tourTotal}'`, 20000);
        check('guided tour starts at step 1', true);
        let advanced = 1;
        try {
            for (let step = 2; step <= 10 && step <= tourTotal; step += 1) {
                await page.evalJs(`window.Guide.nextStep()`);
                const expected = `${step} of ${tourTotal}`;
                await page.waitFor(`document.getElementById('stepPosition').textContent.trim() === ${JSON.stringify(expected)}`, 25000);
                advanced = step;
                await sleep(250);
            }
        } catch (error) {
            const diag = await page.evalJs(`(() => {
                const state = window.Guide.state;
                const frame = document.getElementById('app-frame');
                let framePath = '';
                try { framePath = frame.contentWindow.location.pathname + frame.contentWindow.location.search; } catch (e) { framePath = 'cross-origin'; }
                return {
                    bar: document.getElementById('stepPosition').textContent.trim(),
                    hash: window.location.hash,
                    framePath: framePath,
                    tourActive: state.tourActive,
                    chapterId: state.chapterId,
                    stepIndex: state.stepIndex,
                    activeId: state.activeId,
                    follow: state.follow,
                    systemOpen: state.systemOpen,
                    drawerOpen: state.drawerOpen,
                    pendingOpen: state.pendingOpen,
                    frameReady: state.frameReady,
                    navToken: state.navToken,
                };
            })()`);
            console.log(`  diag ${JSON.stringify(diag)}`);
            throw error;
        }
        check('guided tour advances 10 steps', advanced === Math.min(10, tourTotal), `${advanced} steps`);
        const followOff = await page.evalJs(`(() => {
            document.getElementById('followToggle').click();
            return document.getElementById('followToggle').getAttribute('aria-pressed');
        })()`);
        check('follow toggle turns off', followOff === 'false', followOff || 'no state');
        await page.evalJs(`window.Guide.setFollow(true); window.Guide.stopTour();`);

        console.log('== palette ==');
        await page.evalJs(`window.Guide.search('ballot')`);
        const palette = await page.evalJs(`(() => {
            const panel = document.getElementById('palette');
            const items = Array.from(document.querySelectorAll('#paletteResults .palette-item'));
            return {
                open: !!(panel && !panel.hidden),
                count: items.length,
                first: items[0] ? items[0].textContent : '',
            };
        })()`);
        check('palette finds ballot content', palette.open === true && palette.count > 0 && /ballot/i.test(palette.first), `${palette.count} results`);
        await page.evalJs(`(() => {
            const input = document.getElementById('paletteInput');
            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        })()`);
        await page.waitFor(`document.getElementById('palette').hidden === true`);
        check('Enter opens the first palette result', true);
        await page.evalJs(`(() => {
            const drawer = document.getElementById('drawer');
            if (drawer && drawer.getAttribute('aria-hidden') === 'false') {
                document.getElementById('drawerClose').click();
            }
            if (window.Guide.state.systemOpen) { window.Guide.closeSystem(); }
        })()`);

        console.log('== fallback ==');
        await page.evalJs(`window.Guide.navigate({ path: '/public/404.php' })`);
        let fallbackShown = '';
        try {
            fallbackShown = await page.waitFor(`(() => {
                const fallback = document.getElementById('fallback');
                if (!fallback || fallback.hidden) { return ''; }
                return document.querySelectorAll('#fallbackList button').length ? 'ok' : '';
            })()`, 12000);
        } catch (error) { /* reported below */ }
        check('404 in the frame shows the recorded view', fallbackShown === 'ok', fallbackShown || 'no fallback');
        await page.evalJs(`window.Guide.navigate({ path: '/public/landingpage.php' })`);
        await page.waitFor(framePathExpression());
        await page.waitFor(`document.getElementById('fallback').hidden === true`);
        check('live view recovers after the fallback', true);

        console.log('== print ==');
        await page.evalJs(`(() => {
            window.Guide.renderSystem('defense');
            window.dispatchEvent(new Event('beforeprint'));
        })()`);
        await page.send('Emulation.setEmulatedMedia', { media: 'print' });
        await sleep(400);
        const printState = await page.evalJs(`(() => {
            const head = document.querySelector('.print-head');
            const sysHead = document.querySelector('.system-head');
            const items = Array.from(document.querySelectorAll('#systemBody .qa-item'));
            return {
                head: head ? getComputedStyle(head).display : 'none',
                sysHead: sysHead ? getComputedStyle(sysHead).display : 'none',
                open: items.length ? items.every((item) => item.open) : false,
                items: items.length,
                date: (document.getElementById('printDate') || {}).textContent || '',
                width: Math.round(document.getElementById('systemBody').getBoundingClientRect().width),
            };
        })()`);
        check('print header is visible', printState.head !== 'none', printState.head);
        check('print uses the full page width', printState.width > 900, `${printState.width}px`);
        check('print hides screen chrome', printState.sysHead === 'none', printState.sysHead);
        check('print expands all defense answers', printState.open === true && printState.items >= 20, `${printState.items} open:${printState.open}`);
        check('print header carries a date', printState.date.length > 6, printState.date);
        await page.shot(join(OUT, 'print-defense.png'));
        await page.send('Emulation.setEmulatedMedia', { media: '' });
        await page.evalJs(`window.dispatchEvent(new Event('afterprint')); window.Guide.closeSystem();`);
        await page.waitFor(`document.getElementById('systemView').hidden === true`);

        await page.evalJs(`window.Guide.logout()`);
        await page.waitFor(framePathExpression());

        console.log('== console ==');
        // Candidate 1 in the demo data has a non-NULL but empty photo column,
        // so dashboard-data.php emits photo.php?c=1 and photo.php correctly
        // answers 404. The guide cannot fix app data (no DB writes), so this
        // known app-side image miss is reported but not counted as a guide
        // console error.
        const photoMisses = page.errors.filter((entry) => /photo\.php\?c=1(?:&|$)/i.test(entry));
        if (photoMisses.length) {
            console.log(`  note known app-data image 404: ${photoMisses.length} request(s) for candidate 1 with an empty photo`);
        }
        // The fallback test deliberately requests /404.php, so that one
        // response is expected and is not a guide error.
        const deliberate404 = page.errors.filter((entry) => /404\.php/i.test(entry));
        const errors = page.errors.filter((entry) => !/favicon/i.test(entry) && !photoMisses.includes(entry) && !deliberate404.includes(entry));
        check('no console errors', errors.length === 0, errors.join(' || '));

        console.log('');
        if (failures) {
            console.log(`FAIL — ${failures} assertion(s) failed. Screenshots: ${OUT}`);
        } else {
            console.log(`PASS — all assertions passed. Screenshots: ${OUT}`);
        }
    } finally {
        await page.close();
        await session.close();
    }
    process.exit(failures ? 1 : 0);
}

main().catch((error) => {
    console.error(`QA crashed: ${error.message}`);
    console.error(error.stack);
    process.exit(1);
});
