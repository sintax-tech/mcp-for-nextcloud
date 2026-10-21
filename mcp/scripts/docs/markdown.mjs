// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { fromMarkdown } from 'mdast-util-from-markdown';
import { gfmFromMarkdown } from 'mdast-util-gfm';
import { gfm } from 'micromark-extension-gfm';
import GithubSlugger from 'github-slugger';
import { parseFragment } from 'parse5';
import { lint } from 'markdownlint/sync';

function text(node) {
  return node.type === 'html' ? '' : node.value ?? node.alt ?? (node.children ?? []).map(text).join('');
}

function prose(node) {
  if (['html', 'code', 'inlineCode', 'image'].includes(node.type)) return '';
  return node.value ?? (node.children ?? []).map(prose).join('');
}

// Positions come from parsed nodes. Only destination bytes are replaced at staging.
function destinationSpan(source, node) {
  const start = node.position.start.offset;
  const raw = source.slice(start, node.position.end.offset);
  let at;
  if (node.type === 'definition') {
    at = /^ {0,3}\[(?:\\.|[^\]])*\]:\s*/.exec(raw)?.[0].length;
  } else {
    const lastChild = node.children?.at(-1)?.position.end.offset;
    at = raw.indexOf('](', lastChild ? lastChild - start : 0);
    if (at < 0) return null; // Autolinks are external; they need no transformation.
    at += 2;
    while (/\s/.test(raw[at] ?? '') && at < raw.length) at++;
  }
  if (at === undefined) throw new Error('Unsupported Markdown destination syntax');
  const angle = raw[at] === '<';
  if (angle) at++;
  let end = at;
  let depth = 0;
  for (; end < raw.length; end++) {
    const char = raw[end];
    if (char === '\\') { end++; continue; }
    if (angle) { if (char === '>') break; continue; }
    if (/\s/.test(char) || (char === ')' && depth === 0)) break;
    if (char === '(') depth++;
    if (char === ')') depth--;
  }
  return { start: start + at, end: start + end };
}

export function parseMarkdown(source) {
  const tree = fromMarkdown(source, { extensions: [gfm()], mdastExtensions: [gfmFromMarkdown()] });
  const anchors = new Set();
  const links = [];
  const slugger = new GithubSlugger();
  const metadata = [];
  const paragraphs = [];
  function visit(node) {
    if (node.type === 'paragraph') paragraphs.push(prose(node));
    if (node.type === 'heading') anchors.add(slugger.slug(text(node)));
    if (['link', 'image', 'definition'].includes(node.type)) {
      links.push({ url: node.url, span: destinationSpan(source, node), line: node.position.start.line, html: false });
    }
    if (node.type === 'html') {
      const fragment = parseFragment(node.value, { sourceCodeLocationInfo: true });
      function html(child) {
        if (child.nodeName === '#comment') metadata.push(child.data.trim());
        for (const attr of child.attrs ?? []) {
          if (['id', 'name'].includes(attr.name) && (attr.name === 'id' || child.tagName === 'a')) anchors.add(attr.value);
          if (['srcset', 'poster', 'action', 'data'].includes(attr.name)) throw new Error(`Unsupported HTML link attribute: ${attr.name}`);
          if (!['href', 'src'].includes(attr.name)) continue;
          if (!['a', 'img'].includes(child.tagName)) throw new Error(`Unsupported HTML link element: ${child.tagName}`);
          const loc = child.sourceCodeLocation?.attrs?.[attr.name];
          if (!loc) throw new Error('Missing HTML attribute location');
          const raw = node.value.slice(loc.startOffset, loc.endOffset);
          const prefix = /^[^=]+=\s*["']?/.exec(raw)?.[0];
          if (!prefix) throw new Error('Unsupported HTML attribute syntax');
          const quoted = /["']$/.test(prefix);
          links.push({ url: attr.value, html: true, line: node.position.start.line,
            span: { start: node.position.start.offset + loc.startOffset + prefix.length,
              end: node.position.start.offset + loc.endOffset - (quoted ? 1 : 0) } });
        }
        for (const nested of child.childNodes ?? []) html(nested);
      }
      html(fragment);
    }
    for (const child of node.children ?? []) visit(child);
  }
  visit(tree);
  return { anchors, links, metadata, visibleProse: paragraphs.join('\n') };
}

export function structuralErrors(source, file) {
  const result = lint({ strings: { [file]: source }, config: { default: false, MD011: true, MD042: true, MD052: true } });
  return result[file].map(error => `${file}:${error.lineNumber}: ${error.ruleNames[0]} ${error.errorDescription}`);
}
