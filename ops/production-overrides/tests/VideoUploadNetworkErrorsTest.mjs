import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { test } from 'node:test'
import vm from 'node:vm'

// Run against the exact Pro frontend source used to build the candidate image.
const sourceDir = process.env.MIXPOST_FRONTEND_SOURCE_DIR
assert.ok(sourceDir, 'Set MIXPOST_FRONTEND_SOURCE_DIR to the Pro package directory')

const load = (file, globals = {}) => {
  const source = readFileSync(join(sourceDir, 'resources/js', file), 'utf8')
    .replace(/^import .*$/gm, '')
    .replace(/^export default (\w+)$/gm, 'globalThis.loaded = $1')
  const context = vm.createContext({ ...globals })
  vm.runInContext(source, context, { filename: file })
  return context
}

const interceptor = (axios = {}) => {
  let rejectResponse
  axios.defaults = { headers: { common: {} } }
  axios.interceptors = { response: { use: (_, reject) => { rejectResponse = reject } } }
  load('bootstrap.js', {
    axios, window: { location: { reload() {} } },
    http: { onError() {} }, emitter: { emit() {} }, route: () => '/refresh'
  })
  return rejectResponse
}

test('a disconnected request preserves the original network error for the uploader', async () => {
  const reject = interceptor()
  for (const code of ['ERR_NETWORK', 'ECONNABORTED', 'ERR_CANCELED']) {
    const error = Object.assign(new Error('Network Error'), { code })
    await assert.rejects(reject(error), actual => actual === error)
  }
})

test('HTTP failures still preserve their response and CSRF refresh still replays the request', async () => {
  for (const status of [422, 500, 502]) {
    const error = { response: { status } }
    await assert.rejects(interceptor()(error), actual => actual === error)
  }
  const config = { method: 'post', url: '/upload' }
  const replayed = []
  const axios = async request => { replayed.push(request); return 'replayed' }
  axios.get = async () => ({ request: { responseURL: '/refresh' } })
  assert.equal(await interceptor(axios)({ response: { status: 419, config } }), 'replayed')
  assert.equal(replayed[0], config)
})

test('notifications can display an Axios error without an HTTP response', () => {
  const events = []
  const { loaded: useNotifications } = load('Composables/useNotifications.js', {
    axios: { isAxiosError: error => error?.isAxiosError === true },
    emitter: { emit: (...args) => events.push(args) },
    convertLaravelErrorsToString: () => 'validation error'
  })
  const error = Object.assign(new Error('Network Error'), { isAxiosError: true })
  useNotifications().notify('error', error)
  assert.equal(events.length, 1)
  assert.equal(events[0][0], 'notify')
  assert.equal(events[0][1].message, 'Network Error')
})

const uploader = (postHandler, timers = {}) => {
  const messages = []
  const aborts = []
  const rejectResponse = interceptor()
  const axios = {
    post: (...args) => Promise.resolve().then(() => postHandler(...args)).catch(rejectResponse),
    delete: async url => { aborts.push(url) }
  }
  const { loaded: useChunkedUpload } = load('Composables/useChunkedUpload.js', {
    axios, ref: value => ({ value }),
    useConfig: () => ({ chunkedUpload: { threshold: 1 } }),
    useNotifications: () => ({ notify: (_, message) => messages.push(message) }),
    route: (name, params) => ({ name, ...params }),
    FormData: class { values = {}; append(key, value) { this.values[key] = value } },
    // Retry backoff is bypassed only in this test harness.
    setTimeout: callback => callback(), clearTimeout, ...timers
  })
  return { instance: useChunkedUpload({ routePrefix: 'mixpost', workspaceId: 12 }), messages, aborts }
}

const file = { name: 'preview.mp4', type: 'video/mp4', size: 12, slice: () => ({}) }

test('a transient disconnect retries the same chunk and completes all chunks', async () => {
  const indices = []
  let completions = 0
  const { instance } = uploader((url, body) => {
    if (url.name.endsWith('.initiate')) {
      return { data: { upload_uuid: 'session', chunk_size: 4, total_chunks: 3 } }
    }
    if (url.name.endsWith('.complete')) { completions++; return { data: { id: 1 } } }
    const index = body.values.chunk_index
    indices.push(index)
    if (indices.length === 1) throw Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK' })
    return { data: { chunk_index: index } }
  })
  assert.equal((await instance.smartUpload(file)).id, 1)
  assert.deepEqual(indices, [0, 0, 1, 2])
  assert.equal(completions, 1)
  assert.equal(instance.status.value, 'complete')
  assert.equal(instance.progress.value, 100)
})

test('exhausted connection retries show a useful message and never complete a partial file', async () => {
  const networkError = Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK' })
  let attempts = 0
  const { instance, messages } = uploader(url => {
    if (url.name.endsWith('.initiate')) {
      return { data: { upload_uuid: 'session', chunk_size: 4, total_chunks: 3 } }
    }
    assert.ok(url.name.endsWith('.upload'), 'A partial upload must not be completed')
    attempts++
    throw networkError
  })
  await assert.rejects(instance.smartUpload(file), actual => actual === networkError)
  assert.equal(attempts, 4)
  assert.equal(instance.status.value, 'error')
  assert.match(instance.error.value, /Connection interrupted/)
  assert.equal(messages[0], instance.error.value)
})

