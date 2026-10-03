<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\Graph;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Project\BuildOptions;
use PhpGraph\Project\ProjectSummary;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * Routes as entry points, from Symfony attributes, Laravel route files and YAML routing files alike, and HTTP calls
 * reaching them.
 */
final class HttpTest extends TestCase
{
    use TemporaryProject;

    private const TWO_APPS = [
        'apps/mooc/src/GetCoursesController.php' => 'namespace App\Mooc; class GetCoursesController { public function __invoke(): void {} }',
        'apps/mooc/src/PutCourseController.php' => 'namespace App\Mooc; class PutCourseController { public function __invoke(): void {} }',
        'apps/mooc/config/routes.yaml' => "courses_get:\n    path: /courses\n    methods: [GET]\n    controller: App\\Mooc\\GetCoursesController\ncourse_put:\n    path: /courses/{id}\n    methods: [PUT]\n    controller: App\\Mooc\\PutCourseController\n",
        'apps/backoffice/config/routes.yaml' => "api_courses_get:\n    path: /courses\n    methods: [GET]\n    controller: App\\Backoffice\\ApiCoursesGetController\nstudents:\n    path: /students\n    methods: [GET]\n    controller: App\\Mooc\\GetCoursesController\n",
    ];

    public function testSymfonyAttributesWithAClassPrefix(): void
    {
        $graph = $this->buildProject([
            'src/OrderController.php' => 'namespace App; use Symfony\Component\Routing\Attribute\Route; #[Route("/orders")] class OrderController { #[Route("/{id}", methods: ["GET"])] public function show(int $id): void {} }',
            'src/HealthCheck.php' => 'namespace App; use Symfony\Component\Routing\Attribute\Route; #[Route("/health", methods: "GET")] class HealthCheck { public function __invoke(): void {} }',
        ])->graph;

        self::assertSame(['GET /health', 'GET /orders/{id}'], $this->routes($graph));
        self::assertTrue($this->hasEdge($graph, 'route:src/OrderController.php#GET /orders/{id}', 'App\OrderController::show', Relation::HandledBy, Confidence::Extracted));
        self::assertTrue($this->hasEdge($graph, 'route:src/HealthCheck.php#GET /health', 'App\HealthCheck::__invoke', Relation::HandledBy, Confidence::Extracted));
    }

    public function testLaravelRouteFiles(): void
    {
        $graph = $this->buildProject([
            'app/Http/Controllers/BookController.php' => 'namespace App\Http\Controllers; class BookController { public function index(): void {} public function store(): void {} } class ShowHome { public function __invoke(): void {} } class PhotoController { public function show(): void {} }',
            'routes/web.php' => 'use App\Http\Controllers\BookController; use App\Http\Controllers\ShowHome; use App\Http\Controllers\PhotoController; use Illuminate\Support\Facades\Route;'
                . ' Route::get("/", ShowHome::class);'
                . ' Route::middleware("auth")->group(function () { Route::prefix("shelf")->group(function () { Route::get("/books", [BookController::class, "index"]); Route::match(["GET", "POST"], "/books/new", [BookController::class, "store"]); }); });'
                . ' Route::resource("photos", PhotoController::class);',
            'routes/api.php' => 'use Illuminate\Support\Facades\Route; Route::get("/books", "App\Http\Controllers\BookController@index");',
        ])->graph;

        $routes = $this->routes($graph);
        foreach (['GET /', 'GET /shelf/books', 'GET|POST /shelf/books/new', 'GET /api/books', 'GET /photos/{id}', 'DELETE /photos/{id}'] as $expected) {
            self::assertContains($expected, $routes);
        }
        self::assertTrue($this->hasEdge($graph, 'route:routes/web.php#GET /shelf/books', 'App\Http\Controllers\BookController::index', Relation::HandledBy));
        self::assertTrue($this->hasEdge($graph, 'route:routes/api.php#GET /api/books', 'App\Http\Controllers\BookController::index', Relation::HandledBy));
        self::assertTrue($this->hasEdge($graph, 'route:routes/web.php#GET /photos/{id}', 'App\Http\Controllers\PhotoController::show', Relation::HandledBy));
    }

