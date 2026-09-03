<?php

declare(strict_types=1);

namespace kostamax27\vanillatext\tools\generate_item_keys;

use kostamax27\vanillatext\Language;
use kostamax27\vanillatext\TranslationKeyResolver;
use pocketmine\data\bedrock\item\BlockItemIdMap;
use pocketmine\data\bedrock\item\upgrade\ItemIdMetaUpgrader;
use pocketmine\data\bedrock\item\upgrade\ItemIdMetaUpgradeSchemaUtils;
use pocketmine\network\mcpe\convert\ItemTypeDictionaryFromDataHelper;
use pocketmine\utils\Filesystem;
use RuntimeException;
use function array_reverse;
use function array_slice;
use function basename;
use function count;
use function dirname;
use function file_put_contents;
use function fwrite;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function ksort;
use function preg_match;
use function sort;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function usort;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const PHP_INT_MAX;
use const STDERR;

require dirname(__DIR__) . "/vendor/autoload.php";

/**
 * Identifiers whose translation keys can be derived neither from the identifier, the display name, nor the item
 * upgrade schema.
 */
const OVERRIDES = [
	// potion keys live under "potion.", outside the tile./item. display name index
	"minecraft:potion" => "potion.emptyPotion.name",
	"minecraft:splash_potion" => "potion.emptyPotion.splash.name",
	"minecraft:lingering_potion" => "potion.emptyPotion.linger.name",
	// both flattened out of the legacy grouped stone_slab, whose variant keys share nothing with the modern identifiers
	"minecraft:normal_stone_slab" => "tile.stone_slab.name",
	"minecraft:petrified_oak_slab" => "tile.stone_slab.wood.name",
	// the colorless variant keeps the plain pre-flattening key
	"minecraft:undyed_shulker_box" => "tile.shulkerBox.name",
	// the keys spell "brick" in singular while the identifiers spell "bricks"
	"minecraft:infested_chiseled_stone_bricks" => "tile.monster_egg.chiseledbrick.name",
	"minecraft:infested_cracked_stone_bricks" => "tile.monster_egg.crackedbrick.name",
	"minecraft:infested_mossy_stone_bricks" => "tile.monster_egg.mossybrick.name",
];

/**
 * Identifiers the vanilla client itself has no display name for. Education Edition content (elements, compounds,
 * hardened glass, colored torches, ...) falls here because Mojang/bedrock-samples ships no Education translations.
 */
const UNTRANSLATABLE = [
	// Education Edition
	"minecraft:balloon", "minecraft:bleach", "minecraft:chemical_heat", "minecraft:chemistry_table",
	"minecraft:compound", "minecraft:compound_creator", "minecraft:element_constructor", "minecraft:glow_stick",
	"minecraft:ice_bomb", "minecraft:lab_table", "minecraft:material_reducer", "minecraft:medicine",
	"minecraft:rapid_fertilizer", "minecraft:sparkler", "minecraft:underwater_tnt", "minecraft:underwater_torch",
	// technical blocks
	"minecraft:client_request_placeholder_block", "minecraft:deprecated_anvil", "minecraft:deprecated_purpur_block_1",
	"minecraft:deprecated_purpur_block_2", "minecraft:info_update", "minecraft:info_update2", "minecraft:moving_block",
	"minecraft:reserved6",
	// creator tools
	"minecraft:debug_stick",
];
const UNTRANSLATABLE_PREFIXES = ["minecraft:element_", "minecraft:colored_torch_", "minecraft:hard_"];

final class KeyGenerator{
	private TranslationKeyResolver $pattern;

	/** @var array<string, string> lowercase key => canonical key */
	private array $key_index = [];

	/** @var array<string, non-empty-list<string>> normalized display name => keys, shortest first */
	private array $display_name_index = [];

	/**
	 * @param list<Language> $languages
	 */
	public function __construct(
		readonly public array $languages,
		readonly public BlockItemIdMap $block_item_ids,
		readonly public ItemIdMetaUpgrader $upgrader
	){
		$this->pattern = new TranslationKeyResolver([]);

		// keys are matched case-insensitively because renames lowercased identifiers the keys kept camel-cased
		// ("minecraft:glazedterracotta.silver" is keyed as "tile.glazedTerracotta.silver.name") - the canonical casing
		// is what gets written to the mapping
		foreach($languages as $language){
			foreach($language->translations as $key => $value){
				$this->key_index[strtolower($key)] ??= $key;
				if(str_starts_with($key, "tile.") || str_starts_with($key, "item.")){
					$this->display_name_index[strtolower(str_replace(" ", "", $value))][] = $key;
				}
			}
		}
		// display names are not unique across keys ("Brick Slab" names both tile.stone_slab.brick.name and
		// tile.double_stone_slab.brick.name) - the shortest key is the least specific variant
		foreach($this->display_name_index as $name => $keys){
			usort($keys, static fn(string $a, string $b) : int => strlen($a) <=> strlen($b));
			$this->display_name_index[$name] = $keys;
		}
	}

	public function hasKey(string $key) : bool{
		return ($this->key_index[strtolower($key)] ?? null) === $key;
	}

