<?php

declare(strict_types=1);

use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\Contracts\Windows\Mail\Staged\StagedDragged;
use Surface\Contracts\Windows\Mail\Staged\StagedEntered;
use Surface\Contracts\Windows\Mail\Staged\StagedKeyPressed;
use Surface\Contracts\Windows\Mail\Staged\StagedKeyReleased;
use Surface\Contracts\Windows\Mail\Staged\StagedLeft;
use Surface\Contracts\Windows\Mail\Staged\StagedMoved;
use Surface\Contracts\Windows\Mail\Staged\StagedPressed;
use Surface\Contracts\Windows\Mail\Staged\StagedReleased;
use Surface\Contracts\Windows\Mail\Staged\StagedTextCommitted;
use Surface\Contracts\Windows\Mail\Staged\StagedWheeled;

/*
 * Opens a hidden window: a GUI run. GLFW cannot synthesise input, so each test takes the callable the
 * window registered (glfwSet*Callback returns the one it replaces) and calls it with GLFW's arguments.
 */

afterEach(fn () => stager()->closeAllStaged());

/** A watching, hidden window and the callbacks it registered, by name. */
function inputWindow(bool $watching = true): array
{
    stager()->getStaged('in')?->close();
    $window = stager()->openStaged('in', 160, 120, ['visible' => false]);
    $watching && $window->watchInput();
    takeMail();
    $native = $window->native();
    $callbacks = [];
    foreach (['Key', 'Char', 'MouseButton', 'CursorPos', 'CursorEnter', 'Scroll'] as $name) {
        $callbacks[$name] = ('glfwSet'.$name.'Callback')($native, null);
        expect($callbacks[$name])->toBeCallable();
    }

    return [$window, ...array_map(fn (Closure $callback) => fn (mixed ...$args) => $callback($native, ...$args), $callbacks)];
}

/**
 * On Cocoa GLFW calls its callbacks while the application dispatches an NSEvent, and that event is the
 * application's current one. Makes the event $event builds current the way the application does: posted, then
 * dequeued. Nothing elsewhere, where there is no NSEvent (and no ext-appkit).
 */
function dispatching(Closure $event): void
{
    if (glfwGetPlatform() !== GLFW_PLATFORM_COCOA) {
        return;
    }
    $app = NSApplication::sharedApplication();
    $app->postEventAtStart($event(), true);
    $app->nextEventMatchingMaskUntilDateInModeDequeue(NSEventMask::ANY, NSDate::distantPast(), NSDefaultRunLoopMode, true)
        ?? throw new RuntimeException('the posted event did not come back');
}

function pressOf(NSEventType $type, int $clicks): NSEvent
{
    return NSEvent::mouseEventWithTypeLocationModifierFlagsTimestampWindowNumberContextEventNumberClickCountPressure(
        $type, new NSPoint(0.0, 0.0), 0, 0.0, 0, null, 0, $clicks, 1.0,
    );
}

function scrollOf(CGScrollEventUnit $units): NSEvent
{
    return NSEvent::eventWithCGEvent(CGEvent::createScrollWheelEvent2(null, $units, 2, -1, 0, 0));
}

/** What a press reports: the OS's click count on Cocoa, one click where GLFW has no source. */
function clicksOf(int $os): int
{
    return glfwGetPlatform() === GLFW_PLATFORM_COCOA ? $os : 1;
}

function inputMail(): array
{
    stager()->connect()->flushLatest();

    return takeMail();
}

it('posts keys with their layout name, modifiers, a repeat flagged, and committed text', function (): void {
    ['0' => $window, 'Key' => $key, 'Char' => $char] = inputWindow();
    $shift = new Modifiers(shift: true);
    glfwGetKeyName(GLFW_KEY_W, 0) !== null || throw new RuntimeException('the layout names no W key');
    $name = glfwGetKeyName(GLFW_KEY_W, 0);

    $key(GLFW_KEY_W, 0, GLFW_PRESS, GLFW_MOD_SHIFT);
    $key(GLFW_KEY_W, 0, GLFW_REPEAT, GLFW_MOD_SHIFT);
    $char(0x57);
    $key(GLFW_KEY_W, 0, GLFW_RELEASE, GLFW_MOD_SHIFT);

    expect(inputMail())->toEqual([
        new StagedKeyPressed('in', Key::W, $name, $shift, false),
        new StagedKeyPressed('in', Key::W, $name, $shift, true),
        new StagedTextCommitted('in', 'W'),
        new StagedKeyReleased('in', Key::W, $shift),
    ]);
});

