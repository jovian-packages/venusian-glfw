<?php

namespace Jovian\Toolkits\Glfw\Windows;

use CALayer;
use CAMetalLayer;
use Closure;
use EGLDisplay;
use EGLSurface;
use GLFWimage;
use GLFWmonitor;
use GLFWwindow;
use Jovian\Toolkits\Glfw\Bridge\GlfwBridgeDriver;
use Jovian\Toolkits\Glfw\Bridge\GlfwSession;
use NSLayoutConstraint;
use NSOpenGLContext;
use NSRect;
use NSView;
use Surface\Contracts\Drawing\LentSurface;
use Surface\Contracts\Drawing\SurfaceBorrower;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Framebuffers\DamageTrackingFramebuffer;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\DisplayMode;
use Surface\Contracts\Windows\Hdr;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowException;
use Surface\Contracts\Windows\WindowMode;
use Surface\Drawing\Gpu\DamageHistory;
use Surface\Windows\StagedWindow;
use VkInstance;
use VkSurfaceKHR;

/**
 * A staged window on GLFW 3.4. Its backend names GLFW's platform:
 * glfw/cocoa, glfw/wayland, glfw/x11.
 *
 * The window has an OpenGL context (4.1 core on macOS; OpenGL ES 3.1 over
 * EGL elsewhere, the dialects ext-opengl and the opengl engine speak).
 * present() uploads only the damaged regions of the frame into a texture,
 * then blits the texture into the window at presentRect() with the scaling
 * filter, bars cleared, and swaps. On EGL the swap names the rects that
 * changed (EGL_KHR_swap_buffers_with_damage), so the compositor redraws only
 * those. On Wayland every swap is at interval 0: a swap waiting for vsync
 * waits for a frame callback a hidden window never gets, and the compositor
 * shows whole frames at its refresh either way. Elsewhere the swap interval
 * is the vsync. A hidden window presents nothing, through a lent surface or
 * not: on Wayland a hidden GLFW window has no shell role, and a buffer
 * committed to it makes showing it again a protocol error. The first present
 * after showing is the whole frame. A GL borrower that asks for 'native' gets the native window,
 * remade without a context, and swaps it itself.
 *
 * Fullscreen is GLFW's windowed full screen on a monitor at its current mode;
 * Exclusive switches the monitor to a video mode, and GLFW puts it back when
 * the window leaves. Callbacks give size, position, focus, iconify, maximize,
 * scale, refresh and close.
 *
 * Lends its own GL context (CGL through ext-appkit on macOS; EGL elsewhere),
 * a CAMetalLayer on a view pinned over its own (macOS, ext-appkit and
 * ext-metal), or a VkSurfaceKHR (ext-vulkan). GLFW makes a Vulkan surface only
 * on a window without a context, so lending one remakes the native window
 * without; the next CPU present or GL lend remakes it with. A remade window
 * keeps its place, limits, aspect, opacity, icon, visibility and mode.
 */
class GlfwStagedWindow extends StagedWindow
{
    protected ?GLFWwindow $window = null;

    protected readonly string $platform_name;

    protected bool $key = false;

    /** The mode last reported through modeChanged(). */
    protected WindowMode $native_mode = WindowMode::Windowed;

    /** Whether the window holds its monitor at a video mode of its own. */
    protected bool $exclusive_held = false;

    /** @var array{int, int, int, int}|null the windowed place and size full screen replaced */
    protected ?array $windowed = null;

    /** GLFW_OPENGL_API (the window's context) or GLFW_NO_API (a Vulkan lend). */
    protected int $api = GLFW_OPENGL_API;

    protected ?int $texture = null;

    protected ?int $read_framebuffer = null;

    /** @var array{int, int} the texture's size */
    protected array $texture_size = [0, 0];

    protected int $last_display = 0;

    protected bool $clamping = false;

    /** @var array{int, int}|null a size just asked for: X11 reports it only once the server has applied it */
    protected ?array $asked = null;

    /** @var array{string, int, int}|null */
    protected ?array $icon = null;

    protected ?NSView $metal_view = null;

    /** @var list<NSLayoutConstraint> */
    protected array $metal_pins = [];

    protected ?int $vk_instance = null;

    protected ?int $vk_surface = null;

    /** @var list<Region> the regions the last present uploaded, for tests */
    public array $uploaded = [];

    /** @var list<int>|null the rects the last swap named, four ints each, origin bottom-left; null after a whole swap, for tests */
    public ?array $swapped = null;

    protected DamageHistory $history;

    /** Whether the lent GL surface is the native window, presented and swapped by its borrower. */
    protected bool $native_gl = false;

