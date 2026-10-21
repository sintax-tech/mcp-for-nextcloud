// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { parseMarkdown } from '../../scripts/docs/markdown.mjs';
import { checkRepository } from '../../scripts/docs/repository.mjs';
import { discoverMarkdown } from '../../scripts/docs/sources.mjs';
import { translationStates } from '../../scripts/docs/translations.mjs';

const build = new URL('../../build/', import.meta.url);
fs.mkdirSync(build, { recursive: true });
function fixture(t) {
  const root = fs.mkdtempSync(path.join(build.pathname, 'docs-test-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  const write = (file, value) => { fs.mkdirSync(path.dirname(path.join(root, file)), { recursive: true }); fs.writeFileSync(path.join(root, file), value); };
  const git = (...args) => execFileSync('git', args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  git('init', '-q'); git('config', 'user.email', 'docs-test@example.invalid'); git('config', 'user.name', 'Documentation tests');
  write('.gitignore', 'mcp/build/\n');
  write('README.md', '# Overview\n');
  write('mcp/README.md', '# Package\n\n[Guides](../docs/en/guides/README.md)\n');
  write('docs/en/guides/README.md', '# Guides\n');
  const manifest = { version: 1, locales: ['en', 'pt-BR', 'es'], package: ['mcp/README.md', 'docs/en/guides/README.md'] };
  const saveManifest = () => write('docs/documentation.json', JSON.stringify(manifest));
  saveManifest(); git('add', '.'); git('commit', '-qm', 'Canonical source');
  const sha = git('rev-parse', 'HEAD');
  const translation = (review = 'pending', revision = sha, source = 'docs/en/guides/README.md') => {
    write('docs/pt-BR/guides/README.md', `<!-- translation-source: ${source} -->\n<!-- translation-source-revision: ${revision} -->\n<!-- translation-review: ${review} -->\n\n# Guias\n\n> **Revisão pendente:** consulte [English](../../en/guides/README.md).\n`);
  };
  return { root, write, git, sha, translation, manifest, saveManifest };
}

test('GFM headings, reference links and HTML targets are parsed; code is inert', () => {
  const result = parseMarkdown('# Café\n\n## Café\n\n[Read][topic]\n\n[topic]: guide.md#café\n\n<a id="stable" href="guide.md">Read</a><img src="image.png">\n\n```md\n[ignored](missing.md)\n```\n');
  assert.deepEqual([...result.anchors], ['café', 'café-1', 'stable']);
  assert.deepEqual(result.links.map(x => x.url), ['guide.md#café', 'guide.md', 'image.png']);
});

test('links validate accented duplicate fragments, encoded names and repository roots', t => {
  const f = fixture(t);
  f.write('docs/en/guides/a b.md', '# Café\n\n## Café\n');
  f.write('README.md', '# Overview\n\n[Read](/docs/en/guides/a%20b.md#caf%C3%A9-1)\n');
  assert.doesNotThrow(() => checkRepository(f.root));
});

for (const [target, pattern] of [['missing.md', /missing/i], ['README.md#absent', /fragment/i], ['../../escape.md', /escape/i], ['%2e%2e/%2e%2e/escape.md', /escape/i], ['bad%ZZ.md', /encoding/i]]) {
  test(`reject local target ${target}`, t => {
    const f = fixture(t); f.write('README.md', `# Overview\n\n[Broken](${target})\n`);
    assert.throws(() => checkRepository(f.root), pattern);
  });
}

test('HTML and image references are checked, including explicit unsupported srcset', t => {
  const f = fixture(t); f.write('README.md', '# Overview\n\n<img src="missing.png">\n');
  assert.throws(() => checkRepository(f.root), /missing/);
  f.write('README.md', '# Overview\n\n<img srcset="missing.png 1x">\n');
  assert.throws(() => checkRepository(f.root), /unsupported/i);
});

test('symlink escapes cannot masquerade as local documents', t => {
  const f = fixture(t); fs.symlinkSync('/etc/hosts', path.join(f.root, 'outside.md'));
  f.write('README.md', '# Overview\n\n[Escape](outside.md)\n');
  assert.throws(() => checkRepository(f.root), /escape|symlink/i);
});

test('new non-ignored documents are checked and ignored generated fixtures are not', t => {
  const f = fixture(t); f.write('new.md', '# New\n\n[Broken](missing.md)\n');
  f.write('mcp/build/invalid.md', '[Fixture](absent.md)');
  assert(discoverMarkdown(f.root).includes('new.md'));
  assert(!discoverMarkdown(f.root).includes('mcp/build/invalid.md'));
  assert.throws(() => checkRepository(f.root), /missing/);
});

test('malformed references and empty links fail structural validation', t => {
  const f = fixture(t);
  for (const text of ['[undefined][missing]', '[empty]()']) {
    f.write('README.md', '# Overview\n\n'+text+'\n');
    assert.throws(() => checkRepository(f.root), /MD052|MD042|empty/i);
  }
});

test('manifest rejects duplicates, forbidden development paths and unknown locales', t => {
  const f = fixture(t);
  f.manifest.package.push('mcp/README.md'); f.saveManifest();
  assert.throws(() => checkRepository(f.root), /duplicate/i);
  f.manifest.package.pop(); f.manifest.package.push('docs/en/development/setup.md'); f.saveManifest();
  assert.throws(() => checkRepository(f.root), /operational|allowed/i);
  f.manifest.package.pop(); f.manifest.locales.push('pt_BR'); f.saveManifest();
  assert.throws(() => checkRepository(f.root), /locale/i);
});

test('missing and pending translations are valid advisory states, never silently current', t => {
  const f = fixture(t);
  assert(translationStates(f.root).some(x => x.state === 'missing'));
  f.translation();
  assert.equal(translationStates(f.root).find(x => x.file === 'docs/pt-BR/guides/README.md').state, 'review-required');
  f.write('docs/en/guides/README.md', '# Guides\n\n[Português (Brasil)](../../pt-BR/guides/README.md)\n');
  assert.doesNotThrow(() => checkRepository(f.root));
});

test('source bytes, not latest commit ID, determine freshness', t => {
  const f = fixture(t); f.translation('reviewed');
  f.write('unrelated.txt', 'unrelated'); f.git('add', '.'); f.git('commit', '-qm', 'Unrelated change');
  assert.equal(translationStates(f.root).find(x => x.file === 'docs/pt-BR/guides/README.md').state, 'current');
  f.write('docs/en/guides/README.md', '# Changed source\n\n[Português (Brasil)](../../pt-BR/guides/README.md)\n');
  assert.equal(translationStates(f.root).find(x => x.file === 'docs/pt-BR/guides/README.md').state, 'review-required');
  assert.doesNotThrow(() => checkRepository(f.root));
});

test('metadata requires a real ancestor, canonical mapping and source at both revisions', t => {
  const f = fixture(t);
  for (const [sha, source] of [['f'.repeat(40), 'docs/en/guides/README.md'], [f.sha, '../../etc/passwd'], [f.sha, 'README.md']]) {
    f.translation('pending', sha, source);
    assert.throws(() => translationStates(f.root), /revision|source|mapping/i);
  }
  f.git('checkout', '-qb', 'abandoned'); f.write('other.txt', 'branch'); f.git('add', '.'); f.git('commit', '-qm', 'Abandoned source');
  const abandoned = f.git('rev-parse', 'HEAD'); f.git('checkout', '--detach', f.sha);
  f.translation('pending', abandoned);
  assert.throws(() => translationStates(f.root), /ancestor/i);
  f.translation(); fs.unlinkSync(path.join(f.root, 'docs/en/guides/README.md'));
  assert.throws(() => translationStates(f.root), /source|missing/i);
});

test('incomplete and invalid review metadata fail rather than becoming warnings', t => {
  const f = fixture(t);
  f.write('docs/pt-BR/guides/README.md', '# Guias\n');
  assert.throws(() => translationStates(f.root), /metadata/i);
  f.translation('automatically-reviewed');
  assert.throws(() => translationStates(f.root), /review/i);
});

test('shallow history fails explicitly; ordinary merges preserve source provenance', t => {
  const f = fixture(t); f.translation(); f.git('add', '.'); f.git('commit', '-qm', 'Translation');
  f.git('checkout', '-qb', 'topic'); f.write('topic.txt', 'x'); f.git('add', '.'); f.git('commit', '-qm', 'Topic');
  f.git('checkout', '--detach', f.sha); f.git('merge', '--no-ff', 'topic', '-m', 'Merge preserves source');
  assert.doesNotThrow(() => translationStates(f.root));
  const shallow = path.join(f.root, 'mcp/build/shallow'); fs.mkdirSync(path.dirname(shallow), {recursive:true});
  execFileSync('git', ['clone', '-q', '--depth=1', 'file://'+f.root, shallow], {stdio:'pipe'});
  assert.throws(() => translationStates(shallow), /history|shallow/i);
});


test('equivalent-page navigation must point to the actual counterpart', t => {
  const f = fixture(t); f.translation();
  assert.throws(() => checkRepository(f.root), /navigation|counterpart/i);
  f.write('docs/en/guides/README.md', '# Guides\n\n[Português (Brasil)](../../pt-BR/guides/README.md)\n');
  assert.doesNotThrow(() => checkRepository(f.root));
});

test('pending locale drafts require a visible notice and remain review-required', t => {
  const f = fixture(t);
  f.manifest.locales = ['en', 'pt-BR', 'pt-PT', 'es', 'ca']; f.saveManifest();
  const notices = { 'pt-BR': 'Revisão pendente', 'pt-PT': 'Revisão humana pendente', es: 'Traducción pendiente de revisión humana', ca: 'Traducció pendent de revisió humana' };
  for (const [locale, notice] of Object.entries(notices)) {
    const file = `docs/${locale}/guides/README.md`;
    f.write(file, `<!-- translation-source: docs/en/guides/README.md -->\n<!-- translation-source-revision: ${f.sha} -->\n<!-- translation-review: pending -->\n\n# Guides\n\n> **${notice}:** [English](../../en/guides/README.md).\n`);
  }
  const states = translationStates(f.root).filter(x => x.state !== 'missing');
  assert.equal(states.length, 4);
  assert(states.every(x => x.state === 'review-required' && !x.noticeRequired));
  f.write('docs/en/guides/README.md', '# Guides\n\n' + Object.keys(notices).map(locale => `[${locale}](../../${locale}/guides/README.md)`).join(' · ') + '\n');
  assert.doesNotThrow(() => checkRepository(f.root));
});

test('pending drafts cannot hide their human-review requirement', t => {
  const f = fixture(t); f.translation();
  const file = 'docs/pt-BR/guides/README.md';
  const original = fs.readFileSync(path.join(f.root, file), 'utf8');
  for (const replacement of ['', '<!-- Revisão pendente -->', '`Revisão pendente`', '```md\nRevisão pendente\n```']) {
    f.write(file, original.replace('**Revisão pendente:**', replacement));
    assert.throws(() => translationStates(f.root), /visible.*review|review.*notice/i);
  }
});
