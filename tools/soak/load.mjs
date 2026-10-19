#!/usr/bin/env node
// ─────────────────────────────────────────────────────────────────────────────
// Mixed-load generator for the Swoole soak (tools/soak/soak.sh runs it).
//
// Plain Node, no packages: it runs in the semitexa/e2e-runner image, which
// already has Node, so a 72-hour run needs nothing installed. Open-loop: every
// scenario fires at a fixed rate whether or not the server keeps up, the way
// visitors do, with a cap on requests in flight so a stalled server shows up
// as `dropped` instead of as a generator that ran out of memory.
//
// Scenarios (requests per second at --scale 1):
//   ssr     6    GET a Demo page, rendered on the server
//   api     3    GET the Demo JSON API (product list, one product)
//   write   0.5  POST an ORM row whose save publishes a scope invalidation
//   event   0.5  POST an event in turn sync, async (Swoole defer) and queued (NATS)
//   drain   1    open a page, then its KISS stream until the deferred blocks are done
//   live    --live holders: KISS mode=live streams held open, each replaced
//                after a random 2-10 min lifetime, like pages being closed
//
// Every --window seconds one CSV row per scenario goes to <out>/load.csv:
//   ts,scenario,count,errors,dropped,p50_ms,p95_ms,p99_ms,max_ms,open
// Percentiles are over the requests that succeeded. For `live` they are how
// long a stream was held, and `open` is the number held at that moment.
// The first error of each kind per window goes to <out>/errors.ndjson.
//
// Usage:
//   node load.mjs --base-url http://127.0.0.1:9502 --duration 600 --out var/soak/run1
//                 [--scale 1] [--live 20] [--window 60]
// ─────────────────────────────────────────────────────────────────────────────

import { appendFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';

const args = parseArgs(process.argv.slice(2));
const BASE = (args['base-url'] ?? '').replace(/\/+$/, '');
const DURATION_S = Number(args.duration ?? 600);
const OUT = args.out ?? '';
const SCALE = Number(args.scale ?? 1);
const LIVE = Number(args.live ?? 20);
const WINDOW_S = Number(args.window ?? 60);

if (BASE === '' || OUT === '' || !(DURATION_S > 0) || !(SCALE > 0) || !(WINDOW_S > 0) || !(LIVE >= 0)) {
    console.error('usage: node load.mjs --base-url URL --duration SECONDS --out DIR [--scale N] [--live N] [--window SECONDS]');
    process.exit(2);
}

const SSR_PAGES = [
    '/',
    '/demo/routing',
    '/demo/routing/basic',
    '/demo/di/readonly',
    '/demo/data/relations',
    '/demo/data/pagination',
    '/demo/rendering/components',
    '/demo/rendering/seo',
    '/demo/events/sync',
    '/demo/api/rest-api',
];
const WRITE_PATH = '/ui-playground/admin/pings/demo-add';
const EVENT_PATH = '/demo/events/arena/launch';
const EVENT_MODES = ['sync', 'async', 'queued'];
const DEFERRED_PAGE = '/demo/rendering/deferred';
const KISS_PATH = '/__semitexa_kiss';
const REQUEST_TIMEOUT_MS = 30_000;
const DRAIN_TIMEOUT_MS = 15_000;
// The server sends a live stream a heartbeat every 20 s
// (SseTransportModePolicy::HEARTBEAT_INTERVAL_SECONDS); silence past two of
// them is a stream that has stopped, however long it stays open.
const LIVE_SILENCE_MS = 45_000;

mkdirSync(OUT, { recursive: true });
const CSV = `${OUT}/load.csv`;
const ERRORS = `${OUT}/errors.ndjson`;
writeFileSync(CSV, 'ts,scenario,count,errors,dropped,p50_ms,p95_ms,p99_ms,max_ms,open\n');
writeFileSync(ERRORS, '');

// ── measurement ─────────────────────────────────────────────────────────────

const stats = new Map();
function bucket(name) {
    if (!stats.has(name)) {
        stats.set(name, { samples: [], count: 0, errors: 0, dropped: 0, inFlight: 0, errorKinds: new Set() });
    }
    return stats.get(name);
}

function record(name, ms, error) {
    const b = bucket(name);
    // Latency of what succeeded: a fast refusal would pull the percentiles down.
    if (error === null) b.samples.push(ms);
    b.count++;
    if (error !== null) {
        b.errors++;
        // The first of each kind per window: enough to read what broke without
        // writing every one of a thousand identical failures.
        const kind = String(error).slice(0, 120);
        if (!b.errorKinds.has(kind)) {
            b.errorKinds.add(kind);
            appendFileSync(ERRORS, JSON.stringify({ ts: new Date().toISOString(), scenario: name, error: String(error) }) + '\n');
        }
    }
}

function percentile(sorted, p) {
    if (sorted.length === 0) return '';
    return sorted[Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1)].toFixed(1);
}