    public function __construct(
        string $name,
        GlfwSession $session,
        protected readonly GlfwBridgeDriver $driver,
        int $width,
        int $height,
        array $options,
    ) {
        $this->history = new DamageHistory(1);
        parent::__construct($name, $session, $options);
        $this->platform_name = match ($session->platform()) {
            GLFW_PLATFORM_COCOA => 'cocoa',
            GLFW_PLATFORM_WAYLAND => 'wayland',
            GLFW_PLATFORM_X11 => 'x11',
            default => 'other',
        };
        if (! $options['focusable']) {
            throw new WindowException("Staged window '{$name}' cannot refuse focus: the glfw/{$this->platform_name} backend does not support it.");
        }

        $place = null;
        if ($this->placeable()) {
            if (! is_null($options['x'])) {
                $place = [$options['x'], $options['y']];
            } elseif (! is_null($options['display'])) {
                $place = $this->centredOn($options['display'], $width, $height);
            }
        }
        $this->window = $this->makeWindow($width, $height, $place, GLFW_OPENGL_API);
        $this->last_display = $this->currentMonitor()?->pointer() ?? 0;

        $this->stage($options);
    }

    public function backend(): string
    {
        return "glfw/{$this->platform_name}";
    }

    public function isKey(): bool
    {
        return $this->key;
    }

    /** The native window, for engines and tests. */
    public function native(): GLFWwindow
    {
        return $this->window ?? throw new WindowException("Staged window '{$this->name}' was closed.");
    }

    public function surfaces(): array
    {
        $mac = $this->platform_name === 'cocoa';
        $kinds = [];
        if (! $mac || class_exists(NSOpenGLContext::class)) {
            $kinds[] = SurfaceKind::GL_CONTEXT;
        }
        if ($mac && class_exists(CAMetalLayer::class) && class_exists(NSView::class)) {
            $kinds[] = SurfaceKind::METAL_LAYER;
        }
        if (extension_loaded('vulkan') && glfwVulkanSupported()) {
            $kinds[] = SurfaceKind::VULKAN_SURFACE;
        }

        return $kinds;
    }

    /** Measured per platform: macOS has no window icon; Wayland neither places, stacks, fades nor iconizes windows, nor switches modes. */
    protected function nativeCapabilities(): array
    {
        return match ($this->platform_name) {
            'cocoa' => [WindowCapability::Position, WindowCapability::AlwaysOnTop, WindowCapability::Opacity, WindowCapability::Attention, WindowCapability::ExclusiveFullscreen],
            'x11' => [WindowCapability::Position, WindowCapability::AlwaysOnTop, WindowCapability::Opacity, WindowCapability::Icon, WindowCapability::Attention, WindowCapability::ExclusiveFullscreen],
            default => [WindowCapability::Attention],
        };
    }

    /**
     * The GLFW window: the creation-time style this window holds, hidden, the
     * client API $api, at $place or where the platform puts it, callbacks bound.
     *
     * @param  array{int, int}|null  $place
     * @throws WindowException When GLFW makes no window.
     */
    protected function makeWindow(int $width, int $height, ?array $place, int $api): GLFWwindow
    {
        glfwDefaultWindowHints();
        glfwWindowHint(GLFW_VISIBLE, GLFW_FALSE);
        glfwWindowHint(GLFW_RESIZABLE, $this->resizable ? GLFW_TRUE : GLFW_FALSE);
        glfwWindowHint(GLFW_DECORATED, $this->borderless ? GLFW_FALSE : GLFW_TRUE);
        glfwWindowHint(GLFW_FLOATING, $this->always_on_top ? GLFW_TRUE : GLFW_FALSE);
        glfwWindowHint(GLFW_TRANSPARENT_FRAMEBUFFER, $this->transparent ? GLFW_TRUE : GLFW_FALSE);
        glfwWindowHint(GLFW_SCALE_FRAMEBUFFER, GLFW_TRUE);
        glfwWindowHint(GLFW_SCALE_TO_MONITOR, GLFW_TRUE);
        if (! is_null($place)) {
            glfwWindowHint(GLFW_POSITION_X, $place[0]);
            glfwWindowHint(GLFW_POSITION_Y, $place[1]);
        }
        if ($api === GLFW_NO_API) {
            glfwWindowHint(GLFW_CLIENT_API, GLFW_NO_API);
        } elseif ($this->platform_name === 'cocoa') {
            glfwWindowHint(GLFW_CLIENT_API, GLFW_OPENGL_API);
            glfwWindowHint(GLFW_CONTEXT_VERSION_MAJOR, 4);
            glfwWindowHint(GLFW_CONTEXT_VERSION_MINOR, 1);
            glfwWindowHint(GLFW_OPENGL_PROFILE, GLFW_OPENGL_CORE_PROFILE);
            glfwWindowHint(GLFW_OPENGL_FORWARD_COMPAT, GLFW_TRUE);
        } else {
            glfwWindowHint(GLFW_CLIENT_API, GLFW_OPENGL_ES_API);
            glfwWindowHint(GLFW_CONTEXT_CREATION_API, GLFW_EGL_CONTEXT_API);
            glfwWindowHint(GLFW_CONTEXT_VERSION_MAJOR, 3);
            glfwWindowHint(GLFW_CONTEXT_VERSION_MINOR, 1);
        }
        $window = glfwCreateWindow($width, $height, $this->title);
        if (is_null($window)) {
            glfwGetError($description);

            throw new WindowException("Staged window '{$this->name}' could not be made by GLFW: ".($description ?? 'no description'));
        }
        $this->api = $api;
        $this->texture = null;
        $this->read_framebuffer = null;
        $this->texture_size = [0, 0];
        $this->bind($window);

        return $window;
    }

