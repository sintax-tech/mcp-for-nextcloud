<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

/**
 * Reads the app's own code (lib/) with the PHP tokenizer and lists what it uses from Nextcloud: the classes it names,
 * the methods it calls and the static properties it reads. Comments and PHPDoc are tokens of their own and never count.
 *
 * A class counts when it is named in code — imported with `use`, written fully qualified, or held in a string such as
 * `'OCA\\Talk\\Manager'`, the way the optional apps are resolved without loading them. A method counts by name only:
 * the tokenizer cannot tell the type of `$room->getToken()`, so {@see NextcloudApiContractTest} pairs a name with the
 * classes that declare it. Every `new` of a Nextcloud class is listed with how many arguments it passes.
 */
final class LibScanner {
    /** Namespaces whose classes come from Nextcloud and its apps; `OC` alone is the server's global class. */
    public const NEXTCLOUD_PREFIXES = ['OCP\\', 'OC\\', 'OCA\\'];
    /** The app's own namespace, never a dependency. */
    private const OWN_PREFIX = 'OCA\\Mcp\\';

    /** @var array<string, list<string>> class => files naming it */
    private array $classes = [];
    /** @var array<string, list<string>> method name => files calling it */
    private array $methods = [];
    /** @var array<string, list<string>> "Class::$property" => files reading it */
    private array $properties = [];
    /** @var array<string, list<array{file: string, arguments: int, spread: bool, named: list<string>}>> class => its `new` */
    private array $constructions = [];

