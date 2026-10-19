'use strict';

/*
 * Shared code for the Stillgate GitHub Action. No dependencies: Node
 * built-ins and the global fetch only, so the files in the repository are
 * exactly what runs.
 *
 * main.js starts a freeze, post.js reads the receipt and ends it. State
 * travels between them through the runner's GITHUB_STATE file (read back as
 * STATE_<name> environment variables in the post step). The runner applies
 * the file in order and a later line for the same name replaces an earlier
 * one (SaveStateFileCommand in actions/runner), which is how "started" goes
 * from maybe to true or false.
 *
 * State saved by main.js:
 *   started      maybe  saved BEFORE the freeze request is sent
 *                true   the site accepted it
 *                false  the site refused it with a JSON 4xx answer, so
 *                       nothing was started
 *   startedat    runner clock, epoch milliseconds, taken before the request
 *   route        pretty (/wp-json/) or plain (?rest_route=)
 *   windowstart  schedule_start the site returned for the freeze it started
 *   endsat       ends_at the site returned
 * The post step ends a freeze only when it can show the active freeze is the
 * one saved here (see classifyFreeze).
 *
 * Environment variables for tests only. They are not inputs, are not in
 * action.yml and are not part of any contract:
 *   STILLGATE_ACTION_TIMEOUT_MS      per-request time limit, default 20000
 *   STILLGATE_ACTION_RETRY_DELAY_MS  base delay before a retried GET or
 *                                    DELETE, default 1000 (multiplied by the
 *                                    attempt number)
 */

const fs = require('node:fs');
const crypto = require('node:crypto');

const USER_AGENT = 'stillgate-github-action';
const MAX_LISTED_IDS = 50;
// The plugin lists at most 200 changed post IDs; the output never carries more.
const MAX_OUTPUT_IDS = 200;
const MAX_LOG_TEXT = 300;
// How far the runner clock and the site clock may disagree when a freeze is
// matched to its start time (see classifyFreeze).
const CLOCK_TOLERANCE_MS = 5 * 60 * 1000;
const ISO_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/;

/** An expected failure with a message that is safe and useful to print. */
class ActionError extends Error {}

/* ---------------------------------------------------------------------- *
 * Secrets, and everything that is printed
 * ---------------------------------------------------------------------- */

const secrets = new Set();

/**
 * Register a value as secret: tell the runner to mask it, and remember it so
 * that redact() can remove it from anything this process writes itself.
 */
function addSecret(value) {
  for (const candidate of [value, ...String(value).split(/\r?\n/)]) {
    if (candidate === '' || secrets.has(candidate)) {
      continue;
    }
    secrets.add(candidate);
    process.stdout.write(`::add-mask::${escapeData(candidate)}\n`);
  }
}

/** Replace every registered secret in a string. Longest first. */
function redact(text) {
  let result = String(text);
  for (const secret of [...secrets].sort((a, b) => b.length - a.length)) {
    result = result.split(secret).join('***');
  }
  return result;
}

/**
 * Make text that may come from the server or from inputs safe to print: no
 * secrets, one line, no control characters, and nothing the runner could
 * read as a workflow command (a line starting with "::" or "##[").
 */
