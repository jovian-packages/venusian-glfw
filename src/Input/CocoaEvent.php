<?php

namespace Jovian\Toolkits\Glfw\Input;

use NSApplication;
use NSEvent;
use NSEventType;
use Surface\Contracts\Windows\WindowException;

/**
 * What GLFW does not report on macOS and the OS does: a press's click count and a scroll's precision. GLFW calls a
 * window's callbacks while the application dispatches an NSEvent, and that event is the application's current event,
 * so its clickCount() and hasPreciseScrollingDeltas() belong to the press or scroll being reported.
 * Nothing here guesses: an event of another kind is an error, not a default.
 */
final class CocoaEvent
{
    /** The click count of the mouse press being dispatched. */
    public static function clicks(): int
    {
        return self::clicksOf(self::current());
    }

    /** Whether the scroll being dispatched has precise deltas (a trackpad's), not a wheel's ticks. */
    public static function precise(): bool
    {
        return self::preciseOf(self::current());
    }

    /**
     * @throws WindowException When $event is not a press of a mouse button.
     */
    public static function clicksOf(?NSEvent $event): int
    {
        if (is_null($event) || ! in_array(self::typeOf($event), [NSEventType::LEFT_MOUSE_DOWN->value, NSEventType::RIGHT_MOUSE_DOWN->value, NSEventType::OTHER_MOUSE_DOWN->value], true)) {
            throw new WindowException('glfw/cocoa: no mouse press is the current event, so its click count is unknown.');
        }

        return $event->clickCount();
    }

    /**
     * @throws WindowException When $event is not a scroll.
     */
    public static function preciseOf(?NSEvent $event): bool
    {
        if (is_null($event) || self::typeOf($event) !== NSEventType::SCROLL_WHEEL->value) {
            throw new WindowException('glfw/cocoa: no scroll is the current event, so its precision is unknown.');
        }

        return $event->hasPreciseScrollingDeltas();
    }

    private static function current(): ?NSEvent
    {
        return NSApplication::sharedApplication()->currentEvent();
    }

    private static function typeOf(NSEvent $event): int
    {
        $type = $event->type();

        return $type instanceof NSEventType ? $type->value : $type;
    }
}
