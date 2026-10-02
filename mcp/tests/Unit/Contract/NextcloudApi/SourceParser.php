<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

/**
 * Reads the public surface of Nextcloud classes from their source, without loading them: public methods with their
 * parameter names, public constants, enum cases, public static properties and the instance fields an
 * `OCP\AppFramework\Db\Entity` turns into magic getters and setters, with what a class inherits from parents and
 * interfaces found in the same source tree. It only serves `generate.php`, which writes the fixtures
 * of tests/fixtures/nextcloud-api; the tests read those fixtures and never the sources.
 */
final class SourceParser {
    /** Where each namespace lives in a Nextcloud tree with the apps under apps/. */
    private const DIRECTORIES = [
        'OCP\\' => 'lib/public/',
        'OC\\' => 'lib/private/',
        'OCA\\DAV\\' => 'apps/dav/lib/',
        'OCA\\Deck\\' => 'apps/deck/lib/',
        'OCA\\Talk\\' => 'apps/spreed/lib/',
        'OCA\\Files_Versions\\' => 'apps/files_versions/lib/',
        'OCA\\Files_Trashbin\\' => 'apps/files_trashbin/lib/',
        'OCA\\Files_Sharing\\' => 'apps/files_sharing/lib/',
    ];

    /** @var array<string, array<string, mixed>|null> parsed classes, null when absent */
    private array $parsed = [];

    /** @param string $root Nextcloud server tree with Deck and Talk under apps/ */
    public function __construct(private string $root) {}

    /**
     * @param string $class fully qualified class, interface, trait or enum
     * @return array{kind:string, methods:array<string, list<string>>, constants:list<string>, properties:list<string>, fields:list<string>, external:list<string>}|null
     *     its public surface, inherited members included, and the ancestors outside the tree (Sabre, Doctrine…) whose
     *     members it also inherits; a parameter with a default ends in "?", a variadic one starts
     *     with "..."; null when the tree has no such class
     */
    public function surface(string $class): ?array {
        if (array_key_exists($class, $this->parsed)) {
            return $this->parsed[$class];
        }
        $this->parsed[$class] = null;
        $file = $this->file($class);
        if ($file === null) {
            return null;
        }
        $own = $this->parse($file);
        $surface = ['kind' => $own['kind'], 'methods' => $own['methods'], 'constants' => $own['constants'], 'properties' => $own['properties'], 'fields' => $own['fields'], 'external' => []];
        foreach ($own['parents'] as $parent) {
            $inherited = $this->surface($parent);
            if ($inherited === null) {
                if ($this->file($parent) === null && !$this->inTree($parent)) {
                    $surface['external'][] = $parent;
                }
                continue;
            }
            $surface['external'] = array_values(array_unique([...$surface['external'], ...$inherited['external']]));
            $surface['methods'] += $inherited['methods'];
            $surface['constants'] = array_values(array_unique([...$surface['constants'], ...$inherited['constants']]));
            $surface['properties'] = array_values(array_unique([...$surface['properties'], ...$inherited['properties']]));
            $surface['fields'] = array_values(array_unique([...$surface['fields'], ...$inherited['fields']]));
        }
        ksort($surface['methods']);
        sort($surface['constants']);
        sort($surface['properties']);
        sort($surface['fields']);
        sort($surface['external']);
        return $this->parsed[$class] = $surface;
    }

    /** @return bool whether the class belongs to a namespace this tree holds, so its absence means it does not exist */
    private function inTree(string $class): bool {
        foreach (array_keys(self::DIRECTORIES) as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }
        return $class === 'OC';
    }

    /** @return string|null the file declaring the class, if the tree has it */
    private function file(string $class): ?string {
        if ($class === 'OC') {
            return is_file($this->root . '/lib/base.php') ? $this->root . '/lib/base.php' : null;
        }
        foreach (self::DIRECTORIES as $prefix => $directory) {
            if (str_starts_with($class, $prefix)) {
                $file = $this->root . '/' . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                return is_file($file) ? $file : null;
            }
        }
        return null;
    }

