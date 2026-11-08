<?php

namespace Jovian\Toolkits\Glfw\Input;

use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;

/** GLFW's key, modifier and button numbers as Surface's. A key Surface has no name for is Key::UNKNOWN. */
final class GlfwKeyMap
{
    public static function key(int $glfw): Key
    {
        return match ($glfw) {
            GLFW_KEY_A => Key::A,
            GLFW_KEY_B => Key::B,
            GLFW_KEY_C => Key::C,
            GLFW_KEY_D => Key::D,
            GLFW_KEY_E => Key::E,
            GLFW_KEY_F => Key::F,
            GLFW_KEY_G => Key::G,
            GLFW_KEY_H => Key::H,
            GLFW_KEY_I => Key::I,
            GLFW_KEY_J => Key::J,
            GLFW_KEY_K => Key::K,
            GLFW_KEY_L => Key::L,
            GLFW_KEY_M => Key::M,
            GLFW_KEY_N => Key::N,
            GLFW_KEY_O => Key::O,
            GLFW_KEY_P => Key::P,
            GLFW_KEY_Q => Key::Q,
            GLFW_KEY_R => Key::R,
            GLFW_KEY_S => Key::S,
            GLFW_KEY_T => Key::T,
            GLFW_KEY_U => Key::U,
            GLFW_KEY_V => Key::V,
            GLFW_KEY_W => Key::W,
            GLFW_KEY_X => Key::X,
            GLFW_KEY_Y => Key::Y,
            GLFW_KEY_Z => Key::Z,
            GLFW_KEY_0 => Key::DIGIT_0,
            GLFW_KEY_1 => Key::DIGIT_1,
            GLFW_KEY_2 => Key::DIGIT_2,
            GLFW_KEY_3 => Key::DIGIT_3,
            GLFW_KEY_4 => Key::DIGIT_4,
            GLFW_KEY_5 => Key::DIGIT_5,
            GLFW_KEY_6 => Key::DIGIT_6,
            GLFW_KEY_7 => Key::DIGIT_7,
            GLFW_KEY_8 => Key::DIGIT_8,
            GLFW_KEY_9 => Key::DIGIT_9,
            GLFW_KEY_F1 => Key::F1,
            GLFW_KEY_F2 => Key::F2,
            GLFW_KEY_F3 => Key::F3,
            GLFW_KEY_F4 => Key::F4,
            GLFW_KEY_F5 => Key::F5,
            GLFW_KEY_F6 => Key::F6,
            GLFW_KEY_F7 => Key::F7,
            GLFW_KEY_F8 => Key::F8,
            GLFW_KEY_F9 => Key::F9,
            GLFW_KEY_F10 => Key::F10,
            GLFW_KEY_F11 => Key::F11,
            GLFW_KEY_F12 => Key::F12,
            GLFW_KEY_KP_0 => Key::NUMPAD_0,
            GLFW_KEY_KP_1 => Key::NUMPAD_1,
            GLFW_KEY_KP_2 => Key::NUMPAD_2,
            GLFW_KEY_KP_3 => Key::NUMPAD_3,
            GLFW_KEY_KP_4 => Key::NUMPAD_4,
            GLFW_KEY_KP_5 => Key::NUMPAD_5,
            GLFW_KEY_KP_6 => Key::NUMPAD_6,
            GLFW_KEY_KP_7 => Key::NUMPAD_7,
            GLFW_KEY_KP_8 => Key::NUMPAD_8,
            GLFW_KEY_KP_9 => Key::NUMPAD_9,
            GLFW_KEY_UP => Key::UP,
            GLFW_KEY_DOWN => Key::DOWN,
            GLFW_KEY_LEFT => Key::LEFT,
            GLFW_KEY_RIGHT => Key::RIGHT,
            GLFW_KEY_SPACE => Key::SPACE,
            GLFW_KEY_ENTER => Key::ENTER,
            GLFW_KEY_ESCAPE => Key::ESCAPE,
            GLFW_KEY_TAB => Key::TAB,
            GLFW_KEY_BACKSPACE => Key::BACKSPACE,
            GLFW_KEY_DELETE => Key::DELETE,
            GLFW_KEY_INSERT => Key::INSERT,
            GLFW_KEY_HOME => Key::HOME,
            GLFW_KEY_END => Key::END,
            GLFW_KEY_PAGE_UP => Key::PAGE_UP,
            GLFW_KEY_PAGE_DOWN => Key::PAGE_DOWN,
            GLFW_KEY_CAPS_LOCK => Key::CAPS_LOCK,
            GLFW_KEY_LEFT_SHIFT => Key::LEFT_SHIFT,
            GLFW_KEY_RIGHT_SHIFT => Key::RIGHT_SHIFT,
            GLFW_KEY_LEFT_CONTROL => Key::LEFT_CTRL,
            GLFW_KEY_RIGHT_CONTROL => Key::RIGHT_CTRL,
            GLFW_KEY_LEFT_ALT => Key::LEFT_ALT,
            GLFW_KEY_RIGHT_ALT => Key::RIGHT_ALT,
            GLFW_KEY_LEFT_SUPER => Key::LEFT_META,
            GLFW_KEY_RIGHT_SUPER => Key::RIGHT_META,
            GLFW_KEY_MINUS => Key::MINUS,
            GLFW_KEY_EQUAL => Key::EQUALS,
            GLFW_KEY_LEFT_BRACKET => Key::LEFT_BRACKET,
            GLFW_KEY_RIGHT_BRACKET => Key::RIGHT_BRACKET,
            GLFW_KEY_BACKSLASH => Key::BACKSLASH,
            GLFW_KEY_SEMICOLON => Key::SEMICOLON,
            GLFW_KEY_APOSTROPHE => Key::APOSTROPHE,
            GLFW_KEY_GRAVE_ACCENT => Key::GRAVE,
            GLFW_KEY_COMMA => Key::COMMA,
            GLFW_KEY_PERIOD => Key::PERIOD,
            GLFW_KEY_SLASH => Key::SLASH,
            GLFW_KEY_KP_DIVIDE => Key::NUMPAD_DIVIDE,
            GLFW_KEY_KP_MULTIPLY => Key::NUMPAD_MULTIPLY,
            GLFW_KEY_KP_SUBTRACT => Key::NUMPAD_MINUS,
            GLFW_KEY_KP_ADD => Key::NUMPAD_PLUS,
            GLFW_KEY_KP_ENTER => Key::NUMPAD_ENTER,
            GLFW_KEY_KP_DECIMAL => Key::NUMPAD_PERIOD,
            GLFW_KEY_KP_EQUAL => Key::NUMPAD_EQUALS,
            GLFW_KEY_PRINT_SCREEN => Key::PRINT_SCREEN,
            GLFW_KEY_SCROLL_LOCK => Key::SCROLL_LOCK,
            GLFW_KEY_PAUSE => Key::PAUSE,
            GLFW_KEY_NUM_LOCK => Key::NUM_LOCK,
            GLFW_KEY_MENU => Key::MENU,
            default => Key::UNKNOWN,
        };
    }

