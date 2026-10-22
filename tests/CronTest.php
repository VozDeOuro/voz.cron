<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

// Semantics follow Unraid's crond: dcron 4.5 with Slackware's patches.
final class CronTest extends TestCase
{
    public static function validProvider(): array
    {
        return array_map(fn($e) => [$e], ['* * * * *', '*/5 * * * *', '0 3 * * 1', '0 */12 * * *', '15,45 8-18 * * mon-fri',
            '0 4 * * 7', '0 0 * * 0', '5-55/10 * * * *', '0 0 */2 * *', '0 0 1-31 * *', '0 0 * * 0-6', '0 0 * * 1-5', '0 0 */1 * mon']);
    }

    public static function invalidProvider(): array
    {
        return array_map(fn($e) => [$e], ['', '* * * *', '* * * * * *', '60 * * * *', '* 24 * * *', '* * 0 * *', '* * * 13 *',
            '* * * * 8', '*/0 * * * *', 'a * * * *', '* * * * mon-', '1-  * * * *', '@daily', '0 3 * * 1 /bin/x',
            "*\n* * * *", "* * * *\t*", "0 3 * * 1\n* * * * * /bin/x",
            // dom and dow both restricted: dcron reads this as "Nth weekday of the month"
            '30 2 1-7 * sun', '0 0 13 * sun', '0 0 */2 * mon', '0 0 1 * 1-5', '0 0 1-31 * mon',
            // a weekday range ending in 7 is Sunday-only (0-7) or wraps (5-7) in dcron
            '0 0 * * 0-7', '0 0 * * 5-7', '0 0 * * 7-7', '0 0 * * 0-07', '0 0 * * 5-07', '0 0 */2 * 0-7',
            // a step that leaves no real day
            '0 0 */32 * *']);
    }

    #[DataProvider('validProvider')]
    public function testValid(string $e): void { $this->assertTrue(Cron::valid($e), $e); }

    #[DataProvider('invalidProvider')]
    public function testInvalid(string $e): void { $this->assertFalse(Cron::valid($e), $e); }

    public function testErrorMessages(): void
    {
        $this->assertNull(Cron::error('0 4 * * 7'));
        $this->assertSame("weekday ranges can't end in 7 on Unraid; use 0-6 or *", Cron::error('0 0 * * 0-7'));
        $this->assertSame("weekday ranges can't end in 7 on Unraid; use 0-6 or *", Cron::error('0 0 * * mon,5-7'));
        $this->assertSame("day-of-month and day-of-week can't both be set (not supported by Unraid's cron)", Cron::error('0 0 13 * sun'));
        $this->assertSame('cron needs 5 fields', Cron::error('* * *'));
    }

    private function at(string $tz, callable $fn): void
    {
        $prev = date_default_timezone_get();
        date_default_timezone_set($tz);
        try { $fn(); } finally { date_default_timezone_set($prev); }
    }

    public function testNext(): void
    {
        $this->at('America/Sao_Paulo', function () {
            $t = strtotime('2026-10-10 14:02:30'); // a Saturday
            $this->assertSame(strtotime('2026-10-10 14:03:00'), Cron::next('* * * * *', $t));
            $this->assertSame(strtotime('2026-10-10 14:05:00'), Cron::next('*/5 * * * *', $t));
            $this->assertSame(strtotime('2026-10-10 14:15:00'), Cron::next('*/15 * * * *', $t));
            $this->assertSame(strtotime('2026-10-12 03:00:00'), Cron::next('0 3 * * 1', $t));
            $this->assertSame(strtotime('2026-10-11 04:00:00'), Cron::next('0 4 * * 7', $t), '7 alone is Sunday');
            $this->assertSame(strtotime('2026-11-01 00:00:00'), Cron::next('0 0 1 * *', $t));
            $this->assertSame(strtotime('2026-10-11 00:00:00'), Cron::next('0 0 1-31 * *', $t));
        });
    }

