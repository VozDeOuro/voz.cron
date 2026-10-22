<?php
declare(strict_types=1);

final class LayoutTest extends TestCase
{
    private function scripts(string ...$ids): void { foreach ($ids as $id) $this->makeScript($id); }

    private function write(array $doc): void { file_put_contents(Paths::layoutFile(), json_encode($doc)); }

    public function testNoFileListsScriptsByName(): void
    {
        $this->makeScript('b', "#!/bin/bash\n", 'Alpha');
        $this->makeScript('a', "#!/bin/bash\n", 'Zulu');
        $this->assertSame(['version' => 0, 'items' => [['type' => 'script', 'id' => 'b'], ['type' => 'script', 'id' => 'a']]], Layout::get());
        $this->assertFileDoesNotExist(Paths::layoutFile());
    }

    public function testReconcileDropsUnknownKeepsFirstAndAppendsNew(): void
    {
        $this->scripts('a', 'b', 'c', 'd');
        $this->write(['version' => 4, 'items' => [
            ['type' => 'folder', 'id' => 'f-1', 'name' => 'Backups', 'icon' => '💾', 'scripts' => ['b', 'gone', 'a']],
            ['type' => 'script', 'id' => 'a'],      // listed twice: its first place (in the folder) wins
            ['type' => 'script', 'id' => 'ghost'],
            ['type' => 'script', 'id' => 'c'],
        ]]);
        $before = file_get_contents(Paths::layoutFile());
        $this->assertSame(['version' => 4, 'items' => [
            ['type' => 'folder', 'id' => 'f-1', 'name' => 'Backups', 'icon' => '💾', 'scripts' => ['b', 'a']],
            ['type' => 'script', 'id' => 'c'],
            ['type' => 'script', 'id' => 'd'],
        ]], Layout::get());
        $this->assertSame($before, file_get_contents(Paths::layoutFile()), 'reconciling never writes');
    }