    /**
     * @return array{kind:string, parents:list<string>, methods:array<string, list<string>>, constants:list<string>, properties:list<string>, fields:list<string>}
     *     the members the first class-like declaration of the file declares itself
     */
    private function parse(string $file): array {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize((string)file_get_contents($file)),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $namespace = '';
        $imports = [];
        $result = ['kind' => '', 'parents' => [], 'methods' => [], 'constants' => [], 'properties' => [], 'fields' => []];
        $depth = 0;
        $body = null;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }
            if ($token->text === '}') {
                $depth--;
                if ($body !== null && $depth < $body) {
                    break;
                }
                continue;
            }
            if ($body === null) {
                if ($token->id === T_NAMESPACE) {
                    $namespace = $tokens[$i + 1]->text;
                } elseif ($token->id === T_USE && $depth === 0) {
                    $name = ltrim($tokens[$i + 1]->text, '\\');
                    $alias = ($tokens[$i + 2]->id ?? null) === T_AS ? $tokens[$i + 3]->text : substr($name, (int)strrpos('\\' . $name, '\\'));
                    $imports[$alias] = $name;
                } elseif (in_array($token->id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                    && !in_array($tokens[$i - 1]->id ?? null, [T_DOUBLE_COLON, T_NEW], true)) {
                    $result['kind'] = strtolower($token->text);
                    for ($i += 2; $i < $count && $tokens[$i]->text !== '{'; $i++) {
                        if (in_array($tokens[$i]->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                            $result['parents'][] = $this->resolve($tokens[$i]->text, $namespace, $imports);
                        }
                    }
                    $depth++;
                    $body = $depth;
                }
                continue;
            }
            if ($depth !== $body) {
                continue;
            }
            $modifiers = $this->modifiers($tokens, $i);
            // A protected field is what an Entity serves through its magic getX()/setX(); a private one never is.
            if ($token->id === T_VARIABLE && $modifiers !== [] && !in_array('static', $modifiers, true) && !in_array('private', $modifiers, true)) {
                $result['fields'][] = substr($token->text, 1);
                continue;
            }
            if (in_array('private', $modifiers, true) || in_array('protected', $modifiers, true)) {
                continue;
            }
            if ($token->id === T_FUNCTION) {
                $name = $tokens[$i + 1]->text === '&' ? $tokens[$i + 2]->text : $tokens[$i + 1]->text;
                $result['methods'][$name] = $this->parameters($tokens, $i);
            } elseif ($token->id === T_CONST) {
                for ($j = $i + 1; $j < $count && $tokens[$j]->text !== ';'; $j++) {
                    if ($tokens[$j]->id === T_STRING && ($tokens[$j + 1]->text ?? '') === '=') {
                        $result['constants'][] = $tokens[$j]->text;
                    }
                }
            } elseif ($token->id === T_CASE) {
                $result['constants'][] = $tokens[$i + 1]->text;
            } elseif ($token->id === T_VARIABLE && in_array('static', $modifiers, true)) {
                $result['properties'][] = substr($token->text, 1);
            }
        }
        return $result;
    }

    /**
     * @param list<\PhpToken> $tokens tokens of the file, without whitespace and comments
     * @param int $at position of the member keyword or of the property variable
     * @return list<string> the modifiers written before it, such as "public" and "static"
     */
    private function modifiers(array $tokens, int $at): array {
        $found = [];
        $skippable = [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_VAR, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE];
        for ($j = $at - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (in_array($token->id, [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY, T_VAR], true)) {
                $found[] = strtolower($token->text);
                continue;
            }
            // Property types (`public static string $x`, `?Foo`, `A|B`) sit between the modifiers and the variable.
            if (in_array($token->id, $skippable, true) || in_array($token->text, ['?', '|', '&', '(', ')'], true)) {
                if ($tokens[$at]->id === T_VARIABLE) {
                    continue;
                }
            }
            break;
        }
        return $found;
    }

    /**
     * @param list<\PhpToken> $tokens tokens of the file, without whitespace and comments
     * @param int $at position of the `function` keyword
     * @return list<string> parameter names, "?" appended to an optional one and "..." prepended to a variadic one
     */
    private function parameters(array $tokens, int $at): array {
        $i = $at;
        while ($tokens[$i]->text !== '(') {
            $i++;
        }
        $parameters = [];
        $nesting = 0;
        $variadic = false;
        for ($i++; isset($tokens[$i]); $i++) {
            $text = $tokens[$i]->text;
            if ($text === '(' || $text === '[') {
                $nesting++;
            } elseif ($text === ')' || $text === ']') {
                if ($nesting === 0) {
                    break;
                }
                $nesting--;
            } elseif ($nesting === 0 && $tokens[$i]->id === T_ELLIPSIS) {
                $variadic = true;
            } elseif ($nesting === 0 && $tokens[$i]->id === T_VARIABLE) {
                $parameters[] = ($variadic ? '...' : '') . substr($text, 1);
                $variadic = false;
            } elseif ($nesting === 0 && $text === '=' && $parameters !== []) {
                $parameters[count($parameters) - 1] .= '?';
            }
        }
        return $parameters;
    }

    /** @param array<string, string> $imports alias => imported class */
    private function resolve(string $name, string $namespace, array $imports): string {
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        $first = explode('\\', $name)[0];
        if (isset($imports[$first])) {
            return $imports[$first] . substr($name, strlen($first));
        }
        return ltrim($namespace . '\\' . $name, '\\');
    }
}
