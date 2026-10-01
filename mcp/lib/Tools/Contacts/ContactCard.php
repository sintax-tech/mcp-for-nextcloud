<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ToolFailure;
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCard;

/** Patches the parsed card, preserving untouched properties, parameters, groups and version. */
class ContactCard {
    private const FIELDS=['name'=>'FN','organization'=>'ORG','title'=>'TITLE','note'=>'NOTE','url'=>'URL'];
    public function parse(string $data): VCard {
        try { $card=Reader::read($data); } catch(\Throwable $error) { throw new ToolFailure(Translator::t('The contact could not be read safely.'),0,$error); }
        if (!$card instanceof VCard || !isset($card->UID) || !isset($card->FN)) { throw new ToolFailure(Translator::t('The contact could not be read safely.')); }
        return $card;
    }
    public function create(array $arguments): VCard {
        $card=new VCard(['VERSION'=>'3.0','UID'=>bin2hex(random_bytes(16)),'FN'=>$arguments['name']]);
        return $this->patch($card,$arguments);
    }
    public function patch(VCard $original,array $arguments): VCard {
        $card=clone $original;
        foreach(self::FIELDS as $argument=>$property) {
            if (!array_key_exists($argument,$arguments)) { continue; }
            // Modifying the first property preserves its parameters/group; other occurrences stay intact.
            if ($property==='ORG' && isset($card->ORG)) {
                $parts=$card->ORG->getParts();
                $parts[0]=$arguments[$argument];
                $card->ORG->setParts($parts);
            } elseif (isset($card->$property)) { $card->$property->setValue($arguments[$argument]); }
            else { $card->add($property,$arguments[$argument]); }
        }
        foreach(['emails'=>'EMAIL','phones'=>'TEL'] as $argument=>$property) {
            if (!array_key_exists($argument,$arguments)) { continue; }
            $old=array_values($card->select($property));
            foreach($arguments[$argument] as $index=>$value) {
                if(isset($old[$index])) { $old[$index]->setValue($value); }
                else { $card->add($property,$value); }
            }
            // Array replaces only this understood property; labels/custom properties are retained.
            foreach(array_slice($old,count($arguments[$argument])) as $removed) { $card->remove($removed); }
        }
        return $card;
    }
    public function item(VCard $card): array {
        $out=['uid'=>(string)$card->UID];
        foreach(self::FIELDS as $argument=>$property) { $out[$argument]=isset($card->$property) ? (string)$card->$property : ''; }
        if (isset($card->ORG)) { $out['organization']=$card->ORG->getParts()[0] ?? ''; }
        foreach(['emails'=>'EMAIL','phones'=>'TEL'] as $argument=>$property) { $out[$argument]=array_values(array_map(static fn($p)=>(string)$p,$card->select($property))); }
        $out['properties']=[];
        foreach($card->children() as $property) {
            $out['properties'][]=['name'=>$property->name,'group'=>$property->group,'value'=>$property->getValue(),'serialized'=>$property->serialize()];
        }
        $out['vcard']=$card->serialize();
        return $out;
    }
}
