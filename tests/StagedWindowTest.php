<?php

declare(strict_types=1);

use GLFWwindow;
use Jovian\Engines\Vulkan\VulkanDevice;
use Jovian\Toolkits\Glfw\Bridge\GlfwBridgeDriver;
use Jovian\Toolkits\Glfw\Providers\VenusianGlfwServiceProvider;
use Jovian\Toolkits\Glfw\Windows\GlfwStagedWindow;
use Surface\Bridge\ToolkitManager;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\Drawing\VSync;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Display;
use Surface\Contracts\Windows\DisplayMode;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowModeChanged;
use Surface\Contracts\Windows\ScaleFilter;
use Surface\Contracts\Windows\ScaleFit;
use Surface\Contracts\Windows\StagedWindow;
use Surface\Contracts\Windows\StagedWindowDriver;
use Surface\Contracts\Windows\WindowCapability;
use Surface\Contracts\Windows\WindowException;
use Surface\Contracts\Windows\WindowMode;
use Surface\Drawing\Gpu\GpuRenderingEngine;
use Surface\NutsAndBolts\Color;
use Voyager\Vessel\ControlPanel;

/*
 * Real GLFW windows through one stager. Mail is read from the session's
 * outbox; pumpUntil() pumps as the loop's poller does. The backend names
 * GLFW's platform, and what a platform cannot do is asserted as measured.
 */

function stage(string $name = 'stage', array $options = [], int $width = 320, int $height = 200): GlfwStagedWindow
{
    stager()->getStaged($name)?->close();
    $window = stager()->openStaged($name, $width, $height, $options);
    pumpFor(0.1);
    takeMail();

    return $window;
}

function platform(): string
{
    stager()->connect();

    return [GLFW_PLATFORM_COCOA => 'cocoa', GLFW_PLATFORM_WAYLAND => 'wayland', GLFW_PLATFORM_X11 => 'x11'][glfwGetPlatform()];
}

afterEach(function (): void {
    stager()->closeAllStaged();
    pumpFor(0.05);
});

it('registers the glfw stager on the bridge and stages windows', function (): void {
    $container = new ControlPanel();
    $container->registerInstance('config', new class
    {
        public function get(string $key, mixed $default = null): mixed
        {
            return $default;
        }
    });
    $toolkits = new ToolkitManager($container);
    VenusianGlfwServiceProvider::stage($toolkits);
    $window = stage('stage', ['title' => 'Doom']);

    expect($toolkits->driver('glfw'))->toBeInstanceOf(GlfwBridgeDriver::class)
        ->and(stager())->toBeInstanceOf(StagedWindowDriver::class)
        ->and($window)->toBeInstanceOf(StagedWindow::class)
        ->and($window->backend())->toBe('glfw/'.platform())
        ->and(glfwGetWindowTitle($window->native()))->toBe('Doom')
        ->and($window->size())->toBe([320, 200])
        ->and(glfwGetWindowAttrib($window->native(), GLFW_VISIBLE))->toBe(GLFW_TRUE)
        ->and($window->supports(WindowCapability::Attention))->toBeTrue()
        ->and($window->supports(WindowCapability::Position))->toBe(platform() !== 'wayland')
        ->and($window->supports(WindowCapability::Icon))->toBe(platform() === 'x11')
        ->and($window->supports(WindowCapability::HitTest))->toBeFalse()
        ->and(fn () => stager()->openStaged('stage', 1, 1))->toThrow(WindowException::class, "A staged window named 'stage' is already open.");
});

it('refuses a window that refuses focus, and a platform it does not know', function (): void {
    expect(fn () => stager()->openStaged('nofocus', 1, 1, ['focusable' => false]))
        ->toThrow(WindowException::class, "Staged window 'nofocus' cannot refuse focus: the glfw/".platform().' backend does not support it.')
        ->and(stager()->hasStaged('nofocus'))->toBeFalse()
        ->and(fn () => (new GlfwBridgeDriver(new ControlPanel(), 'beos'))->connect())->toThrow(WindowException::class, "GLFW runs on 'cocoa', 'wayland' or 'x11', got 'beos'.");
});

it('uploads only the damaged regions of a dirty framebuffer', function (): void {
    $window = stage('stage', ['vsync' => VSync::Off]);
    $frame = $window->framebuffer();
    [$width, $height] = $window->pixelSize();

    $window->present();
    expect($window->uploaded)->toEqual([new Region(0, 0, $width, $height)])
        ->and(glGetError())->toBe(GL_NO_ERROR);

    $frame->setPixel(4, 4, 0xFF0000FF);
    $frame->setPixel(40, 30, 0xFF0000FF);
    $window->present();

    expect($window->uploaded)->not->toBeEmpty()
        ->and(array_sum(array_map(fn (Region $r): int => $r->width * $r->height, $window->uploaded)))->toBeLessThan($width * $height)
        ->and(glGetError())->toBe(GL_NO_ERROR);
});

