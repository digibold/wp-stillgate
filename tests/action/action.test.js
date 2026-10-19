'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const { Job, SECRET, SECRET_COMPACT, isoSeconds, mockSite, parseCommandFile, receiptDocument, workflowCommands } = require('./helpers.js');

// Every test gets a time limit of its own, and every step a test starts is
// killed after 30 seconds (see Job.spawnStep), so a step that waits for a
// site that never answers fails its test instead of hanging the run.
const TEST_TIMEOUT_MS = 90000;

const BASIC = `Basic ${Buffer.from(`deploy-bot:${SECRET}`).toString('base64')}`;

/** Run a scenario with a mock site and a job directory, always cleaned up. */
function scenario(name, siteOptions, body) {
  test(name, { timeout: TEST_TIMEOUT_MS }, async () => {
    const site = await mockSite(siteOptions);
    const job = new Job();
    try {
      await body(site, job);
    } finally {
      job.cleanup();
      await site.close();
    }
  });
}

/** Everything an observer could read, except the add-mask commands that carry the secret to the runner. */
function observable(...texts) {
  return texts
    .join('\n')
    .split('\n')
    .filter((line) => !line.startsWith('::add-mask::'))
    .join('\n');
}

function errors(stdout) {
  return workflowCommands(stdout).filter((command) => command.name === 'error');
}

function warnings(stdout) {
  return workflowCommands(stdout).filter((command) => command.name === 'warning');
}

scenario('happy path: starts a timed freeze, reads a clean receipt, releases', {}, async (site, job) => {
  const started = await job.main(site, { message: 'Deploying release 42' });
  assert.equal(started.code, 0, started.stdout + started.stderr);
  assert.equal(started.outputs.frozen, 'true');
  assert.equal(started.outputs['ends-at'], '2026-09-20T22:30:00+00:00');
  assert.equal(started.state.started, 'true');
  assert.equal(started.state.route, 'pretty');
  assert.equal(started.state.windowstart, site.state.scheduleStart, 'the start the site returned is saved');
  assert.equal(started.state.endsat, '2026-09-20T22:30:00+00:00');
  assert.ok(Math.abs(Number(started.state.startedat) - Date.now()) < 60000, 'the runner time is saved');

  const [freeze] = site.calls('POST', '/freeze');
  assert.deepEqual(freeze.body, { for: '30m', message: 'Deploying release 42' });
  assert.equal(freeze.headers.authorization, BASIC);
  assert.equal(freeze.headers['content-type'], 'application/json');
  assert.equal(site.calls('POST', '/freeze').length, 1);

  const finished = await job.post(site);
  assert.equal(finished.code, 0, finished.stdout + finished.stderr);
  assert.equal(finished.outputs['receipt-clean'], 'true');
  assert.equal(finished.outputs['changed-ids'], '');
  assert.match(finished.summary, /Clean: nothing changed/);
  assert.match(finished.summary, /\| Visitor comments refused \| 2 \|/);
  assert.match(finished.summary, /\| Form submissions refused \| 1 \|/);
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
  assert.equal(site.state.active, false);
});

scenario('an empty message is still sent, so the message of an earlier freeze is cleared', { message: 'Deploying release 41' }, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 0, started.stdout);
  assert.deepEqual(site.calls('POST', '/freeze')[0].body, { for: '30m', message: '' });
  assert.equal(site.state.message, '', 'the earlier message is gone');
});

scenario('a message is sent as given', {}, async (site, job) => {
  await job.main(site, { message: 'Deploying release 42' });
  assert.equal(site.state.message, 'Deploying release 42');
});

scenario('the receipt is read while the freeze is still on, before it is released', {}, async (site, job) => {
  await job.main(site);
  await job.post(site);
  const order = site.requests.filter((r) => r.route !== '/status' || r.method !== 'GET').map((r) => `${r.method} ${r.route}`);
  assert.deepEqual(order, ['POST /freeze', 'GET /receipt', 'DELETE /freeze']);
});

scenario('if-already-frozen fail: refuses to start and the post step does nothing', { active: true, endsAt: '2026-09-20T23:00:00+00:00' }, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.equal(errors(started.stdout).length, 1);
  assert.match(errors(started.stdout)[0].data, /already active/);
  assert.match(errors(started.stdout)[0].data, /if-already-frozen/);
  assert.equal(site.calls('POST', '/freeze').length, 0);
  assert.equal(started.outputs.frozen, 'false');
  assert.equal(started.state.started, undefined);

  const before = site.requests.length;
  const finished = await job.post(site);
  assert.equal(finished.code, 0);
  assert.equal(site.requests.length, before, 'the post step must not touch the site');
  assert.equal(site.state.active, true, 'the existing freeze is untouched');
});