function oneLine(text, limit = MAX_LOG_TEXT) {
  let result = redact(text)
    .replace(/[\x00-\x1f\x7f\x85\u2028\u2029]+/g, ' ')
    .replace(/::/g, ': :')
    .replace(/##\[/g, '# #[')
    .trim();
  if (result.length > limit) {
    result = `${result.slice(0, limit)}...`;
  }
  return result;
}

/** Workflow command escaping, as the runner defines it for the data part. */
function escapeData(value) {
  return String(value).replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A');
}

function info(message) {
  process.stdout.write(`${oneLine(message, 2000)}\n`);
}

function warning(message) {
  process.stdout.write(`::warning::${escapeData(oneLine(message, 2000))}\n`);
}

function notice(message) {
  process.stdout.write(`::notice::${escapeData(oneLine(message, 2000))}\n`);
}

function fail(message) {
  process.stdout.write(`::error::${escapeData(oneLine(message, 2000))}\n`);
  process.exitCode = 1;
}

/* ---------------------------------------------------------------------- *
 * Runner files: GITHUB_OUTPUT, GITHUB_STATE, GITHUB_STEP_SUMMARY
 * ---------------------------------------------------------------------- */

/**
 * Append name=value to a runner file using the multiline delimiter
 * protocol with a random delimiter, so any value is stored as data.
 */
function appendKeyValue(env, variable, name, value) {
  const file = env[variable];
  if (!file) {
    return false;
  }
  if (!/^[A-Za-z0-9_.-]+$/.test(name)) {
    throw new Error(`Invalid name for ${variable}`);
  }
  const text = redact(value);
  const delimiter = `ghadelimiter_${crypto.randomUUID()}`;
  if (text.includes(delimiter) || name.includes(delimiter)) {
    throw new Error(`Unexpected delimiter in a value for ${variable}`);
  }
  fs.appendFileSync(file, `${name}<<${delimiter}\n${text}\n${delimiter}\n`);
  return true;
}

function setOutput(env, name, value) {
  appendKeyValue(env, 'GITHUB_OUTPUT', name, String(value));
}

function saveState(env, name, value) {
  appendKeyValue(env, 'GITHUB_STATE', name, String(value));
}

function appendSummary(env, markdown) {
  const file = env.GITHUB_STEP_SUMMARY;
  if (!file) {
    return;
  }
  fs.appendFileSync(file, `${redact(markdown)}\n`);
}

/* ---------------------------------------------------------------------- *
 * Inputs
 * ---------------------------------------------------------------------- */

/**
 * The runner turns an input id into INPUT_<ID> by upper-casing it and
 * replacing spaces; hyphens stay (INPUT_SITE-URL). The underscore spelling
 * is accepted too, for shells that cannot export a name with a hyphen.
 * Returns undefined when the input was not supplied at all.
 */
function rawInput(env, name) {
  const key = `INPUT_${name.replace(/ /g, '_').toUpperCase()}`;
  const value = env[key] !== undefined ? env[key] : env[key.replace(/-/g, '_')];
  return value === undefined ? undefined : String(value).trim();
}

function textInput(env, name, fallback = '') {
  const value = rawInput(env, name);
  return value === undefined || value === '' ? fallback : value;
}

function booleanInput(env, name, fallback) {
  const value = textInput(env, name, '').toLowerCase();
  if (value === '') {
    return fallback;
  }
  if (value === 'true') {
    return true;
  }
  if (value === 'false') {
    return false;
  }
  throw new ActionError(`The input ${name} must be true or false.`);
}

/**
 * Only https is accepted, because Application Passwords need it, except for
 * a local site. The URL is never echoed back: it could contain credentials.
 */
function normalizeSiteUrl(raw) {
  let url;
  try {
    url = new URL(raw);
  } catch (error) {
    throw new ActionError('site-url is not a valid URL. Use the full address, for example https://example.com.');
  }
  if (url.protocol !== 'https:' && url.protocol !== 'http:') {
    throw new ActionError('site-url must start with https://.');
  }
  if (url.protocol === 'http:' && url.hostname !== 'localhost' && url.hostname !== '127.0.0.1') {
    throw new ActionError(
      'site-url must use https://. Application Passwords are only available over HTTPS (http is accepted for localhost and 127.0.0.1 only).'
    );
  }
  if (url.username !== '' || url.password !== '') {
    throw new ActionError('site-url must not contain a user name or password. Use the username and app-password inputs.');
  }
  if (url.search !== '' || url.hash !== '') {
    throw new ActionError('site-url must be the address of the site, without a query string or fragment.');
  }
  return `${url.origin}${url.pathname.replace(/\/+$/, '')}`;
}

/**
 * Read and validate the inputs. The Application Password is read and
 * masked first, before anything else can print.
 */
function readConfig(env, { forStart }) {
  const appPassword = rawInput(env, 'app-password') || '';
  if (appPassword !== '') {
    addSecret(appPassword);
    const compact = appPassword.replace(/\s+/g, '');
    if (compact !== appPassword) {
      addSecret(compact);
    }
  }

  const siteUrlInput = rawInput(env, 'site-url') || '';
  const username = rawInput(env, 'username') || '';
  if (siteUrlInput === '') {
    throw new ActionError('The input site-url is required.');
  }
  if (username === '') {
    throw new ActionError('The input username is required.');
  }
  if (appPassword === '') {
    throw new ActionError('The input app-password is required. Create an Application Password for the user and store it as a secret.');
  }
  const siteUrl = normalizeSiteUrl(siteUrlInput);

  // The Authorization header value is a secret too.
  addSecret(Buffer.from(`${username}:${appPassword}`).toString('base64'));

  const ifAlreadyFrozen = textInput(env, 'if-already-frozen', 'fail').toLowerCase();
  if (ifAlreadyFrozen !== 'fail' && ifAlreadyFrozen !== 'keep' && ifAlreadyFrozen !== 'replace') {
    throw new ActionError('The input if-already-frozen must be fail, keep or replace.');
  }

  // An empty duration is an error, never a default: a pipeline whose
  // variable is unset must not start a freeze nobody chose.
  const durationRaw = rawInput(env, 'duration');
  const duration = durationRaw === undefined ? '30m' : durationRaw;
  if (forStart && duration === '') {
    throw new ActionError('The input duration is empty. Use a duration such as 30m, 2h or 1h30m.');
  }

  return {
    siteUrl,
    username,
    appPassword,
    duration,
    message: textInput(env, 'message', ''),
    ifAlreadyFrozen,
    failOnChanges: booleanInput(env, 'fail-on-changes', true),
    release: booleanInput(env, 'release', true),
    timeoutMs: positiveInt(env.STILLGATE_ACTION_TIMEOUT_MS, 20000),
    retryDelayMs: nonNegativeInt(env.STILLGATE_ACTION_RETRY_DELAY_MS, 1000),
  };
}

function positiveInt(value, fallback) {
  const number = Number.parseInt(value, 10);
  return Number.isFinite(number) && number > 0 ? number : fallback;
}

function nonNegativeInt(value, fallback) {
  const number = Number.parseInt(value, 10);
  return Number.isFinite(number) && number >= 0 ? number : fallback;
}

/* ---------------------------------------------------------------------- *
 * HTTP
 * ---------------------------------------------------------------------- */

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The time limit of one request, and the job being cancelled (if wired). */
function requestSignal(config) {
  const limit = AbortSignal.timeout(config.timeoutMs);
  return config.cancel ? AbortSignal.any([limit, config.cancel]) : limit;
}

function routeUrl(config, style, route) {
  if (style === 'plain') {
    return `${config.siteUrl}/?rest_route=/stillgate/v1${route}`;
  }
  return `${config.siteUrl}/wp-json/stillgate/v1${route}`;
}

function describeNetworkError(error, config) {
  if (config.cancel && config.cancel.aborted) {
    return 'The job was cancelled while a request to the site was in progress.';
  }
  if (error && error.name === 'TimeoutError') {
    return `The site did not answer within ${Math.round(config.timeoutMs / 1000)} seconds.`;
  }
  const code = error && error.cause && (error.cause.code || error.cause.message);
  return `Could not reach the site${code ? ` (${oneLine(code, 80)})` : ''}. Check site-url and that the site is reachable from the runner.`;
}

/**
 * One request. Only GET and DELETE are retried (twice, on a network error
 * or a 5xx answer); a POST is sent once. Redirects are not followed, so the
 * Authorization header can never travel to another address.
 *
 * Resolves with { status, contentType, location, json, text }; json is null
 * when the body was not JSON. Rejects with an ActionError carrying
 * `networkError = true` when no answer arrived.
 */
async function send(config, method, url, body) {
  const idempotent = method === 'GET' || method === 'DELETE';
  const attempts = idempotent ? 3 : 1;
  const headers = {
    Accept: 'application/json',
    Authorization: `Basic ${Buffer.from(`${config.username}:${config.appPassword}`).toString('base64')}`,
    'User-Agent': USER_AGENT,
  };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  let lastError;
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      const response = await fetch(url, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        redirect: 'manual',
        signal: requestSignal(config),
      });
      const text = await response.text();
      if (response.status >= 500 && attempt < attempts) {
        await sleep(config.retryDelayMs * attempt);
        continue;
      }
      let json = null;
      try {
        const parsed = JSON.parse(text);
        json = parsed !== null && typeof parsed === 'object' ? parsed : null;
      } catch (error) {
        json = null;
      }
      return {
        status: response.status,
        contentType: response.headers.get('content-type') || '',
        location: response.headers.get('location') || '',
        json,
        text,
      };
    } catch (error) {
      lastError = error;
      if (config.cancel && config.cancel.aborted) {
        break;
      }
      if (attempt < attempts) {
        await sleep(config.retryDelayMs * attempt);
      }
    }
  }
  const failure = new ActionError(describeNetworkError(lastError, config));
  failure.networkError = true;
  throw failure;
}

