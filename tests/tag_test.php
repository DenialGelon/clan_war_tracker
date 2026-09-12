<?php
declare(strict_types=1);

function test_tag_normalize_strips_hash_and_uppercases(): void
{
    assert_same('JUQRRL8', Tag::normalize('#juqrrl8'));
    assert_same('JUQRRL8', Tag::normalize('  #JUQRRL8  '));
    assert_same('JUQRRL8', Tag::normalize('JUQRRL8'));
}

function test_tag_normalize_turns_letter_o_into_zero(): void
{
    assert_same('P00J90PJ', Tag::normalize('#POOJ9OPJ'));
    assert_same('RG0GUVJG', Tag::normalize('rgOguvjg'));
}

function test_tag_validation(): void
{
    assert_true(Tag::isValid('JUQRRL8'));
    assert_true(Tag::isValid('P00J90PJ'));
    assert_true(!Tag::isValid(''), 'empty tag');
    assert_true(!Tag::isValid('ABC'), 'A and B are not in the alphabet');
    assert_true(!Tag::isValid('JUQ RRL8'), 'space');
    assert_true(!Tag::isValid(str_repeat('P', 16)), 'too long');
}

function test_tag_display_and_url(): void
{
    assert_same('#JUQRRL8', Tag::display('JUQRRL8'));
    assert_same('%23JUQRRL8', Tag::urlEncode('JUQRRL8'));
}
