<?php

declare(strict_types=1);

namespace kostamax27\vanillatext;

use pocketmine\block\Block;
use pocketmine\data\bedrock\block\BlockStateSerializeException;
use pocketmine\data\bedrock\item\ItemTypeSerializeException;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\utils\Filesystem;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use function basename;
use function is_file;

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
	 * @param string $default_locale locale used when none is given or the given one is not covered by the directory
	 */
	public static function fromDirectory(string $directory, string $default_locale = "en_US") : self{
		return new self(new DirectoryLanguageProvider($directory), TranslationKeyResolver::default(), $default_locale);
	}

	/**
	 * Creates a VanillaText serving a single .lang file. The locale is taken from the file name (ru_RU.lang -> ru_RU)
	 * unless given explicitly, and becomes the default locale.
	 *
	 * @param string $path path to a .lang file
	 * @param string|null $locale locale code (e.g. "ru_RU"), or null to derive it from the file name
	 */
	public static function fromFile(string $path, ?string $locale = null) : self{
		is_file($path) || throw new \InvalidArgumentException("File {$path} does not exist");
		$locale ??= basename($path, ".lang");
		Language::isValidCode($locale) || throw new \InvalidArgumentException("Invalid locale code '{$locale}', expected a code such as en_US");
		$language = Language::parse($locale, Filesystem::fileGetContents($path));
		return new self(new ArrayLanguageProvider([$locale => $language->translations]), TranslationKeyResolver::default(), $locale);
	}

	/**
	 * @param LanguageProvider $provider source of languages
	 * @param TranslationKeyResolver $resolver mapping of identifiers to translation keys
	 * @param string $default_locale locale used when none is given or the given one is unknown to the provider
	 */
	public function __construct(
		readonly public LanguageProvider $provider,
		readonly public TranslationKeyResolver $resolver,
		readonly public string $default_locale
	){}

	/**
	 * Returns a copy of this instance answering in the given locale by default. The provider and its caches are
	 * shared, so the copy is cheap. Locales unknown to the provider leave the default untouched - the copy behaves
	 * exactly like the original, which is what a fallback is.
	 *
	 * @param string $locale locale code (e.g. "ru_RU")
	 */
	public function withLocale(string $locale) : self{
		if($locale === $this->default_locale || $this->provider->get($locale) === null){
			return $this;
		}
		return new self($this->provider, $this->resolver, $locale);
	}

	public function forPlayer(Player $player) : self{
		return $this->withLocale($player->getLocale());
	}

	/**
	 * @param string|null $locale locale code (e.g. "en_US"), or null for the default locale
	 */
	public function language(?string $locale = null) : ?Language{
		if($locale !== null && $locale !== $this->default_locale){
			$language = $this->provider->get($locale);
			if($language !== null){
				return $language;
			}
		}
		return $this->provider->get($this->default_locale);
	}

	/**
	 * @return list<string> locale codes available to this instance
	 */
	public function locales() : array{
		return $this->provider->locales();
	}

	/**
	 * @param string $key translation key (e.g. "item.apple.name")
	 * @param string|null $locale locale code (e.g. "en_US"), or null for the default locale
	 */
	public function translate(string $key, ?string $locale = null) : ?string{
		return $this->language($locale)?->get($key);
	}

	public function itemName(Item $item, ?string $locale = null) : ?string{
		$language = $this->language($locale);
		if($language === null){
			return null;
		}
		if($item->isNull()){
			return $this->name($language, "minecraft:air", false);
		}
		try{
			$data = GlobalItemDataHandlers::getSerializer()->serializeType($item);
		}catch(ItemTypeSerializeException){
			return null;
		}
		$block = $data->getBlock();
		return ($block !== null ? $this->name($language, $block->getName(), true) : null) ?? $this->name($language, $data->getName(), false);
	}

	public function blockName(Block $block, ?string $locale = null) : ?string{
		$language = $this->language($locale);
		if($language === null){
			return null;
		}
		try{
			$data = GlobalBlockStateHandlers::getSerializer()->serialize($block->getStateId());
		}catch(BlockStateSerializeException){
			return null;
		}
		return $this->name($language, $data->getName(), true);
	}

	/**
	 * @param string $network_id namespaced entity network id (e.g. "minecraft:zombie") - vanilla keys drop the
	 *                           namespace ("entity.zombie.name"), custom entity keys keep it
	 * @param string|null $locale locale code (e.g. "en_US"), or null for the default locale
	 */
	public function entityName(string $network_id, ?string $locale = null) : ?string{
		$language = $this->language($locale);
		return $language?->get("entity." . TranslationKeyResolver::stripNamespace($network_id) . ".name")
			?? $language?->get("entity.{$network_id}.name");
	}

	private function name(Language $language, string $identifier, bool $prefer_block) : ?string{
		$key = $this->resolver->resolve($identifier, $language, $prefer_block);
		return $key !== null ? $language->get($key) : null;
	}
}
