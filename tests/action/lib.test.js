'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const lib = require('../../action/lib.js');
const { SECRET, SECRET_COMPACT, parseCommandFile } = require('./helpers.js');

test('normalizeSiteUrl accepts https and local http only', () => {
  assert.equal(lib.normalizeSiteUrl('https://example.com'), 'https://example.com');
  assert.equal(lib.normalizeSiteUrl('https://example.com/'), 'https://example.com');
  assert.equal(lib.normalizeSiteUrl('https://example.com/blog///'), 'https://example.com/blog');
  assert.equal(lib.normalizeSiteUrl('http://localhost:8080'), 'http://localhost:8080');
  assert.equal(lib.normalizeSiteUrl('http://127.0.0.1:8080/'), 'http://127.0.0.1:8080');
  for (const bad of ['http://example.com', 'http://localhost.example.com', 'ftp://example.com', 'example.com', '', 'https://example.com/?a=1', 'https://u:p@example.com']) {
    assert.throws(() => lib.normalizeSiteUrl(bad), lib.ActionError, bad);
  }
});

test('rawInput keeps hyphens like the runner and accepts the underscore spelling', () => {
  assert.equal(lib.rawInput({ 'INPUT_SITE-URL': ' https://a.test ' }, 'site-url'), 'https://a.test');
  assert.equal(lib.rawInput({ INPUT_SITE_URL: 'https://b.test' }, 'site-url'), 'https://b.test');
  assert.equal(lib.rawInput({}, 'site-url'), undefined);
});

test('escapeData encodes what the runner decodes', () => {
  assert.equal(lib.escapeData('a%b\r\nc'), 'a%25b%0D%0Ac');
});

test('oneLine removes newlines, command prefixes and secrets', () => {
  const out = lib.oneLine('a\n::error::b\r\n##[warning]c d');
  assert.ok(!out.includes('\n') && !out.includes('\r'));
  assert.ok(!out.includes('::') && !out.includes('##['));
  assert.equal(lib.oneLine('x'.repeat(500), 10), 'xxxxxxxxxx...');
});

test('values survive the delimiter protocol, whatever they contain', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'stillgate-lib-'));
  try {
    const file = path.join(dir, 'out');
    fs.writeFileSync(file, '');
    const env = { GITHUB_OUTPUT: file };
    const nasty = 'one\n::error::two\nghadelimiter_fake\nname=value\n\nlast';
    lib.setOutput(env, 'a', nasty);
    lib.setOutput(env, 'b', '');
    lib.setOutput(env, 'c', 'plain');
    assert.deepEqual(parseCommandFile(fs.readFileSync(file, 'utf8')), { a: nasty, b: '', c: 'plain' });
    assert.throws(() => lib.setOutput(env, 'bad name\nx', 'v'));
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('writing to a runner file that is not configured is a no-op', () => {
  assert.doesNotThrow(() => lib.setOutput({}, 'a', 'b'));
  assert.doesNotThrow(() => lib.appendSummary({}, 'text'));
});

test('interpretReceipt keeps only well-formed values', () => {
  const view = lib.interpretReceipt({
    clean: false,
    state: 'closed',
    content: { changed_posts: 2, changed_ids: [4, '5', 6, -1, 1.5], terms_changed: true, comments_added: 'x' },
    paused: { comments: 3 },
  });
  assert.deepEqual(view.ids, [4, 6]);
  assert.equal(view.clean, 'false');
  assert.equal(view.comments, null);
  assert.equal(view.pausedComments, 3);
  assert.equal(view.pausedForms, null);
  assert.equal(lib.interpretReceipt(null).clean, 'unknown');
  assert.equal(lib.interpretReceipt({ clean: null }).clean, 'unknown');
  assert.equal(lib.interpretReceipt({ clean: true }).clean, 'true');
});

test('the summary table names every count and the export hint', () => {
  const view = lib.interpretReceipt({
    clean: false,
    content: { changed_posts: 1, changed_ids: [8], deleted_estimate: 0, terms_changed: false, comments_added: 0 },
    paused: { comments: 4, forms: 5 },
  });
  const markdown = lib.summaryMarkdown(view, { released: 'pending' });
  for (const text of ['| Posts, pages and media changed | 1 |', '| Visitor comments refused | 4 |', '| Form submissions refused | 5 |', 'wp export --post__in=8 --dir=/backups']) {
    assert.ok(markdown.includes(text), text);
  }
});

/** Run fn with the runner files in a temporary directory, always removed. */
function withRunnerFiles(fn) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'stillgate-lib-'));
  try {
    const env = {
      GITHUB_OUTPUT: path.join(dir, 'output'),
      GITHUB_STATE: path.join(dir, 'state'),
      GITHUB_STEP_SUMMARY: path.join(dir, 'summary'),
    };
    for (const file of Object.values(env)) {
      fs.writeFileSync(file, '');
    }
    fn(env, (name) => fs.readFileSync(env[name], 'utf8'));
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
}

/** addSecret prints ::add-mask:: to stdout; keep it out of the test report. */
function quietly(fn) {
  const write = process.stdout.write;
  const written = [];
  process.stdout.write = (chunk) => {
    written.push(String(chunk));
    return true;
  };
  try {
    fn();
  } finally {
    process.stdout.write = write;
  }
  return written.join('');
}

test('addSecret asks the runner to mask the value, each line of it, once', () => {
  const printed = quietly(() => {
    lib.addSecret('first line\nsecond line');
    lib.addSecret('first line\nsecond line');
  });
  assert.equal(printed, '::add-mask::first line%0Asecond line\n::add-mask::first line\n::add-mask::second line\n');
});