    public function testYamlRoutingFilesWithPrefixedImports(): void
    {
        $graph = $this->buildProject([
            'src/ShopBundle/AcmeShopBundle.php' => 'namespace Acme\ShopBundle; class AcmeShopBundle {}',
            'src/ShopBundle/Controller/CartController.php' => 'namespace Acme\ShopBundle\Controller; class CartController { public function summary(): void {} }',
            'src/ShopBundle/Resources/config/routing.yml' => "cart_summary:\n    path: /cart\n    methods: [GET]\n    defaults:\n        _controller: Acme\\ShopBundle\\Controller\\CartController::summary\n",
            'config/routes.yaml' => "shop:\n    resource: '@AcmeShopBundle/Resources/config/routing.yml'\n    prefix: /shop\n\nproduct_index:\n    path: /products\n    controller: sylius.controller.product::indexAction\n",
        ])->graph;

        self::assertSame(['ANY /products', 'GET /shop/cart'], $this->routes($graph));
        self::assertTrue($this->hasEdge($graph, 'route:src/ShopBundle/Resources/config/routing.yml#GET /shop/cart', 'Acme\ShopBundle\Controller\CartController::summary', Relation::HandledBy, Confidence::Extracted));
        self::assertSame(1, $graph->node('route:src/ShopBundle/Resources/config/routing.yml#GET /shop/cart')?->line);
    }

    public function testControllersNamedByAServiceIdAreResolvedThroughTheContainerConfiguration(): void
    {
        $result = $this->buildProject([
            'src/Controller/ProductController.php' => 'namespace App\Controller; class ProductController { public function getAction(): void {} }',
            'src/Controller/CartController.php' => 'namespace App\Controller; class CartController { public function __invoke(): void {} }',
            'src/Controller/OrderController.php' => 'namespace App\Controller; class OrderController { public function show(): void {} }',
            'src/Controller/LegacyController.php' => 'namespace App\Controller; class LegacyController { public function index(): void {} }',
            'config/services.yaml' => "parameters:\n    app.product_controller.class: App\\Controller\\ProductController\n\nservices:\n    _defaults:\n        autowire: true\n    app.controller.product:\n        class: '%app.product_controller.class%'\n    app.controller.cart_alias: '@app.controller.cart'\n    app.controller.cart:\n        class: App\\Controller\\CartController\n    App\\Controller\\:\n        resource: '../src/Controller/'\n",
            'config/legacy/services.xml' => '<?xml version="1.0" ?><container xmlns="http://symfony.com/schema/dic/services"><services><service id="app.controller.legacy" class="App\Controller\LegacyController"/></services></container>',
            'config/services.php' => 'use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator; return static function (ContainerConfigurator $container): void { $container->services()->set("app.controller.order", App\Controller\OrderController::class)->public(); };',
            'config/routes.yaml' => "product:\n    path: /products/{id}\n    controller: app.controller.product:getAction\n"
                . "cart:\n    path: /cart\n    controller: app.controller.cart_alias\n"
                . "order:\n    path: /orders/{id}\n    defaults: { _controller: 'app.controller.order::show' }\n"
                . "legacy:\n    path: /legacy\n    controller: app.controller.legacy::index\n"
                . "home:\n    path: /\n    controller: Symfony\\Bundle\\FrameworkBundle\\Controller\\TemplateController\n"
                . "generated:\n    path: /generated\n    controller: app.controller.generated_by_a_bundle::indexAction\n"
                . "deleted:\n    path: /deleted\n    controller: App\\Controller\\DeletedController\n",
            'composer.json' => '{}',
            'vendor/composer/installed.json' => '{"packages": [{"name": "symfony/framework-bundle", "install-path": "../symfony/framework-bundle", "autoload": {"psr-4": {"Symfony\\\\Bundle\\\\FrameworkBundle\\\\": ""}}}]}',
            'vendor/symfony/framework-bundle/Controller/TemplateController.php' => 'namespace Symfony\Bundle\FrameworkBundle\Controller; class TemplateController { public function __invoke(): void {} }',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'route:config/routes.yaml#ANY /products/{id}', 'App\Controller\ProductController::getAction', Relation::HandledBy), 'YAML class held in a parameter');
        self::assertTrue($this->hasEdge($graph, 'route:config/routes.yaml#ANY /cart', 'App\Controller\CartController::__invoke', Relation::HandledBy), 'YAML alias, invokable');
        self::assertTrue($this->hasEdge($graph, 'route:config/routes.yaml#ANY /orders/{id}', 'App\Controller\OrderController::show', Relation::HandledBy), 'PHP configurator');
        self::assertTrue($this->hasEdge($graph, 'route:config/routes.yaml#ANY /legacy', 'App\Controller\LegacyController::index', Relation::HandledBy), 'XML');
        self::assertSame(7, $result->http->routes);
        self::assertSame(4, $result->http->routesWithHandler);
        self::assertSame(1, $result->http->routesToDependencies, 'TemplateController is a class of a dependency');
        self::assertSame(1, $result->http->routesToMissingControllers, 'DeletedController is declared nowhere');
        self::assertSame(['ANY /deleted -> App\Controller\DeletedController (config/routes.yaml:19)'], $result->http->missingControllers);
    }

