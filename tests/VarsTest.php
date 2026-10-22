<?php
declare(strict_types=1);

final class VarsTest extends TestCase
{
    public function testReadsLeadingHeaderLines(): void
    {
        $d = $this->makeScript('a', "#!/bin/bash\r\n#name=Backup\r\n#description=Nightly (rsync) a=b\r\n#arrayStarted=true\n\n#clearLog=false\necho x\n#late=ignored\n");
        $this->assertSame(['name' => 'Backup', 'description' => 'Nightly (rsync) a=b', 'arrayStarted' => 'true', 'clearLog' => 'false'], Vars::raw("$d/script"));
    }

    public function testMissingEmptyAndBlankPathNeverFatal(): void
    {
        $this->assertSame([], Vars::raw(''));
        $this->assertSame([], Vars::raw($this->root . '/nope'));
        $d = $this->makeScript('e', '');
        $this->assertSame([], Vars::raw("$d/script"));
    }

    public function testTypedConvertsBooleans(): void
    {
        $d = $this->makeScript('b', "#arrayStarted=true\n#noParity=no\n#argumentDefault=-v\n");
        $v = Vars::typed("$d/script");
        $this->assertTrue($v['arrayStarted']);
        $this->assertFalse($v['noParity']);
        $this->assertSame('-v', $v['argumentDefault']);
    }

    public function testCommentWithoutEqualsIsSkipped(): void
    {
        $d = $this->makeScript('c', "# just a comment\n#name=X\n");
        $this->assertSame(['name' => 'X'], Vars::raw("$d/script"));
    }
}