scenario('if-already-frozen keep: continues, starts nothing and never releases', { active: true, endsAt: '2026-09-20T23:00:00+00:00' }, async (site, job) => {
  const started = await job.main(site, { 'if-already-frozen': 'keep' });
  assert.equal(started.code, 0, started.stdout);
  assert.equal(started.outputs.frozen, 'false');
  assert.equal(started.outputs['ends-at'], '2026-09-20T23:00:00+00:00');
  assert.equal(site.calls('POST', '/freeze').length, 0);

  const finished = await job.post(site, { 'if-already-frozen': 'keep' });
  assert.equal(finished.code, 0);
  assert.equal(site.calls('DELETE', '/freeze').length, 0);
  assert.equal(site.calls('GET', '/receipt').length, 0);
  assert.equal(site.state.active, true);
});

function notices(stdout) {
  return workflowCommands(stdout).filter((command) => command.name === 'notice');
}

const UPCOMING = {
  scheduleStatus: 'upcoming',
  scheduleStart: '2026-09-21T01:00:00+00:00',
  scheduleEnd: '2026-09-21T03:00:00+00:00',
};

scenario('if-already-frozen fail: an upcoming scheduled window counts as a conflict and is named', UPCOMING, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.equal(errors(started.stdout).length, 1);
  const message = errors(started.stdout)[0].data;
  assert.match(message, /scheduled freeze window/);
  assert.ok(message.includes('2026-09-21T01:00:00+00:00'), 'names the start');
  assert.ok(message.includes('2026-09-21T03:00:00+00:00'), 'names the end');
  assert.match(message, /replace/);
  assert.equal(site.calls('POST', '/freeze').length, 0, 'the window must not be overwritten');
  assert.equal(started.outputs.frozen, 'false');
  assert.equal(started.state.started, undefined);
  assert.equal(site.state.scheduleStatus, 'upcoming');

  const before = site.requests.length;
  const finished = await job.post(site);
  assert.equal(finished.code, 0);
  assert.equal(site.requests.length, before, 'the post step does not touch the site');
});

scenario('if-already-frozen keep: an upcoming scheduled window is refused, because keep would continue unfrozen', UPCOMING, async (site, job) => {
  const started = await job.main(site, { 'if-already-frozen': 'keep' });
  assert.equal(started.code, 1);
  const message = errors(started.stdout)[0].data;
  assert.match(message, /keep/);
  assert.match(message, /without a freeze/);
  assert.match(message, /replace/);
  assert.ok(message.includes('2026-09-21T01:00:00+00:00') && message.includes('2026-09-21T03:00:00+00:00'));
  assert.equal(site.calls('POST', '/freeze').length, 0);
  assert.equal(started.outputs.frozen, 'false');
  assert.equal(site.state.scheduleStatus, 'upcoming');
});

scenario('if-already-frozen replace: takes over an upcoming window and the release ends it', UPCOMING, async (site, job) => {
  const started = await job.main(site, { 'if-already-frozen': 'replace' });
  assert.equal(started.code, 0, started.stdout);
  assert.equal(started.outputs.frozen, 'true');
  assert.equal(site.calls('POST', '/freeze').length, 1);
  assert.match(notices(started.stdout)[0].data, /scheduled freeze window.*replace/);
  assert.equal(site.state.active, true);

  const finished = await job.post(site, { 'if-already-frozen': 'replace' });
  assert.equal(finished.code, 0, finished.stdout);
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
  assert.equal(site.state.active, false);
  assert.equal(site.state.scheduleStatus, 'none', 'the window is gone once the job has ended its own freeze');
});

scenario('if-already-frozen replace: takes over a freeze that is already active', { active: true, manual: true }, async (site, job) => {
  const started = await job.main(site, { 'if-already-frozen': 'replace' });
  assert.equal(started.code, 0, started.stdout);
  assert.equal(started.outputs.frozen, 'true');
  assert.match(notices(started.stdout)[0].data, /already active.*takes its place/);
  assert.equal(site.calls('POST', '/freeze').length, 1);
  assert.equal(site.state.manual, false);

  const finished = await job.post(site, { 'if-already-frozen': 'replace' });
  assert.equal(finished.code, 0, finished.stdout);
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
});

scenario('if-already-frozen replace on a site with nothing to replace just starts the freeze', {}, async (site, job) => {
  const started = await job.main(site, { 'if-already-frozen': 'replace' });
  assert.equal(started.code, 0, started.stdout);
  assert.equal(notices(started.stdout).length, 0);
  assert.equal(site.calls('POST', '/freeze').length, 1);
});

scenario('if-already-frozen rejects any other value', {}, async (site, job) => {
  const started = await job.main(site, { 'if-already-frozen': 'continue' });
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /fail, keep or replace/);
  assert.equal(site.requests.length, 0);
});

scenario('401 gives one actionable line', {
  handler: () => ({ status: 401, body: { code: 'incorrect_password', message: 'The provided password is an invalid application password.', data: { status: 401 } } }),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.equal(errors(started.stdout).length, 1);
  assert.match(errors(started.stdout)[0].data, /HTTP 401.*Application Password/);
  assert.equal(started.outputs.frozen, 'false');
});

