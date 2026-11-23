/*
 * live-poll.js - one simple, real-time poller shared by the dashboard pages.
 *
 * - Requests are never cached (no-store) and never stacked: the next request starts only after the
 *   previous one has finished (or timed out).
 * - Failures back off (interval, 2x, 4x ... up to 60s) instead of hammering the server.
 * - Replaces EventSource/SSE: update_cache.php returns JSON, which EventSource cannot consume and
 *   which made every open tab reconnect and poll all routers again every few seconds.
 *
 * Usage:
 *   const poller = LivePoller.create('update_cache.php', function (data) { ... }, { interval: 10000 });
 *   poller.start();     // first request immediately, then every `interval` ms
 *   poller.refresh();   // request now (no-op while a request is already running)
 *   poller.stop();
 */
(function (global) {
    'use strict';

    function create(url, onData, options) {
        var opts = options || {};
        var interval = opts.interval || 10000;
        var timeoutMs = opts.timeout || 60000;
        var maxBackoff = opts.maxBackoff || 60000;

        var timer = null;
        var inFlight = false;
        var failures = 0;
        var running = false;

        function nextDelay() {
            if (!failures) {
                return interval;
            }
            return Math.min(interval * Math.pow(2, failures - 1), maxBackoff);
        }

        function schedule() {
            clearTimeout(timer);
            if (!running) {
                return;
            }
            timer = setTimeout(tick, nextDelay());
        }

        function tick() {
            if (inFlight) {
                return;
            }
            inFlight = true;
            clearTimeout(timer);

            var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            var abortTimer = setTimeout(function () {
                if (controller) {
                    controller.abort();
                }
            }, timeoutMs);

            var sep = url.indexOf('?') === -1 ? '?' : '&';
            fetch(url + sep + '_=' + Date.now(), {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: controller ? controller.signal : undefined
            })
                .then(function (res) {
                    return res.json().then(function (body) {
                        if (!res.ok) {
                            var msg = (body && body.error) ? body.error : ('HTTP ' + res.status);
                            throw new Error(msg);
                        }
                        return body;
                    });
                })
                .then(function (data) {
                    failures = 0;
                    if (typeof onData === 'function') {
                        onData(data);
                    }
                })
                .catch(function (err) {
                    failures++;
                    if (typeof opts.onError === 'function') {
                        opts.onError(err, failures);
                    } else {
                        console.error('Live update failed (' + url + '):', err);
                    }
                })
                .then(function () {
                    clearTimeout(abortTimer);
                    inFlight = false;
                    schedule();
                });
        }

        return {
            start: function () {
                running = true;
                tick();
            },
            stop: function () {
                running = false;
                clearTimeout(timer);
            },
            refresh: function () {
                if (inFlight) {
                    return;
                }
                clearTimeout(timer);
                tick();
            },
            isBusy: function () {
                return inFlight;
            }
        };
    }

    global.LivePoller = { create: create };
})(window);
