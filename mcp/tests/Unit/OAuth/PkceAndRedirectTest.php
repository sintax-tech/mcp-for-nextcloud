<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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

    /** @return array<string, array{string}> requests that look like the registered loopback URI to a lax comparison */
    public static function loopbackLookalikesProvider(): array {
        return [
            'localhost as a label of another host' => ['http://localhost.evil.example/callback'],
            'loopback address as a label of another host' => ['http://127.0.0.1.evil.example/callback'],
            'loopback with a port in the userinfo' => ['http://localhost:80@evil.example/callback'],
            'userinfo before the loopback host' => ['http://user@localhost:3118/callback'],
            'other query' => ['http://localhost:3118/callback?x=1'],
            'fragment added' => ['http://localhost:3118/callback#frag'],
            'path prefix' => ['http://localhost:3118/callback/extra'],
            'other loopback family' => ['http://[::1]:3118/callback'],
            'uppercase path' => ['http://localhost:3118/Callback'],
            'https on loopback' => ['https://localhost:3118/callback'],
            'not loopback at all' => ['http://192.168.0.10:3118/callback'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('loopbackLookalikesProvider')]
    public function testALoopbackLookalikeIsNotTheRegisteredRedirect(string $requested): void {
        $this->assertFalse(RedirectUriMatcher::matches($requested, ['http://localhost/callback']), $requested);
    }

    public function testEachLoopbackFamilyMatchesOnlyItself(): void {
        foreach (['http://localhost:1/cb' => 'http://localhost/cb', 'http://127.0.0.1:2/cb' => 'http://127.0.0.1/cb', 'http://[::1]:3/cb' => 'http://[::1]/cb'] as $requested => $registered) {
            $this->assertTrue(RedirectUriMatcher::matches($requested, [$registered]), $requested);
        }
        $this->assertFalse(RedirectUriMatcher::matches('http://127.0.0.1:2/cb', ['http://localhost/cb']), 'a client registered for localhost');
        $this->assertFalse(RedirectUriMatcher::matches('http://localhost:2/cb', ['http://127.0.0.1/cb']));
        $this->assertFalse(RedirectUriMatcher::matches('http://[::1]:2/cb', ['http://127.0.0.1/cb']));
    }

    public function testALoopbackCandidateDoesNotOpenAnExternalRegisteredUri(): void {
        $this->assertFalse(RedirectUriMatcher::matches('http://localhost:3118/callback', ['https://claude.ai/callback']));
        $this->assertFalse(RedirectUriMatcher::matches('https://claude.ai/callback', ['http://localhost/callback']));
        $this->assertFalse(RedirectUriMatcher::matches('http://localhost:3118/callback', [42, null, ['http://localhost/callback']]));
    }

    /** The audience binding of a bearer: same server only, over the same scheme, whatever the front controller says. */
    public function testResourceComparisonIsStrictAboutTheOriginAndThePathOfTheApp(): void {
        $this->assertFalse(ResourceUrl::sameResource('https://cloud.example.org/apps/mcp/', 'http://cloud.example.org/apps/mcp/'), 'scheme');
        $this->assertFalse(ResourceUrl::sameResource('https://cloud.example.org/apps/mcp/', 'https://cloud.example.org/apps/other/'), 'app path');
        $this->assertFalse(ResourceUrl::sameResource('https://cloud.example.org/apps/mcp/', 'https://cloud.example.org/'), 'origin only');
        $this->assertFalse(ResourceUrl::sameResource('https://cloud.example.org:8443/apps/mcp/', 'https://cloud.example.org/apps/mcp/'), 'port');
    }

    public function testResourceComparisonIgnoresFrontControllerSlashAndCase(): void {
        $this->assertTrue(ResourceUrl::sameResource('https://Cloud.example.org/index.php/apps/mcp/', 'https://cloud.example.org/apps/mcp'));
        $this->assertFalse(ResourceUrl::sameResource('https://cloud.example.org/apps/mcp/', 'https://other.example.org/apps/mcp/'));
        $this->assertFalse(ResourceUrl::sameResource('', ''));
    }
}