scenario('403 on the freeze request names the missing capability', {
  handler: (request) => (request.method === 'POST'
    ? { status: 403, body: { code: 'stillgate_forbidden', message: 'Sorry, you are not allowed to do that.', data: { status: 403 } } }
    : null),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /deployment_guard_bypass/);
  assert.equal(started.state.started, 'false', 'a refused request leaves nothing to release');
  const finished = await job.post(site);
  assert.equal(finished.code, 0, finished.stdout);
  assert.match(finished.stdout, /nothing to end/);
  assert.equal(site.calls('DELETE', '/freeze').length, 0);
});

scenario('403 from a firewall (other code) mentions security plugins', {
  handler: () => ({ status: 403, body: { code: 'rest_forbidden', message: 'Blocked.', data: { status: 403 } } }),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /manage_options/);
});

scenario('404 on /wp-json/ falls back to ?rest_route= and remembers it for the post step', { pretty: false }, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 0, started.stdout);
  assert.equal(started.state.route, 'plain');
  assert.deepEqual(site.requests.map((r) => r.style), ['pretty', 'plain', 'plain'], 'the first attempt is /wp-json/, then ?rest_route=');
  assert.match(site.requests[0].url, /^\/wp-json\/stillgate\/v1\/status$/);
  assert.match(site.requests[1].url, /^\/\?rest_route=\/stillgate\/v1\/status$/);

  const finished = await job.post(site);
  assert.equal(finished.code, 0, finished.stdout);
  assert.ok(site.requests.filter((r) => r.method === 'DELETE').every((r) => r.style === 'plain'));
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
});

scenario('404 on both forms names the likely causes', {
  handler: () => ({ status: 404, body: '<html>Not Found</html>' }),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /HTTP 404.*not be active.*security plugin/);
  assert.equal(site.requests.length, 2, 'one try per form, no retries on a 404');
});

scenario('a non-JSON answer is reported as a firewall or challenge page', {
  handler: () => ({ status: 200, body: '<html><title>Just a moment...</title></html>' }),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /instead of JSON.*firewall/);
});

scenario('400 invalid_duration is explained and nothing is left to release', {
  handler: (request) => (request.method === 'POST'
    ? { status: 400, body: { code: 'invalid_duration', message: 'That duration could not be understood. Use a value such as 30m, 2h, 1d or 1h30m.', data: { status: 400 } } }
    : null),
}, async (site, job) => {
  const started = await job.main(site, { duration: 'banana' });
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /invalid_duration.*"banana"/);
  assert.equal(site.calls('POST', '/freeze')[0].body.for, 'banana');
  assert.equal(started.state.started, 'false');
});

scenario('an empty duration never reaches the site', {}, async (site, job) => {
  const started = await job.main(site, { duration: '' });
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /duration is empty/);
  assert.equal(site.requests.length, 0);
});

scenario('409 is reported as a conflict', {
  handler: (request) => (request.method === 'POST'
    ? { status: 409, body: { code: 'something_conflicts', message: 'Not possible right now.', data: { status: 409 } } }
    : null),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /HTTP 409 something_conflicts.*conflicts/);
});

scenario('https is required except for localhost and 127.0.0.1', {}, async (site, job) => {
  const remote = await job.main({ url: 'http://example.com' });
  assert.equal(remote.code, 1);
  assert.match(errors(remote.stdout)[0].data, /must use https/);

  const withCredentials = await job.main({ url: 'https://user:hunter2@example.com' });
  assert.equal(withCredentials.code, 1);
  assert.match(errors(withCredentials.stdout)[0].data, /user name or password/);
  assert.ok(!withCredentials.stdout.includes('hunter2'));

  assert.equal(site.requests.length, 0);
  const local = await job.main(site);
  assert.equal(local.code, 0);
});

scenario('the Application Password never appears in any output channel', {
  handler: (request) => {
    // A hostile or careless server that echoes the credentials back.
    if (request.method === 'POST') {
      return {
        status: 500,
        body: {
          code: 'echo',
          message: `Authorization was ${request.headers.authorization} and the password ${SECRET} / ${SECRET_COMPACT}`,
          data: { status: 500 },
        },
      };
    }
    return null;
  },
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  // The summary is only written by the post step; the next test covers it,
  // and tests/action/lib.test.js feeds the writers a secret directly.
  const everything = observable(started.stdout, started.stderr, job.read('output'), job.read('state'));
  const base64 = Buffer.from(`deploy-bot:${SECRET}`).toString('base64');
  for (const secret of [SECRET, SECRET_COMPACT, base64, BASIC]) {
    assert.ok(!everything.includes(secret), `leaked: ${secret}`);
  }
  assert.ok(started.stdout.startsWith(`::add-mask::${SECRET}\n::add-mask::${SECRET_COMPACT}\n`), 'the masks come first');
  assert.ok(started.stdout.includes(`::add-mask::${base64}`));
});

