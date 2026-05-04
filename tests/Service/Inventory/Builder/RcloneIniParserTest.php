<?php

namespace App\Tests\Service\Inventory\Builder;

use App\Service\Inventory\Builder\RcloneIniParser;
use PHPUnit\Framework\TestCase;

final class RcloneIniParserTest extends TestCase
{
    private RcloneIniParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RcloneIniParser();
    }

    public function testParsesSingleSection(): void
    {
        $config = <<<'INI'
            [source]
            type = s3
            access_key_id = abc123
            endpoint = https://s3.example.com
            INI;

        self::assertSame(
            [
                'source' => [
                    'type' => 's3',
                    'access_key_id' => 'abc123',
                    'endpoint' => 'https://s3.example.com',
                ],
            ],
            $this->parser->parse($config),
        );
    }

    public function testParsesMultipleSections(): void
    {
        $config = <<<'INI'
            [a]
            type = s3
            [b]
            type = swift
            INI;

        self::assertSame(
            ['a' => ['type' => 's3'], 'b' => ['type' => 'swift']],
            $this->parser->parse($config),
        );
    }

    public function testIgnoresCommentsAndBlankLines(): void
    {
        $config = <<<'INI'
            # comment
            ; another
            [src]

            type = s3
            INI;

        self::assertSame(['src' => ['type' => 's3']], $this->parser->parse($config));
    }

    public function testHandlesValuesWithEqualsSign(): void
    {
        $config = <<<'INI'
            [src]
            endpoint = https://s3.example.com/path?x=1
            INI;

        self::assertSame(
            ['src' => ['endpoint' => 'https://s3.example.com/path?x=1']],
            $this->parser->parse($config),
        );
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], $this->parser->parse(''));
    }

    public function testIgnoresOrphanKeysBeforeFirstSection(): void
    {
        $config = "stray = value\n[a]\ntype = s3";

        self::assertSame(['a' => ['type' => 's3']], $this->parser->parse($config));
    }
}
