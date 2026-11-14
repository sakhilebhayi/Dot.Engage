<?php

declare(strict_types=1);

namespace Dot\Ui;

/**
 * The original platform logos, shipped with the package in `img/platforms/` and described by `manifest.json`
 * (file name and sha256 per platform key). The files are byte copies of InfoDot's own set: never redraw, recolour
 * or replace them. A key with no file has no logo and the tile shows the name only, never a generated icon.
 */
final class PlatformLogos
{
    /** The box InfoDot fits a logo into (its `.ix-bay__logo img` max sizes). */
    public const MAX_WIDTH = 112;

    public const MAX_HEIGHT = 68;

    /** @var array<string, array{file: string, sha256: string, width: int, height: int}>|null */
    private static ?array $platforms = null;

    /** @return array<string, array{file: string, sha256: string, width: int, height: int}> */
    public static function all(): array
    {
        if (self::$platforms === null) {
            $json = @file_get_contents(self::directory().'/manifest.json');
            $data = is_string($json) ? json_decode($json, true) : null;
            self::$platforms = is_array($data) && is_array($data['platforms'] ?? null) ? $data['platforms'] : [];
        }

        return self::$platforms;
    }

    /** The file name for a key (`sheet` is `sheet.png`), or null when the package has no logo for it. */
    public static function file(string $key): ?string
    {
        return self::all()[$key]['file'] ?? null;
    }

    /**
     * The size to print: the PNG scaled to fit InfoDot's 112 x 68 box. Explicit width and height keep the layout
     * from jumping and need no inline style.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function size(string $key): ?array
    {
        $row = self::all()[$key] ?? null;

        if ($row === null || $row['width'] < 1 || $row['height'] < 1) {
            return null;
        }

        $scale = min(self::MAX_WIDTH / $row['width'], self::MAX_HEIGHT / $row['height']);

        return [(int) round($row['width'] * $scale), (int) round($row['height'] * $scale)];
    }

    public static function directory(): string
    {
        return dirname(__DIR__).'/img/platforms';
    }
}