    public function testQueryGraphAnswersQuestionsAboutRoutes(): void
    {
        $query = new GraphQuery($this->buildProject(self::TWO_APPS)->graph);
        $seeds = fn (string $question): array => array_map(static fn ($node): string => $node->label, $query->subgraph($question)->seeds);

        self::assertSame(['GET /courses', 'PUT /courses/{id}'], $seeds('mooc courses routes'), 'the other words narrow by routing file and path');
        self::assertSame(['GET /courses', 'GET /courses', 'PUT /courses/{id}'], $seeds('courses routes HTTP'));
        self::assertSame(['PUT /courses/{id}'], $seeds('PUT /courses/42'));
        self::assertSame(['GET /courses', 'GET /courses', 'PUT /courses/{id}', 'GET /students'], $seeds('endpoints'));
        self::assertContains('App\Mooc\PutCourseController::__invoke', array_map(static fn ($node): string => $node->id, $query->subgraph('mooc courses routes')->nodes));
        self::assertSame(['PutCourseController', 'PutCourseController::__invoke()'], $seeds('PutCourseController'), 'other questions are unchanged');
    }

    public function testTheOverviewListsRoutesAndTheControllersFoundNowhere(): void
    {
        $result = $this->buildProject(self::TWO_APPS);
        $text = (new TextPresenter(new GraphQuery($result->graph, ProjectSummary::fromBuild($result, new BuildOptions(), []))))->overview();

        self::assertStringContainsString('- PUT /courses/{id} -> PutCourseController::__invoke() (apps/mooc/config/routes.yaml L5)', $text);
        self::assertStringContainsString('- GET /courses -> no controller in the graph (apps/backoffice/config/routes.yaml L1)', $text);
        self::assertStringContainsString('1 routes name a controller class declared nowhere in the project', $text);
        self::assertStringContainsString('GET /courses -> App\Backoffice\ApiCoursesGetController (apps/backoffice/config/routes.yaml:1)', $text);

        $many = [];
        for ($i = 0; $i < 40; ++$i) {
            $many['src/Controller' . $i . '.php'] = sprintf('namespace App; use Symfony\Component\Routing\Attribute\Route; class Controller%d { #[Route("/%s/%d", methods: "GET")] public function show(): void {} }', $i, $i % 2 === 0 ? 'orders' : 'invoices', $i);
        }
        $result = $this->buildProject($many);
        $text = (new TextPresenter(new GraphQuery($result->graph, ProjectSummary::fromBuild($result, new BuildOptions(), []))))->overview();

        self::assertStringContainsString('44 routes, by path prefix', $text);
        self::assertStringContainsString('- /orders: 20 (src/Controller0.php, src/Controller10.php, src/Controller12.php, ...)', $text);
    }

