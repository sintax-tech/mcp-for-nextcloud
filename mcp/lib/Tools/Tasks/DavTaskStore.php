<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Tasks;
use OCA\DAV\CalDAV\CalDavBackend;
use Psr\Container\ContainerInterface;
class DavTaskStore implements TaskStore {
    public function __construct(private ContainerInterface $container) {}
    public function uris(int $calendarId): array {
        // Sabre component query includes undated tasks; an event time window would miss them.
        return array_values($this->container->get(CalDavBackend::class)->calendarQuery($calendarId,[
            'name'=>'VCALENDAR','is-not-defined'=>false,'time-range'=>null,'prop-filters'=>[],
            'comp-filters'=>[['name'=>'VTODO','is-not-defined'=>false,'time-range'=>null,'prop-filters'=>[],'comp-filters'=>[]]],
        ]));
    }
}
