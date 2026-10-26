// Loads a .jsx component of the app for a behavior test: bundles it with Vite's own bundler (rolldown), keeping React and Headless UI as real imports,
// and returns the module. The bundle goes to node_modules/.cache (ignored by Git) so that Node resolves React and Headless UI from the project.
import { mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { rolldown } from 'rolldown';

const root = fileURLToPath(new URL('../../../', import.meta.url));

export async function loadJsx(path) {
    const cache = join(root, 'node_modules/.cache/zonno-tests');
    mkdirSync(cache, { recursive: true });
    const dir = mkdtempSync(join(cache, 'jsx-'));
    const file = join(dir, 'bundle.mjs');
    const bundle = await rolldown({
        input: join(root, path),
        platform: 'node',
        external: [/^react(\/|$)/, /^react-dom(\/|$)/, /^@headlessui\//],
        transform: { jsx: { runtime: 'automatic' } },
        resolve: { alias: { '@': join(root, 'resources/js') } },
    });

    await bundle.write({ file, format: 'esm' });

    return { module: await import(`${pathToFileURL(file).href}?t=${Date.now()}`), cleanup: () => rmSync(dir, { recursive: true, force: true }) };
}
