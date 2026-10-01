<?php
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * JSON API of the admin permission matrix. Every endpoint is admin-only and CSRF-protected by
 * Nextcloud's defaults (no NoAdminRequired/NoCSRFRequired); the page sends the requesttoken header.
 * Invalid input answers 400 {"error":"Invalid request"} without saying which part was wrong.
 */
class GrantsController extends Controller {
    /** Pseudo-operation of the bulk endpoint that toggles eligibility instead of a grant. */
    public const ELIGIBLE = 'eligible';
    /** App config key: comma-separated CIMD client_id hosts; absent means DEFAULT_HOSTS. */
    public const HOSTS_KEY = 'oauth_client_hosts';
    /** App config key: '1' when the static native client is accepted, '0' (default) otherwise. */
    public const NATIVE_KEY = 'oauth_native_client_enabled';
    /** Hosts trusted when HOSTS_KEY is absent. */
    public const DEFAULT_HOSTS = ['claude.ai', 'chatgpt.com'];
    // TODO(merge): NativeClient::CLIENT_ID
    public const NATIVE_CLIENT_ID = 'nextcloud-mcp-native';
    // TODO(merge): NativeClient::REDIRECT_URIS
    public const NATIVE_REDIRECT_URIS = ['http://localhost/oauth/callback', 'http://127.0.0.1/oauth/callback', 'http://[::1]/oauth/callback'];

