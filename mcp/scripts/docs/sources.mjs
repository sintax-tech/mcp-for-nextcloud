// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

export const languages = { en: 'English', 'pt-BR': 'Português (Brasil)', 'pt-PT': 'Português (Portugal)', es: 'Español', ca: 'Català' };
export function git(root, ...args) {
  return execFileSync('git', args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trimEnd();
}
export function inside(root, file) {
  const resolved = path.resolve(root, file);
  const relative = path.relative(path.resolve(root), resolved);
  if (relative === '..' || relative.startsWith('../') || path.isAbsolute(relative)) throw new Error(`Path escape: ${file}`);
  if (!fs.existsSync(resolved)) throw new Error(`Missing local file: ${file}`);
  const real = path.relative(fs.realpathSync(root), fs.realpathSync(resolved));
  if (real === '..' || real.startsWith('../') || path.isAbsolute(real)) throw new Error(`Symlink escape: ${file}`);
  return resolved;
}
export function discoverMarkdown(root) {
  return [...new Set(git(root, 'ls-files', '--cached', '--others', '--exclude-standard', '-z').split('\0'))]
    .filter(file => file.endsWith('.md')).sort();
}
export function readManifest(root) {
  const manifest = JSON.parse(fs.readFileSync(inside(root, 'docs/documentation.json'), 'utf8'));
  if (Object.keys(manifest).sort().join(',') !== 'locales,package,version' || manifest.version !== 1) throw new Error('Invalid documentation manifest schema');
  if (!Array.isArray(manifest.locales) || !manifest.locales.includes('en') || manifest.locales.some(x => !Object.hasOwn(languages, x)) || new Set(manifest.locales).size !== manifest.locales.length) throw new Error('Invalid or duplicate locale');
  if (!Array.isArray(manifest.package) || manifest.package.some(x => typeof x !== 'string')) throw new Error('Invalid package allowlist');
  if (new Set(manifest.package).size !== manifest.package.length) throw new Error('Duplicate package destination');
  for (const file of manifest.package) {
    const allowed = /^(?:mcp\/README(?:\.(pt-BR|pt-PT|es|ca))?\.md|docs\/(en|pt-BR|pt-PT|es|ca)\/(?:installation\.md|(?:guides|reference)\/[a-zA-Z0-9/.-]+\.md|assets\/[a-zA-Z0-9/._-]+)|docs\/assets\/[a-zA-Z0-9/._-]+)$/.exec(file);
    if (!allowed || file.split('/').some(x => x === '.' || x === '..') || file.includes('//')) throw new Error(`Not an allowed operational package path: ${file}`);
    const locale = allowed[1] ?? allowed[2];
    if (locale && !manifest.locales.includes(locale)) throw new Error(`Undeclared locale: ${file}`);
    if (!fs.statSync(inside(root, file)).isFile()) throw new Error(`Not a package file: ${file}`);
  }
  for (const required of ['mcp/README.md', 'docs/en/guides/README.md']) {
    if (!manifest.package.includes(required)) throw new Error(`Missing required package entrance: ${required}`);
  }
  for (const locale of manifest.locales.filter(x => x !== 'en')) {
    const app = `mcp/README.${locale}.md`;
    if (fs.existsSync(path.join(root, app)) && !manifest.package.includes(app)) throw new Error(`Missing localized package entrance: ${app}`);
  }
  return manifest;
}
