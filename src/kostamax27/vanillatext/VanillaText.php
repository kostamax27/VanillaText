<?php

declare(strict_types=1);

namespace kostamax27\vanillatext;

use pocketmine\block\Block;
use pocketmine\data\bedrock\block\BlockStateSerializeException;
use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\item\Item;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;

/**
 * Names vanilla items, blocks and entities in the locales of Minecraft: Bedrock Edition using the language files of a
 * vanilla resource pack.
 */
final class VanillaText{
	/**
	 * Creates a VanillaText reading .lang files from a directory. A copy of resource_pack/texts from
	 * {@link https://github.com/Mojang/bedrock-samples} can be placed there with tools/download-texts.php.
	 *
	 * @param string $directory directory containing .lang files (en_US.lang, ru_RU.lang, ...)
	 * @param string|null $fallback_locale locale to answer in for locales the directory does not cover, or null to
	 *                                     answer with null instead
	 */
	public static function fromDirectory(string $directory, ?string $fallback_locale = "en_US") : self{
		return new self(new DirectoryLanguageProvider($directory), TranslationKeyResolver::default(), $fallback_locale);
	}

	/**
	 * @param LanguageProvider $provider source of languages
	 * @param TranslationKeyResolver $resolver mapping of identifiers to translation keys
	 * @param string|null $fallback_locale locale to answer in for locales the provider does not know, or null to
	 *                                                answer with null instead - Player::getLocale() may report a locale no language file covers
	 */
	public function __construct(
		readonly public LanguageProvider $provider,
		readonly public TranslationKeyResolver $resolver,
		readonly public ?string $fallback_locale = null
	){}

	public function language(string $locale) : ?Language{
		$language = $this->provider->get($locale);
		if($language === null && $this->fallback_locale !== null && $this->fallback_locale !== $locale){
			$language = $this->provider->get($this->fallback_locale);
		}
		return $language;
	}

	/**
	 * @return list<string> locale codes available to this instance
	 */
	public function locales() : array{
		return $this->provider->locales();
	}

	/**
	 * @param string $key translation key (e.g. "item.apple.name")
	 * @param string $locale locale code (e.g. "en_US")
	 */
	public function translate(string $key, string $locale) : ?string{
		return $this->language($locale)?->get($key);
	}

	public function itemName(Item $item, string $locale) : ?string{
		$language = $this->language($locale);
		if($language === null){
			return null;
		}
		if($item->isNull()){
			$key = $this->resolver->resolve("minecraft:air", $language);
			return $key !== null ? $language->get($key) : null;
		}
		try{
			$data = GlobalItemDataHandlers::getSerializer()->serializeType($item);
		}catch(ItemTypeSerializeException){
			return null;
		}
		$block = $data->getBlock();
		if($block !== null){
			$key = $this->resolver->resolve($block->getName(), $language, prefer_block: true);
			if($key !== null){
				return $language->get($key);
			}
		}
		$key = $this->resolver->resolve($data->getName(), $language);
		return $key !== null ? $language->get($key) : null;
	}

	public function blockName(Block $block, string $locale) : ?string{
		$language = $this->language($locale);
		if($language === null){
			return null;
		}
		try{
			$data = GlobalBlockStateHandlers::getSerializer()->serialize($block->getStateId());
		}catch(BlockStateSerializeException){
			return null;
		}
		$key = $this->resolver->resolve($data->getName(), $language, prefer_block: true);
		return $key !== null ? $language->get($key) : null;
	}

	/**
	 * @param string $network_id namespaced entity network id (e.g. "minecraft:zombie") - vanilla keys drop the
	 *                           namespace ("entity.zombie.name"), custom entity keys keep it
	 */
	public function entityName(string $network_id, string $locale) : ?string{
		$language = $this->language($locale);
		if($language === null){
			return null;
		}
		return $language->get("entity." . TranslationKeyResolver::stripNamespace($network_id) . ".name")
			?? $language->get("entity.{$network_id}.name");
	}
}