    public function __construct(
        string $appName,
        IRequest $request,
        private GrantMatrix $matrix,
        private GrantPolicy $policy,
        private IUserManager $userManager,
        private IConfig $config,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $search term matched against uid, display name and e-mail
     * @param string $group group id, '' for every user
     * @param int $page 1-based page
     * @return JSONResponse one matrix page (see GrantMatrix::page), or 400
     */
    public function index(string $search = '', string $group = '', int $page = 1): JSONResponse {
        return $this->guard(fn () => $this->matrix->page($search, $group, $page));
    }

    /**
     * Body {eligible: bool} or {module, operation, granted: bool}, never both.
     *
     * @param string $uid existing Nextcloud user id
     * @return JSONResponse the user's updated row, or 400
     */
    public function update(string $uid): JSONResponse {
        return $this->guard(function () use ($uid): array {
            $user = $this->user($uid);
            $eligible = $this->request->getParam('eligible');
            $hasGrant = $this->request->getParam('module') !== null || $this->request->getParam('operation') !== null || $this->request->getParam('granted') !== null;
            if ($eligible !== null && !$hasGrant) {
                $this->policy->setEligible($uid, self::bool($eligible));
            } elseif ($eligible === null && $hasGrant) {
                [$module, $operation] = self::grant($this->request->getParam('module'), $this->request->getParam('operation'));
                $this->policy->setGrant($uid, $module, $operation, self::bool($this->request->getParam('granted')));
            } else {
                throw new InvalidArgumentException('Invalid request');
            }
            return $this->matrix->rows([$user])[0];
        });
    }

    /**
     * Body {uids: list<string> (1..PAGE_SIZE), module: string|null, operation: string, granted: bool}; operation
     * "eligible" with module null toggles eligibility. Every uid is validated before anything is written.
     *
     * @return JSONResponse {updated: int}, or 400
     */
    public function bulk(): JSONResponse {
        return $this->guard(function (): array {
            $uids = $this->request->getParam('uids');
            if (!is_array($uids) || $uids === [] || count($uids) > GrantMatrix::PAGE_SIZE || !array_is_list($uids)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $module = $this->request->getParam('module');
            $operation = $this->request->getParam('operation');
            $granted = self::bool($this->request->getParam('granted'));
            $eligibility = $operation === self::ELIGIBLE && $module === null;
            if (!$eligibility) {
                [$module, $operation] = self::grant($module, $operation);
            }
            $uids = array_values(array_unique(array_map(fn ($uid) => $this->user(is_string($uid) ? $uid : '')->getUID(), $uids)));
            foreach ($uids as $uid) {
                $eligibility ? $this->policy->setEligible($uid, $granted) : $this->policy->setGrant($uid, $module, $operation, $granted);
            }
            return ['updated' => count($uids)];
        });
    }

    /**
     * Body {enabled: bool}.
     *
     * @return JSONResponse {enabled: bool}, or 400
     */
    public function service(): JSONResponse {
        return $this->guard(function (): array {
            $this->policy->setGlobalEnabled(self::bool($this->request->getParam('enabled')));
            return ['enabled' => $this->policy->globalEnabled()];
        });
    }

    /**
     * Current OAuth client settings for the admin page.
     *
     * @return JSONResponse {hosts, hostsDefault, nativeClientEnabled, nativeClientId, nativeRedirectUris}
     */
    public function oauthClients(): JSONResponse {
        return $this->guard(fn (): array => $this->oauthState());
    }

    /**
     * Body {hosts?: list<string>, nativeClientEnabled?: bool}. Everything is validated before anything is written;
     * an empty host list is rejected because at least one host must stay trusted.
     *
     * @return JSONResponse the same shape as oauthClients(), or 400
     */
    public function updateOauthClients(): JSONResponse {
        return $this->guard(function (): array {
            $hosts = $this->request->getParam('hosts');
            $native = $this->request->getParam('nativeClientEnabled');
            if ($hosts === null && $native === null) {
                throw new InvalidArgumentException('Invalid request');
            }
            if ($hosts !== null) {
                $hosts = self::hosts($hosts);
            }
            if ($native !== null) {
                $native = self::bool($native);
            }
            if ($hosts !== null) {
                $this->config->setAppValue($this->appName, self::HOSTS_KEY, implode(',', $hosts));
            }
            if ($native !== null) {
                $this->config->setAppValue($this->appName, self::NATIVE_KEY, $native ? '1' : '0');
            }
            return $this->oauthState();
        });
    }

    /** @return array{hosts:list<string>, hostsDefault:bool, nativeClientEnabled:bool, nativeClientId:string, nativeRedirectUris:list<string>} */
    private function oauthState(): array {
        $raw = $this->config->getAppValue($this->appName, self::HOSTS_KEY, '');
        $hosts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $h) => $h !== ''));
        $default = $hosts === [];
        return [
            'hosts' => $default ? self::DEFAULT_HOSTS : $hosts,
            'hostsDefault' => $default,
            'nativeClientEnabled' => $this->config->getAppValue($this->appName, self::NATIVE_KEY, '0') === '1',
            'nativeClientId' => self::NATIVE_CLIENT_ID,
            'nativeRedirectUris' => self::NATIVE_REDIRECT_URIS,
        ];
    }

    /**
     * @param mixed $value request value, which must be a non-empty list of bare lowercase host names
     * @return list<string> validated hosts without duplicates
     * @throws InvalidArgumentException for an empty list, a non-string or a host with scheme, port, path, wildcard or space
     */
    private static function hosts(mixed $value): array {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new InvalidArgumentException('Invalid request');
        }
        foreach ($value as $host) {
            if (!is_string($host) || preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/D', $host) !== 1 || strlen($host) > 253) {
                throw new InvalidArgumentException('Invalid request');
            }
        }
        return array_values(array_unique($value));
    }

    /** @param callable():array<string, mixed> $action */
    private function guard(callable $action): JSONResponse {
        try {
            return new JSONResponse($action());
        } catch (InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
    }

    /** @throws InvalidArgumentException for an unknown user */
    private function user(string $uid): IUser {
        $user = $uid === '' ? null : $this->userManager->get($uid);
        if ($user === null) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $user;
    }

    /**
     * @param mixed $module module id from the request body, validated against GrantPolicy::CATALOG
     * @param mixed $operation operation id from the request body, validated against GrantPolicy::CATALOG
     * @return array{0:string, 1:string}
     * @throws InvalidArgumentException when the pair is not in GrantPolicy::CATALOG
     */
    private static function grant(mixed $module, mixed $operation): array {
        if (!is_string($module) || !is_string($operation) || !GrantPolicy::inCatalog($module, $operation)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return [$module, $operation];
    }

    /**
     * @param mixed $value request value, which must be a JSON boolean
     * @return bool validated boolean value
     * @throws InvalidArgumentException unless the value is a JSON boolean
     */
    private static function bool(mixed $value): bool {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $value;
    }
}
