<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Checkout\CheckoutToken;
use OCA\Mcp\Checkout\CheckoutTokenStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\ToolFailure;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IURLGenerator;

/**
 * Issues the temporary links that let an agent edit a file with its own local tools: one single-use
 * download link and one single-use upload link, both bound to the user, the file id and the ETag read at
 * checkout time. files_upload gets a third kind, a single-use create link bound to the user and a path that
 * must still be free. Only keyed hashes of the tokens are persisted; the plain values leave this class once,
 * inside the URLs.
 */
final class CheckoutService {
    /** Lifetime of a download link, in seconds. */
    public const DOWNLOAD_TTL = 300;
    /** Lifetime of an upload link, in seconds. */
    public const UPLOAD_TTL = 900;
    /** Upload limit used when the administrator configured none, in bytes. */
    public const DEFAULT_MAX_BYTES = 50 * 1024 * 1024;
    /** App config key holding the upload limit in bytes. */
    public const MAX_BYTES_KEY = 'checkout_max_bytes';
    /** The app whose versions the upload depends on, checked at checkout so no unusable link is issued. */
    public const VERSIONS_APP = 'files_versions';
    /** Token prefix, so a leaked URL is recognisable as this app's and not an OAuth token. */
    private const TOKEN_PREFIX = 'ncmcp_co_';
    /** Route each kind of token is spent on; a token presented on the other one is refused. */
    private const ROUTES = [
        CheckoutToken::KIND_DOWNLOAD => 'mcp.checkout.download',
        CheckoutToken::KIND_UPLOAD => 'mcp.checkout.upload',
        // A create link is a raw upload too, so it is spent on the same route and the controller tells them apart.
        CheckoutToken::KIND_CREATE => 'mcp.checkout.upload',
    ];

    public function __construct(
        private IURLGenerator $urlGenerator,
        private IConfig $config,
        private ITimeFactory $time,
        private TokenHasher $hasher,
        private CheckoutTokenStore $store,
        private IAppManager $appManager,
        private IUserManager $userManager,
    ) {}

