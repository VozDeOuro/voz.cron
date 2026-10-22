<?php
declare(strict_types=1);

final class ApiError extends RuntimeException
{
    /** @param array<string,mixed> $extra merged into the error response, e.g. the current layout on a 409 */
    public function __construct(string $message, int $code, public readonly array $extra = [])
    {
        parent::__construct($message, $code);
    }
}

// JSON API for the UI. Pure dispatch: input array in, response array out, ApiError on failure.
final class Api
{
    private static function id(array $in): string
    {
        $id = (string)($in['id'] ?? '');
        try { Scripts::resolve($id); } catch (InvalidArgumentException) { throw new ApiError('unknown script', 404); }
        return $id;
    }

    private static function str(array $in, string $key): string
    {
        $v = $in[$key] ?? '';
        if (!is_string($v)) throw new ApiError('bad input', 400);
        return $v;
    }

    private static function row(string $id): array
    {
        $file = Paths::scripts() . "/$id/script";
        $sched = Schedule::get($id);
        $since = Runner::runningSince($id);
        $custom = $sched['frequency'] === 'custom' && Cron::valid($sched['custom']);
        return [
            'id' => $id,
            'name' => Scripts::name($id),
            'description' => Scripts::description($id),
            'missing' => !is_file($file),
            'vars' => Vars::typed($file),
            'schedule' => $sched,
            'cron' => $custom ? Cron::describe($sched['custom']) : '',
            'cronError' => $sched['frequency'] === 'custom' ? Cron::error($sched['custom']) : null,
            'next' => $custom ? Cron::next($sched['custom'], time()) : null,
            'running' => $since !== null ? ['since' => $since] : null,
            'last' => History::last($id),
            'health' => State::get($id),
            'spark' => History::spark($id),
        ];
    }

    private static function spawnRun(string $id, string $trigger): void
    {
        if (!Sys::launch([Sys::php(), Paths::$plugin . '/run.php', '--script', $id, '--trigger', $trigger])) throw new RuntimeException("could not launch $id");
    }

    public static function handle(array $in): array
    {
        try {
            return self::dispatch($in);
        } catch (InvalidArgumentException $e) {
            throw new ApiError($e->getMessage(), 400);
        }
    }

    private static function dispatch(array $in): array
    {
        switch ($in['action'] ?? '') {
            case 'list':
                return ['scripts' => array_map(self::row(...), Scripts::ids()), 'settings' => Settings::get(), 'layout' => Layout::get(), 'tz' => Sys::tz()];
            case 'layout.get':
                return ['layout' => Layout::get()];
            case 'layout.save':
                $doc = $in['layout'] ?? null;
                $after = $in['after'] ?? null;
                if (!is_array($doc) || !is_int($in['version'] ?? null) || ($after !== null && !is_array($after))) throw new ApiError('bad input', 400);
                try {
                    return ['layout' => Layout::save($doc, $in['version'], $after)];
                } catch (LayoutConflict $e) {
                    throw new ApiError('the folder layout was changed elsewhere; reload', 409, ['layout' => $e->current]);
                }
            case 'run':
            case 'background':
                $id = self::id($in);
                $vars = Vars::typed(Scripts::file($id));
                if ($in['action'] === 'background' && !empty($vars['foregroundOnly'])) throw new ApiError('this script can only run in the foreground', 400);
                if ($in['action'] === 'run' && !empty($vars['backgroundOnly'])) throw new ApiError('this script can only run in the background', 400);
                if (Runner::isRunning($id) && Schedule::get($id)['overlap'] !== 'parallel') throw new ApiError('already running', 409);
                self::spawnRun($id, $in['action'] === 'run' ? 'manual' : 'background');
                return ['started' => true];
            case 'abort':
                return ['aborted' => Runner::abort(self::id($in))];
            case 'log':
                $id = self::id($in);
                return Runner::log($id, (int)($in['which'] ?? 0), (int)($in['offset'] ?? 0)) + ['running' => Runner::isRunning($id)];
            case 'clearLog':
                Runner::clearLogs(self::id($in));
                return [];
            case 'history':
                return ['runs' => History::read(self::id($in), max(1, min(1000, (int)($in['limit'] ?? 50))))];
            case 'notify.test':
                $id = self::id($in);
                if (!Alerts::test(Scripts::name($id))) throw new ApiError('could not send: Unraid notify failed (see the system log)', 502);
                return ['sent' => true];
            case 'get':
                return ['body' => Scripts::read(self::id($in))];
            case 'save':
                Scripts::save(self::id($in), self::str($in, 'body'));
                return [];
            case 'create':
                return ['id' => Scripts::create(self::str($in, 'name'))];
            case 'rename':
                Scripts::rename(self::id($in), self::str($in, 'name'));
                return [];
            case 'describe':
                Scripts::describe(self::id($in), self::str($in, 'description'));
                return [];
            case 'delete':
                $id = self::id($in);
                if (Runner::isRunning($id)) throw new ApiError('abort the script before deleting it', 409);
                Scripts::delete($id);
                // schedule and crontab first: a failure in the history cleanup must not leave a cron line for a deleted script
                Schedule::prune();
                Schedule::writeCron();
                History::forget($id);
                return [];
            case 'schedule.set':
                $s = Schedule::set(self::id($in), (array)($in['fields'] ?? []));
                Schedule::writeCron();
                return ['schedule' => $s];
            case 'apply':
                $changes = (array)($in['changes'] ?? []);
                foreach (array_keys($changes) as $id) self::id(['id' => (string)$id]);
                Schedule::apply($changes);
                return [];
            case 'cron.check':
                $e = self::str($in, 'expr');
                $err = Cron::error($e);
                return ['valid' => $err === null, 'text' => $err === null ? Cron::describe($e) : '', 'next' => $err === null ? Cron::next($e, time()) : null, 'error' => $err];
            case 'settings.get':
                return ['settings' => Settings::get()];
            case 'settings.set':
                $s = Settings::set((array)($in['settings'] ?? []));
                Schedule::writeCron();
                return ['settings' => $s];
            default:
                throw new ApiError('unknown action', 400);
        }
    }
}
