// Render mockups/<slug>.<W>x<H>.html to PNG at exactly WxH (viewport screenshot, scale 1).
import { chromium } from 'playwright';
import { readdirSync, writeFileSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'mockups');
const filter = process.argv[2] || '';
const files = readdirSync(root).filter(f => /\.\d+x\d+\.html$/.test(f) && f.includes(filter));
const browser = await chromium.launch();
const log = [];
for (const f of files) {
  const [, w, h] = f.match(/\.(\d+)x(\d+)\.html$/).map(Number);
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const errs = [];
  page.on('requestfailed', r => errs.push(r.url()));
  try {
    await page.goto('file://' + join(root, f), { waitUntil: 'networkidle', timeout: 60000 });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(300);
    await page.screenshot({ path: join(root, f.replace(/\.html$/, '.png')), fullPage: false });
    log.push(`OK   ${f}${errs.length ? '  WARN ' + errs.join(' ') : ''}`);
  } catch (e) { log.push(`FAIL ${f}: ${e.message.split('\n')[0]}`); }
  await ctx.close();
}
await browser.close();
console.log(log.join('\n'));
writeFileSync(join(root, 'RENDER-LOG.txt'), log.join('\n') + '\n');