    public function testDayOfMonthStepsCountFromZeroLikeDcron(): void
    {
        $this->at('America/Sao_Paulo', function () {
            $t = strtotime('2026-10-30 12:00:00');
            $this->assertSame(strtotime('2026-11-02 00:00:00'), Cron::next('0 0 */2 * *', $t), 'days 2, 4, 6 …, not 1, 3, 5 …');
            $this->assertSame(strtotime('2026-11-03 00:00:00'), Cron::next('0 0 */3 * *', $t), 'days 3, 6, 9 …');
            $this->assertSame(strtotime('2026-11-07 00:00:00'), Cron::next('0 0 */7 * *', $t), 'days 7, 14, 21, 28');
            $this->assertSame(strtotime('2026-10-12 00:00:00'), Cron::next('0 0 */2 * *', strtotime('2026-10-10 14:02:30')));
        });
    }

    public function testMonthFieldFollowsTowerCron(): void
    {
        $honored = (new ReflectionClassConstant(Cron::class, 'MONTHS_HONORED'))->getValue();
        $this->at('America/Sao_Paulo', function () use ($honored) {
            $t = strtotime('2026-10-10 14:02:30');
            $this->assertTrue(Cron::valid('0 0 1 1-12 *'), 'a full month set is the same as *');
            if ($honored) {
                $this->assertTrue(Cron::valid('0 0 1 JAN,jul *'));
                $this->assertSame(strtotime('2027-02-28 12:00:00'), Cron::next('0 12 28 feb *', $t));
                $this->assertNull(Cron::next('0 0 31 2 *', $t));
                $this->assertSame(strtotime('2026-11-01 00:00:00'), Cron::next('0 0 1 */2 *', $t), '*/2 months = Jan, Mar … Nov');
            } else {
                $this->assertSame("the month field must be * on Unraid's cron", Cron::error('0 0 1 JAN,jul *'));
            }
        });
    }

    public function testDescribe(): void
    {
        $this->assertSame('every minute', Cron::describe('* * * * *'));
        $this->assertSame('every 5 minutes', Cron::describe('*/5 * * * *'));
        $this->assertSame('hourly at :15', Cron::describe('15 * * * *'));
        $this->assertSame('every 12 hours at :00', Cron::describe('0 */12 * * *'));
        $this->assertSame('daily at 03:00', Cron::describe('0 3 * * *'));
        $this->assertSame('Mon at 03:00', Cron::describe('0 3 * * 1'));
        $this->assertSame('Sun at 04:00', Cron::describe('0 4 * * 7'));
        $this->assertSame('Mon, Wed, Fri at 08:30', Cron::describe('30 8 * * mon,wed,fri'));
        $this->assertSame('monthly on day 1 at 00:00', Cron::describe('0 0 1 * *'));
        $this->assertSame('15,45 8-18 * * 1-5', Cron::describe('15,45 8-18 * * 1-5'));
    }

    public function testDescribeDayOfMonthStepsTheWayDcronRunsThem(): void
    {
        $this->assertSame('every 2nd day (2, 4, 6 …) at 03:00', Cron::describe('0 3 */2 * *'));
        $this->assertSame('every 3rd day (3, 6, 9 …) at 00:00', Cron::describe('0 0 */3 * *'));
        $this->assertSame('every 7th day (7, 14, 21, 28) at 00:00', Cron::describe('0 0 */7 * *'));
        $this->assertSame('every 11th day (11, 22) at 00:00', Cron::describe('0 0 */11 * *'));
        $this->assertSame('daily at 00:00', Cron::describe('0 0 */1 * *'));
    }

    public function testDomAndDowBothRestrictedIsRejectedWithReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("day-of-month and day-of-week can't both be set (not supported by Unraid's cron)");
        Cron::parse('0 0 13 * sun');
    }

    public function testDescribeIsHonestForStepsThatDoNotDivide(): void
    {
        $this->assertSame('every minute', Cron::describe('*/1 * * * *'));
        $this->assertSame('hourly at :00, :07, :14, :21, :28, :35, :42, :49, :56', Cron::describe('*/7 * * * *'));
        $this->assertSame('hourly at :00', Cron::describe('*/90 * * * *'));
        $this->assertSame('at 00:00, 07:00, 14:00, 21:00', Cron::describe('0 */7 * * *'));
        $this->assertSame('every 6 hours at :30', Cron::describe('30 */6 * * *'));
        $this->assertSame('hourly at :05', Cron::describe('5 */1 * * *'));
    }

    public function testStepOnASingleValueIsRejected(): void
    {
        $this->assertFalse(Cron::valid('5/10 * * * *'), 'a step needs * or a range in front of it');
        $this->assertTrue(Cron::valid('5-59/10 * * * *'));
    }
}
