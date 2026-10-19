'use strict';

/*
 * Test helpers: a mock WordPress on a local port, and a runner that starts
 * action/main.js or action/post.js as a child process the way the Actions
 * runner does (INPUT_* variables, GITHUB_OUTPUT, GITHUB_STATE and
 * GITHUB_STEP_SUMMARY files, STATE_* variables for the post step).
 */

const http = require('node:http');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');

const ACTION_DIR = path.join(__dirname, '..', '..', 'action');
const SECRET = 'abcd efgh ijkl mnop qrst uvwx';
const SECRET_COMPACT = SECRET.replace(/ /g, '');

/** A time the way the plugin prints it: gmdate('c'), whole seconds, +00:00. */
function isoSeconds(milliseconds) {
  return new Date(milliseconds).toISOString().replace(/\.\d+Z$/, '+00:00');
}

function statusDocument(overrides = {}) {
  return {
    active: false,
    forced: null,
    mode: 'inactive',
    manual_enabled: false,
    schedule_status: 'none',
    schedule_start: null,
    schedule_end: null,
    ends_at: null,
    seconds_left: null,
    message: '',
    ...overrides,
  };
}

function receiptDocument(overrides = {}) {
  return {
    state: 'open',
    opened_at: '2026-09-20T22:00:00+00:00',
    closed_at: null,
    source: 'rest',
    clean: true,
    content: {
      changed_posts: 0,
      changed_ids: [],
      ids_truncated: false,
      deleted_estimate: 0,
      terms_changed: false,
      comments_added: 0,
    },
    paused: { comments: 2, forms: 1 },
    ...overrides,
  };
}

/**
 * A mock site. `handler(request, state)` may return a { status, body,
 * headers } object (or { hang: true } / { destroy: true }); otherwise the
 * default WordPress-like behaviour applies. The route is read from
 * /wp-json/stillgate/v1/... or ?rest_route=...
 *
 * `state` is the site: active, manual, forced, scheduleStatus (none,
 * upcoming, active, expired), scheduleStart, scheduleEnd, endsAt, message.
 * POST /freeze starts a timed freeze whose window begins now (real time),
 * the way the plugin stores one; DELETE /freeze ends an active freeze.
 */
