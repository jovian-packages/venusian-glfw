<?php

declare(strict_types=1);

use Jovian\Toolkits\Glfw\Input\GlfwKeyMap;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;

it('maps GLFW keys to Surface keys', function (): void {
    expect([
        GlfwKeyMap::key(GLFW_KEY_A), GlfwKeyMap::key(GLFW_KEY_0), GlfwKeyMap::key(GLFW_KEY_F12), GlfwKeyMap::key(GLFW_KEY_KP_ADD),
        GlfwKeyMap::key(GLFW_KEY_LEFT_CONTROL), GlfwKeyMap::key(GLFW_KEY_RIGHT_SUPER), GlfwKeyMap::key(GLFW_KEY_GRAVE_ACCENT),
        GlfwKeyMap::key(GLFW_KEY_EQUAL), GlfwKeyMap::key(GLFW_KEY_ENTER), GlfwKeyMap::key(GLFW_KEY_MENU),
    ])->toBe([
        Key::A, Key::DIGIT_0, Key::F12, Key::NUMPAD_PLUS, Key::LEFT_CTRL, Key::RIGHT_META, Key::GRAVE, Key::EQUALS, Key::ENTER, Key::MENU,
    ]);
});

it('maps a key Surface has no name for to UNKNOWN', function (): void {
    expect([GlfwKeyMap::key(GLFW_KEY_UNKNOWN), GlfwKeyMap::key(GLFW_KEY_F13), GlfwKeyMap::key(GLFW_KEY_WORLD_1)])
        ->toBe([Key::UNKNOWN, Key::UNKNOWN, Key::UNKNOWN]);
});

it('maps every key it names to a different Surface key', function (): void {
    $mapped = [];
    foreach (get_defined_constants(true)['glfw'] as $name => $value) {
        if (str_starts_with($name, 'GLFW_KEY_') && $name !== 'GLFW_KEY_LAST' && GlfwKeyMap::key($value) !== Key::UNKNOWN) {
            $mapped[] = GlfwKeyMap::key($value);
        }
    }

    expect($mapped)->toHaveCount(count(array_unique(array_map(fn (Key $key) => $key->value, $mapped))));
});

it('reads the mods bits as modifiers', function (): void {
    expect(GlfwKeyMap::modifiers(0))->toEqual(new Modifiers())
        ->and(GlfwKeyMap::modifiers(GLFW_MOD_SHIFT | GLFW_MOD_SUPER))->toEqual(new Modifiers(shift: true, meta: true))
        ->and(GlfwKeyMap::modifiers(GLFW_MOD_CONTROL | GLFW_MOD_ALT))->toEqual(new Modifiers(ctrl: true, alt: true));
});

it('reads the modifiers held from the state of the eight modifier keys, left or right', function (): void {
    $held = fn (int ...$keys): Modifiers => GlfwKeyMap::held(fn (int $key): bool => in_array($key, $keys, true));

    expect($held())->toEqual(new Modifiers())
        ->and($held(GLFW_KEY_LEFT_SHIFT))->toEqual(new Modifiers(shift: true))
        ->and($held(GLFW_KEY_RIGHT_SHIFT))->toEqual(new Modifiers(shift: true))
        ->and($held(GLFW_KEY_RIGHT_CONTROL, GLFW_KEY_LEFT_ALT))->toEqual(new Modifiers(ctrl: true, alt: true))
        ->and($held(GLFW_KEY_LEFT_SUPER, GLFW_KEY_LEFT_SHIFT))->toEqual(new Modifiers(shift: true, meta: true))
        ->and($held(GLFW_KEY_A, GLFW_KEY_ENTER))->toEqual(new Modifiers());
});

it('asks only about the eight modifier keys', function (): void {
    $asked = [];
    GlfwKeyMap::held(function (int $key) use (&$asked): bool {
        $asked[] = $key;

        return false;
    });

    expect($asked)->toEqualCanonicalizing([
        GLFW_KEY_LEFT_SHIFT, GLFW_KEY_RIGHT_SHIFT, GLFW_KEY_LEFT_CONTROL, GLFW_KEY_RIGHT_CONTROL,
        GLFW_KEY_LEFT_ALT, GLFW_KEY_RIGHT_ALT, GLFW_KEY_LEFT_SUPER, GLFW_KEY_RIGHT_SUPER,
    ]);
});

it('maps GLFW buttons 1 to 5 as left, right, middle, x1, x2', function (): void {
    expect(array_map(GlfwKeyMap::button(...), [GLFW_MOUSE_BUTTON_1, GLFW_MOUSE_BUTTON_2, GLFW_MOUSE_BUTTON_3, GLFW_MOUSE_BUTTON_4, GLFW_MOUSE_BUTTON_5, GLFW_MOUSE_BUTTON_8]))
        ->toBe([MouseButton::LEFT, MouseButton::RIGHT, MouseButton::MIDDLE, MouseButton::X1, MouseButton::X2, null]);
});
