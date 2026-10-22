<?php
declare(strict_types=1);

final class ExitCodesTest extends TestCase
{
    private static function cases(): array
    {
        return json_decode((string)file_get_contents(__DIR__ . '/fixtures/exit-codes-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testValidCasesNormalizeAndMatch(): void
    {
        foreach (self::cases()['valid'] as $c) {
            $this->assertSame($c['text'], ExitCodes::normalize($c['in']), json_encode($c['in']));
            foreach ($c['probe'] as [$exit, $want]) {
                $this->assertSame($want, ExitCodes::outcome($c['in'], $exit === 0 ? 'ok' : 'failed', $exit), json_encode($c['in']) . " exit $exit");
            }
        }
    }

    public function testInvalidCasesNameTheOffendingToken(): void
    {
        foreach (self::cases()['invalid'] as $c) {
            try {
                ExitCodes::normalize($c['in']);
                $this->fail('accepted ' . json_encode($c['in']));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($c['has'], $e->getMessage(), json_encode($c['in']));
            }
            $this->assertFalse(ExitCodes::valid($c['in']));
        }
    }

    public function testTimeoutsAlwaysFailAbortsAndSkipsPassThrough(): void
    {
        $this->assertSame('fail', ExitCodes::outcome('*=ok', 'timeout', 124));
        $this->assertSame('fail', ExitCodes::outcome('*=ignore', 'failed', -1), 'a run that never started');
        $this->assertSame('aborted', ExitCodes::outcome('*=ok', 'aborted', 137));
        $this->assertSame('skipped', ExitCodes::outcome('*=ok', 'skipped', 0));
        $this->assertSame('ok', ExitCodes::outcome('', 'ok', 0));
        $this->assertSame('fail', ExitCodes::outcome('garbage', 'failed', 1), 'an unreadable stored map falls back to the default');
        $this->assertSame('ok', ExitCodes::outcome('garbage', 'ok', 0));
    }

    public function testScheduleStoresNormalizedRulesAndRejectsBadOnes(): void
    {
        $this->makeScript('a');
        $this->assertSame('', Schedule::get('a')['exitCodes'], 'default is empty, meaning 0=ok *=fail');
        $this->assertSame('0=ok 1=warn *=fail', Schedule::set('a', ['exitCodes' => "  0=ok\t1=warn *=fail "])['exitCodes']);
        try { Schedule::set('a', ['exitCodes' => '1=maybe']); $this->fail(); }
        catch (InvalidArgumentException $e) { $this->assertStringContainsString('maybe', $e->getMessage()); }
        try { Schedule::set('a', ['exitCodes' => ['1=warn']]); $this->fail(); }
        catch (InvalidArgumentException $e) { $this->assertSame('bad exit codes', $e->getMessage()); }
        $this->assertSame('0=ok 1=warn *=fail', Schedule::get('a')['exitCodes'], 'a rejected change leaves the stored rules');
    }

    public function testApiReturnsTheValidationMessage(): void
    {
        $this->makeScript('a');
        try { Api::handle(['action' => 'schedule.set', 'id' => 'a', 'fields' => ['exitCodes' => '300=ok']]); $this->fail(); }
        catch (ApiError $e) { $this->assertSame(400, $e->getCode()); $this->assertStringContainsString('300', $e->getMessage()); }
    }
}
