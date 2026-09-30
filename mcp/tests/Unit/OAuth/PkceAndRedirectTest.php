<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\Pkce;
use OCA\Mcp\OAuth\RedirectUriMatcher;
use OCA\Mcp\OAuth\ResourceUrl;
use PHPUnit\Framework\TestCase;

final class PkceAndRedirectTest extends TestCase {
    /** RFC 7636 appendix B test vector. */
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    public function testPkceS256MatchesTheRfcVector(): void {
        $this->assertTrue(Pkce::verify(self::VERIFIER, self::CHALLENGE));
    }

    public function testPkceRejectsWrongShortOrPlainVerifiers(): void {
        $this->assertFalse(Pkce::verify(self::VERIFIER . 'x', self::CHALLENGE));
        $this->assertFalse(Pkce::verify('short', self::CHALLENGE));
        $this->assertFalse(Pkce::verify(self::CHALLENGE, self::CHALLENGE), 'plain (verifier == challenge) must fail');
        $this->assertFalse(Pkce::validChallenge('plain-challenge'));
    }

    public function testRedirectUriMustMatchExactly(): void {
        $registered = ['https://claude.ai/api/mcp/auth_callback'];
        $this->assertTrue(RedirectUriMatcher::matches('https://claude.ai/api/mcp/auth_callback', $registered));
        foreach ([
            'https://claude.ai/api/mcp/auth_callback/', 'https://claude.ai/api/mcp/auth_callback?x=1',
            'https://evil.example/api/mcp/auth_callback', 'http://claude.ai/api/mcp/auth_callback',
            'https://claude.ai:444/api/mcp/auth_callback', 'https://CLAUDE.ai/api/mcp/auth_callback',
        ] as $uri) {
            $this->assertFalse(RedirectUriMatcher::matches($uri, $registered), $uri);
        }
    }

    public function testLoopbackIgnoresOnlyThePort(): void {
        $registered = ['http://localhost/callback', 'http://127.0.0.1/callback'];
        $this->assertTrue(RedirectUriMatcher::matches('http://localhost:3118/callback', $registered));
        $this->assertTrue(RedirectUriMatcher::matches('http://127.0.0.1:50000/callback', $registered));
        $this->assertFalse(RedirectUriMatcher::matches('http://localhost:3118/other', $registered));
        $this->assertFalse(RedirectUriMatcher::matches('https://localhost:3118/callback', $registered));
    }

    public function testResourceComparisonIgnoresFrontControllerSlashAndCase(): void {
        $this->assertTrue(ResourceUrl::sameResource('https://Cloud.example.org/index.php/apps/mcp/', 'https://cloud.example.org/apps/mcp'));
        $this->assertFalse(ResourceUrl::sameResource('https://cloud.example.org/apps/mcp/', 'https://other.example.org/apps/mcp/'));
        $this->assertFalse(ResourceUrl::sameResource('', ''));
    }
}
