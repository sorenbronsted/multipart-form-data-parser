<?php declare(strict_types=1);

namespace Kekos\MultipartFormDataParser\Tests;

use Kekos\MultipartFormDataParser\HttpHeaderLine;
use Kekos\MultipartFormDataParser\ParserException;
use PHPUnit\Framework\TestCase;

class HttpHeaderLineTest extends TestCase
{
    public function testGetName(): void
    {
        $expected = 'Foo';

        $header = new HttpHeaderLine(sprintf('%s: bar', $expected));

        $this->assertEquals($expected, $header->getName());
    }

    public function testThrowsWhitespaceBeforeColon(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('HTTP header field name must not end with whitespace');

        new HttpHeaderLine('Foo : bar');
    }

    public function testGetValue(): void
    {
        $expected = 'bar';

        $header = new HttpHeaderLine(sprintf('Foo: %s', $expected));

        $this->assertEquals($expected, $header->getValue());
    }

    public function testThrowsMissingValue(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('HTTP header field value missing');

        new HttpHeaderLine('Foo');
    }

    public function testGetKeyValue(): void
    {
        $expected = ' v1 ';

        $header = new HttpHeaderLine(sprintf('Foo: bar; k1="%s"', $expected));

        $this->assertEquals($expected, $header->getKeyValue('k1'));
    }
}