    /** The mods bits GLFW passes with a key or button event. */
    public static function modifiers(int $mods): Modifiers
    {
        return new Modifiers(
            shift: ($mods & GLFW_MOD_SHIFT) !== 0,
            ctrl: ($mods & GLFW_MOD_CONTROL) !== 0,
            alt: ($mods & GLFW_MOD_ALT) !== 0,
            meta: ($mods & GLFW_MOD_SUPER) !== 0,
        );
    }

    /**
     * The modifiers held now, from the state of the eight modifier keys (left or right of each): GLFW passes no mods
     * with cursor motion or a scroll, but its key state is current there. $pressed answers glfwGetKey() === GLFW_PRESS.
     *
     * @param  callable(int): bool  $pressed
     */
    public static function held(callable $pressed): Modifiers
    {
        return new Modifiers(
            shift: $pressed(GLFW_KEY_LEFT_SHIFT) || $pressed(GLFW_KEY_RIGHT_SHIFT),
            ctrl: $pressed(GLFW_KEY_LEFT_CONTROL) || $pressed(GLFW_KEY_RIGHT_CONTROL),
            alt: $pressed(GLFW_KEY_LEFT_ALT) || $pressed(GLFW_KEY_RIGHT_ALT),
            meta: $pressed(GLFW_KEY_LEFT_SUPER) || $pressed(GLFW_KEY_RIGHT_SUPER),
        );
    }

    /** A GLFW_MOUSE_BUTTON_n as a mouse button: 1 left, 2 right, 3 middle, 4 and 5 the side buttons; null for the rest. */
    public static function button(int $glfw): ?MouseButton
    {
        return match ($glfw) {
            GLFW_MOUSE_BUTTON_1 => MouseButton::LEFT,
            GLFW_MOUSE_BUTTON_2 => MouseButton::RIGHT,
            GLFW_MOUSE_BUTTON_3 => MouseButton::MIDDLE,
            GLFW_MOUSE_BUTTON_4 => MouseButton::X1,
            GLFW_MOUSE_BUTTON_5 => MouseButton::X2,
            default => null,
        };
    }
}