it('blits a small framebuffer by the scaling fit without a GL error', function (): void {
    $window = stage();
    $window->setScaling(ScaleFilter::Nearest, ScaleFit::Integer);
    $window->framebuffer('full', 64, 40)->setPixel(0, 0, 0xFFFFFFFF);

    $window->present();

    expect($window->uploaded)->toEqual([new Region(0, 0, 64, 40)])
        ->and($window->presentRect(64, 40)->width % 64)->toBe(0)
        ->and(glGetError())->toBe(GL_NO_ERROR);
});

it('lists monitors as displays, the primary first, and their modes', function (): void {
    $window = stage();
    $displays = stager()->displays();

    expect($displays)->not->toBeEmpty()
        ->and($displays[0])->toBeInstanceOf(Display::class)
        ->and($displays[0]->id)->toBe(glfwGetPrimaryMonitor()->pointer())
        ->and(array_map(fn (Display $d): int => $d->id, $displays))->toContain($window->display()->id)
        ->and($window->displayModes())->not->toBeEmpty()
        ->and($window->displayModes()[0])->toBeInstanceOf(DisplayMode::class)
        ->and($window->hdr())->toBeNull();
});

it('moves where the platform places windows', function (): void {
    $window = stage();

    if (platform() === 'wayland') {
        expect(fn () => $window->move(40, 60))->toThrow(WindowException::class, 'the glfw/wayland backend does not support it');
    } else {
        $usable = $window->display()->usable;
        $window->move($usable->x + 40, $usable->y + 60);
        pumpUntil(fn (): bool => $window->position() === [$usable->x + 40, $usable->y + 60], 2.0);
        expect($window->position())->toBe([$usable->x + 40, $usable->y + 60]);
    }
});

it('bounds its size, keeps an exact aspect ratio, and toggles its style natively', function (): void {
    $window = stage();

    $window->setLimits(100, 80, 800, 600)->setAspectRatio(2.0, 2.0)->setBorderless(true)->setResizable(false);

    expect(glfwGetWindowAttrib($window->native(), GLFW_DECORATED))->toBe(GLFW_FALSE)
        ->and(glfwGetWindowAttrib($window->native(), GLFW_RESIZABLE))->toBe(GLFW_FALSE)
        ->and($window->limits())->toBe([100, 80, 800, 600])
        ->and($window->aspectRatio())->toBe([2.0, 2.0]);

    if (platform() !== 'wayland') {
        $window->setAlwaysOnTop(true)->setOpacity(0.5);
        expect(glfwGetWindowAttrib($window->native(), GLFW_FLOATING))->toBe(GLFW_TRUE)
            ->and(glfwGetWindowOpacity($window->native()))->toBe(0.5);
    }
});

it('corrects a size outside an aspect range', function (): void {
    $window = stage('stage', ['resizable' => true]);

    $window->setAspectRatio(1.0, 1.5);
    $window->resize(600, 200);
    pumpUntil(fn (): bool => $window->size() === [300, 200], 2.0);

    expect($window->size())->toBe([300, 200]);
});

it('maximizes and restores, posting each mode it entered', function (): void {
    $window = stage('stage', ['resizable' => true]);

    $window->setMode(WindowMode::Maximized);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Maximized)]);

    $window->setMode(WindowMode::Windowed);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Windowed)]);
});

it('covers its monitor and leaves it', function (): void {
    $window = stage();

    $window->setMode(WindowMode::Fullscreen);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Fullscreen)])
        ->and(glfwGetWindowMonitor($window->native()))->not->toBeNull();

    $window->setMode(WindowMode::Windowed);
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Windowed)])
        ->and(glfwGetWindowMonitor($window->native()))->toBeNull();
    // The window's size comes back as the server applies it (X11 reports it a pump later).
    pumpUntil(fn (): bool => $window->size() === [320, 200], 2.0);
    expect($window->size())->toBe([320, 200]);
});

it('switches its monitor to a video mode for exclusive fullscreen and gives it back', function (): void {
    $window = stage();
    $display = $window->display();
    $others = array_values(array_filter($window->displayModes(), fn (DisplayMode $m): bool => $m->width !== $display->current->width));
    expect($others)->not->toBeEmpty();

    $window->setMode(WindowMode::Exclusive, $others[0]);
    $monitor = glfwGetWindowMonitor($window->native());
    expect(mailOf(WindowModeChanged::class))->toEqual([new WindowModeChanged('stage', WindowMode::Exclusive)])
        ->and(glfwGetVideoMode($monitor)->width)->toBe($others[0]->width)
        ->and($window->display()->desktop->width)->toBe($display->current->width);

    $window->setMode(WindowMode::Windowed);
    pumpFor(0.5);
    expect(glfwGetVideoMode($monitor)->width)->toBe($display->current->width)
        ->and(glfwGetWindowMonitor($window->native()))->toBeNull();
})->skip(fn (): bool => platform() === 'wayland', 'Wayland switches no modes');

