<?php

declare(strict_types=1);

namespace MageOS\AiBase\Test\Integration\Model\Client;

use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiBase\Model\Client\OptionNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a caller's options actually become, per bundled provider, with the real wiring in place.
 *
 * The dialects live in `di.xml`, and reading that file back with XPath only ever proves the file
 * says what it says. It cannot see an argument another module overrode, a `<type>` name that no
 * longer matches the class, or an entry Magento's config merge dropped — each of which leaves the
 * caller's options passed through untouched, which is the silent mis-cap the normalizer exists to
 * prevent. Resolving the normalizer through the ObjectManager and reading the options out the
 * other end is the path a real request takes, so it sees all three.
 */
final class OptionNormalizerTest extends TestCase
{
    private OptionNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = Bootstrap::getObjectManager()->get(OptionNormalizer::class);
    }

    /**
     * The option names themselves, not merely that some name is present: a wrong one is a 400 from
     * the provider for an unknown body field, which no test of shape would notice.
     *
     * @param string $serviceCode
     * @param array<string,mixed> $expected
     */
    #[DataProvider('bundledProviderProvider')]
    public function test_spells_the_universal_options_the_way_each_provider_does(
        string $serviceCode,
        array $expected,
    ): void {
        $normalized = $this->normalizer->normalize($serviceCode, [
            'max_tokens' => 400,
            'temperature' => 0.5,
            'top_p' => 0.9,
        ]);

        self::assertSame($this->byKey($expected), $this->byKey($normalized));
    }

    /**
     * @return array<string, array{0: string, 1: array<string,mixed>}>
     */
    public static function bundledProviderProvider(): array
    {
        $chatCompletions = ['max_tokens' => 400, 'temperature' => 0.5, 'top_p' => 0.9];
        $responsesApi = ['max_output_tokens' => 400, 'temperature' => 0.5, 'top_p' => 0.9];

        return [
            'openai speaks the Responses API' => ['openai', $responsesApi],
            'azure speaks the Responses API' => ['azure', $responsesApi],
            'anthropic messages' => ['anthropic', $chatCompletions],
            'deepseek is chat-completions compatible' => ['deepseek', $chatCompletions],
            'huggingface is chat-completions compatible' => ['huggingface', $chatCompletions],
            'lmstudio is chat-completions compatible' => ['lmstudio', $chatCompletions],
            'openrouter is chat-completions compatible' => ['openrouter', $chatCompletions],
            'google nests under generationConfig in camelCase' => ['google', [
                'maxOutputTokens' => 400,
                'temperature' => 0.5,
                'topP' => 0.9,
            ]],
            'ollama renames the output limit' => ['ollama', [
                'num_predict' => 400,
                'temperature' => 0.5,
                'top_p' => 0.9,
            ]],
        ];
    }

    /**
     * Anthropic rejects a request that omits the output limit, so the wiring has to supply one, and
     * has to supply it as a number: `di.xml`'s number interpreter hands back the raw node value,
     * and Anthropic rejects `"max_tokens": "4096"` as not-an-integer.
     */
    public function test_supplies_the_output_limit_anthropic_requires_when_the_caller_omits_it(): void
    {
        $normalized = $this->normalizer->normalize('anthropic', ['temperature' => 0.2]);

        self::assertArrayHasKey('max_tokens', $normalized);
        self::assertIsInt($normalized['max_tokens']);
        self::assertGreaterThan(0, $normalized['max_tokens']);
    }

    /**
     * A provider that wants stop sequences as an array gets one even from a caller who wrote a
     * single string, which is the shape all three of them document.
     *
     * @param string $serviceCode
     * @param string $expectedKey
     */
    #[DataProvider('listShapedStopProvider')]
    public function test_sends_a_single_stop_sequence_as_the_list_its_provider_expects(
        string $serviceCode,
        string $expectedKey,
    ): void {
        $normalized = $this->normalizer->normalize($serviceCode, ['stop' => 'END']);

        self::assertSame(['END'], $normalized[$expectedKey]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function listShapedStopProvider(): array
    {
        return [
            'anthropic' => ['anthropic', 'stop_sequences'],
            'google' => ['google', 'stopSequences'],
            'ollama' => ['ollama', 'stop'],
        ];
    }

    /**
     * The Responses API has no stop-sequence parameter at all. Dropping the option would generate
     * past the point the caller asked to stop at, and bill for it, so this is an error naming both.
     */
    public function test_refuses_an_option_the_target_provider_has_no_equivalent_for(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('stop');

        $this->normalizer->normalize('openai', ['stop' => 'END']);
    }

    /**
     * A caller who named the provider's own option addressed this provider deliberately and more
     * precisely than the neutral name did, so it must survive untouched.
     */
    public function test_leaves_an_option_the_caller_spelled_the_providers_own_way_alone(): void
    {
        $normalized = $this->normalizer->normalize('openai', ['max_output_tokens' => 99]);

        self::assertSame(['max_output_tokens' => 99], $normalized);
    }

    /**
     * A provider this module knows nothing about is better served raw than guessed at.
     */
    public function test_passes_the_options_of_an_unknown_service_through_untouched(): void
    {
        $options = ['max_tokens' => 400, 'something_only_this_provider_has' => true];

        self::assertSame($options, $this->normalizer->normalize('not_a_registered_service', $options));
    }

    /**
     * Anthropic has no "required" of its own; "any" is its name for "call some tool". Proves the
     * shipped wiring translates the value, not only the option name, for a real bundled provider.
     */
    public function test_translates_tool_choice_to_what_the_provider_calls_it(): void
    {
        $normalized = $this->normalizer->normalize('anthropic', ['tool_choice' => 'required']);

        self::assertSame(['type' => 'any'], $normalized['tool_choice']);
    }

    /**
     * Forcing one named tool is the one canonical value carrying a name the wiring has to place
     * into the provider's own shape, nested however deep that provider puts it.
     */
    public function test_forces_a_named_tool_through_the_shipped_wiring(): void
    {
        $normalized = $this->normalizer->normalize('openrouter', ['tool_choice' => ['tool' => 'classify_product']]);

        self::assertSame(
            ['type' => 'function', 'function' => ['name' => 'classify_product']],
            $normalized['tool_choice']
        );
    }

    /**
     * Ollama has no tool_choice equivalent at all in the shipped wiring: "auto" is every
     * provider's own default, so it stays a no-op rather than failing a call that only asked for
     * what Ollama already does anyway.
     */
    public function test_auto_tool_choice_is_a_no_op_on_a_provider_the_wiring_declares_no_translation_for(): void
    {
        $normalized = $this->normalizer->normalize('ollama', ['tool_choice' => 'auto']);

        self::assertArrayNotHasKey('tool_choice', $normalized);
    }

    /**
     * Unlike "auto", "required" asks Ollama for behaviour it cannot provide, so the shipped wiring
     * has to refuse it rather than silently drop it.
     */
    public function test_a_non_auto_tool_choice_is_refused_on_a_provider_the_wiring_declares_no_translation_for(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('tool_choice');

        $this->normalizer->normalize('ollama', ['tool_choice' => 'required']);
    }

    /**
     * Anthropic's reasoning_effort spreads across two top-level fields in the shipped wiring
     * (`thinking` and `output_config`), which a plain rename could never express.
     */
    public function test_reasoning_effort_expands_into_several_fields_through_the_shipped_wiring(): void
    {
        $normalized = $this->normalizer->normalize('anthropic', ['reasoning_effort' => 'low']);

        self::assertSame(['type' => 'adaptive'], $normalized['thinking']);
        self::assertSame(['effort' => 'low'], $normalized['output_config']);
    }

    /**
     * Every bundled dialect declares a reasoning_effort translation, each in that provider's own
     * vocabulary, proving the shipped wiring covers all five rather than only the ones tested above.
     *
     * @param string $serviceCode
     * @param array<string,mixed> $expected
     */
    #[DataProvider('reasoningEffortProvider')]
    public function test_reasoning_effort_is_translated_for_every_bundled_provider(
        string $serviceCode,
        array $expected,
    ): void {
        $normalized = $this->normalizer->normalize($serviceCode, ['reasoning_effort' => 'high']);

        foreach ($expected as $key => $value) {
            self::assertSame($value, $normalized[$key]);
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string,mixed>}>
     */
    public static function reasoningEffortProvider(): array
    {
        return [
            'openai speaks the Responses API' => ['openai', ['reasoning' => ['effort' => 'high']]],
            'openrouter is chat-completions compatible' => ['openrouter', ['reasoning_effort' => 'high']],
            'google has its own token-budget tiers' => ['google', ['thinkingConfig' => ['thinkingBudget' => 24576]]],
            'ollama has its own think field' => ['ollama', ['think' => 'high']],
        ];
    }

    /**
     * Options are rebuilt in canonical order as each one is rewritten, and no provider cares in
     * which order the body's fields arrive.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function byKey(array $options): array
    {
        ksort($options);

        return $options;
    }
}
