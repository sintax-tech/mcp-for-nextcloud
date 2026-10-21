// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const app = new URL('../../', import.meta.url).pathname;
fs.mkdirSync(path.join(app, 'build'), { recursive: true });
test('public CLI scopes reject extra arguments instead of silently filtering files', () => {
  for (const command of ['check', 'translations', 'test']) {
    const result = spawnSync(process.execPath, [path.join(app, 'scripts/docs/cli.mjs'), command, '--filter', 'none'], { encoding: 'utf8' });
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.match(result.stderr, /Unsupported arguments/);
  }
});
test('the test command rejects empty discovery and propagates a failing suite', t => {
  const root = fs.mkdtempSync(path.join(app, 'build/docs-cli-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  fs.mkdirSync(path.join(root, 'mcp/tests/documentation'), { recursive: true });
  fs.cpSync(path.join(app, 'scripts/docs'), path.join(root, 'mcp/scripts/docs'), { recursive: true });
  fs.symlinkSync(path.join(app, 'node_modules'), path.join(root, 'mcp/node_modules'));
  const env = { ...process.env };
  // This probe represents a standalone Composer invocation, not a nested Node runner.
  delete env.NODE_TEST_CONTEXT;
  const run = () => spawnSync(process.execPath, [path.join(root, 'mcp/scripts/docs/cli.mjs'), 'test'], { cwd: app, encoding: 'utf8', env });
  let result = run();
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stderr, /No documentation tests discovered/);
  fs.writeFileSync(path.join(root, 'mcp/tests/documentation/failing.test.mjs'), "import { test } from 'node:test'; test('visible failure', () => { throw new Error('Intentional failure'); });");
  result = run();
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stdout + result.stderr, /Intentional failure/);
});

test('an inherited Node runner context cannot produce a false green gate', () => {
  const result = spawnSync(process.execPath, [path.join(app, 'scripts/docs/cli.mjs'), 'test'], { encoding: 'utf8', env: { ...process.env, NODE_TEST_CONTEXT: 'child-v8' } });
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stderr, /nested|context/i);
});
