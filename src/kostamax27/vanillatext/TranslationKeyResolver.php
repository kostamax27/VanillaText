<?php

declare(strict_types=1);

namespace kostamax27\vanillatext;

use function array_slice;
use function count;
use function explode;
use function implode;
use function str_contains;
use function str_ends_with;
use function strpos;
use function substr;

/**
 * Resolves Bedrock namespaced identifiers (e.g. "minecraft:andesite") to vanilla translation keys (e.g.
 * "tile.stone.andesite.name").
 *
 * Vanilla language files address a large number of blocks and items by their pre-flattening identifiers ("tile.planks.
 * acacia.name" names "minecraft:acacia_planks"). Most of these follow a pattern the resolver derives on its own
 * ({@see self::candidates()}); the rest are looked up in {@see self::$mapping}.
 */
final class TranslationKeyResolver{
	/**
	 * A resolver holding the mapping for every vanilla identifier the pattern cannot derive.
	 */
	public static function default() : self{
		static $instance = null;
		return $instance ??= new self(VanillaItemKeys::KEYS);
	}

	public static function stripNamespace(string $identifier) : string{
		$separator = strpos($identifier, ":");
		return $separator !== false ? substr($identifier, $separator + 1) : $identifier;
	}

	/**
	 * @param array<string, string> $mapping mapping namespaced identifiers to translation keys, consulted before the
	 *                                       pattern-derived candidates - the place for custom items whose identifiers the pattern cannot guess
	 */
	public function __construct(
		readonly public array $mapping
	){}

	/**
	 * Returns the first translation key candidate the given language holds, or null if it holds none.
	 *
	 * @param string $identifier namespaced identifier (e.g. "minecraft:apple")
	 * @param bool $prefer_block whether block ("tile.") keys should be attempted before item ("item.") keys
	 */
	public function resolve(string $identifier, Language $language, bool $prefer_block = false) : ?string{
		foreach($this->candidates($identifier, $prefer_block) as $key){
			if($language->has($key)){
				return $key;
			}
		}
		return null;
	}

	/**
	 * Lists translation key candidates for the given identifier, most reliable first.
	 *
	 * @param string $identifier namespaced identifier (e.g. "minecraft:apple")
	 * @param bool $prefer_block whether block ("tile.") keys should be attempted before item ("item.") keys
	 *
	 * @return \Generator<string>
	 */
	public function candidates(string $identifier, bool $prefer_block = false) : \Generator{
		if(!str_contains($identifier, ":")){
			$identifier = "minecraft:" . $identifier;
		}
		if(isset($this->mapping[$identifier])){
			yield $this->mapping[$identifier];
		}

		$identifier = self::stripNamespace($identifier);
		$prefixes = $prefer_block ? ["tile", "item"] : ["item", "tile"];
		foreach($prefixes as $prefix){
			yield "{$prefix}.{$identifier}.name";
		}
		if(str_ends_with($identifier, "_spawn_egg")){
			yield "item.spawn_egg.entity." . substr($identifier, 0, -10) . ".name";
		}

		// pre-flattening keys follow a "prefix.base.variant.name" scheme where flattened identifiers are typically
		// "variant_base" (e.g. "minecraft:acacia_planks" -> "tile.planks.acacia.name")
		$parts = explode("_", $identifier, limit: 16);
		for($cut = 1, $max = count($parts); $cut < $max; $cut++){
			$base = implode("_", array_slice($parts, $cut));
			$variant = implode("_", array_slice($parts, 0, $cut));
			foreach($prefixes as $prefix){
				yield "{$prefix}.{$base}.{$variant}.name";
			}
		}

		yield "item.{$identifier}";
	}
}
