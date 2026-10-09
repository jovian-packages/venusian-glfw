# jovian/venusian-glfw

The `glfw` stager for Venusian Surface: staged windows on GLFW 3.4 through ext-glfw, on macOS and Linux (Wayland and X11). Each window has an OpenGL context and presents CPU frames through it.

## Requirements

- macOS or Linux with GLFW 3.4, ext-glfw ^0.10 and ext-opengl ^0.10. PHP 8.4.
- On macOS, ext-appkit for a lent GL context's CGL handle, the Metal layer and the safe area; ext-metal for the Metal layer.
- ext-vulkan, and a Vulkan loader GLFW finds, for a lent Vulkan surface.
- On the Pi, the Wayland session (`WAYLAND_DISPLAY`, `XDG_RUNTIME_DIR`), or X11 with `bridge.stage.glfw.platform` set to `x11`: GLFW finds Wayland's default socket even with only `DISPLAY` set.

## Install

```
composer require jovian/venusian-glfw
```

The provider is discovered through `extra.venusian.providers`. With Surface's bridge bound, it registers the `glfw` stager on `app('toolkit-bridge')`.

## Usage

```php
$window = app('staged-windows')->open('game', 640, 400, ['toolkit' => 'glfw', 'vsync' => VSync::Off]);
$window->setScaling(ScaleFilter::Nearest, ScaleFit::Integer);
$frame = $window->framebuffer('dirty', 320, 200);
$frame->setPixel(10, 10, 0xFF0000FF);
$window->present();                      // only the damaged texels are uploaded
$window->setMode(WindowMode::Exclusive, $window->displayModes()[0]);
```

`config('bridge.stage.glfw.platform')` picks GLFW's platform: `cocoa`, `wayland` or `x11`. Unset, GLFW chooses.

## Behaviour

- Backend: `glfw/cocoa`, `glfw/wayland` or `glfw/x11`.
- Context: OpenGL 4.1 core on macOS; OpenGL ES 3.1 over EGL elsewhere, the dialects ext-opengl and the `opengl` engine speak.
- Present: the damaged regions of the frame go into an RGBA8 texture, read in place through the unpack state (`GL_UNPACK_ROW_LENGTH`, skip pixels and rows); a new size or no damage list uploads it all. The texture is blitted into the window at `presentRect()` with the scaling filter, bars cleared, and the buffers swapped. Vsync is the swap interval: `On` 1, `Adaptive` -1, `Off` and `Mailbox` 0.
- Modes: `Fullscreen` is GLFW's full screen on a monitor at its desktop mode; `Exclusive` switches the monitor to a video mode, and GLFW gives it back when the window leaves. Maximize, minimize and restore through GLFW; the iconify and maximize callbacks report modes the user chose.
- Displays: GLFW monitors, the primary first. A display's id is its `GLFWmonitor` address; sizes are screen coordinates; a mode's pixel density is the monitor's content scale. GLFW reports no HDR.
- Capabilities, measured: macOS position, always-on-top, opacity, attention, exclusive fullscreen; X11 those and the window icon; Wayland attention only. No platform refuses focus, so `focusable: false` is refused at open. No keep-awake, frame clock or hit test.
- Aspect: an exact ratio is GLFW's own; a range is kept by correcting the size after each resize.
- Close: the close callback closes the window, or with `confirm_close` posts `WindowCloseRequested` and clears GLFW's should-close flag.
- Lending: the window's own GL context (CGL on macOS, EGL elsewhere), whose present this window drives (current, framebuffer 0, the borrower's copy, swap); a `CAMetalLayer` on a view pinned over GLFW's (macOS); a `VkSurfaceKHR`. GLFW makes a Vulkan surface only on a window without a context, so lending one remakes the native window without; the next CPU present or GL lend remakes it with. A remade window keeps its place, limits, aspect, opacity, icon, visibility and mode.
- Session: GLFW never sleeps on a descriptor, so the loop polls it at its pace. No GLFW menu bar on macOS. No libdecor on Wayland: its GTK plugin collides with a GTK already in the process.

## Presenting

EGL swaps name changed rects (`EGL_KHR_swap_buffers_with_damage`): compositor redraws only those. Wayland swaps at interval 0 (compositor paces, never tears; hidden window never blocks). Hidden window presents nothing, CPU or lent surface: a buffer committed to a hidden GLFW Wayland window makes showing it a protocol error. First present after show = whole frame. GL borrower asking `'native'` gets the native window, remade without a context.

## Testing

```bash
php84 -d memory_limit=128M vendor/bin/pest
zhp -d memory_limit=128M vendor/bin/pest
WAYLAND_DISPLAY=wayland-0 XDG_RUNTIME_DIR=/run/user/1000 GLFW_TEST_PLATFORM=wayland php -d memory_limit=128M vendor/bin/pest
DISPLAY=:0 XDG_RUNTIME_DIR=/run/user/1000 GLFW_TEST_PLATFORM=x11 php -d memory_limit=128M vendor/bin/pest
```

See [.okf/runbooks/testing.md](.okf/runbooks/testing.md).

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT. See [LICENSE](LICENSE).
