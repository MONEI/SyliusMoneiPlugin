<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Functional;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Captures records of the `monei` channel. State is static so records survive
 * the kernel reboot between KernelBrowser requests.
 */
final class MoneiLogHandler extends AbstractProcessingHandler
{
    /** @var list<array{level: Level, message: string, text: string}> */
    private static array $records = [];

    public function __construct()
    {
        parent::__construct(Level::Debug);
    }

    public static function clear(): void
    {
        self::$records = [];
    }

    /** @return list<array{level: Level, message: string, text: string}> */
    public static function records(): array
    {
        return self::$records;
    }

    protected function write(LogRecord $record): void
    {
        self::$records[] = [
            'level' => $record->level,
            'message' => $record->message,
            // The whole record as text, exception chains included, to search for leaked secrets.
            'text' => $record->message.' '.self::flatten($record->context).' '.self::flatten($record->extra),
        ];
    }

    private static function flatten(mixed $value): string
    {
        if ($value instanceof \Throwable) {
            $text = '';
            for ($e = $value; null !== $e; $e = $e->getPrevious()) {
                $text .= $e::class.': '.$e->getMessage().' ';
            }

            return $text;
        }

        if (\is_array($value)) {
            return implode(' ', array_map(
                static fn (mixed $item, int|string $key): string => $key.'='.self::flatten($item),
                $value,
                array_keys($value),
            ));
        }

        if (\is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        // As Monolog's NormalizerFormatter does, which JSON-encodes an object's public properties.
        return get_debug_type($value).' '.json_encode($value, \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
