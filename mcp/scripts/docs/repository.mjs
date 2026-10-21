// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { inside, readManifest, discoverMarkdown } from './sources.mjs';
import { parseMarkdown, structuralErrors } from './markdown.mjs';
import { translationStates } from './translations.mjs';


export function localTarget(root, file, url) {
  if (/^(?:https?:|mailto:|tel:|\/\/)/i.test(url)) return null;
  if (/^[a-z][a-z0-9+.-]*:/i.test(url)) throw new Error(`Unsupported link scheme: ${url}`);
  let decoded;
  const [location, ...fragments] = url.split('#');
  try { decoded = decodeURIComponent(location.split('?')[0]); } catch { throw new Error(`Invalid URL encoding: ${url}`); }
  if (decoded.includes('\\') || decoded.includes('\0')) throw new Error(`Path escape: ${url}`);
  const target = decoded ? (decoded.startsWith('/') ? decoded.slice(1) : path.posix.join(path.posix.dirname(file), decoded)) : file;
  const absolute = inside(root, target);
  let fragment;
  try { fragment = decodeURIComponent(fragments.join('#')); } catch { throw new Error(`Invalid fragment encoding: ${url}`); }
  return { file: path.relative(root, absolute).split(path.sep).join('/'), absolute, fragment, suffix: url.slice(location.split('?')[0].length) };
}

export function validateLinks(root, files) {
  const cache = new Map();
  const parsed = file => {
    if (!cache.has(file)) cache.set(file, parseMarkdown(fs.readFileSync(inside(root, file), 'utf8')));
    return cache.get(file);
  };
  const errors = [];
  for (const file of files) {
    try {
      const source = fs.readFileSync(inside(root, file), 'utf8');
      errors.push(...structuralErrors(source, file));
      for (const link of parsed(file).links) {
        try {
          const target = localTarget(root, file, link.url);
          if (target?.fragment && target.file.endsWith('.md') && !parsed(target.file).anchors.has(target.fragment)) throw new Error(`Missing fragment ${target.fragment} in ${target.file}`);
        } catch (error) { errors.push(`${file}:${link.line}: ${error.message}`); }
      }
    } catch (error) { errors.push(`${file}: ${error.message}`); }
  }
  if (errors.length) throw new Error(errors.join('\n'));
  return files.length;
}
export function checkRepository(root) {
  const manifest = readManifest(root);
  const files = [...new Set([...discoverMarkdown(root), ...manifest.package.filter(x => x.endsWith('.md'))])];
  validateLinks(root, files);
  const states = translationStates(root, files, manifest);
  for (const state of states.filter(item => item.state !== 'missing')) {
    for (const [from, to] of [[state.file, state.source], [state.source, state.file]]) {
      const document = parseMarkdown(fs.readFileSync(inside(root, from), 'utf8'));
      if (!document.links.some(link => localTarget(root, from, link.url)?.file === to)) {
        throw new Error(`${from}: missing equivalent-page navigation to ${to}`);
      }
    }
  }
  return { files: files.length, states, manifest };
}
