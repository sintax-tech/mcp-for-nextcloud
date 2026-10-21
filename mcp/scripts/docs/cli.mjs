// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { checkRepository } from './repository.mjs';
import { translationStates } from './translations.mjs';
import { stageDocumentation, verifyDocumentation } from './package.mjs';
import { inside } from './sources.mjs';

const root = fileURLToPath(new URL('../../../', import.meta.url));
function report(states) {
  for (const state of states) console.log(`${state.state}: ${state.file}${state.noticeRequired ? ' — visible review notice required' : ''}`);
  const totals = states.reduce((counts, state) => ({ ...counts, [state.state]: (counts[state.state] ?? 0) + 1 }), {});
  console.log(`Translation states: ${JSON.stringify(totals)}. Missing/review-required are advisory; invalid provenance fails.`);
}
try {
  const [command, ...args] = process.argv.slice(2);
  const internal = ['stage', 'verify'].includes(command);
  if (args.length !== (internal ? 1 : 0)) throw new Error('Unsupported arguments: documentation commands have fixed scopes');
  if (command === 'check') {
    const result = checkRepository(root);
    console.log(`Documentation valid: ${result.files} Markdown files; ${result.manifest.package.length} package sources.`);
  } else if (command === 'translations') {
    report(translationStates(root));
  } else if (command === 'test') {
    if (process.env.NODE_TEST_CONTEXT) throw new Error('Cannot run documentation gate inside a nested Node test context');
    const directory = path.join(root, 'mcp/tests/documentation');
    const tests = fs.readdirSync(directory).filter(file => file.endsWith('.test.mjs')).sort().map(file => path.join(directory, file));
    if (!tests.length) throw new Error('No documentation tests discovered');
    const result = spawnSync(process.execPath, ['--test', ...tests], { cwd: root, stdio: 'inherit' });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(`Documentation tests failed (${result.status ?? result.signal})`);
  } else if (internal) {
    const directory = inside(root, args[0]);
    const relative = path.relative(path.join(root, 'mcp/build'), directory);
    if (!relative || relative.startsWith('..') || path.isAbsolute(relative)) throw new Error('Package documentation staging/verification requires a private directory under mcp/build');
    const count = command === 'stage' ? stageDocumentation(root, directory) : verifyDocumentation(root, directory);
    console.log(`Documentation ${command}: ${count} sources.`);
  } else {
    throw new Error('Usage: node scripts/docs/cli.mjs check|translations|test (package script owns stage/verify)');
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
