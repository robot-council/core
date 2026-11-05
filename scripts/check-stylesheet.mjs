// Fails when the committed dashboard stylesheet is not what its sources build to (#305).
//
// This replaces a shell one-liner that ran `mktemp` and `cmp` through whatever shell npm picked,
// which on Windows is cmd.exe and cannot parse the assignment. Nothing here names a shell: Node
// runs the Tailwind CLI directly, and the comparison is a byte comparison in-process.
//
// Exit codes, kept apart so a check that could not run is never read as a stale artifact:
//   0  the committed stylesheet is what a fresh build produces
//   1  it differs: run `npm run build` and commit resources/dist/dashboard.css
//   2  the check itself could not run, so nothing is known about the stylesheet

import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const committed = join(root, 'resources', 'dist', 'dashboard.css');
const input = join(root, 'resources', 'css', 'dashboard.css');

const fail = (code, message) => {
    console.error(message);
    process.exit(code);
};

let built;
const scratch = mkdtempSync(join(tmpdir(), 'robot-council-css-'));

try {
    // The CLI's own entry point, read from its package so no `.bin` shim or shell is involved
    const require = createRequire(import.meta.url);
    const manifest = require.resolve('@tailwindcss/cli/package.json');
    const entry = join(dirname(manifest), require(manifest).bin.tailwindcss);
    const output = join(scratch, 'dashboard.css');

    const run = spawnSync(process.execPath, [entry, '--input', input, '--output', output, '--minify'], { cwd: root, encoding: 'utf8' });

    if (run.error || run.status !== 0) {
        fail(2, `Could not rebuild ${input} to compare it with resources/dist/dashboard.css, so the stylesheet was not checked.\n${run.error?.message ?? run.stderr}`);
    }

    built = readFileSync(output);
} catch (error) {
    fail(2, `Could not run the stylesheet check, so resources/dist/dashboard.css was not checked: ${error.message}`);
} finally {
    rmSync(scratch, { recursive: true, force: true });
}

let stored;

try {
    stored = readFileSync(committed);
} catch (error) {
    fail(2, `Could not read resources/dist/dashboard.css, so it was not checked: ${error.message}`);
}

if (!built.equals(stored)) {
    fail(1, `resources/dist/dashboard.css does not match its sources (${stored.length} bytes committed, ${built.length} built). Run 'npm run build' and commit it.`);
}
