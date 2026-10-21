// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { parseMarkdown } from './markdown.mjs';
import { discoverMarkdown, git, inside, readManifest } from './sources.mjs';

export function canonicalPath(file) {
  return file.replace(/^docs\/(pt-BR|pt-PT|es|ca)\//, 'docs/en/').replace(/README\.(pt-BR|pt-PT|es|ca)\.md$/, 'README.md');
}
function translatedPath(source, locale) {
  return source.startsWith('docs/en/') ? source.replace('docs/en/', `docs/${locale}/`) : source.replace(/README\.md$/, `README.${locale}.md`);
}
export function translationStates(root, files = discoverMarkdown(root), manifest = readManifest(root)) {
  if (git(root, 'rev-parse', '--is-shallow-repository') !== 'false') throw new Error('Incomplete Git history: shallow checkout; provide full source history');
  const results = [];
  const translations = files.filter(file => canonicalPath(file) !== file);
  for (const file of translations) {
    const source = fs.readFileSync(inside(root, file), 'utf8');
    const parsed = parseMarkdown(source);
    const fields = new Map();
    for (const comment of parsed.metadata) {
      if (!comment.startsWith('translation-')) continue;
      const match = /^(translation-source|translation-source-revision|translation-review):\s*(\S+)$/.exec(comment);
      if (!match || fields.has(match[1])) throw new Error(`${file}: invalid/duplicate translation metadata`);
      fields.set(match[1], match[2]);
    }
    const canonical = fields.get('translation-source');
    const revision = fields.get('translation-source-revision');
    const review = fields.get('translation-review');
    if (fields.size !== 3) throw new Error(`${file}: missing translation metadata`);
    if (canonical !== canonicalPath(file)) throw new Error(`${file}: incorrect canonical source mapping`);
    if (!/^[0-9a-f]{40}$/.test(revision)) throw new Error(`${file}: invalid source revision`);
    if (!['pending', 'reviewed'].includes(review)) throw new Error(`${file}: invalid translation review status`);
    inside(root, canonical);
    let previous;
    try {
      git(root, 'cat-file', '-e', `${revision}^{commit}`);
      git(root, 'merge-base', '--is-ancestor', revision, 'HEAD');
      // Preserve final newlines: Git output trimming would create false stale states.
      previous = git(root, 'rev-parse', `${revision}:${canonical}`);
    } catch { throw new Error(`${file}: source revision/path unavailable or revision is not an ancestor of HEAD`); }
    const currentBlob = git(root, 'hash-object', '--no-filters', '--', canonical);
    const state = review === 'reviewed' && previous === currentBlob ? 'current' : 'review-required';
    const notice = /(?:Revisão (?:humana )?pendente|Outdated translation|Translation review required|Traducción pendiente|Traducció pendent|Revisió pendent)/i.test(parsed.visibleProse)
      && parsed.links.some(link => link.url.includes(path.posix.basename(canonical)) && /en\/|README\.md/.test(link.url));
    if (review === 'pending' && !notice) throw new Error(`${file}: pending translation requires a visible human-review notice and English source link`);
    results.push({ file, source: canonical, revision, state, noticeRequired: state !== 'current' && !notice });
  }
  const canonical = files.filter(file => file === 'README.md' || file === 'mcp/README.md' || (file.startsWith('docs/en/') && !file.startsWith('docs/en/development/')));
  for (const file of canonical) for (const locale of manifest.locales.filter(x => x !== 'en')) {
    const target = translatedPath(file, locale);
    if (!files.includes(target)) results.push({ file: target, source: file, state: 'missing', noticeRequired: false });
  }
  return results;
}
