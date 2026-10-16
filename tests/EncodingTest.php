<?php

declare(strict_types=1);

namespace SwanFlutter\LaravelApiSnapshot\Tests;

use PHPUnit\Framework\Attributes\Test;
use SwanFlutter\LaravelApiSnapshot\Support\Utf8;

final class EncodingTest extends TestCase
{
    /**
     * The «م» of a Persian docblock is the two bytes D9 85, and 0x85 is NEL —
     * a line break to /\R/ when the pattern is not in UTF-8 mode. Splitting
     * there cut the character in half and json_encode then rejected the whole
     * collection.
     */
    #[Test]
    public function it_keeps_a_persian_docblock_summary_intact(): void
    {
        $collection = $this->collection();
        $item       = $this->findRequest($collection, 'POST', 'api/v1/reports/generate');

        $this->assertNotNull($item);
        $this->assertStringContainsString('ساخت گزارش برای بازه‌ی زمانی', $item['request']['description']);
        $this->assertNotFalse(json_encode($collection, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function every_generated_description_is_valid_utf8(): void
    {
        $collection = $this->collection(['scan_all_routes' => true]);
        $checked    = 0;

        $walk = function (mixed $node) use (&$walk, &$checked): void {
            foreach (is_array($node) ? $node : [] as $key => $value) {
                if (is_string($value)) {
                    if ($key === 'description' || $key === 'name' || $key === 'value') {
                        $this->assertTrue(mb_check_encoding($value, 'UTF-8'), "unencodable [{$key}]: " . bin2hex($value));
                        $checked++;
                    }

                    continue;
                }

                $walk($value);
            }
        };

        $walk($collection);

        $this->assertGreaterThan(0, $checked);
    }

    #[Test]
    public function it_replaces_invalid_bytes_instead_of_failing_to_encode(): void
    {
        $dirty = [
            'name'  => "broken \xC3\x28 sequence",
            'plain' => 'untouched',
            'item'  => [['description' => "trailing \xE0\xA8"]],
        ];

        $clean = Utf8::cleanTree($dirty);

        $this->assertSame('untouched', $clean['plain']);
        $this->assertTrue(mb_check_encoding($clean['name'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($clean['item'][0]['description'], 'UTF-8'));
        $this->assertNotFalse(json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function it_leaves_valid_multibyte_text_untouched(): void
    {
        $value = "کاربر 👨‍👩 و رمز عبور";

        $this->assertSame($value, Utf8::sanitize($value));
    }
}
