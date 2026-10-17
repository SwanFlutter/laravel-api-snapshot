<?php

namespace SwanFlutter\LaravelApiSnapshot\Testing;

/**
 * The bytes of a real, dimension-carrying image, written without GD.
 *
 * An upload endpoint often goes past the validator: it reads the file with
 * `getimagesize()` and rejects anything smaller than a logo slot. A fake file
 * made of zero bytes fails that check, so the sample sent for an image field
 * has to be an image — headers only, which is all `getimagesize()` reads.
 */
final class FakeImage
{
    public const WIDTH = 256;

    public const HEIGHT = 256;

    /** @param int 1024-bytes blocks the file is padded to */
    public static function of(string $extension, int $kilobytes = 1): ?string
    {
        $bytes = match (strtolower($extension)) {
            'jpg', 'jpeg' => self::jpeg(),
            'png'         => self::png(),
            'gif'         => self::gif(),
            default       => null,
        };

        if ($bytes === null) {
            return null;
        }

        $filler = max(0, $kilobytes * 1024 - strlen($bytes));

        return $filler > 0 ? $bytes . str_repeat("\0", $filler) : $bytes;
    }

    private static function png(): string
    {
        $header = pack('N', self::WIDTH) . pack('N', self::HEIGHT) . chr(8) . chr(2) . chr(0) . chr(0) . chr(0);

        return "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', $header)
            . self::chunk('IDAT', '')
            . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function gif(): string
    {
        return 'GIF89a' . pack('v', self::WIDTH) . pack('v', self::HEIGHT) . chr(0) . chr(0) . chr(0) . "\x3B";
    }

    private static function jpeg(): string
    {
        return "\xFF\xD8"
            . "\xFF\xE0" . pack('n', 16) . "JFIF\x00" . chr(1) . chr(1) . chr(0)
            . pack('n', 1) . pack('n', 1) . chr(0) . chr(0)
            . "\xFF\xDB" . pack('n', 67) . chr(0) . str_repeat(chr(8), 64)
            . "\xFF\xC0" . pack('n', 17) . chr(8) . pack('n', self::HEIGHT) . pack('n', self::WIDTH)
            . chr(3) . chr(1) . chr(0x21) . chr(0) . chr(2) . chr(0x11) . chr(1) . chr(3) . chr(0x11) . chr(1)
            . "\xFF\xDA" . pack('n', 12) . chr(3) . chr(1) . chr(0) . chr(2) . chr(0x11) . chr(3) . chr(0x11)
            . chr(0) . chr(63) . chr(0)
            . "\xFF\xD9";
    }
}