it('asks for attention, takes an icon where the platform has one, and reads its safe area', function (): void {
    $window = stage();
    glfwGetError();                                                    // nothing earlier counts

    $window->requestAttention();
    if ($window->supports(WindowCapability::Icon)) {
        $window->setIcon(str_repeat("\xff\x80\x00\xff", 16 * 16), 16, 16);
    } else {
        expect(fn () => $window->setIcon(str_repeat("\0", 4), 1, 1))->toThrow(WindowException::class, 'cannot set an icon');
    }
    $area = $window->safeArea();

    expect($area->width)->toBeLessThanOrEqual(320)->toBeGreaterThan(0)
        ->and($area->height)->toBeLessThanOrEqual(200)->toBeGreaterThan(0)
        ->and(glfwGetError())->toBe(GLFW_NO_ERROR);
});

it('closes on the close button, or asks first with confirm_close', function (): void {
    $asking = stage('asking', ['confirm_close' => true]);
    glfwSetWindowShouldClose($asking->native(), true);
    // GLFW calls the close callback only for the user's close; this is the callback's own path.
    (function (): void { $this->closeRequested(); glfwSetWindowShouldClose($this->native(), false); })->call($asking);
    expect($asking->isOpen())->toBeTrue()
        ->and(glfwWindowShouldClose($asking->native()))->toBeFalse()
        ->and(takeMail())->toContainEqual(new WindowCloseRequested('asking'));

    $plain = stage('plain');
    (fn () => $this->closeRequested())->call($plain);
    expect($plain->isOpen())->toBeFalse()
        ->and(takeMail())->toContainEqual(new WindowClosed('plain'))
        ->and(stager()->hasStaged('plain'))->toBeFalse();
});

it('lends its own GL context and swaps after the borrower\'s copy', function (): void {
    $window = stage();
    $borrower = new StageBorrower();

    $surface = $window->lend(SurfaceKind::GL_CONTEXT, $borrower);
    $window->present();

    expect($surface->handle('context'))->toBeGreaterThan(0)
        ->and(platform() === 'cocoa' || $surface->handle('display') > 0)->toBeTrue()
        ->and($borrower->presented)->toHaveCount(1);

    $window->reclaim();
    $window->framebuffer()->setPixel(0, 0, 0xFF00FFFF);
    $window->present();
    expect(glGetError())->toBe(GL_NO_ERROR);
})->skip(PHP_OS_FAMILY === 'Darwin' && ! class_exists(NSOpenGLContext::class), 'needs ext-appkit on macOS');

it('lends a Metal layer on a view over its own', function (): void {
    $window = stage();

    $surface = $window->lend(SurfaceKind::METAL_LAYER, new StageBorrower());

    expect(CAMetalLayer::fromPointer($surface->handle('layer')))->toBeInstanceOf(CAMetalLayer::class);
    $window->reclaim();
})->skip(PHP_OS_FAMILY !== 'Darwin' || ! class_exists(CAMetalLayer::class) || ! class_exists(NSView::class), 'needs macOS, ext-appkit and ext-metal');

it('lends a Vulkan surface from a window remade without a context, and draws on the CPU again after', function (): void {
    $window = stage('stage', ['title' => 'VK']);
    $window->setLimits(100, 80);
    $device = new VulkanDevice();

    $surface = $window->lend(SurfaceKind::VULKAN_SURFACE, new StageBorrower($device->handles()));

    expect($surface->handle('surface'))->toBeGreaterThan(0)
        ->and(glfwGetWindowAttrib($window->native(), GLFW_CLIENT_API))->toBe(GLFW_NO_API)
        ->and(glfwGetWindowTitle($window->native()))->toBe('VK')
        ->and($window->size())->toBe([320, 200]);

    $window->reclaim();
    $device->release();
    $window->framebuffer()->setPixel(0, 0, 0xFF00FFFF);
    $window->present();
    expect(glfwGetWindowAttrib($window->native(), GLFW_CLIENT_API))->not->toBe(GLFW_NO_API)
        ->and(glGetError())->toBe(GL_NO_ERROR);
})->skip(fn (): bool => ! extension_loaded('vulkan') || ! class_exists(VulkanDevice::class) || ! (stager()->connect() && glfwVulkanSupported()), 'needs ext-vulkan, jovian/venusian-vulkan and a Vulkan loader GLFW finds');

