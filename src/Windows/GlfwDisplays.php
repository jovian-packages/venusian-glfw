<?php

namespace Jovian\Toolkits\Glfw\Windows;

use GLFWmonitor;
use GLFWvidmode;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\DisplayMode;
use Surface\Contracts\Windows\WindowException;

/**
 * Displays as Surface sees them, from GLFW's monitors. A display's id is its
 * GLFWmonitor's address, steady while it is connected. Sizes are GLFW's screen
 * coordinates; a mode's pixel density is the monitor's content scale.
 *
 * While a staged window holds a monitor in exclusive fullscreen, the mode it
 * ran before stays here as its desktop mode.
 */
final class GlfwDisplays
{
    /** @var array<int, DisplayMode> monitor id → its mode before exclusive fullscreen */
    private static array $desktop = [];

    /** @return list<Display> the primary first */
    public static function all(): array
    {
        $primary = glfwGetPrimaryMonitor();
        $monitors = glfwGetMonitors();
        usort($monitors, fn (GLFWmonitor $a, GLFWmonitor $b): int => ($b === $primary) <=> ($a === $primary));

        return array_map(fn (GLFWmonitor $monitor): Display => self::describe($monitor), $monitors);
    }

    public static function primary(): Display
    {
        return self::describe(glfwGetPrimaryMonitor() ?? throw new WindowException('GLFW reports no monitor.'));
    }

    public static function describe(GLFWmonitor $monitor): Display
    {
        $id = $monitor->pointer();
        $current = glfwGetVideoMode($monitor) ?? throw new WindowException("Monitor {$id} reports no video mode.");
        glfwGetMonitorPos($monitor, $x, $y);
        glfwGetMonitorWorkarea($monitor, $ax, $ay, $aw, $ah);
        glfwGetMonitorContentScale($monitor, $scale, $yscale);
        $mode = self::mode($id, $current, $scale);

        return new Display(
            id: $id,
            name: glfwGetMonitorName($monitor) ?? "Monitor {$id}",
            bounds: new Region($x, $y, $current->width, $current->height),
            usable: new Region($ax, $ay, $aw, $ah),
            scale: $scale,
            current: $mode,
            desktop: self::$desktop[$id] ?? $mode,
            hdr: null,
        );
    }

    /** The monitor with id $id; null when none is connected under it. */
    public static function monitor(int $id): ?GLFWmonitor
    {
        foreach (glfwGetMonitors() as $monitor) {
            if ($monitor->pointer() === $id) {
                return $monitor;
            }
        }

        return null;
    }

    /** The monitor whose bounds hold the point $x, $y; the primary when none does. */
    public static function at(int $x, int $y): ?GLFWmonitor
    {
        foreach (glfwGetMonitors() as $monitor) {
            $mode = glfwGetVideoMode($monitor);
            glfwGetMonitorPos($monitor, $mx, $my);
            if (! is_null($mode) && $x >= $mx && $x < $mx + $mode->width && $y >= $my && $y < $my + $mode->height) {
                return $monitor;
            }
        }

        return glfwGetPrimaryMonitor();
    }

    /** @return list<DisplayMode> each size and refresh rate the monitor offers, once */
    public static function modes(GLFWmonitor $monitor): array
    {
        glfwGetMonitorContentScale($monitor, $scale, $yscale);
        $modes = [];
        foreach (glfwGetVideoModes($monitor) as $vidmode) {
            $mode = self::mode($monitor->pointer(), $vidmode, $scale);
            $modes["{$mode->width}x{$mode->height}@{$mode->refreshRate}"] = $mode;
        }

        return array_values($modes);
    }

    /** Remember the mode the monitor runs now as its desktop mode, before exclusive fullscreen changes it. */
    public static function holdDesktop(GLFWmonitor $monitor): void
    {
        $id = $monitor->pointer();
        if (! isset(self::$desktop[$id])) {
            glfwGetMonitorContentScale($monitor, $scale, $yscale);
            self::$desktop[$id] = self::mode($id, glfwGetVideoMode($monitor) ?? throw new WindowException("Monitor {$id} reports no video mode."), $scale);
        }
    }

    /** GLFW puts the monitor's mode back itself when the window leaves it: forget the held one. */
    public static function releaseDesktop(int $id): void
    {
        unset(self::$desktop[$id]);
    }

    public static function mode(int $id, GLFWvidmode $vidmode, float $scale): DisplayMode
    {
        return new DisplayMode($id, $vidmode->width, $vidmode->height, $scale, (float) $vidmode->refreshRate);
    }
}
