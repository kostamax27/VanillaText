<?php

declare(strict_types=1);

namespace kostamax27\vanillatext;

use function array_merge;
use function explode;
use function preg_match;
use function rtrim;
use function str_starts_with;
use function strpos;
use function substr;

final class Language{
	/**
	 * Locale codes as vanilla resource packs and Player::getLocale() spell them (en_US, pt_BR, zh_TW, ...).
	 */
	public const CODE_PATTERN = '/^[A-Za-z]{2,3}_[A-Za-z]{2,4}$/';

	public static function isValidCode(string $locale) : bool{
		return preg_match(self::CODE_PATTERN, $locale) === 1;
	}

	/**
	 * Parses the contents of a Minecraft: Bedrock Edition language file (resource_pack/texts/*.lang). The format is
	 * one "key=value" pair per line, "##" at the start of a line marks a comment, and a value may carry a trailing
	 * inline comment delimited by "\t#".
	 *
	 * pocketmine\lang\Language and Config::PROPERTIES cannot be used in its place - the former parses INI, and the
	 * latter coerces values ("true", "1.0"), trims them, and drops keys containing characters outside [a-zA-Z0-9\-_.].
	 *
	 * @param string $locale locale code (e.g. "en_US")
	 * @param string $contents raw contents of the .lang file
	 */
	public static function parse(string $locale, string $contents) : self{
		$translations = [];
		foreach(explode("\n", $contents) as $line){
			$line = rtrim($line, "\r");
			if($line === "" || str_starts_with($line, "##")){
				continue;
			}
			$separator = strpos($line, "=");
			if($separator === false){
				continue;
			}
			$value = substr($line, $separator + 1);
			$comment = strpos($value, "\t#");
			if($comment !== false){
				$value = rtrim(substr($value, 0, $comment));
			}
			$translations[substr($line, 0, $separator)] = $value;
		}
		return new self($locale, $translations);
	}

	/**
	 * @param string $locale locale code (e.g. "en_US")
	 * @param array<string, string> $translations mapping translation keys to translated values
	 */
	public function __construct(
		readonly public string $locale,
		readonly public array $translations
	){}

	public function has(string $key) : bool{
		return isset($this->translations[$key]);
	}

	public function get(string $key) : ?string{
		return $this->translations[$key] ?? null;
	}

	/**
	 * Returns a copy of this language with the given translations applied over its own, key by key.
	 */
	public function merge(self $overrides) : self{
		return new self($this->locale, array_merge($this->translations, $overrides->translations));
	}
}