    /**
     * Mints both links for one file and returns what files_checkout reports.
     *
     * @param string $userId authenticated user
     * @param File $file the file about to be edited
     * @param string $path normalized user-relative path of $file
     * @param array<string, mixed> $access ownership description from NodeAccessInfo
     * @param bool $confirmed whether the caller passed confirm_shared; stored so the upload trusts the checkout
     * @return array{path:string, etag:string, size:int, mime:string, access:array<string, mixed>, download_url:string, upload_url:string, expires_at:string}
     * @throws \OCA\Mcp\Tools\ToolFailure when versioning is off, which the upload would refuse later
     */
    public function issue(string $userId, File $file, string $path, array $access, bool $confirmed): array {
        $this->assertAvailable($userId);
        $now = $this->time->getTime();
        [$downloadToken, $uploadToken] = [$this->mint(CheckoutToken::KIND_DOWNLOAD), $this->mint(CheckoutToken::KIND_UPLOAD)];
        $row = [
            'file_id' => (int)$file->getId(),
            'path' => $path,
            'etag' => (string)$file->getEtag(),
            'scope' => (string)$access['scope'],
            'shared_confirmed' => $confirmed ? 1 : 0,
            'created_at' => $now,
        ];
        $this->store->insert($row + [
            'token_hash' => $this->hasher->hash($downloadToken),
            'kind' => CheckoutToken::KIND_DOWNLOAD,
            'user_id' => $userId,
            'expires_at' => $now + self::DOWNLOAD_TTL,
        ], $now);
        $this->store->insert($row + [
            'token_hash' => $this->hasher->hash($uploadToken),
            'kind' => CheckoutToken::KIND_UPLOAD,
            'user_id' => $userId,
            'expires_at' => $now + self::UPLOAD_TTL,
        ], $now);
        return [
            'path' => $path,
            'etag' => (string)$file->getEtag(),
            'size' => (int)$file->getSize(),
            'mime' => (string)$file->getMimetype(),
            'access' => $access,
            'download_url' => $this->url(CheckoutToken::KIND_DOWNLOAD, $downloadToken),
            'upload_url' => $this->url(CheckoutToken::KIND_UPLOAD, $uploadToken),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + self::UPLOAD_TTL),
        ];
    }

    /**
     * Mints the single-use link files_upload hands out: one PUT that creates the file at $path.
     *
     * The row keeps the path and never a node id, because there is no node yet; the upload resolves the folder
     * again and refuses when the name was taken in the meantime. No versioning check: a new file has nothing to back up.
     *
     * @param string $userId authenticated user
     * @param string $path normalized user-relative path of the file to create
     * @param array<string, mixed> $access ownership description of the destination folder, from NodeAccessInfo
     * @param bool $confirmed whether the caller passed confirm_shared; stored so the upload trusts the plan
     * @param int|null $size size the agent declared for the file, kept so an empty body can be told from a failed client
     * @return array{upload_url:string, expires_at:string} the link and when it stops working
     */
    public function issueCreate(string $userId, string $path, array $access, bool $confirmed, ?int $size = null): array {
        $now = $this->time->getTime();
        $token = $this->mint(CheckoutToken::KIND_CREATE);
        $this->store->insert([
            'token_hash' => $this->hasher->hash($token),
            'kind' => CheckoutToken::KIND_CREATE,
            'user_id' => $userId,
            'file_id' => 0,
            'path' => $path,
            'etag' => CheckoutToken::declaredSizeMarker($size),
            'scope' => (string)$access['scope'],
            'shared_confirmed' => $confirmed ? 1 : 0,
            'created_at' => $now,
            'expires_at' => $now + self::UPLOAD_TTL,
        ], $now);
        return [
            'upload_url' => $this->url(CheckoutToken::KIND_CREATE, $token),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $now + self::UPLOAD_TTL),
        ];
    }

    /**
     * Whether a presented token was minted as a create link, read from its prefix alone.
     *
     * Only for decisions taken before the store is read (an empty body is a valid new file but would erase an
     * existing one). The prefix is part of the hashed value, so a token whose prefix lies matches no row; the kind
     * that authorises anything is still the one stored with the row.
     *
     * @param string $token plain token from the route
     * @return bool true when the token carries the create prefix
     */
    public static function isCreateToken(string $token): bool {
        return str_starts_with($token, self::TOKEN_PREFIX . CheckoutToken::KIND_CREATE[0] . '_');
    }

    /**
     * Refuses a checkout whose upload could never be honoured, without minting anything.
     *
     * The upload always takes a backup, so a checkout without files_versions would hand out two links
     * that cannot work. The plan and the mint ask the same question here, so both say so the same way.
     *
     * @param string $userId authenticated user
     * @throws ToolFailure when versioning is off for this account
     */
    public function assertAvailable(string $userId): void {
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser(self::VERSIONS_APP, $user)) {
            throw new ToolFailure(FilesMessages::versionsOff());
        }
    }

    /**
     * @return int effective upload limit in bytes: the configured one, never above what php.ini accepts
     */
    public function maxBytes(): int {
        $limit = $this->configuredMaxBytes();
        $post = $this->phpMaxBytes();
        return $post > 0 ? min($limit, $post) : $limit;
    }

    /** @return int upload limit the administrator set, in bytes; DEFAULT_MAX_BYTES when none or an invalid one is stored */
    public function configuredMaxBytes(): int {
        $configured = (int)$this->config->getAppValue('mcp', self::MAX_BYTES_KEY, (string)self::DEFAULT_MAX_BYTES);
        return $configured > 0 ? $configured : self::DEFAULT_MAX_BYTES;
    }

    /** @return int request body ceiling of php.ini (post_max_size) in bytes, 0 when unlimited or unreadable */
    public function phpMaxBytes(): int {
        return self::iniBytes((string)ini_get('post_max_size'));
    }

    /** @return string 32 random bytes as base64url, prefixed to be recognisable in a leaked URL */
    private function mint(string $kind): string {
        return self::TOKEN_PREFIX . $kind[0] . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @param string $kind CheckoutToken::KIND_DOWNLOAD, KIND_UPLOAD or KIND_CREATE
     * @param string $token plain token, never logged or persisted
     * @return string absolute URL of the route that spends it
     */
    private function url(string $kind, string $token): string {
        return $this->urlGenerator->linkToRouteAbsolute(self::ROUTES[$kind], ['token' => $token]);
    }

    /**
     * @param string $value a php.ini shorthand such as "50M", "0" meaning unlimited
     * @return int the size in bytes, 0 when the directive is unlimited or unparsable
     */
    private static function iniBytes(string $value): int {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+)\s*([KMG]?)B?$/i', $value, $m)) {
            return 0;
        }
        return (int)$m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
    }
}
