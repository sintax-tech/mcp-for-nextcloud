<?php
declare(strict_types=1);

namespace OCA\Mcp\Http;

use OCP\AppFramework\Http\Response;

class McpResponse extends Response {
    public function __construct(private string $body = '', int $status = 200) {
        parent::__construct();
        $this->setStatus($status);
        $this->addHeader('Content-Type', 'application/json; charset=utf-8');
        $this->addHeader('Cache-Control', 'no-store');
    }

    public function render(): string {
        return $this->body;
    }
}