test('a registered secret is removed from the job summary, the outputs and the saved state', () => {
  quietly(() => {
    lib.addSecret(SECRET);
    lib.addSecret(SECRET_COMPACT);
  });
  withRunnerFiles((env, read) => {
    const hostile = [`before ${SECRET} after`, `compact ${SECRET_COMPACT}`, `two\nlines ${SECRET}\nand ${SECRET_COMPACT}`];
    for (const text of hostile) {
      lib.appendSummary(env, `### Title\n\n${text}`);
      lib.setOutput(env, 'some-output', text);
      lib.saveState(env, 'some-state', text);
    }
    for (const file of ['GITHUB_STEP_SUMMARY', 'GITHUB_OUTPUT', 'GITHUB_STATE']) {
      const written = read(file);
      assert.ok(!written.includes(SECRET), `${file} leaked the password`);
      assert.ok(!written.includes(SECRET_COMPACT), `${file} leaked the compact password`);
      assert.ok(written.includes('***'), `${file} should show where it was removed`);
    }
    assert.deepEqual(parseCommandFile(read('GITHUB_OUTPUT')), { 'some-output': 'two\nlines ***\nand ***' });
  });
});

test('appendKeyValue refuses a name that could add a second entry', () => {
  withRunnerFiles((env) => {
    assert.throws(() => lib.setOutput(env, 'a<<b', 'x'));
    assert.throws(() => lib.saveState(env, 'a=b\nc', 'x'));
  });
});

test('readConfig masks the password, its compact form and the Authorization value before anything else', () => {
  const printed = quietly(() => {
    lib.readConfig({
      'INPUT_SITE-URL': 'https://example.com',
      INPUT_USERNAME: 'robot',
      'INPUT_APP-PASSWORD': 'wxyz wxyz wxyz wxyz wxyz wxyz',
    }, { forStart: false });
  });
  const base64 = Buffer.from('robot:wxyz wxyz wxyz wxyz wxyz wxyz').toString('base64');
  assert.ok(printed.startsWith('::add-mask::wxyz wxyz wxyz wxyz wxyz wxyz\n::add-mask::wxyzwxyzwxyzwxyzwxyzwxyz\n'));
  assert.ok(printed.includes(`::add-mask::${base64}\n`));
  assert.equal(lib.redact(`Basic ${base64} / wxyzwxyzwxyzwxyzwxyzwxyz`), 'Basic *** / ***');
});

test('readConfig takes if-already-frozen fail, keep or replace and nothing else', () => {
  const env = (value) => ({
    'INPUT_SITE-URL': 'https://example.com',
    INPUT_USERNAME: 'robot',
    'INPUT_APP-PASSWORD': 'pw pw pw',
    'INPUT_IF-ALREADY-FROZEN': value,
  });
  quietly(() => {
    assert.equal(lib.readConfig(env(''), { forStart: false }).ifAlreadyFrozen, 'fail');
    for (const value of ['fail', 'keep', 'replace', 'REPLACE']) {
      assert.equal(lib.readConfig(env(value), { forStart: false }).ifAlreadyFrozen, value.toLowerCase());
    }
    assert.throws(() => lib.readConfig(env('overwrite'), { forStart: false }), lib.ActionError);
  });
});

test('classifyFreeze: which active freeze is this job\'s', () => {
  const start = '2026-09-20T22:00:00+00:00';
  const startMs = Date.parse(start);
  const active = (overrides = {}) => ({
    active: true,
    manual_enabled: false,
    schedule_status: 'active',
    schedule_start: start,
    schedule_end: '2026-09-20T22:30:00+00:00',
    ...overrides,
  });
  const kind = (status, saved) => lib.classifyFreeze(status, saved).kind;
  const known = { started: 'true', windowStart: start, startedAt: startMs - 1500 };

  assert.equal(kind({ active: false }, known), 'ended');
  assert.equal(kind(active(), known), 'ours');
  assert.equal(kind(active({ schedule_end: '2026-09-21T09:00:00+00:00' }), known), 'ours', 'extending moves the end only');
  assert.equal(kind(active({ schedule_start: '2026-09-20T22:00:01+00:00' }), known), 'foreign');
  assert.equal(kind(active({ schedule_start: null }), known), 'foreign', 'a freeze with no window is not a timed freeze');
  assert.equal(kind(active({ manual_enabled: true }), known), 'foreign');
  assert.equal(kind(active({ schedule_status: 'expired' }), known), 'foreign');
  assert.equal(kind(active({ schedule_start: '2026-09-20T22:00:00Z' }), known), 'ours', 'the same instant written another way');
  // The site never returned a start: fall back to the runner time.
  const unknownStart = { started: 'true', windowStart: '', startedAt: startMs - 1500 };
  assert.equal(kind(active(), unknownStart), 'ours');
  assert.equal(kind(active({ schedule_start: '2026-09-20T22:20:00+00:00' }), unknownStart), 'foreign');

  const maybe = (offsetMs) => ({ started: 'maybe', windowStart: '', startedAt: startMs + offsetMs });
  assert.equal(kind(active(), maybe(0)), 'ours');
  assert.equal(kind(active(), maybe(299000)), 'ours', 'inside the 5 minute tolerance');
  assert.equal(kind(active(), maybe(-299000)), 'ours');
  assert.equal(kind(active(), maybe(301000)), 'foreign', 'outside it');
  assert.equal(kind(active(), maybe(-301000)), 'foreign');
  assert.equal(kind(active(), { started: 'maybe', windowStart: '', startedAt: Number.NaN }), 'foreign', 'no runner time to compare');
  assert.equal(kind(active({ manual_enabled: true }), maybe(0)), 'foreign');
  assert.ok(lib.classifyFreeze(active({ schedule_start: '2026-09-20T23:00:00+00:00' }), known).why.includes('2026-09-20T23:00:00+00:00'));
});