let liveOpen = 0;
function flush() {
    const ts = new Date().toISOString();
    let rows = '';
    for (const [name, b] of stats) {
        const sorted = b.samples.sort((x, y) => x - y);
        rows += [
            ts, name, b.count, b.errors, b.dropped,
            percentile(sorted, 50), percentile(sorted, 95), percentile(sorted, 99),
            sorted.length ? sorted[sorted.length - 1].toFixed(1) : '',
            name === 'live' ? liveOpen : '',
        ].join(',') + '\n';
        b.samples = [];
        b.count = 0;
        b.errors = 0;
        b.dropped = 0;
        b.errorKinds.clear();
    }
    appendFileSync(CSV, rows);
}

// ── HTTP ────────────────────────────────────────────────────────────────────

/** A visitor's cookies, kept the way a browser keeps them for one site. */
class Jar {
    constructor() { this.cookies = new Map(); }
    take(response) {
        for (const line of response.headers.getSetCookie?.() ?? []) {
            const [pair] = line.split(';');
            const at = pair.indexOf('=');
            if (at > 0) this.cookies.set(pair.slice(0, at).trim(), pair.slice(at + 1).trim());
        }
    }
    set(name, value) { this.cookies.set(name, value); }
    header() { return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '); }
}

async function request(path, { method = 'GET', jar = null, headers = {}, body = undefined, timeoutMs = REQUEST_TIMEOUT_MS } = {}) {
    const all = { ...headers };
    if (jar !== null && jar.cookies.size > 0) all.Cookie = jar.header();
    const response = await fetch(BASE + path, { method, headers: all, body, redirect: 'manual', signal: AbortSignal.timeout(timeoutMs) });
    if (jar !== null) jar.take(response);
    return response;
}

/** Fire `fn` at `perSecond`, open-loop, never more than `cap` in flight. */
function every(name, perSecond, cap, fn) {
    const b = bucket(name);
    if (!(perSecond > 0)) return () => {};
    const timer = setInterval(() => {
        if (b.inFlight >= cap) {
            b.dropped++;
            return;
        }
        b.inFlight++;
        const startedAt = performance.now();
        fn().then(
            () => record(name, performance.now() - startedAt, null),
            (error) => record(name, performance.now() - startedAt, error?.message ?? error),
        ).finally(() => { b.inFlight--; });
    }, 1000 / perSecond);
    return () => clearInterval(timer);
}

async function expectOk(response, what) {
    // Read the body in full: a response left unread holds its connection.
    const text = await response.text();
    if (response.status < 200 || response.status >= 300) {
        throw new Error(`${what}: HTTP ${response.status}`);
    }
    return text;
}

// ── scenarios ───────────────────────────────────────────────────────────────

const pick = (list) => list[Math.floor(Math.random() * list.length)];
const sessionId = () => 'sse_' + randomBytes(16).toString('hex');

async function ssr() {
    const page = pick(SSR_PAGES);
    await expectOk(await request(page, { headers: { Accept: 'text/html' } }), `GET ${page}`);
}

let productSlugs = [];
async function api() {
    if (productSlugs.length > 0 && Math.random() < 0.5) {
        const path = `/demo/api/v1/products/${encodeURIComponent(pick(productSlugs))}`;
        await expectOk(await request(path, { headers: { Accept: 'application/json' } }), `GET ${path}`);
        return;
    }
    await expectOk(await request('/demo/api/v1/products', { headers: { Accept: 'application/json' } }), 'GET /demo/api/v1/products');
}

async function write() {
    await expectOk(await request(WRITE_PATH, { method: 'POST', headers: { Referer: BASE + '/ui-playground/admin/pings' } }), `POST ${WRITE_PATH}`);
}

let eventTurn = 0;
async function event() {
    const mode = EVENT_MODES[eventTurn++ % EVENT_MODES.length];
    const text = await expectOk(await request(EVENT_PATH, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Referer: BASE + '/demo/events/arena' },
        body: JSON.stringify({ mode, sessionId: sessionId() }),
    }), `POST ${EVENT_PATH} ${mode}`);
    if (!text.includes('"ok":true')) throw new Error(`POST ${EVENT_PATH} ${mode}: not accepted`);
}