scenario('the password is masked in the post step too, even on a failing read', {}, async (site, job) => {
  await job.main(site);
  site.state.handler = (request) => (request.route === '/receipt'
    ? { status: 502, body: { code: 'echo', message: `seen ${SECRET}`, data: { status: 502 } } }
    : null);
  const finished = await job.post(site);
  const everything = observable(finished.stdout, finished.stderr, job.read('output'), job.read('state'), job.read('summary'));
  assert.ok(!everything.includes(SECRET));
  assert.ok(!everything.includes(SECRET_COMPACT));
  assert.ok(finished.stdout.startsWith('::add-mask::'));
});

scenario('a dirty receipt fails the job, after the freeze was released', {
  receipt: receiptDocument({
    clean: false,
    content: { changed_posts: 2, changed_ids: [5, 7], ids_truncated: false, deleted_estimate: 1, terms_changed: true, comments_added: 3 },
  }),
}, async (site, job) => {
  await job.main(site);
  const finished = await job.post(site);
  assert.equal(finished.code, 1);
  assert.equal(site.calls('DELETE', '/freeze').length, 1, 'released before failing');
  assert.equal(site.state.active, false);
  assert.match(errors(finished.stdout)[0].data, /Content changed.*5, 7/);
  assert.equal(finished.outputs['receipt-clean'], 'false');
  assert.equal(finished.outputs['changed-ids'], '5,7');
  assert.match(finished.summary, /Content changed while the freeze was on/);
  assert.match(finished.summary, /Changed post IDs: 5, 7/);
  assert.match(finished.summary, /wp export --post__in=5,7 /);
  assert.match(finished.summary, /\| Posts, pages and media changed \| 2 \|/);
  assert.match(finished.summary, /\| Comments added \| 3 \|/);
});

scenario('fail-on-changes false reports a dirty receipt without failing', {
  receipt: receiptDocument({ clean: false, content: { changed_posts: 1, changed_ids: [9] } }),
}, async (site, job) => {
  await job.main(site);
  const finished = await job.post(site, { 'fail-on-changes': 'false' });
  assert.equal(finished.code, 0);
  assert.equal(finished.outputs['receipt-clean'], 'false');
  assert.equal(finished.outputs['changed-ids'], '9');
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
});

scenario('the changed IDs in the summary are capped at 50', {
  receipt: receiptDocument({
    clean: false,
    content: { changed_posts: 120, changed_ids: Array.from({ length: 120 }, (_, i) => i + 1), ids_truncated: false },
  }),
}, async (site, job) => {
  await job.main(site);
  const finished = await job.post(site);
  assert.match(finished.summary, /Changed post IDs: 1, 2, 3.*, 50 and 70 more/);
  assert.ok(!finished.summary.includes(', 51'));
  assert.match(finished.summary, /wp stillgate drift/);
  assert.equal(finished.outputs['changed-ids'].split(',').length, 120);
});

scenario('an unknown receipt fails when fail-on-changes is true', {
  receipt: receiptDocument({ clean: null }),
}, async (site, job) => {
  await job.main(site);
  const finished = await job.post(site);
  assert.equal(finished.code, 1);
  assert.match(errors(finished.stdout)[0].data, /cannot confirm/);
  assert.equal(finished.outputs['receipt-clean'], 'unknown');
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
});

scenario('an unknown receipt is only reported when fail-on-changes is false', {
  receipt: receiptDocument({ clean: null }),
}, async (site, job) => {
  await job.main(site);
  const finished = await job.post(site, { 'fail-on-changes': 'false' });
  assert.equal(finished.code, 0);
  assert.equal(finished.outputs['receipt-clean'], 'unknown');
});

scenario('the freeze is released even when the receipt cannot be read', {
  handler: (request) => (request.route === '/receipt' ? { status: 503, body: { code: 'busy', message: 'Try later', data: { status: 503 } } } : null),
}, async (site, job) => {
  await job.main(site);
  const finished = await job.post(site);
  assert.equal(site.calls('GET', '/receipt').length, 3, 'a GET is retried twice on a 5xx');
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
  assert.equal(site.state.active, false);
  assert.equal(finished.code, 1);
  assert.match(errors(finished.stdout)[0].data, /receipt could not be read/);
  assert.equal(finished.outputs['receipt-clean'], 'unknown');
  assert.match(finished.summary, /could not be read/);

  await job.main(site);
  const lenient = await job.post(site, { 'fail-on-changes': 'false' });
  assert.equal(lenient.code, 0);
  assert.equal(site.calls('DELETE', '/freeze').length, 2);
});

scenario('release false leaves the freeze on and still reads the receipt', {}, async (site, job) => {
  await job.main(site);
  const finished = await job.post(site, { release: 'false' });
  assert.equal(finished.code, 0, finished.stdout);
  assert.equal(site.calls('DELETE', '/freeze').length, 0);
  assert.equal(site.state.active, true);
  assert.equal(site.calls('GET', '/receipt').length, 1);
  assert.match(finished.summary, /left on/);
});

scenario('release false with a dirty receipt fails and keeps the freeze', {
  receipt: receiptDocument({ clean: false, content: { changed_posts: 1, changed_ids: [3] } }),
}, async (site, job) => {
  await job.main(site);
  const finished = await job.post(site, { release: 'false' });
  assert.equal(finished.code, 1);
  assert.equal(site.state.active, true);
  assert.match(errors(finished.stdout)[0].data, /left on/);
});

