import { test, expect } from 'playwright/test';
import { fileURLToPath } from 'node:url';

test('real GSAP press/dock interactions do not emit shorthand reset warnings', async ({ page }) => {
    const warnings = [];
    page.on('console', message => {
        if (message.text().includes('not eligible for reset')) warnings.push(message.text());
    });
    await page.setContent('<style>button {margin:20px;padding:20px} nav {display:flex}</style><nav class="sidebar-nav"><button class="nav-link">Library</button><button class="nav-link">Projects</button></nav><button class="button-primary"><span>Build prompt</span></button>');
    await page.addScriptTag({ path: fileURLToPath(new URL('../../public/js/vendor/gsap.min.js', import.meta.url)) });
    await page.addScriptTag({ path: fileURLToPath(new URL('../../public/js/site-motion.js', import.meta.url)) });
    expect(await page.evaluate(() => matchMedia('(hover: hover) and (pointer: fine)').matches)).toBe(true);
    const button = page.locator('.button-primary');
    await button.hover();
    await page.mouse.down();
    await expect.poll(() => button.evaluate(e => Number(window.gsap.getProperty(e, 'scaleX')))).toBeLessThan(1);
    await page.mouse.up();
    await page.mouse.move(800, 400);
    await expect.poll(() => button.evaluate(e => Number(window.gsap.getProperty(e, 'scaleX')))).toBeCloseTo(1, 3);
    await expect.poll(() => button.evaluate(e => Number(window.gsap.getProperty(e, 'scaleY')))).toBeCloseTo(1, 3);
    await page.locator('.nav-link').first().hover();
    await page.mouse.move(800, 400);
    expect(warnings).toEqual([]);
});