    public function testApiPlatformResourcesAreRoutesHandledByTheirStateClasses(): void
    {
        $result = $this->buildProject([
            'src/Resource/SupplierResource.php' => 'namespace App\Resource; use ApiPlatform\Metadata\ApiResource; use ApiPlatform\Metadata\Post; use ApiPlatform\Metadata\Get; use App\State\RetrieveProcessor; use App\State\SupplierProvider; use App\Dto\Payload;'
                . ' #[ApiResource(shortName: "Supplier", routePrefix: "/PO/010_supplier", operations: ['
                . ' new Post(uriTemplate: "/retrieve-supplier.{_format}", input: Payload::class, processor: RetrieveProcessor::class, extraProperties: ["use_case" => \App\UseCase\RetrieveSupplier::class]),'
                . ' new Get(uriTemplate: "/suppliers/{id}", provider: SupplierProvider::class)])] final class SupplierResource {}',
            'src/Resource/PurchaseOrder.php' => 'namespace App\Resource; use ApiPlatform\Metadata\ApiResource; #[ApiResource] class PurchaseOrder {}',
            'src/Resource/Archive.php' => 'namespace App\Resource; use ApiPlatform\Metadata\ApiResource; use ApiPlatform\Metadata\HttpOperation; use Symfony\Component\HttpFoundation\Request;'
                . ' #[ApiResource(operations: [new HttpOperation(method: Request::METHOD_PUT, uriTemplate: "/archives/{id}")])] class Archive {}',
            'src/Resource/Category.php' => 'namespace App\Resource; use ApiPlatform\Metadata\GetCollection; #[GetCollection(controller: \App\Controller\ListCategories::class)] class Category {}',
            'src/State/RetrieveProcessor.php' => 'namespace App\State; class RetrieveProcessor { public function process(): void {} }',
            'src/State/SupplierProvider.php' => 'namespace App\State; class SupplierProvider { public function provide(): void {} }',
            'src/Controller/ListCategories.php' => 'namespace App\Controller; class ListCategories { public function __invoke(): void {} }',
            'src/Dto/Payload.php' => 'namespace App\Dto; class Payload {}',
            'src/UseCase/RetrieveSupplier.php' => 'namespace App\UseCase; class RetrieveSupplier {}',
            'config/routes/api_platform.php' => 'use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator; return static function (RoutingConfigurator $routes): void { $routes->import(".", "api_platform")->prefix("/api"); };',
        ]);
        $graph = $result->graph;
        $post = 'route:src/Resource/SupplierResource.php#POST /api/PO/010_supplier/retrieve-supplier';

        self::assertContains('POST /api/PO/010_supplier/retrieve-supplier', $this->routes($graph), 'uriTemplate, routePrefix, the api_platform import prefix, no .{_format}');
        self::assertTrue($this->hasEdge($graph, $post, 'App\State\RetrieveProcessor::process', Relation::HandledBy, Confidence::Extracted), 'a write is handled by its processor');
        self::assertTrue($this->hasEdge($graph, 'route:src/Resource/SupplierResource.php#GET /api/PO/010_supplier/suppliers/{id}', 'App\State\SupplierProvider::provide', Relation::HandledBy), 'a read by its provider');
        foreach (['App\Resource\SupplierResource', 'App\Dto\Payload', 'App\UseCase\RetrieveSupplier'] as $class) {
            self::assertTrue($this->hasEdge($graph, $post, $class, Relation::References, Confidence::Extracted), $class);
        }
        foreach (['GET /api/purchase_orders/{id}', 'GET /api/purchase_orders', 'POST /api/purchase_orders', 'PATCH /api/purchase_orders/{id}', 'DELETE /api/purchase_orders/{id}'] as $default) {
            self::assertContains($default, $this->routes($graph), 'default operations, snake_case and plural');
        }
        self::assertContains('PUT /api/archives/{id}', $this->routes($graph), 'a method named by a constant');
        self::assertTrue($this->hasEdge($graph, 'route:src/Resource/Category.php#GET /api/categories', 'App\Controller\ListCategories::__invoke', Relation::HandledBy), 'an operation attribute alone, with a controller');
        self::assertSame(6, $result->http->routesToDependencies, 'PurchaseOrder and Archive: API Platform itself handles them');

        unlink($this->root . '/config/routes/api_platform.php');
        $this->write(['config/routes/api_platform.yaml' => "api_platform:\n    resource: .\n    type: api_platform\n    prefix: /v2\n"]);
        self::assertContains('GET /v2/categories', $this->routes((new \PhpGraph\Builder\GraphBuilder())->build($this->root)->graph), 'the prefix of a YAML import');
    }

    public function testRoutesOfPhpRoutingFilesAndPathsHeldInConstants(): void
    {
        $graph = $this->buildProject([
            'src/HealthController.php' => 'namespace App; class HealthController { public const ROUTE = "/liveness"; public function __invoke(): void {} }',
            'src/ProblemController.php' => 'namespace App; use Symfony\Component\Routing\Attribute\Route; class ProblemController { private const BASE = "/problems"; #[Route(self::BASE . "/{type}", methods: ["GET"])] public function show(): void {} }',
            'src/OrderController.php' => 'namespace App; class OrderController { public function show(): void {} }',
            'config/routes/health.php' => 'use App\HealthController; use App\OrderController; use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;'
                . ' return static function (RoutingConfigurator $routes): void {'
                . ' $routes->add("liveness", HealthController::ROUTE)->controller(HealthController::class);'
                . ' $routes->add("order_show", "/orders/{id}")->controller([OrderController::class, "show"])->methods(["GET"]); };',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'route:config/routes/health.php#ANY /liveness', 'App\HealthController::__invoke', Relation::HandledBy), 'a constant of another file');
        self::assertTrue($this->hasEdge($graph, 'route:config/routes/health.php#GET /orders/{id}', 'App\OrderController::show', Relation::HandledBy));
        self::assertTrue($this->hasEdge($graph, 'route:src/ProblemController.php#GET /problems/{type}', 'App\ProblemController::show', Relation::HandledBy), 'self::CONSTANT in an attribute');
    }

