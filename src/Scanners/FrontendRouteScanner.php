<?php

namespace AppGraph\Scanners;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Support\FileFinder;

class FrontendRouteScanner
{
    public function __construct(private FileFinder $files)
    {
    }

    public function scan(Graph $graph): Graph
    {
        $routesByName = [];
        $routes = [];

        foreach ($graph->nodes() as $node) {
            $name = $node->type === 'route' ? ($node->metadata['name'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $routesByName[$name][] = $node->id;
            }

            if ($node->type === 'route' && is_string($node->metadata['uri'] ?? null)) {
                $routes[] = [
                    'id' => $node->id,
                    'uri' => '/'.ltrim($node->metadata['uri'], '/'),
                    'methods' => array_map('strtoupper', $node->metadata['methods'] ?? []),
                ];
            }
        }

        $unresolved = [];

        foreach ($this->files->findFiles(['resources', 'src'], ['js', 'jsx', 'ts', 'tsx', 'vue']) as $file) {
            $source = file_get_contents($file);

            if ($source === false) {
                continue;
            }

            $relative = $this->files->relativePath($file) ?? $file;
            $matches = [];
            preg_match_all('~(?<![\\w$])route\\s*\\(\\s*([\'\"])([^\'\"]+)\\1~', $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[2] ?? [] as [$routeName, $offset]) {
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;

                if (! isset($routesByName[$routeName])) {
                    $unresolved[] = ['file' => $relative, 'line' => $line, 'routeName' => $routeName];
                    continue;
                }

                $nodeId = 'frontend:'.$relative;
                $graph->addNode(Node::make($nodeId, 'frontend', basename($relative), [
                    'file' => $relative,
                    'line' => 1,
                ]));

                foreach ($routesByName[$routeName] as $routeId) {
                    $graph->addEdge(new Edge($nodeId, $routeId, 'consumes_route', 0.98, [
                        'routeName' => $routeName,
                        'line' => $line,
                        'syntax' => 'named_route_helper',
                    ]));
                }
            }

            $axiosMatches = [];
            preg_match_all('~\\baxios\\.(get|post|put|patch|delete)\\s*\\(\\s*([\'\"])([^\'\"]+)\\2~i', $source, $axiosMatches, PREG_OFFSET_CAPTURE);

            foreach ($axiosMatches[3] ?? [] as $index => [$uri, $offset]) {
                $method = strtoupper($axiosMatches[1][$index][0]);
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;

                foreach ($routes as $route) {
                    if (! in_array($method, $route['methods'], true) || ! $this->uriMatches($route['uri'], $uri)) {
                        continue;
                    }

                    $nodeId = 'frontend:'.$relative;
                    $graph->addNode(Node::make($nodeId, 'frontend', basename($relative), [
                        'file' => $relative,
                        'line' => 1,
                    ]));
                    $graph->addEdge(new Edge($nodeId, $route['id'], 'consumes_route', 0.9, [
                        'httpMethod' => $method,
                        'uri' => $uri,
                        'line' => $line,
                        'syntax' => 'axios_literal_url',
                    ]));
                }
            }
        }

        if ($unresolved !== []) {
            $graph->addMeta([
                'analysis' => [
                    'frontendRoutes' => [
                        'unresolvedCount' => count($unresolved),
                        'samples' => array_slice($unresolved, 0, 50),
                    ],
                ],
            ]);
        }

        return $graph;
    }

    private function uriMatches(string $routeUri, string $requestedUri): bool
    {
        $path = parse_url($requestedUri, PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $quoted = preg_quote('/'.ltrim($routeUri, '/'), '~');
        $pattern = preg_replace('~\\\\\{[^}]+\\\\\}~', '[^/]+', $quoted);

        return is_string($pattern) && preg_match('~^'.$pattern.'/?$~', '/'.ltrim($path, '/')) === 1;
    }
}