scenario('a failure to end the freeze fails the job and says how it ends anyway', {}, async (site, job) => {
  await job.main(site);
  site.state.handler = (request) => (request.method === 'DELETE'
    ? { status: 403, body: { code: 'stillgate_forbidden', message: 'no', data: { status: 403 } } }
    : null);
  const finished = await job.post(site);
  assert.equal(finished.code, 1);
  assert.match(errors(finished.stdout)[0].data, /Could not end the freeze.*ends by itself/);
});

scenario('the post step is a no-op when nothing was started', {}, async (site, job) => {
  const finished = await job.post(site);
  assert.equal(finished.code, 0);
  assert.equal(site.requests.length, 0);
  assert.match(finished.stdout, /nothing to end/);
  assert.equal(job.read('summary'), '');
});

scenario('a freeze that ended before the post step cannot be vouched for: the job fails', {}, async (site, job) => {
  await job.main(site);
  site.state.active = false;
  site.state.scheduleStatus = 'expired';
  site.state.receipt = receiptDocument({ state: 'closed', closed_at: '2026-09-20T22:30:00+00:00' });
  const finished = await job.post(site);
  assert.equal(finished.code, 1, finished.stdout);
  assert.equal(finished.outputs['receipt-clean'], 'unknown', 'a clean-looking receipt is not trusted');
  assert.equal(finished.outputs['changed-ids'], '');
  assert.equal(warnings(finished.stdout).length, 1);
  assert.match(warnings(finished.stdout)[0].data, /already ended/);
  assert.match(errors(finished.stdout)[0].data, /already ended.*nothing can be promised/);
  assert.match(finished.summary, /Cannot be confirmed/);
  assert.match(finished.summary, /already ended/);
  assert.ok(!finished.summary.includes('Clean: nothing changed'));
  assert.equal(site.calls('DELETE', '/freeze').length, 0, 'there is nothing to end');
  assert.equal(site.calls('GET', '/receipt').length, 0, 'a receipt that cannot prove anything is not read');
});

scenario('a freeze that ended before the post step is only reported when fail-on-changes is false', {}, async (site, job) => {
  await job.main(site);
  site.state.active = false;
  site.state.scheduleStatus = 'expired';
  const finished = await job.post(site, { 'fail-on-changes': 'false' });
  assert.equal(finished.code, 0, finished.stdout);
  assert.equal(finished.outputs['receipt-clean'], 'unknown');
  assert.equal(errors(finished.stdout).length, 0);
  assert.match(finished.summary, /Cannot be confirmed/);
});

/** Another pipeline's freeze took the place of this job's. */
function handOver(site, overrides = {}) {
  Object.assign(site.state, {
    active: true,
    scheduleStatus: 'active',
    scheduleStart: isoSeconds(Date.now() + 5000),
    scheduleEnd: '2030-01-01T00:00:00+00:00',
    endsAt: '2030-01-01T00:00:00+00:00',
  }, overrides);
}

scenario('the post step never ends a freeze that changed hands', {}, async (site, job) => {
  await job.main(site);
  handOver(site);
  const finished = await job.post(site);
  assert.equal(finished.code, 1, finished.stdout);
  assert.equal(site.calls('DELETE', '/freeze').length, 0, 'the other freeze must be left alone');
  assert.equal(site.calls('GET', '/receipt').length, 0, 'its receipt says nothing about this job');
  assert.equal(site.state.active, true);
  assert.equal(site.state.endsAt, '2030-01-01T00:00:00+00:00');
  assert.equal(finished.outputs['receipt-clean'], 'unknown');
  assert.equal(finished.outputs['changed-ids'], '');
  assert.equal(warnings(finished.stdout).length, 1);
  assert.match(warnings(finished.stdout)[0].data, /not the one this job started/);
  assert.match(warnings(finished.stdout)[0].data, /wp stillgate disable/);
  assert.match(warnings(finished.stdout)[0].data, /DELETE \/wp-json\/stillgate\/v1\/freeze/);
  assert.match(errors(finished.stdout)[0].data, /changed hands/);
  assert.match(finished.summary, /Cannot be confirmed/);
  assert.match(finished.summary, /not the one this job started/);
});

scenario('a freeze that changed hands is only reported when fail-on-changes is false', {}, async (site, job) => {
  await job.main(site);
  handOver(site);
  const finished = await job.post(site, { 'fail-on-changes': 'false' });
  assert.equal(finished.code, 0, finished.stdout);
  assert.equal(site.calls('DELETE', '/freeze').length, 0);
  assert.equal(finished.outputs['receipt-clean'], 'unknown');
  assert.equal(warnings(finished.stdout).length, 1);
});