/** What a page hands its stream: the session, the deferred request and the bind cookie. */
async function openPage(jar, path) {
    const html = await expectOk(await request(path, { jar, headers: { Accept: 'text/html' } }), `GET ${path}`);
    const match = html.match(/data-ssr-deferred-manifest>([^<]+)</);
    if (match === null) throw new Error(`GET ${path}: no deferred manifest`);
    const manifest = JSON.parse(match[1]);
    if (!Array.isArray(manifest.slots) || manifest.slots.length === 0) throw new Error(`GET ${path}: the deferred manifest names no slots`);
    if (manifest.bindToken) jar.set('semitexa_ssr_bind', encodeURIComponent(manifest.bindToken));
    return manifest;
}

function kissHeaders(jar, page) {
    // Same-origin Referer: the stream door refuses a request it cannot place (403).
    return { Accept: 'text/event-stream', Referer: BASE + page, Cookie: jar.header() };
}

async function drain() {
    const jar = new Jar();
    const manifest = await openPage(jar, DEFERRED_PAGE);
    const url = `${KISS_PATH}?session_id=${encodeURIComponent(manifest.sessionId)}&deferred_request_id=${encodeURIComponent(manifest.requestId)}`;
    const response = await fetch(BASE + url, { headers: kissHeaders(jar, DEFERRED_PAGE), signal: AbortSignal.timeout(DRAIN_TIMEOUT_MS) });
    const text = await expectOk(response, 'KISS deferred');
    const blocks = (text.match(/"type":"deferred_block"/g) ?? []).length;
    if (!text.includes('"type":"done"')) throw new Error(`KISS deferred: stream ended without done after ${blocks} blocks`);
    if (blocks < manifest.slots.length) throw new Error(`KISS deferred: ${blocks} of ${manifest.slots.length} blocks`);
}

