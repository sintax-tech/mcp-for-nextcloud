<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Service\UserTimezone;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\IRootFolder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;

/** A verified, non-overwriting vCard copy in the acting user's Files before permanent CardDAV deletion. */
class ContactBackup {
    public function __construct(private IRootFolder $roots,private ITimeFactory $time,private IConfig $config,private ILockingProvider $locks) {}
    public function directory(string $bookName): string { return '/'.FileBackup::FOLDER.'/Contacts/'.$this->segment($bookName); }
    public function save(string $userId,string $bookName,string $name,string $data): string {
        $locked=false; $lockKey=null;
        try {
            $root=$this->roots->getUserFolder($userId);
            $folder=NodeAccess::ensureFolder($root,$this->directory($bookName));
            // Use a separate application mutex: locking the Files folder would block newFile itself.
            $lockKey='mcp-contact-backup/'.hash('sha256',$userId.'\0'.$folder->getPath());
            $this->locks->acquireLock($lockKey,ILockingProvider::LOCK_EXCLUSIVE); $locked=true;
            $stamp=$this->time->now()->setTimezone((new UserTimezone($this->config))->forUser($userId))->format('Ymd-His');
            $base=$this->segment($name).'.'.$stamp.'-'.bin2hex(random_bytes(8));
            $filename=$base.'.vcf';
            for($i=2;$folder->nodeExists($filename);$i++) { $filename=$base.'-'.$i.'.vcf'; }
            $copy=$folder->newFile($filename,$data);
            // Read-back equality proves that every original property was captured, not merely its size.
            if($copy->getContent()!==$data) { throw new \RuntimeException('Contact backup verification failed'); }
            $relative=$root->getRelativePath($copy->getPath());
            if($relative===null || $relative==='') { throw new \RuntimeException('Contact backup path unavailable'); }
            return '/'.ltrim($relative,'/');
        } catch(\Throwable $e) {
            throw new ToolFailure(Translator::t('The contact backup could not be saved and verified; the contact was not deleted.'),0,$e);
        } finally {
            if($locked) { $this->locks->releaseLock($lockKey,ILockingProvider::LOCK_EXCLUSIVE); }
        }
    }
    private function segment(string $name): string {
        $value=preg_replace('/[\x00-\x1f\x7f\\\\\/:*?"<>|]/u','_', $name) ?? '';
        $value=trim(mb_substr($value,0,100)," .\t\r\n");
        return $value==='' ? 'contact' : $value;
    }
}