scenario('a pipeline that extends the freeze moves only the end: it is still this job\'s freeze', {}, async (site, job) => {
  await job.main(site);
  site.state.scheduleEnd = '2026-09-20T23:15:00+00:00';
  site.state.endsAt = '2026-09-20T23:15:00+00:00';
  const finished = await job.post(site);
  assert.equal(finished.code, 0, finished.stdout);
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
  assert.equal(finished.outputs['receipt-clean'], 'true');
  assert.equal(site.state.active, false);
});

scenario('a freeze turned on by hand during the job is not ended, even though the old window is still stored', {}, async (site, job) => {
  await job.main(site);
  site.state.manual = true;
  const finished = await job.post(site);
  assert.equal(finished.code, 1);
  assert.equal(site.calls('DELETE', '/freeze').length, 0);
  assert.match(warnings(finished.stdout)[0].data, /turned on by hand/);
  assert.equal(site.state.active, true);
});

scenario('a freeze that is only active because wp-config.php forces it is not ended', {}, async (site, job) => {
  await job.main(site);
  site.state.scheduleStatus = 'expired';
  site.state.forced = true;
  const finished = await job.post(site);
  assert.equal(finished.code, 1);
  assert.equal(site.calls('DELETE', '/freeze').length, 0);
  assert.match(warnings(finished.stdout)[0].data, /not a running window/);
});

scenario('a freeze that may have started is matched by its start time, and another job\'s is left alone', {
  handler: startsThenReplies({ destroy: true }),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.state.started, 'maybe');
  handOver(site, { scheduleStart: isoSeconds(Date.now() - 3600000) });
  const finished = await job.post(site);
  assert.equal(finished.code, 1, finished.stdout);
  assert.equal(site.calls('DELETE', '/freeze').length, 0);
  assert.ok(warnings(finished.stdout).some((warning) => /not the one this job started/.test(warning.data)));
  assert.equal(site.state.active, true);
});

scenario('when the freeze status cannot be read the post step does not end anything', {}, async (site, job) => {
  await job.main(site);
  site.state.handler = (request) => (request.route === '/status'
    ? { status: 503, body: { code: 'busy', message: 'Try later', data: { status: 503 } } }
    : null);
  const finished = await job.post(site);
  assert.equal(finished.code, 1);
  assert.equal(site.calls('DELETE', '/freeze').length, 0);
  assert.equal(site.calls('GET', '/status').length, 4, 'one from main, three attempts in the post step');
  assert.match(warnings(finished.stdout)[0].data, /Could not read the freeze status.*will not end it/);
  assert.equal(finished.outputs['receipt-clean'], 'unknown');

  const lenient = await job.post(site, { 'fail-on-changes': 'false' });
  assert.equal(lenient.code, 0);
});

/** A mock site that starts the freeze but then answers the POST with `reply`. */
function startsThenReplies(reply) {
  return (request, state) => {
    if (request.method !== 'POST') {
      return null;
    }
    state.active = true;
    state.scheduleStatus = 'active';
    state.scheduleStart = isoSeconds(Date.now());
    state.scheduleEnd = '2026-09-20T22:30:00+00:00';
    state.endsAt = '2026-09-20T22:30:00+00:00';
    return reply;
  };
}

scenario('a POST that gets no answer is not retried, and the post step still tries to release', {
  handler: startsThenReplies({ destroy: true }),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.equal(site.calls('POST', '/freeze').length, 1);
  assert.equal(started.state.started, 'maybe');
  assert.match(errors(started.stdout)[0].data, /Could not reach the site|did not answer/);

  site.state.handler = null;
  const finished = await job.post(site);
  assert.equal(finished.code, 0, finished.stdout);
  assert.equal(site.calls('GET', '/receipt').length, 0);
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
  assert.equal(site.state.active, false, 'the freeze that may have started was ended');
  assert.equal(warnings(finished.stdout).length, 1);
  assert.equal(finished.outputs['receipt-clean'], 'unknown');
});

scenario('a POST that did not start anything leaves nothing to end', {
  handler: (request) => (request.method === 'POST' ? { destroy: true } : null),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.state.started, 'maybe');
  site.state.handler = null;
  const finished = await job.post(site);
  assert.equal(finished.code, 0, finished.stdout);
  assert.match(finished.stdout, /nothing to end/);
  assert.equal(site.calls('DELETE', '/freeze').length, 0);
});

scenario('the state is saved before the freeze request is sent', {
  handler: (request, state) => {
    if (request.method === 'POST') {
      state.stateWhenPosted = fs.readFileSync(state.stateFile, 'utf8');
    }
    return null;
  },
}, async (site, job) => {
  site.state.stateFile = job.files.state;
  const started = await job.main(site);
  assert.equal(started.code, 0, started.stdout);
  const before = parseCommandFile(site.state.stateWhenPosted);
  assert.equal(before.started, 'maybe');
  assert.equal(before.route, 'pretty');
  assert.ok(/^\d{13}$/.test(before.startedat), 'startedat is the runner time in milliseconds');
  assert.equal(started.state.started, 'true', 'and overwritten once the site accepted it');
});

