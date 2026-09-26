<?php

declare(strict_types=1);

namespace MageOS\AiBase\Model\Chat;

use MageOS\AiBase\Api\Data\ChatMessageInterface;
use MageOS\AiBase\Api\Data\MessageRole;
use MageOS\AiBase\Api\Data\ReasoningInterface;
use MageOS\AiBase\Api\Data\ToolCallInterface;

class ChatMessage implements ChatMessageInterface
{
    /**
     * @var list<ToolCallInterface>
     */
    private readonly array $toolCalls;

    /**
     * @var list<ReasoningInterface>
     */
    private readonly array $reasoning;

    /**
     * @param MessageRole $role
     * @param string $content
     * @param array<mixed> $toolCalls Assistant turns only, validated below
     * @param ToolCallInterface|null $answeredToolCall Tool-result turns only
     * @param array<mixed> $reasoning Assistant turns only, validated below
     */
    public function __construct(
        private readonly MessageRole $role,
        private readonly string $content = '',
        array $toolCalls = [],
        private readonly ?ToolCallInterface $answeredToolCall = null,
        array $reasoning = [],
    ) {
        $this->toolCalls = $this->assertInstances($toolCalls, ToolCallInterface::class);
        $this->reasoning = $this->assertInstances($reasoning, ReasoningInterface::class);
    }

    /**
     * Reject a caller-supplied entry that does not implement the expected type.
     *
     * The array is built by the consuming module, so this is the only place the promise made
     * by the property type is actually enforced.
     *
     * @template T of object
     * @param array<mixed> $items
     * @param class-string<T> $expected
     * @return list<T>
     */
    private function assertInstances(array $items, string $expected): array
    {
        $validated = [];
        foreach ($items as $item) {
            if (!$item instanceof $expected) {
                throw new \InvalidArgumentException(sprintf(
                    'Every entry must implement %s, got %s',
                    $expected,
                    get_debug_type($item),
                ));
            }
            $validated[] = $item;
        }

        return $validated;
    }

    /**
     * @inheritdoc
     */
    public function getRole(): MessageRole
    {
        return $this->role;
    }

    /**
     * @inheritdoc
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @inheritdoc
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * @inheritdoc
     */
    public function getReasoning(): array
    {
        return $this->reasoning;
    }

    /**
     * @inheritdoc
     */
    public function getAnsweredToolCall(): ?ToolCallInterface
    {
        return $this->answeredToolCall;
    }

    /**
     * @inheritdoc
     */
    public function getToolCallId(): ?string
    {
        return $this->answeredToolCall?->getId();
    }
}
