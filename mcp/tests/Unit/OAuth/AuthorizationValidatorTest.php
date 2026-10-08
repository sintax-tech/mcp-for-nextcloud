<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\OAuthException;
use PHPUnit\Framework\TestCase;

final class AuthorizationValidatorTest extends TestCase {
    public function testMcpScopeIsRequiredAndDefaultsWhenOmitted(): void {
        $fetcher = $this->createMock(ClientResolver::class);
        $fetcher->method('resolve')->willReturn(new ClientMetadata('https://claude.ai/client', 'Claude', ['https://claude.ai/callback']));
        $validator = new AuthorizationValidator($fetcher);
        $params = ['client_id' => 'https://claude.ai/client', 'redirect_uri' => 'https://claude.ai/callback',
            'response_type' => 'code', 'code_challenge_method' => 'S256', 'code_challenge' => str_repeat('A', 43)];
        foreach (['' => 'mcp', 'mcp' => 'mcp', 'mcp offline_access' => 'mcp offline_access'] as $scope => $expected) {
            $this->assertSame($expected, $validator->validate($params + ['scope' => $scope], 'https://cloud.example/apps/mcp/')->scope);
        }
        try {
            $validator->validate($params + ['scope' => 'offline_access'], 'https://cloud.example/apps/mcp/');
            $this->fail('offline_access alone must be rejected');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_scope', $e->error);
        }
    }

    /** @return ClientResolver&\PHPUnit\Framework\MockObject\MockObject */
    private function validator(): AuthorizationValidator {
        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('resolve')->willReturn(new ClientMetadata('https://claude.ai/client', 'Claude', ['https://claude.ai/callback']));
        return new AuthorizationValidator($resolver);
    }

    /** @return array<string,string> */
    private function params(array $override = []): array {
        return $override + ['client_id' => 'https://claude.ai/client', 'redirect_uri' => 'https://claude.ai/callback',
            'response_type' => 'code', 'code_challenge_method' => 'S256', 'code_challenge' => str_repeat('A', 43), 'scope' => 'mcp'];
    }

    /** @return array<string, array{array<string,string>, string, bool}> override, error, whether the error may be redirected */
    public static function refusalsProvider(): array {
        return [
            'redirect_uri empty' => [['redirect_uri' => ''], 'invalid_request', false],
            'redirect_uri not registered' => [['redirect_uri' => 'https://evil.example/cb'], 'invalid_request', false],
            'unregistered redirect and a bad response_type' => [['redirect_uri' => 'https://evil.example/cb', 'response_type' => 'token'], 'invalid_request', false],
            'unregistered redirect and PKCE plain' => [['redirect_uri' => 'https://evil.example/cb', 'code_challenge_method' => 'plain'], 'invalid_request', false],
            'unregistered redirect and a bad scope' => [['redirect_uri' => 'https://evil.example/cb', 'scope' => 'x'], 'invalid_request', false],
            'response_type token' => [['response_type' => 'token'], 'unsupported_response_type', true],
            'PKCE plain' => [['code_challenge_method' => 'plain'], 'invalid_request', true],
            'PKCE method absent' => [['code_challenge_method' => ''], 'invalid_request', true],
            'challenge malformed' => [['code_challenge' => 'not-a-challenge'], 'invalid_request', true],
            'challenge absent' => [['code_challenge' => ''], 'invalid_request', true],
            'resource of another server' => [['resource' => 'https://other.example/apps/mcp/'], 'invalid_target', true],
            'unknown scope next to mcp' => [['scope' => 'mcp admin'], 'invalid_scope', true],
        ];
    }

    /** @param array<string,string> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusalsProvider')]
    public function testEveryInvalidParameterIsRefusedWithItsErrorAndRedirectability(array $override, string $error, bool $redirectable): void {
        try {
            $this->validator()->validate($this->params($override), 'https://cloud.example/apps/mcp/');
            $this->fail('the request was accepted');
        } catch (OAuthException $e) {
            $this->assertSame($error, $e->error);
            $this->assertSame($redirectable, $e->redirectable);
        }
    }

    public function testTheRegisteredRequestIsAccepted(): void {
        $request = $this->validator()->validate($this->params(), 'https://cloud.example/apps/mcp/');

        $this->assertSame('https://claude.ai/callback', $request->redirectUri);
    }
}