    /** This window's callbacks on the GLFW window. */
    protected function bind(GLFWwindow $window): void
    {
        $session = $this->session;
        glfwSetWindowPosCallback($window, function (GLFWwindow $w, int $x, int $y) use ($session): void {
            $session->dispatched();
            $this->moved($x, $y);
            $this->checkDisplay();
        });
        glfwSetWindowSizeCallback($window, function (GLFWwindow $w, int $width, int $height) use ($session): void {
            $session->dispatched();
            $this->resized($width, $height);
            $this->keepAspect();
        });
        glfwSetWindowCloseCallback($window, function (GLFWwindow $w) use ($session): void {
            $session->dispatched();
            $this->closeRequested();
            if ($this->open) {
                glfwSetWindowShouldClose($w, false);
            }
        });
        glfwSetWindowRefreshCallback($window, function (GLFWwindow $w) use ($session): void {
            $session->dispatched();
            $this->shown = null;                                         // the OS lost the pixels: the next present is whole
        });
        glfwSetWindowFocusCallback($window, function (GLFWwindow $w, int $focused) use ($session): void {
            $session->dispatched();
            $this->key = $focused !== 0;
            $this->key ? $this->focused() : $this->focusLost();
        });
        glfwSetWindowIconifyCallback($window, function () use ($session): void {
            $session->dispatched();
            $this->syncMode();
        });
        glfwSetWindowMaximizeCallback($window, function () use ($session): void {
            $session->dispatched();
            $this->syncMode();
        });
        glfwSetWindowContentScaleCallback($window, function (GLFWwindow $w, float $x, float $y) use ($session): void {
            $session->dispatched();
            $this->scaleChanged($this->nativeScale());
        });
    }

    protected function nativeSize(): array
    {
        glfwGetWindowSize($this->native(), $width, $height);

        return [$width, $height];
    }

    /** Framebuffer pixels per window unit. */
    protected function nativeScale(): float
    {
        glfwGetWindowSize($this->native(), $width, $height);
        glfwGetFramebufferSize($this->native(), $pixels, $rows);
        if ($width > 0) {
            return $pixels / $width;
        }
        glfwGetWindowContentScale($this->native(), $x, $y);

        return $x;
    }

    protected function nativePosition(): array
    {
        glfwGetWindowPos($this->native(), $x, $y);

        return [$x, $y];
    }

    /** The content view's safe area on macOS with ext-appkit; elsewhere GLFW knows none, and the window is whole. */
    protected function nativeSafeArea(): Region
    {
        [$width, $height] = $this->nativeSize();
        if ($this->platform_name !== 'cocoa' || ! class_exists(NSView::class)) {
            return new Region(0, 0, $width, $height);
        }
        $view = NSView::fromPointer(glfwGetCocoaView($this->native()));
        $area = $view->safeAreaRect();

        return new Region((int) round($area->x), (int) round($view->bounds()->height - $area->y - $area->height), (int) round($area->width), (int) round($area->height));
    }

    protected function nativeDisplay(): Display
    {
        return GlfwDisplays::describe($this->currentMonitor() ?? throw new WindowException('GLFW reports no monitor.'));
    }

    protected function nativeDisplayModes(): array
    {
        return GlfwDisplays::modes($this->currentMonitor() ?? throw new WindowException('GLFW reports no monitor.'));
    }

    /** GLFW reports no HDR state. */
    protected function nativeHdr(): ?Hdr
    {
        return null;
    }

    protected function applyTitle(string $title): void
    {
        glfwSetWindowTitle($this->native(), $title);
    }

    protected function applyVisible(bool $visible): void
    {
        $visible ? glfwShowWindow($this->native()) : glfwHideWindow($this->native());
        if ($visible) {
            glfwFocusWindow($this->native());
            // Nothing was presented while hidden: the next present is the whole frame.
            $this->shown = null;
        }
    }

