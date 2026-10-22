<?php

declare(strict_types=1);

namespace Psc\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psc\Support\Names;

/** One rule for how clients see each other's names: first name and last initial, never an address. */
final class NamesTest extends TestCase
{
    public function testAShortNameIsTheFirstNameAndTheLastInitial(): void
    {
        self::assertSame('Ada L.', Names::shortName('Ada Lovelace'));
        self::assertSame('Ada L.', Names::shortName('  Ada   Augusta  lovelace '));
        self::assertSame('Cher', Names::shortName('Cher'));
        self::assertSame('Ada L.', Names::shortName('Ada (ada@corp.test) Lovelace'), 'a word with an @ is left out');
        self::assertSame('A client', Names::shortName('jane.doe@corp.test'));
        self::assertSame('A reader', Names::shortName('', 'A reader'));
        self::assertSame("\u{00C9}mile Z.", Names::shortName("\u{00C9}mile zola"), 'the initial in upper case');
    }

    public function testInvisibleAndReorderingCharactersAreRemoved(): void
    {
        self::assertSame('Ada L.', Names::shortName("Ada\u{200B} \u{202E}Lovelace"));
        self::assertSame('A client', Names::shortName("\u{200B}\u{2066}"), 'a name of invisible characters is no name');
        self::assertSame('a b', Names::visible("a\u{FEFF}  b\u{2069}"));
    }

    public function testCommentAuthorsAndPublicNames(): void
    {
        $comment = ['user_id' => 5, 'is_staff' => 0, 'author_name' => 'Grace Hopper', 'author_gone' => 0];
        self::assertSame('You', Names::commentAuthor($comment, 5, false));
        self::assertSame('Grace H.', Names::commentAuthor($comment, 6, false));
        self::assertSame('Grace Hopper', Names::commentAuthor($comment, 6, true), 'staff see the full name');
        self::assertSame('Grace Hopper', Names::commentAuthor(['is_staff' => 1] + $comment, 6, false), 'staff are named in full');
        self::assertSame('A former client', Names::commentAuthor(['author_gone' => 1] + $comment, 6, false));
        self::assertSame('Grace H.', Names::publicName(['name' => 'Grace Hopper', 'email' => 'grace@example.test']));
        self::assertSame('A former client', Names::publicName(['name' => 'Deleted user', 'email' => 'deleted-5@deleted.invalid']));
        self::assertSame('Gone', Names::publicName(['name' => 'Deleted user', 'email' => 'deleted-5@deleted.invalid'], 'Gone'));
    }
}
