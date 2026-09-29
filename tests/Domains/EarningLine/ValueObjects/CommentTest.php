<?php

declare(strict_types=1);

namespace Tests\Domains\EarningLine\ValueObjects;

use App\Domains\EarningLine\Exceptions\CommentCannotBeBlank;
use App\Domains\EarningLine\ValueObjects\Comment;
use PHPUnit\Framework\TestCase;

final class CommentTest extends TestCase
{
    public function testBlankCommentIsRejected(): void
    {
        $this->expectException(CommentCannotBeBlank::class);

        new Comment('   ');
    }
}
