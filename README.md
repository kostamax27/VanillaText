# VanillaText
A library for naming vanilla items, blocks and entities in every Minecraft: Bedrock Edition locale in PocketMine-MP.

```php
$texts->itemName(VanillaItems::GOLDEN_APPLE(), "ru_RU"); // "Золотое яблоко"
$texts->blockName(VanillaBlocks::ACACIA_PLANKS(), "ja_JP"); // "アカシアの板材"
$texts->entityName("minecraft:zombie", "de_DE"); // "Zombie"
```

## Features
- Names for 1,700+ items and blocks, every entity, in the 29 locales the vanilla client ships
- Resolves the pre-flattening keys vanilla language files still use (`minecraft:acacia_planks` is named by
  `tile.planks.acacia.name`) through a bundled index the vanilla client otherwise keeps to itself
- Overlays your own translations over vanilla ones key by key
- Works with a single language file just as well as with all of them - the locale argument is optional

## Setup
VanillaText ships no language files of its own - they are Mojang's, weigh 800KB a locale, and a virion has nowhere to put
them. Place a copy of `resource_pack/texts` from [Mojang/bedrock-samples](https://github.com/Mojang/bedrock-samples/tree/main/resource_pack/texts)
in your plugin's `resources/` directory. `tools/download-texts.php` does this for you - pass locale codes to download
a subset, and `--names-only` to keep only the keys VanillaText resolves (~150KB a locale).
```sh
php vendor/kostamax27/vanillatext/tools/download-texts.php --names-only resources/texts en_US ru_RU
```

## Usage
### One language
A server that answers everyone in the same language needs one `.lang` file and never passes a locale.
```php
$this->texts = VanillaText::fromFile(Path::join($this->getDataFolder(), "ru_RU.lang"));

$name = $this->texts->itemName($item);
$name = $this->texts->blockName($block);
$name = $this->texts->entityName(EntityIds::ZOMBIE);
$name = $this->texts->translate("item.record_13.desc");
```

### Many languages
`VanillaText::fromDirectory()` takes a directory of `.lang` files and a default locale. The default answers whenever a
locale is omitted or is not covered by the directory (`Player::getLocale()` may report one).
```php
$this->texts = VanillaText::fromDirectory(Path::join($this->getDataFolder(), "texts"), "en_US");

$name = $this->texts->itemName($item, $player->getLocale());
$name = $this->texts->blockName($block, $player->getLocale());
```

`withLocale()` returns a copy with another default locale, so a run of lookups for one player does not repeat it.
The copy shares the language files with the original and costs nothing to make. Locales the directory does not cover
leave the default untouched.
```php
$texts = $this->texts->forPlayer($player); // same as withLocale($player->getLocale())
$name = $texts->itemName($item);
$name = $texts->blockName($block);
```

Every method returns `null` for what it cannot name - custom content, Education Edition content, and keys the
language file lacks.

### Composition
VanillaText is a thin layer over a `LanguageProvider` and a `TranslationKeyResolver`, both of which it exposes as
readonly properties. Compose your own to override or extend what it serves.
```php
// override the names of a few vanilla items - earlier providers win, key by key
$provider = new ChainLanguageProvider(
	new ArrayLanguageProvider(["ru_RU" => ["item.apple.name" => "Яблочко"]]),
	new DirectoryLanguageProvider(Path::join($this->getDataFolder(), "texts"))
);

// name custom items - keys are looked up in the same language files
$resolver = new TranslationKeyResolver(VanillaItemKeys::KEYS + ["myplugin:cool_sword" => "item.myplugin.cool_sword.name"]);

$texts = new VanillaText($provider, $resolver, "en_US");
```
