<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\PlanText;

/**
 * Writes the plan of a contact write as the text the person asked to confirm reads.
 *
 * The module already knows everything the plan carries, so this class only decides what to say:
 * the contact by the name its owner gave it, the address book it lives in, and only the fields
 * an edit really changes. What the plan says about the consequences of the write — the address
 * book belongs to somebody else, the deletion is permanent, a backup is saved first — is part of
 * the text, because a confirmation without it is a confirmation the person cannot give knowingly.
 *
 * A plan that does not carry what this renderer expects gives back `null`, and the caller falls
 * back to the generic body, which is worse but never missing.
 */
final class ContactsPlanRenderer {
    /** Longest excerpt of a long text (a note) the body shows; the plan keeps the whole text. */
    private const EXCERPT = 200;

    /**
     * Contact fields a person reads, in the order a card shows them.
     *
     * @var list<string>
     */
    private const FIELDS = ['name', 'organization', 'title', 'emails', 'phones', 'url', 'note'];

    /** Fields whose value holds several values, joined for the reader instead of printed as JSON. */
    private const LISTS = ['emails', 'phones'];

    /**
     * @param string $tool write tool of this module
     * @param array<string, mixed> $plan plan returned by ContactsModule::preview()
     * @return string|null Markdown body, or null when the plan lacks what this renderer needs
     */
    public function render(string $tool, array $plan): ?string {
        $book = $this->name($plan['addressbook'] ?? null);
        if ($book === null) {
            return null;
        }
        $body = match ($tool) {
            'contacts_create_contact' => $this->create($book, $plan),
            'contacts_edit_contact' => $this->edit($book, $plan),
            'contacts_delete_contact' => $this->delete($book, $plan),
            default => null,
        };
        if ($body === null) {
            return null;
        }
        return $this->shared($body, $plan, $book);
    }

