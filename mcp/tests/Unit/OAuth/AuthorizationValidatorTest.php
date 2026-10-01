<?php
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\ClientMetadata;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\OAuthException;
use PHPUnit\Framework\TestCase;

final class AuthorizationValidatorTest extends TestCase {
    public function testMcpScopeIsRequiredAndDefaultsWhenOmitted(): void {
        $fetcher = $this->createMock(ClientMetadataFetcher::class);
        $fetcher->method('fetch')->willReturn(new ClientMetadata('https://claude.ai/client', 'Claude', ['https://claude.ai/callback']));
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
}