test('regular uploads report the same connection interruption without a status TypeError', async () => {
  const networkError = Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK' })
  const { instance } = uploader(() => { throw networkError })
  await assert.rejects(instance.regularUpload(file), actual => actual === networkError)
  assert.match(instance.error.value, /Connection interrupted/)
})

test('manual retry retains the upload session and starts at the interrupted chunk', async () => {
  let unavailable = true
  let initiations = 0
  const indices = []
  const { instance, aborts } = uploader((url, body) => {
    if (url.name.endsWith('.initiate')) {
      initiations++
      return { data: { upload_uuid: 'saved-session', chunk_size: 4, total_chunks: 3 } }
    }
    assert.equal(url.uploadUuid, 'saved-session')
    if (url.name.endsWith('.complete')) return { data: { id: 1 } }
    const index = body.values.chunk_index
    indices.push(index)
    if (unavailable && index === 1) throw { response: { status: 500 } }
    return { data: {} }
  })
  await assert.rejects(instance.smartUpload(file))
  assert.equal(instance.progress.value, 33)
  assert.match(instance.error.value, /progress is saved/)
  unavailable = false
  await instance.smartUpload(file)
  assert.deepEqual(indices, [0, 1, 1, 1, 1, 1, 2])
  assert.equal(initiations, 1)
  assert.equal(aborts.length, 0)
  assert.equal(instance.progress.value, 100)
})

test('validation and authorization errors are never retried automatically', async () => {
  for (const status of [403, 404, 413, 422]) {
    let attempts = 0
    const { instance } = uploader(url => {
      if (url.name.endsWith('.initiate')) return { data: { upload_uuid: 'session', chunk_size: 4, total_chunks: 3 } }
      attempts++
      throw { response: { status, data: { message: 'Rejected' } } }
    })
    await assert.rejects(instance.smartUpload(file))
    assert.equal(attempts, 1, `HTTP ${status} must not be retried`)
  }
})

test('a lost completion response blocks replay and directs the user to check the library', async () => {
  let completions = 0
  const { instance } = uploader(url => {
    if (url.name.endsWith('.initiate')) return { data: { upload_uuid: 'session', chunk_size: 4, total_chunks: 3 } }
    if (url.name.endsWith('.complete')) {
      completions++
      throw new Error('Network Error')
    }
    return { data: {} }
  })
  await assert.rejects(instance.smartUpload(file))
  assert.equal(instance.retryBlocked.value, true)
  assert.match(instance.error.value, /Check the media library/)
  await assert.rejects(instance.smartUpload(file), /Check the media library/)
  assert.equal(completions, 1)
})

test('canceling during initialization aborts the later-created session without sending chunks', async () => {
  let release
  let calls = 0
  const { instance, aborts } = uploader(() => {
    calls++
    return new Promise(resolve => { release = resolve })
  })
  const uploading = instance.smartUpload(file)
  await new Promise(resolve => setImmediate(resolve))
  instance.abort()
  release({ data: { upload_uuid: 'late-session', chunk_size: 4, total_chunks: 3 } })
  await assert.rejects(uploading, /Upload aborted/)
  assert.equal(calls, 1)
  assert.equal(aborts[0].uploadUuid, 'late-session')
})

test('a slow chunk shows that confirmation is pending instead of unexplained frozen progress', async () => {
  let release
  let waitingTimer
  const { instance } = uploader(url => {
    if (url.name.endsWith('.initiate')) return { data: { upload_uuid: 'session', chunk_size: 12, total_chunks: 1 } }
    if (url.name.endsWith('.complete')) return { data: { id: 1 } }
    return new Promise(resolve => { release = resolve })
  }, { setTimeout: (callback, delay) => { if (delay === 5000) waitingTimer = callback }, clearTimeout() {} })
  const uploading = instance.smartUpload(file)
  await new Promise(resolve => setImmediate(resolve))
  waitingTimer()
  assert.equal(instance.status.value, 'waiting')
  release({ data: {} })
  await uploading
  assert.equal(instance.status.value, 'complete')
})

test('expired sessions disable manual replay while keeping the actionable server message', async () => {
  const { instance } = uploader(url => {
    if (url.name.endsWith('.initiate')) return { data: { upload_uuid: 'session', chunk_size: 4, total_chunks: 3 } }
    throw { response: { status: 422, data: { message: 'Select the file again.', errors: { upload_session: ['Expired'] } } } }
  })
  await assert.rejects(instance.smartUpload(file))
  assert.equal(instance.retryBlocked.value, true)
  assert.equal(instance.error.value, 'Select the file again.')
})

test('a successful uploader can start a different file without carrying the old session', async () => {
  let initiations = 0
  const { instance } = uploader(url => {
    if (url.name.endsWith('.initiate')) return { data: { upload_uuid: `session-${++initiations}`, chunk_size: 12, total_chunks: 1 } }
    if (url.name.endsWith('.complete')) return { data: { id: initiations } }
    return { data: {} }
  })
  await instance.smartUpload(file)
  await instance.smartUpload({ ...file, name: 'another.mp4' })
  assert.equal(initiations, 2)
})
