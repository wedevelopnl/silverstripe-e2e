<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Fixtures;

use Override;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use Throwable;
use WeDevelop\Fixtures\FixtureLoader;
use WeDevelop\Fixtures\InvalidFixtureException;

/**
 * Dev-only JSON layer over wedevelopnl/silverstripe-fixtures for browser tests.
 *
 * Registered with DevelopmentAdmin at /dev/e2e-fixtures via _config/fixtures.yml
 * (gated Only: environment: dev). Playwright POSTs fixture files (`file[]`) and
 * directories (`dir[]`) to /load and gets a flat `Class.identifier => id` map;
 * POST /reset?confirm=1 clears what the loads put in.
 *
 * SECURITY POSTURE — read before changing the guards:
 *
 * These POST handlers are INTENTIONALLY unauthenticated and CSRF-unprotected so
 * Playwright's global-setup can seed and tear down fixture data over plain HTTP
 * without juggling CMS sessions or security tokens. Adding session auth or a
 * CSRF token here would break that automation.
 *
 * This is acceptable ONLY because the route is locked to the dev environment by
 * two independent layers:
 *   1. {@see canInit()} — DevelopmentAdmin refuses to expose the route unless
 *      {@see Director::isDev()}.
 *   2. {@see init()} — returns HTTP 404 on any non-dev request as defence in
 *      depth, even if the route gating were somehow bypassed.
 *
 * INVARIANT: this controller MUST never be reachable on a staging/production
 * host. A dev/CI host reachable from the public internet turns the
 * unauthenticated /reset endpoint into a remote data-wipe primitive. Do not
 * relax the env gating without adding real authentication.
 *
 * INVARIANT: no path outside the project root reaches the loader. The `..`
 * refusal in {@see hasDotDotSegment()}, the root check in {@see isInsideProject()}
 * and the extension rule are the only fence on the caller-supplied paths.
 * Containment is lexical, never `realpath`: a module installed from a symlinked
 * path repository lives outside the root on disk.
 *
 * Both actions answer 405 to anything but POST, so opening the address in a
 * browser changes nothing. The destructive {@see reset()} action additionally
 * requires an explicit `confirm=1` query parameter. That is an accident guard,
 * NOT an authentication control.
 */
class FixtureController extends Controller
{
    private const array EXTENSIONS = ['yml', 'yaml'];

    /** @var array<string, string> */
    private static array $url_handlers = [
        'load' => 'load',
        'reset' => 'reset',
    ];

    /** @var list<string> */
    private static array $allowed_actions = [
        'load',
        'reset',
    ];

    /**
     * DevelopmentAdmin checks this before exposing the route.
     */
    public function canInit(): bool
    {
        return Director::isDev();
    }

    /**
     * Defence-in-depth: block non-dev access even if config gating is bypassed.
     */
    #[Override]
    protected function init(): void
    {
        parent::init();

        if (!Director::isDev()) {
            $this->httpError(404);
        }
    }

    public function load(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->methodNotAllowed();
        }

        $files = $this->listVar($request->postVar('file'));
        $dirs = $this->listVar($request->postVar('dir'));
        if ($files === [] && $dirs === []) {
            return $this->jsonResponse(400, [
                'success' => false,
                'error' => 'Pass at least one file[] or dir[]',
            ]);
        }

        try {
            $loaded = FixtureLoader::singleton()->reload(...$this->paths($files, $dirs));
        } catch (InvalidFixtureException $invalidFixtureException) {
            return $this->jsonResponse(400, [
                'success' => false,
                'error' => $invalidFixtureException->getMessage(),
                'problems' => $invalidFixtureException->getProblems(),
            ]);
        } catch (Throwable $throwable) {
            return $this->failure($throwable);
        }

        $ids = [];
        foreach ($loaded->toArray() as $class => $identifiers) {
            foreach ($identifiers as $identifier => $id) {
                $ids[$class . '.' . $identifier] = $id;
            }
        }

