// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { stageDocumentation, verifyDocumentation } from '../../scripts/docs/package.mjs';

const build = new URL('../../build/', import.meta.url).pathname;
fs.mkdirSync(build, { recursive: true });
function fixture(t) {
  const root = fs.mkdtempSync(path.join(build, 'docs-package-'));
  t.after(() => fs.rmSync(root, {recursive:true,force:true}));
  const write = (file, text) => { fs.mkdirSync(path.dirname(path.join(root,file)),{recursive:true}); fs.writeFileSync(path.join(root,file),text); };
  const manifest = {version:1,locales:['en','pt-BR','pt-PT','es','ca'],package:['mcp/README.md','mcp/README.pt-BR.md','docs/en/guides/README.md','docs/pt-BR/guides/README.md','docs/en/guides/permissions.md','docs/assets/icon.png']};
  write('docs/documentation.json',JSON.stringify(manifest));
  write('mcp/NOTICE','Original attribution');
  write('mcp/README.md','# Package\n\n[English](../docs/en/guides/README.md) · [Português (Brasil)](README.pt-BR.md)\n\n[Legal](NOTICE)\n');
  write('mcp/README.pt-BR.md','# Pacote\n\n[Português (Brasil)](../docs/pt-BR/guides/README.md) · [English](README.md)\n');
  write('docs/en/guides/README.md','# Guides\n\n[Permissions][access]\n\n[access]: permissions.md#café "Access"\n\n<a href="../../../mcp/NOTICE">Legal &amp; attribution</a>\n\n![Icon](../../assets/icon.png)\n');
  write('docs/pt-BR/guides/README.md','# Guias\n\n[Permissions (English)](../../en/guides/permissions.md#café)\n');
  write('docs/en/guides/permissions.md','# Café\n\nLiteral `../docs/` prose.\n\n<!-- SPDX-' + 'License-Identifier: AGPL-3.0-or-later -->\n<!-- translation-source: docs/en/guides/permissions.md -->\n');
  write('docs/assets/icon.png',Buffer.from([0,1,2,3,255]));
  const stage=path.join(root,'mcp/build/stage'); fs.mkdirSync(path.join(stage,'mcp'),{recursive:true});
  fs.copyFileSync(path.join(root,'mcp/NOTICE'),path.join(stage,'mcp/NOTICE'));
  return {root,stage,write,manifest,save:()=>write('docs/documentation.json',JSON.stringify(manifest))};
}

test('single-source staging rewrites destinations only and verifies offline locale closure',t=>{
 const f=fixture(t);const before=fs.readFileSync(path.join(f.root,'mcp/README.md'),'utf8');
 stageDocumentation(f.root,f.stage);
 assert.equal(fs.readFileSync(path.join(f.root,'mcp/README.md'),'utf8'),before);
 assert.match(fs.readFileSync(path.join(f.stage,'mcp/README.md'),'utf8'),/\]\(docs\/en\/guides\/README.md\)/);
 const guide=fs.readFileSync(path.join(f.stage,'mcp/docs/en/guides/README.md'),'utf8');
 assert.match(guide,/\[access\]: permissions.md#café "Access"/);
 assert.match(guide,/<a href="..\/..\/..\/NOTICE">Legal &amp; attribution<\/a>/);
 assert.match(fs.readFileSync(path.join(f.stage,'mcp/docs/en/guides/permissions.md'),'utf8'),/Literal `..\/docs\/` prose/);
 assert.equal(verifyDocumentation(f.root,f.stage),6);
});

test('extra, missing and stale-but-link-valid documents are all rejected',t=>{
 const f=fixture(t);stageDocumentation(f.root,f.stage);
 const page=path.join(f.stage,'mcp/docs/en/guides/permissions.md');
 const original=fs.readFileSync(page);
 fs.writeFileSync(page,'# Café\n\nOld but link-valid content\n');
 assert.throws(()=>verifyDocumentation(f.root,f.stage),/content|bytes|stale/i);
 fs.unlinkSync(page);assert.throws(()=>verifyDocumentation(f.root,f.stage),/missing|inventory/i);
 fs.writeFileSync(page,original);
 fs.writeFileSync(path.join(f.stage,'mcp/docs/extra.md'),'# Extra\n');
 assert.throws(()=>verifyDocumentation(f.root,f.stage),/inventory|extra/i);
});

test('omitted linked sources and broken fragments fail staging without silent inclusion',t=>{
 const f=fixture(t);f.manifest.package=f.manifest.package.filter(x=>!x.endsWith('permissions.md'));f.save();
 assert.throws(()=>stageDocumentation(f.root,f.stage),/allowlist|destination/i);
 f.manifest.package.push('docs/en/guides/permissions.md');f.save();f.write('docs/en/guides/permissions.md','# Different heading\n');
 assert.throws(()=>stageDocumentation(f.root,f.stage),/fragment/i);
});

test('assets and legal bytes cannot change while retaining valid links',t=>{
 const f=fixture(t);stageDocumentation(f.root,f.stage);
 fs.writeFileSync(path.join(f.stage,'mcp/docs/assets/icon.png'),'wrong');
 assert.throws(()=>verifyDocumentation(f.root,f.stage),/bytes|content/i);
 fs.copyFileSync(path.join(f.root,'docs/assets/icon.png'),path.join(f.stage,'mcp/docs/assets/icon.png'));
 fs.writeFileSync(path.join(f.stage,'mcp/NOTICE'),'wrong');
 assert.throws(()=>verifyDocumentation(f.root,f.stage),/bytes|content/i);
});

test('archive traversal via symlinks is rejected before following the extracted tree',t=>{
 const f=fixture(t);stageDocumentation(f.root,f.stage);
 fs.symlinkSync('/tmp',path.join(f.stage,'mcp/docs/escape'));
 assert.throws(()=>verifyDocumentation(f.root,f.stage),/symlink/i);
});

test('partial additional locales use explicit English fallbacks without bundling developer docs',t=>{
 const f=fixture(t);
 for(const locale of ['es','ca','pt-PT']) {
  const file=`docs/${locale}/guides/README.md`;f.manifest.package.push(file);
  f.write(file,'# Guides\n\n[Permissions (English)](../../en/guides/permissions.md#café)\n');
 }
 f.save();stageDocumentation(f.root,f.stage);
 assert.equal(verifyDocumentation(f.root,f.stage),9);
 assert(!fs.existsSync(path.join(f.stage,'mcp/docs/en/development')));
});
