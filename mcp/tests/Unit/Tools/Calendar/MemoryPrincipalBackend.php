<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use Sabre\DAVACL\PrincipalBackend\AbstractBackend;

/**
 * In-memory principal backend for the dispatcher tests.
 *
 * The Sabre ACL plugin walks the group membership of the acting principal on every privilege
 * check (sabre/dav/lib/DAVACL/Plugin.php:325-345), so the tests need real principal nodes at
 * `principals/users/<uid>` even though no tool ever touches them.
 */
final class MemoryPrincipalBackend extends AbstractBackend {
    /** @var array<string, array<string, mixed>> principals by URI */
    private array $principals = [];

    /**
     * @param string $uid user id
     * @param string $email address of the account
     * @return void
     */
    public function addUser(string $uid, string $email): void {
        $this->principals['principals/users/' . $uid] = [
            'id' => $uid,
            'uri' => 'principals/users/' . $uid,
            '{DAV:}displayname' => $uid,
            '{http://sabredav.org/ns}email-address' => $email,
        ];
    }

    /**
     * @param string $prefixPath collection prefix, e.g. "principals/users"
     * @return list<array<string, mixed>>
     */
    public function getPrincipalsByPrefix($prefixPath): array {
        return array_values(array_filter($this->principals, static fn (array $p): bool => str_starts_with($p['uri'], $prefixPath . '/')));
    }

    /**
     * @param string $path principal URI
     * @return array<string, mixed>|null
     */
    public function getPrincipalByPath($path): ?array {
        return $this->principals[$path] ?? null;
    }

    /**
     * @param string $path principal URI
     * @param \Sabre\DAV\PropPatch $propPatch property changes
     * @return void
     */
    public function updatePrincipal($path, \Sabre\DAV\PropPatch $propPatch): void {
        if (isset($this->principals[$path])) {
            $propPatch->handleRemainingResultCode(200);
        }
    }

    /**
     * @param string $prefixPath collection prefix
     * @param array<string, mixed> $searchProperties properties to match
     * @param string $test match mode
     * @return list<string>
     */
    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof'): array {
        $found = [];
        foreach ($this->getPrincipalsByPrefix($prefixPath) as $principal) {
            foreach ($searchProperties as $property => $value) {
                if (($principal[$property] ?? null) === $value) {
                    $found[] = $principal['uri'];
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * @param string $uri address to look for
     * @param string $principalPrefix collection prefix
     * @return list<array<string, mixed>>
     */
    public function findByUri($uri, $principalPrefix): array {
        return array_values(array_filter(
            $this->getPrincipalsByPrefix($principalPrefix),
            static fn (array $p): bool => ($p['{http://sabredav.org/ns}email-address'] ?? null) === $uri,
        ));
    }

    /**
     * @param string $principal principal URI
     * @return list<string>
     */
    public function getGroupMemberSet($principal): array {
        return [];
    }

    /**
     * @param string $principal principal URI
     * @return list<string>
     */
    public function getGroupMembership($principal): array {
        return [];
    }

    /**
     * @param string $principal principal URI
     * @param list<string> $members member principals
     * @return void
     */
    public function setGroupMemberSet($principal, array $members): void {
    }
}