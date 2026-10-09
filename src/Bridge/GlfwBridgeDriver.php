<?php

namespace Jovian\Toolkits\Glfw\Bridge;

use Jovian\Toolkits\Glfw\Windows\GlfwDisplays;
use Jovian\Toolkits\Glfw\Windows\GlfwStagedWindow;
use Surface\Bridge\ToolkitBridgeDriver;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\StagedWindowDriver;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\StagedWindow;
use Voyager\Contracts\Vessel\TheServiceContainer;

/**
 * GLFW as a stager: toolkit 'glfw' on the bridge, staged windows only. One
 * session, one set of windows. config('bridge.stage.glfw.platform') picks
 * GLFW's platform ('cocoa', 'wayland', 'x11'); unset, GLFW chooses.
 */
class GlfwBridgeDriver extends ToolkitBridgeDriver implements StagedWindowDriver
{
    private const array PLATFORMS = ['cocoa' => GLFW_PLATFORM_COCOA, 'wayland' => GLFW_PLATFORM_WAYLAND, 'x11' => GLFW_PLATFORM_X11];

    /** @var array<string, GlfwStagedWindow> */
    protected array $staged = [];

    public function __construct(TheServiceContainer $app, protected readonly ?string $platform = null)
    {
        parent::__construct($app);
    }

    /** @throws WindowException For a platform GLFW does not name. */
    public function connect(): GlfwSession
    {
        if (is_null($this->session)) {
            $platform = $this->platform ?? ($this->app->has('config') ? $this->app->get('config')->get('bridge.stage.glfw.platform') : null);
            if (! is_null($platform) && ! isset(self::PLATFORMS[$platform])) {
                throw new WindowException("GLFW runs on 'cocoa', 'wayland' or 'x11', got '{$platform}'.");
            }
            $this->session = new GlfwSession(is_null($platform) ? null : self::PLATFORMS[$platform]);
        }

        return $this->session->connect();
    }

    public function openStaged(string $name, int $width, int $height, array $options = []): GlfwStagedWindow
    {
        if (isset($this->staged[$name])) {
            throw new WindowException("A staged window named '{$name}' is already open.");
        }

        return $this->staged[$name] = new GlfwStagedWindow($name, $this->connect(), $this, $width, $height, StagedWindow::options($options, $name));
    }

    public function hasStaged(string $name): bool
    {
        return isset($this->staged[$name]);
    }

    public function getStaged(string $name): ?GlfwStagedWindow
    {
        return $this->staged[$name] ?? null;
    }

    /** @return array<string, GlfwStagedWindow> */
    public function allStaged(): array
    {
        return $this->staged;
    }

    public function closeAllStaged(): void
    {
        foreach ($this->staged as $window) {
            $window->close();
        }
    }

    public function displays(): array
    {
        $this->connect();

        return GlfwDisplays::all();
    }

    public function primaryDisplay(): Display
    {
        $this->connect();

        return GlfwDisplays::primary();
    }

    public function forgetStaged(string $name): void
    {
        unset($this->staged[$name]);
    }
}
