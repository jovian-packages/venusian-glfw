<?php

namespace Jovian\Toolkits\Glfw\Providers;

use Jovian\Toolkits\Glfw\Bridge\GlfwBridgeDriver;
use Surface\Bridge\ToolkitManager;
use Voyager\NutsAndBolts\ServiceProvider;

/**
 * Registers the 'glfw' stager on Surface's bridge: staged windows on GLFW.
 * Surface names no toolkit.
 */
class VenusianGlfwServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->has('toolkit-bridge')) {
            self::stage($this->app->get('toolkit-bridge'));
        }
    }

    /** The 'glfw' stager, on any toolkit manager. */
    public static function stage(ToolkitManager $toolkits): void
    {
        $toolkits->extend('glfw', fn ($app): GlfwBridgeDriver => new GlfwBridgeDriver($app));
    }
}
