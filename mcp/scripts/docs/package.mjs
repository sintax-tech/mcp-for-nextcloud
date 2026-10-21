// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { readManifest, inside } from './sources.mjs';
import { parseMarkdown } from './markdown.mjs';
import { localTarget, validateLinks } from './repository.mjs';

function legalSources(root) {
  const files = ['mcp/LICENSE', 'mcp/NOTICE', 'mcp/THIRD-PARTY-NOTICES.md'].filter(file => fs.existsSync(path.join(root, file)));
  const directory = path.join(root, 'mcp/LICENSES');
  if (fs.existsSync(directory)) {
    for (const name of fs.readdirSync(directory)) if (fs.statSync(inside(root, `mcp/LICENSES/${name}`)).isFile()) files.push(`mcp/LICENSES/${name}`);
  }
  return files;
}
function packageInputs(root) {
  const manifest = readManifest(root);
  const mapping = new Map(manifest.package.map(file => [file, file.startsWith('docs/') ? `mcp/${file}` : file]));
  const legal = legalSources(root);
  for (const file of legal) mapping.set(file, file);
  return { manifest, mapping, legal };
}
function transformed(root, source, mapping) {
  const bytes = fs.readFileSync(inside(root, source));
  if (!source.endsWith('.md')) return bytes;
  let content = bytes.toString('utf8');
  const edits = [];
  for (const link of parseMarkdown(content).links) {
    const target = localTarget(root, source, link.url);
    if (!target) continue;
    const destination = mapping.get(target.file);
    if (!destination) throw new Error(`${source}:${link.line}: no allowlisted package destination for ${target.file}`);
    const relative = path.posix.relative(path.posix.dirname(mapping.get(source)), destination);
    let url = link.url.startsWith('#') ? link.url : relative.split('/').map(encodeURIComponent).join('/') + target.suffix;
    if (url === link.url) continue;
    if (!link.span) throw new Error(`${source}: unsupported local link transformation`);
    if (link.html) url = url.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll("'", '&#39;');
    edits.push({ ...link.span, url });
  }
  for (const edit of edits.sort((a,b) => b.start - a.start)) content = content.slice(0,edit.start)+edit.url+content.slice(edit.end);
  return Buffer.from(content);
}
function expectedDocumentation(root) {
  const { manifest, mapping, legal } = packageInputs(root);
  validateLinks(root, manifest.package.filter(file => file.endsWith('.md')));
  const expected = new Map(manifest.package.map(source => [mapping.get(source), transformed(root, source, mapping)]));
  return { expected, legal };
}
export function stageDocumentation(root, stage) {
  const { expected } = expectedDocumentation(root);
  for (const [file, bytes] of expected) {
    const destination = path.join(stage, file);
    fs.mkdirSync(path.dirname(destination), { recursive: true });
    fs.writeFileSync(destination, bytes);
  }
  return expected.size;
}
function inventory(extracted, expected) {
  const files = [];
  const walk = directory => {
    for (const entry of fs.readdirSync(path.join(extracted, directory), { withFileTypes: true })) {
      const file = `${directory}/${entry.name}`;
      if (entry.isSymbolicLink()) throw new Error(`Extracted documentation symlink: ${file}`);
      if (entry.isDirectory()) {
        if (![...expected.keys()].some(x => x.startsWith(file+'/'))) throw new Error(`Extra documentation directory in inventory: ${file}`);
        walk(file);
      } else if (entry.isFile()) files.push(file);
      else throw new Error(`Unsupported extracted documentation entry: ${file}`);
    }
  };
  if (!fs.existsSync(path.join(extracted,'mcp/docs'))) throw new Error('Missing package documentation inventory');
  if (fs.lstatSync(path.join(extracted,'mcp/docs')).isSymbolicLink()) throw new Error('Extracted documentation symlink: mcp/docs');
  walk('mcp/docs');
  for (const name of fs.readdirSync(path.join(extracted,'mcp'))) {
    if (!/^README(?:\.[^.]+)?\.md$/.test(name)) continue;
    const file = `mcp/${name}`;
    if (!fs.lstatSync(path.join(extracted,file)).isFile()) throw new Error(`Package README is not a regular file: ${file}`);
    files.push(file);
  }
  return files.sort();
}
export function verifyDocumentation(root, extracted) {
  const { expected, legal } = expectedDocumentation(root);
  const actual = inventory(extracted, expected);
  if (JSON.stringify(actual) !== JSON.stringify([...expected.keys()].sort())) throw new Error('Package documentation inventory differs from the explicit allowlist (missing or extra files)');
  for (const [file, bytes] of expected) {
    if (!fs.readFileSync(inside(extracted,file)).equals(bytes)) throw new Error(`Package content bytes differ from canonical sources: ${file}`);
  }
  for (const file of legal) {
    if (!fs.readFileSync(inside(extracted,file)).equals(fs.readFileSync(inside(root,file)))) throw new Error(`Package legal content bytes differ: ${file}`);
  }
  validateLinks(extracted,[...actual,...legal].filter(file => file.endsWith('.md')));
  return expected.size;
}