async function mockSite(options = {}) {
  const requests = [];
  const state = {
    active: options.active === true,
    manual: options.manual === true,
    forced: options.forced === undefined ? null : options.forced,
    scheduleStatus: options.scheduleStatus || 'none',
    scheduleStart: options.scheduleStart || null,
    scheduleEnd: options.scheduleEnd || null,
    message: options.message || '',
    endsAt: options.endsAt || null,
    receipt: options.receipt || receiptDocument(),
    pretty: options.pretty !== false,
    handler: options.handler || null,
  };

  const server = http.createServer((req, res) => {
    const chunks = [];
    req.on('data', (chunk) => chunks.push(chunk));
    req.on('end', () => {
      const url = new URL(req.url, 'http://127.0.0.1');
      let route = null;
      let style = null;
      if (url.pathname.startsWith('/wp-json/stillgate/v1')) {
        route = url.pathname.slice('/wp-json/stillgate/v1'.length) || '/';
        style = 'pretty';
      } else if (url.searchParams.get('rest_route') && url.searchParams.get('rest_route').startsWith('/stillgate/v1')) {
        route = url.searchParams.get('rest_route').slice('/stillgate/v1'.length) || '/';
        style = 'plain';
      }
      const raw = Buffer.concat(chunks).toString('utf8');
      let body = null;
      try {
        body = raw === '' ? null : JSON.parse(raw);
      } catch (error) {
        body = raw;
      }
      const record = { method: req.method, route, style, body, headers: req.headers, url: req.url };
      requests.push(record);

      const send = (status, payload, headers = {}) => {
        if (typeof payload === 'string') {
          res.writeHead(status, { 'content-type': 'text/html; charset=UTF-8', ...headers });
          res.end(payload);
          return;
        }
        res.writeHead(status, { 'content-type': 'application/json; charset=UTF-8', ...headers });
        res.end(JSON.stringify(payload));
      };

      if (state.handler) {
        const custom = state.handler(record, state);
        if (custom && custom.hang) {
          return;
        }
        if (custom && custom.destroy) {
          req.socket.destroy();
          return;
        }
        if (custom) {
          send(custom.status, custom.body, custom.headers);
          return;
        }
      }

      if (route === null || (style === 'pretty' && !state.pretty)) {
        send(404, '<html><body>Not Found</body></html>');
        return;
      }

      const document = () => statusDocument({
        active: state.active,
        forced: state.forced,
        mode: state.manual ? 'manual' : state.scheduleStart ? 'scheduled' : 'inactive',
        manual_enabled: state.manual,
        schedule_status: state.scheduleStatus,
        schedule_start: state.scheduleStart,
        schedule_end: state.scheduleEnd,
        ends_at: state.active ? state.endsAt : null,
        seconds_left: state.active ? 1800 : null,
        message: state.message,
      });

      if (req.method === 'GET' && route === '/status') {
        send(200, document());
      } else if (req.method === 'POST' && route === '/freeze') {
        if (body !== null && typeof body === 'object' && typeof body.message === 'string') {
          state.message = body.message;
        }
        state.active = true;
        state.manual = false;
        state.scheduleStatus = 'active';
        state.scheduleStart = isoSeconds(Date.now());
        state.scheduleEnd = '2026-09-20T22:30:00+00:00';
        state.endsAt = '2026-09-20T22:30:00+00:00';
        send(200, { ...document(), result: 'timed' });
      } else if (req.method === 'DELETE' && route === '/freeze') {
        const result = state.active ? 'disabled' : 'unchanged';
        if (state.active) {
          state.active = false;
          state.manual = false;
          state.scheduleStatus = 'none';
          state.scheduleStart = null;
          state.scheduleEnd = null;
          state.endsAt = null;
        }
        send(200, { ...document(), result });
      } else if (req.method === 'GET' && route === '/receipt') {
        send(200, state.receipt);
      } else {
        send(404, { code: 'rest_no_route', message: 'No route was found matching the URL and request method.', data: { status: 404 } });
      }
    });
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  return {
    url: `http://127.0.0.1:${port}`,
    requests,
    state,
    calls: (method, route) => requests.filter((r) => r.method === method && r.route === route),
    close: () => new Promise((resolve) => {
      server.closeAllConnections();
      server.close(resolve);
    }),
  };
}

/** What the runner does with a GITHUB_OUTPUT / GITHUB_STATE file. */
function parseCommandFile(text) {
  const result = {};
  const lines = text.split('\n');
  for (let i = 0; i < lines.length; i += 1) {
    const line = lines[i];
    if (line === '') {
      continue;
    }
    const heredoc = /^([^<=]+)<<(.+)$/.exec(line);
    if (heredoc) {
      const values = [];
      i += 1;
      while (i < lines.length && lines[i] !== heredoc[2]) {
        values.push(lines[i]);
        i += 1;
      }
      if (i >= lines.length) {
        throw new Error(`Unterminated delimiter for ${heredoc[1]}`);
      }
      result[heredoc[1]] = values.join('\n');
      continue;
    }
    const simple = /^([^=]+)=(.*)$/.exec(line);
    if (!simple) {
      throw new Error(`Unparseable line in command file: ${JSON.stringify(line)}`);
    }
    result[simple[1]] = simple[2];
  }
  return result;
}

/** The workflow commands the runner would see on stdout. */
function workflowCommands(stdout) {
  return stdout
    .split('\n')
    .map((line) => /^::([a-z-]+)(?: [^:]*)?::(.*)$/i.exec(line))
    .filter(Boolean)
    .map((match) => ({ name: match[1].toLowerCase(), data: match[2] }));
}

class Job {
  constructor() {
    this.dir = fs.mkdtempSync(path.join(os.tmpdir(), 'stillgate-action-'));
    this.files = {
      output: path.join(this.dir, 'output'),
      state: path.join(this.dir, 'state'),
      summary: path.join(this.dir, 'summary'),
    };
    for (const file of Object.values(this.files)) {
      fs.writeFileSync(file, '');
    }
  }

  inputs(site, overrides = {}) {
    const inputs = {
      'site-url': site.url,
      username: 'deploy-bot',
      'app-password': SECRET,
      duration: '30m',
      message: '',
      'if-already-frozen': 'fail',
      'fail-on-changes': 'true',
      release: 'true',
      ...overrides,
    };
    const env = {};
    for (const [name, value] of Object.entries(inputs)) {
      if (value !== undefined) {
        env[`INPUT_${name.toUpperCase()}`] = value;
      }
    }
    return env;
  }

  read(which) {
    return fs.readFileSync(this.files[which], 'utf8');
  }

  /**
   * Start main.js or post.js. Returns { child, result }; result resolves
   * { code, signal, timedOut, stdout, stderr }. A step that has not ended
   * after killAfterMs is killed, so a step that waits forever fails the test
   * that started it instead of hanging the run.
   */
  spawnStep(script, extraEnv = {}, { killAfterMs = 30000 } = {}) {
    const env = {
      PATH: process.env.PATH,
      GITHUB_OUTPUT: this.files.output,
      GITHUB_STATE: this.files.state,
      GITHUB_STEP_SUMMARY: this.files.summary,
      STILLGATE_ACTION_RETRY_DELAY_MS: '5',
      STILLGATE_ACTION_TIMEOUT_MS: '3000',
      ...extraEnv,
    };
    const child = spawn(process.execPath, [path.join(ACTION_DIR, script)], { env });
    const result = new Promise((resolve) => {
      let stdout = '';
      let stderr = '';
      let timedOut = false;
      const timer = setTimeout(() => {
        timedOut = true;
        child.kill('SIGKILL');
      }, killAfterMs);
      child.stdout.on('data', (chunk) => { stdout += chunk; });
      child.stderr.on('data', (chunk) => { stderr += chunk; });
      child.on('close', (code, signal) => {
        clearTimeout(timer);
        resolve({ code, signal, timedOut, stdout, stderr });
      });
    });
    return { child, result };
  }

  /** Run main.js or post.js to the end. */
  run(script, extraEnv, options) {
    return this.spawnStep(script, extraEnv, options).result;
  }

  /** Start main.js and hand back the child, to signal it mid-flight. */
  startMain(site, overrides, extraEnv, options) {
    return this.spawnStep('main.js', { ...this.inputs(site, overrides), ...extraEnv }, options);
  }

  async main(site, overrides, extraEnv) {
    const result = await this.run('main.js', { ...this.inputs(site, overrides), ...extraEnv });
    result.outputs = parseCommandFile(this.read('output'));
    result.state = parseCommandFile(this.read('state'));
    return result;
  }

  /** The post step gets the inputs again and the saved state as STATE_*. */
  async post(site, overrides, extraEnv) {
    const state = parseCommandFile(this.read('state'));
    const stateEnv = {};
    for (const [name, value] of Object.entries(state)) {
      stateEnv[`STATE_${name}`] = value;
    }
    fs.writeFileSync(this.files.output, '');
    const result = await this.run('post.js', { ...this.inputs(site, overrides), ...stateEnv, ...extraEnv });
    result.outputs = parseCommandFile(this.read('output'));
    result.summary = this.read('summary');
    return result;
  }

  cleanup() {
    fs.rmSync(this.dir, { recursive: true, force: true });
  }
}

module.exports = {
  Job,
  isoSeconds,
  SECRET,
  SECRET_COMPACT,
  mockSite,
  parseCommandFile,
  receiptDocument,
  statusDocument,
  workflowCommands,
};
