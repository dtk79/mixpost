import assert from 'node:assert/strict'
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

const assetDir = process.env.MIXPOST_ASSET_DIR
assert.ok(assetDir, 'Set MIXPOST_ASSET_DIR to the complete rebuilt/published vendor/mixpost directory')
const manifest = JSON.parse(readFileSync(join(assetDir, 'manifest.json'), 'utf8'))
for (const [key, entry] of Object.entries(manifest)) {
  for (const field of ['file', 'css', 'assets']) {
    let files = entry[field] || []
    if (typeof files === 'string') files = [files]
    for (const file of files) assert.ok(existsSync(join(assetDir, file)), `${key}: missing ${file}`)
  }
  for (const field of ['imports', 'dynamicImports']) {
    for (const key2 of entry[field] || []) assert.ok(manifest[key2], `${key}: missing import ${key2}`)
  }
}
const compiled = readdirSync(join(assetDir, 'assets')).filter(name => name.endsWith('.js'))
  .map(name => readFileSync(join(assetDir, 'assets', name), 'utf8')).join('\n')
for (const message of [
  'Your progress is saved. Click Retry to continue.',
  'Waiting for upload confirmation',
  'Check the media library before uploading again.'
]) assert.ok(compiled.includes(message), `Missing compiled recovery behavior: ${message}`)
console.log(`PASS ${Object.keys(manifest).length} manifest entries, all assets/imports, and compiled upload recovery messages`)
