<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\Controller\OAuthController;
use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\ResourceUrl;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class IssuerRedirectTest extends TestCase {
    private const ISSUER = 'https://cloud.example.org';
    private const REDIRECT = 'https://claude.ai/cb?x=1';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /** @var array<string,mixed> */
    private array $stored = [];
    private ClientMetadata $client;

    /** @param array<string,mixed> $params */
    private function controller(array $params): OAuthController {
        $this->client ??= new ClientMetadata('https://claude.ai/c', 'Claude', ['https://claude.ai/cb?x=1']);
        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturn($params);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $user->method('getDisplayName')->willReturn('Alice');
        $users = $this->createMock(IUserSession::class);
        $users->method('getUser')->willReturn($user);
        $session = $this->createMock(ISession::class);
        $session->method('get')->willReturnCallback(fn () => $this->stored);
        $session->method('set')->willReturnCallback(function ($key, $value): void {
            $this->stored = $value;
        });
        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('resolve')->willReturn($this->client);
        $policy = $this->createMock(GrantPolicy::class);
        $policy->method('globalEnabled')->willReturn(true);
        $policy->method('eligible')->willReturn(true);
        $tokens = $this->createMock(TokenService::class);
        $tokens->method('createCode')->willReturn('CODE');
        $urls = $this->createMock(ResourceUrl::class);
        $urls->method('base')->willReturn(self::ISSUER . '/apps/mcp/');
        $urls->method('issuer')->willReturn(self::ISSUER);
        $generator = $this->createMock(IURLGenerator::class);
        $generator->method('linkToRoute')->willReturn('/consent');
        return new OAuthController('mcp', $request, $users, $session, $generator,
            new AuthorizationValidator($resolver), $tokens, $policy, $urls);
    }

    /** @return array<string,string> */
    private function query(string $url): array {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $out);
        return $out;
    }

    /** @param array<string,string> $override */
    private function params(array $override = []): array {
        return $override + ['client_id' => 'https://claude.ai/c', 'redirect_uri' => self::REDIRECT,
            'response_type' => 'code', 'code_challenge_method' => 'S256', 'code_challenge' => self::CHALLENGE,
            'state' => 'st&te', 'scope' => 'mcp'];
    }

    public function testRedirectWithAddsIssuerOnceAndKeepsState(): void {
        $request = new AuthorizationRequest($this->client = new ClientMetadata('c', 'C', []), self::REDIRECT, 'S', 'ch', 'r', 'mcp');
        $url = $request->redirectWith(['code' => 'abc'], self::ISSUER);
        $query = $this->query($url);
        $this->assertSame(['x' => '1', 'code' => 'abc', 'iss' => self::ISSUER, 'state' => 'S'], $query);
        $this->assertSame(1, substr_count($url, 'iss='));
    }

    public function testRedirectableErrorsCarryIssuerAndState(): void {
        foreach (['response_type' => ['response_type', 'token', 'unsupported_response_type'],
            'scope' => ['scope', 'bogus', 'invalid_scope'], 'resource' => ['resource', 'https://other.example/', 'invalid_target']] as [$key, $value, $error]) {
            $response = $this->controller($this->params([$key => $value]))->authorize();
            $this->assertInstanceOf(RedirectResponse::class, $response);
            $query = $this->query($response->getRedirectURL());
            $this->assertSame($error, $query['error']);
            $this->assertSame(self::ISSUER, $query['iss']);
            $this->assertSame('st&te', $query['state']);
            $this->assertSame(1, substr_count($response->getRedirectURL(), 'iss='));
        }
    }

    public function testUnregisteredRedirectUriStaysOnTheLocalPageWithoutIssuer(): void {
        $response = $this->controller($this->params(['redirect_uri' => 'https://evil.example/cb']))->authorize();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertNotNull($response->getParams()['error'], 'the local page says why');
        $this->assertNull($response->getParams()['pending'], 'no consent can be given for this request');
        $this->assertFalse($response->getParams()['blocked']);
        $this->assertSame([], $this->stored, 'nothing is kept in the session');
    }

    /**
     * The redirect_uri is judged before anything else: whatever else is wrong with the request, an
     * unregistered redirect never gets an error redirected to it, because the redirect is the attacker's.
     *
     * @return array<string, array{array<string,string>}>
     */
    public static function otherInvalidParametersProvider(): array {
        return [
            'response_type=token' => [['response_type' => 'token']],
            'unknown scope' => [['scope' => 'bogus']],
            'scope without mcp' => [['scope' => 'offline_access']],
            'other resource' => [['resource' => 'https://other.example/']],
            'PKCE plain' => [['code_challenge_method' => 'plain']],
            'no PKCE method' => [['code_challenge_method' => '']],
            'malformed challenge' => [['code_challenge' => 'short']],
            'everything wrong at once' => [['response_type' => 'token', 'scope' => 'bogus', 'code_challenge_method' => 'plain']],
        ];
    }

    /** @param array<string,string> $broken */
    #[\PHPUnit\Framework\Attributes\DataProvider('otherInvalidParametersProvider')]
    public function testAnUnregisteredRedirectNeverReceivesAnErrorWhateverElseIsWrong(array $broken): void {
        $response = $this->controller($this->params($broken + ['redirect_uri' => 'https://evil.example/cb']))->authorize();

        $this->assertNotInstanceOf(RedirectResponse::class, $response, 'an error was sent to the attacker redirect');
        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertNotNull($response->getParams()['error']);
        $this->assertNull($response->getParams()['pending']);
    }

    public function testARedirectableErrorGoesToTheRegisteredRedirectAndNowhereElse(): void {
        $response = $this->controller($this->params(['response_type' => 'token']))->authorize();

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://claude.ai/cb', strtok($response->getRedirectURL(), '?'));
    }

    public function testConsentSuccessAndDenialCarryIssuer(): void {
        $controller = $this->controller($this->params());
        $page = $controller->authorize();
        $this->assertInstanceOf(TemplateResponse::class, $page);
        $pending = $page->getParams()['pending'];
        $allow = $controller->consent($pending, 'allow');
        $query = $this->query($allow->getRedirectURL());
        $this->assertSame(['x' => '1', 'code' => 'CODE', 'iss' => self::ISSUER, 'state' => 'st&te'], $query);

        $controller = $this->controller($this->params());
        $pending = $controller->authorize()->getParams()['pending'];
        $query = $this->query($controller->consent($pending, 'deny')->getRedirectURL());
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame(self::ISSUER, $query['iss']);
        $this->assertSame('st&te', $query['state']);
    }

    public function testNativeClientConsentShowsANonEmptyLabelAndLoopbackWarning(): void {
        $this->client = NativeClient::metadata();
        $controller = $this->controller($this->params([
            'client_id' => NativeClient::CLIENT_ID, 'redirect_uri' => 'http://localhost:53421/oauth/callback']));
        $page = $controller->authorize();
        $this->assertInstanceOf(TemplateResponse::class, $page);
        $this->assertNotSame('', $page->getParams()['clientHost']);
        $this->assertTrue($page->getParams()['loopback']);
        $this->assertNotSame('', $page->getParams()['client']);
    }
}
