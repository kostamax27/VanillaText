<?php

declare(strict_types=1);

namespace kostamax27\vanillatext\tools\download_texts;

use kostamax27\vanillatext\Language;
use RuntimeException;
use function array_filter;
use function array_shift;
use function array_slice;
use function count;
use function dirname;
use function error_get_last;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function implode;
use function is_array;
use function is_dir;
use function is_string;
use function json_decode;
use function mkdir;
use function number_format;
use function str_starts_with;
use function stream_context_create;
use function strlen;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const STDERR;

require dirname(__DIR__) . "/src/kostamax27/vanillatext/Language.php";

const NAME_PREFIXES = ["item.", "tile.", "entity.", "potion."];

function download(string $url) : string{
	$contents = @file_get_contents($url, context: stream_context_create(["http" => ["timeout" => 30.0]]));
	$contents !== false || throw new RuntimeException("Failed to download {$url}: " . (error_get_last()["message"] ?? "unknown error"));
	return $contents;
}

/**
 * @return list<string>
 */
function listLocales(string $base_url) : array{
	$decoded = json_decode(download("{$base_url}/languages.json"), flags: JSON_THROW_ON_ERROR);
	is_array($decoded) || throw new RuntimeException("Invalid languages.json, expected array as root type");
	$locales = [];
	foreach($decoded as $locale){
		is_string($locale) || throw new RuntimeException("Invalid languages.json, expected an array of strings");
		$locales[] = $locale;
	}
	return $locales;
}

function namesOnly(string $contents) : string{
	return implode("\n", array_filter(explode("\n", $contents), static function(string $line) : bool{
		foreach(NAME_PREFIXES as $prefix){
			if(str_starts_with($line, $prefix)){
				return true;
			}
		}
		return false;
	}));
}

$args = array_slice($argv ?? [], 1);
$branch = "main";
$names_only = false;
while(isset($args[0]) && str_starts_with($args[0], "--")){
	$option = array_shift($args);
	match($option){
		"--preview" => $branch = "preview",
		"--names-only" => $names_only = true,
		default => throw new RuntimeException("Unknown option {$option}")
	};
}

$directory = array_shift($args);
if($directory === null){
	fwrite(STDERR, "Downloads vanilla resource pack language files for VanillaText" . PHP_EOL);
	fwrite(STDERR, "Usage: php " . __FILE__ . " [--preview] [--names-only] <directory> [locale ...]" . PHP_EOL);
	exit(1);
}
if(!@mkdir($directory, recursive: true) && !is_dir($directory)){
	throw new RuntimeException("Failed to create directory {$directory}");
}

$base_url = "https://raw.githubusercontent.com/Mojang/bedrock-samples/{$branch}/resource_pack/texts";
$locales = count($args) > 0 ? $args : listLocales($base_url);
foreach($locales as $locale){
	Language::isValidCode($locale) || throw new RuntimeException("Malformed locale code {$locale}");
	echo "Downloading {$locale}.lang ... ";
	$contents = download("{$base_url}/{$locale}.lang");
	if($names_only){
		$contents = namesOnly($contents);
	}
	$path = $directory . DIRECTORY_SEPARATOR . "{$locale}.lang";
	file_put_contents($path, $contents) !== false || throw new RuntimeException("Failed to write {$path}");
	echo number_format(strlen($contents) / 1024, 1) . " KiB" . PHP_EOL;
}
echo "Saved " . count($locales) . " language file(s) to {$directory}" . PHP_EOL;
