<?php

declare(strict_types=1);

namespace PhpGraph\Tests;

use PhpGraph\Graph\Confidence;
use PhpGraph\Graph\NodeKind;
use PhpGraph\Graph\Relation;
use PhpGraph\Presentation\TextPresenter;
use PhpGraph\Query\GraphQuery;
use PhpGraph\Tests\Support\Dig;
use PhpGraph\Tests\Support\TemporaryProject;
use PHPUnit\Framework\TestCase;

/**
 * API schemas as contracts: OpenAPI operations linked to the routes declaring them, protobuf rpcs to the classes
 * implementing their generated service.
 */
final class ApiSchemasTest extends TestCase
{
    use TemporaryProject;

    private const GATEWAY = <<<'YAML'
        openapi: 3.1.0
        info: {title: Gateway, version: 1.0.0}
        servers:
          - url: http://localhost:9080/api
        paths:
          /hotels:
            get: {summary: List}
            post: {summary: Create}
          /hotels/{hotelId}:
            get: {summary: Show}
          /bookings/{id}/cancel:
            post: {summary: Cancel}
          /reports:
            get: {summary: Not built yet}
        YAML;

    public function testAGatewayDocumentIsLinkedToTheRoutesOfEveryService(): void
    {
        $result = $this->buildProject([
            'docs/openapi.yaml' => self::GATEWAY,
            'hotels/composer.json' => '{"name": "acme/hotels"}',
            'hotels/app/HotelController.php' => 'namespace App; class HotelController { public function index(): void {} public function store(): void {} public function show(): void {} public function destroy(): void {} }',
            'hotels/routes/api.php' => 'use Illuminate\Support\Facades\Route; Route::apiResource("hotels", App\HotelController::class);',
            'bookings/composer.json' => '{"name": "acme/bookings"}',
            'bookings/app/BookingController.php' => 'namespace App; class BookingController { public function cancel(): void {} }',
            'bookings/routes/api.php' => 'use Illuminate\Support\Facades\Route; Route::post("/bookings/{booking}/cancel", [App\BookingController::class, "cancel"]);',
        ]);
        $graph = $result->graph;

        $show = 'root@operation:docs/openapi.yaml#GET /api/hotels/{hotelId}';
        self::assertSame(NodeKind::Operation, $graph->node($show)?->kind);
        self::assertSame(9, $graph->node($show)->line);
        self::assertTrue($this->hasEdge($graph, $show, 'hotels@route:hotels/routes/api.php#GET /api/hotels/{hotel}', Relation::ImplementedBy, Confidence::Inferred), 'placeholders match whatever their names; the server path is the prefix');
        self::assertTrue($this->hasEdge($graph, 'root@operation:docs/openapi.yaml#POST /api/bookings/{id}/cancel', 'bookings@route:bookings/routes/api.php#POST /api/bookings/{booking}/cancel', Relation::ImplementedBy));

        $outline = (new TextPresenter(new GraphQuery($graph, null, $this->root)))->outline('hotel controller');
        self::assertStringContainsString('GET /api/hotels/{hotel} → HotelController::show() (API: GET /api/hotels/{hotelId} docs/openapi.yaml:9)', $outline, 'the routes of a service');

        $stats = $result->apiSchemas;
        self::assertSame([1, 5, 4], [$stats->documents, $stats->operations, $stats->implemented]);
        self::assertSame(['GET /api/reports (docs/openapi.yaml:13)'], $stats->notImplemented);
        self::assertSame(['hotels: PUT|PATCH /api/hotels/{hotel} (hotels/routes/api.php:2)', 'hotels: DELETE /api/hotels/{hotel} (hotels/routes/api.php:2)'], $stats->undocumented);
    }