	/**
	 * Resolves an identifier through every strategy the generator knows, or null if none applies.
	 */
	public function resolve(string $identifier) : ?string{
		// every music disc is displayed as "Music Disc" - artist and title live in a separate "item.record_<name>.desc"
		if(str_starts_with($identifier, "minecraft:music_disc")){
			return "item.record.name";
		}

		$key = $this->resolveDirect($identifier);

		// block-items ("minecraft:item.acacia_door") resolve through their block
		if($key === null && ($block = $this->block_item_ids->lookupBlockId($identifier)) !== null){
			$key = $this->resolveDirect($block, prefer_block: true);
		}

		// legacy aliases ("minecraft:log2", "minecraft:dye", "minecraft:boat", ...)
		if($key === null && ($upgraded = $this->upgrade($identifier)) !== $identifier){
			$key = $this->resolveDirect($upgraded);
		}

		// keys named after an identifier's older form ("minecraft:filled_map" -> "minecraft:map" -> "item.map.name")
		if($key === null){
			foreach($this->downgrade($identifier) as $downgraded){
				$key = $this->resolveDirect($downgraded);
				if($key !== null){
					break;
				}
			}
		}

		$key ??= $this->resolveDoubleSlab($identifier);

		// flattened numeric variants share their base's name ("light_block_7" -> "tile.light_block.name")
		if($key === null && preg_match('/^(.+)_\d+$/', $identifier, $matches) === 1){
			$key = $this->resolveDirect($matches[1]);
		}
		return $key;
	}

	/**
	 * Whether TranslationKeyResolver derives the given key for the identifier on its own, in which case the mapping
	 * need not carry it.
	 */
	public function isDerivable(string $identifier, string $key) : bool{
		foreach([false, true] as $prefer_block){
			$derived = null;
			foreach($this->pattern->candidates($identifier, $prefer_block) as $candidate){
				if($this->hasKey($candidate)){
					$derived = $candidate;
					break;
				}
			}
			if($derived !== $key){
				return false;
			}
		}
		return true;
	}

	private function resolveDirect(string $identifier, bool $prefer_block = false) : ?string{
		if(isset(OVERRIDES[$identifier])){
			return OVERRIDES[$identifier];
		}
		foreach($this->pattern->candidates($identifier, $prefer_block) as $candidate){
			if(isset($this->key_index[strtolower($candidate)])){
				return $this->key_index[strtolower($candidate)];
			}
		}
		foreach($this->displayNameGuesses($identifier) as $guess){
			if(isset($this->display_name_index[$guess])){
				return $this->display_name_index[$guess][0];
			}
		}
		return null;
	}

	/**
	 * Guesses are normalized like the display name index - lowercase, spaces removed - which is what lets "trip_wire"
	 * match "Tripwire".
	 *
	 * @return list<string>
	 */
	private function displayNameGuesses(string $identifier) : array{
		$identifier = TranslationKeyResolver::stripNamespace($identifier);
		$base = str_replace("_", "", $identifier);
		$guesses = [$base];
		if(str_ends_with($identifier, "_bucket")){ // "Bucket of Cod"
			$guesses[] = "bucketof" . substr($base, 0, -6);
		}
		if(str_ends_with($identifier, "_chest_boat")){ // "Dark Oak Boat with Chest"
			$guesses[] = substr($base, 0, -9) . "boatwithchest";
		}
		if(str_ends_with($identifier, "_chest_raft")){ // "Bamboo Raft with Chest"
			$guesses[] = substr($base, 0, -9) . "raftwithchest";
		}
		$guesses[] = $base . "block"; // "Smooth Quartz Block"
		return $guesses;
	}

	/**
	 * Replays the item upgrade schemas over an identifier at meta 0, returning its modern equivalent
	 * ("minecraft:log2" -> "minecraft:acacia_log").
	 */
	private function upgrade(string $identifier) : string{
		[$identifier,] = $this->upgrader->upgrade($identifier, 0);
		return $identifier;
	}

	/**
	 * Replays renames backwards (newest schema first), listing an identifier's older forms - vanilla keys are named
	 * after the identifiers of their era. Meta remaps are not reversed, as the dropped meta carries the variant and
	 * reversal would derive the wrong key.
	 *
	 * @return list<string>
	 */
	private function downgrade(string $identifier) : array{
		$forms = [];
		foreach(array_reverse($this->upgrader->getSchemas()) as $schema){
			foreach($schema->getRenamedIds() as $old => $new){
				if(strtolower($new) === strtolower($identifier)){
					$forms[] = $identifier = $old;
					break;
				}
			}
		}
		return $forms;
	}

