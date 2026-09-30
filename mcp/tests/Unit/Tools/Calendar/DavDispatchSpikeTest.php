<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use PHPUnit\Framework\TestCase;
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\DAV\Exception\PreconditionFailed;
use Sabre\DAV\IFile;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;
use Sabre\DAV\SimpleFile;
use Sabre\DAVACL\IACL;
use Sabre\DAVACL\Exception\NeedPrivileges;
use Sabre\DAVACL\Plugin as AclPlugin;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;

/**
 * Tests the Sabre in-process request dispatch and response containment assumptions for the DAV spike.
 */
final class DavDispatchSpikeTest extends TestCase {
    /**
     * @return void
     */
    public function testStaleIfMatchRaises412WithoutOutputOrHeaders(): void {
        $server = $this->serverForPrincipal('principals/users/alice');
        $request = new Request('GET', '/event.ics', ['If-Match' => '"stale-etag"']);
        $response = new Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        $headersBefore = headers_list();
        $output = '';
        ob_start();

        try {
            $server->invokeMethod($request, $response, false);
            self::fail('Expected a stale If-Match precondition to throw.');
        } catch (PreconditionFailed $exception) {
            self::assertSame(412, $exception->getHTTPCode());
        } finally {
            $output = ob_get_clean();
        }

        self::assertSame('', $output);
        self::assertSame($headersBefore, headers_list());
        self::assertSame(0, CapturingSapi::$sendCount);
        self::assertSame($request, $server->httpRequest);
        self::assertSame($response, $server->httpResponse);
    }

    /**
     * Sabre calls SAPI even with sendResponse=false when a conditional request returns early.
     *
     * @return void
     */
    public function testShortCircuitResponseUsesCaptureSapiWithoutOutputOrHeaders(): void {
        $server = $this->serverForPrincipal('principals/users/alice');
        $eventFile = $server->tree->getNodeForPath('event.ics');
        $request = new Request('GET', '/event.ics', ['If-None-Match' => $eventFile->getETag()]);
        $response = new Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        $headersBefore = headers_list();
        $bufferLevel = ob_get_level();
        $output = '';
        ob_start();

        try {
            $server->invokeMethod($request, $response, false);
        } finally {
            if (ob_get_level() > $bufferLevel) {
                $output = (string)ob_get_clean();
            }
        }

        self::assertSame('', $output);
        self::assertSame($headersBefore, headers_list());
        self::assertSame(1, CapturingSapi::$sendCount);
        self::assertSame(304, CapturingSapi::$lastStatus);
        self::assertSame(304, $response->getStatus());
    }

    /**
     * @return void
     */
    public function testSyntheticRequestCarriesSchedulingHeaderAndSessionPrincipalThroughAcl(): void {
        $server = $this->serverForPrincipal('principals/users/alice');
        $request = new Request('GET', '/event.ics', ['x-nc-scheduling' => 'false']);
        $response = new Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;
        $server->on('beforeMethod:GET', static function (Request $observedRequest) use ($server): void {
            self::assertSame('false', $observedRequest->getHeader('x-nc-scheduling'));
            self::assertSame($observedRequest, $server->httpRequest);
        });

        $server->invokeMethod($request, $response, false);

        self::assertSame(200, $response->getStatus());
        $body = $response->getBody();
        self::assertIsResource($body);
        self::assertSame('calendar data', stream_get_contents($body));
        fclose($body);
        self::assertSame('principals/users/alice', $server->getPlugin('auth')->getCurrentPrincipal());
        self::assertSame($request, $server->httpRequest);
        self::assertSame($response, $server->httpResponse);
        self::assertSame(0, CapturingSapi::$sendCount);
    }

    /**
     * @return void
     */
    public function testAclRejectsRequestForPrincipalWithoutReadPrivilege(): void {
        $server = $this->serverForPrincipal('principals/users/bob');
        $request = new Request('GET', '/event.ics');
        $response = new Response();
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        try {
            $server->invokeMethod($request, $response, false);
            self::fail('Expected the DAV ACL plugin to reject the principal.');
        } catch (NeedPrivileges) {
            self::assertSame('principals/users/bob', $server->getPlugin('auth')->getCurrentPrincipal());
            self::assertSame($request, $server->httpRequest);
            self::assertSame($response, $server->httpResponse);
            self::assertSame(0, CapturingSapi::$sendCount);
        }
    }

    /**
     * @param string $principal authenticated session principal
     * @return Server configured with the Sabre core and ACL plugins
     */
    private function serverForPrincipal(string $principal): Server {
        CapturingSapi::$sendCount = 0;
        CapturingSapi::$lastStatus = null;
        $event = new AclReadableFile('event.ics', 'calendar data', 'principals/users/alice');
        $principals = new SimpleCollection('principals', [
            new SimpleCollection('users', [new SimpleFile('bob', '')]),
        ]);
        $server = new Server(new SimpleCollection('root', [$event, $principals]), new CapturingSapi());
        $auth = new SessionPrincipalFixture();
        $auth->setCurrentPrincipal($principal);
        $server->addPlugin($auth);
        $server->addPlugin(new AclPlugin());
        return $server;
    }
}

/**
 * Captures Sabre's fallback response send while deliberately avoiding PHP output and header APIs.
 */
final class CapturingSapi extends Sapi {
    /** @var int number of fallback sendResponse calls */
    public static int $sendCount = 0;
    /** @var int|null captured HTTP status */
    public static ?int $lastStatus = null;

    /**
     * @param ResponseInterface $response response Sabre would otherwise emit to PHP SAPI
     * @return void
     */
    public static function sendResponse(ResponseInterface $response): void {
        self::$sendCount++;
        self::$lastStatus = $response->getStatus();
    }
}

/**
 * Mirrors stable33 CustomPrincipalPlugin's setter for a testable authenticated session principal.
 */
final class SessionPrincipalFixture extends AuthPlugin {
    /**
     * @param string|null $currentPrincipal authenticated principal URI
     * @return void
     */
    public function setCurrentPrincipal(?string $currentPrincipal): void {
        $this->currentPrincipal = $currentPrincipal;
    }
}

/**
 * Read-only calendar object whose ACL grants read access to exactly one session principal.
 */
final class AclReadableFile extends SimpleFile implements IACL, IFile {
    /**
     * @param string $name DAV object name
     * @param string $contents ICS payload
     * @param string $reader principal granted read access
     */
    public function __construct(string $name, string $contents, private string $reader) {
        parent::__construct($name, $contents, 'text/calendar');
    }

    /** @return string|null */
    public function getOwner(): ?string {
        return $this->reader;
    }

    /** @return string|null */
    public function getGroup(): ?string {
        return null;
    }

    /** @return list<array{principal:string, privilege:string}> */
    public function getACL(): array {
        return [['principal' => $this->reader, 'privilege' => '{DAV:}read']];
    }

    /**
     * @param array<array{principal:string, privilege:string}> $acl replacement access entries
     * @return void
     */
    public function setACL(array $acl): void {
    }

    /** @return array<string, mixed>|null */
    public function getSupportedPrivilegeSet(): ?array {
        return null;
    }

    /**
     * @param resource|string $data replacement file data
     * @return string|null
     */
    public function put($data): ?string {
        throw new \LogicException('The spike resource is read-only.');
    }
}
