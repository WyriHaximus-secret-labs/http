<?php

namespace React\Http\Middleware;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Io\HttpBodyStream;
use React\Http\Io\PauseBufferStream;
use React\Promise;
use React\Promise\Deferred;
use React\Stream\ReadableStreamInterface;

/**
 * Limits how many next handlers can be executed concurrently.
 *
 * If this middleware is invoked, it will check if the number of pending
 * handlers is below the allowed limit and then simply invoke the next handler
 * and it will return whatever the next handler returns (or throws).
 *
 * If the number of pending handlers exceeds the allowed limit, the request will
 * be queued (and its streaming body will be paused) and it will return a pending
 * promise.
 * Once a pending handler returns (or throws), it will pick the oldest request
 * from this queue and invokes the next handler (and its streaming body will be
 * resumed).
 *
 * The following example shows how this middleware can be used to ensure no more
 * than 10 handlers will be invoked at once:
 *
 * ```php
 * $server = new StreamingServer(array(
 *     new LimitConcurrentRequestsMiddleware(10),
 *     $handler
 * ));
 * ```
 *
 * Similarly, this middleware is often used in combination with the
 * [`RequestBodyBufferMiddleware`](#requestbodybuffermiddleware) (see below)
 * to limit the total number of requests that can be buffered at once:
 *
 * ```php
 * $server = new StreamingServer(array(
 *     new LimitConcurrentRequestsMiddleware(100), // 100 concurrent buffering handlers
 *     new RequestBodyBufferMiddleware(2 * 1024 * 1024), // 2 MiB per request
 *     new RequestBodyParserMiddleware(),
 *     $handler
 * ));
 * ```
 *
 * More sophisticated examples include limiting the total number of requests
 * that can be buffered at once and then ensure the actual request handler only
 * processes one request after another without any concurrency:
 *
 * ```php
 * $server = new StreamingServer(array(
 *     new LimitConcurrentRequestsMiddleware(100), // 100 concurrent buffering handlers
 *     new RequestBodyBufferMiddleware(2 * 1024 * 1024), // 2 MiB per request
 *     new RequestBodyParserMiddleware(),
 *     new LimitConcurrentRequestsMiddleware(1), // only execute 1 handler (no concurrency)
 *     $handler
 * ));
 * ```
 *
 * @see RequestBodyBufferMiddleware
 */
final class LimitConcurrentRequestsMiddleware
{
    private $limit;
    private $pending = 0;
    private $queue = array();

    /**
     * @param int $limit Maximum amount of concurrent requests handled.
     *
     * For example when $limit is set to 10, 10 requests will flow to $next
     * while more incoming requests have to wait until one is done.
     */
    public function __construct($limit)
    {
        $this->limit = $limit;
    }

    public function __invoke(ServerRequestInterface $request, $next)
    {
        $body = $request->getBody();
        if ($body instanceof ReadableStreamInterface) {
            // pause actual body to stop emitting data until the handler is called
            $size = $body->getSize();
            $body = new PauseBufferStream($body);
            $body->pauseImplicit();

            // replace with buffering body to ensure any readable events will be buffered
            $request = $request->withBody(new HttpBodyStream(
                $body,
                $size
            ));
        }

        // get next queue position
        $queue =& $this->queue;
        $queue[] = null;
        end($queue);
        $id = key($queue);

        $deferred = new Deferred(function ($_, $reject) use (&$queue, $id) {
            // queued promise cancelled before its next handler is invoked
            // remove from queue and reject explicitly
            unset($queue[$id]);
            $reject(new \RuntimeException('Cancelled queued next handler'));
        });

        // queue request and process queue if pending does not exceed limit
        $queue[$id] = $deferred;
        $this->processQueue();

        $that = $this;
        $pending = &$this->pending;
        return $deferred->promise()->then(function () use ($request, $next, $body, &$pending) {
            $pending++;

            $ret = $next($request);

            // resume readable stream and replay buffered events
            if ($body instanceof PauseBufferStream) {
                $body->resumeImplicit();
            }

            return $ret;
        })->then(function ($response) use ($that, &$pending) {
            $pending--;
            $that->processQueue();

            return $response;
        }, function ($error) use ($that, &$pending) {
            $pending--;
            $that->processQueue();

            return Promise\reject($error);
        });
    }

    /**
     * @internal
     */
    public function processQueue()
    {
        if ($this->pending >= $this->limit) {
            return;
        }

        if (!$this->queue) {
            return;
        }

        $first = reset($this->queue);
        unset($this->queue[key($this->queue)]);

        $first->resolve();
    }
}
