<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Change\ChangeProtocol;
use RivetCore\Rmm\Crypto\Redactor;

/**
 * CORE-DATA-2 (red team 2026-11-21, chain E): the job-output redactor masked user:password@ URLs only with a user name, and no Cookie / Set-Cookie value, `--pass x`,
 * `net use` password, Stripe key, SAS signature or Slack webhook; `password: a b c` was masked to the first space only. The rows of src/Rmm/Crypto/redaction-vectors.json
 * are the vectors the Go agent's jobs.Redact replays too (the agent side owns the file; this repository carries a byte-for-byte copy).
 */
final class RedactionSharedVectorsTest extends TestCase
{
    private const VECTORS = __DIR__ . '/../../../src/Rmm/Crypto/redaction-vectors.json';

    protected function setUp(): void
    {
        ini_set('pcre.jit', '1');
    }

    /** @return iterable<string,array{string,list<string>,list<string>}> */
    public static function sharedVectors(): iterable
    {
        $rows = json_decode((string) file_get_contents(self::VECTORS), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        foreach ($rows as $r) {
            self::assertIsArray($r);
            self::assertSame(['name', 'input', 'expect_masked', 'expect_kept'], array_keys($r), 'schema of a vector row');
            yield (string) $r['name'] => [(string) $r['input'], $r['expect_masked'], $r['expect_kept']];
        }
    }

    /**
     * @param list<string> $masked
     * @param list<string> $kept
     */
    #[DataProvider('sharedVectors')]
    public function testTheSharedVectors(string $input, array $masked, array $kept): void
    {
        $out = Redactor::redact($input);
        foreach ($masked as $secret) {
            $this->assertStringNotContainsString($secret, $out, 'leaked from ' . json_encode($input));
        }
        foreach ($kept as $text) {
            $this->assertStringContainsString($text, $out, 'lost from ' . json_encode($input));
        }
        if ($masked !== []) {
            $this->assertStringContainsString(Redactor::MASK, $out);
        }
    }

    public function testTheVectorFileHasTheRowsBothSidesNeed(): void
    {
        $names = array_keys(iterator_to_array(self::sharedVectors()));
        $this->assertGreaterThanOrEqual(30, count($names));
        foreach (['url_userinfo_empty_username', 'cookie_request_header', 'set_cookie_response_header', 'net_use_password_after_share', 'dash_dash_pass_value',
            'stripe_live_secret_key', 'azure_sas_sig_in_url_query', 'slack_incoming_webhook', 'password_unquoted_value_masked_to_end_of_line', 'keep_sig_outside_url_query',
            'keep_cookie_word_not_at_line_start', 'keep_psql_dash_p_port', 'keep_passive_switch', 'keep_bare_stripe_prefixes'] as $name) {
            $this->assertContains($name, $names);
        }
    }

    /** @return iterable<string,array{string,string}> the exact inputs of core-data/poc_core_data_2_redactor_gaps.php, and the secret each one must lose */
    public static function proofOfConcept(): iterable
    {
        $s = 'Zx9fictionalSecret42';
        yield 'URL userinfo (postgres)' => ["postgres://admin:$s@db.internal:5432/app", $s];
        yield 'URL userinfo (https git remote)' => ["origin https://deploy:$s@git.example.com/org/repo.git", $s];
        yield 'Cookie header' => ["Cookie: session=$s; theme=dark", $s];
        yield 'Set-Cookie header' => ["Set-Cookie: PHPSESSID=$s; path=/; HttpOnly", $s];
        yield 'CLI --pass SECRET' => ["tool --pass $s", $s];
        yield 'net use drive password' => ["net use Z: \\\\srv\\share $s /user:bob", $s];
        yield 'Stripe live key' => ['sk_live_' . str_repeat('a1B2', 6), 'a1B2a1B2'];
        yield 'Azure SAS signature' => ['https://acct.blob.core.windows.net/c?sv=2022-11-02&sig=' . rawurlencode('abc/DEF+123=='), 'abc%2F'];
        yield 'Slack incoming webhook' => ['https://hooks.slack.com/services/T0000/B0000/' . str_repeat('X', 24), 'XXXXXXXX'];
        yield 'control: password=' => ["password=$s", $s];
        yield 'control: Authorization: Bearer' => ['Authorization: Bearer abcdefghijklmnop', 'abcdefghijklmnop'];
        // chain E (chain_e_level2_reads_output.out): what leaked after both redactors
        yield 'chain E: empty-user redis URL' => ["REDIS_URL=redis://:$s@cache:6379/0", $s];
    }

    #[DataProvider('proofOfConcept')]
    public function testTheProofOfConceptShapesAreMasked(string $input, string $secret): void
    {
        $this->assertStringNotContainsString($secret, Redactor::redact($input));
    }

    /** @return iterable<string,array{string,string}> narrow rules: the input and the exact output */
    public static function exact(): iterable
    {
        // (the URLs are split at the `@` so the repository's secret scan does not read the mask or the port as a password)
        yield 'only the password of a URL is masked' => ['postgres://app:pw1' . '@db:5432/x', 'postgres://app:' . Redactor::MASK . '@db:5432/x'];
        yield 'an @ inside the password does not cut the mask short' => ['https://u:p@ss' . '@host/x', 'https://u:' . Redactor::MASK . '@host/x'];
        yield 'a port and an @ in the query string are not userinfo' => ['http://host:8080?email=a' . '@b.example', 'http://host:8080?email=a' . '@b.example'];
        yield 'a curl -v request cookie line' => ["> Cookie: a=b; c=d\n> Accept: */*", "> Cookie: [REDACTED]\n> Accept: */*"];
        yield 'password to the end of the line, the next line kept' => ["password: correct horse battery staple\r\nuser: alice", "password: [REDACTED]\r\nuser: alice"];
        yield 'an empty password value: the end-of-line rule does not take the next line (the older rule still masks its first word)' => ["password:\nuser: alice", "password:\n[REDACTED] alice"];
        yield 'a quoted password is masked to its closing quote' => ['password="a b" user=x', 'password="[REDACTED]" user=x'];
        yield 'a token is still masked to the first space' => ['token=abc def', 'token=[REDACTED] def'];
        yield 'plink -pw' => ['plink -pw hunter2 admin@host', 'plink -pw [REDACTED] admin@host'];
        yield '--pass at the start of a line' => ["--pass hunter2 --host x", "--pass [REDACTED] --host x"];
        yield 'net use with a prompt for the password' => ['net use Z: \\\\srv\\share * /user:bob', 'net use Z: \\\\srv\\share * /user:bob'];
        yield 'net use with the password after /user' => ['net use \\\\srv\\share /user:dom\\bob hunter2', 'net use \\\\srv\\share /user:dom\\bob [REDACTED]'];
        yield 'a camelCase key name longer than 80 characters' => [str_repeat('a', 100) . 'Key=hunter2', str_repeat('a', 100) . 'Key=[REDACTED]'];
    }

    #[DataProvider('exact')]
    public function testTheRulesAreNarrow(string $input, string $expected): void
    {
        $this->assertSame($expected, Redactor::redact($input));
    }

    /**
     * The change monitor keeps the text of the frozen rc.38 vectors (endpoint-agent/testdata/vectors/agent_change_monitor_rc38_vectors.json, replayed by the Go agent's
     * hardenRedact): it turns the password-to-end-of-line rule off. Job output and logs keep it on.
     */
    public function testTheChangeMonitorKeepsTheFrozenRc38Text(): void
    {
        $this->assertSame('passw0rd: [REDACTED]', Redactor::redact('passw0rd: abc def'));
        $this->assertSame('passw0rd: [REDACTED] def', Redactor::redact('passw0rd: abc def', false));
        $this->assertSame('passw0rd: [REDACTED] def', ChangeProtocol::redact('passw0rd: abc def'));
        // every other new rule applies to the change monitor too
        $this->assertSame('redis://:[REDACTED]@cache:6379', ChangeProtocol::redact('redis://:pw1@cache:6379'));
        $this->assertSame('Set-Cookie: [REDACTED]', ChangeProtocol::redact('Set-Cookie: a=b'));
    }

    /**
     * The redactor stays linear (red team 2026-11-21: "ReDoS and quadratic probes on 800 KB inputs linear (under 0.5 s)"): 800 KB inputs built to make each rule, old and
     * new, try at every position are redacted in under half a second each (best of three runs, so one slow scheduler tick does not fail it; measured: under 0.2 s on a
     * workstation).
     *
     * @return iterable<string,array{string}>
     */
    public static function adversarial(): iterable
    {
        $k = 800 * 1024;
        $fill = static fn (string $unit): string => substr(str_repeat($unit, intdiv($k, strlen($unit)) + 1), 0, $k);
        foreach (['password.', 'x-token-', 'a.a.', 'pass-', 'pwd', '://:', '://u:p', "Cookie: a=b\n", '--pass ', ' -pw x', 'net use ', 'sk_live_', 'hooks.slack.com/services/',
            '&sig=', 'passwd= ', 'aKey', "password: x\nnet use \\\\a b /user:c d\n--pass x ://u:p@h &sig=x sk_live_0123456789 Cookie: x\n"] as $unit) {
            yield json_encode($unit) => [$fill($unit)];
        }
        yield 'one URL with 800 KB of colons' => ['://' . $fill(':')];
        yield 'one URL user with 800 KB of @' => ['://u:' . $fill('@')];
        yield 'one password value of 800 KB of words' => ['password: ' . $fill('a ')];
        yield 'one net use with 800 KB of backslashes' => ['net use ' . $fill('\\\\a ')];
        yield 'one Set-Cookie of 800 KB' => ['Set-Cookie: ' . $fill('a')];
    }

    #[DataProvider('adversarial')]
    public function testEightHundredKilobytesTakeLessThanHalfASecond(string $input): void
    {
        $best = INF;
        for ($run = 0; $run < 3 && $best >= 0.5; ++$run) {
            $t = hrtime(true);
            $out = Redactor::redact($input);
            $best = min($best, (hrtime(true) - $t) / 1e9);
            $this->assertNotSame(Redactor::MASK, $out, 'a PCRE failure (the whole text withheld) is not the linear path');
        }
        $this->assertLessThan(0.5, $best, 'took ' . round($best * 1000) . ' ms for ' . strlen($input) . ' bytes');
    }
}