    /** @param string $root directory scanned, normally the app's lib/ */
    public function __construct(private string $root) {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $this->scan($file->getPathname());
            }
        }
        ksort($this->classes);
        ksort($this->methods);
        ksort($this->properties);
        ksort($this->constructions);
    }

    /**
     * @return array<string, list<array{file: string, arguments: int, spread: bool, named: list<string>}>> every
     *     `new` of a Nextcloud class, with the number of arguments, whether one is unpacked with `...` and the names of
     *     the named ones
     */
    public function constructions(): array {
        return $this->constructions;
    }

    /** @return array<string, list<string>> Nextcloud classes named in code, with the files naming them */
    public function classes(): array {
        return $this->classes;
    }

    /** @return array<string, list<string>> every method name called in code, with the files calling it */
    public function methods(): array {
        return $this->methods;
    }

    /** @return array<string, list<string>> static properties of Nextcloud classes read in code, as "Class::$name" */
    public function properties(): array {
        return $this->properties;
    }

    /** @param string $path PHP file of lib/ */
    private function scan(string $path): void {
        $relative = substr($path, strlen($this->root) + 1);
        $tokens = array_values(array_filter(
            \PhpToken::tokenize((string)file_get_contents($path)),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $namespace = '';
        $imports = [];
        $depth = 0;
        foreach ($tokens as $i => $token) {
            if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            }
            if ($token->id === T_NAMESPACE && $depth === 0) {
                $namespace = $tokens[$i + 1]->text;
                continue;
            }
            // A top-level `use` imports a class; inside a class it pulls in a trait, inside a closure a variable.
            if ($token->id === T_USE && $depth === 0 && isset($tokens[$i + 1]) && in_array($tokens[$i + 1]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($tokens[$i + 1]->text, '\\');
                $alias = ($tokens[$i + 2]->id ?? null) === T_AS ? $tokens[$i + 3]->text : substr($name, (int)strrpos('\\' . $name, '\\'));
                $imports[$alias] = $name;
                $this->addClass($name, $relative);
                continue;
            }
            if ($token->id === T_NEW && isset($tokens[$i + 1]) && in_array($tokens[$i + 1]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $class = $this->resolve($tokens[$i + 1], $namespace, $imports);
                if ($class !== null && $this->isNextcloud($class)) {
                    $this->constructions[$class][] = ['file' => $relative, ...$this->arguments($tokens, $i + 2)];
                }
            }
            $previous = $tokens[$i - 1] ?? null;
            if ($token->id === T_STRING && $previous !== null && in_array($previous->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                if (($tokens[$i + 1]->text ?? '') === '(') {
                    $this->methods[$token->text][] = $relative;
                }
                continue;
            }
            if (in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                if ($previous !== null && in_array($previous->id, [T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_GOTO], true)) {
                    continue;
                }
                $class = $this->resolve($token, $namespace, $imports);
                if ($class === null) {
                    continue;
                }
                $this->addClass($class, $relative);
                if (($tokens[$i + 1]->id ?? null) === T_DOUBLE_COLON && ($tokens[$i + 2]->id ?? null) === T_VARIABLE && $this->isNextcloud($class)) {
                    $this->properties[$class . '::' . $tokens[$i + 2]->text][] = $relative;
                }
                continue;
            }
            if ($token->id === T_CONSTANT_ENCAPSED_STRING) {
                $value = str_replace('\\\\', '\\', substr($token->text, 1, -1));
                if (preg_match('/^\\\\?(?:OCP|OCA|OC)(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $value) === 1) {
                    $this->addClass(ltrim($value, '\\'), $relative);
                }
            }
        }
    }

    /**
     * @param list<\PhpToken> $tokens tokens of the file, without whitespace and comments
     * @param int $at position right after the class name of a `new`
     * @return array{arguments: int, spread: bool, named: list<string>} the arguments of the call, none without parentheses
     */
    private function arguments(array $tokens, int $at): array {
        $found = ['arguments' => 0, 'spread' => false, 'named' => []];
        if (($tokens[$at]->text ?? '') !== '(') {
            return $found;
        }
        $nesting = 0;
        $pending = false;
        for ($i = $at + 1; isset($tokens[$i]); $i++) {
            $text = $tokens[$i]->text;
            if (in_array($text, ['(', '[', '{'], true) || in_array($tokens[$i]->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true)) {
                $nesting++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($nesting === 0) {
                    break;
                }
                $nesting--;
            }
            if ($nesting !== 0 || $text === ')') {
                $pending = $pending || $nesting !== 0;
                continue;
            }
            if ($text === ',') {
                // A trailing comma closes the last argument and opens none.
                $found['arguments'] += $pending ? 1 : 0;
                $pending = false;
                continue;
            }
            if ($tokens[$i]->id === T_ELLIPSIS) {
                $found['spread'] = true;
            } elseif ($tokens[$i]->id === T_STRING && ($tokens[$i + 1]->text ?? '') === ':' && !$pending) {
                $found['named'][] = $text;
            }
            $pending = true;
        }
        $found['arguments'] += $pending ? 1 : 0;
        return $found;
    }

    /**
     * @param array<string, string> $imports alias => imported class of the file
     * @return string|null the class a name stands for, or null when it is not a class from outside the file
     */
    private function resolve(\PhpToken $token, string $namespace, array $imports): ?string {
        if ($token->id === T_NAME_FULLY_QUALIFIED) {
            return ltrim($token->text, '\\');
        }
        $first = explode('\\', $token->text)[0];
        if (isset($imports[$first])) {
            return $imports[$first] . substr($token->text, strlen($first));
        }
        // An unqualified, unimported name belongs to the file's own namespace (or is a constant, a function, a keyword).
        return $token->id === T_NAME_QUALIFIED && $namespace === '' ? $token->text : null;
    }

    /** @return bool whether the class comes from Nextcloud rather than from the app or a library */
    public function isNextcloud(string $class): bool {
        if ($class === 'OC') {
            return true;
        }
        foreach (self::NEXTCLOUD_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return !str_starts_with($class, self::OWN_PREFIX);
            }
        }
        return false;
    }

    private function addClass(string $class, string $file): void {
        if ($this->isNextcloud($class) && !in_array($file, $this->classes[$class] ?? [], true)) {
            $this->classes[$class][] = $file;
        }
    }
}
