const { chromium } = require('playwright-core');
const BASE = 'http://localhost/voting-app3';
const OUT = 'C:\\xampp\\htdocs\\voting-app3\\docs\\design\\screens';
const EXE = 'C:\\Users\\Piolo Mangilog\\AppData\\Local\\ms-playwright\\chromium-1248\\chrome-win64\\chrome.exe';
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

(async () => {
  const browser = await chromium.launch({ executablePath: EXE, headless: true, args: ['--force-device-scale-factor=1'] });
  const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 } })).newPage();
  page.on('console', m => { if (m.type() === 'error') console.log('CONSOLE', m.text()); });
  page.on('pageerror', e => console.log('PAGEERROR', e.message));

  // Public pages
  await page.goto(BASE + '/landingpage.php', { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  await page.screenshot({ path: OUT + '\\01-landing.jpeg', fullPage: true, type: 'jpeg', quality: 78 });
  console.log('landing');

  await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  await page.screenshot({ path: OUT + '\\02-login.jpeg', fullPage: true, type: 'jpeg', quality: 78 });
  console.log('login');

  await page.goto(BASE + '/student/register.php', { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  await page.screenshot({ path: OUT + '\\03-register.jpeg', fullPage: true, type: 'jpeg', quality: 78 });
  console.log('register');

  await page.goto(BASE + '/results.php', { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  await page.screenshot({ path: OUT + '\\04-results-public.jpeg', fullPage: true, type: 'jpeg', quality: 78 });
  console.log('results public');

  await page.goto(BASE + '/404.php', { waitUntil: 'networkidle' });
  await page.waitForTimeout(700);
  await page.screenshot({ path: OUT + '\\05-404.jpeg', fullPage: true, type: 'jpeg', quality: 78 });
  console.log('404');

  // Mobile landing
  const mctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true });
  const mpage = await mctx.newPage();
  await mpage.goto(BASE + '/landingpage.php', { waitUntil: 'networkidle' });
  await mpage.waitForTimeout(700);
  await mpage.screenshot({ path: OUT + '\\06-mobile-landing.jpeg', fullPage: true, type: 'jpeg', quality: 78 });
  console.log('mobile landing');

  // Admin login & dashboard
  await page.goto(BASE + '/admin/login.php', { waitUntil: 'networkidle' });
  await page.fill('#username', 'admin');
  await page.fill('#password', 'admin123');
  await Promise.all([page.waitForURL(/admin\/dashboard\.php/, { timeout: 15000 }), page.click('#submitBtn')]);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: OUT + '\\07-admin-dashboard.jpeg', fullPage: true, type: 'jpeg', quality: 78 });
  console.log('admin dashboard');

  // Admin panels
  for (const panel of ['election', 'students', 'results', 'archive', 'logs', 'settings']) {
    await page.click(`[data-panel="${panel}"]`);
    await sleep(1200);
    await page.screenshot({ path: OUT + `\\08-admin-${panel}.jpeg`, fullPage: true, type: 'jpeg', quality: 78 });
    console.log('admin ' + panel);
  }

  // Student login
  await page.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await page.fill('#student_id', '987654321');
  await page.fill('#password', 'password123');
  await Promise.all([page.waitForURL(/dashboard\.php/, { timeout: 15000 }), page.click('#submitBtn')]);
  await page.waitForLoadState('networkidle');
  await page.screenshot({ path: OUT + '\\09-student-home.jpeg', fullPage: true, type: 'jpeg', quality: 78 });
  console.log('student home');

  // Student panels
  for (const panel of ['candidates', 'vote', 'results']) {
    await page.click(`[data-panel="${panel}"]`);
    await sleep(1200);
    await page.screenshot({ path: OUT + `\\09-student-${panel}.jpeg`, fullPage: true, type: 'jpeg', quality: 78 });
    console.log('student ' + panel);
  }

  // Mobile student home
  await mpage.goto(BASE + '/login.php', { waitUntil: 'networkidle' });
  await mpage.fill('#student_id', '987654321');
  await mpage.fill('#password', 'password123');
  await Promise.all([mpage.waitForURL(/dashboard\.php/, { timeout: 15000 }), mpage.click('#submitBtn')]);
  await mpage.waitForLoadState('networkidle');
  await mpage.screenshot({ path: OUT + '\\10-mobile-student-home.jpeg', fullPage: true, type: 'jpeg', quality: 78 });
  console.log('mobile student home');

  await browser.close();
  console.log('DONE');
})().catch(e => { console.error(e); process.exit(1); });
