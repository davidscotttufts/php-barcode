<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

/**
 * An iCalendar event that phones offer to add to the calendar.
 */
final class CalendarEvent extends AbstractPayload
{
    public function __construct(
        private readonly string $summary,
        private readonly \DateTimeInterface $start,
        private readonly \DateTimeInterface $end,
        private readonly ?string $location = null,
        private readonly ?string $description = null,
    ) {
        if ($end < $start) {
            throw new \InvalidArgumentException('An event cannot end before it starts.');
        }
    }

    public function toString(): string
    {
        $utc = new \DateTimeZone('UTC');
        $format = static fn(\DateTimeInterface $date): string => \DateTimeImmutable::createFromInterface($date)->setTimezone($utc)->format('Ymd\THis\Z');
        $escape = static fn(string $v): string => str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\;'], $v);

        $lines = ['BEGIN:VEVENT', 'SUMMARY:' . $escape($this->summary), 'DTSTART:' . $format($this->start), 'DTEND:' . $format($this->end)];
        if ($this->location !== null) {
            $lines[] = 'LOCATION:' . $escape($this->location);
        }
        if ($this->description !== null) {
            $lines[] = 'DESCRIPTION:' . $escape($this->description);
        }
        $lines[] = 'END:VEVENT';

        return implode("\r\n", $lines);
    }
}
