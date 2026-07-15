<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Scanners\RouteScanner;
use AppGraph\Tests\Fixtures\ProgressNote;
use AppGraph\Tests\Fixtures\ProgressNoteController;
use AppGraph\Tests\Fixtures\UpdateProgressNoteRequest;
use AppGraph\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class RouteScannerTest extends TestCase
{
    public function test_it_scans_routes_and_controller_methods(): void
    {
        Route::put('/progress-notes/{note}', [ProgressNoteController::class, 'update'])
            ->name('progress-notes.update');

        $graph = new Graph();

        app(RouteScanner::class)->scan($graph);

        $array = $graph->toArray();

        $routeId = 'route:PUT:/progress-notes/{note}';
        $methodId = ProgressNoteController::class.'::update';

        $this->assertGraphHasNode($array, $routeId, 'route');
        $this->assertGraphHasNode($array, $methodId, 'method');
        $this->assertGraphHasNode($array, UpdateProgressNoteRequest::class, 'form_request');
        $this->assertGraphHasNode($array, ProgressNote::class, 'model');
        $this->assertGraphHasEdge($array, $routeId, $methodId, 'routes_to');
        $this->assertGraphHasEdge($array, $methodId, UpdateProgressNoteRequest::class, 'validates_with');
        $this->assertGraphHasEdge($array, $methodId, ProgressNote::class, 'uses_model');

        $method = $this->graphNode($array, $methodId);

        $this->assertSame('update(UpdateProgressNoteRequest $request, ProgressNote $note): array', $method['signature']);
        $this->assertSame('ProgressNoteController', $method['class']);
        $this->assertSame('update', $method['method']);
    }
}
