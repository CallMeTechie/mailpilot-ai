<?php
declare(strict_types=1);

namespace MailPilot\Claude;

use RuntimeException;

/**
 * AWS Bedrock provider for Claude models.
 *
 * Use when you need EU data residency — point at eu-central-1 (Frankfurt)
 * and payloads never leave the EU. Bedrock access requires:
 *   1. AWS account with Bedrock enabled
 *   2. Model access requested for Anthropic Claude in the Bedrock console
 *   3. IAM user/role with bedrock:InvokeModel permission
 *
 * Payload is largely compatible with the Anthropic Messages API, with two
 * caveats handled here:
 *   - The "model" field in the payload is removed and encoded in the URL
 *     path as a Bedrock model ID (e.g. eu.anthropic.claude-haiku-4-5-v1:0).
 *   - An "anthropic_version" field is REQUIRED in the JSON body
 *     (not a header like the direct API).
 *
 * Signing: AWS Signature Version 4, implemented inline to avoid pulling in
 * the full aws-sdk-php (which is heavy and brings 30+ deps).
 */
final class BedrockClient implements ClaudeProvider
{
	private const MAX_RETRIES = 3;
	private const SERVICE = 'bedrock';
	private const BEDROCK_ANTHROPIC_VERSION = 'bedrock-2023-05-31';

	/**
	 * @param array<string,string> $modelMap  logical Anthropic model → Bedrock model ID
	 *                                        e.g. "claude-haiku-4-5-20251001" => "eu.anthropic.claude-haiku-4-5-v1:0"
	 */
	public function __construct(
		private readonly string $accessKey,
		private readonly string $secretKey,
		private readonly ?string $sessionToken,  // null unless using STS / assumed role
		private readonly string $region,          // "eu-central-1" for Frankfurt
		private readonly array  $modelMap,
		private readonly int    $timeout,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
		if ($this->accessKey === '' || $this->secretKey === '') {
			throw new RuntimeException('Bedrock credentials missing');
		}
		if ($this->region === '') {
			throw new RuntimeException('Bedrock region missing');
		}
	}

	public function messages(array $payload): array
	{
		$logicalModel = (string)($payload['model'] ?? '');
		$bedrockModelId = $this->modelMap[$logicalModel] ?? null;
		if ($bedrockModelId === null) {
			throw new RuntimeException("No Bedrock mapping for model: {$logicalModel}");
		}

		// Bedrock body: same as Anthropic, but:
		//  - remove "model" (now in URL)
		//  - add "anthropic_version" (bedrock-specific value)
		unset($payload['model']);
		$payload['anthropic_version'] = self::BEDROCK_ANTHROPIC_VERSION;

		$body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

		$host = "bedrock-runtime.{$this->region}.amazonaws.com";
		$path = '/model/' . rawurlencode($bedrockModelId) . '/invoke';
		$url  = "https://{$host}{$path}";

		$attempt = 0;
		while (true) {
			$attempt++;
			$start = microtime(true);

			$headers = $this->signRequest('POST', $host, $path, $body);

			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $this->timeout,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_HTTPHEADER     => $headers,
			]);

			$response = curl_exec($ch);
			$status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$err      = curl_error($ch);
			curl_close($ch);

			$durMs = (int)((microtime(true) - $start) * 1000);

			$this->logger->info('claude.bedrock.call', [
				'attempt'     => $attempt,
				'status'      => $status,
				'model'       => $logicalModel,
				'bedrock_id'  => $bedrockModelId,
				'region'      => $this->region,
				'duration_ms' => $durMs,
				// Never log body
			]);

			if ($status >= 200 && $status < 300 && is_string($response)) {
				return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
			}

			$retriable = ($status === 429 || $status >= 500) || $err !== '';
			if ($retriable && $attempt < self::MAX_RETRIES) {
				usleep((int)(2 ** $attempt * 500_000));
				continue;
			}

			throw new RuntimeException(sprintf(
				'Bedrock call failed: status=%d attempt=%d curlErr=%s',
				$status, $attempt, $err ?: 'none',
			));
		}
	}

	// ---------------------------------------------------------------------
	// AWS Signature Version 4
	// Reference: https://docs.aws.amazon.com/general/latest/gr/sigv4_signing.html
	// ---------------------------------------------------------------------

	/**
	 * Returns list of HTTP headers incl. Authorization + x-amz-date.
	 *
	 * @return list<string>
	 */
	private function signRequest(string $method, string $host, string $path, string $payload): array
	{
		$amzDate   = gmdate('Ymd\THis\Z');
		$dateStamp = gmdate('Ymd');

		$payloadHash = hash('sha256', $payload);

		$canonicalHeaders = [
			'content-type' => 'application/json',
			'host'         => $host,
			'x-amz-date'   => $amzDate,
		];
		if ($this->sessionToken !== null && $this->sessionToken !== '') {
			$canonicalHeaders['x-amz-security-token'] = $this->sessionToken;
		}
		ksort($canonicalHeaders);

		$canonicalHeadersStr = '';
		$signedHeaders = [];
		foreach ($canonicalHeaders as $k => $v) {
			$canonicalHeadersStr .= $k . ':' . trim($v) . "\n";
			$signedHeaders[] = $k;
		}
		$signedHeadersStr = implode(';', $signedHeaders);

		// --- Canonical request ---
		$canonicalRequest = implode("\n", [
			strtoupper($method),
			$path,
			'',                      // empty canonical querystring (POST body)
			$canonicalHeadersStr,
			$signedHeadersStr,
			$payloadHash,
		]);

		// --- String to sign ---
		$algorithm       = 'AWS4-HMAC-SHA256';
		$credentialScope = "{$dateStamp}/{$this->region}/" . self::SERVICE . '/aws4_request';
		$stringToSign    = implode("\n", [
			$algorithm,
			$amzDate,
			$credentialScope,
			hash('sha256', $canonicalRequest),
		]);

		// --- Signing key derivation ---
		$kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
		$kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
		$kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
		$kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

		$signature = hash_hmac('sha256', $stringToSign, $kSigning);

		// --- Authorization header ---
		$authorization = sprintf(
			'%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$algorithm,
			$this->accessKey,
			$credentialScope,
			$signedHeadersStr,
			$signature,
		);

		$headers = [
			'Content-Type: application/json',
			'X-Amz-Date: ' . $amzDate,
			'Authorization: ' . $authorization,
		];
		if ($this->sessionToken !== null && $this->sessionToken !== '') {
			$headers[] = 'X-Amz-Security-Token: ' . $this->sessionToken;
		}
		return $headers;
	}
}
