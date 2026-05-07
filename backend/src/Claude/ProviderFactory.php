<?php
declare(strict_types=1);

namespace MailPilot\Claude;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Builds a ClaudeProvider based on config['claude']['provider'].
 *
 *   provider = 'anthropic' (default) → AnthropicClient, api.anthropic.com
 *   provider = 'bedrock'             → BedrockClient, AWS Bedrock Runtime
 */
final class ProviderFactory
{
	/**
	 * @param array<string, mixed> $config  full app config
	 */
	public static function build(array $config, LoggerInterface $logger): ClaudeProvider
	{
		$claude = $config['claude'] ?? [];
		$provider = (string)($claude['provider'] ?? 'anthropic');

		return match ($provider) {
			'anthropic' => new AnthropicClient(
				(string)$claude['api_key'],
				(string)$claude['base_url'],
				(string)$claude['anthropic_version'],
				(int)$claude['timeout'],
				$logger,
			),
			'bedrock'   => new BedrockClient(
				(string)($claude['bedrock']['access_key']    ?? ''),
				(string)($claude['bedrock']['secret_key']    ?? ''),
				$claude['bedrock']['session_token']          ?? null,
				(string)($claude['bedrock']['region']        ?? 'eu-central-1'),
				(array)($claude['bedrock']['model_map']      ?? []),
				(int)$claude['timeout'],
				$logger,
			),
			default     => throw new RuntimeException("Unknown Claude provider: {$provider}"),
		};
	}
}
