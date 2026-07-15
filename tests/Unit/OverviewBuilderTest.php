<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Edge;
use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Graph\OverviewBuilder;
use PHPUnit\Framework\TestCase;

class OverviewBuilderTest extends TestCase
{
    public function test_it_builds_a_bounded_overview_projection(): void
    {
        $graph = new Graph([
            'appName' => 'Demo',
            'appgraphVersion' => '0.2.0',
        ]);

        $graph->addNode(Node::make('route:PUT:/notes/{note}', 'route', 'PUT /notes/{note}', [
            'metadata' => ['name' => 'notes.update', 'uri' => '/notes/{note}'],
        ]));
        $graph->addNode(Node::make('App\\Http\\Controllers\\NoteController::update', 'method', 'NoteController::update'));
        $graph->addEdge(new Edge('route:PUT:/notes/{note}', 'App\\Http\\Controllers\\NoteController::update', 'routes_to'));

        // The controller method touches one model and two tables.
        $graph->addEdge(new Edge('App\\Http\\Controllers\\NoteController::update', 'App\\Models\\Note', 'uses_model'));
        $graph->addEdge(new Edge('App\\Http\\Controllers\\NoteController::update', 'table:notes', 'writes'));
        $graph->addEdge(new Edge('App\\Http\\Controllers\\NoteController::update', 'table:note_images', 'reads'));

        // The model resolves to a table and declares a relationship.
        $graph->addNode(Node::make('App\\Models\\Note', 'model', 'Note', ['metadata' => ['table' => 'notes']]));
        $graph->addEdge(new Edge('App\\Models\\Note', 'table:notes', 'uses_table'));
        $graph->addEdge(new Edge('App\\Models\\Note', 'App\\Models\\NoteImage', 'has_many'));

        // The controller dispatches an event with one listener.
        $graph->addEdge(new Edge('App\\Http\\Controllers\\NoteController::update', 'App\\Events\\NoteSaved', 'dispatches'));
        $graph->addEdge(new Edge('App\\Listeners\\SendNotification::handle', 'App\\Events\\NoteSaved', 'listens_to'));

        $overview = (new OverviewBuilder())->build($graph);

        $this->assertSame('0.2.0', $overview['meta']['appgraphVersion']);
        $this->assertSame('Demo', $overview['meta']['appName']);

        $this->assertSame('notes', $overview['models']['App\\Models\\Note']);

        $this->assertSame(
            [['from' => 'App\\Models\\Note', 'to' => 'App\\Models\\NoteImage', 'type' => 'has_many']],
            $overview['relationships']
        );

        $this->assertSame(['method' => 1, 'model' => 1, 'route' => 1], $overview['counts']['byNodeType']);

        $route = $overview['routes'][0];
        $this->assertSame('PUT /notes/{note}', $route['route']);
        $this->assertSame('notes.update', $route['name']);
        $this->assertSame('App\\Http\\Controllers\\NoteController::update', $route['action']);
        $this->assertSame(['App\\Models\\Note'], $route['models']);
        $this->assertSame(['note_images', 'notes'], $route['tables']); // sorted

        $this->assertSame(
            ['App\\Events\\NoteSaved' => ['dispatchSites' => 1, 'listeners' => 1]],
            $overview['events']
        );
    }
}