    public function testADocumentOfAServiceDescribesThatServiceOnly(): void
    {
        $spec = '{"swagger": "2.0", "basePath": "/v1", "paths": {"/orders": {"get": {}}, "/hotels": {"get": {}}}}';
        $result = $this->buildProject([
            'orders/composer.json' => '{"name": "acme/orders"}',
            'orders/swagger.json' => $spec,
            'orders/src/Orders.php' => 'namespace App; class Orders { #[\Symfony\Component\Routing\Attribute\Route("/v1/orders", methods: ["GET"])] public function list(): void {} }',
            'hotels/composer.json' => '{"name": "acme/hotels"}',
            'hotels/src/Hotels.php' => 'namespace App; class Hotels { #[\Symfony\Component\Routing\Attribute\Route("/v1/hotels", methods: ["GET"])] public function list(): void {} }',
        ]);

        self::assertTrue($this->hasEdge($result->graph, 'orders@operation:orders/swagger.json#GET /v1/orders', 'orders@route:orders/src/Orders.php#GET /v1/orders', Relation::ImplementedBy), 'Swagger 2: the base path');
        self::assertSame(['GET /v1/hotels (orders/swagger.json:1)'], $result->apiSchemas->notImplemented, 'the route of another service does not implement it');
        self::assertSame(1, $result->apiSchemas->routesCovered);
    }

    public function testAnRpcIsLinkedToTheClassesImplementingItsGeneratedService(): void
    {
        $result = $this->buildProject([
            'proto/cinema.proto' => "syntax = \"proto3\";\npackage api.cinema.v1;\noption php_namespace = \"Shared\\\\Cinema\";\n\nservice CinemaService {\n    rpc Reserve (ReserveRequest) returns (ReserveResponse) {}\n    rpc Refund (RefundRequest) returns (RefundResponse) {}\n}\n",
            'shared/composer.json' => '{"name": "acme/shared"}',
            'shared/src/CinemaServiceInterface.php' => 'namespace Shared\Cinema; interface CinemaServiceInterface { public function Reserve(): void; public function Refund(): void; }',
            'shared/src/CinemaServiceClient.php' => 'namespace Shared\Cinema; class CinemaServiceClient implements CinemaServiceInterface { public function Reserve(): void {} public function Refund(): void {} }',
            'cinema/composer.json' => '{"name": "acme/cinema", "require": {"acme/shared": "*"}}',
            'cinema/src/CinemaService.php' => 'namespace Cinema; class CinemaService implements \Shared\Cinema\CinemaServiceInterface { public function Reserve(): void {} public function Refund(): void {} }',
            'web/composer.json' => '{"name": "acme/web", "require": {"acme/shared": "*"}}',
            'web/src/Booking.php' => 'namespace Web; class Booking { public function __construct(private \Shared\Cinema\CinemaServiceInterface $cinema) {} public function book(): void { $this->cinema->Reserve(); } }',
        ]);
        $graph = $result->graph;
        $reserve = 'root@operation:proto/cinema.proto#api.cinema.v1.CinemaService/Reserve';

        self::assertSame('CinemaService.Reserve', $graph->node($reserve)?->label);
        self::assertSame(6, $graph->node($reserve)->line);
        self::assertTrue($this->hasEdge($graph, $reserve, 'cinema@Cinema\CinemaService::Reserve', Relation::ImplementedBy, Confidence::Inferred));
        self::assertFalse($this->hasEdge($graph, $reserve, 'root@Shared\Cinema\CinemaServiceClient::Reserve', Relation::ImplementedBy), 'the generated client calls it, it does not serve it');
        self::assertTrue($this->hasEdge($graph, $reserve, 'root@Shared\Cinema\CinemaServiceInterface::Reserve', Relation::References), 'its callers are the clients');
        self::assertSame([2, 2], [$result->apiSchemas->rpcs, $result->apiSchemas->rpcsImplemented]);
    }

    public function testTheDocumentOfAnotherApiIsLinkedToTheCallsOfItsClient(): void
    {
        $result = $this->buildProject([
            'docs/marketplace.yaml' => "swagger: '2.0'\nhost: marketplace.example.com\nbasePath: /api/1.0\npaths:\n  /extensions:\n    get: {}\n  /app/{id}/challenge:\n    post: {}\n",
            'src/Marketplace.php' => 'namespace App; class Marketplace { public function __construct(private \\GuzzleHttp\\Client $client) {}'
                . ' public function extensions(): void { $this->client->request("GET", "/api/1.0/extensions"); }'
                . ' public function challenge(string $id): void { $this->client->request("POST", \\sprintf("/api/1.0/app/%s/challenge", $id)); } }',
            'src/Home.php' => 'namespace App; class Home { #[\\Symfony\\Component\\Routing\\Attribute\\Route("/", methods: ["GET"])] public function __invoke(): void {} }',
        ]);
        $graph = $result->graph;

        self::assertTrue($this->hasEdge($graph, 'App\\Marketplace::extensions', 'operation:docs/marketplace.yaml#GET /api/1.0/extensions', Relation::Requests, Confidence::Inferred));
        self::assertTrue($this->hasEdge($graph, 'App\\Marketplace::challenge', 'operation:docs/marketplace.yaml#POST /api/1.0/app/{id}/challenge', Relation::Requests, Confidence::Ambiguous), 'sprintf: a computed segment');
        self::assertSame([1, 1, 2, 0, 0], [$result->apiSchemas->documents, $result->apiSchemas->otherApis, $result->apiSchemas->requests, $result->apiSchemas->operations, $result->apiSchemas->routesCovered], 'the routes of the project are not this API');
    }