/** The one-line, actionable explanation of an answer that was not a success. */
function explain(response, config) {
  const { status, json } = response;
  const code = json && typeof json.code === 'string' ? json.code : '';
  const serverMessage = json && typeof json.message === 'string' ? oneLine(json.message, 200) : '';

  if (status >= 300 && status < 400) {
    const where = response.location ? ` to ${oneLine(response.location, 200)}` : '';
    return `The site redirected the request${where} (HTTP ${status}). Set site-url to the final address (check https and www); redirects are not followed so that the credentials stay with the address you gave.`;
  }
  if (status === 404) {
    return 'HTTP 404: the Stillgate REST routes were not found, at /wp-json/ or at ?rest_route=. Stillgate may not be active, or the REST API is disabled by a security plugin or server rule.';
  }
  if (json === null) {
    const type = oneLine(response.contentType.split(';')[0] || 'no content type', 60);
    return `The site answered HTTP ${status} with ${type} instead of JSON. A firewall, CDN challenge, maintenance page or PHP output in front of the REST API is probably in the way; allow requests to /wp-json/stillgate/v1/ from the runner.`;
  }
  if (status === 401) {
    return `HTTP 401: the credentials were not accepted${code ? ` (${oneLine(code, 60)})` : ''}. Check username and app-password: it must be an Application Password, not the account password. Application Passwords need HTTPS (or WP_ENVIRONMENT_TYPE set to local), must not be disabled by a plugin, and the host must pass the Authorization header to PHP.`;
  }
  if (status === 403) {
    if (code === 'stillgate_forbidden' || code === 'rest_forbidden') {
      return `HTTP 403: the user "${oneLine(config.username, 80)}" is not allowed to do that. It needs manage_options and the deployment_guard_bypass capability (administrators have both).`;
    }
    return `HTTP 403${code ? ` (${oneLine(code, 60)})` : ''}: the request was refused${serverMessage ? `: ${serverMessage}` : ''}. A security plugin or firewall may be blocking REST requests, or the user lacks the deployment_guard_bypass capability.`;
  }
  if (status === 400 && code === 'invalid_duration') {
    return `HTTP 400 invalid_duration: ${serverMessage || 'the duration was not understood'} The duration input is "${oneLine(config.duration, 40)}".`;
  }
  if (status === 409) {
    return `HTTP 409${code ? ` ${oneLine(code, 60)}` : ''}: the request conflicts with the state of the site${serverMessage ? `: ${serverMessage}` : '.'}`;
  }
  if (status >= 500) {
    return `HTTP ${status}: the site failed to handle the request${serverMessage ? `: ${serverMessage}` : '.'}`;
  }
  return `HTTP ${status}${code ? ` ${oneLine(code, 60)}` : ''}${serverMessage ? `: ${serverMessage}` : ''}`;
}

