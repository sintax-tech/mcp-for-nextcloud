// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { mkdir, mkdtemp, readdir, rename, rm, writeFile } from 'node:fs/promises'
import { basename, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const app = fileURLToPath(new URL('../', import.meta.url))
const entries = ['admin', 'personal']
const banner = `/*
 * Generated from src/entries; edit the authored modules, then run composer assets:build.
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */`

async function sources(root, directory = join(root, 'src')) {
    const found = []
    for (const entry of await readdir(directory, { withFileTypes: true })) {
        const path = join(directory, entry.name)
        if (entry.isSymbolicLink()) throw new Error(`Source symlink is not allowed: ${path}`)
        if (entry.isDirectory()) found.push(...await sources(root, path))
        else if (entry.name.endsWith('.js')) found.push(relative(root, path))
    }
    return found
}

function verifyGraph(metadata, inventory) {
    const inputs = metadata.inputs
    for (const source of inventory) {
        if (!Object.hasOwn(inputs, source)) throw new Error(`Unreachable production source: ${source}`)
    }
    const visited = new Set()
    const active = new Set()
    function visit(source) {
        if (active.has(source)) throw new Error(`Cyclic production import: ${source}`)
        if (visited.has(source)) return
        if (!inventory.includes(source)) throw new Error(`Non-app runtime dependency: ${source}`)
        active.add(source)
        for (const dependency of inputs[source].imports) {
            if (dependency.external) throw new Error(`External runtime import: ${dependency.path}`)
            visit(dependency.path)
        }
        active.delete(source)
        visited.add(source)
    }
    for (const source of Object.keys(inputs)) visit(source)
    for (const [output, details] of Object.entries(metadata.outputs)) {
        if (details.imports.length) throw new Error(`Bundle still imports runtime resources: ${output}`)
        if (output.endsWith('/personal.js') && Object.keys(details.inputs).some(input => input.startsWith('src/admin/'))) {
            throw new Error('The personal bundle must not include administration features.')
        }
    }
}

/** Build an app source tree; CLI always uses this app, tests provide isolated trees. */
export async function buildAssets(root = app) {
    const outputs = entries.map(entry => join(root, 'js', `${entry}.js`))
    await Promise.all(outputs.map(path => rm(path, { force: true })))
    let stage
    try {
        if (!process.versions.node.startsWith('24.21.')) throw new Error('Asset builds require Node 24.21.x; see docs/en/development/setup.md.')
        await mkdir(join(root, 'build'), { recursive: true })
        await mkdir(join(root, 'js'), { recursive: true })
        stage = await mkdtemp(join(root, 'build/assets-stage-'))
        const { build } = await import('esbuild')
        const result = await build({
            absWorkingDir: root,
            entryPoints: Object.fromEntries(entries.map(entry => [entry, `src/entries/${entry}.js`])),
            outdir: 'js', bundle: true, format: 'iife', platform: 'browser', target: 'es2020',
            write: false, metafile: true, minify: false, sourcemap: false, charset: 'utf8',
            legalComments: 'inline', banner: { js: banner },
        })
        verifyGraph(result.metafile, await sources(root))
        if (result.outputFiles.length !== 2) throw new Error('Expected exactly two production scripts.')
        for (const output of result.outputFiles) await writeFile(join(stage, basename(output.path)), output.contents)
        for (const entry of entries) await rename(join(stage, `${entry}.js`), join(root, 'js', `${entry}.js`))
        await writeFile(join(root, 'build/assets-metafile.json'), JSON.stringify(result.metafile, null, 2) + '\n')
        return result.metafile
    } catch (error) {
        await Promise.all(outputs.map(path => rm(path, { force: true })))
        throw error
    } finally {
        if (stage) await rm(stage, { recursive: true, force: true })
    }
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    try { await buildAssets() } catch (error) { console.error(error); process.exitCode = 1 }
}
