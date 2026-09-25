<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Client;

use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Model\Client\SymfonyAiClient;
use MageOS\AiBase\Model\Client\SymfonyAiClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * {@see \MageOS\AiBase\Model\Client\ClientFactory::buildClient()} never hands the generated
 * {@see SymfonyAiClientFactory} a BridgeRegistry, so the one the client asks for its dialect has
 * to come from the ObjectManager. Magento only resolves a class-typed argument when it is
 * required: an optional one silently gets its default, an empty registry here, which knows no
 * dialect and so never asks OpenAI or Azure to return their reasoning at all. Building the client
 * with `new` in a unit test hands it the right registry by hand and cannot catch that.
 */
final class ReasoningIncludeDiTest extends TestCase
{
    public function test_a_client_built_through_di_asks_the_responses_api_for_its_reasoning(): void
    {
        $factory = Bootstrap::getObjectManager()->get(SymfonyAiClientFactory::class);

        /** @var SymfonyAiClient $client */
        $client = $factory->create([
            'platform' => new \stdClass(),
            'model' => 'gpt-5',
            'serviceCode' => 'openai',
            'serviceId' => '_row_1',
        ]);

        self::assertSame(['reasoning.encrypted_content'], $client->normalizeOptions([])['include']);
    }

    public function test_a_client_built_through_di_leaves_include_alone_for_other_dialects(): void
    {
        $factory = Bootstrap::getObjectManager()->get(SymfonyAiClientFactory::class);

        /** @var SymfonyAiClient $client */
        $client = $factory->create([
            'platform' => new \stdClass(),
            'model' => 'claude-sonnet',
            'serviceCode' => 'anthropic',
            'serviceId' => '_row_1',
        ]);

        self::assertArrayNotHasKey('include', $client->normalizeOptions([]));
    }
}