    /** @throws WindowException When the display or mode asked for is not connected. */
    protected function applyMode(WindowMode $mode, Display|DisplayMode|null $on): void
    {
        $window = $this->native();
        $held = ! is_null(glfwGetWindowMonitor($window));
        if ($held && ! in_array($mode, [WindowMode::Fullscreen, WindowMode::Exclusive], true)) {
            $this->leaveMonitor();
        }
        if ($mode !== WindowMode::Minimized && glfwGetWindowAttrib($window, GLFW_ICONIFIED) === GLFW_TRUE) {
            glfwRestoreWindow($window);
        }
        match ($mode) {
            WindowMode::Windowed => glfwGetWindowAttrib($window, GLFW_MAXIMIZED) === GLFW_TRUE ? glfwRestoreWindow($window) : null,
            WindowMode::Maximized => glfwMaximizeWindow($window),
            WindowMode::Minimized => glfwIconifyWindow($window),
            WindowMode::Fullscreen => $this->coverMonitor($on),
            WindowMode::Exclusive => $this->holdMonitor($on),
        };
        $this->syncMode();
    }

    /** Wayland reports no size callback for a size asked for: the aspect range is kept here too. */
    protected function applyResize(int $width, int $height): void
    {
        glfwSetWindowSize($this->native(), $width, $height);
        $this->asked = [$width, $height];
        $this->keepAspect();
    }

    protected function applyPosition(int $x, int $y): void
    {
        glfwSetWindowPos($this->native(), $x, $y);
    }

    protected function applyDisplay(Display $display): void
    {
        [$width, $height] = $this->nativeSize();
        glfwSetWindowPos($this->native(), ...$this->centredOn($display, $width, $height));
    }

    protected function applyLimits(int $minWidth, int $minHeight, int $maxWidth, int $maxHeight): void
    {
        glfwSetWindowSizeLimits($this->native(), $minWidth, $minHeight, $maxWidth > 0 ? $maxWidth : GLFW_DONT_CARE, $maxHeight > 0 ? $maxHeight : GLFW_DONT_CARE);
    }

    /** GLFW keeps one exact ratio, as a fraction; a range is kept by correcting the size after each resize. */
    protected function applyAspectRatio(float $min, float $max): void
    {
        if ($min > 0.0 && $min === $max) {
            $denominator = 1000;
            $numerator = (int) round($min * $denominator);
            $gcd = self::gcd($numerator, $denominator);
            glfwSetWindowAspectRatio($this->native(), intdiv($numerator, $gcd), intdiv($denominator, $gcd));
        } else {
            glfwSetWindowAspectRatio($this->native(), GLFW_DONT_CARE, GLFW_DONT_CARE);
            $this->keepAspect();
        }
    }

    protected function applyResizable(bool $resizable): void
    {
        glfwSetWindowAttrib($this->native(), GLFW_RESIZABLE, $resizable ? GLFW_TRUE : GLFW_FALSE);
    }

    protected function applyBorderless(bool $borderless): void
    {
        glfwSetWindowAttrib($this->native(), GLFW_DECORATED, $borderless ? GLFW_FALSE : GLFW_TRUE);
    }

    /** The swap interval of the window's context: 1, 0, or -1 for adaptive. */
    protected function applyVsync(VSync $vsync): void
    {
        if ($this->api === GLFW_OPENGL_API) {
            glfwMakeContextCurrent($this->native());
            glfwSwapInterval($this->interval($vsync));
        }
    }

    protected function applyAlwaysOnTop(bool $onTop): void
    {
        glfwSetWindowAttrib($this->native(), GLFW_FLOATING, $onTop ? GLFW_TRUE : GLFW_FALSE);
    }

    protected function applyOpacity(float $opacity): void
    {
        glfwSetWindowOpacity($this->native(), $opacity);
    }

    protected function applyAttention(): void
    {
        glfwRequestWindowAttention($this->native());
    }

    protected function applyIcon(string $rgba8, int $width, int $height): void
    {
        glfwSetWindowIcon($this->native(), [new GLFWimage($width, $height, $rgba8)]);
        $this->icon = [$rgba8, $width, $height];
    }

    protected function applyPixels(string $rgba8, int $width, int $height, array $damage): void
    {
        $this->upload($rgba8, $width, $height, $width * 4, $damage);
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $this->upload($address, $width, $height, $stride, $damage);
    }

