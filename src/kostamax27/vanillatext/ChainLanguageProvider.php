<?php

declare(strict_types=1);

namespace kostamax27\vanillatext;

use function array_key_exists;
use function array_reverse;
use function array_values;
use function in_array;

/**
 * Merges several providers into one, earlier providers overriding later ones key by key. A partial ru_RU.lang holding
 * two renamed items therefore leaves the rest of the locale intact instead of replacing it.
 */
final class ChainLanguageProvider implements LanguageProvider{
	/** @var list<LanguageProvider> */
	readonly public array $providers;

	/** @var array<string, Language|null> */
	private array $cache = [];

	/**
	 * @param LanguageProvider ...$providers highest precedence first
	 */
	public function __construct(LanguageProvider ...$providers){
		$this->providers = array_values($providers);
	}

	public function get(string $locale) : ?Language{
		if(array_key_exists($locale, $this->cache)){
			return $this->cache[$locale];
		}
		$result = null;
		foreach(array_reverse($this->providers) as $provider){
			$language = $provider->get($locale);
			if($language !== null){
				$result = $result?->merge($language) ?? $language;
			}
		}
		return $this->cache[$locale] = $result;
	}

	public function locales() : array{
		$locales = [];
		foreach($this->providers as $provider){
			foreach($provider->locales() as $locale){
				if(!in_array($locale, $locales, true)){
					$locales[] = $locale;
				}
			}
		}
		return $locales;
	}
}