/** Call a route; any answer that is not a 2xx JSON object is an ActionError. */
async function call(config, style, method, route, body) {
  const response = await send(config, method, routeUrl(config, style, route), body);
  if (response.status < 200 || response.status >= 300 || response.json === null) {
    const failure = new ActionError(explain(response, config));
    failure.status = response.status;
    // The site itself answered with a JSON error and a 4xx status: the
    // request was understood and refused, so it changed nothing.
    failure.refused = response.status >= 400 && response.status < 500 && response.json !== null;
    throw failure;
  }
  return response.json;
}

/**
 * Find the REST base: /wp-json/ first, and on a 404 (or a redirect) the
 * ?rest_route= form that works without pretty permalinks. Returns the style that worked and
 * the status document it answered with.
 */
async function discover(config) {
  let last;
  for (const style of ['pretty', 'plain']) {
    const response = await send(config, 'GET', routeUrl(config, style, '/status'));
    // A 404 is the answer when /wp-json/ is not routed. WordPress with plain
    // permalinks answers it with a redirect to the same address plus a
    // trailing slash, which is just as much a "not here"; the second form
    // tells the truth, and if that redirects as well it is reported.
    if (response.status === 404 || (response.status >= 300 && response.status < 400)) {
      last = response;
      continue;
    }
    if (response.status < 200 || response.status >= 300 || response.json === null) {
      throw new ActionError(explain(response, config));
    }
    return { style, status: response.json };
  }
  throw new ActionError(explain(last, config));
}

/* ---------------------------------------------------------------------- *
 * Reading what the server said
 * ---------------------------------------------------------------------- */

/** A timestamp from the server, or '' if it does not look like one. */
function timestamp(value) {
  return typeof value === 'string' && ISO_TIME.test(value) ? value : '';
}

function count(value) {
  return Number.isSafeInteger(value) && value >= 0 ? value : null;
}

