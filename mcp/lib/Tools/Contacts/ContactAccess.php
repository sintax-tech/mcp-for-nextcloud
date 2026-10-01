<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\Calendar\Calendar;

/** Uses the Calendar collection descriptor for the identical ownership/shared guard metadata. */
class ContactAccess {
    private const PREFIX='/remote.php/dav/addressbooks/users/';
    public function __construct(private ContactStore $store) {}
    /** @return list<Calendar> Personal and shared books only; no system principal can pass this filter. */
    public function visible(string $userId): array {
        $out=[];
        foreach($this->store->books('principals/users/'.$userId) as $row) {
            $owner=$row['ownerPrincipal'];
            if (!str_starts_with($owner,'principals/users/')) { continue; }
            $ownerId=substr($owner,strlen('principals/users/'));
            $out[]=new Calendar($row['id'],$row['uri'],$row['displayName'],$ownerId,$owner,!$row['readOnly'],self::PREFIX.rawurlencode($userId).'/'.rawurlencode($row['uri']).'/');
        }
        return $out;
    }
    public function resolve(string $userId,string $path,bool $write=false): Calendar {
        foreach($this->visible($userId) as $book) {
            if (rtrim($book->path,'/') !== rtrim($path,'/')) { continue; }
            if ($write && !$book->writable) { throw new ToolFailure(\OCA\Mcp\Tools\Common\CommonMessages::forbidden()); }
            return $book;
        }
        throw new ToolFailure(\OCA\Mcp\Tools\Common\CommonMessages::notFound());
    }
}