it('posts a press at the cursor position GLFW reports, with the OS click count, a drag by the first button, and the release', function (): void {
    ['0' => $window, 'CursorPos' => $pos, 'MouseButton' => $button] = inputWindow();
    $none = new Modifiers();

    glfwGetCursorPos($window->native(), $x, $y);
    dispatching(fn () => pressOf(NSEventType::LEFT_MOUSE_DOWN, 2));
    $button(GLFW_MOUSE_BUTTON_1, GLFW_PRESS, 0);
    dispatching(fn () => pressOf(NSEventType::RIGHT_MOUSE_DOWN, 3));
    $button(GLFW_MOUSE_BUTTON_2, GLFW_PRESS, 0);
    $pos(5.0, 6.0);
    $button(GLFW_MOUSE_BUTTON_1, GLFW_RELEASE, 0);

    expect(inputMail())->toEqual([
        new StagedPressed('in', $x, $y, MouseButton::LEFT, clicksOf(2), $none),
        new StagedPressed('in', $x, $y, MouseButton::RIGHT, clicksOf(3), $none),
        new StagedDragged('in', 5.0, 6.0, MouseButton::LEFT, $none),
        new StagedReleased('in', $x, $y, MouseButton::LEFT, $none),
    ]);
});

it('reads the modifiers of cursor motion and scrolls from GLFW\'s key state, not from the last key event', function (): void {
    ['CursorPos' => $pos, 'Scroll' => $scroll, 'Key' => $key] = inputWindow();

    // GLFW cannot synthesise input: this key event is injected, so GLFW's own state still says nothing is held.
    $key(GLFW_KEY_LEFT_CONTROL, 0, GLFW_PRESS, GLFW_MOD_CONTROL);
    $pos(1.0, 2.0);
    dispatching(fn () => scrollOf(CGScrollEventUnit::LINE));
    $scroll(0.5, -2.0);

    $none = new Modifiers();
    expect(inputMail())->toEqual([
        new StagedKeyPressed('in', Key::LEFT_CTRL, '', new Modifiers(ctrl: true), false),
        new StagedMoved('in', 1.0, 2.0, $none),
        new StagedWheeled('in', 0.5, -2.0, false, $none),
    ]);
});

it('posts a scroll as precise when the OS says its deltas are (a trackpad), not for a wheel\'s ticks', function (): void {
    ['Scroll' => $scroll] = inputWindow();
    $none = new Modifiers();

    dispatching(fn () => scrollOf(CGScrollEventUnit::PIXEL));
    $scroll(3.0, -4.0);
    dispatching(fn () => scrollOf(CGScrollEventUnit::LINE));
    $scroll(0.0, 1.0);

    $precise = glfwGetPlatform() === GLFW_PLATFORM_COCOA;
    expect(inputMail())->toEqual([
        new StagedWheeled('in', 3.0, -4.0, $precise, $none),
        new StagedWheeled('in', 0.0, 1.0, false, $none),
    ]);
});

it('posts the cursor entering and leaving', function (): void {
    ['CursorEnter' => $enter] = inputWindow();

    $enter(GLFW_TRUE);
    $enter(GLFW_FALSE);

    expect(inputMail())->toEqual([new StagedEntered('in'), new StagedLeft('in')]);
});

it('posts nothing while the window does not watch input', function (): void {
    ['Key' => $key, 'CursorPos' => $pos, 'MouseButton' => $button] = inputWindow(false);

    $key(GLFW_KEY_A, 0, GLFW_PRESS, 0);
    $pos(1.0, 1.0);
    dispatching(fn () => pressOf(NSEventType::LEFT_MOUSE_DOWN, 1));
    $button(GLFW_MOUSE_BUTTON_1, GLFW_PRESS, 0);

    expect(inputMail())->toBe([]);
});