    /**
     * The damaged regions of the frame (all of it for a new size or no damage
     * list) into the texture, read in place by the unpack state; then the
     * texture blitted into the window at presentRect(), bars cleared, swapped.
     *
     * @param  list<Region>  $damage
     */
    protected function upload(string|int $pixels, int $width, int $height, int $stride, array $damage): void
    {
        // A hidden window has no shell role on Wayland: a buffer committed now makes showing it a protocol error.
        if (! $this->visible) {
            return;
        }
        $this->withContext();
        $fresh = $this->texture_size !== [$width, $height];
        if ($fresh) {
            $this->makeTexture($width, $height);
        }
        glBindTexture(GL_TEXTURE_2D, $this->texture);
        glPixelStorei(GL_UNPACK_ALIGNMENT, 4);
        glPixelStorei(GL_UNPACK_ROW_LENGTH, intdiv($stride, 4));
        $regions = $damage === [] || $fresh ? [new Region(0, 0, $width, $height)] : $damage;
        foreach ($regions as $region) {
            glPixelStorei(GL_UNPACK_SKIP_PIXELS, $region->x);
            glPixelStorei(GL_UNPACK_SKIP_ROWS, $region->y);
            glTexSubImage2D(GL_TEXTURE_2D, 0, $region->x, $region->y, $region->width, $region->height, GL_RGBA, GL_UNSIGNED_BYTE, $pixels);
        }
        glPixelStorei(GL_UNPACK_ROW_LENGTH, 0);
        glPixelStorei(GL_UNPACK_SKIP_PIXELS, 0);
        glPixelStorei(GL_UNPACK_SKIP_ROWS, 0);
        $this->uploaded = $regions;

        glfwGetFramebufferSize($this->native(), $across, $down);
        $into = $this->presentRect($width, $height);
        glBindFramebuffer(GL_READ_FRAMEBUFFER, $this->read_framebuffer);
        glBindFramebuffer(GL_DRAW_FRAMEBUFFER, 0);
        glViewport(0, 0, $across, $down);
        glClearColor(0.0, 0.0, 0.0, $this->transparent ? 0.0 : 1.0);
        glClear(GL_COLOR_BUFFER_BIT);
        // The texture's first row is the frame's top; the window's is its bottom: the blit flips.
        glBlitFramebuffer(0, 0, $width, $height, $into->x, $down - $into->y, $into->x + $into->width, $down - $into->y - $into->height,
            GL_COLOR_BUFFER_BIT, $this->filter === ScaleFilter::Nearest ? GL_NEAREST : GL_LINEAR);
        glBindFramebuffer(GL_READ_FRAMEBUFFER, 0);
        $this->swap($regions, $width, $height, $into, $across, $down);
    }

    /**
     * Swap, naming on EGL the rects of the surface that changed: $damage (in
     * frame pixels) through where the frame landed. The back buffer was
     * redrawn whole, so the rects only tell the compositor what to redraw.
     *
     * @param  list<Region>  $damage
     */
    protected function swap(array $damage, int $width, int $height, Region $placed, int $across, int $down): void
    {
        $changed = $this->history->record($damage, $width, $height, $placed, $across, $down);
        $this->swapped = null;
        if ($this->platform_name !== 'cocoa' && $changed !== []) {
            $rects = [];
            foreach (DamageHistory::flipped($changed, $down) as $rect) {
                array_push($rects, $rect->x, $rect->y, $rect->width, $rect->height);
            }
            $display = EGLDisplay::fromPointer(glfwGetEGLDisplay());
            $surface = EGLSurface::fromPointer(glfwGetEGLSurface($this->native()));
            if (eglSwapBuffersWithDamageKHR($display, $surface, $rects)) {
                $this->swapped = $rects;

                return;
            }
        }
        glfwSwapBuffers($this->native());
    }

