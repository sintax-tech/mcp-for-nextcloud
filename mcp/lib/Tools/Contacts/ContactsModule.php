<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use InvalidArgumentException;
use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\Calendar\Calendar;
use OCA\Mcp\Tools\Calendar\SharedGuard;
use OCA\Mcp\Tools\Calendar\ToolSchema;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Dav\CollectionSchema as Schema;
use OCA\Mcp\Tools\PreviewsWrites;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolGuideNotes;
use OCA\Mcp\Tools\ToolModule;
use OCA\Mcp\Tools\ToolResult;
use OCA\Mcp\Tools\WriteGate;
use Sabre\VObject\Component\VCard;
use RuntimeException;

/** Personal CardDAV contacts; writes use the same native ACL/validation pipeline as the web UI. */
final class ContactsModule implements ToolModule, PreviewsWrites, ToolGuideNotes {
    /**
     * Receives collection access, CardDAV ports, preserving vCard edits and verified backups.
     *
     * @param ContactAccess $access collection visibility and write-access resolver
     * @param ContactStore $store read-only storage port
     * @param ContactDav $dav native DAV write port
     * @param ContactCard $cards vCard parser and preserving patch builder
     * @param SharedGuard $guard shared-owner confirmation policy
     * @param ContactBackup $backup verified contact backup service
     * @return void
     */
    public function __construct(
        private ContactAccess $access,
        private ContactStore $store,
        private ContactDav $dav,
        private ContactCard $cards,
        private SharedGuard $guard,
        private ContactBackup $backup,
    ) {}

    /**
     * Declares tool schemas and operation kinds used to decide whether calls need confirmation.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array {
        $book = Schema::text('Address book path returned by List address books.', 1, 1024);
        $uri = Schema::text('Contact object URI returned by Search contacts.', 1, 255);
        $fields = [];
        foreach (['name', 'organization', 'title', 'note', 'url'] as $field) {
            $fields[$field] = Schema::text(
                'Contact ' . $field . '. Omit to preserve its existing value.',
                $field === 'name' ? 1 : 0,
                $field === 'note' ? 65536 : 1024
            );
        }
        $fields['organization']['description'] = 'Organization company name (first ORG component); '
            . 'existing departments and units are preserved.';
        foreach (['emails', 'phones'] as $field) {
            $fields[$field] = [
                'type' => 'array',
                'maxItems' => 50,
                'items' => Schema::text('Value.', 1, 1024),
                'description' => 'Replace the ' . $field . ' in order; existing parameters are preserved. '
                    . 'Omit to keep all values, [] to clear them.',
            ];
        }
        return [
            Schema::definition(
                'contacts',
                'contacts',
                'contacts_list_addressbooks',
                'List your own and shared address books, excluding the system user directory.',
                'read',
                []
            ),
            Schema::definition(
                'contacts',
                'contacts',
                'contacts_search_contacts',
                'Search contacts in one visible address book by name, email, phone or organization; '
                    . 'an empty query lists contacts.',
                'read',
                [
                    'addressbook' => $book,
                    'query' => Schema::text('Case-insensitive search text.', 0, 1024),
                    'limit' => Schema::limit(),
                    'offset' => Schema::offset(),
                ],
                ['addressbook']
            ),
            Schema::definition(
                'contacts',
                'contacts',
                'contacts_read_contact',
                'Read a contact including all vCard properties and its ETag.',
                'read',
                ['addressbook' => $book, 'uri' => $uri],
                ['addressbook', 'uri']
            ),
            Schema::definition(
                'contacts',
                'contacts',
                'contacts_create_contact',
                'Create a contact in a writable personal or shared address book.',
                'create',
                ['addressbook' => $book] + $fields,
                ['addressbook', 'name']
            ),
            Schema::definition(
                'contacts',
                'contacts',
                'contacts_edit_contact',
                'Edit only supplied contact fields, preserving unknown vCard properties.',
                'edit',
                ['addressbook' => $book, 'uri' => $uri, 'etag' => ToolSchema::etag()] + $fields,
                ['addressbook', 'uri']
            ),
            Schema::definition(
                'contacts',
                'contacts',
                'contacts_delete_contact',
                'Permanently delete a contact after saving a verified vCard backup in your Files. '
                    . 'Nextcloud has no contact trash.',
                'delete',
                ['addressbook' => $book, 'uri' => $uri, 'etag' => ToolSchema::etag()],
                ['addressbook', 'uri']
            ),
        ];
    }

    /**
     * Describes the confirmation workflow, data protection and recovery limitations.
     *
     * @return list<string>
     */
    public function guideNotes(): array {
        return [
            'List address books first; only your own and shared address books are exposed. The '
                . 'system user directory is excluded.',
            'Search one address book to obtain contact object URIs, then read a contact before '
                . 'editing it. Unknown vCard properties are preserved; the preview includes every '
                . 'property before and after.',
            'Writes first show a plan without changing anything. Wait for an explicit yes, repeat '
                . 'with confirm=true, and acknowledge another owner with confirm_shared=true. The '
                . 'optional etag prevents overwriting a newer edit.',
            'Contact deletion is PERMANENT in Nextcloud: there is no contact trash. After '
                . 'confirmation, a verified full .vcf copy is saved in /MCP backups/Contacts before '
                . 'native CardDAV DELETE. Backup failure prevents deletion; the result gives the backup '
                . 'path. Import the .vcf through Contacts to recover it.',
            'These tools are hidden when the Contacts app is disabled. Writing grants start disabled.',
        ];
    }

