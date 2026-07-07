<?php

declare(strict_types=1);

namespace WeDevelop\E2e\Fixtures;

use InvalidArgumentException;
use Override;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Dev-only HTTP controller for loading and resetting E2E fixtures.
 *
 * Registered with DevelopmentAdmin at /dev/e2e-fixtures via _config/fixtures.yml
 * (gated Only: environment: dev). Playwright POSTs a fixture name to /load and
 * gets JSON with page IDs for CMS navigation; POST /reset clears E2E data.
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
 * The destructive {@see reset()} action additionally requires an explicit
 * `confirm=1` query parameter so an accidental browser/curl hit cannot wipe
 * fixture data. That is an accident guard, NOT an authentication control.
 */
class FixtureController extends Controller
{
    /** @var array<string, string> */
    private static array $url_handlers = [
        'POST load' => 'load',
        'POST reset' => 'reset',
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
        $fixtureName = $request->postVar('fixture');
        if (!is_string($fixtureName) || $fixtureName === '') {
            return $this->jsonResponse(400, [
                'success' => false,
                'error' => 'Missing required "fixture" parameter',
            ]);
        }

        $loader = FixtureLoader::create();

        /** @var non-empty-string $fixtureName */
        try {
            $result = $loader->load($fixtureName);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $this->jsonResponse(400, [
                'success' => false,
                'error' => $invalidArgumentException->getMessage(),
            ]);
        }

        return $this->jsonResponse(200, [
            'success' => true,
            'fixture' => $result->fixtureName,
            'data' => $result,
        ]);
    }

    public function reset(HTTPRequest $request): HTTPResponse
    {
        if ($request->getVar('confirm') !== '1') {
            return $this->jsonResponse(400, [
                'success' => false,
                'error' => 'Missing confirm=1 query parameter',
            ]);
        }

        FixtureLoader::create()->reset();

        return $this->jsonResponse(200, [
            'success' => true,
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
