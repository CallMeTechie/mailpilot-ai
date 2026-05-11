<?php
declare(strict_types=1);

namespace MailPilot\Util;

final class Utf8
{
	/**
	 * Return a guaranteed-valid UTF-8 string.
	 *
	 * Graph delivers HTML bodies that occasionally contain stray bytes from
	 * upstream MTAs (Windows-1252 punctuation, ISO-8859-1 letters, NUL bytes
	 * in PDF fragments). Storing those is harmless until we hand them to
	 * json_encode — which then refuses the whole batch with JSON_ERROR_UTF8
	 * and produces an empty payload. Run this on anything that originates
	 * outside our own database before it leaves the repository layer.
	 */
	public static function sanitize(string $s): string
	{
		if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
			return $s;
		}
		$converted = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
		return is_string($converted) ? $converted : '';
	}
}