function show(value) {
  return value === null ? 'unknown' : String(value);
}

function interpretReceipt(receipt) {
  const content = receipt && typeof receipt.content === 'object' && receipt.content !== null ? receipt.content : {};
  const paused = receipt && typeof receipt.paused === 'object' && receipt.paused !== null ? receipt.paused : {};
  const ids = Array.isArray(content.changed_ids) ? content.changed_ids.filter((id) => Number.isSafeInteger(id) && id > 0) : [];
  let clean = 'unknown';
  if (receipt && receipt.clean === true) {
    clean = 'true';
  } else if (receipt && receipt.clean === false) {
    clean = 'false';
  }
  return {
    clean,
    state: receipt && ['none', 'open', 'closed'].includes(receipt.state) ? receipt.state : 'unknown',
    openedAt: timestamp(receipt && receipt.opened_at),
    closedAt: timestamp(receipt && receipt.closed_at),
    changedPosts: count(content.changed_posts),
    ids,
    idsTruncated: content.ids_truncated === true,
    deleted: count(content.deleted_estimate),
    terms: content.terms_changed === true ? 'yes' : content.terms_changed === false ? 'no' : 'unknown',
    comments: count(content.comments_added),
    pausedComments: count(paused.comments),
    pausedForms: count(paused.forms),
  };
}

function summaryMarkdown(view, { released }) {
  const verdict = {
    true: 'Clean: nothing changed while the freeze was on.',
    false: 'Content changed while the freeze was on.',
    unknown: 'Cannot be confirmed: nothing can be promised about this freeze.',
  }[view.clean];

  const lines = [
    '### Stillgate freeze receipt',
    '',
    verdict,
    '',
    '| What | Count |',
    '| --- | --- |',
    `| Posts, pages and media changed | ${show(view.changedPosts)} |`,
    `| Posts deleted (estimate) | ${show(view.deleted)} |`,
    `| Terms changed | ${view.terms} |`,
    `| Comments added | ${show(view.comments)} |`,
    `| Visitor comments refused | ${show(view.pausedComments)} |`,
    `| Form submissions refused | ${show(view.pausedForms)} |`,
    '',
  ];

  if (view.openedAt) {
    lines.push(`Freeze began: ${view.openedAt}${view.closedAt ? `, ended: ${view.closedAt}` : ', still on when the receipt was read'}.`, '');
  }
  if (view.ids.length > 0) {
    const shown = view.ids.slice(0, MAX_LISTED_IDS);
    const hidden = view.ids.length - shown.length;
    lines.push(
      `Changed post IDs: ${shown.join(', ')}${hidden > 0 ? ` and ${hidden} more` : ''}${view.idsTruncated ? ' (the receipt lists at most 200)' : ''}`,
      '',
      'To review them on the server:',
      '',
      '```',
      `wp export --post__in=${shown.join(',')} --dir=/backups`,
      '```',
      ''
    );
    if (hidden > 0 || view.idsTruncated) {
      lines.push('`wp stillgate drift` prints the full list.', '');
    }
  }
  if (released === 'left') {
    lines.push('The freeze was left on (release is false). It ends by itself at its end time, or when someone ends it.');
  }
  return lines.join('\n').trimEnd();
}

/** The summary written when there is no receipt that can be trusted. */
function unknownSummary(reason) {
  return ['### Stillgate freeze receipt', '', 'Cannot be confirmed: nothing can be promised about content changes during this job\'s freeze.', '', reason].join('\n');
}

/* ---------------------------------------------------------------------- *
 * Main step
 * ---------------------------------------------------------------------- */

/**
 * What to do about a freeze, or a scheduled window, that is already on the
 * site. Returns 'proceed' (go on and start this job's freeze), 'leave' (an
 * active freeze stays as it is and this job does nothing), or throws.
 */
