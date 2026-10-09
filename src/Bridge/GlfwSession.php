<?php

namespace Jovian\Toolkits\Glfw\Bridge;

use NSApplication;
use ObjCDelegate;
use Surface\Bridge\BridgedToolkitSession;
use Surface\Contracts\Bridge\BridgeException;
use Surface\Contracts\Windows\Mail\DisplaysChanged;

/**
 * GLFW as a bridge session. GLFW cannot fold a descriptor into its wait, so
 * the session never sleeps natively: the loop polls it at its pace
 * (ToolkitPoller), and pump() runs GLFW's event processing, which calls each
 * window's callbacks. A monitor connected or disconnected posts DisplaysChanged.
 */
class GlfwSession extends BridgedToolkitSession
{
    /** Callbacks run during the current pump. */
    protected int $dispatched = 0;

    /**
     * @param  int|null  $platform  GLFW_PLATFORM_COCOA, _WAYLAND or _X11; null lets GLFW choose.
     */
    public function __construct(protected readonly ?int $platform = null)
    {
        parent::__construct();
    }

    /**
     * GLFW up once, never terminated (windows of other code may live on it). No
     * menu bar of GLFW's own on macOS; no libdecor on Wayland, whose GTK plugin
     * collides with a GTK already in the process.
     *
     * @throws BridgeException When GLFW cannot start.
     */
    protected function initializeEngine(): void
    {
        if (PHP_OS_FAMILY === 'Darwin' && class_exists(NSApplication::class)) {
            // The application exists before GLFW's: GLFW then joins it instead of building its own.
            NSApplication::sharedApplication();
        }
        glfwInitHint(GLFW_COCOA_MENUBAR, GLFW_FALSE);
        glfwInitHint(GLFW_WAYLAND_LIBDECOR, GLFW_WAYLAND_DISABLE_LIBDECOR);
        if (! is_null($this->platform)) {
            glfwInitHint(GLFW_PLATFORM, $this->platform);
        }
        if (! glfwInit()) {
            glfwGetError($description);

            throw new BridgeException('glfw: GLFW could not start: '.($description ?? 'no description'));
        }
        glfwSetMonitorCallback(function (): void {
            $this->dispatched++;
            $this->post(new DisplaysChanged());
        });
    }

    /** GLFW windows present themselves to the OS when shown. */
    protected function connectToEngine(): void {}

    protected function disconnectEngine(): void {}

    protected function sleepsNatively(): bool
    {
        return false;
    }

    /** Never called: the session does not sleep natively. */
    protected function wakeDescriptor(int $fd): void {}

    /** Never called: the session does not sleep natively. */
    protected function releaseWakeDescriptor(): void {}

    /** Wait at most $budget_ns for events, then process what came; the callbacks run inside. */
    public function pump(int $budget_ns): int
    {
        $this->dispatched = 0;
        $budget_ns > 0 ? glfwWaitEventsTimeout($budget_ns / 1e9) : glfwPollEvents();

        return $this->dispatched;
    }

    /** A window callback ran: counted for pump(). */
    public function dispatched(): void
    {
        $this->dispatched++;
    }

    /** GLFW_PLATFORM_COCOA, _WAYLAND or _X11, as GLFW runs. */
    public function platform(): int
    {
        return glfwGetPlatform();
    }
}
