<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit;

use OCA\Mcp\Controller\CheckoutController;
use PHPUnit\Framework\TestCase;

/**
 * The routing contract of appinfo/routes.php, exercised instead of assumed.
 *
 * Nextcloud resolves `name#method` through OC\Route\RouteParser::buildControllerName(), which is
 * `underScoreToCamelCase(ucfirst($name)) . 'Controller'` — so `mcp#post` means OCA\Mcp\Controller\McpController
 * and `o_auth#token` means OAuthController. A controller declared anywhere else, with no DI alias in its
 * place, fails at request time and not at build time: the class simply is not where the router looks.
 * That is exactly how the checkout routes shipped broken, so this test derives the name the same way
 * Nextcloud does and proves the class and the method are where the router will look.
 */
final class RoutesTest extends TestCase {
    /** App id, equal to the app directory. */
    private const APP = 'mcp';
    /** PSR-4 prefix the app's own namespaces use. */
    private const NAMESPACE = 'OCA\\Mcp\\';

    /**
     * @return list<array{name:string, url:string, verb:string}> every declared route
     */
    private function routes(): array {
        $declared = require __DIR__ . '/../../appinfo/routes.php';
        $this->assertIsArray($declared);
        $this->assertArrayHasKey('routes', $declared);
        foreach ($declared['routes'] as $route) {
            foreach (['name', 'url', 'verb'] as $key) {
                $this->assertArrayHasKey($key, $route, json_encode($route));
                $this->assertIsString($route[$key]);
            }
        }
        return $declared['routes'];
    }

    /**
     * OC\Route\RouteParser::buildControllerName(): ucfirst, then every `_x` becomes `X`, then `Controller`.
     *
     * @param string $name controller name as written in routes.php
     * @return string the class name the router will look for
     */
    private static function controllerClass(string $name): string {
        $short = preg_replace_callback('/_[a-z]?/', static function (array $matches): string {
            return strtoupper(ltrim($matches[0], '_'));
        }, ucfirst($name));
        return self::NAMESPACE . 'Controller\\' . $short . 'Controller';
    }

    /**
     * OC\Route\RouteParser::buildActionName(): the same camel-casing, without the suffix.
     *
     * @param string $action method name as written in routes.php
     * @return string the method name the router will call
     */
    private static function actionMethod(string $action): string {
        return (string)preg_replace_callback('/_[a-z]?/', static function (array $matches): string {
            return strtoupper(ltrim($matches[0], '_'));
        }, $action);
    }

    /** Every route must name a controller that exists in the namespace the router derives, with a public method. */
    public function testEveryRouteResolvesToAControllerMethodTheRouterCanReach(): void {
        $seen = [];
        foreach ($this->routes() as $route) {
            $this->assertStringContainsString('#', $route['name'], $route['name']);
            [$controller, $action] = explode('#', $route['name'], 2);
            $class = self::controllerClass($controller);
            $this->assertTrue(class_exists($class), "$route[name] resolves to $class, which does not exist");
            $method = self::actionMethod($action);
            $this->assertTrue(method_exists($class, $method), "$route[name] has no $method() on $class");
            $this->assertTrue((new \ReflectionMethod($class, $method))->isPublic(), "$route[name] must be public");
            $seen[] = $route['name'];
        }
        $this->assertNotSame([], $seen);
    }

    /** The derivation itself is the contract; a rename in Nextcloud would show up here first. */
    public function testTheDerivationMatchesTheKnownRouteNames(): void {
        $this->assertSame('OCA\Mcp\Controller\McpController', self::controllerClass('mcp'));
        $this->assertSame('OCA\Mcp\Controller\OAuthController', self::controllerClass('o_auth'));
        $this->assertSame('OCA\Mcp\Controller\CheckoutController', self::controllerClass('checkout'));
        $this->assertSame('protectedResource', self::actionMethod('protected_resource'));
    }

    /** Two routes with the same name but different verbs are a collision the router cannot express. */
    public function testRouteNamesAreUnique(): void {
        $names = array_column($this->routes(), 'name');
        $this->assertSame(count($names), count(array_unique($names)), 'a route name may appear only once');
    }

    /** A controller outside lib/Controller would need a DI alias; this app has none, so none may exist. */
    public function testEveryControllerLivesWhereTheRouterLooks(): void {
        $directory = __DIR__ . '/../../lib/Controller';
        $classes = glob($directory . '/*.php') ?: [];
        $this->assertNotSame([], $classes);
        foreach ($classes as $file) {
            $source = (string)file_get_contents($file);
            $this->assertStringContainsString('namespace OCA\\Mcp\\Controller;', $source, basename($file));
            $this->assertStringContainsString('extends Controller', $source, basename($file));
        }
        $this->assertCount(1, glob($directory . '/CheckoutController.php') ?: [], basename($directory));
    }

    /**
     * The checkout routes are public on purpose: the token in the path is the credential, so there is no
     * session and no CSRF token to check. Losing the attribute would turn every link into a redirect.
     */
    public function testCheckoutRoutesArePublicAndCsrfFree(): void {
        $routes = $this->routes();
        $checkout = array_values(array_filter($routes, static fn (array $route) => str_starts_with($route['name'], 'checkout#')));
        $this->assertSame(['checkout#download', 'checkout#upload', 'checkout#uploadPost'], array_column($checkout, 'name'));
        foreach ($checkout as $route) {
            [$controller, $action] = explode('#', $route['name'], 2);
            $attributes = array_map(
                static fn (\ReflectionAttribute $attribute) => $attribute->getName(),
                (new \ReflectionMethod(self::controllerClass($controller), self::actionMethod($action)))->getAttributes(),
            );
            $this->assertContains(\OCP\AppFramework\Http\Attribute\PublicPage::class, $attributes, $route['name']);
            $this->assertContains(\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class, $attributes, $route['name']);
        }
        $this->assertTrue(class_exists(CheckoutController::class), 'the checkout controller must be reachable by the router');
    }
}