for (const [label, reply] of [
  ['200 with HTML', { status: 200, body: '<html>notice: something printed before the JSON</html>' }],
  ['a 302 redirect', { status: 302, body: '', headers: { location: 'https://example.invalid/' } }],
  ['a 4xx without JSON', { status: 403, body: '<html>Blocked by the firewall</html>' }],
  ['a 502', { status: 502, body: '<html>Bad gateway</html>' }],
]) {
  scenario(`the freeze request answered with ${label} may have started a freeze: the post step ends it`, {
    handler: startsThenReplies(reply),
  }, async (site, job) => {
    const started = await job.main(site);
    assert.equal(started.code, 1);
    assert.equal(started.state.started, 'maybe');
    assert.equal(site.calls('POST', '/freeze').length, 1, 'a POST is never retried');
    assert.equal(site.state.active, true);

    site.state.handler = null;
    const finished = await job.post(site);
    assert.equal(finished.code, 0, finished.stdout);
    assert.equal(site.calls('DELETE', '/freeze').length, 1);
    assert.equal(site.state.active, false);
  });
}

scenario('a job cancelled while the freeze request is in flight still leaves the state to end the freeze', {
  handler: (request, state) => {
    if (request.method === 'POST') {
      state.active = true;
      state.scheduleStatus = 'active';
      state.scheduleStart = isoSeconds(Date.now());
      state.scheduleEnd = '2026-09-20T22:30:00+00:00';
      state.endsAt = '2026-09-20T22:30:00+00:00';
      return { hang: true };
    }
    return null;
  },
}, async (site, job) => {
  const step = job.startMain(site, {}, { STILLGATE_ACTION_TIMEOUT_MS: '60000' });
  const deadline = Date.now() + 10000;
  while (site.calls('POST', '/freeze').length === 0 && Date.now() < deadline) {
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
  assert.equal(site.calls('POST', '/freeze').length, 1, 'the request reached the site');
  step.child.kill('SIGTERM');
  const cancelled = await step.result;
  assert.ok(!cancelled.timedOut, 'the step ends when it is cancelled');
  assert.equal(cancelled.signal, null, 'it ended by itself, not by the signal');
  assert.equal(cancelled.code, 1);
  assert.match(errors(cancelled.stdout)[0].data, /cancelled/);
  const state = parseCommandFile(job.read('state'));
  assert.equal(state.started, 'maybe');
  assert.equal(parseCommandFile(job.read('output')).frozen, 'false');
  assert.equal(site.state.active, true, 'the freeze is still on');

  site.state.handler = null;
  const finished = await job.post(site);
  assert.equal(finished.code, 0, finished.stdout);
  assert.equal(site.calls('DELETE', '/freeze').length, 1);
  assert.equal(site.state.active, false, 'the post step ended it');
});

scenario('a job cancelled before the freeze request is sent starts nothing', {
  handler: (request) => (request.route === '/status' ? { hang: true } : null),
}, async (site, job) => {
  const step = job.startMain(site, {}, { STILLGATE_ACTION_TIMEOUT_MS: '60000' });
  const deadline = Date.now() + 10000;
  while (site.calls('GET', '/status').length === 0 && Date.now() < deadline) {
    await new Promise((resolve) => setTimeout(resolve, 20));
  }
  step.child.kill('SIGINT');
  const cancelled = await step.result;
  assert.ok(!cancelled.timedOut);
  assert.equal(cancelled.code, 1);
  assert.equal(site.calls('GET', '/status').length, 1, 'a cancelled request is not retried');
  assert.equal(site.calls('POST', '/freeze').length, 0);
  assert.equal(parseCommandFile(job.read('state')).started, undefined);
});

scenario('a 5xx on a GET is retried and then succeeds', {
  handler: (request, state) => {
    if (request.method === 'GET' && request.route === '/status') {
      state.statusCalls = (state.statusCalls || 0) + 1;
      if (state.statusCalls <= 2) {
        return { status: 503, body: { code: 'busy', message: 'Try later', data: { status: 503 } } };
      }
    }
    return null;
  },
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 0, started.stdout);
  assert.equal(site.calls('GET', '/status').length, 3);
});

scenario('a 5xx on a POST is not retried', {
  handler: (request) => (request.method === 'POST' ? { status: 503, body: { code: 'busy', message: 'Try later', data: { status: 503 } } } : null),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.equal(site.calls('POST', '/freeze').length, 1);
  assert.equal(started.state.started, 'maybe');
});

scenario('every request has a timeout', {
  handler: (request) => (request.method === 'GET' && request.route === '/status' ? { hang: true } : null),
}, async (site, job) => {
  const started = await job.run('main.js', { ...job.inputs(site), STILLGATE_ACTION_TIMEOUT_MS: '150', STILLGATE_ACTION_RETRY_DELAY_MS: '1' }, { killAfterMs: 8000 });
  assert.ok(!started.timedOut, 'the step must give up on its own: every request needs a time limit');
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /did not answer within/);
  assert.equal(site.calls('GET', '/status').length, 3);
});

