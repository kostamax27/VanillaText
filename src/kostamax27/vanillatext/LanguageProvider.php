<?php

declare(strict_types=1);

namespace kostamax27\vanillatext;

interface LanguageProvider{
	/**
	 * Returns the language for the given locale, or null if the locale is unknown to this provider.
	 *
	 * @param string $locale locale code (e.g. "en_US")
	 */
	public function get(string $locale) : ?Language;

	/**
	 * @return list<string> locale codes known to this provider
	 */
	public function locales() : array;
}
