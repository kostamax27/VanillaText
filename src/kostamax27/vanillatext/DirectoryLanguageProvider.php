<?php

declare(strict_types=1);

namespace kostamax27\vanillatext;

use pocketmine\errorhandler\ErrorToExceptionHandler;
use pocketmine\utils\Filesystem;
use Symfony\Component\Filesystem\Path;
use function is_dir;
use function is_file;
use function scandir;
use function str_ends_with;
use function substr;

/**
 * Lazily reads languages from a directory of .lang files (en_US.lang, ru_RU.lang, ...). A language is parsed at most
 * once - subsequent reads are served from cache.
 */
final class DirectoryLanguageProvider implements LanguageProvider{
	/** @var array<string, Language> */
	private array $cache = [];

	/**
	 * @param string $directory directory containing .lang files, e.g. resource_pack/texts of a vanilla resource pack
	 */
	public function __construct(readonly public string $directory){
		is_dir($directory) || throw new \InvalidArgumentException("Directory {$directory} does not exist");
	}

	public function get(string $locale) : ?Language{
		if(isset($this->cache[$locale])){
			return $this->cache[$locale];
		}
		$path = Path::join($this->directory, "{$locale}.lang");
		if(!Language::isValidCode($locale) || !is_file($path)){
			return null;
		}
		return $this->cache[$locale] = Language::parse($locale, Filesystem::fileGetContents($path));
	}

	public function locales() : array{
		$entries = ErrorToExceptionHandler::trapAndRemoveFalse(fn() => scandir($this->directory));
		$locales = [];
		foreach($entries as $entry){
			if(str_ends_with($entry, ".lang") && Language::isValidCode($locale = substr($entry, 0, -5))){
				$locales[] = $locale;
			}
		}
		return $locales;
	}
}
