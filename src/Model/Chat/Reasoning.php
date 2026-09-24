<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\ReasoningInterface;

class Reasoning implements ReasoningInterface
{
    /**
     * @param string $text
     * @param string|null $signature Opaque; see {@see ReasoningInterface}
     */
    public function __construct(
        private readonly string $text = '',
        private readonly ?string $signature = null,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @inheritdoc
     */
    public function getSignature(): ?string
    {
        return $this->signature;
    }
}
