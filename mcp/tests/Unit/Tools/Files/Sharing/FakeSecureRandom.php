<?php
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Files\Sharing;

use OCP\Security\ISecureRandom;

/**
 * ISecureRandom that draws from the requested alphabet with a counter, so passwords are reproducible but differ on each
 * call. With $biased, every draw takes the first character, to prove the fallback forces each kind of character.
 */
final class FakeSecureRandom implements ISecureRandom {
    private int $counter = 0;

    public function __construct(private bool $biased = false) {}

    public function generate(int $length, string $characters = ISecureRandom::CHAR_ALPHANUMERIC): string {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $characters[$this->biased ? 0 : ($this->counter++ * 7) % strlen($characters)];
        }
        return $out;
    }
}