        return $this->jsonResponse(200, [
            'success' => true,
            // An object even when empty: the client reads a map, never a list
            'fixtures' => (object) $ids,
        ]);
    }

    public function reset(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST()) {
            return $this->methodNotAllowed();
        }

        if ($request->getVar('confirm') !== '1') {
            return $this->jsonResponse(400, [
                'success' => false,
                'error' => 'Missing confirm=1 query parameter',
            ]);
        }

        try {
            FixtureLoader::singleton()->reset();
        } catch (Throwable $throwable) {
            return $this->failure($throwable);
        }

        return $this->jsonResponse(200, [
            'success' => true,
        ]);
    }

    /**
     * @return list<string>
     */
    private function listVar(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [$value], is_string(...)));
    }

    /**
     * The files, then each directory's fixture files, as `FixturesTask::paths()` of silverstripe-fixtures expands
     * them: `scandir` order, `.yml`/`.yaml` only, no subdirectories, prefixed with the directory as given. Paths stay
     * as given, so the loader's problems name what the caller sent.
     *
     * @param list<string> $files
     * @param list<string> $dirs
     *
     * @return list<string>
     *
     * @throws InvalidFixtureException listing every refused path; nothing reaches the loader then
     */
    private function paths(array $files, array $dirs): array
    {
        $problems = [];
        foreach ($files as $file) {
            if ($this->hasDotDotSegment($file)) {
                $problems[] = $file . ': must not contain .. segments';
            } elseif (!$this->isInsideProject($file)) {
                $problems[] = $file . ': outside the project';
            } elseif (!in_array(pathinfo($file, PATHINFO_EXTENSION), self::EXTENSIONS, true)) {
                $problems[] = $file . ': not a .yml or .yaml file';
            }
        }

        foreach ($dirs as $dir) {
            if ($this->hasDotDotSegment($dir)) {
                $problems[] = $dir . ': must not contain .. segments';
                continue;
            }

            if (!$this->isInsideProject($dir)) {
                $problems[] = $dir . ': outside the project';
                continue;
            }

            $absolute = $this->absolute($dir);
            if (!is_dir($absolute)) {
                $problems[] = $dir . ': no directory at this path';
                continue;
            }

            $names = array_filter(
                scandir($absolute) ?: [],
                static fn (string $name): bool => in_array(pathinfo($name, PATHINFO_EXTENSION), self::EXTENSIONS, true)
                    && is_file($absolute . '/' . $name),
            );
            if ($names === []) {
                $problems[] = $dir . ': no .yml or .yaml file in this directory';
            }

            foreach ($names as $name) {
                $files[] = rtrim($dir, '/') . '/' . $name;
            }
        }

        if ($problems !== []) {
            throw new InvalidFixtureException($problems);
        }

        return $files;
    }

    /**
     * The OS resolves `..` after following a symlink, so a `..` behind a symlinked directory inside the root can leave
     * it while the path's text stays inside. No text-only rule can pop it safely: refuse it.
     */
    private function hasDotDotSegment(string $path): bool
    {
        return in_array('..', explode('/', $path), true);
    }

    /**
     * Lexical containment on a path without `..`: `.` and empty segments are dropped and symlinks are not followed.
     */
    private function isInsideProject(string $path): bool
    {
        $segments = array_filter(
            explode('/', $this->absolute($path)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        );
        $normalised = '/' . implode('/', $segments);
        $root = rtrim(Director::baseFolder(), '/');

        return $normalised === $root || str_starts_with($normalised, $root . '/');
    }

    private function absolute(string $path): string
    {
        return Director::is_absolute($path) ? $path : Director::baseFolder() . '/' . $path;
    }

    private function methodNotAllowed(): HTTPResponse
    {
        return $this->jsonResponse(405, [
            'success' => false,
            'error' => 'Use POST',
        ]);
    }

    private function failure(Throwable $throwable): HTTPResponse
    {
        return $this->jsonResponse(500, [
            'success' => false,
            'error' => $throwable->getMessage(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(int $statusCode, array $data): HTTPResponse
    {
        $response = HTTPResponse::create();
        $response->setStatusCode($statusCode);
        $response->addHeader('Content-Type', 'application/json');
        $response->setBody(json_encode($data, JSON_THROW_ON_ERROR));

        return $response;
    }
}
