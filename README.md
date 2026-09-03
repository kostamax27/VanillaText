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

## Setup
VanillaText ships no language files of its own - they are Mojang's, weigh 800KB a locale, and a virion has nowhere to put
them. Place a copy of `resource_pack/texts` from [Mojang/bedrock-samples](https://github.com/Mojang/bedrock-samples/tree/main/resource_pack/texts)
in your plugin's `resources/` directory. `tools/download-texts.php` does this for you - pass locale codes to download
a subset, and `--names-only` to keep only the keys VanillaText resolves (~150KB a locale).
```sh
php vendor/kostamax27/vanillatext/tools/download-texts.php --names-only resources/texts en_US ru_RU
```

## Usage
`VanillaText::fromDirectory()` answers in `en_US` for locales the directory does not cover (`Player::getLocale()` may
report one), pass `null` as its second argument to receive `null` instead. Every method returns `null` for what it
cannot name - custom content, Education Edition content, and locales without a fallback.
```php
$name = $this->texts->itemName($item, $player->getLocale());
$name = $this->texts->blockName($block, $player->getLocale());
$name = $this->texts->entityName(EntityIds::ZOMBIE, $player->getLocale());
$name = $this->texts->translate("item.record_13.desc", $player->getLocale());
```

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