/** One long-held stream; replaced when it ends or when its lifetime is up. */
async function holdLive(stopAt) {
    bucket('live');
    while (Date.now() < stopAt) {
        // Whole milliseconds: AbortSignal.timeout() refuses a fraction.
        const lifetimeMs = Math.max(1, Math.round(Math.min(stopAt - Date.now(), (120 + Math.random() * 480) * 1000)));
        const startedAt = performance.now();
        let error = null;
        let opened = false;
        try {
            const jar = new Jar();
            const manifest = await openPage(jar, DEFERRED_PAGE);
            const url = `${KISS_PATH}?session_id=${encodeURIComponent(manifest.sessionId)}&mode=live`;
            const response = await fetch(BASE + url, { headers: kissHeaders(jar, DEFERRED_PAGE), signal: AbortSignal.timeout(lifetimeMs) });
            if (response.status !== 200) {
                await response.text();
                throw new Error(`KISS live: HTTP ${response.status}`);
            }
            if (!(response.headers.get('content-type') ?? '').startsWith('text/event-stream')) {
                await response.text();
                throw new Error(`KISS live: answered ${response.headers.get('content-type')}, not an event stream`);
            }
            opened = true;
            liveOpen++;
            const reader = response.body.getReader();
            let silent = null;
            const watchdog = () => setTimeout(() => {
                silent = new Error(`KISS live: silent for ${LIVE_SILENCE_MS / 1000}s`);
                reader.cancel().catch(() => {});
            }, LIVE_SILENCE_MS);
            let timer = watchdog();
            try {
                for (;;) {
                    const { done } = await reader.read();
                    if (done) break;
                    clearTimeout(timer);
                    timer = watchdog();
                }
            } finally {
                clearTimeout(timer);
            }
            if (silent !== null) throw silent;
            // The server closed it before its lifetime: count it, then replace it.
            throw new Error('KISS live: closed by the server');
        } catch (e) {
            // Only the planned end of a lifetime is a clean hold.
            const timedOut = e?.name === 'TimeoutError' || e?.name === 'AbortError';
            error = opened && timedOut ? null : (e?.message ?? String(e));
        } finally {
            if (opened) liveOpen--;
        }
        // `live` latency is how long a stream was held; errors are refusals and early closes.
        record('live', performance.now() - startedAt, error);
        if (error !== null) await new Promise((r) => setTimeout(r, 1000 + Math.random() * 2000));
    }
}

// ── run ─────────────────────────────────────────────────────────────────────

async function preflight() {
    // Fail before the run, not 72 hours into it: every path the load uses answers now.
    for (const page of SSR_PAGES) {
        await expectOk(await request(page, { headers: { Accept: 'text/html' } }), `preflight GET ${page}`);
    }
    const list = JSON.parse(await expectOk(await request('/demo/api/v1/products', { headers: { Accept: 'application/json' } }), 'preflight API'));
    // Product slugs only: the list nests each product's category, which has a slug of its own.
    productSlugs = (Array.isArray(list?.data) ? list.data : []).map((p) => p?.slug).filter((slug) => typeof slug === 'string');
    if (productSlugs.length === 0) throw new Error('preflight API: no product slugs in /demo/api/v1/products');
    await write();
    await event();
    await drain();
}

// Stopped early (docker stop sends TERM to node as PID 1, which has no default
// handler): keep the window in progress, then go.
for (const signal of ['SIGTERM', 'SIGINT']) {
    process.on(signal, () => {
        flush();
        console.log(`load: stopped by ${signal}`);
        process.exit(143);
    });
}

try {
    await preflight();
} catch (e) {
    console.error(`preflight failed: ${e?.message ?? e}`);
    process.exit(1);
}

const stopAt = Date.now() + DURATION_S * 1000;
console.log(`load: ${BASE} for ${DURATION_S}s at scale ${SCALE}, ${LIVE} live streams, ${productSlugs.length} product slugs, out ${OUT}`);

const stops = [
    every('ssr', 6 * SCALE, 100, ssr),
    every('api', 3 * SCALE, 100, api),
    every('write', 0.5 * SCALE, 20, write),
    every('event', 0.5 * SCALE, 20, event),
    every('drain', 1 * SCALE, 40, drain),
];
const holders = Array.from({ length: LIVE }, (_, i) => new Promise((r) => setTimeout(r, i * 250)).then(() => holdLive(stopAt)));
const flusher = setInterval(flush, WINDOW_S * 1000);

await new Promise((r) => setTimeout(r, Math.max(0, stopAt - Date.now())));
stops.forEach((stop) => stop());
await Promise.allSettled(holders);
// Let what is in flight finish, so the last window counts it.
await new Promise((r) => setTimeout(r, 2000));
clearInterval(flusher);
flush();
console.log('load: done');

function parseArgs(argv) {
    const out = {};
    for (let i = 0; i < argv.length; i++) {
        if (argv[i].startsWith('--')) {
            const key = argv[i].slice(2);
            const next = argv[i + 1];
            out[key] = next !== undefined && !next.startsWith('--') ? (i++, next) : 'true';
        }
    }
    return out;
}
