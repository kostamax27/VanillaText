<?php

declare(strict_types=1);

namespace kostamax27\vanillatext;

use function array_keys;

/**
 * Serves translations defined in code. Useful for overriding a handful of names without shipping a .lang file for
 * them (see ChainLanguageProvider).
 */
final class ArrayLanguageProvider implements LanguageProvider{
	/** @var array<string, Language> */
	readonly public array $languages;

	/**
	 * @param array<string, array<string, string>> $translations mapping locale codes to (translation key =>
	 *                                                           translated value), e.g. ["ru_RU" => ["item.apple.name" => "Яблочко"]]
	 */
	public function __construct(array $translations){
		$languages = [];
		foreach($translations as $locale => $values){
			$languages[$locale] = new Language($locale, $values);
		}
		$this->languages = $languages;
	}

	public function get(string $locale) : ?Language{
		return $this->languages[$locale] ?? null;
	}

	public function locales() : array{
		return array_keys($this->languages);
	}
}
