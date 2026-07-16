<?php

namespace AppGraph\Scanners\Concerns;

trait MatchesRouteTargets
{
    /**
     * @param array<int, array{id: string, methods: array<int, string>, uri: string, domain: string|null}> $routes
     * @return array<int, array{
     *     route: array{id: string, methods: array<int, string>, uri: string, domain: string|null},
     *     certainty: 'exact'|'possible',
     *     ambiguity: string|null,
     *     candidateCount: int,
     *     requestedHost: string|null
     * }>
     */
    private function literalRouteMatches(array $routes, string $method, string $requestedUri): array
    {
        $pathCandidates = array_values(array_filter(
            $routes,
            fn (array $route): bool => in_array($method, $route['methods'], true)
                && $this->routeUriMatches($route['uri'], $requestedUri),
        ));
        $requestedHost = $this->requestedHost($requestedUri);
        $candidates = $requestedHost === null
            ? $pathCandidates
            : array_values(array_filter(
                $pathCandidates,
                fn (array $route): bool => $this->routeDomainMatches($route['domain'], $requestedHost),
            ));
        $candidateCount = count($candidates);
        $hostRequiredForExactMatch = $requestedHost === null
            && (bool) array_filter(
                $candidates,
                fn (array $route): bool => $this->hasRouteDomainConstraint($route['domain']),
            );
        $possible = $candidateCount > 1 || $hostRequiredForExactMatch;
        $ambiguity = ! $possible
            ? null
            : ($requestedHost === null
                ? 'request_host_unavailable'
                : 'multiple_host_compatible_routes');

        return array_map(static fn (array $route): array => [
            'route' => $route,
            'certainty' => $possible ? 'possible' : 'exact',
            'ambiguity' => $ambiguity,
            'candidateCount' => $candidateCount,
            'requestedHost' => $requestedHost,
        ], $candidates);
    }

    private function routeUriMatches(string $routeUri, string $requestedUri): bool
    {
        $path = parse_url($requestedUri, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $routePath = trim($routeUri, '/');
        $pattern = '';

        if ($routePath === '') {
            $pattern = '/';
        } else {
            foreach (explode('/', $routePath) as $segment) {
                if (preg_match('/^\{[^}]+\?\}$/', $segment) === 1) {
                    $pattern .= '(?:/[^/]+)?';
                    continue;
                }

                $parts = preg_split('/(\{[^}]+\??\})/', $segment, -1, PREG_SPLIT_DELIM_CAPTURE);

                if (! is_array($parts)) {
                    return false;
                }

                $segmentPattern = '';

                foreach ($parts as $part) {
                    if (preg_match('/^\{[^}]+\?\}$/', $part) === 1) {
                        $segmentPattern .= '[^/]*';
                    } elseif (preg_match('/^\{[^}]+\}$/', $part) === 1) {
                        $segmentPattern .= '[^/]+';
                    } else {
                        $segmentPattern .= preg_quote($part, '~');
                    }
                }

                $pattern .= '/'.$segmentPattern;
            }
        }

        return preg_match('~^'.$pattern.'/?$~', '/'.ltrim($path, '/')) === 1;
    }

    private function requestedHost(string $requestedUri): ?string
    {
        $host = parse_url($requestedUri, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return rtrim(strtolower(trim($host)), '.');
    }

    private function routeDomainMatches(?string $domain, string $requestedHost): bool
    {
        if (! $this->hasRouteDomainConstraint($domain)) {
            return true;
        }

        $domain = preg_replace('#^https?://#i', '', trim($domain)) ?? trim($domain);
        $domain = rtrim(strtolower($domain), '.');
        $quoted = preg_quote($domain, '~');
        $pattern = preg_replace('~\\\{[^}]+\\\}~', '[^.]+', $quoted);

        return is_string($pattern) && preg_match('~^'.$pattern.'$~i', $requestedHost) === 1;
    }

    private function hasRouteDomainConstraint(?string $domain): bool
    {
        return is_string($domain) && trim($domain) !== '';
    }
}
