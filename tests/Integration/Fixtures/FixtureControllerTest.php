<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Tests\Integration\Fixtures;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\E2e\Fixtures\FixtureController;
use WeDevelop\Fixtures\FixtureLoader;

#[CoversClass(FixtureController::class)]
final class FixtureControllerTest extends SapphireTest
{
    /**
     * Relative to the project root and reached through the harness's symlinked vendor/ path repository, so every
     * load here also proves that containment does not follow symlinks.
     */
    private const string FIXTURES = 'vendor/wedevelopnl/silverstripe-e2e/tests/Integration/Fixtures/fixtures';

    protected $usesDatabase = true;

    private const string OUTSIDE_FIXTURE = "Page:\n  outside:\n    Title: Outside\n    URLSegment: e2e-controller-outside\n    Sort: 1\n";

    private string $originalEnvironment = '';

    private ?string $outsideFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = Injector::inst()->get(Kernel::class);
        $this->originalEnvironment = $kernel->getEnvironment();
        $kernel->setEnvironment('dev');
    }

    protected function tearDown(): void
    {
        if ($this->outsideFile !== null) {
            unlink($this->outsideFile);
        }

        Injector::inst()->get(Kernel::class)->setEnvironment($this->originalEnvironment);

        parent::tearDown();
    }

    /**
     * @param array<string, list<string>> $postVars
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

    /**
     * @return array<string, mixed>
     */
    private function json(HTTPResponse|HTTPResponse_Exception $response, int $expectedStatus): array
    {
        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame($expectedStatus, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/json', $response->getHeader('Content-Type'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    private function pageExists(string $urlSegment): bool
    {
        return SiteTree::get()->filter('URLSegment', $urlSegment)->exists();
    }

    private function writeOutsideFixture(): string
    {
        $this->outsideFile = sys_get_temp_dir() . '/e2e-controller-outside-' . bin2hex(random_bytes(4)) . '.yml';
        file_put_contents($this->outsideFile, self::OUTSIDE_FIXTURE);

        return $this->outsideFile;
    }

    public function testLoadFilesAnswersFlatMapOfIntegerIds(): void
    {
        $body = $this->json($this->dispatch('POST', 'load', ['file' => [
            self::FIXTURES . '/pages/a-home.yml',
            self::FIXTURES . '/pages/b-about.yaml',
        ]]), 200);

        self::assertTrue($body['success']);
        self::assertIsArray($body['fixtures']);
        self::assertSame(['Page.home', 'Page.about'], array_keys($body['fixtures']));
        self::assertIsInt($body['fixtures']['Page.home']);
        self::assertSame('e2e-controller-home', SiteTree::get()->byID($body['fixtures']['Page.home'])?->URLSegment);
        self::assertTrue($this->pageExists('e2e-controller-about'));
    }

    public function testLoadDirectoryTakesOnlyItsOwnFixtureFilesInNameOrder(): void
    {
        $body = $this->json($this->dispatch('POST', 'load', ['dir' => [self::FIXTURES . '/pages']]), 200);

        self::assertIsArray($body['fixtures']);
        self::assertSame(['Page.home', 'Page.about'], array_keys($body['fixtures']));
        self::assertFalse($this->pageExists('e2e-controller-shout'), 'an uppercase .YML extension is skipped');
        self::assertFalse($this->pageExists('e2e-controller-deep'), 'a subdirectory is skipped');
    }

    public function testLoadTakesFilesBeforeDirectories(): void
    {
        $body = $this->json($this->dispatch('POST', 'load', [
            'dir' => [self::FIXTURES . '/pages'],
            'file' => [self::FIXTURES . '/pages/nested/deep.yml'],
        ]), 200);

        self::assertIsArray($body['fixtures']);
        self::assertSame(['Page.deep', 'Page.home', 'Page.about'], array_keys($body['fixtures']));
    }

    public function testSecondLoadReplacesTheRecordsOfTheFirst(): void
    {
        $this->json($this->dispatch('POST', 'load', ['file' => [self::FIXTURES . '/pages/a-home.yml']]), 200);
        $this->json($this->dispatch('POST', 'load', ['file' => [self::FIXTURES . '/pages/b-about.yaml']]), 200);

        self::assertFalse($this->pageExists('e2e-controller-home'));
        self::assertTrue($this->pageExists('e2e-controller-about'));
    }

    public function testLoadWithoutPathsAnswers400(): void
    {
        $body = $this->json($this->dispatch('POST', 'load'), 400);

        self::assertSame(['success' => false, 'error' => 'Pass at least one file[] or dir[]'], $body);
    }

    public function testLoadRefusesRelativePathLeavingTheProject(): void
    {
        $outside = $this->writeOutsideFixture();
        $depth = substr_count(trim(Director::baseFolder(), '/'), '/') + 1;
        $path = str_repeat('../', $depth) . ltrim($outside, '/');

        $body = $this->json($this->dispatch('POST', 'load', ['file' => [$path]]), 400);

        self::assertFalse($body['success']);
        self::assertSame([$path . ': must not contain .. segments'], $body['problems']);
        self::assertFalse($this->pageExists('e2e-controller-outside'));
    }

    /**
     * The OS resolves `..` after following a symlink, so a `..` behind the harness's symlinked vendor/ module leaves
     * the project even though the path's text stays inside it.
     */
    public function testLoadRefusesDotDotBehindSymlinkedDirectory(): void
    {
        $outside = $this->writeOutsideFixture();
        $link = 'vendor/wedevelopnl/silverstripe-e2e';
        $target = (string) realpath(Director::baseFolder() . '/' . $link);
        $path = $link . '/' . str_repeat('../', substr_count(trim($target, '/'), '/') + 1) . ltrim($outside, '/');
        self::assertTrue(is_link(Director::baseFolder() . '/' . $link));
        self::assertSame(realpath($outside), realpath(Director::baseFolder() . '/' . $path));

        $body = $this->json($this->dispatch('POST', 'load', ['file' => [$path], 'dir' => [dirname($path)]]), 400);

        self::assertSame([
            $path . ': must not contain .. segments',
            dirname($path) . ': must not contain .. segments',
        ], $body['problems']);
        self::assertFalse($this->pageExists('e2e-controller-outside'));
    }

    public function testLoadRefusesAbsolutePathOutsideTheProject(): void
    {
        $outside = $this->writeOutsideFixture();

        $body = $this->json($this->dispatch('POST', 'load', ['file' => [$outside]]), 400);

        self::assertSame([$outside . ': outside the project'], $body['problems']);
        self::assertFalse($this->pageExists('e2e-controller-outside'));
    }

    public function testLoadAcceptsAbsolutePathInsideTheProject(): void
    {
        $body = $this->json($this->dispatch('POST', 'load', [
            'file' => [Director::baseFolder() . '/' . self::FIXTURES . '/pages/a-home.yml'],
        ]), 200);

        self::assertIsArray($body['fixtures']);
        self::assertArrayHasKey('Page.home', $body['fixtures']);
    }

    public function testLoadRefusesDirectoryOutsideTheProject(): void
    {
        $dir = self::FIXTURES . str_repeat('/..', 8);

        $body = $this->json($this->dispatch('POST', 'load', ['dir' => [$dir, sys_get_temp_dir()]]), 400);

        self::assertSame([$dir . ': must not contain .. segments', sys_get_temp_dir() . ': outside the project'], $body['problems']);
    }

    public function testLoadRefusesFileWithoutFixtureExtension(): void
    {
        $body = $this->json($this->dispatch('POST', 'load', ['file' => [
            self::FIXTURES . '/pages/notes.txt',
            self::FIXTURES . '/pages/c-shout.YML',
            self::FIXTURES . '/pages/a-home.yml',
        ]]), 400);

        self::assertSame([
            self::FIXTURES . '/pages/notes.txt: not a .yml or .yaml file',
            self::FIXTURES . '/pages/c-shout.YML: not a .yml or .yaml file',
        ], $body['problems']);
        self::assertFalse($this->pageExists('e2e-controller-home'));
    }

    public function testLoadRefusesMissingAndEmptyDirectoriesTogether(): void
    {
        $body = $this->json($this->dispatch('POST', 'load', ['dir' => [
            self::FIXTURES . '/does-not-exist',
            self::FIXTURES . '/no-fixtures',
        ]]), 400);

        self::assertSame([
            self::FIXTURES . '/does-not-exist: no directory at this path',
            self::FIXTURES . '/no-fixtures: no .yml or .yaml file in this directory',
        ], $body['problems']);
    }

    public function testInvalidFixtureLoadsNothingAndKeepsEarlierData(): void
    {
        $this->json($this->dispatch('POST', 'load', ['file' => [self::FIXTURES . '/pages/a-home.yml']]), 200);

        $body = $this->json($this->dispatch('POST', 'load', ['file' => [
            self::FIXTURES . '/pages/b-about.yaml',
            self::FIXTURES . '/broken.yml',
        ]]), 400);

        self::assertFalse($body['success']);
        self::assertIsString($body['error']);
        self::assertStringContainsString('nothing was written', $body['error']);
        self::assertIsArray($body['problems']);
        self::assertCount(1, $body['problems']);
        self::assertIsString($body['problems'][0]);
        self::assertStringContainsString('NoSuchField', $body['problems'][0]);
        self::assertFalse($this->pageExists('e2e-controller-about'));
        self::assertFalse($this->pageExists('e2e-controller-broken'));
        self::assertTrue($this->pageExists('e2e-controller-home'));
    }

    public function testLoaderFailureAnswers500(): void
    {
        Injector::inst()->registerService(new class () extends FixtureLoader {
            public function reload(string ...$paths): never
            {
                throw new RuntimeException('Database is gone');
            }

            public function reset(): never
            {
                throw new RuntimeException('Database is gone');
            }
        }, FixtureLoader::class);

        $loadBody = $this->json($this->dispatch('POST', 'load', ['file' => [self::FIXTURES . '/pages/a-home.yml']]), 500);
        $resetBody = $this->json($this->dispatch('POST', 'reset', [], ['confirm' => '1']), 500);

        self::assertSame(['success' => false, 'error' => 'Database is gone'], $loadBody);
        self::assertSame(['success' => false, 'error' => 'Database is gone'], $resetBody);
    }

    public function testResetRemovesTheLoadedRecordsAndKeepsOthers(): void
    {
        $this->json($this->dispatch('POST', 'load', ['file' => [self::FIXTURES . '/pages/a-home.yml']]), 200);
        SiteTree::create(['Title' => 'Made in the CMS', 'URLSegment' => 'e2e-controller-cms'])->write();

        $body = $this->json($this->dispatch('POST', 'reset', [], ['confirm' => '1']), 200);

        self::assertSame(['success' => true], $body);
        self::assertFalse($this->pageExists('e2e-controller-home'));
        self::assertTrue($this->pageExists('e2e-controller-cms'), 'a record the loader did not write survives');
    }

    public function testResetRemovesRecordsOfResetClasses(): void
    {
        Config::modify()->set(FixtureLoader::class, 'reset_classes', [SiteTree::class]);
        SiteTree::create(['Title' => 'Made in the CMS', 'URLSegment' => 'e2e-controller-cms'])->write();

        $this->json($this->dispatch('POST', 'reset', [], ['confirm' => '1']), 200);

        self::assertFalse($this->pageExists('e2e-controller-cms'));
    }

    public function testResetWithoutConfirmAnswers400AndKeepsData(): void
    {
        $this->json($this->dispatch('POST', 'load', ['file' => [self::FIXTURES . '/pages/a-home.yml']]), 200);

        $body = $this->json($this->dispatch('POST', 'reset'), 400);

        self::assertSame(['success' => false, 'error' => 'Missing confirm=1 query parameter'], $body);
        self::assertTrue($this->pageExists('e2e-controller-home'));
    }

    public function testGetOnLoadAndResetAnswers405(): void
    {
        $this->json($this->dispatch('POST', 'load', ['file' => [self::FIXTURES . '/pages/a-home.yml']]), 200);

        $loadBody = $this->json($this->dispatch('GET', 'load'), 405);
        $resetBody = $this->json($this->dispatch('GET', 'reset', [], ['confirm' => '1']), 405);

        self::assertFalse($loadBody['success']);
        self::assertFalse($resetBody['success']);
        self::assertTrue($this->pageExists('e2e-controller-home'));
    }

    public function testLoadAllIsGone(): void
    {
        $response = $this->dispatch('POST', 'load-all');

        self::assertInstanceOf(HTTPResponse::class, $response);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testNonDevRequestReturns404(): void
    {
        Injector::inst()->get(Kernel::class)->setEnvironment('live');

        $response = $this->dispatch('POST', 'load', ['file' => [self::FIXTURES . '/pages/a-home.yml']]);

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