    public function testAnOpenApiDocumentInAConfigDirectoryIsNoRoutingFile(): void
    {
        $result = $this->buildProject([
            'config/routes/openapi.yaml' => "openapi: 3.0.0\npaths:\n  /status:\n    get: {}\n",
            'src/Status.php' => 'namespace App; class Status { #[\Symfony\Component\Routing\Attribute\Route("/status", methods: ["GET"])] public function __invoke(): void {} }',
        ]);

        self::assertSame(1, $result->http->routes, 'the document declares no route');
        self::assertTrue($this->hasEdge($result->graph, 'operation:config/routes/openapi.yaml#GET /status', 'route:src/Status.php#GET /status', Relation::ImplementedBy));
    }

    private const CONTRACTS = [
        'openapi.yaml' => "openapi: 3.0.0\npaths:\n  /orders:\n    get: {}\n  /status:\n    get: {}\n",
        'src/Http/Orders.php' => 'namespace App\\Http; class Orders { public function __construct(private \\App\\Repository $repository) {}'
            . ' #[\\Symfony\\Component\\Routing\\Attribute\\Route("/orders", methods: ["GET"])] public function list(): void { $this->repository->all(); } }',
        'src/Repository.php' => 'namespace App; class Repository { public function all(): array { return []; } }',
        'src/Status.php' => 'namespace App; class Status { #[\\Symfony\\Component\\Routing\\Attribute\\Route("/status", methods: ["GET"])] public function __invoke(): void {} }',
        'proto/cinema.proto' => "syntax = \"proto3\";\npackage cinema;\noption php_namespace = \"Cinema\";\nservice CinemaService {\n    rpc Reserve (ReserveRequest) returns (ReserveResponse) {}\n}\n",
        'src/CinemaServiceInterface.php' => 'namespace Cinema; interface CinemaServiceInterface { public function Reserve(): void; }',
        'src/CinemaService.php' => 'namespace Cinema; class CinemaService implements CinemaServiceInterface { public function __construct(private \\App\\Repository $repository) {} public function Reserve(): void { $this->repository->all(); } }',
    ];

    public function testTheImpactOfAChangeListsTheOperationsBehindIt(): void
    {
        $result = $this->buildProject(self::CONTRACTS);
        $presenter = new TextPresenter(new GraphQuery($result->graph));

        $text = $presenter->impact('App\Repository::all');
        self::assertStringContainsString(', 2 API operations,', $text);
        self::assertStringContainsString('  GET /orders openapi.yaml L3 ← GET /orders src/Http/Orders.php L2', $text);
        self::assertStringContainsString('  CinemaService.Reserve proto/cinema.proto L5 ← CinemaService::Reserve() src/CinemaService.php L2', $text);
        self::assertStringNotContainsString('GET /status', $text);

        $json = json_decode($presenter->impact('App\Repository::all', 3, 40, 'operations', 'json'), true);
        self::assertSame(['GET /orders', 'CinemaService.Reserve'], array_map(static fn (mixed $operation): string => Dig::text($operation, 'operation'), Dig::list($json, 'operations')));
    }

    public function testTheOutlineOfAFeatureNamesTheOperationsItServes(): void
    {
        $result = $this->buildProject(self::CONTRACTS);
        $presenter = new TextPresenter(new GraphQuery($result->graph, null, $this->root));

        self::assertStringContainsString('GET /orders → Orders::list() (API: GET /orders openapi.yaml:3)', $presenter->outline('orders'));
        self::assertStringContainsString("API operations its methods implement:\n    CinemaService.Reserve proto/cinema.proto:5 → CinemaService::Reserve()", $presenter->outline('cinema service'));
    }
}
