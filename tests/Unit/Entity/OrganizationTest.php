<?php

declare(strict_types=1);

namespace Buddy\Repman\Tests\Unit\Entity;

use Buddy\Repman\Entity\Organization;
use Buddy\Repman\Entity\Organization\Member;
use Buddy\Repman\Entity\Organization\Token;
use Buddy\Repman\Entity\User;
use Buddy\Repman\Entity\User\OAuthToken;
use Buddy\Repman\Tests\MotherObject\PackageMother;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OrganizationTest extends TestCase
{
    private Organization $org;
    private User $owner;

    protected function setUp(): void
    {
        $this->org = new Organization(Uuid::uuid4(), $this->owner = new User(Uuid::uuid4(), 'admin@buddy.works', Uuid::uuid4()->toString(), []), 'Buddy', 'buddy');
    }

    public function testOrganizationAddSameToken(): void
    {
        $token = new Token('secret', 'prod');

        $this->org->addToken($token);
        $this->org->addToken($token); // this should not throw exception

        $this->expectException(\RuntimeException::class);
        $token->setOrganization($this->org);
    }

    public function testOrganizationAddSamePackage(): void
    {
        $package = PackageMother::some();

        $this->org->addPackage($package);
        $this->org->addPackage($package); // this should not throw exception

        $this->expectException(\RuntimeException::class);
        $package->setOrganization($this->org);
    }

    public function testPreventDoubleInvitation(): void
    {
        $this->org->inviteUser('some@buddy.works', Member::ROLE_MEMBER, 'token');
        $this->org->inviteUser('some@buddy.works', Member::ROLE_MEMBER, 'token');

        $this->expectException(\InvalidArgumentException::class);
        $this->org->inviteUser('other@buddy.works', 'invalid-role', 'token');
    }

    public function testAcceptMissingInvitation(): void
    {
        $this->org->acceptInvitation('not-exist', new User(Uuid::uuid4(), 'user@buddy.works', Uuid::uuid4()->toString(), []));

        $this->expectException(\InvalidArgumentException::class);
        $this->org->inviteUser('user@buddy.works', 'invalid-role', 'token');
    }

    public function testInviteMember(): void
    {
        $this->org->inviteUser('some@buddy.works', Member::ROLE_MEMBER, 'token');
        $this->org->acceptInvitation('token', new User(Uuid::uuid4(), 'some@buddy.works', Uuid::uuid4()->toString(), []));
        $this->org->inviteUser('some@buddy.works', Member::ROLE_MEMBER, 'token');

        $this->expectException(\InvalidArgumentException::class);
        $this->org->inviteUser('other@buddy.works', 'invalid-role', 'token');
    }

    public function testIgnoreWhenUserTriesToAcceptNotOwnInvitation(): void
    {
        $this->org->inviteUser('some@buddy.works', Member::ROLE_MEMBER, 'token');
        $this->org->acceptInvitation('token', new User(Uuid::uuid4(), 'bad@buddy.works', Uuid::uuid4()->toString(), []));
        $this->org->removeInvitation('token');

        self::assertTrue($this->org->inviteUser('some@buddy.works', Member::ROLE_MEMBER, 'token'));
    }

    public function testPreventToOrphanOrganizationByRemovingLastOwner(): void
    {
        $this->org->inviteUser('some@buddy.works', Member::ROLE_OWNER, 'token');
        $this->org->acceptInvitation('token', $member = new User(Uuid::uuid4(), 'some@buddy.works', Uuid::uuid4()->toString(), []));
        $this->org->removeMember($this->owner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Organisation must have at least one owner.');

        $this->org->removeMember($member);
    }

    public function testPreventToOrphanOrganizationByChangeRoleOfLastOwner(): void
    {
        $this->org->inviteUser('some@buddy.works', Member::ROLE_OWNER, 'token');
        $this->org->acceptInvitation('token', $member = new User(Uuid::uuid4(), 'some@buddy.works', Uuid::uuid4()->toString(), []));
        $this->org->changeRole($this->owner, Member::ROLE_MEMBER);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Organisation must have at least one owner.');

        $this->org->changeRole($member, Member::ROLE_MEMBER);
    }

    public function testNoOAuthTokenWhenNoOwnerAuthorizedOne(): void
    {
        self::assertNull($this->org->oauthToken(OAuthToken::TYPE_BITBUCKET));
    }

    public function testOAuthTokenOfTheTypeAsked(): void
    {
        $bitbucket = $this->authorize($this->owner, OAuthToken::TYPE_BITBUCKET, '-1 day');
        $this->authorize($this->owner, OAuthToken::TYPE_GITHUB, '-1 hour');

        self::assertSame($bitbucket, $this->org->oauthToken(OAuthToken::TYPE_BITBUCKET));
    }

    public function testNewestOwnerAuthorizationWins(): void
    {
        $this->authorize($this->owner, OAuthToken::TYPE_BITBUCKET, '-4 years');
        $newest = $this->authorize($this->addOwner('newest@buddy.works'), OAuthToken::TYPE_BITBUCKET, '-1 minute');
        $this->authorize($this->addOwner('older@buddy.works'), OAuthToken::TYPE_BITBUCKET, '-1 month');

        self::assertSame($newest, $this->org->oauthToken(OAuthToken::TYPE_BITBUCKET));
    }

    public function testTokensAuthorizedInTheSameSecondResolveToTheSameOne(): void
    {
        $sameSecond = new \DateTimeImmutable('2026-09-08 13:41:16');
        $first = $this->authorizeAt($this->addOwner('first@buddy.works'), $sameSecond);
        $second = $this->authorizeAt($this->addOwner('second@buddy.works'), $sameSecond);

        $expected = strcmp($first->id()->toString(), $second->id()->toString()) > 0 ? $first : $second;

        self::assertSame($expected, $this->org->oauthToken(OAuthToken::TYPE_BITBUCKET));
    }

    /**
     * Members are only ever ordered by the database, so a non-owner holding the newest
     * token must not take the choice away from an owner holding an older one.
     */
    public function testTokensOfPlainMembersAreIgnored(): void
    {
        $ownersToken = $this->authorize($this->owner, OAuthToken::TYPE_BITBUCKET, '-1 month');

        $this->org->inviteUser('member@buddy.works', Member::ROLE_MEMBER, 'member-token');
        $this->org->acceptInvitation('member-token', $member = new User(Uuid::uuid4(), 'member@buddy.works', Uuid::uuid4()->toString(), []));
        $this->authorize($member, OAuthToken::TYPE_BITBUCKET, '-1 minute');

        self::assertSame($ownersToken, $this->org->oauthToken(OAuthToken::TYPE_BITBUCKET));
    }

    private function addOwner(string $email): User
    {
        $this->org->inviteUser($email, Member::ROLE_OWNER, $token = 'invitation-'.$email);
        $this->org->acceptInvitation($token, $owner = new User(Uuid::uuid4(), $email, Uuid::uuid4()->toString(), []));

        return $owner;
    }

    private function authorize(User $user, string $type, string $modify): OAuthToken
    {
        return $this->authorizeAt($user, (new \DateTimeImmutable())->modify($modify), $type);
    }

    /**
     * The entity stamps createdAt itself, so a test cannot ask for an authorization that
     * happened years ago - and relying on real wall-clock ordering would make the
     * ordering assertions depend on how fine the clock is.
     */
    private function authorizeAt(User $user, \DateTimeImmutable $createdAt, string $type = OAuthToken::TYPE_BITBUCKET): OAuthToken
    {
        $token = new OAuthToken(Uuid::uuid4(), $user, $type, 'access-token', 'refresh-token');

        $property = (new \ReflectionObject($token))->getProperty('createdAt');
        $property->setAccessible(true);
        $property->setValue($token, $createdAt);

        return $token;
    }
}
