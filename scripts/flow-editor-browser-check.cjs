/**
 * Prueba en navegador del editor de flujos (6.38.0).
 *
 * Uso: ATORA_DUMP_EDITOR=/tmp/editor.html vendor/bin/phpunit -c phpunit.integration.xml --filter test_editor_renders \
 *        tests/integration/AutomationEditorViewTest.php && node scripts/flow-editor-browser-check.cjs /tmp/editor.html [captura.png]
 *
 * Abre la pantalla real del editor (HTML generado por WordPress) en Chromium y comprueba: lienzo con ramas,
 * agregar una condición y una acción, que el JSON que se guarda refleje los cambios, sin errores de consola y
 * sin desborde horizontal a 375 px.
 */
const { chromium } = require('playwright');

(async () => {
	const file = process.argv[2];
	const shot = process.argv[3];
	const browser = await chromium.launch();
	const errors = [];
	const fail = (msg) => { console.error('FALLO: ' + msg); process.exitCode = 1; };
	for (const width of [1280, 375]) {
		const page = await browser.newPage({ viewport: { width, height: 900 } });
		page.on('pageerror', (e) => errors.push(e.message));
		page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
		await page.goto('file://' + file);

		// La plantilla «Estudiante en riesgo» trae una condición con dos ramas.
		const branches = await page.locator('#atora-flow-canvas .atora-flow-branch').count();
		if (branches !== 2) { fail(`ramas en el lienzo: ${branches}`); }
		const nodes = await page.locator('#atora-flow-canvas .atora-flow-node').count();
		if (nodes < 7) { fail(`nodos en el lienzo: ${nodes}`); }

		if (width === 1280) {
			const before = JSON.parse(await page.inputValue('#atora-flow-definition')).steps.length;
			await page.click('[data-add="condition"]');
			await page.click('[data-add="action"]');
			const def = JSON.parse(await page.inputValue('#atora-flow-definition'));
			if (def.steps.length !== before + 2) { fail(`pasos tras agregar: ${def.steps.length}`); }
			const cond = def.steps.find((s) => s.type === 'condition' && s.id.startsWith('cond'));
			if (!cond) { fail('no se agregó la condición'); }

			// Regla en la condición nueva y su rama «Sí» hacia la acción nueva.
			const card = page.locator('.atora-flow-step', { has: page.locator(`legend code:text-is("${cond.id}")`) });
			await card.getByRole('button', { name: /Agregar regla/ }).click();
			await card.locator('.atora-flow-rule select').first().selectOption('grade');
			await card.locator('.atora-flow-rule select').nth(1).selectOption('less_than');
			await card.locator('.atora-flow-rule input').fill('60');
			await card.locator('.atora-flow-rule input').blur();
			const action = def.steps[def.steps.length - 1].id;
			await card.locator('label:has-text("Sí") select').selectOption(action);
			const after = JSON.parse(await page.inputValue('#atora-flow-definition'));
			const c2 = after.steps.find((s) => s.id === cond.id);
			if (!c2.rules.rules.length || c2.rules.rules[0].field !== 'grade' || c2.rules.rules[0].value !== '60') { fail('la regla no llegó al JSON: ' + JSON.stringify(c2.rules)); }
			if (c2.yes !== action) { fail('la rama sí no llegó al JSON'); }
			const branchesNow = await page.locator('#atora-flow-canvas .atora-flow-branch').count();
			if (branchesNow < 4) { fail(`el lienzo no dibujó la condición nueva (${branchesNow} ramas)`); }

			// Quitar un paso limpia las referencias.
			const removeCard = page.locator('.atora-flow-step', { has: page.locator(`legend code:text-is("${action}")`) });
			await removeCard.getByRole('button', { name: 'Quitar' }).click();
			const removed = JSON.parse(await page.inputValue('#atora-flow-definition'));
			if (removed.steps.some((s) => s.yes === action || s.next === action)) { fail('quedó una referencia a un paso quitado'); }
		}

		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		if (width === 375 && overflow > 0) { fail(`desborde horizontal a 375 px: ${overflow}px`); }
		if (shot) { await page.screenshot({ path: shot.replace('.png', `-${width}.png`), fullPage: true }); }
		await page.close();
	}
	if (errors.length) { fail('errores de consola: ' + errors.join(' | ')); }
	await browser.close();
	if (!process.exitCode) { console.log('OK: editor de flujos en navegador'); }
})();