it('names the rects that changed when it swaps on EGL, the whole swap first', function (): void {
    $window = stage('swap', ['vsync' => VSync::Off]);
    $frame = $window->framebuffer();
    [$width, $height] = $window->pixelSize();

    $window->present();
    $first = $window->swapped;
    $frame->setPixel(4, 4, 0xFF0000FF);
    $window->present();

    if (platform() === 'cocoa') {
        expect([$first, $window->swapped])->toBe([null, null]);

        return;
    }
    $display = EGLDisplay::fromPointer(glfwGetEGLDisplay());
    if (! str_contains(eglQueryString($display, EGL_EXTENSIONS) ?? '', 'EGL_KHR_swap_buffers_with_damage')) {
        expect($window->swapped)->toBeNull();

        return;
    }
    expect($first)->toBe([0, 0, $width, $height])
        ->and($window->swapped)->toBe([4, $height - 5, 1, 1])
        ->and(glGetError())->toBe(GL_NO_ERROR);
});

it('hands a GL borrower that asks for it the native window, remade without a context', function (): void {
    $window = stage('native-gl');
    $borrower = new StageBorrower(['native' => 1]);

    $surface = $window->lend(SurfaceKind::GL_CONTEXT, $borrower);
    $window->present();
    $keys = array_keys($surface->handles());
    sort($keys);

    expect($keys)->toBe(match (platform()) {
        'cocoa' => ['context', 'view'],
        'wayland' => ['context', 'wl_display', 'wl_surface'],
        'x11' => ['context', 'x11_display', 'x11_window'],
    })->and($surface->handle('context'))->toBe(0)->and(glfwGetWindowAttrib($window->native(), GLFW_CLIENT_API))->toBe(GLFW_NO_API)
        ->and($borrower->presented)->toHaveCount(1)
        ->and($window->swapped)->toBeNull();
    $window->reclaim();
    $window->framebuffer()->setPixel(0, 0, 0x00FF00FF);
    $window->present();
    expect(glfwGetWindowAttrib($window->native(), GLFW_CLIENT_API))->not->toBe(GLFW_NO_API);
});

it('presents nothing while hidden, through a lent Vulkan surface or not, and the whole frame once shown', function (): void {
    $window = stage('hidden-vk', ['vsync' => VSync::On]);
    $device = new VulkanDevice();
    $engine = new GpuRenderingEngine($device, 320, 200, output: $window);
    for ($i = 0; $i < 5; $i++) {
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(0, 0, 40 * $i)));
        $window->present();
        pumpFor(0.02);
    }
    $before = $device->submitted();

    $window->hide();
    pumpFor(0.3);
    $started = hrtime(true);
    for ($i = 0; $i < 30; $i++) {
        $engine->frame(fn (RenderingEngine $g) => $g->clear(Color::rgb(255, 0, 0)));
        $window->present();
    }
    $elapsed = (hrtime(true) - $started) / 1e9;
    $hidden = $device->submitted() - $before;
    $window->show();
    pumpFor(0.3);
    for ($i = 0; $i < 20 && $device->submitted() === $before; $i++) {
        $window->present();
        pumpFor(0.02);
    }
    $engine->release();
    // A window made after the hidden one: a buffer committed while hidden would have ended the Wayland connection by now.
    $next = stage('after-hidden');

    expect($hidden)->toBe(0)
        ->and($elapsed)->toBeLessThan(1.0)
        ->and($device->submitted())->toBeGreaterThan($before)
        ->and($next->native())->toBeInstanceOf(GLFWwindow::class);
})->skip(fn (): bool => ! extension_loaded('vulkan') || ! class_exists(VulkanDevice::class) || ! (stager()->connect() && glfwVulkanSupported()), 'needs ext-vulkan, jovian/venusian-vulkan and a Vulkan loader GLFW finds');

it('swaps a lent GL context only while shown, the whole frame first after', function (): void {
    $window = stage('hidden-gl', ['vsync' => VSync::On]);
    $borrower = new StageBorrower();
    $window->lend(SurfaceKind::GL_CONTEXT, $borrower);
    $window->hide();
    pumpFor(0.3);

    for ($i = 0; $i < 10; $i++) {
        $borrower->frame->setPixel($i % 8, 0, 0xFF0000FF);
        $window->present();
    }
    $hidden = count($borrower->presented);
    $window->show();
    pumpFor(0.3);
    $window->present();
    $window->reclaim();
    $next = stage('after-hidden-gl');

    expect($hidden)->toBe(0)
        ->and($borrower->presented)->toHaveCount(1)
        ->and($next->native())->toBeInstanceOf(GLFWwindow::class);
});

it('swaps at interval 0 on Wayland whatever the vsync, and at the vsync elsewhere', function (): void {
    $window = stage('interval');
    $interval = fn (VSync $vsync): int => (fn (): int => $this->interval($vsync))->call($window);

    expect([$interval(VSync::On), $interval(VSync::Off), $interval(VSync::Adaptive)])
        ->toBe(platform() === 'wayland' ? [0, 0, 0] : [1, 0, -1]);
});
