<?php

namespace App\Service\Calendar;

/**
 * Minimal iCalendar (RFC 5545) writer for all-day events: escaping, 75-octet line folding and CRLF
 * line endings, which Apple Calendar, Google Calendar and Outlook all require.
 */
final class IcsWriter
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(string $calendarName, string $refreshInterval = 'PT6H')
    {
        $this->lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Storno.ro//Calendar feed//RO',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape($calendarName),
            'X-WR-TIMEZONE:Europe/Bucharest',
            'REFRESH-INTERVAL;VALUE=DURATION:' . $refreshInterval,
            'X-PUBLISHED-TTL:' . $refreshInterval,
        ];
    }

    /**
     * @param list<int> $alarmDaysBefore alarms at 09:00, this many days before the event (0 = the same day)
     */
    public function addAllDayEvent(
        string $uid,
        \DateTimeImmutable $date,
        string $summary,
        string $description = '',
        ?string $url = null,
        array $alarmDaysBefore = [],
        ?\DateTimeImmutable $stamp = null,
    ): void {
        $stamp ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->lines[] = 'BEGIN:VEVENT';
        $this->lines[] = 'UID:' . $uid;
        $this->lines[] = 'DTSTAMP:' . $stamp->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $this->lines[] = 'DTSTART;VALUE=DATE:' . $date->format('Ymd');
        $this->lines[] = 'DTEND;VALUE=DATE:' . $date->modify('+1 day')->format('Ymd');
        $this->lines[] = 'SUMMARY:' . self::escape($summary);
        if ($description !== '') {
            $this->lines[] = 'DESCRIPTION:' . self::escape($description);
        }
        if ($url !== null) {
            $this->lines[] = 'URL:' . $url;
        }
        $this->lines[] = 'TRANSP:TRANSPARENT';
        foreach (array_values(array_unique($alarmDaysBefore)) as $days) {
            $this->lines[] = 'BEGIN:VALARM';
            $this->lines[] = 'ACTION:DISPLAY';
            $this->lines[] = 'DESCRIPTION:' . self::escape($summary);
            $this->lines[] = 'TRIGGER:' . self::trigger($days);
            $this->lines[] = 'END:VALARM';
        }
        $this->lines[] = 'END:VEVENT';
    }

    public function render(): string
    {
        $out = '';
        foreach ([...$this->lines, 'END:VCALENDAR'] as $line) {
            $out .= self::fold($line) . "\r\n";
        }

        return $out;
    }

    public static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\\,', '\\n', '\\n', '\\n'], $text);
    }

    /** An all-day event starts at 00:00, so 09:00 `$days` days before is (days - 1) days and 15 hours earlier. */
    public static function trigger(int $days): string
    {
        if ($days <= 0) {
            return 'PT9H';
        }

        return $days === 1 ? '-PT15H' : sprintf('-P%dDT15H', $days - 1);
    }

    /** Lines longer than 75 octets continue on the next line after a space, never splitting a UTF-8 character. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $parts = [];
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $parts[] = $current;
                $current = '';
                $limit = 74; // continuation lines start with a space
            }
            $current .= $char;
        }
        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
