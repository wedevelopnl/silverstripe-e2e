<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration\Fixtures;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\E2e\Fixtures\FixtureController;
use WeDevelop\E2e\Fixtures\FixtureLoader;
use WeDevelop\E2e\Tests\Support\E2eFixtureTestPage;
use WeDevelop\E2e\Tests\Support\E2eOtherTestPage;
use WeDevelop\E2e\Tests\Support\E2eVersionedObject;

#[CoversClass(FixtureController::class)]
final class FixtureControllerTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        E2eFixtureTestPage::class,
        E2eOtherTestPage::class,
        E2eVersionedObject::class,
    ];

    private string $originalEnvironment = '';

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = Injector::inst()->get(Kernel::class);
        $this->originalEnvironment = $kernel->getEnvironment();
        $kernel->setEnvironment('dev');

        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
        ]);
        Config::modify()->set(FixtureLoader::class, 'fixture_page_classes', [
            E2eFixtureTestPage::class,
        ]);
        Config::modify()->set(FixtureLoader::class, 'purge_classes', [
            E2eFixtureTestPage::class,
            E2eOtherTestPage::class,
        ]);
    }

    protected function tearDown(): void
    {
        Injector::inst()->get(Kernel::class)->setEnvironment($this->originalEnvironment);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $postVars
     * @param array<string, string> $getVars
     */
    private function dispatch(string $method, string $url, array $postVars = [], array $getVars = []): HTTPResponse|HTTPResponse_Exception
    {
        $request = new HTTPRequest($method, $url, $getVars, $postVars);
        $request->setSession(new Session([]));

        try {
            return FixtureController::create()->handleRequest($request);
        } catch (HTTPResponse_Exception $exception) {
            return $exception;
        }
    }

    public function testLoadReturnsSuccessJson(): void
    {
        $response = $this->dispatch('POST', 'load', ['fixture' => 'simple-page']);

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        /** @var array{success: bool, fixture: string, data: array<string, mixed>} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertSame('simple-page', $body['fixture']);
        self::assertArrayHasKey('pageId', $body['data']);
    }

    public function testLoadWithoutFixtureParamReturns400(): void
    {
        $response = $this->dispatch('POST', 'load');

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        // The message reads `Missing required "fixture" parameter`, but the raw JSON
        // body escapes the inner quotes (`\"fixture\"`), so assert on the unquoted
        // stem that survives JSON encoding.
        self::assertStringContainsString('Missing required', (string) $response->getBody());
    }

    public function testLoadUnknownFixtureReturns400(): void
    {
        $response = $this->dispatch('POST', 'load', ['fixture' => 'nope']);

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Unknown fixture', (string) $response->getBody());
    }

    public function testLoadReturnsJsonErrorWhenFixtureCreatesNoSiteTree(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'no-sitetree' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/no-sitetree.yml',
        ]);

        $response = $this->dispatch('POST', 'load', ['fixture' => 'no-sitetree']);

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());

        /** @var array{success: bool, error: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
        self::assertStringContainsString('did not create any SiteTree', $body['error']);
    }

    public function testResetWithConfirmReturnsSuccess(): void
    {
        $response = $this->dispatch('POST', 'reset', [], ['confirm' => '1']);

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testResetWithoutConfirmReturns400(): void
    {
        $response = $this->dispatch('POST', 'reset');

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('confirm=1', (string) $response->getBody());
    }

    public function testLoadAllReturnsSuccessJsonForEveryFixture(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
            'fallback-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/fallback-page.yml',
        ]);
        Config::modify()->set(FixtureLoader::class, 'fixture_page_classes', [
            E2eFixtureTestPage::class,
            E2eOtherTestPage::class,
        ]);

        $response = $this->dispatch('POST', 'load-all');

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        /** @var array{success: bool, fixtures: array<string, array<string, mixed>>} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertArrayHasKey('simple-page', $body['fixtures']);
        self::assertArrayHasKey('fallback-page', $body['fixtures']);
        self::assertArrayHasKey('pageId', $body['fixtures']['simple-page']);
    }

    public function testLoadAllWithNoFixturesConfiguredReturns400(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', []);

        $response = $this->dispatch('POST', 'load-all');

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());

        /** @var array{success: bool, error: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
        self::assertStringContainsString('No fixtures', $body['error']);
    }

    public function testLoadAllIsFailFastReturns500WhenFixtureCreatesNoSiteTree(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'simple-page' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/simple-page.yml',
            'broken' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/no-sitetree.yml',
        ]);

        $response = $this->dispatch('POST', 'load-all');

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('did not create any SiteTree', (string) $response->getBody());
    }

    public function testLoadAllIsFailFastReturns400WhenFixturePathInvalid(): void
    {
        Config::modify()->set(FixtureLoader::class, 'fixtures', [
            'missing-file' => 'wedevelopnl/silverstripe-e2e:tests/Support/fixtures/does-not-exist.yml',
        ]);

        $response = $this->dispatch('POST', 'load-all');

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('Fixture file not found', (string) $response->getBody());
    }

    public function testLoadAllNonDevRequestReturns404(): void
    {
        Injector::inst()->get(Kernel::class)->setEnvironment('live');

        $response = $this->dispatch('POST', 'load-all');

        self::assertInstanceOf(HTTPResponse_Exception::class, $response);
        self::assertSame(404, $response->getResponse()->getStatusCode());
    }

    public function testNonDevRequestReturns404(): void
    {
        Injector::inst()->get(Kernel::class)->setEnvironment('live');

        $response = $this->dispatch('POST', 'load', ['fixture' => 'simple-page']);

        self::assertInstanceOf(HTTPResponse_Exception::class, $response);
        self::assertSame(404, $response->getResponse()->getStatusCode());
    }

    /**
     * canInit() is layer 1 of the security posture: DevelopmentAdmin consults it
     * before exposing the route. It is not reached by the isolated handleRequest
     * harness, so cover the dev gate directly.
     */
    public function testCanInitReflectsDevEnvironment(): void
    {
        self::assertTrue(FixtureController::create()->canInit());

        Injector::inst()->get(Kernel::class)->setEnvironment('live');

        self::assertFalse(FixtureController::create()->canInit());
    }
}
