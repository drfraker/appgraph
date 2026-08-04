<?php

namespace AppGraph\Tests\Unit;

use AppGraph\Graph\Graph;
use AppGraph\Graph\Node;
use AppGraph\Scanners\ViewScanner;
use AppGraph\Support\FileFinder;
use AppGraph\Support\SourceFileObservations;
use AppGraph\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;

class ViewScannerTest extends TestCase
{
    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = sys_get_temp_dir().'/appgraph-views-'.bin2hex(random_bytes(4));
        mkdir($this->fixturePath.'/resources/views', 0775, true);
        mkdir($this->fixturePath.'/app/Http/Controllers', 0775, true);
        mkdir($this->fixturePath.'/routes', 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->fixturePath);
        parent::tearDown();
    }

    public function test_it_creates_view_nodes_for_blade_and_php_templates(): void
    {
        mkdir($this->fixturePath.'/resources/views/notes', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/notes/show.blade.php', "<div>\n{{ \$note->title }}\n</div>\n");
        file_put_contents($this->fixturePath.'/resources/views/legacy.php', '<?php echo $value;');

        $observations = new SourceFileObservations();
        $graph = $this->scan(new Graph(), $observations);
        $array = $graph->toArray();

        $this->assertGraphHasNode($array, 'view:notes.show', 'view');
        $node = $this->graphNode($array, 'view:notes.show');
        $this->assertSame('notes.show', $node['label']);
        $this->assertSame('notes.show', $node['metadata']['name']);
        $this->assertSame('blade', $node['metadata']['engine']);
        $this->assertSame('resources/views/notes/show.blade.php', $node['file']);
        $this->assertSame(1, $node['line']);
        $this->assertSame(4, $node['endLine']);

        $this->assertGraphHasNode($array, 'view:legacy', 'view');
        $this->assertSame('php', $this->graphNode($array, 'view:legacy')['metadata']['engine']);

        $bladePath = $this->fixturePath.'/resources/views/notes/show.blade.php';
        $this->assertSame(
            [hash('sha256', (string) file_get_contents($bladePath))],
            $observations->hashes()[$bladePath],
        );
    }

    public function test_it_maps_controller_methods_that_render_views(): void
    {
        mkdir($this->fixturePath.'/resources/views/notes', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/notes/show.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/resources/views/notes/index.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/app/Http/Controllers/NoteController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\View;

class NoteController
{
    public function show(int $note)
    {
        return view('notes.show', ['note' => $note]);
    }

    public function index()
    {
        return View::make('notes.index');
    }

    public function export(string $template)
    {
        return view($template);
    }

    public function missing()
    {
        return response()->view('notes.missing');
    }
}
PHP);

        $array = $this->scan(new Graph())->toArray();

        $this->assertGraphHasNode($array, 'App\Http\Controllers\NoteController::show', 'method');
        $edge = $this->graphEdge($array, 'App\Http\Controllers\NoteController::show', 'view:notes.show', 'renders');
        $this->assertSame(0.95, $edge['confidence']);
        $this->assertSame('view_helper', $edge['metadata']['syntax']);

        $facadeEdge = $this->graphEdge($array, 'App\Http\Controllers\NoteController::index', 'view:notes.index', 'renders');
        $this->assertSame('view_facade', $facadeEdge['metadata']['syntax']);

        // Dynamic names are diagnostics, unresolved names never invent nodes.
        $this->assertNull($this->graphNode($array, 'view:notes.missing'));
        $analysis = $array['meta']['analysis']['views'];
        $this->assertSame(1, $analysis['dynamicReferences']['count']);
        $this->assertSame('app/Http/Controllers/NoteController.php', $analysis['dynamicReferences']['samples'][0]['file']);
        $this->assertSame(1, $analysis['unresolvedViews']['count']);
        $this->assertSame('notes.missing', $analysis['unresolvedViews']['samples'][0]['view']);
    }

    public function test_blade_comments_verbatim_blocks_and_escaped_directives_produce_no_edges(): void
    {
        mkdir($this->fixturePath.'/resources/views/partials', 0775, true);
        mkdir($this->fixturePath.'/resources/views/components', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/partials/nav.blade.php', '<nav></nav>');
        file_put_contents($this->fixturePath.'/resources/views/components/alert.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/resources/views/page.blade.php', <<<'BLADE'
{{--
@extends('partials.nav')
{{ route('commented.route') }}
--}}
@verbatim
@include('partials.nav')
<x-alert />
@endverbatim
Docs example: @@include('partials.nav')
BLADE);

        $array = $this->scan(new Graph())->toArray();

        $this->assertNull($this->graphEdge($array, 'view:page', 'view:partials.nav', 'extends'));
        $this->assertNull($this->graphEdge($array, 'view:page', 'view:partials.nav', 'includes'));
        $this->assertNull($this->graphEdge($array, 'view:page', 'view:components.alert', 'uses_component'));
        $this->assertArrayNotHasKey('unresolvedRoutes', $array['meta']['analysis']['views'] ?? []);
    }

    public function test_conditional_includes_survive_idiomatic_conditions(): void
    {
        mkdir($this->fixturePath.'/resources/views/partials', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/partials/nav.blade.php', '<nav></nav>');
        file_put_contents($this->fixturePath.'/resources/views/partials/admin.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/resources/views/shell.blade.php', <<<'BLADE'
@includeWhen(auth()->check(), 'partials.nav')
@includeUnless($user->can('edit', $note), 'partials.admin')
BLADE);

        $array = $this->scan(new Graph())->toArray();

        $nav = $this->graphEdge($array, 'view:shell', 'view:partials.nav', 'includes');
        $this->assertSame(0.9, $nav['confidence']);
        $this->assertSame('@includeWhen', $nav['metadata']['syntax']);

        $admin = $this->graphEdge($array, 'view:shell', 'view:partials.admin', 'includes');
        $this->assertSame('@includeUnless', $admin['metadata']['syntax']);
    }

    public function test_it_maps_route_view_registrations_and_closure_routes(): void
    {
        file_put_contents($this->fixturePath.'/resources/views/about.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/resources/views/home.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/routes/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::view('/about', 'about');
Route::get('/home', fn () => view('home'));
PHP);

        $graph = new Graph();
        $graph->addNode(Node::make('route:GET:/about', 'route', 'GET /about', [
            'metadata' => ['uri' => 'about', 'methods' => ['GET', 'HEAD']],
        ]));
        $graph->addNode(Node::make('route:GET:/home', 'route', 'GET /home', [
            'metadata' => ['uri' => 'home', 'methods' => ['GET', 'HEAD']],
        ]));

        $array = $this->scan($graph)->toArray();

        $routeView = $this->graphEdge($array, 'route:GET:/about', 'view:about', 'renders');
        $this->assertSame('route_view', $routeView['metadata']['syntax']);

        $closure = $this->graphEdge($array, 'route:GET:/home', 'view:home', 'renders');
        $this->assertSame('route_closure', $closure['metadata']['syntax']);
        $this->assertSame(0.9, $closure['confidence']);
    }

    public function test_grouped_closure_routes_attach_to_the_prefixed_route_not_a_same_uri_sibling(): void
    {
        mkdir($this->fixturePath.'/resources/views/admin', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/admin/dashboard.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/routes/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('dashboard', [App\Http\Controllers\HomeController::class, 'index']);

Route::prefix('admin')->group(function () {
    Route::get('dashboard', fn () => view('admin.dashboard'));
});
PHP);

        $graph = new Graph();
        $graph->addNode(Node::make('route:GET:/dashboard', 'route', 'GET /dashboard', [
            'metadata' => [
                'uri' => 'dashboard',
                'methods' => ['GET', 'HEAD'],
                'action' => 'App\Http\Controllers\HomeController@index',
            ],
        ]));
        $graph->addNode(Node::make('route:GET:/admin/dashboard', 'route', 'GET /admin/dashboard', [
            'metadata' => [
                'uri' => 'admin/dashboard',
                'methods' => ['GET', 'HEAD'],
                'action' => 'Closure',
            ],
        ]));

        $array = $this->scan($graph)->toArray();

        $this->assertGraphHasEdge($array, 'route:GET:/admin/dashboard', 'view:admin.dashboard', 'renders');
        $this->assertNull($this->graphEdge($array, 'route:GET:/dashboard', 'view:admin.dashboard', 'renders'));
    }

    public function test_fluent_registrations_and_framework_prefixed_uris_resolve_by_unique_suffix(): void
    {
        file_put_contents($this->fixturePath.'/resources/views/fluent-page.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/resources/views/api-user.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/routes/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth')->get('/fluent', fn () => view('fluent-page'));
Route::get('/user', fn () => view('api-user'));
PHP);

        $graph = new Graph();
        $graph->addNode(Node::make('route:GET:/fluent', 'route', 'GET /fluent', [
            'metadata' => ['uri' => 'fluent', 'methods' => ['GET', 'HEAD'], 'action' => 'Closure'],
        ]));
        // The router registered /user under the api prefix from bootstrap.
        $graph->addNode(Node::make('route:GET:/api/user', 'route', 'GET /api/user', [
            'metadata' => ['uri' => 'api/user', 'methods' => ['GET', 'HEAD'], 'action' => 'Closure'],
        ]));

        $array = $this->scan($graph)->toArray();

        $fluent = $this->graphEdge($array, 'route:GET:/fluent', 'view:fluent-page', 'renders');
        $this->assertSame(0.9, $fluent['confidence']);

        $suffix = $this->graphEdge($array, 'route:GET:/api/user', 'view:api-user', 'renders');
        $this->assertSame(0.7, $suffix['confidence']);
        $this->assertSame('possible', $suffix['metadata']['matchCertainty']);
    }

    public function test_methods_registering_closure_routes_are_not_credited_with_renders(): void
    {
        file_put_contents($this->fixturePath.'/resources/views/inline-page.blade.php', '<div></div>');
        mkdir($this->fixturePath.'/app/Providers', 0775, true);
        file_put_contents($this->fixturePath.'/app/Providers/RouteRegistrar.php', <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;

class RouteRegistrar
{
    public function boot(): void
    {
        Route::get('/inline', fn () => view('inline-page'));
    }
}
PHP);

        $graph = new Graph();
        $graph->addNode(Node::make('route:GET:/inline', 'route', 'GET /inline', [
            'metadata' => ['uri' => 'inline', 'methods' => ['GET', 'HEAD'], 'action' => 'Closure'],
        ]));

        $array = $this->scan($graph)->toArray();

        $this->assertGraphHasEdge($array, 'route:GET:/inline', 'view:inline-page', 'renders');
        $this->assertNull($this->graphEdge($array, 'App\Providers\RouteRegistrar::boot', 'view:inline-page', 'renders'));
        $this->assertNull($this->graphNode($array, 'App\Providers\RouteRegistrar::boot'));
    }

    public function test_trait_methods_that_render_views_are_mapped(): void
    {
        mkdir($this->fixturePath.'/resources/views/reports', 0775, true);
        mkdir($this->fixturePath.'/app/Concerns', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/reports/summary.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/app/Concerns/RendersReports.php', <<<'PHP'
<?php

namespace App\Concerns;

trait RendersReports
{
    public function summary()
    {
        return view('reports.summary');
    }
}
PHP);

        $array = $this->scan(new Graph())->toArray();

        $this->assertGraphHasEdge($array, 'App\Concerns\RendersReports::summary', 'view:reports.summary', 'renders');
    }

    public function test_dotted_template_filenames_do_not_shadow_the_resolvable_template(): void
    {
        mkdir($this->fixturePath.'/resources/views/user', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/user.profile.blade.php', 'unreachable');
        file_put_contents($this->fixturePath.'/resources/views/user/profile.blade.php', 'reachable');

        $array = $this->scan(new Graph())->toArray();

        $node = $this->graphNode($array, 'view:user.profile');
        $this->assertSame('resources/views/user/profile.blade.php', $node['file']);
    }

    public function test_view_factory_first_maps_all_candidates_as_possible(): void
    {
        file_put_contents($this->fixturePath.'/resources/views/dashboard.blade.php', '<div></div>');
        file_put_contents($this->fixturePath.'/app/Http/Controllers/ThemeController.php', <<<'PHP'
<?php

namespace App\Http\Controllers;

class ThemeController
{
    public function show()
    {
        return view()->first(['custom.dashboard', 'dashboard']);
    }
}
PHP);

        $array = $this->scan(new Graph())->toArray();

        $edge = $this->graphEdge($array, 'App\Http\Controllers\ThemeController::show', 'view:dashboard', 'renders');
        $this->assertSame(0.7, $edge['confidence']);
        $this->assertSame('possible', $edge['metadata']['matchCertainty']);
    }

    public function test_it_maps_blade_directives_between_templates(): void
    {
        mkdir($this->fixturePath.'/resources/views/layouts', 0775, true);
        mkdir($this->fixturePath.'/resources/views/partials', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/layouts/app.blade.php', '<html>@yield(\'content\')</html>');
        file_put_contents($this->fixturePath.'/resources/views/partials/nav.blade.php', '<nav></nav>');
        file_put_contents($this->fixturePath.'/resources/views/dashboard.blade.php', <<<'BLADE'
@extends('layouts.app')

@include('partials.nav')
@include('partials.missing')
@includeWhen($admin, 'partials.nav')
@includeFirst(['partials.custom-nav', 'partials.nav'])
BLADE);

        $array = $this->scan(new Graph())->toArray();

        $extends = $this->graphEdge($array, 'view:dashboard', 'view:layouts.app', 'extends');
        $this->assertSame('@extends', $extends['metadata']['syntax']);
        $this->assertSame(1, $extends['metadata']['line']);

        $include = $this->graphEdge($array, 'view:dashboard', 'view:partials.nav', 'includes');
        $this->assertNotNull($include);

        $first = array_filter(
            $array['meta']['analysis']['views']['unresolvedViews']['samples'],
            static fn (array $sample): bool => $sample['view'] === 'partials.missing',
        );
        $this->assertCount(1, $first);
        $this->assertNull($this->graphNode($array, 'view:partials.missing'));
    }

    public function test_it_resolves_blade_components_to_classes_and_anonymous_views(): void
    {
        mkdir($this->fixturePath.'/resources/views/components/forms', 0775, true);
        mkdir($this->fixturePath.'/app/View/Components/Forms', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/components/alert.blade.php', '<div role="alert"></div>');
        file_put_contents($this->fixturePath.'/resources/views/components/forms/input.blade.php', '<input />');
        file_put_contents($this->fixturePath.'/app/View/Components/Forms/Input.php', <<<'PHP'
<?php

namespace App\View\Components\Forms;

use Illuminate\View\Component;

class Input extends Component
{
    public function render()
    {
        return view('components.forms.input');
    }
}
PHP);
        file_put_contents($this->fixturePath.'/resources/views/page.blade.php', <<<'BLADE'
<x-alert type="error" />
<x-forms.input name="title" />
<x-slot:footer></x-slot>
<x-unknown-widget />
BLADE);

        $array = $this->scan(new Graph())->toArray();

        $anonymous = $this->graphEdge($array, 'view:page', 'view:components.alert', 'uses_component');
        $this->assertSame('component_tag', $anonymous['metadata']['syntax']);

        // Class components win over same-named anonymous views, and their
        // render() connects the class to its template.
        $this->assertGraphHasEdge($array, 'view:page', 'App\View\Components\Forms\Input', 'uses_component');
        $this->assertGraphHasEdge(
            $array,
            'App\View\Components\Forms\Input::render',
            'view:components.forms.input',
            'renders',
        );

        $unresolved = $array['meta']['analysis']['views']['unresolvedComponents'];
        $this->assertSame(1, $unresolved['count']);
        $this->assertSame('unknown-widget', $unresolved['samples'][0]['component']);
    }

    public function test_it_maps_named_route_consumption_inside_templates(): void
    {
        file_put_contents($this->fixturePath.'/resources/views/menu.blade.php', <<<'BLADE'
<a href="{{ route('notes.show', $note) }}">Show</a>
<a href="{{ route('missing.route') }}">Missing</a>
BLADE);

        $graph = new Graph();
        $graph->addNode(Node::make('route:GET:/notes/{note}', 'route', 'GET /notes/{note}', [
            'metadata' => ['name' => 'notes.show', 'uri' => 'notes/{note}', 'methods' => ['GET', 'HEAD']],
        ]));

        $array = $this->scan($graph)->toArray();

        $edge = $this->graphEdge($array, 'view:menu', 'route:GET:/notes/{note}', 'consumes_route');
        $this->assertSame('named_route_helper', $edge['metadata']['syntax']);
        $this->assertSame(0.98, $edge['confidence']);
        $this->assertSame(1, $array['meta']['analysis']['views']['unresolvedRoutes']['count']);
    }

    public function test_it_maps_mailable_views_and_mail_content_definitions(): void
    {
        mkdir($this->fixturePath.'/resources/views/mail', 0775, true);
        mkdir($this->fixturePath.'/app/Mail', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/mail/invoice.blade.php', '<p></p>');
        file_put_contents($this->fixturePath.'/resources/views/mail/invoice-text.blade.php', '<p></p>');
        file_put_contents($this->fixturePath.'/resources/views/mail/receipt.blade.php', '<p></p>');
        file_put_contents($this->fixturePath.'/resources/views/mail/receipt-text.blade.php', '<p></p>');
        file_put_contents($this->fixturePath.'/app/Mail/InvoicePaid.php', <<<'PHP'
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;

class InvoicePaid extends Mailable
{
    public function build()
    {
        return $this->markdown('mail.invoice');
    }

    public function plain()
    {
        return $this->text('mail.invoice-text');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.receipt', text: 'mail.receipt-text');
    }
}
PHP);

        $array = $this->scan(new Graph())->toArray();

        $markdown = $this->graphEdge($array, 'App\Mail\InvoicePaid::build', 'view:mail.invoice', 'renders');
        $this->assertSame('mailable_markdown', $markdown['metadata']['syntax']);

        $text = $this->graphEdge($array, 'App\Mail\InvoicePaid::plain', 'view:mail.invoice-text', 'renders');
        $this->assertSame('mailable_text', $text['metadata']['syntax']);

        $content = $this->graphEdge($array, 'App\Mail\InvoicePaid::content', 'view:mail.receipt', 'renders');
        $this->assertSame('mail_content', $content['metadata']['syntax']);
        $this->assertGraphHasEdge($array, 'App\Mail\InvoicePaid::content', 'view:mail.receipt-text', 'renders');
    }

    public function test_namespaced_views_are_reported_not_guessed(): void
    {
        file_put_contents($this->fixturePath.'/resources/views/note-mail.blade.php', "@extends('mail::message')");

        $array = $this->scan(new Graph())->toArray();

        $samples = $array['meta']['analysis']['views']['unresolvedViews']['samples'];
        $this->assertSame('mail::message', $samples[0]['view']);
        $this->assertSame('namespaced_view', $samples[0]['reason']);
    }

    public function test_earlier_view_paths_shadow_later_ones(): void
    {
        mkdir($this->fixturePath.'/custom/views', 0775, true);
        file_put_contents($this->fixturePath.'/resources/views/welcome.blade.php', 'first');
        file_put_contents($this->fixturePath.'/custom/views/welcome.blade.php', 'second');
        file_put_contents($this->fixturePath.'/custom/views/extra.blade.php', 'extra');

        $scanner = new ViewScanner(
            new FileFinder($this->fixturePath),
            viewPaths: ['resources/views', 'custom/views'],
        );
        $array = $scanner->scan(new Graph())->toArray();

        $this->assertSame('resources/views/welcome.blade.php', $this->graphNode($array, 'view:welcome')['file']);
        $this->assertGraphHasNode($array, 'view:extra', 'view');
    }

    private function scan(Graph $graph, ?SourceFileObservations $observations = null): Graph
    {
        return (new ViewScanner(
            new FileFinder($this->fixturePath),
            $observations ?? new SourceFileObservations(),
        ))->scan($graph);
    }
}