	/**
	 * The language files carry no keys for the flattened legacy double slab identifiers themselves - their key is the
	 * double_-prefixed form of their single-slab counterpart's key ("brick_double_slab" resolves "brick_slab" to
	 * "tile.stone_slab.brick.name", yielding "tile.double_stone_slab.brick.name").
	 */
	private function resolveDoubleSlab(string $identifier) : ?string{
		$identifier = TranslationKeyResolver::stripNamespace($identifier);
		if(str_ends_with($identifier, "_double_slab")){
			$slab = substr($identifier, 0, -12) . "_slab";
		}elseif(str_starts_with($identifier, "double_")){
			$slab = substr($identifier, 7);
		}else{
			return null;
		}

		$slab = "minecraft:" . $slab;
		$key = $this->resolveDirect($this->upgrade($slab), prefer_block: true) ?? $this->resolveDirect($slab, prefer_block: true);
		if($key === null){
			return null;
		}
		foreach([
			str_replace("tile.stone_slab", "tile.double_stone_slab", $key),
			str_replace("tile.wooden_slab", "tile.double_wooden_slab", $key),
			str_replace("_slab.name", "_double_slab.name", $key),
		] as $candidate){
			if($candidate !== $key && isset($this->key_index[strtolower($candidate)])){
				return $this->key_index[strtolower($candidate)];
			}
		}
		return $key;
	}
}

function untranslatable(string $identifier) : bool{
	foreach(UNTRANSLATABLE_PREFIXES as $prefix){
		if(str_starts_with($identifier, $prefix)){
			return true;
		}
	}
	return in_array($identifier, UNTRANSLATABLE, true);
}

$args = array_slice($argv ?? [], 1);
$item_list_file = $args[0] ?? null;
$block_item_map_file = $args[1] ?? null;
$schemas_directory = $args[2] ?? null;
$lang_files = array_slice($args, 3);
if($item_list_file === null || $block_item_map_file === null || $schemas_directory === null || count($lang_files) === 0){
	fwrite(STDERR, "Regenerates VanillaItemKeys from BedrockData and vanilla language files" . PHP_EOL);
	fwrite(STDERR, "Usage: php " . __FILE__ . " <required_item_list.json> <block_id_to_item_id_map.json> <id_meta_upgrade_schema directory> <en_US.lang> [en_US.lang ...]" . PHP_EOL);
	exit(1);
}

$languages = [];
foreach($lang_files as $file){
	$languages[] = Language::parse(basename($file, ".lang"), Filesystem::fileGetContents($file));
}

$block_item_map = json_decode(Filesystem::fileGetContents($block_item_map_file), true, flags: JSON_THROW_ON_ERROR);
is_array($block_item_map) || throw new RuntimeException("Invalid {$block_item_map_file}, expected object as root type");
foreach($block_item_map as $block => $item){
	(is_string($block) && is_string($item)) || throw new RuntimeException("Invalid {$block_item_map_file}, expected an object of strings");
}

$generator = new KeyGenerator(
	$languages,
	new BlockItemIdMap($block_item_map),
	new ItemIdMetaUpgrader(ItemIdMetaUpgradeSchemaUtils::loadSchemas($schemas_directory, PHP_INT_MAX))
);

foreach(OVERRIDES as $identifier => $key){
	$generator->hasKey($key) || throw new RuntimeException("Override for {$identifier} points to an unknown key {$key}");
}

$mapping = [];
$derivable = 0;
$untranslatable = [];
$errors = [];
$dictionary = ItemTypeDictionaryFromDataHelper::loadFromString(Filesystem::fileGetContents($item_list_file));
foreach($dictionary->getEntries() as $entry){
	$identifier = $entry->getStringId();
	$key = $generator->resolve($identifier);
	if($key === null){
		if(!untranslatable($identifier)){
			$errors[] = "{$identifier} did not resolve to any key";
		}
		$untranslatable[] = $identifier;
	}elseif(untranslatable($identifier)){
		$errors[] = "{$identifier} was expected to be untranslatable, but resolved to {$key} - remove it from UNTRANSLATABLE";
	}elseif($generator->isDerivable($identifier, $key)){
		$derivable++;
	}else{
		$mapping[$identifier] = $key;
	}
}
if(count($errors) > 0){
	fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
	exit(1);
}

ksort($mapping);
$lines = [];
foreach($mapping as $identifier => $key){
	$lines[] = "\t\t'{$identifier}' => '{$key}',";
}
$output = dirname(__DIR__) . "/src/kostamax27/vanillatext/VanillaItemKeys.php";
$contents = <<<'PHP'
<?php

declare(strict_types=1);

namespace kostamax27\vanillatext;

/**
 * Translation keys of vanilla identifiers that TranslationKeyResolver::candidates() cannot derive.
 * This is generated by tools/generate-item-keys.php.
 * Do not edit this file manually.
 */
final class VanillaItemKeys{
	/** @var array<string, string> */
	public const KEYS = [

PHP;
$contents .= implode(PHP_EOL, $lines) . PHP_EOL . "\t];" . PHP_EOL . "}" . PHP_EOL;
file_put_contents($output, $contents) !== false || throw new RuntimeException("Failed to write {$output}");

sort($untranslatable);
echo "Wrote " . count($mapping) . " identifiers to {$output}" . PHP_EOL;
echo "Skipped {$derivable} identifiers the resolver derives on its own" . PHP_EOL;
echo "Verified " . count($untranslatable) . " identifiers as untranslatable: " . implode(", ", $untranslatable) . PHP_EOL;
