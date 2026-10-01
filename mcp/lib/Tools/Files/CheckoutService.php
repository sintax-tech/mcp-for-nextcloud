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
 * checkout time. Only keyed hashes of the tokens are persisted; the plain values leave this class once,
 * inside the two URLs.
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
        // The upload always takes a backup, so a checkout without files_versions would hand out two links
        // that cannot work. Better to say so now, with both links still unused.
        $user = $this->userManager->get($userId);
        if ($user === null || !$this->appManager->isEnabledForUser(self::VERSIONS_APP, $user)) {
            throw new ToolFailure(FilesMessages::versionsOff());
        }
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
     * @return int effective upload limit in bytes: the configured one, never above what php.ini accepts
     */
    public function maxBytes(): int {
        $configured = (int)$this->config->getAppValue('mcp', self::MAX_BYTES_KEY, (string)self::DEFAULT_MAX_BYTES);
        $limit = $configured > 0 ? $configured : self::DEFAULT_MAX_BYTES;
        $post = self::iniBytes((string)ini_get('post_max_size'));
        return $post > 0 ? min($limit, $post) : $limit;
    }

    /** @return string 32 random bytes as base64url, prefixed to be recognisable in a leaked URL */
    private function mint(string $kind): string {
        return self::TOKEN_PREFIX . $kind[0] . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @param string $kind CheckoutToken::KIND_DOWNLOAD or KIND_UPLOAD
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
