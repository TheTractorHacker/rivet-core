<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Tests\Support\TestRedis;

/**
 * Proves the kit CHECKS what it claims to: for every rule there is an adapter that breaks exactly that rule, and the case
 * must report a failure in the test(s) named here. A kit that passes everything proves nothing.
 *
 * Each row: case class, flaw, test method name(s) that must fail ("method" matches "method" and "method#dataset").
 */
final class KitMutationTest extends TestCase
{
    /** @return array<string,array{class-string<TestCase>,string,list<string>}> */
    public static function mutants(): array
    {
        $rows = [
            // Clock
            [ClockKitTest::class, 'backwards', ['testSuccessiveCallsNeverGoBackwards']],
            [ClockKitTest::class, 'skewed', ['testRealClockIsCloseToSystemTime']],
            [ClockKitTest::class, 'wrong_tz', ['testTimezoneIsValidAndConsistent']],
            [ClockKitTest::class, 'epoch', ['testNowIsAnImmutableInstantUnaffectedByLaterCalls', 'testRealClockIsCloseToSystemTime']],
            // Settings
            [SettingsKitTest::class, 'falsy_to_default', ['testStoredValuesAreReturnedEvenWhenFalsy']],
            [SettingsKitTest::class, 'coerces_default', ['testUnknownKeyReturnsTheDefaultUnchanged', 'testDefaultDefaultsToNull']],
            [SettingsKitTest::class, 'ignores_default', ['testUnknownKeyReturnsTheDefaultUnchanged', 'testOddKeysNeverThrowAndReadAsDefault']],
            [SettingsKitTest::class, 'throws_odd', ['testOddKeysNeverThrowAndReadAsDefault']],
            [SettingsKitTest::class, 'flaky', ['testReadsAreRepeatable']],
            [SettingsKitTest::class, 'default_leaks', ['testStoredValueDoesNotDependOnTheDefaultPassedIn']],
            // Request context
            [RequestContextKitTest::class, 'trust_client_id', ['testClientSuppliedRequestIdIsNeverTrusted']],
            [RequestContextKitTest::class, 'unstable_id', ['testRequestIdIsStableWithinARequest', 'testNoRequestAtAllNeverThrows']],
            [RequestContextKitTest::class, 'ip_placeholder', ['testIpAddressIsNullOrAValidAddressLiteral']],
            [RequestContextKitTest::class, 'throws_cli', ['testNoRequestAtAllNeverThrows']],
            [RequestContextKitTest::class, 'long_id', ['testRequestIdIsNullOrABoundedToken']],
            [RequestContextKitTest::class, 'bad_id_chars', ['testRequestIdIsNullOrABoundedToken']],
            [RequestContextKitTest::class, 'ctrl_ua', ['testUserAgentIsNullOrPrintableText']],
            // Redis (the "down" rules need no Redis; the live rules are checked when a throwaway Redis is configured)
            [RedisKitTest::class, 'throws_when_down', ['testUnreachableRedisYieldsNullAndNeverThrows']],
            [RedisKitTest::class, 'lazy_client_when_down', ['testUnreachableRedisYieldsNullAndNeverThrows']],
            // Webhook subscriptions
            [WebhookKitTest::class, 'includes_disabled', ['testDisabledEndpointsAreExcluded', 'testStarSubscribesToEveryEvent']],
            [WebhookKitTest::class, 'includes_deleted', ['testDeletedEndpointsAreExcluded']],
            [WebhookKitTest::class, 'prefix_match', ['testNearMissAndHostileEventNamesMatchNothingSeeded']],
            [WebhookKitTest::class, 'no_patterns', ['testStarSubscribesToEveryEvent', 'testPrefixPatternMatchesItsGroupOnly', 'testPatternsAndPlainEventsMixInOneSubscription']],
            [WebhookKitTest::class, 'greedy_star', ['testPrefixPatternMatchesItsGroupOnly']],
            [WebhookKitTest::class, 'encrypted_secret', ['testEnabledEndpointsForAnEventAreReturnedWithIdUrlAndPlainSecret', 'testFindReturnsAnEnabledEndpointLikeForEvent']],
            [WebhookKitTest::class, 'find_disabled', ['testFindReturnsNullForUnknownDisabledAndDeletedEndpoints']],
            [WebhookKitTest::class, 'like_wildcards', ['testNearMissAndHostileEventNamesMatchNothingSeeded']],
            [WebhookKitTest::class, 'keyed_list', ['testEventListsWithSeveralEventsMatchEachOfThem']],
            // Ticket <-> problem link
            [TicketProblemLinkKitTest::class, 'unlink_any', ['testUnlinkOnlyRemovesTheLinkToThatProblem']],
            [TicketProblemLinkKitTest::class, 'no_replace', ['testLinkingToAnotherProblemReplacesTheLink']],
            [TicketProblemLinkKitTest::class, 'throws_unknown', ['testUnknownTicketNeverThrows']],
            [TicketProblemLinkKitTest::class, 'creates_unknown', ['testUnknownTicketNeverThrows']],
            [TicketProblemLinkKitTest::class, 'unlink_all', ['testLinksAreIsolatedPerTicket']],
            [TicketProblemLinkKitTest::class, 'link_all', ['testLinksAreIsolatedPerTicket']],
            // Agent directory
            [AgentDirectoryKitTest::class, 'inactive_linkable', ['testInactiveAgentsAreNotLinkableAndNotActive']],
            [AgentDirectoryKitTest::class, 'inactive_found', ['testInactiveAgentsAreNotLinkableAndNotActive']],
            [AgentDirectoryKitTest::class, 'link_ignores_issuer', ['testIdentityIsMatchedAsAnIssuerSubjectPair']],
            [AgentDirectoryKitTest::class, 'unlink_keeps_identity', ['testUnlinkReversesTheLink']],
            [AgentDirectoryKitTest::class, 'linked_still_linkable', ['testLinkMovesTheAgentFromLinkableToLinked']],
            [AgentDirectoryKitTest::class, 'count_wrong', ['testLinkMovesTheAgentFromLinkableToLinked']],
            [AgentDirectoryKitTest::class, 'find_throws_unknown', ['testUnknownUserIdIsNullNotAnError']],
            [AgentDirectoryKitTest::class, 'link_unknown_creates', ['testUnlinkAndLinkForUnknownOrUnlinkedUsersAreHarmless']],
            [AgentDirectoryKitTest::class, 'find_linked_false', ['testLinkMovesTheAgentFromLinkableToLinked']],
            // Access policy
            [AccessPolicyKitTest::class, 'throws_unknown_subject', ['testNeverThrowsForAnyUserAbilitySubjectOrContext']],
            [AccessPolicyKitTest::class, 'null_user_throws', ['testNullUserIsAcceptedAsTheSystemActor']],
            [AccessPolicyKitTest::class, 'system_bypass', ['testUnknownAbilitiesAreDeniedForEveryUser', 'testTheEditionsSampleDecisionsHold']],
            [AccessPolicyKitTest::class, 'context_elevates', ['testContextCannotElevateAnUnknownAbility']],
            [AccessPolicyKitTest::class, 'nondeterministic', ['testTheSameQuestionGetsTheSameAnswer']],
            [AccessPolicyKitTest::class, 'ignores_user', ['testTheEditionsSampleDecisionsHold']],
            [AccessPolicyKitTest::class, 'allow_all', ['testUnknownAbilitiesAreDeniedForEveryUser', 'testTheEditionsSampleDecisionsHold']],
            // Attestations
            [AttestationKitTest::class, 'first_wins', ['testTheLastReviewRecordedWins', 'testItemsAreIndependentAndOnePerItem']],
            [AttestationKitTest::class, 'max_date_wins', ['testTwoReviewsOnTheSameDayStillPickTheLastRecorded']],
            [AttestationKitTest::class, 'empty_strings', ['testMissingNextDueAndNoteAreNullNotEmptyStrings']],
            [AttestationKitTest::class, 'extra_key', ['testAReviewIsReturnedWithTheDocumentedShape']],
        ];
        $out = [];
        foreach ($rows as [$class, $flaw, $tests]) {
            $out[substr(strrchr($class, '\\') ?: $class, 1) . ' / ' . $flaw] = [$class, $flaw, $tests];
        }

        return $out;
    }

