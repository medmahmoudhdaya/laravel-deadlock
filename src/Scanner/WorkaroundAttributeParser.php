<?php

declare(strict_types=1);

namespace Zidbih\Deadlock\Scanner;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PhpParser\Node\Attribute as PhpAttribute;
use PhpParser\Node\Scalar\String_;

final class WorkaroundAttributeParser
{
    private const ARGUMENT_COUNT_MESSAGE = 'Workaround attribute must receive exactly 2 arguments.';

    private const STRICT_ARGUMENT_COUNT_MESSAGE = 'Workaround attribute must receive exactly 2 arguments: description and expires.';

    public static function parse(PhpAttribute $attribute): ParsedWorkaroundAttribute
    {
        $issues = self::validate($attribute, strictArgumentCountMessage: true);

        if ($issues !== []) {
            throw new InvalidArgumentException($issues[0]);
        }

        return new ParsedWorkaroundAttribute(
            description: $attribute->args[0]->value->value,
            expires: $attribute->args[1]->value->value,
        );
    }

    /**
     * @return string[]
     */
    public static function validate(PhpAttribute $attribute, bool $strictArgumentCountMessage = false): array
    {
        if (count($attribute->args) !== 2) {
            return [
                $strictArgumentCountMessage
                    ? self::STRICT_ARGUMENT_COUNT_MESSAGE
                    : self::ARGUMENT_COUNT_MESSAGE,
            ];
        }

        $issues = [];
        $description = $attribute->args[0]->value;
        $expires = $attribute->args[1]->value;

        if (! $description instanceof String_) {
            $issues[] = 'Workaround description must be a string literal.';
        }

        if (! $expires instanceof String_) {
            $issues[] = 'Workaround expires must be a string literal in YYYY-MM-DD format.';

            return $issues;
        }

        $dateIssue = self::validateDate($expires->value);

        if ($dateIssue !== null) {
            $issues[] = $dateIssue;
        }

        return $issues;
    }

    private static function validateDate(string $expires): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
            return "Invalid expires date '{$expires}'. Expected YYYY-MM-DD.";
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return "Invalid expires date '{$expires}'.";
        }

        return null;
    }
}