    /** An RGBA8 texture of the frame's size, read through a framebuffer of its own. */
    protected function makeTexture(int $width, int $height): void
    {
        if (! is_null($this->texture)) {
            glDeleteFramebuffers([$this->read_framebuffer]);
            glDeleteTextures([$this->texture]);
        }
        [$this->texture] = glGenTextures(1);
        glBindTexture(GL_TEXTURE_2D, $this->texture);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_NEAREST);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_NEAREST);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA8, $width, $height, 0, GL_RGBA, GL_UNSIGNED_BYTE, null);
        [$this->read_framebuffer] = glGenFramebuffers(1);
        glBindFramebuffer(GL_FRAMEBUFFER, $this->read_framebuffer);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->texture, 0);
        if (glCheckFramebufferStatus(GL_FRAMEBUFFER) !== GL_FRAMEBUFFER_COMPLETE) {
            throw new WindowException("Staged window '{$this->name}' could not read its {$width}x{$height} texture through a framebuffer.");
        }
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
        $this->texture_size = [$width, $height];
    }

    /** The window has a context, remade with one after a Vulkan lend, and it is current. */
    protected function withContext(): void
    {
        if ($this->api !== GLFW_OPENGL_API) {
            $this->remake(GLFW_OPENGL_API);
        }
        glfwMakeContextCurrent($this->native());
    }

    /** @return array<string, int> */
    protected function makeSurface(SurfaceKind $kind, array $handles): array
    {
        return match ($kind) {
            SurfaceKind::GL_CONTEXT => ($handles['native'] ?? 0) === 1 ? $this->lendNative() : $this->lendContext(),
            SurfaceKind::METAL_LAYER => $this->makeMetalView(),
            SurfaceKind::VULKAN_SURFACE => $this->makeVulkanSurface($handles),
            default => throw new WindowException("Staged window '{$this->name}' cannot make a {$kind->value} surface."),
        };
    }

    protected function removeSurface(SurfaceKind $kind): void
    {
        match ($kind) {
            SurfaceKind::METAL_LAYER => $this->removeMetalView(),
            SurfaceKind::VULKAN_SURFACE => $this->removeVulkanSurface(),
            default => null,
        };
        $this->native_gl = false;
        $this->shown = null;
    }

    /**
     * The native window for a GL borrower that makes its own context and
     * surface on it (an HDR or wide-gamut one): remade without a client API,
     * as for Vulkan. The next CPU present makes it a GL window again.
     *
     * @return array<string, int>
     */
    protected function lendNative(): array
    {
        if ($this->api !== GLFW_NO_API) {
            $this->remake(GLFW_NO_API);
        }
        $this->native_gl = true;
        $window = $this->native();

        return match ($this->platform_name) {
            'cocoa' => ['context' => 0, 'view' => glfwGetCocoaView($window)],
            'wayland' => ['context' => 0, 'wl_display' => glfwGetWaylandDisplay(), 'wl_surface' => glfwGetWaylandWindow($window)],
            'x11' => ['context' => 0, 'x11_display' => glfwGetX11Display(), 'x11_window' => glfwGetX11Window($window)],
            default => throw new WindowException("Staged window '{$this->name}' has no native window to lend on the glfw/{$this->platform_name} backend."),
        };
    }

    /** The swap interval for $vsync on this platform: 0 on Wayland, where the compositor paces and never tears. */
    protected function interval(VSync $vsync): int
    {
        return $this->platform_name === 'wayland' ? 0 : self::swapInterval($vsync);
    }

    /** A lent GL context's frame: current, framebuffer 0 bound, the borrower's copy, swapped. */
    protected function presentLent(LentSurface $surface, SurfaceBorrower $borrower): static
    {
        // Hidden: nothing presented, by the window or its borrower (see upload()); the frame keeps its damage.
        if (! $this->visible) {
            return $this;
        }
        if ($surface->kind !== SurfaceKind::GL_CONTEXT || $this->native_gl) {
            return parent::presentLent($surface, $borrower);
        }
        $frame = $borrower->framebuffer();
        $tracked = $frame instanceof DamageTrackingFramebuffer;
        if (! is_null($this->shown) && $tracked && $frame->damage() === []) {
            return $this;
        }
        $this->withContext();
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
        glfwSwapInterval($this->interval($surface->vsync()));
        if ($borrower->presentInto($surface)) {
            glfwGetFramebufferSize($this->native(), $across, $down);
            $width = $frame->viewportWidth();
            $height = $frame->viewportHeight();
            $damage = $tracked && ! is_null($this->shown) ? $frame->damage() : [new Region(0, 0, $width, $height)];
            $this->swap($damage, $width, $height, $this->presentRect($width, $height), $across, $down);
            $this->shown = 0;
            if ($tracked) {
                $frame->beginEpoch();
            }
        }

        return $this;
    }

    /** @return array<string, int> the window's context: its CGLContextObj on macOS, its EGL context and display elsewhere */
    protected function lendContext(): array
    {
        $this->withContext();
        if ($this->platform_name === 'cocoa') {
            return ['context' => NSOpenGLContext::fromPointer(glfwGetNSGLContext($this->native()))->CGLContextObj()];
        }

        return ['context' => glfwGetEGLContext($this->native()), 'display' => glfwGetEGLDisplay()];
    }

    /** @return array{layer: int} a CAMetalLayer on a view pinned over GLFW's content view */
    protected function makeMetalView(): array
    {
        $content = NSView::fromPointer(glfwGetCocoaView($this->native()));
        $layer = CAMetalLayer::layer();
        $layer->setContentsScale($this->nativeScale());
        $view = NSView::initWithFrame(new NSRect());
        $view->setLayer(CALayer::fromPointer($layer->pointer()));
        $content->addSubview($view);
        $view->setTranslatesAutoresizingMaskIntoConstraints(false);
        $this->metal_pins = [
            $view->leadingAnchor()->constraintEqualToAnchor($content->leadingAnchor()),
            $view->trailingAnchor()->constraintEqualToAnchor($content->trailingAnchor()),
            $view->topAnchor()->constraintEqualToAnchor($content->topAnchor()),
            $view->bottomAnchor()->constraintEqualToAnchor($content->bottomAnchor()),
        ];
        NSLayoutConstraint::activateConstraints($this->metal_pins);
        $this->metal_view = $view;

        return ['layer' => $layer->pointer()];
    }

    protected function removeMetalView(): void
    {
        NSLayoutConstraint::deactivateConstraints($this->metal_pins);
        $this->metal_pins = [];
        $this->metal_view?->removeFromSuperview();
        $this->metal_view = null;
    }

    /**
     * @param  array<string, int>  $handles  The borrower's: its VkInstance as 'instance'.
     * @return array{surface: int}
     */
    protected function makeVulkanSurface(array $handles): array
    {
        $instance = $handles['instance'] ?? throw new WindowException("Staged window '{$this->name}' makes a Vulkan surface against the borrower's 'instance' handle, and it has none.");
        if ($this->api !== GLFW_NO_API) {
            $this->remake(GLFW_NO_API);
        }
        $surface = 0;
        $result = glfwCreateWindowSurface($instance, $this->native(), 0, $surface);
        if ($result !== 0) {
            glfwGetError($description);

            throw new WindowException("Staged window '{$this->name}' could not make a Vulkan surface (VkResult {$result}): ".($description ?? 'no description'));
        }
        $this->vk_instance = $instance;
        $this->vk_surface = $surface;

        return ['surface' => $surface];
    }

    /** After the borrower let go (LentSurface released first): its swapchain is gone, the surface may go. */
    protected function removeVulkanSurface(): void
    {
        if (! is_null($this->vk_surface)) {
            vkDestroySurfaceKHR(VkInstance::fromPointer($this->vk_instance), VkSurfaceKHR::fromPointer($this->vk_surface), null);
            $this->vk_surface = null;
            $this->vk_instance = null;
        }
    }

    /**
     * The native window made again with client API $api, its state carried over:
     * place, limits, aspect, opacity, icon, visibility and mode.
     */
    protected function remake(int $api): void
    {
        $old = $this->native();
        [$width, $height] = $this->nativeSize();
        $place = $this->placeable() ? $this->nativePosition() : null;
        $window = $this->makeWindow($width, $height, $place, $api);
        glfwDestroyWindow($old);
        $this->window = $window;
        $this->history = new DamageHistory(1);

        [$minWidth, $minHeight, $maxWidth, $maxHeight] = $this->limits;
        $this->applyLimits($minWidth, $minHeight, $maxWidth, $maxHeight);
        $this->applyAspectRatio(...$this->aspect);
        if ($this->opacity < 1.0) {
            $this->applyOpacity($this->opacity);
        }
        if (! is_null($this->icon)) {
            $this->applyIcon(...$this->icon);
        }
        if ($api === GLFW_OPENGL_API) {
            $this->applyVsync($this->vsync);
        }
        if ($this->visible) {
            $this->applyVisible(true);
        }
        $mode = $this->mode;
        if ($mode !== WindowMode::Windowed) {
            $this->native_mode = WindowMode::Windowed;
            $this->exclusive_held = false;
            $this->applyMode($mode, $mode === WindowMode::Exclusive ? $this->exclusive : null);
        }
        $this->shown = null;
    }

    protected function destroyNative(): void
    {
        if (is_null($this->window)) {
            return;
        }
        if (! is_null(glfwGetWindowMonitor($this->window))) {
            $this->leaveMonitor();
        }
        glfwDestroyWindow($this->window);
        $this->window = null;
    }

    protected function forget(): void
    {
        $this->driver->forgetStaged($this->name);
    }

    protected function live(): static
    {
        if (is_null($this->window)) {
            throw new WindowException("Staged window '{$this->name}' was closed.");
        }

        return parent::live();
    }

    /** Full screen on $on's monitor (the window's own when null) at that monitor's desktop mode. */
    protected function coverMonitor(?Display $on): void
    {
        $monitor = is_null($on) ? $this->currentMonitor() : (GlfwDisplays::monitor($on->id) ?? throw new WindowException("Staged window '{$this->name}': display {$on->id} is not connected."));
        $desktop = GlfwDisplays::describe($monitor)->desktop;
        $this->rememberWindowed();
        $this->exclusive_held = false;
        glfwSetWindowMonitor($this->native(), $monitor, 0, 0, $desktop->width, $desktop->height, (int) round($desktop->refreshRate));
        GlfwDisplays::releaseDesktop($monitor->pointer());
    }

    /** The monitor of $mode switched to it, the window covering it. */
    protected function holdMonitor(DisplayMode $mode): void
    {
        $monitor = GlfwDisplays::monitor($mode->displayId) ?? throw new WindowException("Staged window '{$this->name}': display {$mode->displayId} is not connected.");
        $offered = array_filter(GlfwDisplays::modes($monitor), fn (DisplayMode $m): bool => $m->equals($mode));
        if ($offered === []) {
            throw new WindowException("Staged window '{$this->name}': display {$mode->displayId} offers no {$mode->width}x{$mode->height} mode at {$mode->refreshRate} Hz.");
        }
        GlfwDisplays::holdDesktop($monitor);
        $this->rememberWindowed();
        $this->exclusive_held = true;
        glfwSetWindowMonitor($this->native(), $monitor, 0, 0, $mode->width, $mode->height, (int) round($mode->refreshRate));
    }

    /** Back to a window where it was; GLFW gives the monitor its mode back. */
    protected function leaveMonitor(): void
    {
        $monitor = glfwGetWindowMonitor($this->native());
        [$x, $y, $width, $height] = $this->windowed ?? [0, 0, ...$this->nativeSize()];
        glfwSetWindowMonitor($this->native(), null, $x, $y, $width, $height, GLFW_DONT_CARE);
        if (! is_null($monitor)) {
            GlfwDisplays::releaseDesktop($monitor->pointer());
        }
        $this->exclusive_held = false;
        $this->windowed = null;
    }

    protected function rememberWindowed(): void
    {
        if (is_null(glfwGetWindowMonitor($this->native()))) {
            $this->windowed = [...$this->nativePosition(), ...$this->nativeSize()];
        }
    }

    /** Report the mode GLFW's window is in, when it changed. */
    protected function syncMode(): void
    {
        if (is_null($this->window)) {
            return;
        }
        $actual = match (true) {
            ! is_null(glfwGetWindowMonitor($this->window)) => $this->exclusive_held ? WindowMode::Exclusive : WindowMode::Fullscreen,
            glfwGetWindowAttrib($this->window, GLFW_ICONIFIED) === GLFW_TRUE => WindowMode::Minimized,
            glfwGetWindowAttrib($this->window, GLFW_MAXIMIZED) === GLFW_TRUE => WindowMode::Maximized,
            default => WindowMode::Windowed,
        };
        if ($actual !== $this->native_mode) {
            $this->native_mode = $actual;
            $this->modeChanged($actual);
        }
    }

    /** The monitor the window covers, or the one under its centre. */
    protected function currentMonitor(): ?GLFWmonitor
    {
        $window = $this->native();
        $held = glfwGetWindowMonitor($window);
        if (! is_null($held)) {
            return $held;
        }
        [$x, $y] = $this->nativePosition();
        [$width, $height] = $this->nativeSize();

        return GlfwDisplays::at($x + intdiv($width, 2), $y + intdiv($height, 2));
    }

    protected function checkDisplay(): void
    {
        $id = $this->currentMonitor()?->pointer() ?? 0;
        if ($id !== $this->last_display) {
            $this->last_display = $id;
            $this->displayChanged($id);
        }
    }

    /** A size outside the aspect range set, corrected; the correction resizes once more. */
    protected function keepAspect(): void
    {
        [$min, $max] = $this->aspect;
        if ($this->clamping || ($min === 0.0 && $max === 0.0) || $min === $max || is_null($this->window)) {
            return;
        }
        [$width, $height] = $this->asked ?? $this->nativeSize();
        $this->asked = null;
        $ratio = $width / max(1, $height);
        $width = match (true) {
            $min > 0.0 && $ratio < $min => (int) round($height * $min),
            $max > 0.0 && $ratio > $max => (int) round($height * $max),
            default => null,
        };
        if (! is_null($width)) {
            $this->clamping = true;
            glfwSetWindowSize($this->window, $width, $height);
            $this->clamping = false;
        }
    }

    /** Whether GLFW can place a window on this platform. */
    protected function placeable(): bool
    {
        return $this->platform_name !== 'wayland';
    }

    /** @return array{int, int} where a $width × $height window sits centred in $display's usable area */
    protected function centredOn(Display $display, int $width, int $height): array
    {
        return [
            $display->usable->x + intdiv(max(0, $display->usable->width - $width), 2),
            $display->usable->y + intdiv(max(0, $display->usable->height - $height), 2),
        ];
    }

    protected static function swapInterval(VSync $vsync): int
    {
        return match ($vsync) {
            VSync::On => 1,
            VSync::Adaptive => -1,
            VSync::Off, VSync::Mailbox => 0,
        };
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? max(1, $a) : self::gcd($b, $a % $b);
    }
}