    /** @return array<string,array{class-string<TestCase>,string,list<string>}> flaws that need a live Redis to be seen */
    public static function liveRedisMutants(): array
    {
        return [
            'null_when_up' => [RedisKitTest::class, 'null_when_up', ['testLiveProviderReturnsAWorkingPredisClient', 'testSetGetDeleteRoundTrip']],
            'other_database' => [RedisKitTest::class, 'other_database', ['testClientIsUsableOnRepeatedCalls']],
        ];
    }

    /**
     * @param class-string<TestCase> $class
     * @param list<string> $mustFail
     */
    #[DataProvider('mutants')]
    public function testBrokenAdapterIsCaught(string $class, string $flaw, array $mustFail): void
    {
        $this->assertCaught($class, $flaw, $mustFail);
    }

    /**
     * @param class-string<TestCase> $class
     * @param list<string> $mustFail
     */
    #[DataProvider('liveRedisMutants')]
    public function testBrokenRedisAdapterIsCaughtAgainstALiveRedis(string $class, string $flaw, array $mustFail): void
    {
        if (!TestRedis::available()) {
            $this->markTestSkipped('RIVETCORE_TEST_REDIS_PORT not set (throwaway Redis required).');
        }
        $this->assertCaught($class, $flaw, $mustFail);
    }

    /**
     * @param class-string<TestCase> $class
     * @param list<string> $mustFail
     */
    private function assertCaught(string $class, string $flaw, array $mustFail): void
    {
        $clean = Harness::run($class, null);
        $this->assertSame([], $clean['failed'], 'the reference adapter must pass before a flaw can prove anything');
        $this->assertNotSame([], $clean['passed']);

        $run = Harness::run($class, $flaw);
        $failedMethods = array_unique(array_map(fn (string $label) => explode('#', $label)[0], array_keys($run['failed'])));
        $this->assertNotSame([], $failedMethods, "flaw '$flaw' was NOT caught by any test of " . $class);
        foreach ($mustFail as $method) {
            $this->assertContains($method, $failedMethods, "flaw '$flaw' should fail $method but only failed: " . implode(', ', $failedMethods));
        }
    }

    public function testHarnessSeparatesPassSkipAndFailure(): void
    {
        $run = Harness::run(SettingsKitTest::class, 'throws_odd');
        $this->assertNotSame([], $run['passed']);
        $this->assertNotSame([], $run['failed']);
        $this->assertNull(SettingsKitTest::$flaw, 'the harness must restore the flaw');
    }
}
