<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\Authentication;

final class AuthenticationTest extends TestCase
{
    /** @return iterable<string,array{array<string,mixed>,bool}> */
    public static function validationTable(): iterable
    {
        yield 'none' => [['mode' => 'none'], true];
        yield 'default mode' => [[], true];
        yield 'hmac' => [['mode' => 'hmac'], true];
        yield 'unknown mode' => [['mode' => 'oauth'], false];
        yield 'bearer ok' => [['mode' => 'bearer', 'token' => 'tk_abc.DEF-123'], true];
        yield 'bearer empty' => [['mode' => 'bearer', 'token' => ''], false];
        yield 'bearer space' => [['mode' => 'bearer', 'token' => 'a b'], false];
        yield 'bearer crlf' => [['mode' => 'bearer', 'token' => "a\r\nX-Evil: 1"], false];
        yield 'bearer too long' => [['mode' => 'bearer', 'token' => str_repeat('a', 2049)], false];
        yield 'basic ok' => [['mode' => 'basic', 'username' => 'bob', 'password' => 'p:ss w0rd'], true];
        yield 'basic empty password ok' => [['mode' => 'basic', 'username' => 'bob', 'password' => ''], true];
        yield 'basic no user' => [['mode' => 'basic', 'username' => '', 'password' => 'x'], false];
        yield 'basic colon user' => [['mode' => 'basic', 'username' => 'a:b', 'password' => 'x'], false];
        yield 'basic crlf password' => [['mode' => 'basic', 'username' => 'a', 'password' => "x\ny"], false];
        yield 'basic long password' => [['mode' => 'basic', 'username' => 'a', 'password' => str_repeat('p', 1025)], false];
        yield 'header ok' => [['mode' => 'header', 'header_name' => 'X-Gotify-Key', 'header_value' => 'abc'], true];
        yield 'header authorization ok' => [['mode' => 'header', 'header_name' => 'Authorization', 'header_value' => 'Token abc'], true];
        yield 'header no name' => [['mode' => 'header', 'header_name' => '', 'header_value' => 'abc'], false];
        yield 'header no value' => [['mode' => 'header', 'header_name' => 'X-A', 'header_value' => ''], false];
        yield 'header name space' => [['mode' => 'header', 'header_name' => 'X A', 'header_value' => 'abc'], false];
        yield 'header name colon' => [['mode' => 'header', 'header_name' => 'X-A:', 'header_value' => 'abc'], false];
        yield 'header name crlf' => [['mode' => 'header', 'header_name' => "X-A\r\nX-B", 'header_value' => 'abc'], false];
        yield 'header name unicode' => [['mode' => 'header', 'header_name' => 'X-Ü', 'header_value' => 'abc'], false];
        yield 'header name long' => [['mode' => 'header', 'header_name' => str_repeat('A', 65), 'header_value' => 'abc'], false];
        yield 'header value crlf' => [['mode' => 'header', 'header_name' => 'X-A', 'header_value' => "a\r\nX-Evil: 1"], false];
        yield 'header value nul' => [['mode' => 'header', 'header_name' => 'X-A', 'header_value' => "a\0b"], false];
        yield 'header value long' => [['mode' => 'header', 'header_name' => 'X-A', 'header_value' => str_repeat('v', 2049)], false];
        foreach (['Host', 'content-length', 'Content-Type', 'Transfer-Encoding', 'Connection', 'Keep-Alive', 'TE', 'Upgrade', 'Expect', 'Proxy-Authorization', 'X-Rivet-Timestamp', 'X-Rivet-Signature-V2', 'X-RivetIT-Signature', 'X-ITFlow-Signature', 'X-Hub-Signature'] as $name) {
            yield 'forbidden ' . $name => [['mode' => 'header', 'header_name' => $name, 'header_value' => 'x'], false];
        }
    }

    /** @param array<string,mixed> $config */
    #[DataProvider('validationTable')]
    public function testValidate(array $config, bool $valid): void
    {
        $errors = Authentication::validate($config);
        self::assertSame($valid, $errors === [], json_encode($errors) ?: '');
        if (!$valid) {
            try {
                Authentication::headers($config);
                self::fail('headers() must reject an invalid config');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testHeaders(): void
    {
        self::assertSame([], Authentication::headers(['mode' => 'none']));
        self::assertSame([], Authentication::headers(['mode' => 'hmac']));
        self::assertSame(['Authorization' => 'Bearer tk_1'], Authentication::headers(['mode' => 'bearer', 'token' => 'tk_1']));
        self::assertSame(['Authorization' => 'Basic ' . base64_encode('bob:p:w')], Authentication::headers(['mode' => 'basic', 'username' => 'bob', 'password' => 'p:w']));
        self::assertSame(['X-Gotify-Key' => 'abc'], Authentication::headers(['mode' => 'header', 'header_name' => 'X-Gotify-Key', 'header_value' => 'abc']));
    }

    public function testRedactNeverEchoesSecrets(): void
    {
        $r = Authentication::redact(['mode' => 'basic', 'token' => 'TOK', 'username' => 'bob', 'password' => 'PW', 'header_name' => 'X-K', 'header_value' => 'HV', 'extra' => 'ignored']);
        self::assertSame(['mode' => 'basic', 'token' => '********', 'username' => 'bob', 'password' => '********', 'header_name' => 'X-K', 'header_value' => '********'], $r);
        self::assertStringNotContainsString('TOK', json_encode($r) ?: '');
        self::assertSame('', Authentication::redact(['mode' => 'none'])['token']);
    }

    public function testHeaderNameHelpers(): void
    {
        self::assertTrue(Authentication::isValidHeaderName('X-Api_Key.1'));
        self::assertFalse(Authentication::isValidHeaderName(''));
        self::assertFalse(Authentication::isValidHeaderName('a b'));
        self::assertTrue(Authentication::isForbiddenHeaderName('hOsT'));
        self::assertFalse(Authentication::isForbiddenHeaderName('X-Gotify-Key'));
    }
}
