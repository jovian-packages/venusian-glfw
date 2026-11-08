<?php

declare(strict_types=1);

use Jovian\Toolkits\Glfw\Input\CocoaEvent;
use Surface\Contracts\Windows\WindowException;

/*
 * The click count and the scroll precision of a GLFW event on macOS come from the NSEvent the application is
 * handling. These build NSEvents by hand (no window, no GLFW) and read them the way the window does.
 */

function pressEvent(NSEventType $type, int $clicks): NSEvent
{
    return NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        $type, new NSPoint(0.0, 0.0), 0, 0.0, 0, null, 0, $clicks, 1.0,
    );
}

function wheelEvent(CGScrollEventUnit $units): NSEvent
{
    return NSEvent::eventWithCGEvent(CGEvent::createScrollWheelEvent2(null, $units, 2, -1, 0, 0));
}

describe('on macOS', function (): void {
    it('reads the OS click count of a press of each button', function (): void {
        expect(CocoaEvent::clicksOf(pressEvent(NSEventType::LEFT_MOUSE_DOWN, 1)))->toBe(1)
            ->and(CocoaEvent::clicksOf(pressEvent(NSEventType::LEFT_MOUSE_DOWN, 2)))->toBe(2)
            ->and(CocoaEvent::clicksOf(pressEvent(NSEventType::RIGHT_MOUSE_DOWN, 3)))->toBe(3)
            ->and(CocoaEvent::clicksOf(pressEvent(NSEventType::OTHER_MOUSE_DOWN, 2)))->toBe(2);
    });

    it('refuses to count a click when the current event is no press', function (?NSEvent $event): void {
        expect(fn () => CocoaEvent::clicksOf($event))->toThrow(WindowException::class, 'no mouse press');
    })->with([
        'a release' => fn () => pressEvent(NSEventType::LEFT_MOUSE_UP, 1),
        'a move' => fn () => pressEvent(NSEventType::MOUSE_MOVED, 0),
        'no event' => null,
    ]);

    it('reads the OS precision of a scroll: pixels from a trackpad, lines from a wheel', function (): void {
        expect(CocoaEvent::preciseOf(wheelEvent(CGScrollEventUnit::PIXEL)))->toBeTrue()
            ->and(CocoaEvent::preciseOf(wheelEvent(CGScrollEventUnit::LINE)))->toBeFalse();
    });

    it('refuses to read precision when the current event is no scroll', function (?NSEvent $event): void {
        expect(fn () => CocoaEvent::preciseOf($event))->toThrow(WindowException::class, 'no scroll');
    })->with([
        'a press' => fn () => pressEvent(NSEventType::LEFT_MOUSE_DOWN, 1),
        'no event' => null,
    ]);
})->skip(PHP_OS_FAMILY !== 'Darwin', 'the click count and precision come from AppKit: macOS');