    public function testHttpCallsReachTheRoutesOfAnotherService(): void
    {
        $result = $this->buildProject([
            'billing/composer.json' => '{"autoload": {"psr-4": {"App\\\\\\\\": "src/"}}}',
            'billing/src/InvoiceController.php' => 'namespace App; use Symfony\Component\Routing\Attribute\Route; class InvoiceController { #[Route("/invoices/{id}", methods: ["GET"])] public function show(): void {} #[Route("/invoices", methods: ["POST"])] public function create(): void {} }',
            'shop/composer.json' => '{"autoload": {"psr-4": {"App\\\\\\\\": "src/"}}}',
            'shop/src/BillingClient.php' => 'namespace App; class BillingClient { public function __construct(private \Symfony\Contracts\HttpClient\HttpClientInterface $http, private string $base) {}'
                . ' public function create(): void { $this->http->request("POST", "http://billing/invoices"); }'
                . ' public function show(int $id): void { $this->http->request("GET", "{$this->base}/invoices/$id"); }'
                . ' public function rates(): void { $this->http->request("GET", "https://api.exchange.example/rates"); } }',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'shop@App\BillingClient::create', 'billing@route:billing/src/InvoiceController.php#POST /invoices', Relation::Requests, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'shop@App\BillingClient::show', 'billing@route:billing/src/InvoiceController.php#GET /invoices/{id}', Relation::Requests, Confidence::Ambiguous), 'Part of the path is computed');
        self::assertSame(3, $result->http->requests);
        self::assertSame(2, $result->http->requestsToProject);
        self::assertSame(2, $result->http->requestsToServices);
    }

    public function testACallWithTheWrongMethodIsLinkedAmbiguousAndReported(): void
    {
        $result = $this->buildProject([
            'products/composer.json' => '{"autoload": {"psr-4": {"App\\\\\\\\": "app/"}}}',
            'products/app/ProductController.php' => 'namespace App; class ProductController { public function show(): void {} public function info(): void {} }',
            'products/routes/api.php' => 'use Illuminate\Support\Facades\Route; Route::get("/products/{id}", [App\ProductController::class, "show"]); Route::get("/products/info", [App\ProductController::class, "info"]);',
            'orders/composer.json' => '{"autoload": {"psr-4": {"App\\\\\\\\": "app/"}}}',
            'orders/app/OrderController.php' => 'namespace App; use Illuminate\Support\Facades\Http; class OrderController { public function store(): void { Http::post("http://products/api/products/info"); } }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'orders@App\OrderController::store', 'products@route:products/routes/api.php#GET /api/products/info', Relation::Requests, Confidence::Ambiguous));
        self::assertSame(1, $result->http->methodMismatchCount);
        self::assertSame(['POST /api/products/info, declared for GET /api/products/info'], $result->http->methodMismatches, 'The most specific route, not /products/{id}');
        self::assertSame(0, $result->http->requestsToProject);
    }

    public function testLaravelHttpFacade(): void
    {
        $graph = $this->buildProject([
            'app/OrderController.php' => 'namespace App; use Illuminate\Support\Facades\Route; class OrderController { public function store(): void {} }',
            'routes/api.php' => 'use Illuminate\Support\Facades\Route; Route::post("/orders", [App\OrderController::class, "store"]);',
            'app/Client.php' => 'namespace App; use Illuminate\Support\Facades\Http; class Client { public function order(): void { Http::withToken("x")->post("http://orders.internal/api/orders"); } }',
        ])->graph;

        self::assertTrue($this->hasEdge($graph, 'App\Client::order', 'route:routes/api.php#POST /api/orders', Relation::Requests, Confidence::Inferred));
    }

    public function testCallsThatAreNotHttpAreIgnored(): void
    {
        $graph = $this->buildProject([
            'src/Service.php' => 'namespace App; class Service { public function run(\Psr\Container\ContainerInterface $container, $request): void { $container->get("/orders"); $request->get("page"); } }',
            'src/OrderController.php' => 'namespace App; use Symfony\Component\Routing\Attribute\Route; class OrderController { #[Route("/orders")] public function index(): void {} }',
        ])->graph;

        foreach ($graph->edges() as $edge) {
            self::assertNotSame(Relation::Requests, $edge->relation, 'A container is not an HTTP client');
        }
    }

    /**
     * @return list<string> labels of the route nodes, sorted
     */
    private function routes(Graph $graph): array
    {
        $routes = [];
        foreach ($graph->nodes() as $node) {
            if ($node->kind === NodeKind::Route) {
                $routes[] = $node->label;
            }
        }
        sort($routes);

        return $routes;
    }
}