function handleExisting(config, status) {
  const active = status.active === true;
  const upcoming = !active && status.schedule_status === 'upcoming';
  if (!active && !upcoming) {
    return 'proceed';
  }

  if (active) {
    const until = timestamp(status.ends_at);
    const untilText = until ? ` (until ${until})` : ' (it has no end time)';
    if (config.ifAlreadyFrozen === 'keep') {
      notice(`A freeze is already active${until ? ` until ${until}` : ''}. Leaving it as it is; this job will not end it.`);
      return 'leave';
    }
    if (config.ifAlreadyFrozen === 'replace') {
      notice(`A freeze is already active${until ? ` until ${until}` : ''}. if-already-frozen is replace, so this job's freeze takes its place and ending it at the end of the job ends the freeze for good. The receipt of that earlier freeze is kept, so changes made during it count when the job checks the receipt.`);
      return 'proceed';
    }
    throw new ActionError(
      `A freeze is already active on the site${untilText}. Wait for it to end, set if-already-frozen to keep to continue without touching it, or to replace to take it over.`
    );
  }

  const start = timestamp(status.schedule_start);
  const end = timestamp(status.schedule_end);
  const window = `starts ${start || 'at an unknown time'}, ends ${end || 'at an unknown time'}`;
  if (config.ifAlreadyFrozen === 'replace') {
    notice(`A scheduled freeze window is set on the site (${window}). if-already-frozen is replace, so this job's freeze takes its place and the window is gone once the job ends it.`);
    return 'proceed';
  }
  if (config.ifAlreadyFrozen === 'keep') {
    throw new ActionError(
      `A scheduled freeze window is set on the site (${window}) but has not begun. if-already-frozen is keep, which only leaves a freeze that is already active alone, and nothing is active now, so the job would continue without a freeze. Set if-already-frozen to replace to take the window over, or wait until it is over.`
    );
  }
  throw new ActionError(
    `A scheduled freeze window is set on the site (${window}) but has not begun. Starting a freeze would replace it, and ending that freeze would remove it. Wait until the window is over, or set if-already-frozen to replace to let this job take it over.`
  );
}

async function runMain(env = process.env) {
  let frozen = false;
  let endsAt = '';

  // A cancelled job gets SIGINT, then SIGTERM, then SIGKILL. The state is
  // written before the request that starts the freeze is sent, so there is
  // nothing left to flush here; the handler stops waiting for the site so the
  // step ends on its own and the outputs are written.
  const cancel = new AbortController();
  const onSignal = () => {
    cancel.abort();
    setTimeout(() => process.exit(1), 3000).unref();
  };
  process.once('SIGINT', onSignal);
  process.once('SIGTERM', onSignal);

  try {
    const config = readConfig(env, { forStart: true });
    config.cancel = cancel.signal;
    const found = await discover(config);
    const style = found.style;

    if (handleExisting(config, found.status) === 'leave') {
      endsAt = timestamp(found.status.ends_at);
      return;
    }

    // The message is always sent: an empty one clears the message of an
    // earlier freeze, so it is never shown for this one.
    const body = { for: config.duration, message: config.message };

    // Saved before the request is sent. If the job is cancelled or the
    // runner dies while the request is in flight, the post step still knows
    // that a freeze may have started, and when.
    const startedAt = Date.now();
    saveState(env, 'started', 'maybe');
    saveState(env, 'startedat', startedAt);
    saveState(env, 'route', style);

    let answer;
    try {
      answer = await call(config, style, 'POST', '/freeze', body);
    } catch (error) {
      // Only a JSON 4xx answer proves that nothing was started. Anything
      // else (no answer, a 5xx, a redirect, a body that is not the expected
      // JSON) may have reached the site and left a freeze behind, so the
      // state stays at "maybe".
      if (error.refused) {
        saveState(env, 'started', 'false');
      }
      throw error;
    }

    const windowStart = timestamp(answer.schedule_start);
    endsAt = timestamp(answer.ends_at);
    saveState(env, 'started', 'true');
    saveState(env, 'windowstart', windowStart);
    saveState(env, 'endsat', endsAt);
    frozen = true;

    if (answer.active !== true) {
      throw new ActionError('The site accepted the request but reports no active freeze (a freeze forced from wp-config.php, or another plugin, may interfere). This job will still try to end what it started.');
    }

    info(`Freeze started${endsAt ? `, ends at ${endsAt}` : ''}. It ends by itself if this job never gets to release it.`);
  } catch (error) {
    fail(error instanceof ActionError ? error.message : `Unexpected error: ${error && error.message ? error.message : error}`);
  } finally {
    process.removeListener('SIGINT', onSignal);
    process.removeListener('SIGTERM', onSignal);
    try {
      setOutput(env, 'frozen', frozen ? 'true' : 'false');
      setOutput(env, 'ends-at', endsAt);
    } catch (error) {
      fail(`Could not write the outputs: ${error.message}`);
    }
  }
}

/** Run a step that must never stop the release; report why it failed. */
function quietly(fn) {
  try {
    fn();
  } catch (error) {
    warning(`Could not write the job summary: ${error.message}`);
  }
}

