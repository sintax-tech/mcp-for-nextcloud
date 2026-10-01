<?php
declare(strict_types=1);
namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\Calendar\{CapturingSapi,DavResult,Session};
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Sabre\DAV\{Server,Exception as DavException};
use Sabre\DAV\Auth\Plugin as AuthPlugin;
use Sabre\HTTP\{Request,Response};

/** Native CardDAV request dispatch, with fresh plugin state and the acting session pinned. */
class EmbeddedCardDavDispatcher implements ContactDav {
    public function __construct(private \Closure $serverFactory,private Session $session,private IAppConfig $config,private LoggerInterface $logger) {}
    public function put(string $userId,string $bookUri,string $uri,string $vcard): DavResult {
        return $this->dispatch('PUT',$userId,$bookUri,$uri,['If-None-Match'=>'*'],$vcard,201);
    }
    public function update(string $userId,string $bookUri,string $uri,string $etag,string $vcard): DavResult {
        return $this->dispatch('PUT',$userId,$bookUri,$uri,['If-Match'=>$etag],$vcard,204);
    }
    public function delete(string $userId,string $bookUri,string $uri,string $etag): DavResult {
        return $this->dispatch('DELETE',$userId,$bookUri,$uri,['If-Match'=>$etag],null,204);
    }
    private function dispatch(string $method,string $userId,string $bookUri,string $uri,array $headers,?string $body,int $status): DavResult {
        if ($this->session->uid()!==$userId) { throw new \RuntimeException('CardDAV session mismatch'); }
        foreach([$userId,$bookUri,$uri] as $segment) {
            if ($segment==='' || $segment==='.' || $segment==='..' || preg_match('/[\x00-\x1f\x7f\/\\\\]/',$segment)) { throw new ToolFailure(\OCA\Mcp\Tools\Common\CommonMessages::notFound()); }
        }
        if($body!==null && strlen($body)>$this->config->getValueInt('dav','card_size_limit',5242880)) { throw new ToolFailure(Translator::t('The contact exceeds the server vCard size limit.')); }
        CapturingSapi::reset();
        $server=($this->serverFactory)();
        $auth=$server->getPlugin('auth');
        if (!$auth instanceof AuthPlugin || !method_exists($auth,'setCurrentPrincipal')) { throw new \RuntimeException('CardDAV principal cannot be pinned'); }
        $principal='principals/users/'.$userId;
        $auth->setCurrentPrincipal($principal);
        if($auth->getCurrentPrincipal()!==$principal) { throw new \RuntimeException('CardDAV principal mismatch'); }
        $base=$server->getBaseUri();
        $path='addressbooks/users/'.rawurlencode($userId).'/'.rawurlencode($bookUri).'/'.rawurlencode($uri);
        if($body!==null) { $headers+=['Content-Type'=>'text/vcard; charset=utf-8','Content-Length'=>(string)strlen($body)]; }
        $request=new Request($method,$base.$path,$headers,$body); $request->setBaseUrl($base);
        $response=new Response();
        $server->httpRequest=$request; $server->httpResponse=$response; $server->sapi=new CapturingSapi();
        try { $server->invokeMethod($request,$response,false); }
        catch(DavException $error) {
            $this->logger->error('MCP CardDAV write refused',['app'=>'mcp','exception_class'=>$error::class,'http_code'=>$error->getHTTPCode()]);
            $message=match($error->getHTTPCode()) {
                403=>\OCA\Mcp\Tools\Common\CommonMessages::forbidden(),404=>\OCA\Mcp\Tools\Common\CommonMessages::notFound(),
                409,412=>\OCA\Mcp\Tools\Common\CommonMessages::conflict(),400,415=>Translator::t('The server refused the contact data.'),
                default=>Translator::t('Unexpected error while accessing Nextcloud.'),
            };
            throw new ToolFailure($message,$error->getHTTPCode(),$error);
        } catch(\Throwable $error) {
            $this->logger->error('MCP CardDAV dispatch failed',['app'=>'mcp','exception_class'=>$error::class]);
            throw new \RuntimeException('CardDAV dispatch failed',0,$error);
        }
        $actual=$response->getStatus() ?? CapturingSapi::$lastStatus;
        if($actual!==$status) { throw new \RuntimeException('Unexpected CardDAV status'); }
        return new DavResult($actual,$response->getHeader('ETag'));
    }
}