    /**
     * What the card will hold: the fields with a value, as a person would read them.
     *
     * @param string $book display name of the address book the contact goes into
     * @param array<string, mixed> $plan the plan of a create
     * @return string|null the body, or null when the card has no name to show
     */
    private function create(string $book, array $plan): ?string {
        $after = $this->card($plan['after'] ?? null);
        $name = $after === null ? '' : $this->text($after['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $lines = [Translator::t('Creating the contact %s in the address book %s.', [$this->strong($name), $this->emphasis($book)])];
        foreach (self::FIELDS as $field) {
            if ($field === 'name') {
                continue;
            }
            $value = $this->value($after[$field] ?? null, in_array($field, self::LISTS, true));
            if ($value !== null) {
                $lines[] = '- ' . $this->label($field) . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * What an edit changes: one line per field whose value differs, from what it is to what it becomes.
     *
     * @param string $book display name of the address book of the contact
     * @param array<string, mixed> $plan the plan of an edit
     * @return string|null the body, or null when the plan carries no card to compare
     */
    private function edit(string $book, array $plan): ?string {
        $before = $this->card($plan['before'] ?? null);
        $after = $this->card($plan['after'] ?? null);
        if ($before === null || $after === null) {
            return null;
        }
        $name = $this->text($before['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $lines = [Translator::t(
            'Editing the contact %s in the address book %s.',
            [$this->strong($name), $this->emphasis($book)]
        )];
        foreach (self::FIELDS as $field) {
            $from = $this->value($before[$field] ?? null, in_array($field, self::LISTS, true));
            $to = $this->value($after[$field] ?? null, in_array($field, self::LISTS, true));
            if ($from === $to) {
                continue;
            }
            $lines[] = '- ' . $this->label($field) . ': ' . ($from ?? Translator::t('empty'))
                . ' → ' . ($to ?? Translator::t('empty'));
        }

        return implode("\n", $lines);
    }

    /**
     * What deleting means: the contact by name, that it cannot be undone, and where the backup goes.
     *
     * @param string $book display name of the address book of the contact
     * @param array<string, mixed> $plan the plan of a delete
     * @return string|null the body, or null when the plan carries no card to name
     */
    private function delete(string $book, array $plan): ?string {
        $before = $this->card($plan['before'] ?? null);
        $name = $before === null ? '' : $this->text($before['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $lines = [Translator::t(
            'Deleting the contact %s from the address book %s.',
            [$this->strong($name), $this->emphasis($book)]
        )];
        $warning = $this->text($plan['warning'] ?? '');
        if ($warning !== '') {
            $lines[] = PlanText::inline($warning, 300);
        }
        $backup = is_array($plan['backup'] ?? null) ? $plan['backup'] : [];
        $directory = $this->text($backup['directory'] ?? '');
        $format = $this->text($backup['format'] ?? '');
        if ($directory !== '') {
            $lines[] = Translator::t(
                'A copy of the card (%s) is saved in %s before the deletion; if that copy fails, nothing is deleted.',
                ['.' . PlanText::inline($format === '' ? 'vcf' : ltrim($format, '.'), 20), $this->emphasis($directory)]
            );
        } else {
            $message = $this->text($backup['message'] ?? '');
            if ($message !== '') {
                $lines[] = PlanText::inline($message, 300);
            }
        }
        $message = $this->text($backup['message'] ?? '');
        if ($directory !== '' && $message !== '') {
            $lines[] = Translator::t(
                'The card stays there: import the file through Contacts to bring the contact back.'
            );
        }

        return implode("\n", $lines);
    }

    /**
     * A write on somebody else's address book affects other people, and the person must hear it.
     *
     * @param string $body body rendered so far
     * @param array<string, mixed> $plan the whole plan
     * @param string $book display name of the address book, used when the notice names none
     * @return string the body with the notice of a shared address book, if the plan carries one
     */
    private function shared(string $body, array $plan, string $book): string {
        foreach ((array) ($plan['shared'] ?? []) as $notice) {
            if (!is_array($notice)) {
                continue;
            }
            $owner = $this->text($notice['ownerDisplayName'] ?? $notice['owner'] ?? '');
            if ($owner === '') {
                continue;
            }
            $resource = $this->text($notice['resource'] ?? '') ?: $book;
            return $body . "\n" . Translator::t(
                'The address book %s belongs to %s and is shared with you, so the change affects other people.',
                [$this->emphasis($resource), $this->strong($owner)]
            );
        }

        return $body;
    }

    /**
     * @param mixed $value raw plan value of an address book or a calendar
     * @return string|null the display name, or null when the plan carries none
     */
    private function name(mixed $value): ?string {
        if (!is_array($value)) {
            return null;
        }
        $name = $this->text($value['name'] ?? '');

        return $name === '' ? null : $name;
    }

    /**
     * @param mixed $value raw plan value of a card
     * @return array<string, mixed>|null the card fields, or null when the plan carries no card
     */
    private function card(mixed $value): ?array {
        return is_array($value) && $value !== [] ? $value : null;
    }

    /**
     * @param mixed $value raw plan value of one field
     * @param bool $list whether the field holds several values
     * @return string|null the value a person reads, or null when it is empty
     */
    private function value(mixed $value, bool $list): ?string {
        if ($list) {
            if (!is_array($value)) {
                return null;
            }
            $values = array_values(array_filter(
                array_map(fn (mixed $item): string => $this->text($item), $value),
                static fn (string $item): bool => $item !== ''
            ));

            return $values === [] ? null : PlanText::inline(implode(', ', $values), 300);
        }
        $text = $this->text($value);

        return $text === '' ? null : PlanText::inline($text, self::EXCERPT);
    }

    /** @param string $field key of a contact field */
    private function label(string $field): string {
        // One literal per call: the translation tool extracts the source texts from here.
        return match ($field) {
            'name' => Translator::t('Name'),
            'organization' => Translator::t('Organization'),
            'title' => Translator::t('Job title'),
            'emails' => Translator::t('E-mails'),
            'phones' => Translator::t('Phones'),
            'url' => Translator::t('Website'),
            'note' => Translator::t('Note'),
            default => Translator::t('Name'),
        };
    }

    /** @param mixed $value raw plan value */
    private function text(mixed $value): string {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param string $text the name a person recognizes @return string the name in bold, inert as Markdown */
    private function strong(string $text): string {
        return PlanText::strong($text);
    }

    /** @param string $text the name of a collection, read as a name and not as an id @return string the name in italics, inert as Markdown */
    private function emphasis(string $text): string {
        return PlanText::em($text);
    }
}