/* ---------------------------------------------------------------------- *
 * Post step
 * ---------------------------------------------------------------------- */

/**
 * Is the freeze on the site the one the main step started?
 *
 * Returns { kind: 'ended' } when no freeze is active, { kind: 'ours' }, or
 * { kind: 'foreign', why } for an active freeze this job cannot show to be
 * its own. The post step ends only 'ours'.
 *
 * A freeze started by the action is a timed freeze, stored as a window that
 * begins when it is started. Its start does not change when a pipeline
 * extends it (only the end moves), so the start is the identity:
 *   started=true   schedule_start equals the one the site returned
 *   started=maybe  there was no answer to read it from, so schedule_start
 *                  must fall within CLOCK_TOLERANCE_MS of the runner time
 *                  saved before the request (the two clocks may disagree)
 * A freeze that was turned on by hand, or is only active because
 * wp-config.php forces it, is never ours.
 */
function classifyFreeze(status, saved) {
  if (status.active !== true) {
    return { kind: 'ended' };
  }
  if (status.manual_enabled === true) {
    return { kind: 'foreign', why: 'a freeze was turned on by hand while this job was running' };
  }
  const siteStart = timestamp(status.schedule_start);
  if (siteStart === '') {
    return { kind: 'foreign', why: 'it is not a timed freeze' };
  }
  if (typeof status.schedule_status === 'string' && status.schedule_status !== 'active') {
    return { kind: 'foreign', why: 'it is not a running window (for example a freeze forced from wp-config.php)' };
  }
  const siteMs = Date.parse(siteStart);
  if (saved.started === 'true' && saved.windowStart !== '') {
    if (siteMs === Date.parse(saved.windowStart)) {
      return { kind: 'ours' };
    }
    return { kind: 'foreign', why: `it began at ${siteStart}, this job's freeze began at ${saved.windowStart}` };
  }
  if (Number.isFinite(saved.startedAt) && Math.abs(siteMs - saved.startedAt) <= CLOCK_TOLERANCE_MS) {
    return { kind: 'ours' };
  }
  return { kind: 'foreign', why: `it began at ${siteStart}, which does not match when this job sent its request` };
}

const END_HINT = 'It ends by itself at its end time, or end it with wp stillgate disable.';