    public function testSaveValidatesAndBumpsVersion(): void
    {
        $this->scripts('a', 'b');
        $doc = ['items' => [['type' => 'folder', 'id' => 'f-x', 'name' => ' Media ', 'icon' => '🎬', 'scripts' => ['b']], ['type' => 'script', 'id' => 'a']]];
        $saved = Layout::save($doc, 0);
        $this->assertSame(1, $saved['version']);
        $this->assertSame('Media', $saved['items'][0]['name']);
        $this->assertSame(2, Layout::save($doc, 1)['version']);
        $bad = [
            'items not a list' => ['items' => ['x' => ['type' => 'script', 'id' => 'a']]],
            'unknown type' => ['items' => [['type' => 'tag', 'id' => 'a']]],
            'unknown script' => ['items' => [['type' => 'script', 'id' => 'nope']]],
            'script twice' => ['items' => [['type' => 'script', 'id' => 'a'], ['type' => 'folder', 'id' => 'f-y', 'name' => 'Y', 'scripts' => ['a']]]],
            'empty name' => ['items' => [['type' => 'folder', 'id' => 'f-y', 'name' => '  ', 'scripts' => []]]],
            'name over 64 bytes' => ['items' => [['type' => 'folder', 'id' => 'f-y', 'name' => str_repeat('é', 33), 'scripts' => []]]],
            'icon over 16 bytes' => ['items' => [['type' => 'folder', 'id' => 'f-y', 'name' => 'Y', 'icon' => str_repeat('💾', 5), 'scripts' => []]]],
            'bad folder id' => ['items' => [['type' => 'folder', 'id' => '../x', 'name' => 'Y', 'scripts' => []]]],
            'duplicate folder id' => ['items' => [['type' => 'folder', 'id' => 'f-y', 'name' => 'Y', 'scripts' => []], ['type' => 'folder', 'id' => 'f-y', 'name' => 'Z', 'scripts' => []]]],
            'newline in name' => ['items' => [['type' => 'folder', 'id' => 'f-y', 'name' => "a\nb", 'scripts' => []]]],
            'scripts not a list' => ['items' => [['type' => 'folder', 'id' => 'f-y', 'name' => 'Y', 'scripts' => 'a']]],
        ];
        foreach ($bad as $why => $d) {
            try { Layout::save($d, 2); $this->fail($why); } catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(2, Layout::get()['version'], 'rejected saves write nothing');
    }

    public function testStaleVersionConflictsWithTheCurrentLayout(): void
    {
        $this->scripts('a');
        Layout::save(['items' => [['type' => 'folder', 'id' => 'f-1', 'name' => 'One', 'scripts' => ['a']]]], 0);
        try { Layout::save(['items' => [['type' => 'script', 'id' => 'a']]], 0); $this->fail(); }
        catch (LayoutConflict $e) { $this->assertSame('One', $e->current['items'][0]['name']); $this->assertSame(1, $e->current['version']); }
        $this->assertSame('f-1', Layout::get()['items'][0]['id'], 'the stale save changed nothing');
    }

    public function testSaveAfterAnInFlightSave(): void
    {
        $this->scripts('a', 'b');
        $A = [['type' => 'folder', 'id' => 'f-1', 'name' => 'F', 'icon' => '', 'scripts' => ['a']], ['type' => 'script', 'id' => 'b']];
        $B = [['type' => 'folder', 'id' => 'f-1', 'name' => 'F', 'icon' => '', 'scripts' => ['a', 'b']]];
        Layout::save(['items' => [['type' => 'script', 'id' => 'a'], ['type' => 'script', 'id' => 'b']]], 0);

        // (a) A (sent with 1) has not landed yet: B, which includes A's change, supersedes it; A then conflicts.
        $this->assertSame(2, Layout::save(['items' => $B], 1, $A)['version']);
        $this->assertSame($B, Layout::get()['items']);
        try { Layout::save(['items' => $A], 1); $this->fail('late A'); } catch (LayoutConflict) { $this->addToAssertionCount(1); }
        $this->assertSame($B, Layout::get()['items'], 'the late A changed nothing');

        // (b) A (sent with 2) landed first and wrote version 3: B is accepted on top of it.
        $this->assertSame(3, Layout::save(['items' => $A], 2)['version']);
        $this->assertSame(4, Layout::save(['items' => $B], 2, $A)['version']);
        $this->assertSame($B, Layout::get()['items']);
    }

    public function testSaveAfterRefusesWhenAnotherTabSavedInBetween(): void
    {
        $this->scripts('a', 'b');
        $A = [['type' => 'script', 'id' => 'b'], ['type' => 'script', 'id' => 'a']];
        $B = [['type' => 'folder', 'id' => 'f-1', 'name' => 'F', 'icon' => '', 'scripts' => ['b', 'a']]];
        $other = [['type' => 'folder', 'id' => 'f-o', 'name' => 'Other tab', 'icon' => '', 'scripts' => []], ['type' => 'script', 'id' => 'a'], ['type' => 'script', 'id' => 'b']];
        Layout::save(['items' => $other], 0); // the other tab wrote version 1; this tab's A (sent with 0) will conflict
        try { Layout::save(['items' => $B], 0, $A); $this->fail('version 1 does not hold A'); }
        catch (LayoutConflict $e) { $this->assertSame('Other tab', $e->current['items'][0]['name']); }
        Layout::save(['items' => $other], 1); // version 2, still not A
        try { Layout::save(['items' => $B], 0, $A); $this->fail('two saves ahead'); } catch (LayoutConflict) { $this->addToAssertionCount(1); }
        $this->assertSame($other, Layout::get()['items']);
        $this->assertSame(2, Layout::get()['version']);
        try { Layout::save(['items' => $B], 2, [['type' => 'script', 'id' => 'nope']]); $this->fail('after is validated'); }
        catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame(2, Layout::get()['version']);
    }

    public function testGarbageLayoutFallsBackToScriptOrder(): void
    {
        $this->scripts('a', 'b');
        foreach (['{broken', '[]', '{"version":"x","items":"y"}', '{"items":[1,null,{"type":"folder"}]}'] as $txt) {
            file_put_contents(Paths::layoutFile(), $txt);
            $this->assertSame([['type' => 'script', 'id' => 'a'], ['type' => 'script', 'id' => 'b']], Layout::get()['items'], $txt);
        }
        $this->assertSame(1, Layout::save(['items' => [['type' => 'script', 'id' => 'b']]], 0)['version'], 'a damaged file counts as version 0');
    }

    public function testDeletedScriptDropsOutOfItsFolder(): void
    {
        $this->scripts('a', 'b', 'c');
        Layout::save(['items' => [['type' => 'script', 'id' => 'c'], ['type' => 'folder', 'id' => 'f-1', 'name' => 'F', 'scripts' => ['a', 'b']]]], 0);
        Scripts::delete('a');
        $this->assertSame(['b'], Layout::get()['items'][1]['scripts']);
        Api::handle(['action' => 'delete', 'id' => 'b']);
        $this->assertSame([], Layout::get()['items'][1]['scripts'], 'an emptied folder stays');
    }

    public function testNumericIdsInLayout(): void
    {
        $this->scripts('0', '2024');
        $saved = Layout::save(['items' => [['type' => 'folder', 'id' => 'f-n', 'name' => 'N', 'scripts' => ['2024']], ['type' => 'script', 'id' => '0']]], 0);
        $this->assertSame(['2024'], $saved['items'][0]['scripts']);
        $this->assertSame('0', $saved['items'][1]['id']);
        $this->assertStringStartsWith('{', file_get_contents(Paths::layoutFile()));
    }
}