    /**
     * Builds a real before/after plan without changing the DAV collection.
     *
     * @param string $name registered tool name
     * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
     * @param string $userId authenticated user UID
     * @return array<string, mixed>
     * @throws ToolFailure when access, concurrency or recovery checks refuse the operation
     * @throws InvalidArgumentException when the tool or supplied fields are invalid
     */
    public function preview(string $name, array $arguments, string $userId): array {
        return $this->prepare($name, $arguments, $userId)['plan'];
    }

    /**
     * Reads data or executes a prepared write after confirmation and access checks.
     *
     * @param string $name registered tool name
     * @param array<string, mixed> $arguments validated tool arguments; omitted editable fields remain unchanged
     * @param string $userId authenticated user UID
     * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
     * @throws ToolFailure when access, concurrency or recovery checks refuse the operation
     * @throws InvalidArgumentException when the tool or supplied fields are invalid
     * @throws RuntimeException when the native write or its read-back verification fails
     */
    public function call(string $name, array $arguments, string $userId): array {
        if ($name === 'contacts_list_addressbooks') {
            return ToolResult::json(array_map(static fn ($book) => (array) $book, $this->access->visible($userId)));
        }
        if ($name === 'contacts_search_contacts' || $name === 'contacts_read_contact') {
            $book = $this->access->resolve($userId, $arguments['addressbook']);
            if ($name === 'contacts_read_contact') {
                $row = $this->row($book->id, $arguments['uri']);
                return ToolResult::json(
                    $this->cards->item($this->cards->parse($row['data'])) + [
                        'uri' => $row['uri'],
                        'etag' => $row['etag'],
                        'addressbook' => $book->path,
                    ]
                );
            }
            $query = mb_strtolower($arguments['query'] ?? '');
            $limit = $arguments['limit'] ?? 50;
            $items = [];
            $more = false;
            $offset = $arguments['offset'] ?? 0;
            $matched = 0;
            foreach ($this->store->cards($book->id) as $row) {
                try {
                    $item = $this->cards->item($this->cards->parse($row['data']));
                } catch (ToolFailure) {
                    continue;
                }
                $search = implode(
                    ' ',
                    [
                        $item['name'],
                        $item['organization'],
                        ...$item['emails'],
                        ...$item['phones'],
                    ]
                );
                if ($query !== '' && !str_contains(mb_strtolower($search), $query)) {
                    continue;
                }
                if ($matched++ < $offset) {
                    continue;
                }
                if (count($items) >= $limit) {
                    $more = true;
                    break;
                }
                unset($item['vcard'], $item['properties']);
                $items[] = $item + ['uri' => $row['uri'], 'etag' => $row['etag'], 'addressbook' => $book->path];
            }
            return ToolResult::json(
                [
                    'contacts' => $items,
                    'hasMore' => $more,
                    'nextOffset' => $more ? $offset + count($items) : null,
                ]
            );
        }
        $prepared = $this->prepare($name, $arguments, $userId);
        if (!WriteGate::confirmed($arguments)) {
            return ToolResult::json($prepared['plan']);
        }
        if ($prepared['plan']['shared'] !== [] && ($arguments['confirm_shared'] ?? false) !== true) {
            throw new ToolFailure(
                Translator::t('Confirm the change to the shared collection with confirm_shared=true.')
            );
        }
        $book = $prepared['book'];
        $card = $prepared['card'];
        $row = $prepared['row'];
        if ($name === 'contacts_delete_contact') {
            $backupPath = $this->backup->save(
                $userId,
                $book->name,
                $prepared['plan']['before']['name'] ?: $prepared['plan']['before']['uid'],
                $row['data']
            );
            // Check ACL/ownership and ETag again after the backup; If-Match covers changes after this check.
            $currentBook = $this->access->resolve($userId, $arguments['addressbook'], true);
            if ($currentBook->id !== $book->id || $currentBook->ownerPrincipal !== $book->ownerPrincipal) {
                throw new ToolFailure(CommonMessages::conflict());
            }
            $latest = $this->row($book->id, $row['uri']);
            if ($latest['etag'] !== $row['etag']) {
                throw new ToolFailure(CommonMessages::conflict());
            }
            $this->dav->delete($userId, $book->uri, $row['uri'], $row['etag']);
            return ToolResult::json(
                [
                    'uri' => $row['uri'],
                    'addressbook' => $book->path,
                    'deleted' => true,
                    'permanent' => true,
                    'recoverable' => false,
                    'backupPath' => $backupPath,
                    'message' => Translator::t(
                        'The contact was permanently deleted. Import the saved vCard through Contacts to recover it.'
                    ),
                ]
            );
        }
        $result = $row === null
            ? $this->dav->put($userId, $book->uri, $prepared['uri'], $card->serialize())
            : $this->dav->update($userId, $book->uri, $row['uri'], $row['etag'], $card->serialize());
        // Read back because native plugins may normalize the card and omit the response ETag.
        $written = $this->row($book->id, $prepared['uri']);
        return ToolResult::json(
            $this->cards->item($this->cards->parse($written['data'])) + [
                'uri' => $written['uri'],
                'etag' => $written['etag'],
                'addressbook' => $book->path,
                'status' => $result->status,
            ]
        );
    }

