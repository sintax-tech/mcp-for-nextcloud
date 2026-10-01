<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\L10n;

use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\L10N\ILanguageIterator;
use OCP\IUser;

/**
 * Test double of the Nextcloud translator: loads l10n/<lang>.json of the app like the real IFactory does, and falls
 * back to the source text when the language has no file or the key has no entry.
 */
final class JsonL10n implements IL10N {
    /** @var array<string, string|list<string>> */
    private array $translations = [];

    public function __construct(private string $language, ?string $directory = null) {
        $file = ($directory ?? __DIR__ . "/../../../l10n") . "/" . $language . ".json";
        if (is_file($file)) {
            $decoded = json_decode((string)file_get_contents($file), true);
            $this->translations = is_array($decoded) ? ($decoded["translations"] ?? []) : [];
        }
    }

    /**
     * Wires a mocked factory to resolve the user language from the given uid map (English by default) and to serve JsonL10n.
     *
     * @param IFactory&\PHPUnit\Framework\MockObject\MockObject $factory
     * @param array<string, string> $languages language by uid
     */
    public static function wire(IFactory $factory, array $languages = [], string $serverDefault = "en", ?string $forcedLanguage = null): IFactory {
        $factory->method("getUserLanguage")->willReturnCallback(static fn (?IUser $user): string => $languages[$user?->getUID()] ?? "en");
        $factory->method("languageExists")->willReturnCallback(static function (string $app, string $lang): bool {
            if ($lang === "en") {
                return true;
            }
            $file = __DIR__ . "/../../../l10n/" . $lang . ".json";
            return is_file($file);
        });
        $factory->method("getLanguageIterator")->willReturnCallback(static function (?IUser $user) use ($languages, $serverDefault, $forcedLanguage): ILanguageIterator {
            $chain = [];
            if ($forcedLanguage !== null) {
                $chain[] = $forcedLanguage;
            }
            $userLang = $user !== null ? ($languages[$user->getUID()] ?? null) : null;
            if ($userLang !== null) {
                $chain[] = $userLang;
            }
            if ($serverDefault !== "") {
                $chain[] = $serverDefault;
            }
            $chain[] = "en";
            return new class($chain) implements ILanguageIterator {
                private int $idx = 0;
                /** @param list<string> $chain */
                public function __construct(private array $chain) {}
                public function current(): string { return $this->chain[$this->idx]; }
                public function key(): int { return $this->idx; }
                public function next(): void { $this->idx++; }
                public function rewind(): void { $this->idx = 0; }
                public function valid(): bool { return isset($this->chain[$this->idx]); }
            };
        });
        $factory->method("get")->willReturnCallback(static fn (string $app, ?string $lang = null): IL10N => new self((string)$lang));
        return $factory;
    }

    public function t(string $text, $parameters = []): string {
        $translated = $this->translations[$text] ?? $text;
        return vsprintf(is_array($translated) ? $text : $translated, (array)$parameters);
    }

    public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
        $forms = $this->translations["_" . $text_singular . "_::_" . $text_plural . "_"] ?? [$text_singular, $text_plural];
        $form = $this->language === "en" || !isset($this->translations["_" . $text_singular . "_::_" . $text_plural . "_"])
            ? ($count === 1 ? 0 : 1)
            : ($count === 0 || $count === 1 ? 0 : 2);
        return vsprintf(str_replace("%n", (string)$count, $forms[$form] ?? $forms[0]), $parameters);
    }

    public function l(string $type, $data, array $options = []) {
        return (string)$data;
    }

    public function getLanguageCode(): string {
        return $this->language;
    }

    public function getLocaleCode(): string {
        return $this->language;
    }
}