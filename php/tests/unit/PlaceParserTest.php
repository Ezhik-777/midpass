<?php

declare(strict_types=1);

use App\Queue\PlaceParser;

test('PlaceParser: int passes through', function () {
    assert_eq(106, PlaceParser::parse(106));
});

test('PlaceParser: numeric string', function () {
    assert_eq(106, PlaceParser::parse('106'));
});

test('PlaceParser: localized "Место 106"', function () {
    assert_eq(106, PlaceParser::parse('Место 106'));
});

test('PlaceParser: first digits win', function () {
    assert_eq(3, PlaceParser::parse('Место 3 из 100'));
});

test('PlaceParser: null for null', function () {
    assert_eq(null, PlaceParser::parse(null));
});

test('PlaceParser: null for empty string', function () {
    assert_eq(null, PlaceParser::parse(''));
});

test('PlaceParser: null for non-digit string', function () {
    assert_eq(null, PlaceParser::parse('нет данных'));
});