    /**
     * Prepares contact access, ETag checks and a complete plan without mutating DAV or Files.
     *
     * @param string $name write tool name
     * @param array<string, mixed> $arguments validated tool arguments
     * @param string $userId authenticated UID
     * @return array{book:Calendar, row:?array, card:?VCard, uri:string, plan:array<string, mixed>}
     * @throws ToolFailure when the book, object or ETag cannot be used safely
     * @throws InvalidArgumentException when the tool or edit fields are invalid
     */
    private function prepare(string $name, array $arguments, string $userId): array {
        if (!in_array(
            $name,
            [
                'contacts_create_contact',
                'contacts_edit_contact',
                'contacts_delete_contact',
            ],
            true
        )) {
            throw new InvalidArgumentException('Unknown tool');
        }
        if ($name === 'contacts_edit_contact' && array_intersect(
            array_keys($arguments),
            ['name', 'organization', 'title', 'note', 'url', 'emails', 'phones']
        ) === []) {
            throw new InvalidArgumentException(Translator::t('Provide at least one field to change.'));
        }
        $book = $this->access->resolve($userId, $arguments['addressbook'], true);
        $row = null;
        $before = null;
        if ($name !== 'contacts_create_contact') {
            $row = $this->row($book->id, $arguments['uri']);
            if (isset($arguments['etag']) && trim($arguments['etag'], '"') !== trim($row['etag'], '"')) {
                throw new ToolFailure(CommonMessages::conflict());
            }
            $old = $this->cards->parse($row['data']);
            $before = $this->cards->item($old);
            $before['vcard'] = $row['data'];
            $card = $name === 'contacts_delete_contact' ? null : $this->cards->patch($old, $arguments);
        } else {
            $card = $this->cards->create($arguments);
        }
        $uri = $row['uri'] ?? (string) $card->UID . '.vcf';
        $notice = $this->guard->confirm($book, $userId, []);
        $shared = $notice === null ? [] : [json_decode($notice['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR)];
        $plan = [
            'requiresConfirmation' => true,
            'message' => Translator::t('Nothing was changed. Show this plan and ask for explicit confirmation.'),
            'action' => $name,
            'addressbook' => (array) $book,
            'etag' => $row['etag'] ?? null,
            'before' => $before,
            'after' => $card === null ? null : $this->cards->item($card),
            'shared' => $shared,
            'recoverable' => false,
            'consequence' => Translator::t(
                'Contacts have no native trash or edit history; review the changes carefully.'
            ),
        ];
        if ($name === 'contacts_delete_contact') {
            $plan['permanent'] = true;
            $plan['warning'] = Translator::t('PERMANENT DELETION: Nextcloud has no contact trash bin.');
            $plan['consequence'] = $plan['warning'];
            $plan['backup'] = [
                'required' => true,
                'directory' => $this->backup->directory($book->name),
                'format' => 'vcf',
                'message' => Translator::t(
                    'A verified vCard copy will be saved before deletion. If the backup fails, nothing will be deleted.'
                ),
            ];
        }
        return compact('book', 'row', 'card', 'uri', 'plan');
    }

    /**
     * Reads a contact only after rejecting object URI syntax that could address another node.
     *
     * @param int $bookId authorized address-book ID
     * @param string $uri contact object URI
     * @return array{uri:string, etag:string, data:string}
     * @throws ToolFailure when the URI is malformed or the contact no longer exists
     */
    private function row(int $bookId, string $uri): array {
        // Reject path syntax even for direct callers; no foreign node can be addressed through the port.
        if ($uri === '' || $uri === '.' || $uri === '..' || strpbrk($uri, "/\\\x00") !== false) {
            throw new ToolFailure(CommonMessages::notFound());
        }
        return $this->store->card($bookId, $uri) ?? throw new ToolFailure(CommonMessages::notFound());
    }
}
