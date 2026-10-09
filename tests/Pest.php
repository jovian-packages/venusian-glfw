<?php

declare(strict_types=1);

use Jovian\Toolkits\Glfw\Bridge\GlfwBridgeDriver;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Framebuffers\Native\NativeDirtyFramebuffer;
use Voyager\Vessel\ControlPanel;

if (! extension_loaded('glfw') || ! extension_loaded('opengl')) {
    throw new RuntimeException('venusian-glfw tests need ext-glfw and ext-opengl loaded.');
}

/**
 * The one GLFW stager for the process, through a container like the framework's.
 * GLFW_TEST_PLATFORM (cocoa, wayland, x11) picks GLFW's platform: on the Pi,
 * GLFW finds Wayland's default socket even with only DISPLAY set.
 */
function stager(): GlfwBridgeDriver
{
    static $driver = null;
    if (is_null($driver)) {
        $container = new ControlPanel();
        $container->registerInstance('config', new class
        {
            public function get(string $key, mixed $default = null): mixed
            {
                return $key === 'bridge.stage.glfw.platform' ? (getenv('GLFW_TEST_PLATFORM') ?: null) : $default;
            }
        });
        $driver = new GlfwBridgeDriver($container);
    }

    return $driver;
}

/** Mail the session is holding for a loop, taken out. */
function takeMail(): array
{
    $session = stager()->connect();

    return (function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    })->call($session);
}

/** Pump GLFW as the loop's poller does, every 5 ms, until $until() holds or $seconds pass; whether it held. */
function pumpUntil(callable $until, float $seconds): bool
{
    $session = stager()->connect();
    $limit = microtime(true) + $seconds;
    while (! $until()) {
        if (microtime(true) >= $limit) {
            return false;
        }
        $session->pump(5_000_000);
        $session->flushLatest();
    }

    return true;
}

function pumpFor(float $seconds): void
{
    pumpUntil(fn (): bool => false, $seconds);
}

/** Mail of class $class, pumping until one arrives or $seconds pass. */
function mailOf(string $class, float $seconds = 3.0): array
{
    $seen = [];
    pumpUntil(function () use ($class, &$seen): bool {
        foreach (takeMail() as $mail) {
            if ($mail instanceof $class) {
                $seen[] = $mail;
            }
        }

        return $seen !== [];
    }, $seconds);

    return $seen;
}

/** A borrower that keeps what it was asked to present into, with the handles given. */
final class StageBorrower implements SurfaceBorrower
{
    /** @var list<LentSurface> */
    public array $presented = [];

    public Framebuffer $frame;

    public function __construct(private readonly array $handles = [])
    {
        $this->frame = new NativeDirtyFramebuffer(FormatSpec::rgba8(), 8, 8);
    }

    public function framebuffer(): Framebuffer
    {
        return $this->frame;
    }

    public function lendingHandles(): array
    {
        return $this->handles;
    }

    public function presentInto(LentSurface $surface): bool
    {
        $this->presented[] = $surface;

        return true;
    }
}
