<?php
declare(strict_types=1);

namespace Shlinkio\Shlink\Rest\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Zend\Stratigility\MiddlewareInterface;

class PathVersionMiddleware implements MiddlewareInterface
{
    /**
     * Process an incoming request and/or response.
     *
     * Accepts a server-side request and a response instance, and does
     * something with them.
     *
     * If the response is not complete and/or further processing would not
     * interfere with the work done in the middleware, or if the middleware
     * wants to delegate to another process, it can use the `$out` callable
     * if present.
     *
     * If the middleware does not return a value, execution of the current
     * request is considered complete, and the response instance provided will
     * be considered the response to return.
     *
     * Alternately, the middleware may return a response instance.
     *
     * Often, middleware will `return $out();`, with the assumption that a
     * later middleware will return a response.
     *
     * @param Request $request
     * @param Response $response
     * @param null|callable $out
     * @return null|Response
     */
    public function __invoke(Request $request, Response $response, callable $out = null)
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        // If the path does not begin with the version number, prepend v1 by default for BC compatibility purposes
        if (strpos($path, '/v') !== 0) {
            $parts = explode('/', $path);
            // Remove the first empty part and the
            array_shift($parts);
            // Prepend the version prefix
            array_unshift($parts, '/v1');

            $request = $request->withUri($uri->withPath(implode('/', $parts)));
        }

        return $out($request, $response);
    }
}