async function runPost(env = process.env) {
  const started = env.STATE_started || '';
  if (started !== 'true' && started !== 'maybe') {
    info('This job did not start a freeze, so there is nothing to end.');
    return;
  }

  let problem = '';
  const addProblem = (text) => {
    problem = problem ? `${problem} ${text}` : text;
  };

  try {
    const config = readConfig(env, { forStart: false });
    const style = env.STATE_route === 'plain' ? 'plain' : 'pretty';
    const saved = {
      started,
      windowStart: timestamp(env.STATE_windowstart),
      startedAt: Number.parseInt(env.STATE_startedat, 10),
    };
    // Not declared in action.yml: the runner drops the outputs a post step
    // writes (see the note above `outputs` there). They are still written so
    // that tests/e2e/action.sh and the unit tests can read the verdict, and so
    // that .github/workflows/action-selftest.yml can report whether a runner
    // passes them on. The verdict that users see is the summary and the
    // result of the job.
    const writeOutputs = (clean, ids) => {
      try {
        setOutput(env, 'receipt-clean', clean);
        setOutput(env, 'changed-ids', ids);
      } catch (error) {
        warning(`Could not write the outputs: ${error.message}`);
      }
    };

    if (started === 'maybe') {
      warning('The request that starts the freeze got no usable answer, so it is not known whether a freeze began. Checking the site; there is no receipt to read.');
    }

    // Whether there is a freeze, and whose, decides everything below.
    let verdict;
    try {
      verdict = classifyFreeze(await call(config, style, 'GET', '/status'), saved);
    } catch (error) {
      verdict = { kind: 'unreadable', why: error.message };
    }

    if (verdict.kind === 'unreadable') {
      warning(`Could not read the freeze status: ${verdict.why} Without it this job cannot tell whether the freeze on the site is the one it started, so it will not end it. ${END_HINT}`);
      quietly(() => appendSummary(env, unknownSummary('The status of the site could not be read, so the freeze was not ended and nothing can be said about it. ' + END_HINT)));
      writeOutputs('unknown', '');
      if (config.failOnChanges) {
        addProblem('The freeze status could not be read, so the freeze was not ended and nothing can be promised about content changes.');
      }
    } else if (verdict.kind === 'foreign') {
      warning(`The freeze now active on the site is not the one this job started (${verdict.why}). This job will not end it. To end it anyway, run wp stillgate disable on the server, or send DELETE /wp-json/stillgate/v1/freeze with an administrator's Application Password.`);
      quietly(() => appendSummary(env, unknownSummary(`The freeze now active on the site is not the one this job started (${verdict.why}), so it was not ended and its receipt says nothing about this job. If this job's freeze is still needed, it ends at its own end time.`)));
      writeOutputs('unknown', '');
      if (config.failOnChanges) {
        addProblem('The freeze on the site changed hands during the job, so nothing can be promised about content changes during this job\'s freeze. The freeze that is on now was not ended.');
      }
    } else if (verdict.kind === 'ended') {
      writeOutputs('unknown', '');
      if (started === 'maybe') {
        info('No freeze is active, so there is nothing to end.');
      } else {
        warning('The freeze had already ended before this step ran (its time ran out, or someone ended it). A receipt only covers the time up to the end of its freeze, so it cannot show that nothing changed afterwards. Use a longer duration, or extend the freeze while the job runs.');
        quietly(() => appendSummary(env, unknownSummary('The freeze had already ended before this step ran (its time ran out, or someone ended it), and a receipt only covers the time up to the end of its freeze. Anything saved after that is not in it. Use a longer duration, or extend the freeze while the job runs. `wp stillgate receipt` shows what was recorded up to the end.')));
        if (config.failOnChanges) {
          addProblem('The freeze had already ended before the job finished, so nothing can be promised about content changes during the job. Use a longer duration, or extend the freeze while the job runs.');
        }
      }
    } else {
      // The freeze is this job's own.
      let view = null;
      if (started === 'true') {
        try {
          view = interpretReceipt(await call(config, style, 'GET', '/receipt'));
        } catch (error) {
          warning(`Could not read the receipt: ${error.message}`);
        }

        if (view === null) {
          quietly(() => appendSummary(env, '### Stillgate freeze receipt\n\nThe receipt could not be read, so nothing can be promised about content changes during the freeze.'));
        } else {
          info(`Receipt: clean=${view.clean}, posts changed=${show(view.changedPosts)}${view.ids.length ? `, ids=${view.ids.slice(0, MAX_OUTPUT_IDS).join(',')}` : ''}`);
          quietly(() => appendSummary(env, summaryMarkdown(view, { released: config.release ? 'pending' : 'left' })));
        }
      }
      writeOutputs(view === null ? 'unknown' : view.clean, view === null ? '' : view.ids.slice(0, MAX_OUTPUT_IDS).join(','));

      // The freeze is ended before the job is failed, whatever the receipt
      // said and even if it could not be read.
      if (config.release) {
        try {
          await call(config, style, 'DELETE', '/freeze');
          info('Freeze ended.');
          quietly(() => appendSummary(env, '\nThe freeze was ended by this job.'));
        } catch (error) {
          addProblem(`Could not end the freeze: ${error.message} ${END_HINT}`);
        }
      } else {
        info('release is false: the freeze is left on.');
      }

      if (config.failOnChanges && started === 'true') {
        if (view === null) {
          addProblem('The receipt could not be read, so nothing can be promised about content changes during the freeze.');
        } else if (view.clean === 'false') {
          const list = view.ids.length ? ` (post IDs ${view.ids.slice(0, MAX_LISTED_IDS).join(', ')}${view.ids.length > MAX_LISTED_IDS ? ', ...' : ''})` : '';
          addProblem(`Content changed while the site was frozen${list}. The freeze has been ${config.release ? 'ended' : 'left on'}. Review the changes before replacing the database.`);
        } else if (view.clean === 'unknown') {
          addProblem('The receipt cannot confirm that nothing changed during the freeze, so nothing can be promised.');
        }
      }
    }
  } catch (error) {
    addProblem(error instanceof ActionError ? error.message : `Unexpected error: ${error && error.message ? error.message : error}`);
  }

  if (problem !== '') {
    fail(problem);
  }
}

module.exports = {
  ActionError,
  addSecret,
  appendKeyValue,
  appendSummary,
  classifyFreeze,
  discover,
  escapeData,
  interpretReceipt,
  normalizeSiteUrl,
  oneLine,
  rawInput,
  readConfig,
  redact,
  runMain,
  runPost,
  saveState,
  setOutput,
  summaryMarkdown,
};
