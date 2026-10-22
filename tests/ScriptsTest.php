<?php
declare(strict_types=1);

final class ScriptsTest extends TestCase
{
    public function testIdsSortedNaturallyAndOnlyDirs(): void
    {
        $this->makeScript('b2'); $this->makeScript('B10'); $this->makeScript('tidy temp');
        touch(Paths::scripts() . '/stray-file');
        $this->assertSame(['b2', 'B10', 'tidy temp'], Scripts::ids());
    }

    public function testResolveRejectsTraversalAndUnknown(): void
    {
        $this->makeScript('ok');
        $this->assertSame(Paths::scripts() . '/ok', Scripts::resolve('ok'));
        foreach (['', '.', '..', '../ok', 'ok/../ok', 'a/b', "ok\0", 'missing'] as $bad) {
            try { Scripts::resolve($bad); $this->fail("accepted: $bad"); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testNameAndDescriptionFallbacks(): void
    {
        $this->makeScript('plain');
        $this->makeScript('hdr', "#!/bin/bash\n#name=From Header\n#description=Hdr desc\n");
        $this->makeScript('file', "#name=Ignored\n", 'From File');
        file_put_contents(Paths::scripts() . '/file/description', "File desc\r\n");
        $this->assertSame('plain', Scripts::name('plain'));
        $this->assertSame('From Header', Scripts::name('hdr'));
        $this->assertSame('From File', Scripts::name('file'));
        $this->assertSame('Hdr desc', Scripts::description('hdr'));
        $this->assertSame('File desc', Scripts::description('file'));
        $this->assertSame('', Scripts::description('plain'));
    }

    public function testCreateSanitizesAndAvoidsCollision(): void
    {
        $id = Scripts::create('My "backup"; job?');
        $this->assertSame('My backup job', $id);
        $this->assertSame("#!/bin/bash\n", Scripts::read($id));
        $id2 = Scripts::create('My backup job');
        $this->assertNotSame($id, $id2);
        $this->assertStringStartsWith('My backup job', $id2);
    }

    public function testRenameDescribeSaveDelete(): void
    {
        $this->makeScript('x');
        Scripts::rename('x', 'New <Name>');
        $this->assertSame('New Name', Scripts::name('x'));
        Scripts::describe('x', "  hello  ");
        $this->assertSame('hello', Scripts::description('x'));
        Scripts::save('x', "#!/bin/bash\r\necho 1\r\n");
        $this->assertSame("#!/bin/bash\necho 1\n", Scripts::read('x'));
        Scripts::delete('x');
        $this->assertSame([], Scripts::ids());
    }

    public function testControlCharactersNeverReachIds(): void
    {
        $id = Scripts::create("evil\n* * * * * root rm -rf /");
        $this->assertStringNotContainsString("\n", $id);
        $this->assertSame('a b', Scripts::sanitize("a\tb"));
        $this->assertLessThanOrEqual(200, strlen(Scripts::create(str_repeat('x', 300))));
    }

    public function testWritesFailLoudly(): void
    {
        $this->makeScript('x');
        unlink(Paths::scripts() . '/x/script');
        mkdir(Paths::scripts() . '/x/script/occupied', 0777, true); // the script path is now a non-empty directory
        $this->expectException(RuntimeException::class);
        Scripts::save('x', "echo\n");
    }

    public function testCreateFailsLoudlyWhenScriptsDirIsUnwritable(): void
    {
        rmdir(Paths::scripts());
        file_put_contents(Paths::scripts(), 'not a dir');
        $this->expectException(RuntimeException::class);
        Scripts::create('new');
    }

    public function testUnknownIdsAreRejectedForEveryWrite(): void
    {
        foreach ([fn() => Scripts::rename('nope', 'x'), fn() => Scripts::describe('nope', 'x'), fn() => Scripts::save('nope', 'x'), fn() => Scripts::delete('nope')] as $f) {
            try { $f(); $this->fail(); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testLeadingDashIdWorksEverywhere(): void
    {
        $id = Scripts::create('-rf');
        $this->assertSame('-rf', $id);
        Scripts::save($id, "echo dash\n");
        $this->assertSame('ok', Runner::run($id, 'manual'));
        Scripts::delete($id);
        $this->assertSame([], Scripts::ids());
    }

    public function testDeletingASymlinkedScriptDirKeepsItsTarget(): void
    {
        mkdir($this->root . '/elsewhere');
        file_put_contents($this->root . '/elsewhere/script', "echo\n");
        symlink($this->root . '/elsewhere', Paths::scripts() . '/linked');
        $this->assertSame(['linked'], Scripts::ids());
        Scripts::delete('linked');
        $this->assertSame([], Scripts::ids());
        $this->assertFileExists($this->root . '/elsewhere/script');
    }

    public function testDeleteNonAsciiIdsUnderCLocaleOnlyRemovesThatScript(): void
    {
        $lc = getenv('LC_ALL');
        putenv('LC_ALL=C');
        $prev = setlocale(LC_ALL, '0');
        setlocale(LC_ALL, 'C');
        try {
            foreach (['日本', 'Pré', 'Pr', 'keep'] as $id) $this->makeScript($id);
            Scripts::delete('日本');
            $this->assertSame(['keep', 'Pr', 'Pré'], Scripts::ids());
            Scripts::delete('Pré');
            $this->assertSame(['keep', 'Pr'], Scripts::ids());
            $this->assertFileExists(Paths::scripts() . '/Pr/script');
        } finally {
            putenv($lc === false ? 'LC_ALL' : "LC_ALL=$lc");
            setlocale(LC_ALL, (string)$prev);
        }
    }

    public function testDeleteNeverFollowsSymlinksInsideTheScriptDir(): void
    {
        mkdir($this->root . '/outside/sub', 0777, true);
        file_put_contents($this->root . '/outside/sub/precious', 'x');
        file_put_contents($this->root . '/outside/file', 'x');
        $dir = $this->makeScript('s');
        mkdir("$dir/nested/deeper", 0777, true);
        file_put_contents("$dir/nested/deeper/f", 'x');
        file_put_contents("$dir/.hidden", 'x');
        symlink($this->root . '/outside', "$dir/linkdir");
        symlink($this->root . '/outside/file', "$dir/nested/linkfile");
        Scripts::delete('s');
        $this->assertFileDoesNotExist($dir);
        $this->assertFileExists($this->root . '/outside/sub/precious');
        $this->assertFileExists($this->root . '/outside/file');
    }
}