scenario('a redirect on both forms is reported and never followed with the credentials', {
  handler: () => ({ status: 301, body: '', headers: { location: 'https://example.invalid/wp-json/stillgate/v1/status' } }),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /redirected.*example\.invalid.*https and www/);
  assert.equal(site.requests.length, 2, 'one try per form, and the Location is never requested');
});

scenario('plain permalinks: the redirect WordPress gives /wp-json/ falls back to ?rest_route=', {
  handler: (request) => (request.style === 'pretty'
    ? { status: 301, body: '', headers: { location: `http://127.0.0.1${request.url}/` } }
    : null),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 0, started.stdout);
  assert.equal(started.state.route, 'plain');
  const finished = await job.post(site);
  assert.equal(finished.code, 0, finished.stdout);
  assert.equal(site.requests.filter((r) => r.method === 'DELETE').map((r) => r.style).join(), 'plain', 'the release uses the remembered form straight away');
});

scenario('hostile server values cannot inject commands, outputs or summary lines', {
  handler: (request, state) => {
    const hostile = 'x\n::error::pwn\n::set-output name=evil::1\n##[error]pwn2\nghadelimiter_00000000';
    if (request.method === 'GET' && request.route === '/receipt') {
      return {
        status: 200,
        body: {
          state: hostile,
          opened_at: `2026-09-20T22:00:00+00:00\n::error::pwn`,
          clean: false,
          content: {
            changed_posts: '3\n::error::pwn',
            changed_ids: [1, `2\n::error::pwn`, 3, -4, 5.5, null],
            deleted_estimate: hostile,
            terms_changed: hostile,
            comments_added: 0,
          },
          paused: { comments: hostile, forms: 1 },
        },
      };
    }
    if (request.method === 'POST') {
      startsThenReplies(null)(request, state);
      return {
        status: 200,
        body: { active: true, ends_at: `2026-09-20T22:30:00+00:00\n::error::pwn`, schedule_start: `${isoSeconds(Date.now())}\n::error::pwn`, result: hostile },
      };
    }
    return null;
  },
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 0, started.stdout);
  assert.equal(started.outputs['ends-at'], '', 'a value that is not a timestamp is dropped');
  assert.equal(started.state.endsat, '');

  const finished = await job.post(site);
  assert.equal(finished.code, 1);
  assert.equal(finished.outputs['changed-ids'], '1,3');
  assert.equal(finished.outputs['receipt-clean'], 'false');

  const everything = [started.stdout, finished.stdout, finished.summary, job.read('output'), job.read('state')].join('\n');
  assert.ok(!everything.includes('::error::pwn'), 'hostile text must not survive intact');
  assert.ok(!everything.includes('set-output'), 'no set-output command is ever echoed');
  assert.ok(!/^##\[/m.test(everything));
  assert.ok(!finished.summary.includes('pwn'));
  for (const line of `${started.stdout}\n${finished.stdout}`.split('\n')) {
    if (line.startsWith('::')) {
      assert.match(line, /^::(add-mask|warning|error|notice)(?: [^:]*)?::/, `unexpected command: ${line}`);
    }
  }
});

scenario('hostile error text from the server stays on one harmless line', {
  handler: (request) => (request.method === 'POST'
    ? {
      status: 409,
      body: { code: 'bad\n::error::pwn', message: 'line one\n::error::pwn\nline three', data: { status: 409 } },
    }
    : null),
}, async (site, job) => {
  const started = await job.main(site);
  assert.equal(started.code, 1);
  assert.equal(errors(started.stdout).length, 1, 'only the action\'s own error annotation');
  assert.ok(!started.stdout.includes('::error::pwn'));
  assert.ok(!errors(started.stdout)[0].data.includes('\n'));
});

scenario('a value from the inputs cannot inject either', {}, async (site, job) => {
  const started = await job.main(site, { username: 'bot\n::error::pwn', message: 'hi\n::error::pwn' });
  assert.equal(started.code, 0, started.stdout);
  assert.ok(!started.stdout.includes('::error::pwn'));
});

scenario('invalid boolean and enum inputs are rejected before any request', {}, async (site, job) => {
  for (const overrides of [{ 'fail-on-changes': 'maybe' }, { release: 'yes' }, { 'if-already-frozen': 'ignore' }]) {
    const started = await job.main(site, overrides);
    assert.equal(started.code, 1, JSON.stringify(overrides));
  }
  assert.equal(site.requests.length, 0);
});

scenario('missing required inputs are named', {}, async (site, job) => {
  const started = await job.main(site, { username: '' });
  assert.equal(started.code, 1);
  assert.match(errors(started.stdout)[0].data, /username is required/);
});

scenario('state and outputs written with a random delimiter each time', {}, async (site, job) => {
  await job.main(site);
  const delimiters = job.read('state').split('\n').filter((line) => line.startsWith('ghadelimiter_'));
  assert.ok(delimiters.length >= 3);
  assert.equal(new Set(delimiters).size, delimiters.length, 'each value has its own delimiter');
  assert.ok(delimiters.every((line) => /^ghadelimiter_[0-9a-f-]{36}$/.test(line)));
});
