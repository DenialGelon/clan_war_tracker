<?php
declare(strict_types=1);

// Player and clan tag helpers.
//
// Supercell tags use a restricted alphabet (0 2 8 9 P Y L Q G R J C U V). People
// often type the letter O for the digit 0, so we fix that. Tags are stored
// normalized (no #, uppercase) and displayed with a leading #.
final class Tag
{
    private const ALPHABET = '0289PYLQGRJCUV';

    public static function normalize(string $tag): string
    {
        $tag = strtoupper(trim($tag));
        $tag = ltrim($tag, '#');
        $tag = str_replace('O', '0', $tag);
        return $tag;
    }

    public static function isValid(string $normalizedTag): bool
    {
        if ($normalizedTag === '' || strlen($normalizedTag) > 15) {
            return false;
        }
        return strspn($normalizedTag, self::ALPHABET) === strlen($normalizedTag);
    }

    public static function display(string $normalizedTag): string
    {
        return '#' . $normalizedTag;
    }

    // For use in API URLs: "#" must be sent as "%23".
    public static function urlEncode(string $normalizedTag): string
    {
        return '%23' . rawurlencode($normalizedTag);
    }
}
