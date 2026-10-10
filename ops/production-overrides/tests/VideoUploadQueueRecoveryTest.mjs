import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { join } from 'node:path'
import { test } from 'node:test'
import vm from 'node:vm'

const sourceDir = process.env.MIXPOST_FRONTEND_SOURCE_DIR
assert.ok(sourceDir, 'Set MIXPOST_FRONTEND_SOURCE_DIR to the candidate Pro package with npm ci installed')
const require = createRequire(join(sourceDir, 'package.json'))
const vue = require('vue')

const settle = async condition => {
  for (let attempt = 0; attempt < 100; attempt++) {
    await new Promise(resolve => setImmediate(resolve))
    if (condition()) return
  }
  assert.fail('The upload queue did not reach the expected state')
}

const component = post => {
  let id = 0
  const emitted = []
  const aborts = []
  const axios = { post: async (...args) => post(...args), delete: async url => { aborts.push(url.uploadUuid) } }
  const globals = {
    ...vue, axios, console,
    useConfig: () => ({ chunkedUpload: { threshold: 1 }, mimeTypes: ['video/mp4'] }),
    useNotifications: () => ({ notify() {} }),
    route: (name, params) => ({ name, ...params }),
    FormData: class { values = {}; append(key, value) { this.values[key] = value } },
    setTimeout: callback => callback(),
    setInterval, clearInterval, clearTimeout, AbortController
  }
  const composable = readFileSync(join(sourceDir, 'resources/js/Composables/useChunkedUpload.js'), 'utf8')
    .replace(/^import .*$/gm, '').replace(/^export default (\w+)$/gm, 'globalThis.loaded = $1')
  const composableContext = vm.createContext({ ...globals })
  vm.runInContext(composable, composableContext)
  const script = readFileSync(join(sourceDir, 'resources/js/Components/Media/UploadMedia.vue'), 'utf8')
    .split('<script setup>')[1].split('</script>')[0]
    .replace(/^import .*$/gm, '').split('// DEV ONLY:')[0]
  const context = vm.createContext({
    ...globals,
    inject: key => key === 'routePrefix' ? 'mixpost' : { id: 12 },
    useI18n: () => ({ t: value => value }),
    usePage: () => ({ props: { mixpost: { features: {} } } }),
    useRemoteUpload: () => ({}), useMediaFolderPaths: () => ({}),
    nanoid: () => `job-${++id}`,
    defineProps: () => ({ mimeTypes: [], folder: 'saved-folder' }),
    defineEmits: () => (...args) => emitted.push(args), defineExpose() {},
    document: { createElement: () => ({}) },
    useChunkedUpload: composableContext.loaded
  })
  vm.runInContext(script + '\nglobalThis.queue = { enqueue, retryUpload, cancelUpload, uploadJobs, active, pending };', context)
  return { ...context.queue, emitted, aborts }
}

const file = name => ({ name, type: 'video/mp4', size: 12, slice: () => ({}) })

test('the actual Vue queue retries the same job and preserves refs, progress, folder, and session', async () => {
  let unavailable = true
  let initiations = 0
  const indices = []
  const queue = component((url, body) => {
    if (url.name.endsWith('.initiate')) {
      initiations++
      assert.equal(body.folder, 'saved-folder')
      return { data: { upload_uuid: 'saved', chunk_size: 4, total_chunks: 3 } }
    }
    assert.equal(url.uploadUuid, 'saved')
    if (url.name.endsWith('.complete')) {
      assert.equal(body.folder, 'saved-folder')
      return { data: { id: 123 } }
    }
    indices.push(body.values.chunk_index)
    if (unavailable && body.values.chunk_index === 1) throw { response: { status: 500 } }
    return { data: {} }
  })
  queue.enqueue(file('preview.mp4'), 'saved-folder')
  await settle(() => queue.uploadJobs.value[0].status === 'error')
  const job = queue.uploadJobs.value[0]
  const instance = job.uploadInstance
  assert.equal(job.progress, 33)
  assert.match(job.error, /progress is saved/)
  assert.equal(instance.progress.value, 33, 'Vue must not unwrap stored uploader refs')
  unavailable = false
  queue.retryUpload(job)
  queue.retryUpload(job) // A rapid second click cannot queue another attempt.
  await settle(() => job.status === 'complete')
  assert.equal(job.uploadInstance, instance)
  assert.equal(initiations, 1)
  assert.deepEqual(indices, [0, 1, 1, 1, 1, 1, 2])
  assert.equal(queue.aborts.length, 0)
  assert.equal(queue.uploadJobs.value.length, 1)
  assert.equal(queue.emitted.length, 1)
  assert.equal(queue.emitted[0][2], 'saved-folder')
})

test('the progress panel blocks retry after an ambiguous completion response', async () => {
  let completions = 0
  const queue = component(url => {
    if (url.name.endsWith('.initiate')) return { data: { upload_uuid: 'saved', chunk_size: 4, total_chunks: 3 } }
    if (url.name.endsWith('.complete')) {
      completions++
      throw new Error('Network Error')
    }
    return { data: {} }
  })
  queue.enqueue(file('preview.mp4'), 'saved-folder')
  await settle(() => queue.uploadJobs.value[0].status === 'error')
  const job = queue.uploadJobs.value[0]
  assert.equal(job.retryBlocked, true)
  queue.retryUpload(job)
  await new Promise(resolve => setImmediate(resolve))
  assert.equal(completions, 1)
  assert.equal(queue.emitted.length, 0)
  assert.match(job.error, /Check the media library/)
})

test('a canceled initiation settling later does not skip or duplicate the next queued file', async () => {
  let release
  const initiations = []
  const queue = component((url, body) => {
    if (url.name.endsWith('.initiate')) {
      initiations.push(body.filename)
      if (body.filename === 'cancel.mp4') return new Promise(resolve => { release = resolve })
      return { data: { upload_uuid: 'next', chunk_size: 4, total_chunks: 3 } }
    }
    if (url.name.endsWith('.complete')) return { data: { id: 321 } }
    return { data: {} }
  })
  queue.enqueue(file('cancel.mp4'), 'saved-folder')
  queue.enqueue(file('next.mp4'), 'saved-folder')
  await settle(() => release)
  queue.cancelUpload(queue.uploadJobs.value[0])
  release({ data: { upload_uuid: 'canceled', chunk_size: 4, total_chunks: 3 } })
  await settle(() => queue.uploadJobs.value[0].status === 'complete')
  await new Promise(resolve => setImmediate(resolve))
  assert.deepEqual(initiations, ['cancel.mp4', 'next.mp4'])
  assert.deepEqual(queue.aborts, ['canceled'])
  assert.equal(queue.emitted.length, 1)
  assert.equal(queue.pending.value.length, 0)
})
